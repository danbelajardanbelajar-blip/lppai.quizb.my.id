<?php
/**
 * LPPAI Corner - AJAX Scan Absensi Pretes Mahasiswa
 * Memproses hasil pemindaian QR Code dari dashboard mahasiswa.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pretes-helper.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login telah berakhir. Silakan login kembali.']);
    exit;
}

$user = getCurrentUser();
if (($user['role'] ?? '') !== 'mahasiswa') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Fitur scan absensi pretes hanya dapat diakses oleh akun mahasiswa.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode permintaan tidak valid.']);
    exit;
}

// Validasi CSRF Token
$csrfToken = $_POST['csrf_token'] ?? '';
if (!is_string($csrfToken) || !verifyCsrf($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman dan coba lagi.']);
    exit;
}

// Ekstraksi token dari data QR (bisa berupa URL penuh, relative URL, atau token 48-char)
$rawData = trim((string)($_POST['qr_data'] ?? ($_POST['token'] ?? '')));
$token   = null;

if (preg_match('/[?&]token=([a-f0-9]{48})/i', $rawData, $matches)) {
    $token = strtolower($matches[1]);
} elseif (preg_match('/^[a-f0-9]{48}$/i', $rawData)) {
    $token = strtolower($rawData);
}

if (!$token) {
    echo json_encode([
        'success' => false,
        'message' => 'Format QR Code tidak dikenali sebagai QR Absensi Pretes LPPAI. Pastikan Anda memindai QR Code resmi dari Dosen Penguji/Ruangan.'
    ]);
    exit;
}

try {
    $pdo = getDBConnection();
    ensurePretesFeatures($pdo);

    // Cari sesi absensi berdasarkan token
    $stmt = $pdo->prepare('SELECT *, (open_until > NOW()) AS is_open FROM pretes_attendance_sessions WHERE token = ?');
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode([
            'success' => false,
            'message' => 'QR Code absensi pretes tidak ditemukan atau tidak valid di sistem LPPAI.'
        ]);
        exit;
    }

    // Cek apakah sesi absensi sedang dibuka oleh penguji/admin
    if (!$session['is_open']) {
        echo json_encode([
            'success' => false,
            'message' => 'Sesi absensi untuk <strong>' . htmlspecialchars($session['hari'], ENT_QUOTES, 'UTF-8') . '</strong> (Penguji: <strong>' . htmlspecialchars($session['penguji'] ?: 'Penguji', ENT_QUOTES, 'UTF-8') . '</strong>, Ruang: <strong>' . htmlspecialchars($session['ruangan'] ?: '-', ENT_QUOTES, 'UTF-8') . '</strong>) saat ini <strong>DITUTUP</strong>.<br>Silakan minta dosen penguji atau petugas di ruangan untuk membuka absensi.',
            'session' => [
                'hari'    => $session['hari'],
                'penguji' => $session['penguji'],
                'ruangan' => $session['ruangan'],
                'periode' => $session['periode'],
                'is_open' => false
            ]
        ]);
        exit;
    }

    // Ambil registrasi pretes mahasiswa ini pada periode yang bersangkutan
    $stmtReg = $pdo->prepare('SELECT * FROM pretes_registrations WHERE user_id = ? AND periode = ? ORDER BY id DESC');
    $stmtReg->execute([$user['id'], $session['periode']]);
    $registrations = $stmtReg->fetchAll(PDO::FETCH_ASSOC);

    if (empty($registrations)) {
        echo json_encode([
            'success' => false,
            'message' => 'Anda tidak terdaftar sebagai peserta pretes pada periode <strong>' . htmlspecialchars($session['periode'], ENT_QUOTES, 'UTF-8') . '</strong>.'
        ]);
        exit;
    }

    // Cocokkan scope hash (hari, penguji, ruangan)
    $matchedReg = null;
    foreach ($registrations as $row) {
        if (pretesScopeHash(pretesScope($row)) === $session['scope_hash']) {
            $matchedReg = $row;
            break;
        }
    }

    if (!$matchedReg) {
        $first = $registrations[0];
        $myHari    = $first['hari_pilihan'] ?: 'Belum diatur';
        $myPenguji = $first['dosen_penguji'] ?: 'Belum diplot';
        $myRuang   = $first['ruangan'] ?: 'Belum ditentukan';

        echo json_encode([
            'success' => false,
            'message' => 'Sesi QR ini tidak cocok dengan jadwal plotting Anda!<br>' .
                         '• <strong>Jadwal Anda:</strong> ' . htmlspecialchars($myHari, ENT_QUOTES, 'UTF-8') . ' | Penguji: ' . htmlspecialchars($myPenguji, ENT_QUOTES, 'UTF-8') . ' | Ruang: ' . htmlspecialchars($myRuang, ENT_QUOTES, 'UTF-8') . '<br>' .
                         '• <strong>Sesi QR Terpindai:</strong> ' . htmlspecialchars($session['hari'], ENT_QUOTES, 'UTF-8') . ' | Penguji: ' . htmlspecialchars($session['penguji'], ENT_QUOTES, 'UTF-8') . ' | Ruang: ' . htmlspecialchars($session['ruangan'], ENT_QUOTES, 'UTF-8') . '<br>' .
                         '<em>Silakan pastikan Anda berada di ruangan dan menghadap dosen penguji yang sesuai dengan kartu/jadwal Anda.</em>'
        ]);
        exit;
    }

    // Rekam absensi kehadiran
    $pdo->beginTransaction();
    $stmtIns = $pdo->prepare('
        INSERT INTO pretes_attendance_records (registration_id, session_id, attended_at) 
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE session_id = VALUES(session_id), attended_at = NOW()
    ');
    $stmtIns->execute([$matchedReg['id'], $session['id']]);

    $stmtUp = $pdo->prepare("UPDATE pretes_registrations SET status = 'hadir' WHERE id = ? AND user_id = ?");
    $stmtUp->execute([$matchedReg['id'], $user['id']]);
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Alhamdulillah! Kehadiran Pretes Anda resmi tercatat.',
        'session' => [
            'hari'    => $session['hari'],
            'penguji' => $session['penguji'],
            'ruangan' => $session['ruangan'],
            'periode' => $session['periode'],
            'waktu'   => date('d F Y, H:i') . ' WIB'
        ]
    ]);
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Error ajax-pretes-scan: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem saat merekam kehadiran. Silakan coba kembali atau hubungi pengawas.'
    ]);
    exit;
}
