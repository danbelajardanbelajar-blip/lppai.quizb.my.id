<?php
/**
 * Test: Sembunyikan Menu Pretes dan Menu Tutorial bagi Mahasiswa yang Tidak Terdaftar
 * (Baik Akun Mahasiswa Aktif / Non-Aktif, maupun Periode/Status Kegiatan Aktif / Non-Aktif)
 */

require_once __DIR__ . '/../includes/pretes-helper.php';
require_once __DIR__ . '/../includes/tutorial-membership.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE master_pretes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nama_pretes TEXT,
        periode TEXT,
        tahun_ajaran TEXT,
        status TEXT,
        target_program TEXT DEFAULT 'Semua',
        tanggal_mulai TEXT
    );
    CREATE TABLE pretes_registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        periode TEXT,
        hari_pilihan TEXT,
        jam TEXT,
        ruangan TEXT,
        dosen_penguji TEXT,
        status TEXT DEFAULT 'terdaftar'
    );
    CREATE TABLE pretes_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        status_lulus TEXT,
        nilai REAL
    );
    CREATE TABLE master_gelombang (
        id INTEGER PRIMARY KEY,
        nama_gelombang TEXT,
        tahun_ajaran TEXT,
        semester TEXT,
        gelombang TEXT,
        status TEXT
    );
    CREATE TABLE tutorial_classes (
        id INTEGER PRIMARY KEY,
        nama_kelas TEXT,
        gelombang TEXT,
        semester TEXT
    );
    CREATE TABLE tutorial_registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        tutorial_class_id INTEGER,
        gelombang TEXT,
        tahun_ajaran TEXT,
        status TEXT,
        created_at TEXT
    );
    INSERT INTO master_gelombang (id, nama_gelombang, tahun_ajaran, semester, gelombang, status) 
    VALUES (1, 'Gelombang 1', '2026/2027', 'Ganjil', 'gel1', 'aktif');
    INSERT INTO master_pretes (id, nama_pretes, periode, status, target_program) 
    VALUES (1, 'Pretes Reguler 2026/2027', '2026/2027', 'aktif', 'Semua');
");

// Setup Users
// User 10: Mahasiswa TIDAK TERDAFTAR tutorial & TIDAK TERDAFTAR pretes (Akun Aktif is_active = 1)
$mhsUnregisteredActive = ['id' => 10, 'role' => 'mahasiswa', 'is_active' => 1, 'nama_lengkap' => 'Mhs Belum Daftar Aktif', 'jenis_program' => 'Reguler'];

// User 11: Mahasiswa TIDAK TERDAFTAR tutorial & TIDAK TERDAFTAR pretes (Akun Non-Aktif is_active = 0)
$mhsUnregisteredInactive = ['id' => 11, 'role' => 'mahasiswa', 'is_active' => 0, 'nama_lengkap' => 'Mhs Belum Daftar Non Aktif', 'jenis_program' => 'Reguler'];

// User 20: Mahasiswa HANYA TERDAFTAR TUTORIAL AKTIF
$pdo->exec("INSERT INTO tutorial_registrations (id, user_id, tutorial_class_id, gelombang, tahun_ajaran, status, created_at) VALUES (1, 20, 1, 'gel1', '2026/2027', 'terdaftar', '2026-11-01')");
$mhsTutorialOnly = ['id' => 20, 'role' => 'mahasiswa', 'is_active' => 1, 'nama_lengkap' => 'Mhs Tutorial Only', 'jenis_program' => 'Reguler'];

// User 21: Mahasiswa HANYA TERDAFTAR TUTORIAL LAMPAU / NON-AKTIF
$pdo->exec("INSERT INTO tutorial_registrations (id, user_id, tutorial_class_id, gelombang, tahun_ajaran, status, created_at) VALUES (2, 21, NULL, 'gel1', '2025/2026', 'lulus', '2025-10-01')");
$mhsTutorialOld = ['id' => 21, 'role' => 'mahasiswa', 'is_active' => 1, 'nama_lengkap' => 'Mhs Tutorial Old', 'jenis_program' => 'Reguler'];

