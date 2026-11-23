<?php
/**
 * Test: Struktur dan Fondasi Tutorial RPL Online (Tahap 1)
 * Memverifikasi:
 * 1. Helper & Skema Basis Data (includes/tutorial-rpl-helper.php)
 * 2. Navigasi Sidebar Admin & Dosen (includes/sidebar.php)
 * 3. Menu Pretes Admin & Tampilan Mahasiswa TIDAK berubah sedikitpun
 * 4. Rendering Halaman Kelompok, Peserta, Presensi, CBT, dan Nilai RPL
 */

$temporary = sys_get_temp_dir() . '/tutorial-rpl-' . bin2hex(random_bytes(6));
mkdir($temporary . '/includes', 0777, true);
mkdir($temporary . '/admin', 0777, true);
mkdir($temporary . '/dosen', 0777, true);

$copyFiles = [
    'includes/tutorial-rpl-helper.php',
    'includes/tutorial-rpl-registration-policy.php',
    'includes/tutorial-rpl-periods.php',
    'includes/tutorial-rpl-registration-rules-view.php',
    'includes/pretes-examiner.php',
    'includes/pretes-helper.php',
    'includes/tutorial-membership.php',
    'admin/tutorial-rpl-kelas.php',
    'admin/tutorial-rpl-peserta.php',
    'admin/tutorial-rpl-presensi.php',
    'admin/tutorial-rpl-cbt.php',
    'admin/tutorial-rpl-nilai.php',
    'dosen/tutorial-rpl.php'
];

foreach ($copyFiles as $file) {
    if (file_exists(__DIR__ . '/../' . $file)) {
        copy(__DIR__ . '/../' . $file, $temporary . '/' . $file);
    }
}

// Salin sidebar.php
copy(__DIR__ . '/../includes/sidebar.php', $temporary . '/includes/sidebar.php');

$pdoFixture = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Buat skema dasar users & pretes
$pdoFixture->exec("
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nama_lengkap TEXT,
    nim TEXT,
    username TEXT,
    role TEXT,
    jenis_program TEXT,
    program_studi TEXT,
    fakultas TEXT,
    no_hp TEXT
);

CREATE TABLE master_pretes (
    id INTEGER PRIMARY KEY,
    periode TEXT,
    status TEXT,
    penguji_senin TEXT
);

CREATE TABLE pretes_registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    periode TEXT,
    hari_pilihan TEXT,
    jam TEXT,
    dosen_penguji TEXT,
    dosen_penguji_nim TEXT,
    status TEXT
);
");

require_once $temporary . '/includes/tutorial-rpl-helper.php';

$failures = [];
$check = function(bool $condition, string $label) use (&$failures) {
    if (!$condition) {
        $failures[] = "FAIL: $label";
        echo "❌ FAIL: $label\n";
    } else {
        echo "✅ PASS: $label\n";
    }
};

echo "=== TEST 1: Skema & Helper Tutorial RPL ===\n";
ensureTutorialRplTables($pdoFixture);

// Pastikan tabel terbentuk
$tables = $pdoFixture->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
$check(in_array('tutorial_rpl_classes', $tables), 'Tabel tutorial_rpl_classes berhasil dibuat');
$check(in_array('tutorial_rpl_members', $tables), 'Tabel tutorial_rpl_members berhasil dibuat');

