<?php
/**
 * Test otomatis end-to-end untuk Rekapitulasi Kehadiran Tutorial (Dosen & Admin)
 */
$tempDir = sys_get_temp_dir() . '/rekap-test-' . bin2hex(random_bytes(6));
mkdir($tempDir . '/admin', 0777, true);
mkdir($tempDir . '/dosen', 0777, true);
mkdir($tempDir . '/includes', 0777, true);

// Salin file yang diuji
$_SERVER['REQUEST_METHOD'] = 'GET';
copy(__DIR__ . '/../admin/tutorial-rekap-absensi.php', $tempDir . '/admin/tutorial-rekap-absensi.php');
copy(__DIR__ . '/../dosen/detail-kelas.php', $tempDir . '/dosen/detail-kelas.php');
copy(__DIR__ . '/../dosen/kelas.php', $tempDir . '/dosen/kelas.php');
copy(__DIR__ . '/../dosen/dashboard.php', $tempDir . '/dosen/dashboard.php');
copy(__DIR__ . '/../includes/sidebar.php', $tempDir . '/includes/sidebar.php');
copy(__DIR__ . '/../includes/user-helper.php', $tempDir . '/includes/user-helper.php');
copy(__DIR__ . '/../includes/admin-tutorial-registration.php', $tempDir . '/includes/admin-tutorial-registration.php');
copy(__DIR__ . '/../includes/pretes-helper.php', $tempDir . '/includes/pretes-helper.php');
copy(__DIR__ . '/../includes/pretes-examiner.php', $tempDir . '/includes/pretes-examiner.php');
copy(__DIR__ . '/../includes/pretes-face-engine.php', $tempDir . '/includes/pretes-face-engine.php');
if (file_exists(__DIR__ . '/../includes/tutorial-membership.php')) {
    copy(__DIR__ . '/../includes/tutorial-membership.php', $tempDir . '/includes/tutorial-membership.php');
}

