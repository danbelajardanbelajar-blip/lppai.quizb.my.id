<?php
/**
 * Test Suite: Export Rekapitulasi Nilai Pretes CBT per Kategori Soal
 * Verifikasi ekspor breakdown nilai grup soal untuk mahasiswa RPL & Reguler
 */

require_once __DIR__ . '/../includes/user-helper.php';
require_once __DIR__ . '/../includes/pretes-helper.php';
require_once __DIR__ . '/../includes/pretes-cbt.php';

function testAssert(bool $cond, string $msg): void {
    if (!$cond) throw new RuntimeException("FAIL: $msg");
    echo " [PASS] $msg\n";
}

echo "=== Test Suite: Export Rekap Nilai Pretes CBT per Kategori Soal ===\n";

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// 1. Skema Tabel
$pdo->exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT,
    nama_lengkap TEXT NOT NULL,
    nim TEXT,
    program_studi TEXT,
    jenis_program TEXT,
    fakultas TEXT,
    no_hp TEXT
)");

$pdo->exec("CREATE TABLE pretes_registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    periode TEXT NOT NULL,
    hari_pilihan TEXT,
    jam TEXT,
    ruangan TEXT,
    dosen_penguji TEXT,
    dosen_penguji_nim TEXT,
    status TEXT,
    tanggal_daftar TEXT
)");

$pdo->exec("CREATE TABLE pretes_assessments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    registration_id INTEGER NOT NULL,
    scores_json TEXT,
    updated_at TEXT
)");

$pdo->exec("CREATE TABLE pretes_attendance_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    registration_id INTEGER NOT NULL,
    attended_at TEXT
)");

ensurePretesCbtTables($pdo);
$pdo->exec("DELETE FROM pretes_cbt_items");
$pdo->exec("DELETE FROM pretes_cbt_attempts");
$pdo->exec("DELETE FROM pretes_cbt_questions");