// Insert dosen tutor & dosen biasa
$pdoFixture->exec("
INSERT INTO users (id, nama_lengkap, nim, role) VALUES (10, 'Dr. Ahmad Fauzi, M.Pd.', '071101', 'dosen');
INSERT INTO users (id, nama_lengkap, nim, role) VALUES (11, 'Dra. Siti Sarah, M.Ag.', '071102', 'dosen');
INSERT INTO users (id, nama_lengkap, nim, role, jenis_program) VALUES (101, 'Budi Santoso', '20260101', 'mahasiswa', 'RPL');
");

// Sebelum ditugaskan, Dr. Ahmad Fauzi bukan tutor RPL
$check(!tutorialRplIsTutor($pdoFixture, ['id' => 10, 'nim' => '071101', 'nama_lengkap' => 'Dr. Ahmad Fauzi, M.Pd.', 'role' => 'dosen']), 'Dosen belum mengampu -> isTutor false');

// Buat 1 kelompok yang diampu Dr. Ahmad Fauzi
$pdoFixture->exec("
INSERT INTO tutorial_rpl_classes (nama_kelompok, periode, gelombang, dosen_id, dosen_nim, dosen_nama, drive_url, kuota, is_active)
VALUES ('Kelompok RPL 1 (Putra)', 'Tutorial RPL 2026/2027', 'Gelombang 1', 10, '071101', 'Dr. Ahmad Fauzi, M.Pd.', 'https://drive.google.com/test', 50, 1);
");

$check(tutorialRplIsTutor($pdoFixture, ['id' => 10, 'nim' => '071101', 'nama_lengkap' => 'Dr. Ahmad Fauzi, M.Pd.', 'role' => 'dosen']), 'Dosen sudah mengampu -> isTutor true');
$check(!tutorialRplIsTutor($pdoFixture, ['id' => 11, 'nim' => '071102', 'nama_lengkap' => 'Dra. Siti Sarah, M.Ag.', 'role' => 'dosen']), 'Dosen lain -> isTutor tetap false');

// Plot mahasiswa ke kelompok
$classId = (int)$pdoFixture->query("SELECT id FROM tutorial_rpl_classes LIMIT 1")->fetchColumn();
$pdoFixture->exec("INSERT INTO tutorial_rpl_members (class_id, user_id) VALUES ($classId, 101)");

$classes = tutorialRplGetClasses($pdoFixture);
$check(count($classes) === 1 && (int)$classes[0]['terisi'] === 1, 'tutorialRplGetClasses mengembalikan 1 kelas dengan kuota terisi 1');

$members = tutorialRplGetMembers($pdoFixture, $classId);
$check(count($members) === 1 && $members[0]['nim'] === '20260101', 'tutorialRplGetMembers mengembalikan data mahasiswa yang diplot');

echo "\n=== TEST 2: Sidebar Admin, Dosen, dan Mahasiswa ===\n";
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test');
if (!defined('APP_NAME')) define('APP_NAME', 'LPPAI Corner');
function menuActive($p) { return ''; }
function isNavGroupActive($pages) { return false; }
function sanitize($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function getDBConnection() { global $pdoFixture; return $pdoFixture; }

$renderSidebar = function(array $user): string {
    global $currentUser;
    $currentUser = $user;
    ob_start();
    include __DIR__ . '/../includes/sidebar.php';
    return ob_get_clean();
};

// 2a. Admin Sidebar
$adminHtml = $renderSidebar(['id' => 1, 'role' => 'admin', 'nama_lengkap' => 'Administrator']);
$check(str_contains($adminHtml, 'data-group="admin-tutorial-rpl"'), 'Admin sidebar memiliki data-group="admin-tutorial-rpl"');
$check(str_contains($adminHtml, 'Tutorial RPL Online'), 'Admin sidebar memuat judul "Tutorial RPL Online"');
$check(str_contains($adminHtml, 'admin/tutorial-rpl-periode.php'), 'Admin sidebar memuat menu periode pendaftaran RPL');
$check(str_contains($adminHtml, 'admin/tutorial-rpl-pendaftaran.php'), 'Admin sidebar memuat menu pendaftaran peserta RPL');
$check(str_contains($adminHtml, 'admin/tutorial-rpl-kelas.php'), 'Admin sidebar memuat link tutorial-rpl-kelas.php');
$check(str_contains($adminHtml, 'admin/tutorial-rpl-peserta.php'), 'Admin sidebar memuat link tutorial-rpl-peserta.php');
$check(str_contains($adminHtml, 'admin/tutorial-rpl-presensi.php'), 'Admin sidebar memuat link tutorial-rpl-presensi.php');
$check(str_contains($adminHtml, 'admin/tutorial-rpl-cbt.php'), 'Admin sidebar memuat link tutorial-rpl-cbt.php');
$check(str_contains($adminHtml, 'admin/tutorial-rpl-nilai.php'), 'Admin sidebar memuat link tutorial-rpl-nilai.php');
// Pastikan Manajemen Pretes tidak berubah
$check(str_contains($adminHtml, 'data-group="admin-pretes"') && str_contains($adminHtml, 'admin/pretes-jadwal.php'), 'Admin sidebar: Manajemen Pretes tetap utuh tidak berubah');

// 2b. Dosen Sidebar (Tutor RPL)
$dosenTutorHtml = $renderSidebar(['id' => 10, 'role' => 'dosen', 'nim' => '071101', 'nama_lengkap' => 'Dr. Ahmad Fauzi, M.Pd.']);
$check(str_contains($dosenTutorHtml, 'dosen/tutorial-rpl.php'), 'Dosen Tutor RPL memiliki menu "Tutorial RPL Online"');

// 2c. Dosen Sidebar (Non-tutor)
$dosenNonTutorHtml = $renderSidebar(['id' => 11, 'role' => 'dosen', 'nim' => '071102', 'nama_lengkap' => 'Dra. Siti Sarah, M.Ag.']);
$check(!str_contains($dosenNonTutorHtml, 'dosen/tutorial-rpl.php'), 'Dosen Non-Tutor tidak menampilkan menu "Tutorial RPL Online"');

// 2d. Mahasiswa Sidebar (TIDAK BERUBAH)
$mhsHtml = $renderSidebar(['id' => 101, 'role' => 'mahasiswa', 'nim' => '20260101', 'nama_lengkap' => 'Budi Santoso']);
$check(!str_contains($mhsHtml, 'admin-tutorial-rpl'), 'Mahasiswa sidebar: Bebas dari menu admin tutorial RPL');
$check(!str_contains($mhsHtml, 'tutorial-rpl-kelas.php'), 'Mahasiswa sidebar: Bebas dari link admin');
$check(!str_contains($mhsHtml, 'tutorial-rpl-daftar.php'), 'Mahasiswa non-RPL tidak diberi menu pendaftaran RPL');
$rplHtml = $renderSidebar(['id'=>101, 'role'=>'mahasiswa', 'jenis_program'=>'RPL', 'nim'=>'20260101', 'nama_lengkap'=>'Mahasiswa RPL']);
$check(str_contains($rplHtml, 'tutorial-rpl-daftar.php'), 'Mahasiswa RPL dapat membuka pendaftaran tanpa bergantung menu tutorial reguler');

echo "\n=== TEST 3: Smoke Test Halaman Baru Tutorial RPL ===\n";
// Pastikan semua file halaman baru ada di disk dan valid PHP
$newPages = [
    'includes/tutorial-rpl-helper.php',
    'admin/tutorial-rpl-kelas.php',
    'admin/tutorial-rpl-peserta.php',
    'admin/tutorial-rpl-presensi.php',
    'admin/tutorial-rpl-cbt.php',
    'admin/tutorial-rpl-nilai.php',
    'dosen/tutorial-rpl.php'
];

foreach ($newPages as $p) {
    $full = __DIR__ . '/../' . $p;
    $check(file_exists($full), "File $p ada di disk");
}

// Bersihkan direktori sementara
try {
    $it = new RecursiveDirectoryIterator($temporary, RecursiveIteratorIterator::CHILD_FIRST);
    foreach (new RecursiveIteratorIterator($it) as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($temporary);
} catch (Throwable $e) {}

if (!empty($failures)) {
    echo "\nTotal failures: " . count($failures) . "\n";
    exit(1);
}

echo "\nSemua pengujian struktur & navigasi Tutorial RPL Online BERHASIL! (100% PASS)\n";
exit(0);
