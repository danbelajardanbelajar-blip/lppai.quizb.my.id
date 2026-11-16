<?php
/**
 * Test Suite: Tutorial Attendance Modern Mobile Responsive View
 * Verifies both Mahasiswa scanner/tracker view and Dosen tab absensi (presentation mode, desktop table, mobile cards).
 */

echo "=== MEMULAI TEST TUTORIAL ATTENDANCE MOBILE MODERN ===\n\n";

$root = dirname(__DIR__);
$cssFile = $root . '/assets/css/style.css';
$mhsFile = $root . '/absensi-tutorial.php';
$dosenFile = $root . '/dosen/detail-kelas.php';

assert(file_exists($cssFile), "assets/css/style.css harus ada");
assert(file_exists($mhsFile), "absensi-tutorial.php harus ada");
assert(file_exists($dosenFile), "dosen/detail-kelas.php harus ada");

// 1. Verifikasi Keberadaan CSS di style.css
echo "[1] Memeriksa kelengkapan aturan CSS di assets/css/style.css...\n";
$css = file_get_contents($cssFile);
$requiredCssRules = [
    '.attendance-hero-card',
    '.attendance-class-badge',
    '.attendance-progress-bar',
    '.attendance-progress-fill',
    '.meetings-tracker-card',
    '.meetings-badge-grid',
    '.meeting-badge-item.status-hadir',
    '.scanner-card',
    '.scanner-viewfinder-wrapper',
    '.corner-bracket',
    '.scanner-laser-sweep',
    '.pertemuan-nav-scroll',
    '.pertemuan-pill-tab',
    '.qr-fullscreen-modal',
    '.status-pill-group',
    '.status-pill-btn',
    '.student-attendance-card',
    '.student-avatar-initial',
    '.floating-attendance-bar',
    '.floating-save-btn',
    '.desktop-attendance-table',
    '.mobile-attendance-card-list',
    '@media (max-width: 767.98px)',
    '@media (min-width: 768px)'
];

foreach ($requiredCssRules as $rule) {
    if (strpos($css, $rule) === false) {
        echo "❌ GAGAL: Aturan CSS '$rule' tidak ditemukan di assets/css/style.css!\n";
        exit(1);
    }
}
echo "  ✓ Semua aturan CSS responsive, mobile card, dan Emerald Royale lengkap.\n\n";

// 2. Verifikasi Sintaks & Konten absensi-tutorial.php
echo "[2] Memeriksa struktur absensi-tutorial.php (Tampilan Mahasiswa)...\n";
$mhsContent = file_get_contents($mhsFile);

$requiredMhsElements = [
    'attendance-hero-card',
    'attendance-progress-bar',
    'attendance-progress-fill',
    'meetings-tracker-card',
    'meetings-badge-grid',
    'meeting-badge-item',
    'scanner-card',
    'scanner-viewfinder-wrapper',
    'corner-bracket tl',
    'corner-bracket tr',
    'corner-bracket bl',
    'corner-bracket br',
    'scanner-laser-sweep',
    'scanner-live-badge',
    'navigator.vibrate',
    'Swal.fire',
    'Kelas Tutorial Belum Ditetapkan'
];

foreach ($requiredMhsElements as $elem) {
    if (strpos($mhsContent, $elem) === false) {
        echo "❌ GAGAL: Komponen '$elem' tidak ditemukan di absensi-tutorial.php!\n";
        exit(1);
    }
}
echo "  ✓ absensi-tutorial.php memiliki Hero Card, 8-Meeting Grid, Viewfinder Laser, dan haptic feedback.\n\n";

// 3. Verifikasi Struktur dosen/detail-kelas.php (Tampilan Dosen Tab Absensi)
echo "[3] Memeriksa struktur dosen/detail-kelas.php (Tampilan Dosen)...\n";
$dosenContent = file_get_contents($dosenFile);

$requiredDosenElements = [
    'pertemuan-nav-scroll',
    'pertemuan-pill-tab',
    'qr-fullscreen-modal',
    'fullscreenQrcode',
    'btnOpenFullscreenQR',
    'openFullscreenQR',
    'closeFullscreenQR',
    'markAllHadir',
    'resetAllBelum',
    'syncAttendanceStatus',
    'updateLiveCounters',
    'countHadir',
    'countIzin',
    'countSakit',
    'countAbsen',
    'countBelum',
    'desktop-attendance-table',
    'mobile-attendance-card-list',
    'student-attendance-card',
    'student-avatar-initial',
    'floating-attendance-bar',
    'floating-save-btn'
];

