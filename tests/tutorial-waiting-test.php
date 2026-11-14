<?php
/**
 * Test getStudentTutorialWaiting() & integrasi kartu "Menunggu Plotting" di
 * tutorial-pengumuman, rekap-nilai, ambil-sertifikat (seragam dengan Info Tutorial).
 */
require_once __DIR__ . '/../includes/tutorial-waiting.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("
    CREATE TABLE tutorial_classes (id INTEGER PRIMARY KEY, nama_kelas TEXT, gelombang TEXT);
    CREATE TABLE tutorial_registrations (id INTEGER PRIMARY KEY, user_id INT, status TEXT, tahun_ajaran TEXT, gelombang TEXT, tutorial_class_id INT, created_at TEXT);
");
$pdo->exec("INSERT INTO tutorial_classes VALUES (1, 'Kelas A', 'gel1')");
$pdo->exec("INSERT INTO tutorial_registrations VALUES
    (1, 1, 'terdaftar', '2026-2027', 'gel1', NULL, '2026-11-10'),
    (2, 2, 'aktif',     '2026-2027', 'gel1', 1,    '2026-11-10'),
    (3, 3, 'terdaftar', '2026-2027', 'gel1', 1,    '2026-11-10'),
    (4, 4, 'lulus',     '2026-2027', 'gel1', 1,    '2026-11-10'),
    (5, 5, 'aktif',     '2026-2027', 'gel1', NULL, '2026-11-10')
");

$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };

$check(getStudentTutorialWaiting($pdo, ['id' => 1]) !== null, 'U1 terdaftar tanpa kelas → menunggu plotting');
$check(getStudentTutorialWaiting($pdo, ['id' => 2]) === null, 'U2 aktif dengan kelas → sudah diplot');
$check(getStudentTutorialWaiting($pdo, ['id' => 3]) === null, 'U3 terdaftar dengan kelas → sudah diplot (bukan menunggu)');
$check(getStudentTutorialWaiting($pdo, ['id' => 4]) === null, 'U4 lulus → bukan menunggu');
$check(getStudentTutorialWaiting($pdo, ['id' => 5]) !== null, 'U5 aktif tanpa kelas → menunggu');
$check(getStudentTutorialWaiting($pdo, ['id' => 9]) === null, 'U9 tanpa pendaftaran → null');

// Render kartu memuat elemen kunci yang sama dengan Info Tutorial
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test');
ob_start();
renderTutorialWaitingCard(getStudentTutorialWaiting($pdo, ['id' => 1]), ['nama_lengkap' => 'Tes', 'nim' => '123'], 'catatan');
$html = ob_get_clean();
foreach ([
    'Pendaftaran Anda Telah Diterima &amp; Sedang Menunggu Jadwal Plotting',
    'ti-waiting-card', '2. Plotting Kelas', 'Sedang Diproses TU',
    'Silakan Pelajari Diktat Tutorial', 'tutorial-info.php#diktat', 'wa.me/6281515726827',
    'Gelombang 1 (Ganjil)', 'Menunggu Plotting',
] as $needle) {
    $check(strpos($html, $needle) !== false, "Kartu harus memuat: $needle");
}

$root = dirname(__DIR__);
foreach (['pages/tutorial-pengumuman.php', 'rekap-nilai.php', 'pages/ambil-sertifikat.php'] as $f) {
    $check(strpos(file_get_contents("$root/$f"), 'tutorialWaitingGate(') !== false, "$f harus memanggil tutorialWaitingGate()");
}

if ($failures) {
    echo "FAIL:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "PASS: tutorial-waiting tests (6 skenario plotting + render kartu + integrasi 3 halaman) berhasil.\n";
