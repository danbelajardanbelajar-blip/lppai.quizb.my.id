<?php
/**
 * LPPAI Corner - API Proctoring Pengawas AI Ujian Pretes
 * Menangani Skenario A (Check-in Awal) dan Skenario B (Pengecekan Berkala 5 Menit Sekali).
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pretes-helper.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Sesi login telah berakhir. Silakan login kembali.'
    ]);
    exit;
}

$user = getCurrentUser();
$pdo  = getDBConnection();
ensurePretesProctoringTables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metode request tidak diizinkan.'
    ]);
    exit;
}

// Baca JSON atau Form Payload
$rawInput = file_get_contents('php://input');
$jsonBody = json_decode($rawInput, true);

$csrfToken = $_POST['csrf_token'] ?? ($jsonBody['csrf_token'] ?? '');
if (!verifyCsrf($csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Token keamanan (CSRF) tidak valid. Harap muat ulang halaman.'
    ]);
    exit;
}

$action = trim($_POST['action'] ?? ($jsonBody['action'] ?? ''));
$livePhoto = trim($_POST['live_photo'] ?? ($jsonBody['live_photo'] ?? ''));
$regIdInput = (int)($_POST['registration_id'] ?? ($jsonBody['registration_id'] ?? 0));

// Ambil data registrasi pretes mahasiswa aktif
$isTesterPrivileged = (isset($user['username']) && (string)$user['username'] === '12345') 
                   || (isset($user['nim']) && (string)$user['nim'] === '12345')
                   || ((int)($user['id'] ?? 0) === 12345);

if ($regIdInput > 0) {
    $stmtReg = $pdo->prepare("SELECT * FROM pretes_registrations WHERE id = ? AND user_id = ?");
    $stmtReg->execute([$regIdInput, $user['id']]);
} else {
    $stmtReg = $pdo->prepare("SELECT * FROM pretes_registrations WHERE user_id = ? ORDER BY tanggal_daftar DESC, id DESC LIMIT 1");
    $stmtReg->execute([$user['id']]);
}
$myReg = $stmtReg->fetch(PDO::FETCH_ASSOC);

if (!$myReg && $isTesterPrivileged) {
    $myReg = [
        'id' => 999999,
        'user_id' => $user['id'],
        'foto' => !empty($user['foto']) ? $user['foto'] : '',
        'hari_pilihan' => 'Senin',
        'jam' => '13.00 WIB (Jam 1)'
    ];
}

if (!$myReg) {
    echo json_encode([
        'success' => false,
        'message' => 'Anda belum terdaftar pada sesi pretes periode aktif.'
    ]);
    exit;
}

$regId = (int)$myReg['id'];
$refPhoto = trim((string)($myReg['foto'] ?? ''));

// Jika foto masih kosong untuk akun tester 12345, cari foto pasfoto sample di server
if (empty($refPhoto) && $isTesterPrivileged) {
    $photos = glob(__DIR__ . '/../uploads/pretes_foto/*.{jpg,png,jpeg,webp}', GLOB_BRACE);
    if (!empty($photos)) {
        $refPhoto = 'uploads/pretes_foto/' . basename($photos[0]);
    }
}

if ($action === 'fetch_logs') {
    $logs = getStudentProctoringLogs($pdo, $regId);
    echo json_encode([
        'success' => true,
        'registration_id' => $regId,
        'total_logs' => count($logs),
        'logs' => $logs
    ]);
    exit;
}

// ── Validasi Keberadaan Foto Referensi Pendaftaran ──
if (empty($refPhoto)) {
    echo json_encode([
        'success' => false,
        'verified' => false,
        'message' => 'Pasfoto resmi pendaftaran Anda belum tersimpan. Silakan perbarui pasfoto pada menu Pendaftaran Pretes.'
    ]);
    exit;
}

if (empty($livePhoto)) {
    echo json_encode([
        'success' => false,
        'verified' => false,
        'message' => 'Foto kamera live tidak terkirim atau kosong.'
    ]);
    exit;
}

// ── Eksekusi Komparasi Wajah (Face Comparison) ──
$compResult = compareFacesGD($refPhoto, $livePhoto);
$score = (float)($compResult['similarity'] ?? 0.0);
$status = (string)($compResult['status'] ?? 'NO_FACE');
$match = !empty($compResult['match']);
$reason = (string)($compResult['reason'] ?? '');

if ($action === 'initial_checkin') {
    // ═════════════════════════════════════════════════════════════════════════
    // SKENARIO A: CHECK-IN VERIFIKASI WAJAH DI AWAL SEBELUM MASUK UJIAN
    // ═════════════════════════════════════════════════════════════════════════
    $snapshotPath = null;
    if ($match) {
        $snapshotPath = saveProctoringSnapshot($livePhoto, $user['nim'] ?? 'peserta', 'checkin_match');
        $_SESSION['pretes_cbt_verified_' . $regId] = time();
    } else {
        $snapshotPath = saveProctoringSnapshot($livePhoto, $user['nim'] ?? 'peserta', 'checkin_fail');
    }

    // Catat log audit ke database
    try {
        $stmtLog = $pdo->prepare("
            INSERT INTO pretes_proctoring_logs 
            (registration_id, user_id, event_type, similarity_score, status, snapshot_path, notes)
            VALUES (?, ?, 'initial_checkin', ?, ?, ?, ?)
        ");
        $stmtLog->execute([
            $regId,
            $user['id'],
            $score,
            $status,
            $snapshotPath,
            $reason
        ]);
    } catch (Throwable $e) {
        error_log("Gagal mencatat log initial_checkin: " . $e->getMessage());
    }

    echo json_encode([
        'success'    => $match,
        'verified'   => $match,
        'event_type' => 'initial_checkin',
        'score'      => $score,
        'status'     => $status,
        'message'    => $reason,
        'verified_at'=> date('H:i:s') . ' WIB'
    ]);
    exit;

} elseif ($action === 'periodic_5min') {
    // ═════════════════════════════════════════════════════════════════════════
    // SKENARIO B: PENGAWASAN BERKALA SETIAP 5 MENIT SEKALI SAAT PENGERJAAN
    // ═════════════════════════════════════════════════════════════════════════
    $snapType = $match ? 'periodic_ok' : 'periodic_violation';
    $snapshotPath = saveProctoringSnapshot($livePhoto, $user['nim'] ?? 'peserta', $snapType);

    try {
        $stmtLog = $pdo->prepare("
            INSERT INTO pretes_proctoring_logs 
            (registration_id, user_id, event_type, similarity_score, status, snapshot_path, notes)
            VALUES (?, ?, 'periodic_5min', ?, ?, ?, ?)
        ");
        $stmtLog->execute([
            $regId,
            $user['id'],
            $score,
            $status,
            $snapshotPath,
            $reason
        ]);

        // Hitung total pelanggaran (MISMATCH atau NO_FACE) pada sesi ini
        $stmtViolations = $pdo->prepare("
            SELECT COUNT(*) FROM pretes_proctoring_logs 
            WHERE registration_id = ? AND status IN ('MISMATCH', 'NO_FACE')
        ");
        $stmtViolations->execute([$regId]);
        $violationCount = (int)$stmtViolations->fetchColumn();
    } catch (Throwable $e) {
        error_log("Gagal mencatat log periodic_5min: " . $e->getMessage());
        $violationCount = 0;
    }

    echo json_encode([
        'success'         => true,
        'verified'        => $match,
        'event_type'      => 'periodic_5min',
        'score'           => $score,
        'status'          => $status,
        'message'         => $reason,
        'violation_count' => $violationCount,
        'checked_at'      => date('H:i:s') . ' WIB'
    ]);
    exit;

} else {
    echo json_encode([
        'success' => false,
        'message' => 'Action tidak dikenali.'
    ]);
    exit;
}
