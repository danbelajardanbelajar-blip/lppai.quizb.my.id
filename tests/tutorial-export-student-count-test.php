<?php
/**
 * Test Suite: Tutorial Scheduled Lecturers Export with Student Count per Session
 * 
 * Memverifikasi:
 * 1. Fungsi tutorialScheduledLecturerClasses() mengembalikan field jumlah_mahasiswa per sesi kelas.
 * 2. Hitungan mahasiswa per kelas akurat sesuai data tutorial_registrations.
 * 3. Halaman export PDF (includes/tutorial-jadwal-dosen-export-view.php) memuat kolom Jml Mhs, baris data, dan total mahasiswa.
 * 4. Tampilan tabel di halaman admin (includes/tutorial-scheduled-lecturers-view.php) memuat kolom Jml Mahasiswa.
 */

declare(strict_types=1);

if (!defined('BASE_URL')) define('BASE_URL', 'https://lppai.quizb.my.id');

function exportAssert(bool $condition, string $description, string $failureDetails = ''): void {
    if (!$condition) {
        echo "❌ [FAIL] {$description}\n";
        if ($failureDetails) echo "   Detail: {$failureDetails}\n";
        exit(1);
    }
    echo " [PASS] {$description}\n";
}

echo "=== Menjalankan Validasi Export Jadwal Dosen dengan Jumlah Mahasiswa per Sesi ===\n";

// ── 1. Setup SQLite Fixture ────────────────────────────────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$pdo->exec("CREATE TABLE master_gelombang (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    status TEXT,
    tahun_ajaran TEXT,
    semester TEXT,
    gelombang TEXT,
    created_at TEXT
)");

$pdo->exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nama_lengkap TEXT NOT NULL,
    nim TEXT,
    program_studi TEXT,
    jenis_program TEXT DEFAULT 'Reguler',
    role TEXT DEFAULT 'mahasiswa'
)");

$pdo->exec("CREATE TABLE tutorial_classes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nama_kelas TEXT NOT NULL,
    dosen_pengampu TEXT NOT NULL,
    hari TEXT NOT NULL,
    jam TEXT NOT NULL,
    ruangan TEXT,
    gelombang TEXT NOT NULL,
    semester TEXT NOT NULL,
    kuota INTEGER DEFAULT 30
)");

$pdo->exec("CREATE TABLE tutorial_registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    tutorial_class_id INTEGER,
    hari_pilihan TEXT DEFAULT 'Rabu',
    gelombang TEXT DEFAULT 'gel1',
    tahun_ajaran TEXT DEFAULT '2026/2027',
    status TEXT DEFAULT 'terdaftar',
    created_at TEXT DEFAULT '2026-11-01'
)");

// Insert active period
$pdo->exec("INSERT INTO master_gelombang (id, status, tahun_ajaran, semester, gelombang, created_at)
            VALUES (1, 'aktif', '2026/2027', 'Ganjil', 'gel1', '2026-11-01')");

// Insert classes
$pdo->exec("INSERT INTO tutorial_classes (id, nama_kelas, dosen_pengampu, hari, jam, ruangan, gelombang, semester) VALUES
    (1, 'Kelas Rabu A', 'Dr. Ida Latifatul Umroh, M.Pd.I', 'Rabu', '13.00 - 14.30', 'F.Ekonomi lt.1 (102-103)', 'gel1', '2026-2027-Ganjil'),
    (2, 'Kelas Rabu B', 'Dr. Mokhamad Ali Musyaffa, Lc., M.Pd', 'Rabu', '13.00 - 14.30', 'F.Ekonomi lt.1 (104-105)', 'gel1', '2026-2027-Ganjil'),
    (3, 'Kelas Rabu C', 'M. Naqouib Ashrofun Nashr, M.Pd', 'Rabu', '13.00 - 14.30', 'F.Ekonomi lt.1 (106-107)', 'gel1', '2026-2027-Ganjil')
");

// Insert 25 students in Kelas A, 18 students in Kelas B, 0 students in Kelas C
for ($i = 1; $i <= 25; $i++) {
    $pdo->exec("INSERT INTO users (id, nama_lengkap, nim) VALUES ($i, 'Mhs A $i', 'NIM_A_$i')");
    $pdo->exec("INSERT INTO tutorial_registrations (user_id, tutorial_class_id) VALUES ($i, 1)");
}
for ($i = 26; $i <= 43; $i++) {
    $pdo->exec("INSERT INTO users (id, nama_lengkap, nim) VALUES ($i, 'Mhs B $i', 'NIM_B_$i')");
    $pdo->exec("INSERT INTO tutorial_registrations (user_id, tutorial_class_id) VALUES ($i, 2)");
}

require_once __DIR__ . '/../includes/tutorial-scheduled-lecturers.php';

$period = tutorialActiveSchedulePeriod($pdo);
exportAssert($period !== null, 'Periode aktif tutorial berhasil dibaca.');

$classes = tutorialScheduledLecturerClasses($pdo, $period);
exportAssert(count($classes) === 3, 'Tepat 3 kelas terjadwal pada periode aktif.');

// Check student counts
$classMap = [];
foreach ($classes as $c) {
    $classMap[$c['nama_kelas']] = (int)($c['jumlah_mahasiswa'] ?? -1);
}

exportAssert(isset($classMap['Kelas Rabu A']) && $classMap['Kelas Rabu A'] === 25, 'Kelas Rabu A memiliki tepat 25 mahasiswa.');
exportAssert(isset($classMap['Kelas Rabu B']) && $classMap['Kelas Rabu B'] === 18, 'Kelas Rabu B memiliki tepat 18 mahasiswa.');
exportAssert(isset($classMap['Kelas Rabu C']) && $classMap['Kelas Rabu C'] === 0, 'Kelas Rabu C memiliki tepat 0 mahasiswa.');

// ── 2. Test File Markup & Template Elements ────────────────────────────────
$exportView = file_get_contents(__DIR__ . '/../includes/tutorial-jadwal-dosen-export-view.php');
exportAssert(strpos($exportView, '>Jml Mhs</th>') !== false, 'Export view memuat header kolom >Jml Mhs</th>.');
exportAssert(strpos($exportView, "\$row['jumlah_mahasiswa']") !== false, 'Export view merender kolom jumlah_mahasiswa per baris sesi dosen.');
exportAssert(strpos($exportView, 'Total Mahasiswa:') !== false, 'Export view memuat baris footer tfoot Total Mahasiswa.');
exportAssert(strpos($exportView, "\$totalStudents") !== false, 'Export view menghitung total keseluruhan mahasiswa terjadwal.');

$tableView = file_get_contents(__DIR__ . '/../includes/tutorial-scheduled-lecturers-view.php');
exportAssert(strpos($tableView, '<th>Jml Mahasiswa</th>') !== false, 'Tabel admin memuat header kolom <th>Jml Mahasiswa</th>.');
exportAssert(strpos($tableView, "\$schedule['jumlah_mahasiswa']") !== false, 'Tabel admin merender jumlah mahasiswa per baris kelas.');
exportAssert(strpos($tableView, "\$totalScheduledStudents") !== false, 'Tabel admin merangkum total mahasiswa terjadwal pada header card.');

echo "\nSemua pengujian Export Jadwal Dosen dengan Jumlah Mahasiswa per Sesi BERHASIL 100% SEMPURNA!\n";
exit(0);
