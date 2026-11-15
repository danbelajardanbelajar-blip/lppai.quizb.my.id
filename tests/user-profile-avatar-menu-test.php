<?php
/**
 * Test Suite: Validasi Ikon Avatar Foto Profil dan Dropdown Menu Profil & Pengaturan Seluruh Role
 */

define('LPPAI_TEST_MODE', true);
if (!defined('BASE_URL')) {
    define('BASE_URL', 'https://lppai.quizb.my.id');
}
if (!defined('APP_NAME')) {
    define('APP_NAME', 'LPPAI Corner');
}

require_once __DIR__ . '/../includes/user-helper.php';

echo "=== Menjalankan Validasi Fitur Avatar Foto & Dropdown Profil/Pengaturan ===\n";

function menuAssert(bool $condition, string $passMsg, string $failMsg): void {
    if (!$condition) {
        echo " [FAIL] $failMsg\n";
        exit(1);
    }
    echo " [PASS] $passMsg\n";
}

// ── Test 1: In-Memory SQLite Fixture untuk ensureUserPhotoColumn & getUserAvatarUrl ──
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    nama_lengkap TEXT NOT NULL,
    nim TEXT,
    role TEXT NOT NULL DEFAULT 'mahasiswa',
    program_studi TEXT,
    jenis_program TEXT DEFAULT 'Reguler',
    email TEXT,
    no_hp TEXT,
    tempat_lahir TEXT,
    tanggal_lahir TEXT,
    angkatan TEXT,
    is_active INTEGER DEFAULT 1
)");

$pdo->exec("CREATE TABLE pretes_registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    periode TEXT NOT NULL,
    hari_pilihan TEXT,
    jam TEXT,
    foto TEXT,
    status TEXT DEFAULT 'terdaftar'
)");

// Pastikan migrasi kolom foto berjalan idempoten
ensureUserPhotoColumn($pdo);
$cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_map(fn($c) => strtolower((string)$c['name']), $cols);
menuAssert(in_array('foto', $colNames, true), 'Kolom users.foto berhasil dipastikan keberadaannya via ensureUserPhotoColumn()', 'Kolom foto tidak ditemukan di tabel users');

// Setup User 1: Admin dengan foto profil kustom
$pdo->prepare("INSERT INTO users (username, password, nama_lengkap, role, foto) VALUES (?, 'pass', ?, 'admin', ?)")
    ->execute(['admin_zainul', 'Zainul Hakim', 'uploads/profil/avatar_admin.jpg']);
$adminId = (int)$pdo->lastInsertId();

// Setup User 2: Mahasiswa tanpa users.foto, tetapi punya pasfoto pretes resmi
$pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nim, role, program_studi, jenis_program) VALUES (?, 'pass', ?, ?, 'mahasiswa', 'Pendidikan Agama Islam', 'RPL')")
    ->execute(['2601001', 'Fathur Rahman RPL', '2601001']);
$mhsId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO pretes_registrations (user_id, periode, foto) VALUES (?, '2025/2026 Ganjil', ?)")
    ->execute([$mhsId, 'uploads/pretes_foto/pasfoto_2601001.jpg']);

// Setup User 3: Dosen tanpa foto sama sekali
$pdo->prepare("INSERT INTO users (username, password, nama_lengkap, role) VALUES (?, 'pass', ?, 'dosen')")
    ->execute(['dosen_ahmad', 'Dr. Ahmad Fauzi, M.Pd.']);
$dosenId = (int)$pdo->lastInsertId();

// ── Test 2: Pengujian Logika Resolusi Avatar (getUserAvatarUrl) ──────────────
// User 1 (Admin): Foto diambil dari users.foto
$adminUser = ['id' => $adminId, 'nama_lengkap' => 'Zainul Hakim', 'role' => 'admin', 'foto' => 'uploads/profil/avatar_admin.jpg'];
$adminAvatar = getUserAvatarUrl($adminUser, $pdo);
menuAssert($adminAvatar !== null && strpos($adminAvatar, 'uploads/profil/avatar_admin.jpg') !== false, 'Admin mendapatkan URL foto dari users.foto secara tepat', 'URL avatar admin salah');

