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

// Link Try Out
$check(pretesExamTryoutLink($pdo) === null, 'Tanpa tryout aktif → tidak ada link');
$tid = pretesCbtSaveSession($pdo, ['title' => 'TO 1', 'package_ids' => [1], 'mode' => 'tryout', 'is_active' => 1]);
$link = pretesExamTryoutLink($pdo);
$check($link && strpos($link['url'], 'tryout.php?sesi=' . $tid) !== false, 'Satu tryout aktif → link langsung ke sesinya');

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
$check(strpos(file_get_contents("$root/includes/sidebar.php"), 'pretesExamIsRegisteredActive(') !== false, 'Menu Tryout hanya untuk peserta terdaftar');
$check(strpos(file_get_contents("$root/pages/dashboard.php"), 'pretesExamAccess($pdo, $user)') !== false, 'Dashboard memakai pretesExamAccess');

if ($failures) { echo "FAIL:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
echo "PASS: akses ujian pretes per mahasiswa (terdaftar aktif, jadwal hari pilihan 13.00 WIB, sakelar admin, lulus, try out, integrasi).\n";
