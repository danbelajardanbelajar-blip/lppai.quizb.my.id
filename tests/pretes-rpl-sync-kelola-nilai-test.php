<?php
/**
 * Test Suite: Pemetaan Kategori CBT & Sinkronisasi Kelola Nilai Pretes RPL (Muslim & Non-Muslim)
 * Menguji:
 *  1. Pemetaan kategori CBT mahasiswa RPL Muslim ke 6 komponen sertifikat & 12 komponen asesmen.
 *  2. Pemetaan kategori CBT mahasiswa RPL Non-Muslim (Kristen Protestan / Paket 2) ke 6 komponen sertifikat.
 *  3. Eksekusi syncPretesToKelolaNilai() untuk mahasiswa RPL lulus (terisi per kolom di tutorial_registrations).
 *  4. Verifikasi bahwa mahasiswa RPL dengan nilai CBT < 80 dilewati (tidak masuk sertifikat).
 *  5. Verifikasi tampilan adaptif sertifikat.php dan admin/cetak-sertifikat.php (Muslim vs Kristen).
 */

require_once __DIR__ . '/../includes/pretes-helper.php';
require_once __DIR__ . '/../includes/pretes-cbt.php';

$errors = [];
function testCheck(bool $condition, string $msg, array &$errors): void {
    if (!$condition) {
        $errors[] = "FAIL: $msg";
        echo " [FAIL] $msg\n";
    } else {
        echo " [PASS] $msg\n";
    }
}

echo "=== Test Suite: Pemetaan Kategori & Sinkronisasi Kelola Nilai Pretes RPL ===\n";

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Buat skema tabel pengujian
$pdo->exec("
    CREATE TABLE master_pretes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        periode VARCHAR(100) NOT NULL,
        tahun_ajaran VARCHAR(50) NOT NULL,
        target_program VARCHAR(50) DEFAULT 'RPL',
        status VARCHAR(20) DEFAULT 'aktif'
    );
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nama_lengkap VARCHAR(255) NOT NULL,
        nim VARCHAR(50) NOT NULL,
        jenis_program VARCHAR(50) DEFAULT 'RPL',
        program_studi VARCHAR(100) DEFAULT 'Teknik Informatika',
        tempat_lahir VARCHAR(100) DEFAULT 'Lamongan',
        tanggal_lahir VARCHAR(50) DEFAULT '2000-01-01',
        role VARCHAR(50) DEFAULT 'mahasiswa'
    );
    CREATE TABLE pretes_registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        periode VARCHAR(100) NOT NULL,
        hari_pilihan VARCHAR(50),
        status VARCHAR(50) DEFAULT 'terdaftar'
    );
    CREATE TABLE pretes_assessments (
        registration_id INTEGER PRIMARY KEY,
        scores_json TEXT NOT NULL,
        assessed_by INTEGER NOT NULL,
        updated_at TEXT
    );
    CREATE TABLE pretes_cbt_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code VARCHAR(100),
        title VARCHAR(255)
    );
    CREATE TABLE pretes_cbt_packages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code VARCHAR(100),
        name VARCHAR(255)
    );
    CREATE TABLE pretes_cbt_questions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        package_id INTEGER,
        code VARCHAR(100),
        category VARCHAR(100),
        question_text TEXT
    );
    CREATE TABLE pretes_cbt_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        registration_id INTEGER NOT NULL,
        session_id INTEGER,
        started_at INTEGER,
        deadline_at INTEGER,
        submitted_at INTEGER,
        correct_count INTEGER,
        score REAL
    );
    CREATE TABLE pretes_cbt_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        attempt_id INTEGER NOT NULL,
        question_id INTEGER NOT NULL,
        position INTEGER,
        answer VARCHAR(10),
        correct_answer VARCHAR(10)
    );
    CREATE TABLE tutorial_registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        status VARCHAR(50) DEFAULT 'terdaftar',
        gelombang VARCHAR(50),
        tahun_ajaran VARCHAR(50),
        tipe_nilai VARCHAR(50),
        nomor_sertifikat VARCHAR(100),
        nilai_thaharah REAL,
        nilai_shalat REAL,
        nilai_surat_pendek REAL,
        nilai_amaliyah REAL,
        nilai_jenazah REAL,
        nilai_ujian_tulis REAL,
        nilai_akhir REAL
    );
    CREATE TABLE pretes_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        nilai REAL,
        status_lulus VARCHAR(30) DEFAULT 'belum_diumumkan',
        keterangan TEXT
    );
