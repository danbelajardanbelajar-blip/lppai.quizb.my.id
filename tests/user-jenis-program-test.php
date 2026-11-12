<?php
/**
 * Verification tests for user `jenis_program` ("RPL" / "Reguler") schema, helpers,
 * CRUD/import endpoints, and UI displays across jurusan/prodi views.
 */

require_once __DIR__ . '/../includes/user-helper.php';

$root = realpath(__DIR__ . '/..');
$errors = [];

function assertTrue($cond, string $message, array &$errors): void {
    if (!$cond) {
        $errors[] = $message;
    }
}

// 1. Strict two-value helpers
$allowed = getAllowedJenisProgramOptions();
sort($allowed);
assertTrue($allowed === ['RPL', 'Reguler'], 'Allowed options must contain strictly RPL and Reguler.', $errors);

assertTrue(normalizeJenisProgram('RPL') === 'RPL', 'RPL should normalize to RPL.', $errors);
assertTrue(normalizeJenisProgram('  rpl ') === 'RPL', 'Lowercase rpl with whitespace should normalize to RPL.', $errors);
assertTrue(normalizeJenisProgram('Jalur RPL 2026') === 'RPL', 'Label containing RPL should normalize to RPL.', $errors);
assertTrue(normalizeJenisProgram('Reguler') === 'Reguler', 'Reguler should normalize to Reguler.', $errors);
assertTrue(normalizeJenisProgram('reguler') === 'Reguler', 'Lowercase reguler should normalize to Reguler.', $errors);
assertTrue(normalizeJenisProgram('') === 'Reguler', 'Empty value should default to Reguler.', $errors);
assertTrue(normalizeJenisProgram(null) === 'Reguler', 'Null value should default to Reguler.', $errors);
assertTrue(normalizeJenisProgram('Karyawan') === 'Reguler', 'Unknown value must fall back strictly to Reguler.', $errors);

// 2. Badge and prodi display helpers
$badgeRpl = renderJenisProgramBadge('RPL');
$badgeReg = renderJenisProgramBadge('Reguler');
assertTrue(str_contains($badgeRpl, 'badge-jenis-rpl') && str_contains($badgeRpl, '>RPL<'), 'RPL badge must render RPL.', $errors);
assertTrue(str_contains($badgeReg, 'badge-jenis-reguler') && str_contains($badgeReg, '>Reguler<'), 'Reguler badge must render Reguler.', $errors);

$prodiDisplay = formatProdiDisplay('Pendidikan Agama Islam', 'RPL', true);
assertTrue(str_contains($prodiDisplay, 'Pendidikan Agama Islam') && str_contains($prodiDisplay, '>RPL<'), 'formatProdiDisplay must include both prodi and jenis_program badge.', $errors);

$prodiEmpty = formatProdiDisplay('', 'Reguler', true, true);
assertTrue(str_contains($prodiEmpty, '-') && str_contains($prodiEmpty, '>Reguler<'), 'formatProdiDisplay with empty prodi must still show badge.', $errors);

// 3. Auto-migration verification on SQLite PDO
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, nama_lengkap TEXT, program_studi TEXT)');
$pdo->exec("INSERT INTO users (username, nama_lengkap, program_studi) VALUES ('24001', 'Ahmad', 'PAI')");

ensureJenisProgramColumn($pdo);
ensureJenisProgramColumn($pdo); // Idempotent check

$cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($cols, 'name');
assertTrue(in_array('jenis_program', $colNames, true), 'ensureJenisProgramColumn must add jenis_program column on SQLite.', $errors);

$row = $pdo->query("SELECT jenis_program FROM users WHERE username = '24001'")->fetch(PDO::FETCH_ASSOC);
assertTrue(($row['jenis_program'] ?? null) === 'Reguler', 'Existing user rows must default to Reguler after migration.', $errors);

$pdo->exec("INSERT INTO users (username, nama_lengkap, program_studi, jenis_program) VALUES ('24002', 'Budi', 'Manajemen', 'RPL')");
$rowRpl = $pdo->query("SELECT jenis_program FROM users WHERE username = '24002'")->fetch(PDO::FETCH_ASSOC);
assertTrue(($rowRpl['jenis_program'] ?? null) === 'RPL', 'Inserted RPL row must store RPL.', $errors);

// 4. Migration file and Auth propagation checks
$migrationPhp = file_get_contents($root . '/migration_jenis_program.php');
assertTrue(str_contains($migrationPhp, "ENUM('RPL', 'Reguler')"), 'migration_jenis_program.php must define ENUM(RPL, Reguler).', $errors);

