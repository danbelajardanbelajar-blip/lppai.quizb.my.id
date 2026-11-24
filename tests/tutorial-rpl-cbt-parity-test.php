<?php
/**
 * Automated Test: Paritas CBT Tutorial RPL Admin & Mahasiswa dengan CBT Pretes
 * Menguji skema 4 tabel, manajemen sesi 90 menit & passing grade 70, bank soal,
 * attempt interaktif dengan items, auto-save jawaban & flag, kalkulasi nilai,
 * sinkronisasi nilai akhir, reset attempt, serta kelengkapan antarmuka 4 tab dan layout 2 kolom.
 */

require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';

echo "=== MEMULAI TEST PARITAS CBT TUTORIAL RPL DENGAN CBT PRETES ===\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Mock Users Table
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        nama_lengkap TEXT NOT NULL,
        nim TEXT,
        program_studi TEXT,
        fakultas TEXT,
        no_hp TEXT,
        role TEXT NOT NULL DEFAULT 'mahasiswa',
        jenis_program TEXT NOT NULL DEFAULT 'RPL'
    );
");

ensureTutorialRplTables($pdo);
ensureTutorialRplCbtTables($pdo);

// 1. Verifikasi Skema Tabel CBT
echo "\n--- 1. Verifikasi Skema Tabel CBT Tutorial RPL ---\n";
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
assert(in_array('tutorial_rpl_cbt_sessions', $tables, true), 'Tabel tutorial_rpl_cbt_sessions harus ada');
assert(in_array('tutorial_rpl_cbt_questions', $tables, true), 'Tabel tutorial_rpl_cbt_questions harus ada');
assert(in_array('tutorial_rpl_cbt_attempts', $tables, true), 'Tabel tutorial_rpl_cbt_attempts harus ada');
assert(in_array('tutorial_rpl_cbt_items', $tables, true), 'Tabel tutorial_rpl_cbt_items harus ada');
echo "[PASS] Seluruh 4 tabel CBT (sessions, questions, attempts, items) terverifikasi.\n";

// 2. Verifikasi Sesi Ujian (Default 90 Menit & Passing Grade 70.00)
echo "\n--- 2. Pengaturan Sesi Ujian (90 Menit & Passing Grade 70) ---\n";
$session = tutorialRplCbtGetSession($pdo);
assert((int)$session['duration_minutes'] === 90, 'Durasi default harus 90 menit');
assert((float)$session['passing_grade'] === 70.00, 'Passing grade default harus 70.00');
assert($session['token'] === 'TUTOR26', 'Token default harus TUTOR26');
assert((int)$session['exam_open'] === 1, 'Default ujian harus dibuka');

// Update sesi
$updated = tutorialRplCbtUpdateSession($pdo, [
    'title' => 'Ujian Tulis Tutorial RPL 2026 Gelombang 1',
    'duration_minutes' => 90,
    'passing_grade' => 70.00,
    'token' => 'TUTOR99',
    'exam_open' => 1
]);
assert($updated === true, 'Update sesi harus berhasil');
$sessionUpdated = tutorialRplCbtGetSession($pdo);
assert($sessionUpdated['token'] === 'TUTOR99', 'Token berhasil diubah');
echo "[PASS] Konfigurasi sesi (durasi 90 menit, passing grade 70.00, token) terverifikasi.\n";

// 3. Bank Soal & Inisialisasi Soal Standar
echo "\n--- 3. Bank Soal & Inisialisasi Soal Standar LPPAI ---\n";
$questions = tutorialRplCbtGetQuestions($pdo);
assert(count($questions) >= 10, 'Bank soal default LPPAI harus memuat minimal 10 butir soal');

// Tambah soal baru
$newQId = tutorialRplCbtSaveQuestion($pdo, [
    'category' => 'Ubudiyah Lanjutan',
    'question_text' => 'Berapa rakaat shalat tarawih yang umum diamalkan di Indonesia?',
    'options' => ['8 rakaat', '20 rakaat', '8 atau 20 rakaat', '36 rakaat', '40 rakaat'],
    'correct_answer' => 'C'
]);
assert($newQId > 0, 'Tambah soal baru harus berhasil');
$qData = tutorialRplCbtGetQuestion($pdo, $newQId);
assert($qData !== null, 'Soal baru dapat diambil kembali');
assert($qData['correct_answer'] === 'C', 'Kunci jawaban benar adalah C');