");

$pdo->exec("INSERT INTO master_pretes (periode, tahun_ajaran, target_program, status) VALUES ('2026/2027 RPL', '2026/2027', 'RPL', 'aktif')");

// Sesi dan Paket
$pdo->exec("INSERT INTO pretes_cbt_sessions (id, code, title) VALUES (1, 'SESI-RPL-2026', 'Sesi CBT Pretes RPL Utama')");
$pdo->exec("INSERT INTO pretes_cbt_sessions (id, code, title) VALUES (2, 'SESI-RESMI-PROTESTAN-2026', 'Sesi CBT Pretes Kristen Protestan')");

$pdo->exec("INSERT INTO pretes_cbt_packages (id, code, name) VALUES (1, 'PKT-UTAMA', 'Paket Pretes Utama Islam')");
$pdo->exec("INSERT INTO pretes_cbt_packages (id, code, name) VALUES (2, 'PKT-2-KRISTEN-PROTESTAN', 'Paket Pretes Kristen Protestan')");

// Soal-soal Muslim (Paket 1)
$pdo->exec("
    INSERT INTO pretes_cbt_questions (id, package_id, code, category, question_text) VALUES 
    (1, 1, 'PKT1-TH-01', 'Thaharah', 'Pertanyaan wudhu 1'),
    (2, 1, 'PKT1-TH-02', 'Thaharah', 'Pertanyaan wudhu 2'),
    (3, 1, 'PKT1-SH-01', 'Shalat', 'Pertanyaan shalat 1'),
    (4, 1, 'PKT1-SH-02', 'Shalat', 'Pertanyaan shalat 2'),
    (5, 1, 'PKT1-SP-01', 'Al-Quran', 'Pertanyaan surat pendek 1'),
    (6, 1, 'PKT1-SP-02', 'Al-Quran', 'Pertanyaan surat pendek 2'),
    (7, 1, 'PKT1-AM-01', 'Amaliyah', 'Pertanyaan istighasah 1'),
    (8, 1, 'PKT1-AM-02', 'Amaliyah', 'Pertanyaan istighasah 2'),
    (9, 1, 'PKT1-JN-01', 'Jenazah', 'Pertanyaan shalat jenazah 1'),
    (10, 1, 'PKT1-JN-02', 'Jenazah', 'Pertanyaan shalat jenazah 2')
");

// Soal-soal Kristen Protestan (Paket 2)
$pdo->exec("
    INSERT INTO pretes_cbt_questions (id, package_id, code, category, question_text) VALUES 
    (11, 2, 'PKT2-KP-010', 'Ibadah & Sakramen', 'Pertanyaan sakramen baptisan'),
    (12, 2, 'PKT2-KP-011', 'Ibadah & Sakramen', 'Pertanyaan perjamuan kudus'),
    (13, 2, 'PKT2-KP-007', 'Dasar Iman', 'Pertanyaan dasar iman Yesus Kristus'),
    (14, 2, 'PKT2-KP-008', 'Dasar Iman', 'Pertanyaan keselamatan dan anugerah'),
    (15, 2, 'PKT2-KP-001', 'Kitab Suci', 'Pertanyaan Alkitab PL dan PB'),
    (16, 2, 'PKT2-KP-002', 'Kitab Suci', 'Pertanyaan pedoman Alkitab'),
    (17, 2, 'PKT2-KP-019', 'Hari Raya & Nilai Kasih', 'Pertanyaan hari natal & kasih sesama'),
    (18, 2, 'PKT2-KP-024', 'Hari Raya & Nilai Kasih', 'Pertanyaan etika kasih kristen'),
    (19, 2, 'PKT2-KP-004', 'Sejarah & Tokoh', 'Pertanyaan Martin Luther reformasi'),
    (20, 2, 'PKT2-KP-025', 'Gereja & Denominasi', 'Pertanyaan sejarah gereja protestan')
");

// ── 1. Data User 1: Mahasiswa RPL Muslim Lulus CBT (Nilai 90) ───────────────────
$pdo->exec("INSERT INTO users (id, nama_lengkap, nim, jenis_program) VALUES (1, 'Budi Santoso RPL', '26011001', 'RPL')");
$pdo->exec("INSERT INTO pretes_registrations (id, user_id, periode, hari_pilihan) VALUES (101, 1, '2026/2027 RPL', 'Senin')");
$pdo->exec("INSERT INTO pretes_cbt_attempts (id, user_id, registration_id, session_id, started_at, deadline_at, submitted_at, correct_count, score) 
            VALUES (1001, 1, 101, 1, 1700000000, 1700001800, 1700001500, 9, 90.0)");

// Jawaban Budi: 9 benar dari 10 soal
for ($pos = 1; $pos <= 10; $pos++) {
    $isCorrect = ($pos !== 10); // Soal ke-10 (Jenazah 2) salah
    $ans = $isCorrect ? 'A' : 'B';
    $pdo->exec("INSERT INTO pretes_cbt_items (attempt_id, question_id, position, answer, correct_answer) VALUES (1001, $pos, $pos, '$ans', 'A')");
}

$mappedMuslim = mapRplCbtScoresToCertificate($pdo, 101, 1);
testCheck($mappedMuslim['is_kristen'] === false, 'Deteksi mahasiswa Muslim: is_kristen = false', $errors);
testCheck($mappedMuslim['cbt_score'] === 90.0, 'Skor CBT Muslim: 90.0', $errors);
testCheck($mappedMuslim['thaharah'] === 100.0, 'Nilai Thaharah Muslim (2/2 benar) = 100.0', $errors);
testCheck($mappedMuslim['shalat'] === 100.0, 'Nilai Shalat Muslim (2/2 benar) = 100.0', $errors);
testCheck($mappedMuslim['surat_pendek'] === 100.0, 'Nilai Surat Pendek Muslim (2/2 benar) = 100.0', $errors);
testCheck($mappedMuslim['amaliyah'] === 100.0, 'Nilai Amaliyah Muslim (2/2 benar) = 100.0', $errors);
// Jenazah: 1 benar dari 2 soal = 50%, karena CBT lulus (90), diangkat ke min 80.0
testCheck($mappedMuslim['jenazah'] >= 80.0, 'Nilai Jenazah Muslim minimal 80.0 karena CBT lulus: ' . $mappedMuslim['jenazah'], $errors);

$rpl12 = generateRpl12ScoresJson($mappedMuslim);
testCheck(count($rpl12) === 12, '12 komponen asesmen berhasil di-generate', $errors);
testCheck($rpl12['ujian_tulis'] === 90.0, 'ujian_tulis pada 12 komponen = 90.0', $errors);
testCheck($rpl12['praktik_wudlu'] === 100.0, 'praktik_wudlu pada 12 komponen = 100.0', $errors);

// ── 2. Data User 2: Mahasiswa RPL Kristen Protestan Lulus CBT (Nilai 85) ─────────
$pdo->exec("INSERT INTO users (id, nama_lengkap, nim, jenis_program) VALUES (2, 'Yohanes Christian RPL', '26011002', 'RPL')");
$pdo->exec("INSERT INTO pretes_registrations (id, user_id, periode, hari_pilihan) VALUES (102, 2, '2026/2027 RPL', 'Selasa')");
$pdo->exec("INSERT INTO pretes_cbt_attempts (id, user_id, registration_id, session_id, started_at, deadline_at, submitted_at, correct_count, score) 
            VALUES (1002, 2, 102, 2, 1700000000, 1700001800, 1700001600, 9, 85.0)");

// Jawaban Yohanes: soal 11 s.d. 20 (Paket 2 Kristen)
for ($idx = 0; $idx < 10; $idx++) {
    $qId = 11 + $idx;
    $pos = $idx + 1;
    $ans = ($idx === 9) ? 'C' : 'A'; // 1 soal denominasi salah
    $pdo->exec("INSERT INTO pretes_cbt_items (attempt_id, question_id, position, answer, correct_answer) VALUES (1002, $qId, $pos, '$ans', 'A')");
}

$mappedKristen = mapRplCbtScoresToCertificate($pdo, 102, 2);
testCheck($mappedKristen['is_kristen'] === true, 'Deteksi mahasiswa Kristen Protestan: is_kristen = true', $errors);
testCheck($mappedKristen['cbt_score'] === 85.0, 'Skor CBT Kristen: 85.0', $errors);
testCheck($mappedKristen['thaharah'] === 100.0, 'Kolom 1 (Ibadah & Sakramen -> thaharah) = 100.0', $errors);
testCheck($mappedKristen['shalat'] === 100.0, 'Kolom 2 (Dasar Iman -> shalat) = 100.0', $errors);
testCheck($mappedKristen['surat_pendek'] === 100.0, 'Kolom 3 (Kitab Suci -> surat_pendek) = 100.0', $errors);
testCheck($mappedKristen['amaliyah'] === 100.0, 'Kolom 4 (Hari Raya & Nilai Kasih -> amaliyah) = 100.0', $errors);
testCheck($mappedKristen['jenazah'] >= 80.0, 'Kolom 5 (Gereja & Sejarah -> jenazah) minimal 80.0: ' . $mappedKristen['jenazah'], $errors);

// ── 3. Data User 3: Mahasiswa RPL Tidak Lulus CBT (Nilai 60) ────────────────────
$pdo->exec("INSERT INTO users (id, nama_lengkap, nim, jenis_program) VALUES (3, 'Hendra Gagal RPL', '26011003', 'RPL')");
$pdo->exec("INSERT INTO pretes_registrations (id, user_id, periode, hari_pilihan) VALUES (103, 3, '2026/2027 RPL', 'Rabu')");
$pdo->exec("INSERT INTO pretes_cbt_attempts (id, user_id, registration_id, session_id, started_at, deadline_at, submitted_at, correct_count, score) 
            VALUES (1003, 3, 103, 1, 1700000000, 1700001800, 1700001700, 6, 60.0)");

$mappedGagal = mapRplCbtScoresToCertificate($pdo, 103, 3);
testCheck($mappedGagal['cbt_score'] === 60.0, 'Skor CBT Gagal: 60.0', $errors);

// ── 4. Eksekusi syncPretesToKelolaNilai() ─────────────────────────────────────────
$syncRes = syncPretesToKelolaNilai($pdo, '2026/2027 RPL');
testCheck($syncRes['success'] === true, 'Eksekusi syncPretesToKelolaNilai sukses', $errors);
testCheck($syncRes['synced'] === 2, 'Jumlah synced = 2 (User 1 & User 2 lulus), dapat: ' . $syncRes['synced'], $errors);
testCheck($syncRes['skipped'] === 1, 'Jumlah skipped = 1 (User 3 tidak lulus), dapat: ' . $syncRes['skipped'], $errors);

// Verifikasi isi tutorial_registrations untuk User 1 (Muslim)
$tut1 = $pdo->query("SELECT * FROM tutorial_registrations WHERE user_id = 1")->fetch();
testCheck(!empty($tut1), 'User 1 tercatat di tutorial_registrations', $errors);
testCheck($tut1['status'] === 'lulus', 'User 1 status lulus', $errors);
testCheck($tut1['tipe_nilai'] === 'pretest', 'User 1 tipe_nilai pretest', $errors);
testCheck((float)$tut1['nilai_thaharah'] === 100.0, 'User 1 nilai_thaharah terisi: 100.0', $errors);
testCheck((float)$tut1['nilai_shalat'] === 100.0, 'User 1 nilai_shalat terisi: 100.0', $errors);
testCheck((float)$tut1['nilai_surat_pendek'] === 100.0, 'User 1 nilai_surat_pendek terisi: 100.0', $errors);
testCheck((float)$tut1['nilai_amaliyah'] === 100.0, 'User 1 nilai_amaliyah terisi: 100.0', $errors);
testCheck((float)$tut1['nilai_jenazah'] >= 80.0, 'User 1 nilai_jenazah terisi: ' . $tut1['nilai_jenazah'], $errors);
testCheck((float)$tut1['nilai_ujian_tulis'] === 90.0, 'User 1 nilai_ujian_tulis terisi: 90.0', $errors);
testCheck((float)$tut1['nilai_akhir'] >= 80.0, 'User 1 nilai_akhir terisi: ' . $tut1['nilai_akhir'], $errors);

// Verifikasi isi tutorial_registrations untuk User 2 (Kristen Protestan)
$tut2 = $pdo->query("SELECT * FROM tutorial_registrations WHERE user_id = 2")->fetch();
testCheck(!empty($tut2), 'User 2 tercatat di tutorial_registrations', $errors);
testCheck($tut2['status'] === 'lulus', 'User 2 status lulus', $errors);
testCheck((float)$tut2['nilai_thaharah'] === 100.0, 'User 2 Ibadah & Sakramen (nilai_thaharah): 100.0', $errors);
testCheck((float)$tut2['nilai_shalat'] === 100.0, 'User 2 Dasar Iman (nilai_shalat): 100.0', $errors);
testCheck((float)$tut2['nilai_surat_pendek'] === 100.0, 'User 2 Kitab Suci (nilai_surat_pendek): 100.0', $errors);
testCheck((float)$tut2['nilai_amaliyah'] === 100.0, 'User 2 Nilai Kasih (nilai_amaliyah): 100.0', $errors);
testCheck((float)$tut2['nilai_jenazah'] >= 80.0, 'User 2 Gereja & Sejarah (nilai_jenazah): ' . $tut2['nilai_jenazah'], $errors);
testCheck((float)$tut2['nilai_ujian_tulis'] === 85.0, 'User 2 nilai_ujian_tulis: 85.0', $errors);

// Verifikasi User 3 tidak masuk tutorial_registrations
$tut3 = $pdo->query("SELECT * FROM tutorial_registrations WHERE user_id = 3")->fetch();
testCheck(empty($tut3), 'User 3 tidak masuk ke tutorial_registrations karena belum lulus', $errors);

// Verifikasi pretes_assessments terisi 12 komponen lengkap
$pa1 = $pdo->query("SELECT scores_json FROM pretes_assessments WHERE registration_id = 101")->fetchColumn();
$pa1Decoded = json_decode((string)$pa1, true);
testCheck(count($pa1Decoded) === 12, 'pretes_assessments registration 101 memiliki 12 komponen', $errors);
testCheck((float)$pa1Decoded['ujian_tulis'] === 90.0, 'pretes_assessments registration 101 ujian_tulis 90.0', $errors);

// ── 5. Verifikasi Tampilan Adaptif Sertifikat (sertifikat.php & admin/cetak-sertifikat.php) ─
$root = dirname(__DIR__);
$sertifikatCode = file_get_contents($root . '/sertifikat.php');
testCheck(str_contains($sertifikatCode, 'isPretesUserKristen'), 'sertifikat.php memanggil isPretesUserKristen', $errors);
testCheck(str_contains($sertifikatCode, 'Pendidikan Agama Kristen Protestan'), 'sertifikat.php mendukung program Kristen Protestan', $errors);
testCheck(str_contains($sertifikatCode, 'Ibadah &amp; Sakramen'), 'sertifikat.php memuat header adaptif Ibadah & Sakramen', $errors);
testCheck(str_contains($sertifikatCode, 'Daurah Keislaman'), 'sertifikat.php memuat program Daurah Keislaman', $errors);
testCheck(str_contains($sertifikatCode, 'Thaharah'), 'sertifikat.php memuat header Thaharah', $errors);

$cetakCode = file_get_contents($root . '/admin/cetak-sertifikat.php');
testCheck(str_contains($cetakCode, 'isPretesUserKristen'), 'admin/cetak-sertifikat.php memanggil isPretesUserKristen', $errors);
testCheck(str_contains($cetakCode, 'Pendidikan Agama Kristen Protestan'), 'admin/cetak-sertifikat.php mendukung program Kristen Protestan', $errors);
testCheck(str_contains($cetakCode, 'Ibadah &amp; Sakramen'), 'admin/cetak-sertifikat.php memuat header adaptif Ibadah & Sakramen', $errors);

if (empty($errors)) {
    echo "\n=== SELURUH PENGUJIAN PRETES RPL SINKRONISASI KELOLA NILAI BERHASIL (100% PASS) ===\n";
    exit(0);
} else {
    echo "\n=== TERDAPAT " . count($errors) . " KEGAGALAN PENGUJIAN: ===\n";
    foreach ($errors as $e) echo "$e\n";
    exit(1);
}
