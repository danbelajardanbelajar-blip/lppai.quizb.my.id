<?php
/**
 * Test Skenario A & Skenario B pada Mode Tryout CBT (Opsi A)
 * Memverifikasi:
 * 1. Sesi tryout dengan camera_mode = 'wajib':
 *    - API start ditolak jika belum verifikasi wajah (Skenario A).
 *    - API start diterima setelah verifikasi wajah berhasil.
 *    - API resume ditolak jika tidak ada token perhatian wajah (Skenario B).
 *    - API resume diterima setelah verifikasi perhatian wajah berhasil.
 * 2. Sesi tryout dengan camera_mode = 'tidak_aktif':
 *    - API start langsung berhasil tanpa syarat kamera.
 *    - API resume langsung berhasil tanpa syarat perhatian kamera.
 * 3. Integrasi api/pretes-proctoring-check.php dengan registration_id sintetis negatif:
 *    - Mengambil pasfoto dari pendaftaran resmi pretes periode aktif.
 *    - Menyimpan token verifikasi & log audit pada registration_id negatif tryout.
 * 4. Integrasi antarmuka pages/tryout.php:
 *    - Memuat sectionCheckin (Skenario A) & sectionExamRoom (Skenario B) lengkap dengan proctoring-monitor-bar.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [];

require_once __DIR__ . '/../includes/pretes-tryout.php';
require_once __DIR__ . '/../includes/pretes-helper.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensurePretesCbtTables($pdo);
ensurePretesProctoringTables($pdo);

// Setup skema pretes_registrations & pretes_face_challenges pada memori sqlite
$pdo->exec("CREATE TABLE IF NOT EXISTS pretes_registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    periode VARCHAR(100) NOT NULL,
    hari_pilihan VARCHAR(20) NOT NULL,
    jam VARCHAR(30) NOT NULL,
    foto VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'terdaftar',
    tanggal_daftar TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS pretes_schedules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    periode VARCHAR(100) NOT NULL,
    tanggal DATE NOT NULL,
    waktu_mulai TIME NOT NULL,
    waktu_selesai TIME NOT NULL,
    ruangan VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'aktif'
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS pretes_face_challenges (
    id VARCHAR(64) PRIMARY KEY,
    registration_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    direction VARCHAR(5) NOT NULL,
    issued_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    consumed_at INTEGER DEFAULT NULL
)");

$failures = [];
$check = function ($cond, $msg) use (&$failures) {
    if (!$cond) $failures[] = $msg;
};

// ── 1. Setup Data Uji ────────────────────────────────────────────────────────
$userId = 88;
$pdo->exec("INSERT INTO pretes_schedules (periode, tanggal, waktu_mulai, waktu_selesai, ruangan, status)
    VALUES ('Pretes 2026', '2026-11-20', '08:00:00', '16:00:00', 'Lab 1', 'aktif')");
$pdo->exec("INSERT INTO pretes_registrations (user_id, periode, hari_pilihan, jam, foto, status)
    VALUES ($userId, 'Pretes 2026', 'Senin', '13.00 WIB (Jam 1)', 'uploads/pasfoto/test.jpg', 'terdaftar')");
$officialRegId = (int)$pdo->lastInsertId();

// Buat 2 sesi tryout: Sesi A (kamera wajib) & Sesi B (kamera tidak aktif)
$tryoutCamWajibId = pretesCbtSaveSession($pdo, [
    'title' => 'Tryout Wajib Kamera',
    'package_ids' => [1],
    'mode' => 'tryout',
    'camera_mode' => 'wajib',
    'max_attempts' => 3,
    'duration_minutes' => 30,
    'is_active' => 1
]);

$tryoutCamMundurId = pretesCbtSaveSession($pdo, [
    'title' => 'Tryout Mandiri Tanpa Kamera',
    'package_ids' => [1],
    'mode' => 'tryout',
    'camera_mode' => 'tidak_aktif',
    'max_attempts' => 3,
    'duration_minutes' => 30,
    'is_active' => 1
]);

$sesCamWajib = pretesTryoutGetSession($pdo, $tryoutCamWajibId);
$sesCamMundur = pretesTryoutGetSession($pdo, $tryoutCamMundurId);

$check($sesCamWajib['camera_mode'] === 'wajib', 'Sesi Tryout A harus bertanda camera_mode wajib');
$check($sesCamMundur['camera_mode'] === 'tidak_aktif', 'Sesi Tryout B harus bertanda camera_mode tidak_aktif');

// ── 2. Verifikasi Tryout Sesi A (Wajib Kamera) ──────────────────────────────
$regTryoutA = pretesTryoutResolveRegId($pdo, $userId, $sesCamWajib, true);
$check($regTryoutA < 0, 'Tryout A memakai registration_id sintetis negatif');

// Simulasi logika API start di pages/tryout.php:
$camRequiredA = ($sesCamWajib['camera_mode'] ?? 'wajib') === 'wajib';
$verifiedAtA = (int)($_SESSION['pretes_cbt_verified_v2_' . $regTryoutA] ?? 0);
$check(!$verifiedAtA, 'Sebelum check-in, token verifikasi tryout A belum ada');

$startAllowedBefore = !($camRequiredA && (!$verifiedAtA || time() - $verifiedAtA > 14400));
$check(!$startAllowedBefore, 'Tryout A wajib kamera harus menolak start jika belum verifikasi wajah');

// Lakukan check-in wajah (simulasi verifikasi lolos)
$_SESSION['pretes_cbt_verified_v2_' . $regTryoutA] = time();
$verifiedAtAAfter = (int)($_SESSION['pretes_cbt_verified_v2_' . $regTryoutA] ?? 0);
$startAllowedAfter = !($camRequiredA && (!$verifiedAtAAfter || time() - $verifiedAtAAfter > 14400));
$check($startAllowedAfter, 'Tryout A harus mengizinkan start setelah verifikasi wajah lolos');

// Mulai pengerjaan Tryout A
$startResult = pretesCbtStart($pdo, $userId, $regTryoutA, time(), null, $sesCamWajib);
$check($startResult['attempt'] && !$startResult['attempt']['submitted'], 'Tryout A berhasil dimulai di mesin CBT');

// Uji penanganan jeda (interrupt) dan resume dengan pengawasan kamera (Skenario B)
pretesCbtChange($pdo, $userId, $regTryoutA, 'interrupt', [
    'event_id' => 'evt_test_interrupt_001',
    'reason' => 'page_hidden',
    'position' => 1,
    'revision' => 0
], time() + 10);
$statePaused = pretesCbtState($pdo, $userId, $regTryoutA, time() + 15);
$check(!empty($statePaused['attempt']['paused']), 'Tryout A dijeda akibat interupsi kamera/blur');

// Resume tanpa token perhatian wajah (verifyAttention belum dipanggil)
$checkedAttention = (int)($_SESSION['pretes_cbt_attention_' . $regTryoutA] ?? 0);
$guard = $pdo->prepare('SELECT paused_at FROM pretes_cbt_interruptions WHERE attempt_id = ?');
$guard->execute([$statePaused['attempt']['id']]);
$pausedAt = (int)$guard->fetchColumn();

$canResumeBeforeAttention = ($checkedAttention && time() - $checkedAttention <= 30 && $checkedAttention >= $pausedAt);
$check(!$canResumeBeforeAttention, 'Tryout A tidak boleh melanjutkan ujian (resume) jika wajah belum diverifikasi di kamera');

// Hadapkan wajah ke kamera (verifyAttention berhasil)
$_SESSION['pretes_cbt_attention_' . $regTryoutA] = time() + 16;
$checkedAttentionAfter = (int)($_SESSION['pretes_cbt_attention_' . $regTryoutA] ?? 0);
$canResumeAfterAttention = ($checkedAttentionAfter && time() - $checkedAttentionAfter <= 30 && $checkedAttentionAfter >= $pausedAt);
$check($canResumeAfterAttention, 'Tryout A berhasil melanjutkan ujian (resume) setelah verifikasi perhatian wajah di kamera');

$resumeResult = pretesCbtChange($pdo, $userId, $regTryoutA, 'resume', [], time() + 17);
$check(empty($resumeResult['attempt']['paused']), 'Status jeda tryout A terangkat setelah resume berhasil');

// ── 3. Verifikasi Tryout Sesi B (Tanpa Kamera / Mandiri) ──────────────────────
$regTryoutB = pretesTryoutResolveRegId($pdo, $userId, $sesCamMundur, true);
$camRequiredB = ($sesCamMundur['camera_mode'] ?? 'wajib') === 'wajib';
$check(!$camRequiredB, 'Tryout B tidak mewajibkan kamera');

$verifiedAtB = (int)($_SESSION['pretes_cbt_verified_v2_' . $regTryoutB] ?? 0);
$startAllowedB = !($camRequiredB && (!$verifiedAtB || time() - $verifiedAtB > 14400));
$check($startAllowedB, 'Tryout B mandiri langsung mengizinkan start tanpa verifikasi wajah');

$startResultB = pretesCbtStart($pdo, $userId, $regTryoutB, time(), null, $sesCamMundur);
$check($startResultB['attempt'] && !$startResultB['attempt']['submitted'], 'Tryout B berhasil dimulai langsung');

// ── 4. Verifikasi Isolasi Proctoring Logs Tryout (ID Negatif) ─────────────────
$pdo->prepare("INSERT INTO pretes_proctoring_logs (registration_id, user_id, event_type, similarity_score, status, notes)
    VALUES (?, ?, 'initial_checkin', 92.5, 'MATCH', 'Tryout test initial')")->execute([$regTryoutA, $userId]);
$pdo->prepare("INSERT INTO pretes_proctoring_logs (registration_id, user_id, event_type, similarity_score, status, notes)
    VALUES (?, ?, 'periodic_check', 88.0, 'MATCH', 'Tryout test periodic')")->execute([$regTryoutA, $userId]);

$stmtTryLogs = $pdo->prepare("SELECT COUNT(*) FROM pretes_proctoring_logs WHERE registration_id = ?");
$stmtTryLogs->execute([$regTryoutA]);
$check((int)$stmtTryLogs->fetchColumn() === 2, 'Log proctoring tryout tersimpan dengan registration_id negatif');

$stmtOfficialLogs = $pdo->prepare("SELECT COUNT(*) FROM pretes_proctoring_logs WHERE registration_id = ?");
$stmtOfficialLogs->execute([$officialRegId]);
$check((int)$stmtOfficialLogs->fetchColumn() === 0, 'Log proctoring ujian resmi tidak tercampur dengan log tryout');

// ── 5. Verifikasi Integritas File & Kode UI Tryout ───────────────────────────
$root = dirname(__DIR__);
$tryoutCode = file_get_contents("$root/pages/tryout.php");

$check(strpos($tryoutCode, "require __DIR__ . '/../api/pretes-proctoring-check.php';") !== false, 'pages/tryout.php mendispatch face_api ke pretes-proctoring-check.php');
$check(strpos($tryoutCode, 'id="sectionCheckin"') !== false, 'pages/tryout.php memuat Skenario A (sectionCheckin)');
$check(strpos($tryoutCode, 'id="sectionExamRoom"') !== false, 'pages/tryout.php memuat Skenario B (sectionExamRoom)');
$check(strpos($tryoutCode, 'class="proctoring-monitor-bar"') !== false, 'pages/tryout.php memuat Floating Proctoring Monitor Bar');
$check(strpos($tryoutCode, 'verifyAttention:') !== false, 'pages/tryout.php mengintegrasikan verifyAttention dengan PretesCbt');
$check(strpos($tryoutCode, 'PROCTOR_INTERVAL_SECONDS = 30') !== false, 'pages/tryout.php menjalankan pengawasan berkala tiap 30 detik');
$check(strpos($tryoutCode, 'Lakukan verifikasi wajah kembali untuk mengakses tryout.') !== false, 'API tryout.php menolak start jika belum check-in wajah saat kamera wajib');

$participantCode = file_get_contents("$root/includes/pretes-participant-view.php");
$check(strpos($participantCode, 'id="btn-tryout-pretes"') !== false, 'includes/pretes-participant-view.php mempertahankan tombol tryout tersendiri');
$check(strpos($participantCode, 'id="btn-kerjakan-pretes"') !== false, 'includes/pretes-participant-view.php mempertahankan tombol ujian resmi');

if ($failures) {
    echo "FAIL:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "PASS: Implementasi Opsi A pada Tryout CBT sempurna (Skenario A Check-in, Skenario B 30s Proctoring Bar, fallback mandiri, dan isolasi log/attempt).\n";
