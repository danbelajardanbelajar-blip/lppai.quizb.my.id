<?php
/**
 * Test getStudentPretesStage(): penentuan tahap pretes mahasiswa dan prioritas tampilan
 * yang dipakai bersama oleh tutorial-pengumuman, tutorial-pendaftaran, tutorial-info,
 * rekap-nilai, dan ambil-sertifikat.
 */
require_once __DIR__ . '/../includes/pretes-stage.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("
    CREATE TABLE tutorial_registrations (id INTEGER PRIMARY KEY, user_id INT, status TEXT, nomor_sertifikat TEXT, created_at TEXT);
    CREATE TABLE pretes_registrations (id INTEGER PRIMARY KEY, user_id INT, periode TEXT, hari_pilihan TEXT, jam TEXT, ruangan TEXT, dosen_penguji TEXT);
    CREATE TABLE pretes_assessments (registration_id INTEGER PRIMARY KEY, scores_json TEXT);
    CREATE TABLE pretes_results (id INTEGER PRIMARY KEY, user_id INT, status_lulus TEXT);
    CREATE TABLE master_pretes (id INTEGER PRIMARY KEY, periode TEXT, status TEXT, tanggal_mulai TEXT);
");
$pdo->exec("INSERT INTO master_pretes VALUES (1,'Pretes Lama','nonaktif',NULL), (2,'Pretes RPL 2026/2027','aktif','2026-11-16')");

$keys = array_keys(pretesComponents());
$all = function ($v) use ($keys) { return json_encode(array_fill_keys($keys, $v)); };
$partial = json_encode([$keys[0] => 90, $keys[1] => 85]);

$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };

// U1: pretes aktif, belum dinilai → berlangsung, showOngoing
$pdo->exec("INSERT INTO pretes_registrations VALUES (1,1,'Pretes RPL 2026/2027','Senin','13.00 WIB','F.Ekonomi 103','Dr. X')");
$s = getStudentPretesStage($pdo, ['id' => 1, 'role' => 'mahasiswa']);
$check($s['state'] === 'berlangsung' && $s['showOngoing'], 'U1 harus berlangsung & showOngoing');
$check(strpos($s['tanggal'], '16') !== false, 'U1 tanggal Senin harus 16 November: ' . $s['tanggal']);

// U2: pretes aktif, nilai sebagian ≥80 → tetap berlangsung, filled=2
$pdo->exec("INSERT INTO pretes_registrations VALUES (2,2,'Pretes RPL 2026/2027','Selasa','13.00 WIB','','')");
$pdo->exec("INSERT INTO pretes_assessments VALUES (2,'$partial')");
$s = getStudentPretesStage($pdo, ['id' => 2]);
$check($s['state'] === 'berlangsung' && $s['filled'] === 2, 'U2 berlangsung dengan 2 komponen');

// U3: ada nilai <80 → tidak_lulus, tidak showOngoing
$pdo->exec("INSERT INTO pretes_registrations VALUES (3,3,'Pretes RPL 2026/2027','Rabu','','','')");
$pdo->exec("INSERT INTO pretes_assessments VALUES (3,'" . json_encode([$keys[0] => 70]) . "')");
$s = getStudentPretesStage($pdo, ['id' => 3]);
$check($s['state'] === 'tidak_lulus' && !$s['showOngoing'], 'U3 tidak_lulus');

// U4: semua ≥80 → lulus, showLulus
$pdo->exec("INSERT INTO pretes_registrations VALUES (4,4,'Pretes RPL 2026/2027','Kamis','','','')");
$pdo->exec("INSERT INTO pretes_assessments VALUES (4,'" . $all(85) . "')");
$s = getStudentPretesStage($pdo, ['id' => 4]);
$check($s['state'] === 'lulus' && $s['showLulus'] && !$s['showOngoing'], 'U4 lulus');

// U5: periode lama nonaktif, nilai belum lengkap → tidak_lulus
$pdo->exec("INSERT INTO pretes_registrations VALUES (5,5,'Pretes Lama','Senin','','','')");
$s = getStudentPretesStage($pdo, ['id' => 5]);
$check($s['state'] === 'tidak_lulus' && !$s['showOngoing'], 'U5 periode ditutup → tidak_lulus');

// U6: pretes berlangsung tetapi sudah terdaftar tutorial → tidak showOngoing (tutorial diprioritaskan)
$pdo->exec("INSERT INTO pretes_registrations VALUES (6,6,'Pretes RPL 2026/2027','Senin','','','')");
$pdo->exec("INSERT INTO tutorial_registrations VALUES (1,6,'terdaftar',NULL,'2026-11-10')");
$s = getStudentPretesStage($pdo, ['id' => 6]);
$check($s['state'] === 'berlangsung' && !$s['showOngoing'] && $s['isTutorialOngoing'], 'U6 tutorial berjalan diprioritaskan');

// U7: tanpa pendaftaran pretes → none
$s = getStudentPretesStage($pdo, ['id' => 7]);
$check($s['state'] === 'none' && !$s['showOngoing'], 'U7 none');

// Integrasi: keempat halaman memanggil gate & tutorial-pengumuman memakai helper
$root = dirname(__DIR__);
foreach (['pages/tutorial-pendaftaran.php', 'pages/tutorial-info.php', 'rekap-nilai.php', 'pages/ambil-sertifikat.php'] as $f) {
    $check(strpos(file_get_contents("$root/$f"), 'pretesStageGate(') !== false, "$f harus memanggil pretesStageGate()");
}
$check(strpos(file_get_contents("$root/pages/tutorial-pengumuman.php"), 'renderPretesStageCard(') !== false, 'tutorial-pengumuman harus memakai renderPretesStageCard()');

if ($failures) {
    echo "FAIL:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "PASS: pretes-stage tests (7 skenario status + integrasi 5 halaman) berhasil.\n";
