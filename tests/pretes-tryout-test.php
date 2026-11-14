<?php
/**
 * Test mode Tryout CBT (includes/pretes-tryout.php + perubahan includes/pretes-cbt.php):
 * tryout terpisah dari ujian resmi, bisa diulang dengan batas, sesi resmi tetap aktif.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { $_SESSION = []; }
require_once __DIR__ . '/../includes/pretes-tryout.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensurePretesCbtTables($pdo);

$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };

// Bank: pastikan paket 1 punya ≥ 25 soal aktif
$active = (int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_questions WHERE package_id = 1 AND is_active = 1')->fetchColumn();
$check($active >= 25, "Seed bank harus ≥ 25 soal aktif (ada $active)");

$resmiId = (int)$pdo->query("SELECT id FROM pretes_cbt_sessions WHERE mode = 'resmi' AND is_active = 1")->fetchColumn();
$check($resmiId > 0, 'Sesi resmi bawaan harus aktif');

// Buat tryout aktif (maks 2×) → sesi resmi tetap aktif
$tryId = pretesCbtSaveSession($pdo, ['title' => 'Tryout 1', 'package_ids' => [1], 'mode' => 'tryout', 'max_attempts' => 2,
    'duration_minutes' => 30, 'camera_mode' => 'tidak_aktif', 'is_active' => 1]);
$check((int)$pdo->query("SELECT is_active FROM pretes_cbt_sessions WHERE id = $resmiId")->fetchColumn() === 1, 'Menyimpan tryout aktif tidak boleh menonaktifkan sesi resmi');
$check((int)(pretesCbtGetActiveSession($pdo)['id'] ?? 0) === $resmiId, 'Sesi aktif resmi tetap sesi resmi, bukan tryout');

// Tryout kedua (tanpa batas) juga aktif bersamaan
$try2 = pretesCbtSaveSession($pdo, ['title' => 'Tryout 2', 'package_ids' => [1], 'mode' => 'tryout', 'max_attempts' => 0, 'is_active' => 1]);
$check(count(pretesTryoutActiveSessions($pdo)) === 2, 'Dua tryout boleh aktif bersamaan');
pretesCbtActivateSession($pdo, $try2);
$check((int)$pdo->query("SELECT is_active FROM pretes_cbt_sessions WHERE id = $resmiId")->fetchColumn() === 1, 'Mengaktifkan tryout tidak menonaktifkan sesi resmi');
$check((int)$pdo->query("SELECT is_active FROM pretes_cbt_sessions WHERE id = $tryId")->fetchColumn() === 1, 'Mengaktifkan tryout lain tidak menonaktifkan tryout ini');

// Sesi resmi baru yang aktif hanya menonaktifkan sesi resmi lain
$resmi2 = pretesCbtSaveSession($pdo, ['title' => 'Resmi 2', 'package_ids' => [1], 'mode' => 'resmi', 'is_active' => 1]);
$check((int)$pdo->query("SELECT is_active FROM pretes_cbt_sessions WHERE id = $resmiId")->fetchColumn() === 0, 'Sesi resmi lama nonaktif saat resmi baru diaktifkan');
$check((int)$pdo->query("SELECT is_active FROM pretes_cbt_sessions WHERE id = $tryId")->fetchColumn() === 1, 'Tryout tetap aktif saat sesi resmi diganti');
pretesCbtActivateSession($pdo, $resmiId);
$check((int)$pdo->query("SELECT is_active FROM pretes_cbt_sessions WHERE id = $resmi2")->fetchColumn() === 0, 'Aktivasi resmi menonaktifkan resmi lain');
$check((int)$pdo->query("SELECT is_active FROM pretes_cbt_sessions WHERE id = $tryId")->fetchColumn() === 1, 'Aktivasi resmi tidak menyentuh tryout');

$userId = 77; $now = 1000000;
$session = pretesTryoutGetSession($pdo, $tryId);
$check($session && $session['max_attempts'] === 2, 'pretesTryoutGetSession mengembalikan sesi tryout + max_attempts');
$check(pretesTryoutGetSession($pdo, $resmiId) === null, 'Sesi resmi bukan sesi tryout');

// Tryout percobaan ke-1
$reg1 = pretesTryoutResolveRegId($pdo, $userId, $session);
$check($reg1 === pretesTryoutRegId($tryId, 1) && $reg1 < 0, 'Percobaan pertama memakai registration_id sintetis negatif');
$st = pretesCbtStart($pdo, $userId, $reg1, $now, null, $session);
$check($st['attempt'] && !$st['attempt']['submitted'] && count($st['attempt']['questions']) === 25, 'Tryout dimulai dengan 25 soal');
$sid = (int)$pdo->query("SELECT session_id FROM pretes_cbt_attempts WHERE registration_id = $reg1")->fetchColumn();
$check($sid === $tryId, 'Attempt tryout tercatat pada sesi tryout');
$check(pretesTryoutResolveRegId($pdo, $userId, $session, true) === $reg1, 'Selama belum dikumpulkan, ulangi tetap melanjutkan attempt yang sama');

// Ujian RESMI peserta yang sama (registration_id asli) tetap bisa dimulai & memakai sesi resmi
$official = pretesCbtStart($pdo, $userId, 5, $now);
$check($official['attempt'] && !$official['attempt']['submitted'], 'Ujian resmi tetap bisa dimulai walau sudah tryout');
$offSes = (int)$pdo->query('SELECT session_id FROM pretes_cbt_attempts WHERE user_id = 77 AND registration_id = 5')->fetchColumn();
$check($offSes === $resmiId, 'Attempt resmi memakai sesi resmi aktif, bukan tryout');

// Kumpulkan tryout ke-1, lalu ulangi → percobaan ke-2
pretesCbtChange($pdo, $userId, $reg1, 'submit', ['revision' => 0], $now + 60);
$attempts = pretesTryoutAttempts($pdo, $userId, $tryId);
$check(count($attempts) === 1 && $attempts[0]['submitted_at'] !== null, 'Tryout ke-1 terkumpul');
$check(pretesTryoutCanRetake($session, $attempts), 'Masih boleh mengulang (maks 2)');
$check(pretesTryoutResolveRegId($pdo, $userId, $session, false) === $reg1, 'Tanpa ulangi, state menunjuk hasil terakhir');
$reg2 = pretesTryoutResolveRegId($pdo, $userId, $session, true);
$check($reg2 === pretesTryoutRegId($tryId, 2), 'Ulangi membuat percobaan ke-2');
pretesCbtStart($pdo, $userId, $reg2, $now + 120, null, $session);
pretesCbtChange($pdo, $userId, $reg2, 'submit', ['revision' => 0], $now + 180);
$attempts = pretesTryoutAttempts($pdo, $userId, $tryId);
$check(count($attempts) === 2 && $attempts[1]['attempt_no'] === 2, 'Riwayat berisi percobaan ke-1 dan ke-2 berurutan');
$check(!pretesTryoutCanRetake($session, $attempts), 'Batas 2× tercapai');
try { pretesTryoutResolveRegId($pdo, $userId, $session, true); $check(false, 'Ulangi melebihi batas harus ditolak'); } catch (RuntimeException $e) {}

// Ujian resmi tidak terpengaruh tryout yang sudah dikumpulkan
$state = pretesCbtState($pdo, $userId, 5, $now + 200);
$check($state['attempt'] && !$state['attempt']['submitted'], 'Attempt resmi tetap berjalan (tidak tertimpa hasil tryout)');

// Label admin
$cache = [];
$lbl = pretesTryoutAttemptLabel($pdo, ['registration_id' => $reg2], $cache);
$check($lbl['kind'] === 'tryout' && strpos($lbl['label'], 'Tryout 1') !== false && strpos($lbl['label'], 'ke-2') !== false, 'Label tryout untuk admin');
$check(pretesTryoutAttemptLabel($pdo, ['registration_id' => 5], $cache)['kind'] === 'resmi', 'Label resmi untuk admin');

// Jadwal
$check(pretesTryoutWindow(['is_active' => 1, 'start_time' => date('Y-m-d H:i', time() + 3600)])['open'] === false, 'Tryout belum dibuka ditolak');
$check(pretesTryoutWindow(['is_active' => 1, 'end_time' => date('Y-m-d H:i', time() - 3600)])['open'] === false, 'Tryout yang lewat batas ditolak');
$check(pretesTryoutWindow(['is_active' => 0])['open'] === false, 'Tryout nonaktif ditolak');
$check(pretesTryoutWindow(['is_active' => 1])['open'] === true, 'Tryout tanpa jadwal terbuka');

// Integrasi statis
$root = dirname(__DIR__);
$page = file_get_contents("$root/pages/tryout.php");
$check(strpos($page, 'pretesCbtStart($pdo, $userId, $regId, null, $token, $session)') !== false, 'API tryout memaksa sesi tryout saat start');
$check(strpos($page, "'retake'") === false, 'Aksi lama retake tidak dipakai (diganti fresh)');
$check(strpos(file_get_contents("$root/includes/sidebar.php"), "tryout.php") !== false, 'Menu Tryout CBT ada di sidebar');

if ($failures) { echo "FAIL:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
echo "PASS: tryout CBT terpisah dari ujian resmi (sesi, attempt, ulang & batas, jadwal, label admin).\n";