// User 30: Mahasiswa HANYA TERDAFTAR PRETES AKTIF
$pdo->exec("INSERT INTO pretes_registrations (id, user_id, periode) VALUES (1, 30, '2026/2027')");
$mhsPretesActive = ['id' => 30, 'role' => 'mahasiswa', 'is_active' => 1, 'nama_lengkap' => 'Mhs Pretes Active', 'jenis_program' => 'Reguler'];

// User 31: Mahasiswa TERDAFTAR PRETES LULUS
$pdo->exec("INSERT INTO pretes_registrations (id, user_id, periode) VALUES (2, 31, '2026/2027')");
$pdo->exec("INSERT INTO pretes_results (user_id, status_lulus, nilai) VALUES (31, 'lulus', 85.0)");
$mhsPretesLulus = ['id' => 31, 'role' => 'mahasiswa', 'is_active' => 1, 'nama_lengkap' => 'Mhs Pretes Lulus', 'jenis_program' => 'Reguler'];

// User 40: Admin & Dosen
$admin = ['id' => 1, 'role' => 'admin', 'nama_lengkap' => 'Admin Test'];
$dosen = ['id' => 2, 'role' => 'dosen', 'nama_lengkap' => 'Dosen Test'];

echo "=== 1. Validasi Helper Function: studentHasTutorialRegistration & studentHasPretesRegistration ===\n";

assert(studentHasTutorialRegistration($pdo, $mhsUnregisteredActive) === false, "Mhs 10 harus tidak terdaftar tutorial");
assert(studentHasPretesRegistration($pdo, $mhsUnregisteredActive) === false, "Mhs 10 harus tidak terdaftar pretes");

assert(studentHasTutorialRegistration($pdo, $mhsUnregisteredInactive) === false, "Mhs 11 non-aktif harus tidak terdaftar tutorial");
assert(studentHasPretesRegistration($pdo, $mhsUnregisteredInactive) === false, "Mhs 11 non-aktif harus tidak terdaftar pretes");

assert(studentHasTutorialRegistration($pdo, $mhsTutorialOnly) === true, "Mhs 20 harus terdaftar tutorial");
assert(studentHasPretesRegistration($pdo, $mhsTutorialOnly) === false, "Mhs 20 harus tidak terdaftar pretes");

assert(studentHasTutorialRegistration($pdo, $mhsTutorialOld) === true, "Mhs 21 harus terdaftar tutorial lampau");
assert(studentHasPretesRegistration($pdo, $mhsTutorialOld) === false, "Mhs 21 harus tidak terdaftar pretes");

assert(studentHasPretesRegistration($pdo, $mhsPretesActive) === true, "Mhs 30 harus terdaftar pretes");
assert(studentHasTutorialRegistration($pdo, $mhsPretesActive) === false, "Mhs 30 harus tidak terdaftar tutorial");

assert(studentHasPretesRegistration($pdo, $mhsPretesLulus) === true, "Mhs 31 harus terdaftar pretes");
assert(studentHasTutorialRegistration($pdo, $mhsPretesLulus) === false, "Mhs 31 harus tidak terdaftar tutorial");

assert(studentHasTutorialRegistration($pdo, $admin) === false, "Admin harus false");
assert(studentHasPretesRegistration($pdo, $admin) === false, "Admin harus false");
echo " [PASS] Seluruh deteksi fungsi pendaftaran tutorial & pretes akurat 100%.\n";

echo "\n=== 2. Validasi Rendering Sidebar untuk Mahasiswa Tidak Terdaftar (Aktif & Non-Aktif) ===\n";

if (!defined('BASE_URL')) define('BASE_URL', 'https://lppai.quizb.my.id');
if (!defined('APP_NAME')) define('APP_NAME', 'LPPAI Corner');