$authPhp = file_get_contents($root . '/includes/auth.php');
assertTrue(str_contains($authPhp, "user-helper.php") && str_contains($authPhp, "\$_SESSION['jenis_program']"), 'auth.php must require user-helper and store jenis_program in session.', $errors);

$returnAdminPhp = file_get_contents($root . '/api/return-admin.php');
assertTrue(str_contains($returnAdminPhp, "\$_SESSION['jenis_program']"), 'api/return-admin.php must restore jenis_program session.', $errors);

// 5. Kelola Pengguna CRUD, Filter, Import, Template checks
$usersPhp = file_get_contents($root . '/admin/users.php');
assertTrue(str_contains($usersPhp, 'name="jenis_program"'), 'admin/users.php must have jenis_program select inputs.', $errors);
assertTrue(str_contains($usersPhp, 'id="filter_jenis_program"'), 'admin/users.php must expose filter_jenis_program.', $errors);
assertTrue(str_contains($usersPhp, '<th>Jenis Program</th>'), 'admin/users.php table must have Jenis Program column.', $errors);
assertTrue(str_contains($usersPhp, 'targets: [0, 10]'), 'admin/users.php DataTables non-orderable target for Aksi must shift to index 10.', $errors);

$ajaxUsersPhp = file_get_contents($root . '/admin/ajax-users.php');
assertTrue(str_contains($ajaxUsersPhp, "7 => 'u.jenis_program'"), 'admin/ajax-users.php sort column map must include u.jenis_program at index 7.', $errors);
assertTrue(str_contains($ajaxUsersPhp, 'data-jenis-program='), 'admin/ajax-users.php edit button must expose data-jenis-program.', $errors);

$importUsersPhp = file_get_contents($root . '/admin/ajax-import-users.php');
$posJenis = strpos($importUsersPhp, "colMap['jenis_program']");
$posProdi = strpos($importUsersPhp, "colMap['program_studi']");
assertTrue($posJenis !== false && $posProdi !== false && $posJenis < $posProdi, 'admin/ajax-import-users.php must match jenis_program header before program_studi.', $errors);

$templateUsersPhp = file_get_contents($root . '/admin/download-template-users.php');
assertTrue(str_contains($templateUsersPhp, 'Jenis Program (RPL/Reguler)'), 'Template download must include Jenis Program (RPL/Reguler) column.', $errors);

$kelolaNilaiPhp = file_get_contents($root . '/admin/kelola-nilai.php');
assertTrue(str_contains($kelolaNilaiPhp, 'filterJenisProgram'), 'Expected /admin/kelola-nilai.php to wire filterJenisProgram.', $errors);

// 6. Prodi display propagation checks across pages
$pagesRequiringJenisProgram = [
    '/pages/dashboard.php',
    '/admin/dashboard.php',
    '/admin/ajax-kelola-nilai.php',
    '/rekap-nilai.php',
    '/admin/pretes-peserta.php',
    '/admin/pretes-peserta-export.php',
    '/admin/pretes-jadwal.php',
    '/admin/pretes-absensi-export.php',
    '/admin/pretes-rekap-nilai.php',
    '/includes/pretes-card.php',
    '/includes/pretes-panel.php',
    '/pages/pretes-hasil.php',
    '/admin/tutorial-data-pendaftar.php',
    '/admin/tutorial-pendaftaran-kolektif.php',
    '/dosen/detail-kelas.php',
    '/includes/announcement-helper.php',
    '/admin/absensi-alkhidmah.php',
    '/admin/aktivasi-mahasiswa.php',
    '/admin/cetak-sertifikat.php',
    '/admin/cetak-tanda-terima-sertifikat.php',
    '/admin/pengambilan-sertifikat.php',
    '/pages/ambil-sertifikat.php',
    '/sertifikat.php',
    '/admin/ajax-import-nilai.php',
];

foreach ($pagesRequiringJenisProgram as $relPath) {
    $src = file_get_contents($root . $relPath);
    assertTrue(str_contains($src, 'jenis_program'), "Expected {$relPath} to reference jenis_program.", $errors);
}

if (!empty($errors)) {
    foreach ($errors as $err) {
        fwrite(STDERR, "[FAIL] $err\n");
    }
    exit(1);
}

echo "OK: user-jenis-program tests passed (" . count($pagesRequiringJenisProgram) . " files verified).\n";
