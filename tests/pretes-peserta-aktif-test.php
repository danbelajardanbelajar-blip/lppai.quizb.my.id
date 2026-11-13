<?php
/**
 * Test diferensiasi halaman pretes-peserta.php:
 * - peserta terdaftar pada periode pretes aktif → halaman peserta/jadwal aktif;
 * - selain itu → renderActivePretesParticipantPage() false dan pretes-peserta.php memuat pretes-hasil.php.
 */
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test');
require_once __DIR__ . '/../includes/pretes-active-page.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("
    CREATE TABLE master_pretes (id INTEGER PRIMARY KEY, periode TEXT, tahun_ajaran TEXT, status TEXT, target_program TEXT DEFAULT 'Semua', tanggal_mulai TEXT);
    CREATE TABLE pretes_registrations (id INTEGER PRIMARY KEY, user_id INT, periode TEXT, pretes_schedule_id INT, hari_pilihan TEXT);
    CREATE TABLE pretes_schedules (id INTEGER PRIMARY KEY, periode TEXT, tanggal TEXT, waktu_mulai TEXT, waktu_selesai TEXT, ruangan TEXT, status TEXT);
");
$pdo->exec("INSERT INTO master_pretes (id, periode, tahun_ajaran, status, target_program) VALUES
    (1, 'Pretes Lama', '2025/2026', 'nonaktif', 'Semua'),
    (2, 'Pretes 2026/2027', '2026/2027', 'aktif', 'Semua')");
$pdo->exec("INSERT INTO pretes_registrations (id, user_id, periode) VALUES (1, 2, 'Pretes Lama')");

$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };

// U1: tidak terdaftar sama sekali → bukan peserta aktif
ob_start();
$r1 = renderActivePretesParticipantPage($pdo, ['id' => 1, 'role' => 'mahasiswa', 'jenis_program' => 'Reguler']);
$out1 = ob_get_clean();
$check($r1 === false && $out1 === '', 'U1 tanpa pendaftaran harus false tanpa output');

// U2: hanya terdaftar di periode lama (tidak aktif) → bukan peserta aktif
ob_start();
$r2 = renderActivePretesParticipantPage($pdo, ['id' => 2, 'role' => 'mahasiswa', 'jenis_program' => 'Reguler']);
$out2 = ob_get_clean();
$check($r2 === false && $out2 === '', 'U2 peserta periode tidak aktif harus false tanpa output');

// U3: admin/dosen → false
$check(renderActivePretesParticipantPage($pdo, ['id' => 3, 'role' => 'admin']) === false, 'U3 admin harus false');

// Integrasi statis
$root = dirname(__DIR__);
$peserta = file_get_contents("$root/pages/pretes-peserta.php");
$helper  = file_get_contents("$root/includes/pretes-active-page.php");
$check(strpos($peserta, 'renderActivePretesParticipantPage(') !== false, 'pretes-peserta.php harus memanggil renderActivePretesParticipantPage()');
$check(strpos($peserta, "require __DIR__ . '/pretes-hasil.php';") !== false, 'pretes-peserta.php harus memuat pretes-hasil.php untuk non-peserta aktif');
$check(strpos($helper, 'if (!$myReg) return false;') !== false, 'Helper harus mensyaratkan pendaftaran pada periode aktif');

if ($failures) {
    echo "FAIL:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "PASS: pretes-peserta aktif vs tidak aktif (3 skenario + integrasi) berhasil.\n";
