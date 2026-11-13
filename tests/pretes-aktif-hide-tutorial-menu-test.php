<?php
/**
 * Test: Sembunyikan Seluruh Menu Tutorial & Absensi Saat Mahasiswa Dalam Keadaan Pretes Aktif
 */

require_once __DIR__ . '/../includes/pretes-helper.php';

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
    CREATE TABLE pretes_assessments (
        registration_id INTEGER PRIMARY KEY,
        scores_json TEXT,
        assessed_by INTEGER DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
        status TEXT
    );
    INSERT INTO master_gelombang (id, nama_gelombang, status) VALUES (1, 'Gelombang 1', 'aktif');
");

// Setup Periode Pretes Aktif 2026/2027
$pdo->exec("INSERT INTO master_pretes (id, nama_pretes, periode, status, target_program) VALUES (1, 'Pretes Reguler 2026/2027', '2026/2027', 'aktif', 'Semua')");

// User 1: Mahasiswa terdaftar di periode aktif, belum dinilai
$pdo->exec("INSERT INTO pretes_registrations (id, user_id, periode) VALUES (1, 101, '2026/2027')");
$mhs1 = ['id' => 101, 'role' => 'mahasiswa', 'jenis_program' => 'Reguler'];

// User 2: Mahasiswa belum terdaftar, tapi periode pretes aktif terbuka untuknya
$mhs2 = ['id' => 102, 'role' => 'mahasiswa', 'jenis_program' => 'Reguler'];

// User 3: Mahasiswa yang SUDAH LULUS pretes
$pdo->exec("INSERT INTO pretes_registrations (id, user_id, periode) VALUES (3, 103, '2026/2027')");
$pdo->exec("INSERT INTO pretes_results (user_id, status_lulus, nilai) VALUES (103, 'lulus', 90.0)");
$mhs3 = ['id' => 103, 'role' => 'mahasiswa', 'jenis_program' => 'Reguler'];

// User 4: Admin
$admin = ['id' => 1, 'role' => 'admin'];

// User 5: Dosen
$dosen = ['id' => 2, 'role' => 'dosen', 'nama_lengkap' => 'Dosen Test'];

echo "=== Menjalankan Validasi isMahasiswaPretesAktif() ===\n";

assert(isMahasiswaPretesAktif($pdo, $mhs1) === true, "[FAIL] Mahasiswa 1 terdaftar periode aktif harus berstatus pretes aktif");
echo " [PASS] Mahasiswa terdaftar periode aktif terdeteksi isMahasiswaPretesAktif = true\n";

assert(isMahasiswaPretesAktif($pdo, $mhs2) === true, "[FAIL] Mahasiswa 2 belum daftar saat pretes aktif harus berstatus pretes aktif");
echo " [PASS] Mahasiswa belum daftar di periode aktif terdeteksi isMahasiswaPretesAktif = true\n";

assert(isMahasiswaPretesAktif($pdo, $mhs3) === false, "[FAIL] Mahasiswa 3 yang sudah lulus tidak boleh berstatus pretes aktif");
echo " [PASS] Mahasiswa lulus pretes terdeteksi isMahasiswaPretesAktif = false\n";

assert(isMahasiswaPretesAktif($pdo, $admin) === false, "[FAIL] Admin tidak boleh berstatus pretes aktif");
assert(isMahasiswaPretesAktif($pdo, $dosen) === false, "[FAIL] Dosen tidak boleh berstatus pretes aktif");
echo " [PASS] Role Admin dan Dosen terisolasi (isMahasiswaPretesAktif = false)\n";

// Simulasi jika periode pretes dinonaktifkan / selesai
$pdo->exec("UPDATE master_pretes SET status = 'selesai' WHERE id = 1");
assert(isMahasiswaPretesAktif($pdo, $mhs2) === false, "[FAIL] Saat tidak ada periode pretes aktif, mahasiswa 2 tidak aktif pretes");
echo " [PASS] Saat periode pretes ditutup/selesai, isMahasiswaPretesAktif = false\n";

// Kembalikan status aktif untuk testing rendering sidebar
$pdo->exec("UPDATE master_pretes SET status = 'aktif' WHERE id = 1");