// Mock auth.php, header.php, footer.php
file_put_contents($tempDir . '/includes/auth.php', '<?php
define("BASE_URL", "/test");
define("APP_NAME", "LPPAI Test");
function requireAdmin() { global $currentUserMock; if (($currentUserMock["role"] ?? "") !== "admin") { http_response_code(403); exit("Forbidden"); } }
function requireDosen() { global $currentUserMock; if (($currentUserMock["role"] ?? "") !== "dosen") { http_response_code(403); exit("Forbidden"); } }
function requireLogin() {}
function isAdmin() { global $currentUserMock; return ($currentUserMock["role"] ?? "") === "admin"; }
function isDosen() { global $currentUserMock; return ($currentUserMock["role"] ?? "") === "dosen"; }
function getCurrentUser() { global $currentUserMock; return $currentUserMock; }
function getDBConnection() { global $pdoFixture; return $pdoFixture; }
function sanitize($v) { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }
function csrfToken() { return "csrf_test_token"; }
function verifyCsrf($t) { return $t === "csrf_test_token"; }
function adminUserProfileLink($name, $userId = null) { return "<a href=\'/profil?id=" . (int)$userId . "\'>" . sanitize($name) . "</a>"; }
function renderJenisProgramBadge($jp = "Reguler", $small = false) { return "<span class=\'badge\'>" . sanitize($jp) . "</span>"; }
');
file_put_contents($tempDir . '/includes/header.php', '<?php // mock header ?>');
file_put_contents($tempDir . '/includes/footer.php', '<?php // mock footer ?>');

// Setup in-memory SQLite fixture
$pdoFixture = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$pdoFixture->exec("
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    nama_lengkap TEXT,
    nim TEXT,
    role TEXT,
    program_studi TEXT,
    jenis_program TEXT
);
INSERT INTO users VALUES (1, 'Dr. Ahmad Fauzi, M.Pd.', '0711', 'dosen', 'PAI', 'Reguler');
INSERT INTO users VALUES (2, 'Siti Aminah', '240101', 'mahasiswa', 'Pendidikan Agama Islam', 'Reguler');
INSERT INTO users VALUES (3, 'Budi Santoso', '240102', 'mahasiswa', 'Pendidikan Agama Islam', 'RPL');
INSERT INTO users VALUES (4, 'Dewi Lestari', '240103', 'mahasiswa', 'Hukum Keluarga Islam', 'Reguler');
INSERT INTO users VALUES (99, 'Admin LPPAI', 'admin', 'admin', 'LPPAI', 'Reguler');

CREATE TABLE master_gelombang (
    id INTEGER PRIMARY KEY,
    semester TEXT,
    tahun_ajaran TEXT,
    gelombang TEXT,
    status TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO master_gelombang VALUES (1, 'Ganjil', '2026/2027', 'gel1', 'aktif', '2026-09-01 08:00:00');

CREATE TABLE tutorial_classes (
    id INTEGER PRIMARY KEY,
    nama_kelas TEXT,
    dosen_pengampu TEXT,
    semester TEXT,
    gelombang TEXT,
    hari TEXT,
    jam TEXT,
    ruangan TEXT,
    kuota INTEGER,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO tutorial_classes VALUES (10, 'Kelas PAI A', 'Dr. Ahmad Fauzi, M.Pd.', '2026/2027-Ganjil', 'gel1', 'Senin', '08:00 - 09:30', 'R.101', 30, '2026-09-02 08:00:00');
INSERT INTO tutorial_classes VALUES (20, 'Kelas PAI B', 'Dr. Ahmad Fauzi, M.Pd.', '2026/2027-Ganjil', 'gel1', 'Selasa', '10:00 - 11:30', 'R.102', 30, '2026-09-02 08:00:00');

CREATE TABLE tutorial_registrations (
    id INTEGER PRIMARY KEY,
    user_id INTEGER,
    tutorial_class_id INTEGER,
    status TEXT,
    tahun_ajaran TEXT,
    gelombang TEXT,
    hari_pilihan TEXT,
    nilai_thaharah REAL,
    nilai_shalat REAL,
    nilai_surat_pendek REAL,
    nilai_amaliyah REAL,
    nilai_jenazah REAL,
    nilai_ujian_tulis REAL,
    nilai_detail TEXT,
    tipe_nilai TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO tutorial_registrations (id, user_id, tutorial_class_id, status, tahun_ajaran, gelombang)
VALUES (101, 2, 10, 'terdaftar', '2026/2027', 'gel1');
INSERT INTO tutorial_registrations (id, user_id, tutorial_class_id, status, tahun_ajaran, gelombang)
VALUES (102, 3, 10, 'terdaftar', '2026/2027', 'gel1');
INSERT INTO tutorial_registrations (id, user_id, tutorial_class_id, status, tahun_ajaran, gelombang)
VALUES (103, 4, 20, 'terdaftar', '2026/2027', 'gel1');

CREATE TABLE tutorial_attendance (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tutorial_class_id INTEGER,
    user_id INTEGER,
    pertemuan_ke INTEGER,
    tanggal TEXT,
    status_hadir TEXT
);

-- Absensi Kelas 10:
-- Pertemuan 1-6 hadir untuk Siti (H=6, memenuhi syarat >= 75%)
-- Pertemuan 1-3 hadir untuk Budi (H=3, I=2, S=1, A=2 => < 75% rawan)
INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (10, 2, 1, '2026-09-08', 'hadir');
INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (10, 2, 2, '2026-09-15', 'hadir');
INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (10, 2, 3, '2026-09-22', 'hadir');
INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (10, 2, 4, '2026-09-29', 'hadir');
INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (10, 2, 5, '2026-10-06', 'hadir');
INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (10, 2, 6, '2026-10-13', 'hadir');

INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (10, 3, 1, '2026-09-08', 'hadir');
INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (10, 3, 2, '2026-09-15', 'hadir');
INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (10, 3, 3, '2026-09-22', 'hadir');
INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (10, 3, 4, '2026-09-29', 'izin');
INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (10, 3, 5, '2026-10-06', 'sakit');
INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (10, 3, 6, '2026-10-13', 'absen');

CREATE TABLE tutorial_qr_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tutorial_class_id INTEGER,
    pertemuan_ke INTEGER,
    token TEXT,
    expires_at TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO tutorial_qr_sessions (tutorial_class_id, pertemuan_ke, token, expires_at, created_at)
VALUES (10, 1, 'token1', '2026-09-08 09:00:00', '2026-09-08 08:00:00');
");

// Cleanup temporary directory
register_shutdown_function(function() use ($tempDir) {
    if (is_dir($tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            @$todo($fileinfo->getRealPath());
        }
        @rmdir($tempDir);
    }
});

$assertionsPassed = 0;

function assertCondition($cond, $msg) {
    global $assertionsPassed;
    if (!$cond) {
        throw new RuntimeException("Assertion failed: $msg");
    }
    $assertionsPassed++;
    echo "  [PASS] $msg\n";
}

echo "=== TEST 1: Dosen Detail Kelas Tab Rekap Kehadiran ===\n";
$currentUserMock = ['id' => 1, 'nama_lengkap' => 'Dr. Ahmad Fauzi, M.Pd.', 'role' => 'dosen'];
$_GET = ['id' => 10, 'tab' => 'rekap_absensi'];

ob_start();
include $tempDir . '/dosen/detail-kelas.php';
$outputDetail = ob_get_clean();

assertCondition(strpos($outputDetail, 'Status Kehadiran Mengajar Dosen') !== false, 'Card Kehadiran Mengajar Dosen tampil');
assertCondition(strpos($outputDetail, 'Matriks Rekapitulasi Presensi Mahasiswa') !== false, 'Card Matriks Presensi Mahasiswa tampil');
assertCondition(strpos($outputDetail, 'Progres Perkuliahan Tutorial:') !== false, 'Progres perkuliahan dosen tertera');
assertCondition(strpos($outputDetail, '6 dari 8 Pertemuan') !== false, 'Pertemuan terlaksana dosen terhitung 6 dari 8');
assertCondition(strpos($outputDetail, 'Siti Aminah') !== false, 'Mahasiswa Siti Aminah tampil');
assertCondition(strpos($outputDetail, 'Budi Santoso') !== false, 'Mahasiswa Budi Santoso tampil');
assertCondition(strpos($outputDetail, '75%') !== false, 'Siti Aminah memiliki persentase kehadiran 75%');
assertCondition(strpos($outputDetail, 'Memenuhi') !== false, 'Status memenuhi syarat teridentifikasi');
assertCondition(strpos($outputDetail, 'Perlu Perhatian') !== false, 'Status perlu perhatian teridentifikasi');
assertCondition(strpos($outputDetail, 'window.print()') !== false, 'Tombol cetak rekapitulasi tersedia');
assertCondition(strpos($outputDetail, 'rekapSearchInput') !== false, 'Input pencarian realtime rekap mahasiswa tersedia');

echo "\n=== TEST 2: Dosen Kelas & Dashboard Quick Access ===\n";
$_GET = [];
ob_start();
include $tempDir . '/dosen/kelas.php';
$outputKelas = ob_get_clean();
assertCondition(strpos($outputKelas, 'tab=rekap_absensi') !== false, 'Tombol shortcut Rekap Absensi ada di dosen/kelas.php');

echo "\n=== TEST 3: Admin Rekap Kehadiran Tutorial (Tab Mahasiswa) ===\n";
$currentUserMock = ['id' => 99, 'nama_lengkap' => 'Admin LPPAI', 'role' => 'admin'];
$_GET = ['tab' => 'mahasiswa', 'gelombang_id' => 'active'];

ob_start();
include $tempDir . '/admin/tutorial-rekap-absensi.php';
$outputAdminMhs = ob_get_clean();

assertCondition(strpos($outputAdminMhs, 'Rekapitulasi Kehadiran Tutorial') !== false, 'Judul halaman admin rekap kehadiran tampil');
assertCondition(strpos($outputAdminMhs, 'Matriks Presensi Kumulatif Mahasiswa') !== false, 'Tabel matriks presensi mahasiswa tampil');
assertCondition(strpos($outputAdminMhs, 'Siti Aminah') !== false, 'Mahasiswa terdaftar muncul di tab mahasiswa admin');
assertCondition(strpos($outputAdminMhs, 'Budi Santoso') !== false, 'Mahasiswa terdaftar kedua muncul di tab mahasiswa admin');
assertCondition(strpos($outputAdminMhs, 'export_csv_mahasiswa') !== false, 'Link export CSV mahasiswa tersedia');

echo "\n=== TEST 4: Admin Rekap Kehadiran Tutorial (Tab Dosen) ===\n";
$_GET = ['tab' => 'dosen', 'gelombang_id' => 'active'];

ob_start();
include $tempDir . '/admin/tutorial-rekap-absensi.php';
$outputAdminDosen = ob_get_clean();

assertCondition(strpos($outputAdminDosen, 'Matriks Keterlaksanaan Mengajar Dosen per Kelas') !== false, 'Tabel rekap mengajar dosen tampil');
assertCondition(strpos($outputAdminDosen, 'Dr. Ahmad Fauzi, M.Pd.') !== false, 'Dosen pengampu muncul di laporan dosen');
assertCondition(strpos($outputAdminDosen, 'Kelas PAI A') !== false, 'Kelas PAI A muncul di laporan dosen');
assertCondition(strpos($outputAdminDosen, 'Kelas PAI B') !== false, 'Kelas PAI B muncul di laporan dosen');
assertCondition(strpos($outputAdminDosen, '6/8') !== false, 'Progres 6/8 pertemuan tercatat di Kelas PAI A');
assertCondition(strpos($outputAdminDosen, 'export_csv_dosen') !== false, 'Link export CSV dosen tersedia');

echo "\n=== TEST 5: Sidebar Navigation Integration ===\n";
$_SERVER['PHP_SELF'] = '/admin/tutorial-rekap-absensi.php';
$currentUser = ['id' => 99, 'nama_lengkap' => 'Admin LPPAI', 'role' => 'admin'];
$isAdmin = true;
ob_start();
include $tempDir . '/includes/sidebar.php';
$outputSidebar = ob_get_clean();

assertCondition(strpos($outputSidebar, 'tutorial-rekap-absensi.php') !== false, 'Menu tutorial-rekap-absensi.php ada di sidebar');
assertCondition(strpos($outputSidebar, 'Rekap Kehadiran') !== false, 'Label Rekap Kehadiran ada di sidebar');
assertCondition(strpos($outputSidebar, 'page-nav active') !== false, 'Menu aktif saat membuka tutorial-rekap-absensi.php');

echo "\nSemua $assertionsPassed pengujian fungsi berhasil dengan sempurna!\n";