// 2. Insert Data Uji: Mahasiswa RPL & Reguler
$pdo->exec("INSERT INTO users (id, username, nama_lengkap, nim, program_studi, jenis_program, fakultas) VALUES
    (1, '2601101001', 'Ahmad Mahasiswa RPL', '2601101001', 'Pendidikan Agama Islam', 'RPL', 'FAI'),
    (2, '2601101002', 'Budi Mahasiswa RPL Remedial', '2601101002', 'Hukum Keluarga Islam', 'RPL', 'FAI'),
    (3, '2601101003', 'Citra Mahasiswa Belum CBT', '2601101003', 'Ekonomi Syariah', 'Reguler', 'FEI')
");

$pdo->exec("INSERT INTO pretes_registrations (id, user_id, periode, hari_pilihan, ruangan, dosen_penguji, status) VALUES
    (101, 1, 'Pretes Gelombang 1 2026/2027', 'Senin', 'Lab CBT 1', 'Dr. Zainul Hakim, M.H.I.', 'hadir'),
    (102, 2, 'Pretes Gelombang 1 2026/2027', 'Senin', 'Lab CBT 1', 'Dr. Zainul Hakim, M.H.I.', 'hadir'),
    (103, 3, 'Pretes Gelombang 1 2026/2027', 'Selasa', 'Lab CBT 2', 'H. Ahmad Fikri, M.Pd.', 'hadir')
");

// 3. Insert Bank Soal dengan 3 Kategori: Thaharah, Shalat, Ubudiyah
$pdo->exec("INSERT INTO pretes_cbt_questions (id, code, package_id, category, question_text, options_json, correct_answer, is_active) VALUES
    (1, 'TH01', 1, 'Thaharah', 'Soal Thaharah 1', '{\"A\":\"A\",\"B\":\"B\",\"C\":\"C\",\"D\":\"D\"}', 'A', 1),
    (2, 'TH02', 1, 'Thaharah', 'Soal Thaharah 2', '{\"A\":\"A\",\"B\":\"B\",\"C\":\"C\",\"D\":\"D\"}', 'B', 1),
    (3, 'SH01', 1, 'Shalat', 'Soal Shalat 1', '{\"A\":\"A\",\"B\":\"B\",\"C\":\"C\",\"D\":\"D\"}', 'C', 1),
    (4, 'SH02', 1, 'Shalat', 'Soal Shalat 2', '{\"A\":\"A\",\"B\":\"B\",\"C\":\"C\",\"D\":\"D\"}', 'D', 1),
    (5, 'UB01', 1, 'Ubudiyah', 'Soal Ubudiyah 1', '{\"A\":\"A\",\"B\":\"B\",\"C\":\"C\",\"D\":\"D\"}', 'A', 1)
");

// 4. Attempt CBT Mahasiswa 1 (LULUS: Thaharah 2/2 = 100%, Shalat 2/2 = 100%, Ubudiyah 0/1 = 0% -> Skor 80.0)
$now = time();
$pdo->exec("INSERT INTO pretes_cbt_attempts (id, user_id, registration_id, session_id, started_at, deadline_at, submitted_at, correct_count, score) VALUES
    (1, 1, 101, 1, $now - 1800, $now + 1800, $now - 600, 4, 80.00)
");
$pdo->exec("INSERT INTO pretes_cbt_items (attempt_id, position, question_id, question_text, options_json, correct_answer, answer) VALUES
    (1, 1, 1, 'Soal Thaharah 1', '{}', 'A', 'A'),
    (1, 2, 2, 'Soal Thaharah 2', '{}', 'B', 'B'),
    (1, 3, 3, 'Soal Shalat 1', '{}', 'C', 'C'),
    (1, 4, 4, 'Soal Shalat 2', '{}', 'D', 'D'),
    (1, 5, 5, 'Soal Ubudiyah 1', '{}', 'A', 'B')
");

// 5. Attempt CBT Mahasiswa 2 (TIDAK LULUS: Thaharah 0/2 = 0%, Shalat 1/2 = 50%, Ubudiyah 1/1 = 100% -> Skor 40.0)
$pdo->exec("INSERT INTO pretes_cbt_attempts (id, user_id, registration_id, session_id, started_at, deadline_at, submitted_at, correct_count, score) VALUES
    (2, 2, 102, 1, $now - 1500, $now + 1500, $now - 300, 2, 40.00)
");
$pdo->exec("INSERT INTO pretes_cbt_items (attempt_id, position, question_id, question_text, options_json, correct_answer, answer) VALUES
    (2, 1, 1, 'Soal Thaharah 1', '{}', 'A', 'C'),
    (2, 2, 2, 'Soal Thaharah 2', '{}', 'B', 'D'),
    (2, 3, 3, 'Soal Shalat 1', '{}', 'C', 'C'),
    (2, 4, 4, 'Soal Shalat 2', '{}', 'D', 'B'),
    (2, 5, 5, 'Soal Ubudiyah 1', '{}', 'A', 'A')
");

echo "[INFO] Database fixture siap.\n";

// 6. Test Data Aggregation Function
$roster = [
    [
        'reg_id' => 101,
        'user_id' => 1,
        'nim' => '2601101001',
        'nama_lengkap' => 'Ahmad Mahasiswa RPL',
        'program_studi' => 'Pendidikan Agama Islam',
        'jenis_program' => 'RPL',
        'fakultas' => 'FAI',
    ],
    [
        'reg_id' => 102,
        'user_id' => 2,
        'nim' => '2601101002',
        'nama_lengkap' => 'Budi Mahasiswa RPL Remedial',
        'program_studi' => 'Hukum Keluarga Islam',
        'jenis_program' => 'RPL',
        'fakultas' => 'FAI',
    ],
    [
        'reg_id' => 103,
        'user_id' => 3,
        'nim' => '2601101003',
        'nama_lengkap' => 'Citra Mahasiswa Belum CBT',
        'program_studi' => 'Ekonomi Syariah',
        'jenis_program' => 'Reguler',
        'fakultas' => 'FEI',
    ],
];

$exportData = pretesGetCbtCategoryExportData($pdo, $roster);
$categories = $exportData['categories'];
$rows = $exportData['rows'];

testAssert(count($categories) === 3, 'Kategori soal unik berjumlah 3 (Shalat, Thaharah, Ubudiyah).');
testAssert(in_array('Thaharah', $categories, true), 'Kategori Thaharah ditemukan.');
testAssert(in_array('Shalat', $categories, true), 'Kategori Shalat ditemukan.');
testAssert(in_array('Ubudiyah', $categories, true), 'Kategori Ubudiyah ditemukan.');

testAssert(count($rows) === 3, 'Terdapat 3 baris data peserta.');

// Cek Peserta 1 (Ahmad Mahasiswa RPL)
$r1 = $rows[0];
testAssert($r1['nim'] === '2601101001', 'Peserta 1 NIM sesuai.');
testAssert($r1['jenis_program'] === 'RPL', 'Peserta 1 jenis program RPL.');
testAssert($r1['categories']['Thaharah']['score'] === 100.0, 'Peserta 1 Thaharah nilai 100%.');
testAssert($r1['categories']['Thaharah']['ratio'] === '2/2', 'Peserta 1 Thaharah ratio 2/2.');
testAssert($r1['categories']['Shalat']['score'] === 100.0, 'Peserta 1 Shalat nilai 100%.');
testAssert($r1['categories']['Shalat']['ratio'] === '2/2', 'Peserta 1 Shalat ratio 2/2.');
testAssert($r1['categories']['Ubudiyah']['score'] === 0.0, 'Peserta 1 Ubudiyah nilai 0%.');
testAssert($r1['categories']['Ubudiyah']['ratio'] === '0/1', 'Peserta 1 Ubudiyah ratio 0/1.');
testAssert($r1['total_soal'] === 5, 'Peserta 1 total soal 5.');
testAssert($r1['total_benar'] === 4, 'Peserta 1 total benar 4.');
testAssert($r1['final_score'] === 80.0, 'Peserta 1 nilai akhir CBT 80.0.');
testAssert($r1['status_lulus'] === 'LULUS', 'Peserta 1 status kelulusan LULUS.');

// Cek Peserta 2 (Budi Mahasiswa RPL Remedial)
$r2 = $rows[1];
testAssert($r2['nim'] === '2601101002', 'Peserta 2 NIM sesuai.');
testAssert($r2['categories']['Thaharah']['score'] === 0.0, 'Peserta 2 Thaharah nilai 0%.');
testAssert($r2['categories']['Thaharah']['ratio'] === '0/2', 'Peserta 2 Thaharah ratio 0/2.');
testAssert($r2['categories']['Shalat']['score'] === 50.0, 'Peserta 2 Shalat nilai 50%.');
testAssert($r2['categories']['Shalat']['ratio'] === '1/2', 'Peserta 2 Shalat ratio 1/2.');
testAssert($r2['categories']['Ubudiyah']['score'] === 100.0, 'Peserta 2 Ubudiyah nilai 100%.');
testAssert($r2['categories']['Ubudiyah']['ratio'] === '1/1', 'Peserta 2 Ubudiyah ratio 1/1.');
testAssert($r2['total_soal'] === 5, 'Peserta 2 total soal 5.');
testAssert($r2['total_benar'] === 2, 'Peserta 2 total benar 2.');
testAssert($r2['final_score'] === 40.0, 'Peserta 2 nilai akhir CBT 40.0.');
testAssert($r2['status_lulus'] === 'TIDAK LULUS', 'Peserta 2 status kelulusan TIDAK LULUS.');

// Cek Peserta 3 (Citra Belum CBT)
$r3 = $rows[2];
testAssert($r3['nim'] === '2601101003', 'Peserta 3 NIM sesuai.');
testAssert($r3['categories']['Thaharah']['score'] === null, 'Peserta 3 Thaharah score null.');
testAssert($r3['categories']['Thaharah']['ratio'] === '-', 'Peserta 3 Thaharah ratio "-".');
testAssert($r3['status_lulus'] === 'BELUM CBT', 'Peserta 3 status kelulusan BELUM CBT.');

// 7. Test Export CSV
$headers = ['No', 'NIM', 'Nama Mahasiswa', 'Program Studi', 'Jenis Program', 'Fakultas', 'Sesi Ujian CBT', 'Waktu Selesai (WIB)'];
foreach ($categories as $cat) {
    $headers[] = $cat . ' (%)';
    $headers[] = $cat . ' (Benar/Total)';
}
$headers[] = 'Total Soal';
$headers[] = 'Jawaban Benar';
$headers[] = 'Nilai Akhir CBT';
$headers[] = 'Status Kelulusan';

$csvContent = pretesExportCbtCategoriesToCsv('test_cbt_export', $headers, $categories, $rows, true);
testAssert(strpos($csvContent, 'Ahmad Mahasiswa RPL') !== false, 'CSV memuat data Ahmad Mahasiswa RPL.');
testAssert(strpos($csvContent, 'Thaharah (%)') !== false, 'CSV memuat header Thaharah (%).');
testAssert(strpos($csvContent, 'TIDAK LULUS') !== false, 'CSV memuat status TIDAK LULUS.');
testAssert(strpos($csvContent, 'BELUM CBT') !== false, 'CSV memuat status BELUM CBT.');

// 8. Test Export OpenXML Native XLSX
if (class_exists('ZipArchive')) {
    $xlsxBytes = pretesExportCbtCategoriesToXlsxNative('test_cbt_export', 'Pretes Gelombang 1 2026/2027', $headers, $categories, $rows, true);
    testAssert(!empty($xlsxBytes), 'File XLSX binary berhasil dibuat.');
    testAssert(substr($xlsxBytes, 0, 2) === "PK", 'Header file XLSX valid ZIP format (PK).');

    // Cek isi ZIP
    $tmpTestZip = tempnam(sys_get_temp_dir(), 'test_zip_') . '.xlsx';
    file_put_contents($tmpTestZip, $xlsxBytes);
    $za = new ZipArchive();
    $opened = $za->open($tmpTestZip);
    testAssert($opened === true, 'XLSX dapat dibuka oleh ZipArchive.');
    $sheetXml = $za->getFromName('xl/worksheets/sheet1.xml');
    testAssert(strpos($sheetXml, 'Ahmad Mahasiswa RPL') !== false, 'Sheet XML memuat Ahmad Mahasiswa RPL.');
    testAssert(strpos($sheetXml, '2601101001') !== false, 'Sheet XML memuat NIM 2601101001.');
    $za->close();
    @unlink($tmpTestZip);
    echo " [PASS] OpenXML XLSX generator valid & terverifikasi.\n";
}

echo "=== SELURUH PENGUJIAN EKSPOR CBT PER KATEGORI BERHASIL (100% PASS) ===\n";