function renderSidebarTest($pdoInstance, $user) {
    $currentUser = $user;
    $_SERVER['PHP_SELF'] = '/dashboard.php';
    $GLOBALS['_test_pdo'] = $pdoInstance;
    ob_start();
    include __DIR__ . '/../includes/sidebar.php';
    return ob_get_clean();
}

if (!function_exists('getDBConnection')) {
    function getDBConnection() {
        return $GLOBALS['_test_pdo'];
    }
}

// Uji Mahasiswa Belum Terdaftar Akun Aktif
$htmlMhs10 = renderSidebarTest($pdo, $mhsUnregisteredActive);
assert(strpos($htmlMhs10, '<div class="menu-label">Pretes</div>') === false, "[FAIL] Menu Pretes harus disembunyikan untuk mhs belum daftar (aktif)");
assert(strpos($htmlMhs10, 'pretes-daftar.php') === false, "[FAIL] Link pretes-daftar harus disembunyikan");
assert(strpos($htmlMhs10, 'pretes-peserta.php') === false, "[FAIL] Link pretes-peserta harus disembunyikan");
assert(strpos($htmlMhs10, 'pretes-hasil.php') === false, "[FAIL] Link pretes-hasil harus disembunyikan");
assert(strpos($htmlMhs10, '<div class="menu-label">Tutorial</div>') === false, "[FAIL] Menu Tutorial harus disembunyikan untuk mhs belum daftar (aktif)");
assert(strpos($htmlMhs10, 'tutorial-pendaftaran.php') === false, "[FAIL] Link tutorial-pendaftaran harus disembunyikan");
assert(strpos($htmlMhs10, 'tutorial-info.php') === false, "[FAIL] Link tutorial-info harus disembunyikan");
assert(strpos($htmlMhs10, '<div class="menu-label">Absensi</div>') === false, "[FAIL] Menu Absensi harus disembunyikan untuk mhs belum daftar (aktif)");
assert(strpos($htmlMhs10, 'Dashboard') !== false, "[FAIL] Menu Dashboard harus tetap tampil");
echo " [PASS] Mahasiswa tidak terdaftar (akun aktif): Menu Pretes, Menu Tutorial, dan Menu Absensi tersembunyi 100%.\n";

// Uji Mahasiswa Belum Terdaftar Akun Non-Aktif
$htmlMhs11 = renderSidebarTest($pdo, $mhsUnregisteredInactive);
assert(strpos($htmlMhs11, '<div class="menu-label">Pretes</div>') === false, "[FAIL] Menu Pretes harus disembunyikan untuk mhs belum daftar (non-aktif)");
assert(strpos($htmlMhs11, '<div class="menu-label">Tutorial</div>') === false, "[FAIL] Menu Tutorial harus disembunyikan untuk mhs belum daftar (non-aktif)");
assert(strpos($htmlMhs11, '<div class="menu-label">Absensi</div>') === false, "[FAIL] Menu Absensi harus disembunyikan untuk mhs belum daftar (non-aktif)");
echo " [PASS] Mahasiswa tidak terdaftar (akun non-aktif): Menu Pretes, Menu Tutorial, dan Menu Absensi tersembunyi 100%.\n";

echo "\n=== 3. Validasi Rendering Sidebar untuk Mahasiswa Terdaftar Tutorial Saja ===\n";

