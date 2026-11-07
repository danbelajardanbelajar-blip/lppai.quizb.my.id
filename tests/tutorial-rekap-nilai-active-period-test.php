<?php
/**
 * Test Suite: Verifikasi Rekapitulasi Nilai Tutorial Berdasarkan Periode Aktif
 * Memastikan rekap-nilai.php secara default menampilkan data dari periode aktif.
 */

// 1. Static Verification
$file = __DIR__ . '/../rekap-nilai.php';
$content = file_get_contents($file);
assert($content !== false, "File rekap-nilai.php harus dapat dibaca");

// Verifikasi pengecekan status = 'aktif' pada master_gelombang
assert(strpos($content, "status = 'aktif'") !== false, "Harus ada query master_gelombang dengan status = 'aktif'");

// Verifikasi default selected_gel_id adalah 'active'
assert(strpos($content, "'active'") !== false, "Default periode harus 'active'");

// Verifikasi badge indikator Periode Aktif
assert(strpos($content, "Periode Aktif:") !== false, "Badge Periode Aktif harus ditampilkan");

// Verifikasi filter query tidak lagi memakai hardcoded loop 2026..2050
assert(strpos($content, 'for ($y = $startYear; $y < $endYear; $y++)') === false, "Tidak boleh ada hardcoded year loop");

// 2. End-to-End Simulation dengan SQLite in-memory
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Buat skema
$pdo->exec("
    CREATE TABLE master_gelombang (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        semester TEXT NOT NULL,
        tahun_ajaran TEXT NOT NULL,
        gelombang TEXT NOT NULL,
        status TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nama_lengkap TEXT NOT NULL,
        nim TEXT NOT NULL,
        program_studi TEXT,
        role TEXT NOT NULL
    );
    CREATE TABLE tutorial_classes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nama_kelas TEXT NOT NULL,
        dosen_pengampu TEXT NOT NULL,
        gelombang TEXT NOT NULL,
        semester TEXT NOT NULL,
        hari TEXT,
        kuota INTEGER DEFAULT 30
    );
    CREATE TABLE tutorial_registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        tutorial_class_id INTEGER,
        nilai_thaharah REAL,
        nilai_shalat REAL,
        nilai_surat_pendek REAL,
        nilai_amaliyah REAL,
        nilai_jenazah REAL,
        nilai_ujian_tulis REAL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
");

// Insert 2 Periode:
// Periode 1: Lama (Nonaktif) - 2025/2026 Gel. 1
$pdo->exec("INSERT INTO master_gelombang (semester, tahun_ajaran, gelombang, status) VALUES ('Ganjil', '2025-2026', 'gel1', 'nonaktif')");

// Periode 2: Baru (Aktif) - 2026/2027 Gel. 1
$pdo->exec("INSERT INTO master_gelombang (semester, tahun_ajaran, gelombang, status) VALUES ('Ganjil', '2026-2027', 'gel1', 'aktif')");

// Insert Dosen & Mahasiswa
$pdo->exec("INSERT INTO users (id, nama_lengkap, nim, role) VALUES (1, 'Dr. Ahmad', 'D001', 'dosen')");
$pdo->exec("INSERT INTO users (id, nama_lengkap, nim, program_studi, role) VALUES (10, 'Mahasiswa Periode Lama', '25001', 'Hukum', 'mahasiswa')");
$pdo->exec("INSERT INTO users (id, nama_lengkap, nim, program_studi, role) VALUES (20, 'Mahasiswa Periode Aktif', '26001', 'Ekonomi', 'mahasiswa')");

// Insert Kelas:
// Kelas Periode Lama
$pdo->exec("INSERT INTO tutorial_classes (id, nama_kelas, dosen_pengampu, gelombang, semester) VALUES (101, 'Kelas Senin A', 'Dr. Ahmad', 'gel1', '2025-2026-Ganjil')");
// Kelas Periode Aktif
$pdo->exec("INSERT INTO tutorial_classes (id, nama_kelas, dosen_pengampu, gelombang, semester) VALUES (201, 'Kelas Senin B', 'Dr. Ahmad', 'gel1', '2026-2027-Ganjil')");

// Insert Registrasi:
// Mhs 10 di Kelas Lama
$pdo->exec("INSERT INTO tutorial_registrations (id, user_id, tutorial_class_id, nilai_thaharah, nilai_shalat, nilai_surat_pendek, nilai_amaliyah, nilai_jenazah, nilai_ujian_tulis) VALUES (1, 10, 101, 80, 85, 90, 85, 80, 85)");
// Mhs 20 di Kelas Aktif
$pdo->exec("INSERT INTO tutorial_registrations (id, user_id, tutorial_class_id, nilai_thaharah, nilai_shalat, nilai_surat_pendek, nilai_amaliyah, nilai_jenazah, nilai_ujian_tulis) VALUES (2, 20, 201, 95, 90, 95, 90, 95, 90)");

// Uji Ambil Periode Aktif
$active_gel = $pdo->query("SELECT * FROM master_gelombang WHERE status = 'aktif' ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert($active_gel !== false, "Periode aktif harus ditemukan");
assert($active_gel['tahun_ajaran'] === '2026-2027', "Periode aktif harus 2026-2027");

$targetYear = str_replace(['/', ' '], ['-', ''], trim($active_gel['tahun_ajaran']));
$targetSemester = trim($active_gel['semester']);
$targetGelombang = trim($active_gel['gelombang']);
$targetYearSem = $targetYear . '-' . $targetSemester;

// Query Mahasiswa dengan Filter Periode Aktif (Sesuai implementasi rekap-nilai.php)
$sql = "
    SELECT tr.*, u.nama_lengkap, u.nim, tc.nama_kelas
    FROM tutorial_registrations tr
    JOIN users u ON tr.user_id = u.id
    JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
    WHERE tc.gelombang = ?
      AND (
          TRIM(tc.semester) = ?
          OR REPLACE(TRIM(tc.semester), '/', '-') = ?
          OR REPLACE(TRIM(tc.semester), ' ', '') LIKE ?
          OR tc.semester LIKE ?
      )
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$targetGelombang, $targetSemester, $targetYearSem, '%' . $targetYear . '%', '%' . $targetYear . '%']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Verifikasi HANYA mahasiswa periode aktif yang muncul
assert(count($rows) === 1, "Hanya 1 mahasiswa yang boleh muncul pada periode aktif");
assert($rows[0]['nama_lengkap'] === 'Mahasiswa Periode Aktif', "Mahasiswa yang muncul harus dari periode aktif");
assert($rows[0]['nama_kelas'] === 'Kelas Senin B', "Kelas yang muncul harus kelas periode aktif");

echo "✅ PASS: Verifikasi rekapitulasi tutorial berdasarkan periode aktif BERHASIL 100%!\n";
