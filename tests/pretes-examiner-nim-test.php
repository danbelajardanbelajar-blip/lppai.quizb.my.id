<?php
/**
 * Test identitas penguji pretes berbasis NIM (includes/pretes-examiner.php) + integrasi halaman dosen.
 */
require_once __DIR__ . '/../includes/pretes-helper.php';
require_once __DIR__ . '/../includes/pretes-examiner.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, nama_lengkap TEXT, nim TEXT, role TEXT)");
$pdo->exec("INSERT INTO users VALUES
    (1, 'Dr. Ida Latifatul Umroh, M.Pd.I', '0711', 'dosen'),
    (2, 'Ahmad Fauzi', '0722', 'dosen'),
    (3, 'Budi Santoso', '0733', 'dosen'),
    (4, 'Budi Santoso', '0744', 'dosen'),
    (5, 'Tanpa Nim', '', 'dosen'),
    (6, 'Siti Mahasiswa', '2401', 'mahasiswa')");
$pdo->exec("CREATE TABLE master_pretes (id INTEGER PRIMARY KEY, periode TEXT, status TEXT, penguji_senin TEXT, penguji_selasa TEXT, penguji_rabu TEXT, penguji_kamis TEXT, penguji_jumat TEXT)");
$pdo->exec("INSERT INTO master_pretes VALUES
    (1, 'Pretes Lama', 'nonaktif', 'Ahmad Fauzi', '', '', '', ''),
    (2, 'Pretes 2026/2027', 'aktif', 'Dr. Ida Latifatul Umroh, M.Pd.I|||Ahmad Fauzi', '', '', '', '')");
$pdo->exec("CREATE TABLE pretes_registrations (id INTEGER PRIMARY KEY, user_id INT, periode TEXT, hari_pilihan TEXT, dosen_penguji TEXT)");
$pdo->exec("INSERT INTO pretes_registrations VALUES
    (10, 6, 'Pretes 2026/2027', 'Senin', 'Dr. Ida Latifatul Umroh, M.Pd.I'),
    (11, 6, 'Pretes 2026/2027', 'Senin', 'dr ida latifatul umroh  m pd i'),
    (12, 6, 'Pretes Lama', 'Senin', 'Ahmad Fauzi'),
    (13, 6, 'Pretes 2026/2027', 'Senin', 'Budi Santoso'),
    (14, 6, 'Pretes 2026/2027', 'Senin', NULL),
    (15, 6, 'Pretes 2026/2027', 'Senin', 'Nama Tidak Dikenal')");

$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };
$dosen = fn($nim, $nama = 'X') => ['role' => 'dosen', 'nim' => $nim, 'nama_lengkap' => $nama];

// Pemetaan nama → NIM
$check(pretesExaminerNimByName($pdo, 'Dr. Ida Latifatul Umroh, M.Pd.I') === '0711', 'Nama persis → NIM');
$check(pretesExaminerNimByName($pdo, '  dr IDA latifatul umroh m.pd.i ') === '0711', 'Nama dengan beda huruf/tanda baca/spasi → NIM yang sama');
$check(pretesExaminerNimByName($pdo, 'Budi Santoso') === null, 'Nama ganda (dua dosen) → tidak dipetakan (aman)');
$check(pretesExaminerNimByName($pdo, 'Tanpa Nim') === null && pretesExaminerNimByName($pdo, 'Siti Mahasiswa') === null, 'Dosen tanpa NIM / mahasiswa tidak dipetakan');

// Backfill kolom
pretesExaminerEnsure($pdo);
$nims = $pdo->query('SELECT id, dosen_penguji_nim FROM pretes_registrations ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$check($nims[10] === '0711' && $nims[11] === '0711' && $nims[12] === '0722', 'Backfill NIM dari nama (termasuk ejaan berbeda)');
$check($nims[13] === null && $nims[14] === null && $nims[15] === null, 'Nama ganda/kosong/tidak dikenal tidak diberi NIM');

// Kepemilikan penugasan
$check(pretesExaminerOwns($pdo, $dosen('0711'), ['dosen_penguji_nim' => '0711']), 'Pemilik via NIM tersimpan');
$check(pretesExaminerOwns($pdo, $dosen('0711'), ['dosen_penguji' => 'DR. IDA LATIFATUL UMROH M.PD.I']), 'Pemilik via nama → NIM bila kolom kosong');
$check(!pretesExaminerOwns($pdo, $dosen('0722'), ['dosen_penguji_nim' => '0711']), 'Dosen lain bukan pemilik');
$check(!pretesExaminerOwns($pdo, $dosen(''), ['dosen_penguji_nim' => '']), 'Tanpa NIM tidak pernah pemilik');
$check(pretesCanAssess($dosen('0711'), ['dosen_penguji_nim' => '0711'], $pdo) && !pretesCanAssess($dosen('0722'), ['dosen_penguji_nim' => '0711'], $pdo), 'pretesCanAssess memakai NIM');

// Terjadwal pada periode aktif
$check(pretesExaminerIsScheduled($pdo, $dosen('0711')), 'Ida terjadwal (punya peserta periode aktif)');
$check(pretesExaminerIsScheduled($pdo, $dosen('0722')), 'Ahmad terjadwal (tercantum daftar penguji periode aktif walau belum diplot)');
$check(!pretesExaminerIsScheduled($pdo, $dosen('0733')), 'Budi (nama ganda, tanpa penugasan NIM) tidak terjadwal');
$check(!pretesExaminerIsScheduled($pdo, $dosen('')), 'Dosen tanpa NIM tidak terjadwal');
$check(!pretesExaminerIsScheduled($pdo, ['role' => 'mahasiswa', 'nim' => '2401']), 'Mahasiswa bukan penguji');

// Resync setelah plotting & pindah NIM
$pdo->exec("UPDATE pretes_registrations SET dosen_penguji = 'Ahmad Fauzi' WHERE id = 13");
pretesExaminerResync($pdo, 'Pretes 2026/2027');
$check($pdo->query('SELECT dosen_penguji_nim FROM pretes_registrations WHERE id = 13')->fetchColumn() === '0722', 'Resync mengisi NIM setelah penugasan diubah');
$pdo->exec("UPDATE pretes_registrations SET dosen_penguji = NULL WHERE id = 13");
pretesExaminerResync($pdo, 'Pretes 2026/2027');
$check($pdo->query('SELECT dosen_penguji_nim FROM pretes_registrations WHERE id = 13')->fetchColumn() === null, 'Resync mengosongkan NIM bila penguji dikosongkan');
$pdo->exec("UPDATE users SET nama_lengkap = 'Ida L. Umroh' WHERE id = 1"); // ganti nama tanpa cascade
pretesExaminerResync($pdo);
$check($pdo->query('SELECT dosen_penguji_nim FROM pretes_registrations WHERE id = 10')->fetchColumn() === '0711', 'Ganti nama dosen tidak menghapus NIM penugasan');

// Integrasi statis
$root = dirname(__DIR__);
$sidebar = file_get_contents("$root/includes/sidebar.php");
$check(str_contains($sidebar, 'pretesExaminerIsScheduled(') && str_contains($sidebar, '<?php if ($showDosenPretesMenu): ?>'), 'Menu pretes dosen hanya untuk penguji terjadwal');
$check(str_contains(file_get_contents("$root/dosen/pretes.php"), 'pretesExaminerGate(getDBConnection(), getCurrentUser());'), 'Halaman penilaian dosen bergerbang');
$check(str_contains(file_get_contents("$root/dosen/pretes-rekap-nilai.php"), 'pretesExaminerGate(getDBConnection(), getCurrentUser(), true);'), 'Rekap dosen bergerbang (riwayat diizinkan)');
$check(str_contains(file_get_contents("$root/includes/pretes-panel.php"), "AND pr.dosen_penguji_nim = ?"), 'Panel penilaian memfilter via NIM');
$check(str_contains(file_get_contents("$root/admin/pretes-rekap-nilai.php"), 'AND pr.dosen_penguji_nim = ?'), 'Rekap dosen memfilter via NIM');
$check(str_contains(file_get_contents("$root/dosen/dashboard.php"), 'pr.dosen_penguji_nim = ?'), 'Dashboard dosen memfilter via NIM');
$check(str_contains(file_get_contents("$root/dosen/dashboard.php"), 'pretesExaminerIsScheduled(') && str_contains(file_get_contents("$root/dosen/dashboard.php"), '<?php if ($isPretesExaminer): ?>'), 'Dashboard dosen menyembunyikan card pretes bagi dosen non-penguji');
$jadwal = file_get_contents("$root/admin/pretes-jadwal.php");
$check(str_contains($jadwal, 'pretesExaminerResync($pdo, $active_pretes[\'periode\'])') && str_contains($jadwal, 'SET dosen_penguji_nim = ? WHERE id = ?'), 'Plotting/reset/edit penugasan menyimpan NIM');
$check(!preg_match("/TRIM\(pr\.dosen_penguji\) = \?'?;\s*\n\s*\\\$params\[\] = trim\(\\\$user\['nama_lengkap'\]\)/", file_get_contents("$root/includes/pretes-panel.php")), 'Panel tidak lagi memfilter dosen berdasarkan nama');

if ($failures) { echo "FAIL:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
echo "PASS: penguji pretes berbasis NIM (pemetaan toleran, backfill, kepemilikan, terjadwal, resync, gerbang menu & halaman).\n";