$htmlMhs20 = renderSidebarTest($pdo, $mhsTutorialOnly);
assert(strpos($htmlMhs20, '<div class="menu-label">Pretes</div>') === false, "[FAIL] Mahasiswa tutorial tidak boleh melihat menu pretes");
assert(strpos($htmlMhs20, '<div class="menu-label">Tutorial</div>') !== false, "[FAIL] Mahasiswa tutorial harus melihat menu tutorial");
assert(strpos($htmlMhs20, 'tutorial-info.php') !== false, "[FAIL] Mahasiswa tutorial harus melihat tutorial-info.php");
assert(strpos($htmlMhs20, 'absensi-tutorial.php') !== false, "[FAIL] Mahasiswa tutorial harus melihat absensi-tutorial.php");
assert(strpos($htmlMhs20, 'absensi-pretes.php') === false, "[FAIL] Mahasiswa tutorial tidak boleh melihat absensi-pretes.php");
echo " [PASS] Mahasiswa hanya terdaftar tutorial: Menu Pretes tersembunyi, Menu Tutorial & Absensi Tutorial tampil normal.\n";

$htmlMhs21 = renderSidebarTest($pdo, $mhsTutorialOld);
assert(strpos($htmlMhs21, '<div class="menu-label">Pretes</div>') === false, "[FAIL] Mahasiswa tutorial lampau tidak boleh melihat menu pretes");
assert(strpos($htmlMhs21, '<div class="menu-label">Tutorial</div>') !== false, "[FAIL] Mahasiswa tutorial lampau harus melihat menu tutorial");
echo " [PASS] Mahasiswa tutorial lampau: Menu Pretes tersembunyi, Menu Tutorial tampil normal.\n";

echo "\n=== 4. Validasi Rendering Sidebar untuk Mahasiswa Terdaftar Pretes Saja ===\n";

$htmlMhs30 = renderSidebarTest($pdo, $mhsPretesActive);
assert(strpos($htmlMhs30, '<div class="menu-label">Pretes</div>') !== false, "[FAIL] Mahasiswa pretes aktif harus melihat menu pretes");
assert(strpos($htmlMhs30, 'pretes-peserta.php') !== false, "[FAIL] Mahasiswa pretes aktif harus melihat pretes-peserta.php");
assert(strpos($htmlMhs30, '<div class="menu-label">Tutorial</div>') === false, "[FAIL] Mahasiswa pretes aktif tidak boleh melihat menu tutorial");
echo " [PASS] Mahasiswa terdaftar pretes aktif: Menu Pretes tampil, Menu Tutorial tersembunyi.\n";

$htmlMhs31 = renderSidebarTest($pdo, $mhsPretesLulus);
assert(strpos($htmlMhs31, '<div class="menu-label">Pretes</div>') !== false, "[FAIL] Mahasiswa pretes lulus harus melihat menu pretes");
assert(strpos($htmlMhs31, '<div class="menu-label">Tutorial</div>') === false, "[FAIL] Mahasiswa pretes lulus dibebaskan dari tutorial (menu tutorial tersembunyi)");
echo " [PASS] Mahasiswa lulus pretes: Menu Pretes tampil, Menu Tutorial tersembunyi (bebas tutorial).\n";

echo "\n=== 5. Validasi Role Admin dan Dosen Tetap Normal ===\n";

$htmlAdmin = renderSidebarTest($pdo, $admin);
assert(strpos($htmlAdmin, 'Dashboard Admin') !== false, "[FAIL] Admin harus melihat Dashboard Admin");
assert(strpos($htmlAdmin, 'Manajemen Pretes') !== false, "[FAIL] Admin harus melihat Manajemen Pretes");
assert(strpos($htmlAdmin, 'Manajemen Tutorial') !== false, "[FAIL] Admin harus melihat Manajemen Tutorial");

$htmlDosen = renderSidebarTest($pdo, $dosen);
assert(strpos($htmlDosen, 'Dashboard Dosen') !== false, "[FAIL] Dosen harus melihat Dashboard Dosen");
assert(strpos($htmlDosen, 'Kelas Anda') !== false, "[FAIL] Dosen harus melihat Kelas Anda");
echo " [PASS] Menu Admin dan Dosen tetap lengkap dan terisolasi.\n";

echo "\n✅ Seluruh skenario pengujian mahasiswa tidak terdaftar (aktif / non-aktif) BERHASIL 100%!\n";
