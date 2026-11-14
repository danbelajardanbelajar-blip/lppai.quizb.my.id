<?php
/**
 * Test sakelar admin "Buka / Tutup Ujian" pretes (exam_open) & penghapusan tanggal tertanam.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { $_SESSION = []; }
require_once __DIR__ . '/../includes/pretes-tryout.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensurePretesCbtTables($pdo);

$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };
$resmi = pretesCbtGetActiveSession($pdo);
$sid = (int)$resmi['id'];

// Instalasi baru: sesi bawaan terbuka (perilaku lama)
$check(pretesCbtExamStatus($pdo)['open'] === true, 'Sesi bawaan instalasi baru terbuka');

// Tutup → status closed, start ditolak, tester boleh
$check(pretesCbtSetExamOpen($pdo, $sid, false), 'Tutup ujian berhasil');
$st = pretesCbtExamStatus($pdo);
$check($st['open'] === false && $st['reason'] === 'closed' && $st['notice'] !== '', 'Status tertutup + pesan');
try { pretesCbtStart($pdo, 1, 10, 1000); $check(false, 'Mulai ujian saat ditutup harus ditolak'); }
catch (RuntimeException $e) { $check(strpos($e->getMessage(), 'ditutup oleh admin') !== false, 'Pesan penolakan jelas'); }
$tester = pretesCbtStart($pdo, 2, 11, 1000, null, null, true);
$check($tester['attempt'] !== null, 'Akun tester tetap dapat memulai saat ditutup');

// Buka → peserta bisa mulai
pretesCbtSetExamOpen($pdo, $sid, true);
$check(pretesCbtExamStatus($pdo)['open'] === true, 'Status terbuka setelah dibuka');
$a = pretesCbtStart($pdo, 1, 10, 2000);
$check($a['attempt'] !== null && !$a['attempt']['submitted'], 'Peserta dapat mulai setelah dibuka');

// Tutup saat sedang mengerjakan → tetap bisa simpan & kumpulkan
pretesCbtSetExamOpen($pdo, $sid, false);
$state = pretesCbtState($pdo, 1, 10, 2100);
$check($state['attempt'] && !$state['attempt']['submitted'], 'Attempt berjalan tetap dapat dilanjutkan');
$sub = pretesCbtChange($pdo, 1, 10, 'submit', ['revision' => 0], 2200);
$check($sub['attempt']['submitted'] === true, 'Attempt berjalan tetap dapat dikumpulkan walau ujian ditutup');

// Jadwal tetap berlaku saat sakelar dibuka
pretesCbtSetExamOpen($pdo, $sid, true);
$pdo->prepare('UPDATE pretes_cbt_sessions SET start_time = ? WHERE id = ?')->execute([date('Y-m-d H:i', time() + 3600), $sid]);
$check(pretesCbtExamStatus($pdo)['reason'] === 'not_started', 'Sakelar terbuka tetapi belum waktu mulai → not_started');
$pdo->prepare('UPDATE pretes_cbt_sessions SET start_time = NULL, end_time = ? WHERE id = ?')->execute([date('Y-m-d H:i', time() - 3600), $sid]);
$check(pretesCbtExamStatus($pdo)['reason'] === 'ended', 'Lewat batas waktu → ended');
$pdo->prepare('UPDATE pretes_cbt_sessions SET end_time = NULL WHERE id = ?')->execute([$sid]);

// Simpan sesi: tanpa field → nilai lama dipertahankan; field eksplisit → diterapkan; resmi baru dari modal default tertutup
pretesCbtSaveSession($pdo, ['id' => $sid, 'title' => 'Resmi', 'package_ids' => [1], 'is_active' => 1]);
$check((int)$pdo->query("SELECT exam_open FROM pretes_cbt_sessions WHERE id = $sid")->fetchColumn() === 1, 'Edit tanpa exam_open mempertahankan status');
pretesCbtSaveSession($pdo, ['id' => $sid, 'title' => 'Resmi', 'package_ids' => [1], 'is_active' => 1, 'exam_open' => '0']);
$check((int)$pdo->query("SELECT exam_open FROM pretes_cbt_sessions WHERE id = $sid")->fetchColumn() === 0, 'Edit dengan exam_open=0 menutup');
$new = pretesCbtSaveSession($pdo, ['title' => 'Resmi Baru', 'package_ids' => [1], 'is_active' => 1, 'exam_open' => '0']);
$check(pretesCbtExamStatus($pdo)['reason'] === 'closed' && (int)pretesCbtExamStatus($pdo)['session']['id'] === $new, 'Sesi resmi baru dari modal default tertutup');

// Sakelar tidak berlaku untuk tryout
$try = pretesCbtSaveSession($pdo, ['title' => 'Tryout', 'package_ids' => [1], 'mode' => 'tryout', 'is_active' => 1, 'exam_open' => '0']);
$check(pretesCbtSetExamOpen($pdo, $try, false) === false, 'Sakelar tidak mengubah sesi tryout');
$ts = pretesTryoutGetSession($pdo, $try);
$check(pretesCbtStart($pdo, 3, pretesTryoutRegId($try, 1), 3000, null, $ts)['attempt'] !== null, 'Tryout tetap bisa dimulai walau ujian resmi ditutup');

// Tidak ada sesi resmi aktif → tertutup
$pdo->exec("UPDATE pretes_cbt_sessions SET is_active = 0 WHERE mode IS NULL OR mode = '' OR mode = 'resmi'");
$check(pretesCbtExamStatus($pdo)['reason'] === 'no_session', 'Tanpa sesi resmi aktif → no_session');

// Integrasi statis: tanggal tertanam sudah dihapus, semua titik memakai status terpusat
$root = dirname(__DIR__);
foreach (['includes/pretes-active-page.php', 'pages/dashboard.php', 'pages/pretes-ujian.php'] as $f) {
    $src = file_get_contents("$root/$f");
    $check(strpos($src, 'pretesCbtExamStatus(') !== false, "$f memakai pretesCbtExamStatus");
    $check(strpos($src, "'2026-11-16'") === false, "$f tidak lagi memakai tanggal tertanam");
}
$view = file_get_contents("$root/includes/pretes-cbt-admin-view.php");
$check(strpos($view, 'value="set_exam_open"') !== false && strpos($view, 'id="examOpenToggle"') !== false, 'Admin punya sakelar Buka/Tutup Ujian');
$check(strpos(file_get_contents("$root/admin/pretes-bank-soal.php"), "\$action === 'set_exam_open'") !== false, 'Controller menangani set_exam_open');
$check(strpos(file_get_contents("$root/api/pretes-cbt.php"), 'null, $tokenInput, null, $isTester') !== false, 'API meneruskan bypass tester');

if ($failures) { echo "FAIL:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
echo "PASS: sakelar Buka/Tutup Ujian pretes (status, start ditolak/diizinkan, attempt berjalan aman, jadwal, tryout terpisah, tanpa tanggal tertanam).\n";
