<?php
/**
 * Test aturan akses ujian CBT pretes per mahasiswa (includes/pretes-exam-access.php):
 * hanya peserta terdaftar periode aktif, jadwal pribadi hari pilihan pukul 13.00 WIB,
 * sakelar admin, lulus pretes, link Try Out, dan integrasi halaman/API/tryout/sidebar.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { $_SESSION = []; }
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test');
require_once __DIR__ . '/../includes/pretes-exam-access.php';
require_once __DIR__ . '/../includes/pretes-tryout.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("
    CREATE TABLE master_pretes (id INTEGER PRIMARY KEY, periode TEXT, tahun_ajaran TEXT, status TEXT, target_program TEXT DEFAULT 'Semua', tanggal_mulai TEXT);
    CREATE TABLE pretes_registrations (id INTEGER PRIMARY KEY, user_id INT, periode TEXT, hari_pilihan TEXT, jam TEXT, foto TEXT);
    CREATE TABLE pretes_results (id INTEGER PRIMARY KEY, user_id INT, status_lulus TEXT);
    CREATE TABLE pretes_assessments (registration_id INTEGER PRIMARY KEY, scores_json TEXT);
");
$pdo->exec("INSERT INTO master_pretes (id, periode, tahun_ajaran, status, target_program, tanggal_mulai) VALUES
    (1, 'Pretes Lama', '2025/2026', 'nonaktif', 'Semua', '2025-11-17'),
    (2, 'Pretes 2026/2027', '2026/2027', 'aktif', 'Reguler', '2026-11-16')");
$pdo->exec("INSERT INTO pretes_registrations (id, user_id, periode, hari_pilihan, jam) VALUES
    (10, 1, 'Pretes 2026/2027', 'Senin', '13.00 WIB (Jam 1)'),
    (11, 2, 'Pretes Lama', 'Senin', '13.00 WIB (Jam 1)'),
    (12, 4, 'Pretes 2026/2027', 'Rabu', '13.00 WIB (Jam 1)'),
    (13, 5, 'Pretes 2026/2027', 'Selasa', NULL)");
$pdo->exec("INSERT INTO pretes_results (user_id, status_lulus) VALUES (4, 'lulus')");
ensurePretesCbtTables($pdo);
$sid = (int)pretesCbtGetActiveSession($pdo)['id'];
pretesCbtSetExamOpen($pdo, $sid, true);

$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };
$tz = new DateTimeZone('Asia/Jakarta');
$at = fn(string $dt) => (new DateTimeImmutable($dt, $tz))->getTimestamp();
$mhs = fn(int $id, string $prog = 'Reguler') => ['id' => $id, 'role' => 'mahasiswa', 'jenis_program' => $prog];

// Terdaftar periode aktif, Senin 16 Nov 2026 pukul 13.00 WIB
$a = pretesExamAccess($pdo, $mhs(1), $at('2026-11-16 12:59:00'));
$check(!$a['allowed'] && $a['reason'] === 'before_schedule', 'Sebelum 13.00 hari pilihan → belum dibuka');
$check(($a['schedule']['label'] ?? '') === 'Senin, 16 November 2026 pukul 13.00 WIB', 'Label jadwal: ' . ($a['schedule']['label'] ?? '-'));
$check(pretesExamAccess($pdo, $mhs(1), $at('2026-11-16 13:00:00'))['allowed'] === true, 'Tepat 13.00 hari pilihan → dibuka');
$check(pretesExamAccess($pdo, $mhs(1), $at('2026-11-16 21:00:00'))['allowed'] === true, 'Masih hari yang sama → dibuka');
$check(pretesExamAccess($pdo, $mhs(1), $at('2026-11-17 00:00:01'))['reason'] === 'after_schedule', 'Lewat hari pilihan → berakhir');
$check(pretesExamAccess($pdo, $mhs(1), $at('2026-11-18 13:30:00'))['allowed'] === false, 'Hari lain (Rabu) tidak bisa untuk peserta Senin');

// Hari lain & jam default
$check((pretesExamAccess($pdo, $mhs(5), $at('2026-11-16 14:00:00'))['schedule']['label'] ?? '') === 'Selasa, 17 November 2026 pukul 13.00 WIB', 'Jam kosong → default 13.00, Selasa = 17 Nov');
$check(pretesExamAccess($pdo, $mhs(5), $at('2026-11-17 13:05:00'))['allowed'] === true, 'Peserta Selasa terbuka Selasa 13.05');

// Sakelar admin tertutup → tidak bisa walau sudah jadwal; pesan menyebut jadwal
pretesCbtSetExamOpen($pdo, $sid, false);
$c = pretesExamAccess($pdo, $mhs(1), $at('2026-11-16 13:30:00'));
$check(!$c['allowed'] && $c['reason'] === 'closed' && strpos($c['notice'], 'Senin, 16 November 2026') !== false, 'Ditutup admin → tidak bisa + jadwal disebut');
pretesCbtSetExamOpen($pdo, $sid, true);

// Bukan peserta aktif
$check(pretesExamAccess($pdo, $mhs(2), $at('2026-11-16 13:30:00'))['reason'] === 'not_registered', 'Hanya terdaftar periode lama → ditolak');
$check(pretesExamAccess($pdo, $mhs(3), $at('2026-11-16 13:30:00'))['reason'] === 'not_registered', 'Tidak terdaftar → ditolak');
$check(pretesExamAccess($pdo, $mhs(1, 'RPL'), $at('2026-11-16 13:30:00'))['reason'] === 'no_period', 'Program tidak sesuai target periode → ditolak');
$check(pretesExamAccess($pdo, ['id' => 1, 'role' => 'dosen'], $at('2026-11-16 13:30:00'))['reason'] === 'not_student', 'Dosen/admin → bukan mahasiswa');
$check(pretesExamAccess($pdo, $mhs(4), $at('2026-11-18 13:30:00'))['reason'] === 'already_passed', 'Sudah lulus pretes → tidak perlu ujian');

// Syarat melihat link & tryout
$check(pretesExamIsRegisteredActive($pdo, $mhs(1)) && !pretesExamIsRegisteredActive($pdo, $mhs(2)) && !pretesExamIsRegisteredActive($pdo, $mhs(3)), 'Hanya terdaftar periode aktif yang boleh melihat link/try out');

// Akun tester 12345: jadwal otomatis Minggu 22 Nov 2026 pukul 04.25 WIB
$testerUser = ['id' => 12345, 'username' => '12345', 'nim' => '12345', 'role' => 'mahasiswa', 'jenis_program' => 'Reguler'];
$tPre = pretesExamAccess($pdo, $testerUser, $at('2026-11-22 04:24:00'));
$check(!$tPre['allowed'] && $tPre['reason'] === 'before_schedule', 'Tester 12345 sebelum 04.25 Minggu → belum dibuka');
$tOpen = pretesExamAccess($pdo, $testerUser, $at('2026-11-22 04:26:00'));
$check($tOpen['allowed'] === true && $tOpen['reason'] === 'open', 'Tester 12345 setelah 04.25 Minggu → dibuka');
$check(strpos($tOpen['schedule']['label'] ?? '', 'Minggu, 22 November 2026 pukul 04.25 WIB') !== false, 'Label jadwal tester 12345 Minggu 22 Nov 04.25 WIB');

// Sunday/Ahad must match the RPL display and remain tied to the configured week.
$pdo->exec("INSERT INTO master_pretes (id,periode,tahun_ajaran,status,target_program,tanggal_mulai)
    VALUES(3,'Pretes RPL','2026/2027','aktif','RPL','2026-11-16')");
$pdo->exec("INSERT INTO pretes_registrations(id,user_id,periode,hari_pilihan,jam)
    VALUES(14,6,'Pretes RPL','Ahad','13.00 WIB')");
$rplUser = $mhs(6, 'RPL');
$check(pretesExamAccess($pdo,$rplUser,$at('2026-11-15 13:00:00'))['reason']==='before_schedule','Sunday Nov 15 is not the scheduled Sunday');
$check(pretesExamAccess($pdo,$rplUser,$at('2026-11-22 12:59:59'))['reason']==='before_schedule','Sunday before registered time remains closed');
$check(pretesExamAccess($pdo,$rplUser,$at('2026-11-22 13:00:00'))['allowed']===true,'Sunday Nov 22 opens at the registered time');
$check(pretesExamAccess($pdo,$rplUser,$at('2026-11-23 00:00:00'))['reason']==='after_schedule','Sunday closes at end of its scheduled day');
foreach (['Minggu','Ahad',' ahad '] as $day) {
    $schedule=pretesExamPersonalSchedule(['tanggal_mulai'=>'2026-11-16'],['hari_pilihan'=>$day,'jam'=>'13.00']);
    $check($schedule['opens_at']===$at('2026-11-22 13:00:00'),'Sunday alias uses +6 days: '.$day);
}
$nextWeek=pretesExamPersonalSchedule(['tanggal_mulai'=>'2026-11-23'],['hari_pilihan'=>'Ahad']);
$check($nextWeek['opens_at']===$at('2026-11-29 13:00:00'),'Future periods use their own Sunday, not a hardcoded today');
$displayDates=getPretesPeriodDayLabels(['tanggal_mulai'=>'2026-11-16','target_program'=>'RPL']);
$check($displayDates['days']['Ahad']==='Ahad, 22 November 2026','Display and exam access agree on Sunday Nov 22');

// Link Try Out
$check(pretesExamTryoutLink($pdo) === null, 'Tanpa tryout aktif → tidak ada link');
$tid = pretesCbtSaveSession($pdo, ['title' => 'TO 1', 'package_ids' => [1], 'mode' => 'tryout', 'is_active' => 1]);
$link = pretesExamTryoutLink($pdo);
$check($link && strpos($link['url'], 'tryout.php?sesi=' . $tid) !== false, 'Satu tryout aktif → link langsung ke sesinya');

// Render the real participant card: no frozen Nov 15 date or weekday-only date range.
if (!function_exists('sanitize')) {
    function sanitize($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
$renderSundayCard = static function () use ($pdo): string {
    $activePretes = ['tanggal_mulai'=>'2026-11-16','target_program'=>'RPL'];
    $myReg = ['periode'=>'Pretes RPL','hari_pilihan'=>'Ahad','jam'=>'13.00 WIB','status'=>'terdaftar'];
    $user = ['id'=>6,'role'=>'mahasiswa','jenis_program'=>'RPL'];
    $examSchedule = pretesExamPersonalSchedule($activePretes, $myReg);
    $examAccess = ['schedule'=>$examSchedule,'reason'=>'before_schedule'];
    $isUjianDibuka = false;
    $isTesterPrivileged = false;
    $schedules = [];
    ob_start();
    include __DIR__ . '/../includes/pretes-participant-view.php';
    return ob_get_clean();
};
$cardHtml = $renderSundayCard();
$check(strpos($cardHtml,'Ahad, 22 November 2026') !== false,'Actual participant card shows Sunday Nov 22');
$check(strpos($cardHtml,'16 – 22 November 2026') !== false,'Actual RPL card period range includes Sunday');
$check(strpos($cardHtml,'15 Nov 2026') === false,'Actual participant card no longer shows frozen Nov 15');

// Integrasi statis
$root = dirname(__DIR__);
$view = file_get_contents("$root/includes/pretes-participant-view.php");
$check(strpos($view, 'id="pretes-tryout-box"') !== false && strpos($view, '>TRY OUT<') !== false && strpos($view, 'bukan</u> ujian pretes resmi') !== false, 'Kotak Try Out berlabel jelas di posisi akses soal');
$check(strpos(file_get_contents("$root/includes/pretes-active-page.php"), 'pretesExamAccess($pdo, $user)') !== false, 'Halaman peserta memakai pretesExamAccess');
$ujian = file_get_contents("$root/pages/pretes-ujian.php");
$check(strpos($ujian, 'pretesExamAccess($pdo, $user)') !== false && strpos($ujian, 'WHERE pr.id = ? AND pr.user_id = ?') !== false, 'Halaman ujian hanya memakai pendaftaran periode aktif');
$api = file_get_contents("$root/api/pretes-cbt.php");
$check(strpos($api, "if (!\$examAccess['allowed']) throw new RuntimeException") !== false, 'API menolak start di luar akses');
$check(strpos(file_get_contents("$root/pages/tryout.php"), 'function tryoutUserAllowed') !== false, 'Tryout dibatasi peserta terdaftar');
$check(strpos(file_get_contents("$root/includes/sidebar.php"), 'tryout.php') === false, 'Menu Tryout CBT sudah dihilangkan dari sidebar');
$check(strpos(file_get_contents("$root/pages/dashboard.php"), 'pretesExamAccess($pdo, $user)') !== false, 'Dashboard memakai pretesExamAccess');

if ($failures) { echo "FAIL:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
echo "PASS: akses ujian pretes per mahasiswa (terdaftar aktif, jadwal hari pilihan 13.00 WIB, sakelar admin, lulus, try out, integrasi).\n";
