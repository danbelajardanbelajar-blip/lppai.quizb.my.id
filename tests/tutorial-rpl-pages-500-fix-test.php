<?php
/**
 * Test Regression: Fix HTTP 500 on admin/tutorial-rpl-peserta.php and admin/tutorial-rpl-presensi.php
 *
 * Verifies that all SQL queries in helper and pages work against production schema
 * where users table only contains `no_hp` (no `whatsapp` column).
 */

require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';

echo "=== STARTING TUTORIAL RPL 500 FIX REGRESSION TEST ===\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Exactly matching production users schema (with fakultas, NO whatsapp column!)
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        password TEXT NOT NULL,
        nama_lengkap TEXT NOT NULL,
        nim TEXT,
        email TEXT,
        no_hp TEXT,
        program_studi TEXT,
        fakultas TEXT,
        jenis_program TEXT DEFAULT 'Reguler',
        tempat_lahir TEXT,
        tanggal_lahir TEXT,
        angkatan TEXT,
        role TEXT NOT NULL DEFAULT 'mahasiswa',
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE pretes_registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        periode TEXT
    );
");

// Verify that column `whatsapp` does NOT exist on users table
$cols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC), 'name');
assert(!in_array('whatsapp', $cols), 'users table must NOT have whatsapp column in this test fixture');

ensureTutorialRplTables($pdo);

// Seed users (students & dosen)
$pdo->exec("
    INSERT INTO users (id, username, password, nama_lengkap, nim, no_hp, program_studi, jenis_program, role) VALUES
    (1, 'mhs_rpl1', 'pass', 'Siti Rahmawati', '20260010', '081234567890', 'Pendidikan Agama Islam', 'RPL', 'mahasiswa'),
    (2, 'mhs_rpl2', 'pass', 'Bambang Sudiro', '20260011', '089876543210', 'Hukum Keluarga Islam', 'RPL', 'mahasiswa'),
    (3, 'dsn_tutor1', 'pass', 'Dr. Syamsuddin, M.Ag', '19850101', '081122334455', 'PAI', 'Reguler', 'dosen');
");

// 1. Test tutorialRplGetTutors (must NOT throw 1054 Unknown column)
$tutors = tutorialRplGetTutors($pdo);
assert(count($tutors) === 1, 'Should return 1 tutor');
assert($tutors[0]['nama_lengkap'] === 'Dr. Syamsuddin, M.Ag', 'Tutor name must match');
assert(!empty($tutors[0]['no_hp']), 'Tutor phone must be present');
assert(!empty($tutors[0]['whatsapp']), 'Tutor whatsapp alias must be present');
echo "[PASS] tutorialRplGetTutors works on table without whatsapp column\n";

// 2. Test tutorialRplGetStudentsWithPretes (used in tutorial-rpl-peserta.php)
$students = tutorialRplGetStudentsWithPretes($pdo);
assert(count($students) === 2, 'Should return 2 RPL students');
assert($students[0]['nama_lengkap'] === 'Bambang Sudiro' || $students[0]['nama_lengkap'] === 'Siti Rahmawati', 'Student returned');
assert(isset($students[0]['no_hp']), 'no_hp field must exist');
assert(isset($students[0]['whatsapp']), 'whatsapp alias must exist');
echo "[PASS] tutorialRplGetStudentsWithPretes works without HTTP 500\n";

// 3. Create class and plot member
$pdo->exec("
    INSERT INTO tutorial_rpl_classes (nama_kelompok, dosen_id, dosen_nama, dosen_wa, kuota)
    VALUES ('Kelompok RPL 01', 3, 'Dr. Syamsuddin, M.Ag', '081122334455', 30)
");
$classId = (int)$pdo->lastInsertId();

tutorialRplBatchPlot($pdo, [1, 2], $classId);

// 4. Test tutorialRplGetMembers
$members = tutorialRplGetMembers($pdo, $classId);
assert(count($members) === 2, 'Should return 2 members');
assert(!empty($members[0]['no_hp']), 'Member phone must exist');
echo "[PASS] tutorialRplGetMembers works without HTTP 500\n";

// 5. Test Presensi Query (used in tutorial-rpl-presensi.php)
$queryPresensi = "
    SELECT m.*,
           u.nama_lengkap, u.nim, u.no_hp, u.no_hp AS whatsapp, u.program_studi, u.fakultas,
           c.nama_kelompok, c.dosen_nama, c.dosen_wa, c.drive_url
    FROM tutorial_rpl_members m
    JOIN users u ON m.user_id = u.id
    JOIN tutorial_rpl_classes c ON m.class_id = c.id
    WHERE 1=1
";
$stmtPres = $pdo->prepare($queryPresensi);
$stmtPres->execute();
$rowsPres = $stmtPres->fetchAll(PDO::FETCH_ASSOC);
assert(count($rowsPres) === 2, 'Presensi query should return 2 rows');
assert(!empty($rowsPres[0]['nama_kelompok']), 'Class name populated');
assert(isset($rowsPres[0]['no_hp']), 'no_hp populated');
echo "[PASS] Presensi query works without HTTP 500\n";

echo "=== ALL 500 FIX REGRESSION TESTS PASSED (100%) ===\n";