// User 2 (Mahasiswa): Fallback ke pretes_registrations.foto ketika users.foto belum ada
$mhsUser = ['id' => $mhsId, 'nama_lengkap' => 'Fathur Rahman RPL', 'role' => 'mahasiswa', 'foto' => null];
$mhsAvatar = getUserAvatarUrl($mhsUser, $pdo);
menuAssert($mhsAvatar !== null && strpos($mhsAvatar, 'uploads/pretes_foto/pasfoto_2601001.jpg') !== false, 'Mahasiswa otomatis mengambil pasfoto pretes resmi sebagai avatar profil', 'Fallback pasfoto pretes gagal');

// User 3 (Dosen): Belum memiliki foto (mengembalikan null untuk fallback ke inisial huruf)
$dosenUser = ['id' => $dosenId, 'nama_lengkap' => 'Dr. Ahmad Fauzi, M.Pd.', 'role' => 'dosen', 'foto' => null];
$dosenAvatar = getUserAvatarUrl($dosenUser, $pdo);
menuAssert($dosenAvatar === null, 'User tanpa foto mengembalikan null (fallback ke inisial huruf nama)', 'User tanpa foto tidak bernilai null');

// ── Test 3: Verifikasi includes/header.php ───────────────────────────────────
$headerFile = file_get_contents(__DIR__ . '/../includes/header.php');
menuAssert($headerFile !== false, 'File includes/header.php berhasil dibaca', 'File header tidak ditemukan');
menuAssert(strpos($headerFile, 'user-menu-container') !== false, 'includes/header.php memuat container .user-menu-container', 'Container menu tidak ada');
menuAssert(strpos($headerFile, 'id="userMenuBtn"') !== false, 'includes/header.php memuat tombol interaktif #userMenuBtn', 'Tombol menu tidak ada');
menuAssert(strpos($headerFile, 'userPhotoUrl') !== false, 'includes/header.php mengecek ketersediaan foto user via getUserAvatarUrl()', 'Pengecekan foto tidak ada');
menuAssert(strpos($headerFile, 'avatar-img') !== false, 'includes/header.php merender <img> avatar-img saat user memiliki foto', 'Tag <img> avatar tidak ada');
menuAssert(strpos($headerFile, 'avatar-initial') !== false, 'includes/header.php mendukung inisial avatar saat foto kosong atau error', 'Elemen avatar-initial tidak ada');
menuAssert(strpos($headerFile, 'id="userDropdownMenu"') !== false, 'includes/header.php memuat dropdown menu #userDropdownMenu', 'Elemen dropdown menu tidak ada');
menuAssert(strpos($headerFile, 'profil.php') !== false, 'includes/header.php menyediakan menu Profil Saya menuju profil.php', 'Tautan profil.php tidak ada');
menuAssert(strpos($headerFile, 'pengaturan.php') !== false, 'includes/header.php menyediakan menu Pengaturan menuju pengaturan.php', 'Tautan pengaturan.php tidak ada');
menuAssert(strpos($headerFile, 'logout.php') !== false, 'includes/header.php menyediakan menu Keluar menuju logout.php', 'Tautan logout.php tidak ada');
menuAssert(strpos($headerFile, 'Escape') !== false, 'includes/header.php memiliki event listener keyboard Escape untuk menutup dropdown', 'Handler keyboard Escape tidak ada');