echo "\n=== Menjalankan Validasi Rendering Sidebar untuk Mahasiswa Pretes Aktif ===\n";

if (!defined('BASE_URL')) define('BASE_URL', 'https://lppai.quizb.my.id');
if (!defined('APP_NAME')) define('APP_NAME', 'LPPAI Corner');

// Fungsi helper simulasi include sidebar dengan context user & pdo tertentu
function renderSidebarForUser($pdoInstance, $user) {
    $currentUser = $user;
    $_SERVER['PHP_SELF'] = '/dashboard.php';
    
    // Override getDBConnection() jika fungsi mock tersedia
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

// 1. Render sidebar untuk Mahasiswa Pretes Aktif ($mhs1)
$sidebarMhsAktif = renderSidebarForUser($pdo, $mhs1);

assert(strpos($sidebarMhsAktif, 'Peserta & Jadwal Pretes') !== false, "[FAIL] Mahasiswa pretes aktif harus melihat menu Peserta & Jadwal Pretes");
assert(strpos($sidebarMhsAktif, 'Hasil Pretes') !== false, "[FAIL] Mahasiswa pretes aktif harus melihat menu Hasil Pretes");

// Wajib tersembunyi: Menu Tutorial dan Absensi
assert(strpos($sidebarMhsAktif, '<div class="menu-label">Tutorial</div>') === false, "[FAIL] Menu label Tutorial harus disembunyikan saat pretes aktif");
assert(strpos($sidebarMhsAktif, 'tutorial-pendaftaran.php') === false, "[FAIL] Link tutorial-pendaftaran.php harus disembunyikan saat pretes aktif");
assert(strpos($sidebarMhsAktif, 'tutorial-info.php') === false, "[FAIL] Link tutorial-info.php harus disembunyikan saat pretes aktif");
assert(strpos($sidebarMhsAktif, 'rekap-nilai.php') === false, "[FAIL] Link rekap-nilai.php harus disembunyikan saat pretes aktif");
assert(strpos($sidebarMhsAktif, 'tutorial-pengumuman.php') === false, "[FAIL] Link tutorial-pengumuman.php harus disembunyikan saat pretes aktif");
assert(strpos($sidebarMhsAktif, 'ambil-sertifikat.php') === false, "[FAIL] Link ambil-sertifikat.php harus disembunyikan saat pretes aktif");

assert(strpos($sidebarMhsAktif, '<div class="menu-label">Absensi</div>') === false, "[FAIL] Menu label Absensi harus disembunyikan saat pretes aktif");
assert(strpos($sidebarMhsAktif, 'absensi-tutorial.php') === false, "[FAIL] Link absensi-tutorial.php harus disembunyikan saat pretes aktif");
assert(strpos($sidebarMhsAktif, 'absensi-alkhidmah.php') === false, "[FAIL] Link absensi-alkhidmah.php harus disembunyikan saat pretes aktif");

echo " [PASS] Mahasiswa pretes aktif: seluruh menu Tutorial & Absensi tersembunyi 100%\n";

// 2. Render sidebar saat periode pretes SELESAI ($pdo pretes selesai)
$pdo->exec("UPDATE master_pretes SET status = 'selesai' WHERE id = 1");
$sidebarMhsSelesai = renderSidebarForUser($pdo, $mhs1);

assert(strpos($sidebarMhsSelesai, '<div class="menu-label">Tutorial</div>') !== false, "[FAIL] Menu Tutorial harus tampil saat periode pretes selesai");
assert(strpos($sidebarMhsSelesai, 'tutorial-pendaftaran.php') !== false, "[FAIL] Link tutorial-pendaftaran.php harus tampil saat periode pretes selesai");
assert(strpos($sidebarMhsSelesai, '<div class="menu-label">Absensi</div>') !== false, "[FAIL] Menu Absensi harus tampil saat periode pretes selesai");

echo " [PASS] Mahasiswa periode pretes selesai: menu Tutorial & Absensi tampil normal untuk pendaftaran tutorial\n";

echo "\n✅ Seluruh pengujian sembunyikan menu Tutorial & Absensi saat pretes aktif BERHASIL 100%!\n";