// Edit soal
$edited = tutorialRplCbtSaveQuestion($pdo, [
    'id' => $newQId,
    'category' => 'Ubudiyah Lanjutan',
    'question_text' => 'Berapa rakaat shalat tarawih yang dianjurkan sesuai hadits?',
    'options' => ['8 rakaat', '20 rakaat', '8 atau 20 rakaat (keduanya memiliki dasar sunnah)', '36 rakaat', '40 rakaat'],
    'correct_answer' => 'C'
]);
assert($edited === $newQId, 'Edit soal harus mengembalikan ID yang sama');

// Hapus soal
$deleted = tutorialRplCbtDeleteQuestion($pdo, $newQId);
assert($deleted === true, 'Hapus soal harus berhasil');
echo "[PASS] CRUD bank soal terverifikasi.\n";

// 4. Alur Ujian Interaktif Mahasiswa: Attempt, Items, Auto-Save & Flag
echo "\n--- 4. Pengerjaan Ujian Mahasiswa: Attempt & Auto-Save Items ---\n";
// Insert sample student & class
$pdo->exec("
    INSERT INTO users (id, username, nama_lengkap, nim, program_studi, role, jenis_program)
    VALUES (501, 'mhs_rpl501', 'Fulan bin Fulan', '2026501', 'Pendidikan Agama Islam', 'mahasiswa', 'RPL');
");
$pdo->exec("
    INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, gelombang, dosen_id, dosen_nama, kuota)
    VALUES (10, 'Kelompok RPL Beta', 'Tutorial RPL 2026/2027', 'Gelombang 1', 1, 'Ustadz Ahmad, M.Pd.', 30);
");
$pdo->exec("
    INSERT INTO tutorial_rpl_members (id, class_id, user_id, drive_submitted, exam_submitted, nilai_praktik)
    VALUES (10, 10, 501, 1, 0, 80.00);
");

// Buat attempt baru
$attempt = tutorialRplCbtGetOrCreateAttempt($pdo, 501, 10, $sessionUpdated);
assert(!empty($attempt['id']), 'Attempt berhasil dibuat');
assert((int)$attempt['deadline_at'] > time(), 'Deadline harus berada di masa mendatang');
$attemptId = (int)$attempt['id'];

// Ambil items soal
$items = tutorialRplCbtGetAttemptItems($pdo, $attemptId);
assert(count($items) === count($questions), 'Jumlah items pengerjaan harus sesuai dengan jumlah butir soal');

// Auto-save jawaban pada nomor 1 (pilih A)
$savedAns1 = tutorialRplCbtSaveAnswer($pdo, $attemptId, 1, 'A');
assert($savedAns1 === true, 'Auto-save jawaban nomor 1 berhasil');

// Auto-save jawaban pada nomor 2 (pilih B)
$savedAns2 = tutorialRplCbtSaveAnswer($pdo, $attemptId, 2, 'B');
assert($savedAns2 === true, 'Auto-save jawaban nomor 2 berhasil');

// Auto-save flag ragu-ragu pada nomor 2
$savedFlag2 = tutorialRplCbtSaveFlag($pdo, $attemptId, 2, 1);
assert($savedFlag2 === true, 'Auto-save flag ragu-ragu nomor 2 berhasil');

// Verifikasi isi items setelah auto-save
$itemsAfterSave = tutorialRplCbtGetAttemptItems($pdo, $attemptId);
assert($itemsAfterSave[0]['answer'] === 'A', 'Jawaban nomor 1 tersimpan A');
assert($itemsAfterSave[1]['answer'] === 'B', 'Jawaban nomor 2 tersimpan B');
assert((int)$itemsAfterSave[1]['flagged'] === 1, 'Flag ragu-ragu nomor 2 bernilai 1');
echo "[PASS] Inisialisasi attempt, auto-save jawaban real-time, dan flag ragu-ragu terverifikasi.\n";

// 5. Pengumpulan Ujian (Finish Attempt) & Sinkronisasi Nilai Akhir
echo "\n--- 5. Pengumpulan Ujian, Penilaian, & Sinkronisasi Nilai ---\n";
// Jawab sisa soal sesuai kunci jawaban agar lulus
foreach ($itemsAfterSave as $it) {
    tutorialRplCbtSaveAnswer($pdo, $attemptId, (int)$it['position'], $it['correct_answer']);
}

$finishResult = tutorialRplCbtFinishAttempt($pdo, $attemptId);
assert($finishResult['score'] === 100.00, 'Seluruh jawaban benar harus bernilai 100.00');
assert($finishResult['correct_count'] === count($items), 'Jumlah benar sesuai total soal');

// Cek sinkronisasi ke tutorial_rpl_members
$stmtMem = $pdo->prepare("SELECT * FROM tutorial_rpl_members WHERE user_id = 501 AND class_id = 10");
$stmtMem->execute();
$memberData = $stmtMem->fetch(PDO::FETCH_ASSOC);
assert((int)$memberData['exam_submitted'] === 1, 'exam_submitted di tabel members harus 1');
assert((float)$memberData['nilai_ujian_tulis'] === 100.00, 'Nilai ujian tulis harus tersimpan 100.00');
assert((float)$memberData['nilai_akhir'] === 90.00, 'Nilai akhir dihitung: (100 + 80) / 2 = 90.00');
assert($memberData['status_lulus'] === 'lulus', 'Status kelulusan harus lulus (>= 70.00)');
echo "[PASS] Scoring otomatis, finalisasi attempt, dan sinkronisasi ke nilai tutorial RPL terverifikasi.\n";

// 6. Reset Attempt Peserta
echo "\n--- 6. Reset Attempt Ujian Peserta ---\n";
$resetOk = tutorialRplCbtResetAttempt($pdo, 501, 10);
assert($resetOk === true, 'Reset attempt harus sukses');

$chkAtt = tutorialRplCbtGetStudentAttempt($pdo, 501, 10);
assert($chkAtt === null, 'Attempt harus terhapus setelah di-reset');

$chkItems = $pdo->query("SELECT COUNT(*) FROM tutorial_rpl_cbt_items WHERE attempt_id = $attemptId")->fetchColumn();
assert((int)$chkItems === 0, 'Seluruh items attempt harus terhapus setelah di-reset');

$stmtMem2 = $pdo->query("SELECT exam_submitted, nilai_ujian_tulis, status_lulus FROM tutorial_rpl_members WHERE user_id = 501 AND class_id = 10")->fetch(PDO::FETCH_ASSOC);
assert((int)$stmtMem2['exam_submitted'] === 0, 'exam_submitted kembali ke 0');
assert($stmtMem2['nilai_ujian_tulis'] === null, 'nilai_ujian_tulis kembali ke NULL');
assert($stmtMem2['status_lulus'] === 'proses', 'status_lulus kembali ke proses');
echo "[PASS] Reset attempt ujian dan pemulihan status peserta terverifikasi bersih.\n";

// 7. Impor Soal Format Excel (Array Rows)
echo "\n--- 7. Impor Soal Excel (.xlsx rows) ---\n";
$excelRows = [
    ['Kode Soal', 'Kategori', 'Pertanyaan', 'Pilihan A', 'Pilihan B', 'Pilihan C', 'Pilihan D', 'Pilihan E', 'Kunci', 'Status'],
    ['IMP-01', 'Zakat', 'Nisab zakat emas setara dengan berapa gram emas murni?', '75 gram', '85 gram', '95 gram', '100 gram', '120 gram', 'B', 'Aktif'],
    ['IMP-02', 'Puasa', 'Rukun puasa ramadhan yang paling utama adalah...', 'Niat dan menahan diri dari pembatal puasa', 'Makan sahur', 'Shalat tarawih', 'Membayar fidyah', 'Iktikaf', 'A', 'Aktif']
];
$importResult = tutorialRplCbtImportQuestions($pdo, $excelRows, true);
assert($importResult['added'] === 2, '2 soal harus berhasil ditambahkan');
assert(empty($importResult['errors']), 'Tidak ada error pada baris impor');

// Update existing
$excelRowsUpdate = [
    ['IMP-01', 'Zakat & Wakaf', 'Nisab zakat emas sebesar...', '75 gram', '85 gram emas murni', '95 gram', '100 gram', '120 gram', 'B', 'Aktif']
];
$importUpdateResult = tutorialRplCbtImportQuestions($pdo, $excelRowsUpdate, true);
assert($importUpdateResult['updated'] === 1, '1 soal harus berhasil diperbarui');
echo "[PASS] Parsing dan impor butir soal Excel terverifikasi.\n";

// 8. Ekspor Bank Soal & Hasil
echo "\n--- 8. Ekspor Bank Soal & Ekspor Hasil Ujian ---\n";
$bankExport = tutorialRplCbtExportQuestions($pdo);
assert(count($bankExport) > 1, 'Ekspor bank soal memuat header dan baris');
assert($bankExport[0][0] === 'Kode Soal', 'Header kolom pertama adalah Kode Soal');

$resultsExport = tutorialRplCbtExportResults($pdo);
assert(count($resultsExport) >= 1, 'Ekspor hasil ujian memuat header');
assert($resultsExport[0][0] === 'NIM', 'Header kolom pertama hasil ujian adalah NIM');
echo "[PASS] Ekspor bank soal dan hasil ujian terverifikasi.\n";

// 9. Paritas File Antarmuka (Admin & Mahasiswa)
echo "\n--- 9. Paritas Antarmuka Admin & Mahasiswa ---\n";
$adminContent = file_get_contents(__DIR__ . '/../admin/tutorial-rpl-cbt.php');
assert(str_contains($adminContent, 'tab=questions'), 'Admin memuat tab Bank Soal');
assert(str_contains($adminContent, 'tab=session'), 'Admin memuat tab Sesi Ujian');
assert(str_contains($adminContent, 'tab=import'), 'Admin memuat tab Impor Excel');
assert(str_contains($adminContent, 'tab=attempts'), 'Admin memuat tab Hasil & Monitoring');
assert(str_contains($adminContent, 'generateRandomToken'), 'Admin memuat generator token acak');
assert(str_contains($adminContent, 'openReviewModal'), 'Admin memuat modal review lembar jawaban');
assert(str_contains($adminContent, 'pretes-cbt-admin.css'), 'Admin memuat stylesheet pretes-cbt-admin.css');

$studentContent = file_get_contents(__DIR__ . '/../pages/tutorial-rpl-cbt.php');
assert(str_contains($studentContent, 'cbt-shell'), 'Mahasiswa memuat container cbt-shell');
assert(str_contains($studentContent, 'cbt-timer'), 'Mahasiswa memuat countdown timer');
assert(str_contains($studentContent, 'cbt-completion'), 'Mahasiswa memuat bar terjawab');
assert(str_contains($studentContent, 'cbt-layout'), 'Mahasiswa memuat layout 2 kolom');
assert(str_contains($studentContent, 'cbt-option'), 'Mahasiswa memuat kartu opsi pilihan A-E');
assert(str_contains($studentContent, 'cbtFlag'), 'Mahasiswa memuat checkbox ragu-ragu');
assert(str_contains($studentContent, 'cbtNumbers'), 'Mahasiswa memuat grid navigasi nomor');
assert(str_contains($studentContent, 'ajax_action'), 'Mahasiswa memuat handler AJAX auto-save');
assert(str_contains($studentContent, 'cbtSubmitModal'), 'Mahasiswa memuat modal konfirmasi kumpulkan');
assert(str_contains($studentContent, 'pretes-cbt-exam.css'), 'Mahasiswa memuat stylesheet pretes-cbt-exam.css');
echo "[PASS] Kelengkapan 4 tab admin dan modern CBT engine mahasiswa terverifikasi 100% berparitas dengan CBT Pretes.\n";

echo "\n=== SELURUH TES PARITAS CBT TUTORIAL RPL BERHASIL 100% (ALL PASS) ===\n";
exit(0);