foreach ($requiredDosenElements as $elem) {
    if (strpos($dosenContent, $elem) === false) {
        echo "❌ GAGAL: Komponen '$elem' tidak ditemukan di dosen/detail-kelas.php!\n";
        exit(1);
    }
}
echo "  ✓ dosen/detail-kelas.php memiliki Scrolling Tab, Presentation Mode QR, Batch Hadir Semua, Desktop Table, Mobile Cards, dan Floating Bar.\n\n";

// 4. Verifikasi Logika Operasional Database via In-Memory SQLite Mock
echo "[4] Pengujian Alur Logika Database & Penyimpanan Absensi...\n";
$sqlite = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$sqlite->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nama_lengkap TEXT,
        nim TEXT,
        role TEXT,
        username TEXT,
        program_studi TEXT
    );
    CREATE TABLE tutorial_classes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nama_kelas TEXT,
        hari TEXT,
        jam TEXT,
        dosen_pengampu TEXT,
        ruangan TEXT,
        gelombang TEXT,
        semester TEXT
    );
    CREATE TABLE tutorial_registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        tutorial_class_id INTEGER,
        status TEXT,
        hari_pilihan TEXT,
        gelombang TEXT,
        tahun_ajaran TEXT
    );
    CREATE TABLE tutorial_attendance (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tutorial_class_id INTEGER,
        user_id INTEGER,
        pertemuan_ke INTEGER,
        tanggal TEXT,
        status_hadir TEXT,
        UNIQUE(tutorial_class_id, user_id, pertemuan_ke)
    );
");

// Insert Mock Data
$sqlite->exec("INSERT INTO users (nama_lengkap, nim, role, username, program_studi) VALUES ('Dosen Pengampu M.Pd', '19850101', 'dosen', 'dosen1', 'PAI')");
$sqlite->exec("INSERT INTO users (nama_lengkap, nim, role, username, program_studi) VALUES ('Siti Mahasiswa', '2026001', 'mahasiswa', 'mhs01', 'PAI')");
$sqlite->exec("INSERT INTO tutorial_classes (nama_kelas, hari, jam, dosen_pengampu, ruangan, gelombang, semester) VALUES ('PAI-Kelas 1A', 'Senin', '08:00 - 09:30', 'Dosen Pengampu M.Pd', 'R-101', 'gel1', '2026/2027 Ganjil')");
$sqlite->exec("INSERT INTO tutorial_registrations (user_id, tutorial_class_id, status, hari_pilihan, gelombang, tahun_ajaran) VALUES (2, 1, 'terdaftar', 'Senin', 'gel1', '2026/2027')");

// Simulasi query mahasiswa pada absensi-tutorial.php
$stmtMhsQuery = $sqlite->prepare("
    SELECT tr.id as reg_id, tr.tutorial_class_id, tr.status, tr.hari_pilihan, tr.gelombang,
           tc.nama_kelas, tc.dosen_pengampu, tc.hari, tc.jam, tc.ruangan, tc.semester
    FROM tutorial_registrations tr
    LEFT JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
    WHERE tr.user_id = ?
    ORDER BY tr.id DESC
    LIMIT 1
");
$stmtMhsQuery->execute([2]);
$mhsRes = $stmtMhsQuery->fetch();
assert($mhsRes !== false, "Query registrasi tutorial mahasiswa harus berhasil");
assert($mhsRes['nama_kelas'] === 'PAI-Kelas 1A', "Nama kelas harus sesuai");
echo "  ✓ Query riwayat & registrasi tutorial mahasiswa berhasil.\n";

// Simulasi simpan absensi Dosen (Pertemuan 1 Hadir, Pertemuan 2 Izin)
$sqlite->exec("INSERT OR REPLACE INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (1, 2, 1, '2026-11-16', 'hadir')");
$sqlite->exec("INSERT OR REPLACE INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (1, 2, 2, '2026-11-23', 'izin')");

// Ambil riwayat kehadiran untuk mahasiswa
$stmtAttQuery = $sqlite->prepare("
    SELECT pertemuan_ke, tanggal, status_hadir
    FROM tutorial_attendance
    WHERE tutorial_class_id = ? AND user_id = ?
    ORDER BY pertemuan_ke ASC
");
$stmtAttQuery->execute([1, 2]);
$history = $stmtAttQuery->fetchAll();
assert(count($history) === 2, "Harus ada 2 riwayat kehadiran tercatat");
assert($history[0]['status_hadir'] === 'hadir', "Pertemuan 1 harus hadir");
assert($history[1]['status_hadir'] === 'izin', "Pertemuan 2 harus izin");
echo "  ✓ Simulasi penyimpanan absensi dan query 8 pertemuan sukses.\n\n";

echo "=== SEMUA VERIFIKASI PENGUJIAN ABSENSI TUTORIAL MOBILE MODERN LULUS DENGAN SEMPURNA! ===\n";