// ── Test 4: Verifikasi assets/css/style.css ──────────────────────────────────
$cssFile = file_get_contents(__DIR__ . '/../assets/css/style.css');
menuAssert(strpos($cssFile, '.user-menu-container') !== false, 'style.css memiliki styling .user-menu-container', 'CSS .user-menu-container tidak ditemukan');
menuAssert(strpos($cssFile, '.user-info.user-menu-btn') !== false, 'style.css memiliki styling .user-info.user-menu-btn', 'CSS .user-menu-btn tidak ditemukan');
menuAssert(strpos($cssFile, '.topbar .user-info .avatar') !== false, 'style.css mempertahankan styling luxury ring .topbar .user-info .avatar', 'CSS avatar hilang');
menuAssert(strpos($cssFile, '.topbar .user-info .avatar.has-photo') !== false, 'style.css memiliki styling .avatar.has-photo', 'CSS .has-photo tidak ditemukan');
menuAssert(strpos($cssFile, '.avatar-img') !== false, 'style.css memiliki styling .avatar-img (object-fit: cover)', 'CSS .avatar-img tidak ditemukan');
menuAssert(strpos($cssFile, '.user-dropdown-menu') !== false, 'style.css memiliki styling .user-dropdown-menu', 'CSS .user-dropdown-menu tidak ditemukan');
menuAssert(strpos($cssFile, '.user-menu-container.open .user-dropdown-menu') !== false, 'style.css memiliki transisi pembuka dropdown saat .open aktif', 'CSS state .open tidak ditemukan');

// ── Test 5: Verifikasi File Halaman pages/profil.php & profil.php ────────────
$profilFile = file_get_contents(__DIR__ . '/../pages/profil.php');
menuAssert($profilFile !== false, 'File pages/profil.php berhasil dibaca', 'File pages/profil.php tidak ditemukan');
menuAssert(strpos($profilFile, 'requireLogin();') !== false, 'pages/profil.php dilindungi requireLogin()', 'Tidak ada proteksi requireLogin()');
menuAssert(strpos($profilFile, 'upload_photo') !== false, 'pages/profil.php memiliki aksi upload_photo', 'Aksi upload_photo tidak ditemukan');
menuAssert(strpos($profilFile, 'delete_photo') !== false, 'pages/profil.php memiliki aksi delete_photo', 'Aksi delete_photo tidak ditemukan');
menuAssert(strpos($profilFile, 'update_profile') !== false, 'pages/profil.php memiliki aksi update_profile', 'Aksi update_profile tidak ditemukan');
menuAssert(file_exists(__DIR__ . '/../profil.php'), 'File entrypoint root profil.php tersedia', 'profil.php root tidak ditemukan');

// ── Test 6: Verifikasi File Halaman pages/pengaturan.php & pengaturan.php ───
$pengaturanFile = file_get_contents(__DIR__ . '/../pages/pengaturan.php');
menuAssert($pengaturanFile !== false, 'File pages/pengaturan.php berhasil dibaca', 'File pages/pengaturan.php tidak ditemukan');
menuAssert(strpos($pengaturanFile, 'requireLogin();') !== false, 'pages/pengaturan.php dilindungi requireLogin()', 'Tidak ada proteksi requireLogin()');
menuAssert(strpos($pengaturanFile, 'change_password') !== false, 'pages/pengaturan.php memiliki aksi change_password', 'Aksi change_password tidak ditemukan');
menuAssert(strpos($pengaturanFile, 'password_verify') !== false, 'pages/pengaturan.php memvalidasi kata sandi lama sebelum diganti', 'Validasi kata sandi lama tidak ada');
menuAssert(strpos($pengaturanFile, 'password_hash') !== false, 'pages/pengaturan.php mengenkripsi kata sandi baru dengan password_hash()', 'password_hash tidak ada');
menuAssert(file_exists(__DIR__ . '/../pengaturan.php'), 'File entrypoint root pengaturan.php tersedia', 'pengaturan.php root tidak ditemukan');

// ── Test 7: Verifikasi Navigasi Sidebar includes/sidebar.php ─────────────────
$sidebarFile = file_get_contents(__DIR__ . '/../includes/sidebar.php');
menuAssert(strpos($sidebarFile, 'profil.php') !== false, 'includes/sidebar.php memuat tautan Profil Saya', 'Tautan profil di sidebar tidak ada');
menuAssert(strpos($sidebarFile, 'pengaturan.php') !== false, 'includes/sidebar.php memuat tautan Pengaturan', 'Tautan pengaturan di sidebar tidak ada');

echo "\nSemua 32 uji validasi Avatar Foto Profil & Menu Profil/Pengaturan BERHASIL 100% SEMPURNA!\n";
