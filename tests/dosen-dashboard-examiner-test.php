<?php
/**
 * Test isolasi rendering dosen/dashboard.php untuk dosen penguji vs non-penguji
 */
$temporary = sys_get_temp_dir() . '/dosen-dash-' . bin2hex(random_bytes(6));
mkdir($temporary . '/dosen', 0777, true);
mkdir($temporary . '/includes', 0777, true);

$fixtureFiles = [
    'dosen/dashboard.php',
    'includes/pretes-helper.php',
    'includes/pretes-components.php',
    'includes/pretes-examiner.php',
    'includes/pretes-face-engine.php'
];
foreach ($fixtureFiles as $file) {
    copy(__DIR__ . '/../' . $file, $temporary . '/' . $file);
}

// Mock auth.php, header.php, footer.php
file_put_contents($temporary . '/includes/auth.php', '<?php
define("BASE_URL", "");
function requireDosen() {}
function getCurrentUser() { global $currentUserMock; return $currentUserMock; }
function getDBConnection() { global $pdoFixture; return $pdoFixture; }
function sanitize($v) { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }
');
file_put_contents($temporary . '/includes/header.php', '<?php // header mock');
file_put_contents($temporary . '/includes/footer.php', '<?php // footer mock');

$pdoFixture = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$pdoFixture->exec("
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    nama_lengkap TEXT,
    nim TEXT,
    role TEXT
);
INSERT INTO users VALUES (1, 'Dr. Ahmad Fauzi, M.Pd.', '0711', 'dosen');
INSERT INTO users VALUES (2, 'Dra. Siti Sarah, M.Ag.', '0712', 'dosen');

CREATE TABLE master_pretes (
    id INTEGER PRIMARY KEY,
    periode TEXT,
    status TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    penguji_senin TEXT,
    penguji_selasa TEXT,
    penguji_rabu TEXT,
    penguji_kamis TEXT,
    penguji_jumat TEXT
);
INSERT INTO master_pretes (id, periode, status, penguji_senin)
VALUES (1, 'Pretes RPL 2026/2027', 'aktif', 'Dr. Ahmad Fauzi, M.Pd.');

CREATE TABLE pretes_registrations (
    id INTEGER PRIMARY KEY,
    user_id INTEGER,
    periode TEXT,
    dosen_penguji TEXT,
    dosen_penguji_nim TEXT,
    status TEXT
);
INSERT INTO pretes_registrations (id, user_id, periode, dosen_penguji, dosen_penguji_nim, status)
VALUES (1, 101, 'Pretes RPL 2026/2027', 'Dr. Ahmad Fauzi, M.Pd.', '0711', 'terdaftar');

CREATE TABLE pretes_assessments (
    id INTEGER PRIMARY KEY,
    registration_id INTEGER,
    scores_json TEXT
);

CREATE TABLE tutorial_classes (
    id INTEGER PRIMARY KEY,
    nama_kelas TEXT,
    dosen_pengampu TEXT,
    semester TEXT,
    hari TEXT,
    jam TEXT,
    ruangan TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tutorial_registrations (
    id INTEGER PRIMARY KEY,
    tutorial_class_id INTEGER
);
");

try {
    // Case 1: Dosen penguji (Dr. Ahmad Fauzi, NIM 0711)
    $currentUserMock = ['id' => 1, 'nama_lengkap' => 'Dr. Ahmad Fauzi, M.Pd.', 'nim' => '0711', 'role' => 'dosen'];
    ob_start();
    include $temporary . '/dosen/dashboard.php';
    $htmlExaminer = ob_get_clean();

    assert(strpos($htmlExaminer, 'id="info-absensi-pretes"') !== false, 'Dosen penguji harus melihat card Info Absensi Pretes');
    assert(strpos($htmlExaminer, 'id="info-nilai-pretes"') !== false, 'Dosen penguji harus melihat card Info Input Nilai Pretes');
    assert(strpos($htmlExaminer, 'Kelola Absensi & QR') !== false, 'Dosen penguji harus melihat tombol Kelola Absensi & QR');
    assert(strpos($htmlExaminer, 'Dosen & Penguji') !== false || strpos($htmlExaminer, 'Dosen &amp; Penguji') !== false, 'Dosen penguji memiliki badge Dosen & Penguji');

    // Case 2: Dosen BUKAN penguji (Dra. Siti Sarah, NIM 0712)
    $currentUserMock = ['id' => 2, 'nama_lengkap' => 'Dra. Siti Sarah, M.Ag.', 'nim' => '0712', 'role' => 'dosen'];
    ob_start();
    include $temporary . '/dosen/dashboard.php';
    $htmlNonExaminer = ob_get_clean();

    assert(strpos($htmlNonExaminer, 'id="info-absensi-pretes"') === false, 'Dosen non-penguji TIDAK BOLEH melihat card Info Absensi Pretes');
    assert(strpos($htmlNonExaminer, 'id="info-nilai-pretes"') === false, 'Dosen non-penguji TIDAK BOLEH melihat card Info Input Nilai Pretes');
    assert(strpos($htmlNonExaminer, 'Kelola Absensi & QR') === false, 'Dosen non-penguji TIDAK BOLEH melihat tombol Kelola Absensi & QR');
    assert(strpos($htmlNonExaminer, 'Input Nilai Peserta') === false, 'Dosen non-penguji TIDAK BOLEH melihat tombol Input Nilai Peserta');
    assert(strpos($htmlNonExaminer, 'Dosen Pengampu') !== false, 'Dosen non-penguji memiliki badge Dosen Pengampu');
    assert(strpos($htmlNonExaminer, 'Dosen Pengampu Tutorial') !== false, 'Dosen non-penguji memiliki peran Dosen Pengampu Tutorial');

    echo "PASS: Dosen dashboard pretes cards properly gated. Visible for examiners, hidden for non-examiners.\n";
} finally {
    foreach (['dosen/dashboard.php', 'includes/pretes-helper.php', 'includes/pretes-components.php', 'includes/pretes-examiner.php', 'includes/pretes-face-engine.php', 'includes/auth.php', 'includes/header.php', 'includes/footer.php'] as $f) {
        if (file_exists($temporary . '/' . $f)) unlink($temporary . '/' . $f);
    }
    rmdir($temporary . '/dosen');
    rmdir($temporary . '/includes');
    rmdir($temporary);
}
