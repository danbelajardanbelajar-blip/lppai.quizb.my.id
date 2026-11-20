<?php
/**
 * Test: Tab "Semua Mahasiswa" (admin/pretes-peserta.php?tab=semua) + ekspor sesuai filter.
 * Status: sudah_pretes / belum_pretes (sudah daftar) / belum_daftar, per periode terpilih.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$fail = 0;
$t = function (bool $ok, string $msg) use (&$fail): void { echo ($ok ? ' [PASS] ' : '❌ [FAIL] ') . $msg . "\n"; if (!$ok) $fail++; };

require_once $root . '/includes/pretes-all-students.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, nama_lengkap TEXT, nim TEXT, username TEXT, program_studi TEXT, jenis_program TEXT, role TEXT)");
$pdo->exec("ALTER TABLE users ADD COLUMN angkatan TEXT");
$pdo->exec("INSERT INTO users VALUES
 (1,'Ani Sudah','2601','2601','PAI','Reguler','mahasiswa',NULL),
 (2,'Budi Belum Pretes','2602','2602','PAI','RPL','mahasiswa','2024'),
 (3,'Cici Belum Daftar','2603','2603','Hukum','Reguler','mahasiswa',''),
 (4,'Dedi Periode Lama','2604','2604','Hukum','Reguler','mahasiswa','2025'),
 (5,'Eka Impor','2605','2605','Teknik','Reguler','mahasiswa','2026'),
 (6,'Akun Uji','12345','12345','PAI','Reguler','mahasiswa',NULL),
 (7,'Dosen X','','dosenx','PAI','Reguler','dosen',NULL)");
$pdo->exec("CREATE TABLE pretes_registrations (id INTEGER PRIMARY KEY, user_id INT, periode TEXT, hari_pilihan TEXT, jam TEXT, foto TEXT)");
$pdo->exec("INSERT INTO pretes_registrations VALUES (10,1,'P2026','senin','13.00',''),(20,2,'P2026','Rabu','13.00',''),(40,4,'P2025','Kamis','13.00',''),(50,5,'P2026','Rabu','13.00','')");
$pdo->exec("CREATE TABLE pretes_cbt_attempts (id INTEGER PRIMARY KEY, user_id INT, registration_id INT, started_at INT, deadline_at INT, submitted_at INT NULL, score REAL NULL)");
$pdo->exec("INSERT INTO pretes_cbt_attempts VALUES (1,1,10,1,2,2,88),(2,2,20,1,99999999999,NULL,NULL),(3,4,40,1,2,2,70)");
$pdo->exec("CREATE TABLE pretes_assessments (registration_id INT PRIMARY KEY, scores_json TEXT, assessed_by INT)");
$pdo->exec("INSERT INTO pretes_assessments VALUES (50,'{\"ujian_tulis\":79.5}',1)");

$rows = pretesAllStudentsRows($pdo, 'P2026');
$by = array_column($rows, null, 'user_id');
$t(count($rows) === 5 && !isset($by[6]) && !isset($by[7]), 'Semua mahasiswa (tanpa akun uji 12345 & dosen)');
$t($by[1]['status_pretes'] === 'sudah_pretes' && $by[1]['score'] === 88.0 && $by[1]['hari_pilihan'] === 'Senin', 'Sudah daftar + CBT resmi → sudah pretes (88), hari dinormalkan');
$t($by[2]['status_pretes'] === 'belum_pretes' && $by[2]['score'] === null, 'Sudah daftar, CBT belum dikumpulkan → belum pretes');
$t($by[3]['status_pretes'] === 'belum_daftar' && $by[3]['reg_id'] === 0, 'Tanpa pendaftaran → belum daftar');
$t($by[4]['status_pretes'] === 'belum_daftar', 'Hanya daftar periode lain → belum daftar pada periode terpilih');
$t($by[5]['status_pretes'] === 'sudah_pretes' && $by[5]['score'] === 79.5, 'Nilai impor ujian tulis → sudah pretes');
$all = array_column(pretesAllStudentsRows($pdo, '__all__'), null, 'user_id');
$t($all[4]['status_pretes'] === 'sudah_pretes' && $all[4]['periode'] === 'P2025', 'Semua periode: pendaftaran lama ikut dihitung');

$st = pretesAllStudentsStats($rows);
$t($st === ['total' => 5, 'sudah_pretes' => 2, 'belum_pretes' => 1, 'belum_daftar' => 2], 'Statistik per status');

$ids = static fn(array $r) => array_map(static fn($x) => $x['user_id'], $r);
$f = static fn(array $g) => pretesAllStudentsFilters($g);
$t($ids(pretesAllStudentsApply($rows, $f(['status_pretes' => 'belum_pretes']))) === [2], 'Filter belum pretes');
$t($ids(pretesAllStudentsApply($rows, $f(['status_pretes' => 'belum_daftar']))) === [3, 4], 'Filter belum daftar pretes');
$t($ids(pretesAllStudentsApply($rows, $f(['status_pretes' => 'belum_semua']))) === [2, 3, 4], 'Filter belum pretes + belum daftar');
$t($ids(pretesAllStudentsApply($rows, $f(['status_pretes' => 'sudah_pretes', 'prodi' => 'PAI']))) === [1], 'Filter sudah pretes + prodi');
$t($ids(pretesAllStudentsApply($rows, $f(['jenis_program' => 'rpl']))) === [2], 'Filter program RPL');
$t($ids(pretesAllStudentsApply($rows, $f(['hari' => 'Senin,Rabu']))) === [1, 2, 5], 'Filter hari multi (belum daftar tidak punya hari)');
$t($ids(pretesAllStudentsApply($rows, $f(['q' => 'cici']))) === [3] && $ids(pretesAllStudentsApply($rows, $f(['q' => '2602']))) === [2] && $ids(pretesAllStudentsApply($rows, $f(['q' => 'teknik']))) === [5], 'Cari nama / NIM / prodi (sama dengan kotak cari tabel)');
$t($f(['status_pretes' => 'xx'])['status_pretes'] === '', 'Status tidak dikenal diabaikan');
$t(str_contains(pretesAllStudentsFilterText($f(['status_pretes' => 'belum_daftar', 'hari' => 'Senin'])), 'Belum Daftar Pretes • Hari Senin'), 'Teks filter untuk ekspor');

// Angkatan (PRETES-TAB-ANGKATAN): kolom angkatan, atau dari 2 digit awal NIM
$t($by[1]['angkatan'] === '2026' && $by[2]['angkatan'] === '2024' && $by[3]['angkatan'] === '2026' && $by[4]['angkatan'] === '2025', 'Angkatan dari kolom, kosong → dari NIM (26… → 2026)');
$t(pretesStudentAngkatan(null, 'A12') === '' && pretesStudentAngkatan('abc', '2312') === '2023', 'Angkatan tidak valid → turunan NIM / kosong');
$t($ids(pretesAllStudentsApply($rows, $f(['angkatan' => '2026']))) === [1, 3, 5], 'Filter angkatan 2026');
$t($ids(pretesAllStudentsApply($rows, $f(['angkatan' => '2024', 'status_pretes' => 'belum_pretes']))) === [2], 'Filter angkatan + status');
$t($f(['angkatan' => '20x6'])['angkatan'] === '', 'Angkatan tidak valid diabaikan');
$t(str_contains(pretesAllStudentsFilterText($f(['angkatan' => '2025'])), 'Angkatan 2025'), 'Teks filter memuat angkatan');

// Integrasi halaman
$page = file_get_contents($root . '/admin/pretes-peserta.php');
$t(str_contains($page, "\$activeTab = ((\$_GET['tab'] ?? '') === 'semua') ? 'semua' : 'terdaftar';") && str_contains($page, 'data-pp-tab="semua"') && str_contains($page, "include __DIR__ . '/../includes/pretes-all-students-view.php'"), 'Halaman memiliki tab Semua Mahasiswa');
$t(str_contains($page, "<?php endif; // tab terdaftar ?>"), 'Tabel peserta terdaftar hanya tampil pada tab-nya');
$view = file_get_contents($root . '/includes/pretes-all-students-view.php');
foreach (['<th>No</th><th>Nama</th><th>NIM</th><th>Prodi</th><th>Program</th><th>Hari / Periode</th><th>Nilai Pretes</th>', 'value="belum_pretes"', 'value="belum_daftar"', 'id="btn-sm-export-pdf"', 'id="btn-sm-export-xlsx"', "url.searchParams.set('q', q)"] as $needle) {
    $t(str_contains($view, $needle), 'View memuat: ' . substr($needle, 0, 60));
}
$exp = file_get_contents($root . '/admin/pretes-mahasiswa-export.php');
$t(str_contains($exp, 'requireAdmin();') && str_contains($exp, 'pretesAllStudentsApply(pretesAllStudentsRows($pdo, $periode), $filters)') && str_contains($exp, "cbtWriteXlsx(") && str_contains($exp, 'window.print()'), 'Ekspor admin: Excel/CSV & PDF sesuai filter');

$t(str_contains($view, 'id="sm-angkatan"') && str_contains($view, '\'angkatan\' => $f[\'angkatan\']') && str_contains($exp, "'Angkatan', 'Program Studi'") && str_contains($exp, '<th style="width:8%">Angkatan</th>'), 'Filter angkatan di tampilan & kolom angkatan di ekspor');

if ($fail) { echo "\n$fail test gagal\n"; exit(1); }
echo "\nSemua test tab Semua Mahasiswa lulus.\n";
