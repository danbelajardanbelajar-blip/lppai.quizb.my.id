<?php
/**
 * Automated Test: Paritas CBT Tutorial RPL Admin & Mahasiswa dengan CBT Pretes
 * Menguji skema 5 tabel, paritas 2 paket soal (Muslim & Kristen Protestan),
 * 2 sesi ujian 90 menit (passing grade 70), penindihan 65 butir soal resmi dari CBT Pretes,
 * attempt interaktif adaptif per agama dengan items, auto-save jawaban & flag, kalkulasi nilai,
 * sinkronisasi nilai akhir, reset attempt, impor/ekspor Excel per paket,
 * serta kelengkapan antarmuka 4 tab admin dan layout 2 kolom mahasiswa.
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
assert(in_array('tutorial_rpl_cbt_packages', $tables, true), 'Tabel tutorial_rpl_cbt_packages harus ada');
assert(in_array('tutorial_rpl_cbt_sessions', $tables, true), 'Tabel tutorial_rpl_cbt_sessions harus ada');
assert(in_array('tutorial_rpl_cbt_questions', $tables, true), 'Tabel tutorial_rpl_cbt_questions harus ada');
assert(in_array('tutorial_rpl_cbt_attempts', $tables, true), 'Tabel tutorial_rpl_cbt_attempts harus ada');
assert(in_array('tutorial_rpl_cbt_items', $tables, true), 'Tabel tutorial_rpl_cbt_items harus ada');
echo "[PASS] Seluruh 5 tabel CBT (packages, sessions, questions, attempts, items) terverifikasi.\n";

// 2. Verifikasi 2 Paket Soal & 2 Sesi Ujian (Default 90 Menit & Passing Grade 70.00)
echo "\n--- 2. Verifikasi Paket Soal & Sesi Ujian Berparitas CBT Pretes ---\n";
$packages = tutorialRplCbtGetPackages($pdo);
assert(count($packages) === 2, 'Harus ada tepat 2 paket soal (Paket 1 Muslim & Paket 2 Kristen Protestan)');
assert($packages[0]['code'] === 'PKT-TUTOR-UTAMA', 'Kode paket 1 adalah PKT-TUTOR-UTAMA');
assert($packages[1]['code'] === 'PKT-2-KRISTEN-PROTESTAN', 'Kode paket 2 adalah PKT-2-KRISTEN-PROTESTAN');

$sessions = tutorialRplCbtGetSessions($pdo);
assert(count($sessions) === 2, 'Harus ada 2 sesi ujian (Sesi 1 Utama & Sesi 2 Kristen Protestan)');
assert($sessions[0]['code'] === 'SESI-TUTORIAL-RPL-2026', 'Kode sesi 1 adalah SESI-TUTORIAL-RPL-2026');
assert($sessions[1]['code'] === 'SESI-TUTORIAL-PROTESTAN-2026', 'Kode sesi 2 adalah SESI-TUTORIAL-PROTESTAN-2026');
assert((int)$sessions[0]['duration_minutes'] === 90, 'Durasi sesi 1 harus 90 menit');
assert((int)$sessions[1]['duration_minutes'] === 90, 'Durasi sesi 2 harus 90 menit');
assert((float)$sessions[0]['passing_grade'] === 70.00, 'Passing grade sesi 1 harus 70.00');
assert((float)$sessions[1]['passing_grade'] === 70.00, 'Passing grade sesi 2 harus 70.00');
assert($sessions[0]['token'] === 'TUTOR26', 'Token default sesi 1 harus TUTOR26');
assert($sessions[1]['token'] === 'TUTOR26', 'Token default sesi 2 harus TUTOR26');

// Uji CRUD Paket Soal Tambahan
$customPkgId = tutorialRplCbtSavePackage($pdo, [
    'code' => 'PKT-TUTOR-TEST',
    'name' => 'Paket Uji Coba Tambahan',
    'description' => 'Paket pengujian tambahan',
    'duration_minutes' => 60,
    'question_count' => 15,
    'is_active' => 1
]);
assert($customPkgId > 0, 'Tambah paket soal baru harus berhasil');
$customPkg = tutorialRplCbtGetPackage($pdo, $customPkgId);
assert($customPkg['name'] === 'Paket Uji Coba Tambahan', 'Nama paket tersimpan sesuai');
$deletedPkg = tutorialRplCbtDeletePackage($pdo, $customPkgId);
assert($deletedPkg === true, 'Hapus paket tambahan harus berhasil');
$cannotDeleteCore = tutorialRplCbtDeletePackage($pdo, 1);
assert($cannotDeleteCore === false, 'Paket inti 1 dilindungi dari penghapusan');
echo "[PASS] Paritas Paket Soal & Sesi Ujian serta CRUD Paket terverifikasi.\n";

// 3. Bank Soal & Penindihan Data Soal Resmi CBT Pretes
echo "\n--- 3. Bank Soal & Penindihan Data Soal CBT Pretes ---\n";
$questionsPkg1 = tutorialRplCbtGetQuestions($pdo, null, 1);
$questionsPkg2 = tutorialRplCbtGetQuestions($pdo, null, 2);
$allQuestions = tutorialRplCbtGetQuestions($pdo);

assert(count($questionsPkg1) === 177, 'Paket 1 Muslim harus memuat 177 butir soal resmi dari CBT Pretes');
assert(count($questionsPkg2) === 25, 'Paket 2 Kristen Protestan harus memuat 25 butir soal resmi dari data/paket-2-kristen-protestan.php');
assert(count($allQuestions) === 202, 'Total butir soal aktif harus 202 butir soal resmi (177 Muslim + 25 Kristen)');

// Pastikan soal lama dummy TUTOR-RPL-Q% sudah tertindih bersih
$dummyCount = (int)$pdo->query("SELECT COUNT(*) FROM tutorial_rpl_cbt_questions WHERE code LIKE 'TUTOR-RPL-Q%'")->fetchColumn();
assert($dummyCount === 0, 'Data soal dummy lama TUTOR-RPL-Q% harus tertindih bersih (0 butir)');
echo "[PASS] Penindihan data soal CBT Pretes (177 Muslim + 25 Kristen = 202 soal resmi) terverifikasi.\n";

// Tambah soal baru di Paket 1
$newQId = tutorialRplCbtSaveQuestion($pdo, [
    'package_id' => 1,
    'category' => 'Ubudiyah Lanjutan',
    'question_text' => 'Berapa rakaat shalat tarawih yang umum diamalkan di Indonesia?',
    'options' => ['8 rakaat', '20 rakaat', '8 atau 20 rakaat', '36 rakaat', '40 rakaat'],
    'correct_answer' => 'C'
]);
assert($newQId > 0, 'Tambah soal baru harus berhasil');
$qData = tutorialRplCbtGetQuestion($pdo, $newQId);
assert($qData !== null, 'Soal baru dapat diambil kembali');
assert((int)$qData['package_id'] === 1, 'Soal baru tersimpan pada Paket 1');
assert($qData['correct_answer'] === 'C', 'Kunci jawaban benar adalah C');

// Hapus soal tambahan
$deleted = tutorialRplCbtDeleteQuestion($pdo, $newQId);
assert($deleted === true, 'Hapus soal harus berhasil');
echo "[PASS] CRUD bank soal terverifikasi.\n";

// 4. Alur Ujian Mahasiswa Muslim (Paket 1 - 25 Butir Soal)
echo "\n--- 4. Pengerjaan Ujian Mahasiswa Muslim (Paket 1) ---\n";
$pdo->exec("
    INSERT INTO users (id, username, nama_lengkap, nim, program_studi, role, jenis_program)
    VALUES (501, 'mhs_muslim501', 'Ahmad Farhan', '2026501', 'Pendidikan Agama Islam', 'mahasiswa', 'RPL');
");
$pdo->exec("
    INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, gelombang, dosen_id, dosen_nama, kuota)
    VALUES (10, 'Kelompok RPL Al-Farabi', 'Tutorial RPL 2026/2027', 'Gelombang 1', 1, 'Ustadz Ahmad, M.Pd.', 30);
");
$pdo->exec("
    INSERT INTO tutorial_rpl_members (id, class_id, user_id, drive_submitted, exam_submitted, nilai_praktik)
    VALUES (10, 10, 501, 1, 0, 80.00);
");

// Buat attempt untuk mahasiswa Muslim (Sesi 1 / Paket 1)
$sessionMuslim = tutorialRplCbtGetSession($pdo, 1);
$attemptMuslim = tutorialRplCbtGetOrCreateAttempt($pdo, 501, 10, $sessionMuslim);
assert(!empty($attemptMuslim['id']), 'Attempt mahasiswa Muslim berhasil dibuat');
assert((int)$attemptMuslim['package_id'] === 1, 'Attempt terhubung ke Paket 1 (Muslim)');
assert((int)$attemptMuslim['session_id'] === 1, 'Attempt terhubung ke Sesi 1');

$itemsMuslim = tutorialRplCbtGetAttemptItems($pdo, (int)$attemptMuslim['id']);
assert(count($itemsMuslim) === 25, 'Lembar soal mahasiswa Muslim berisi 25 butir soal (sesuai kuota paket)');

// Auto-save jawaban dan flag
$savedAns1 = tutorialRplCbtSaveAnswer($pdo, (int)$attemptMuslim['id'], 1, 'C');
assert($savedAns1 === true, 'Auto-save jawaban nomor 1 berhasil');
$savedFlag2 = tutorialRplCbtSaveFlag($pdo, (int)$attemptMuslim['id'], 2, 1);
assert($savedFlag2 === true, 'Auto-save flag ragu-ragu berhasil');

// Jawab semua soal dengan benar agar 100%
foreach ($itemsMuslim as $it) {
    tutorialRplCbtSaveAnswer($pdo, (int)$attemptMuslim['id'], (int)$it['position'], $it['correct_answer']);
}
$finishMuslim = tutorialRplCbtFinishAttempt($pdo, (int)$attemptMuslim['id']);
assert($finishMuslim['score'] === 100.00, 'Seluruh jawaban benar harus bernilai 100.00');

// Cek sinkronisasi ke tutorial_rpl_members
$stmtMem = $pdo->prepare("SELECT * FROM tutorial_rpl_members WHERE user_id = 501 AND class_id = 10");
$stmtMem->execute();
$memberData = $stmtMem->fetch(PDO::FETCH_ASSOC);
assert((int)$memberData['exam_submitted'] === 1, 'exam_submitted di tabel members harus 1');
assert((float)$memberData['nilai_ujian_tulis'] === 100.00, 'Nilai ujian tulis harus 100.00');
assert((float)$memberData['nilai_akhir'] === 90.00, 'Nilai akhir dihitung: (100 + 80) / 2 = 90.00');
assert($memberData['status_lulus'] === 'lulus', 'Status kelulusan harus lulus (>= 70.00)');
echo "[PASS] Pengerjaan ujian mahasiswa Muslim (Paket 1, 25 soal, scoring 100%, sinkronisasi) terverifikasi.\n";

// 5. Alur Ujian Mahasiswa Kristen Protestan (Paket 2 - 25 Butir Soal)
echo "\n--- 5. Pengerjaan Ujian Mahasiswa Kristen Protestan (Paket 2) ---\n";
$pdo->exec("
    INSERT INTO users (id, username, nama_lengkap, nim, program_studi, role, jenis_program)
    VALUES (502, 'mhs_kristen502', 'Yohanes Christian', '2026502', 'Manajemen', 'mahasiswa', 'RPL');
");
$pdo->exec("
    INSERT INTO tutorial_rpl_members (id, class_id, user_id, drive_submitted, exam_submitted, nilai_praktik)
    VALUES (11, 10, 502, 1, 0, 85.00);
");

// Buat attempt untuk mahasiswa Kristen (Sesi 2 / Paket 2)
$sessionKristen = tutorialRplCbtGetSession($pdo, 2);
$attemptKristen = tutorialRplCbtGetOrCreateAttempt($pdo, 502, 10, $sessionKristen);
assert(!empty($attemptKristen['id']), 'Attempt mahasiswa Kristen berhasil dibuat');
assert((int)$attemptKristen['package_id'] === 2, 'Attempt terhubung ke Paket 2 (Kristen Protestan)');
assert((int)$attemptKristen['session_id'] === 2, 'Attempt terhubung ke Sesi 2');

$itemsKristen = tutorialRplCbtGetAttemptItems($pdo, (int)$attemptKristen['id']);
assert(count($itemsKristen) === 25, 'Lembar soal mahasiswa Kristen berisi 25 butir soal');
assert(in_array($itemsKristen[0]['category'], ['Kitab Suci', 'Gereja & Denominasi', 'Sejarah & Tokoh', 'Dasar Iman', 'Ibadah & Sakramen', 'Hari Raya & Nilai Kasih'], true), 'Kategori soal mahasiswa Kristen berasal dari materi kristiani');

// Selesaikan ujian mahasiswa Kristen
foreach ($itemsKristen as $it) {
    tutorialRplCbtSaveAnswer($pdo, (int)$attemptKristen['id'], (int)$it['position'], $it['correct_answer']);
}
$finishKristen = tutorialRplCbtFinishAttempt($pdo, (int)$attemptKristen['id']);
assert($finishKristen['score'] === 100.00, 'Ujian mahasiswa Kristen bernilai 100.00');
echo "[PASS] Pengerjaan ujian mahasiswa Kristen Protestan (Paket 2, 25 soal, scoring 100%) terverifikasi.\n";

// 6. Reset Attempt Peserta
echo "\n--- 6. Reset Attempt Ujian Peserta ---\n";
$resetOk = tutorialRplCbtResetAttempt($pdo, 501, 10);
assert($resetOk === true, 'Reset attempt harus sukses');
$chkAtt = tutorialRplCbtGetStudentAttempt($pdo, 501, 10);
assert($chkAtt === null, 'Attempt harus terhapus setelah di-reset');
echo "[PASS] Reset attempt ujian dan pemulihan status peserta terverifikasi bersih.\n";

// 7. Impor Soal Format Excel (Multi-Paket)
echo "\n--- 7. Impor Soal Excel per Paket Soal ---\n";
$excelRows = [
    ['Kode Soal', 'Kategori', 'Pertanyaan', 'Pilihan A', 'Pilihan B', 'Pilihan C', 'Pilihan D', 'Pilihan E', 'Kunci', 'Status'],
    ['IMP-ISL-01', 'Zakat', 'Nisab zakat emas setara dengan berapa gram emas murni?', '75 gram', '85 gram', '95 gram', '100 gram', '120 gram', 'B', 'Aktif'],
    ['IMP-ISL-02', 'Puasa', 'Rukun puasa ramadhan yang paling utama adalah...', 'Niat dan menahan diri dari pembatal puasa', 'Makan sahur', 'Shalat tarawih', 'Membayar fidyah', 'Iktikaf', 'A', 'Aktif']
];
$importResult = tutorialRplCbtImportQuestions($pdo, $excelRows, true, 1);
assert($importResult['added'] === 2, '2 soal berhasil diimpor ke Paket 1');
assert(empty($importResult['errors']), 'Tidak ada error pada baris impor');
echo "[PASS] Parsing dan impor butir soal Excel ke Paket 1 terverifikasi.\n";

// 8. Ekspor Bank Soal & Hasil
echo "\n--- 8. Ekspor Bank Soal & Ekspor Hasil Ujian ---\n";
$bankExportAll = tutorialRplCbtExportQuestions($pdo);
assert(count($bankExportAll) > 1, 'Ekspor bank soal memuat header dan baris');
assert($bankExportAll[0][0] === 'Kode Soal', 'Header kolom pertama adalah Kode Soal');
assert($bankExportAll[0][1] === 'Paket', 'Header kolom kedua adalah Paket');

$resultsExport = tutorialRplCbtExportResults($pdo);
assert(count($resultsExport) >= 1, 'Ekspor hasil ujian memuat header');
assert($resultsExport[0][0] === 'NIM', 'Header kolom pertama hasil ujian adalah NIM');
assert(in_array('Sesi Ujian', $resultsExport[0], true), 'Ekspor memuat kolom Sesi Ujian');
assert(in_array('Paket Soal', $resultsExport[0], true), 'Ekspor memuat kolom Paket Soal');
echo "[PASS] Ekspor bank soal dan hasil ujian dengan kolom Sesi & Paket terverifikasi.\n";

// 9. Paritas File Antarmuka (Admin & Mahasiswa)
echo "\n--- 9. Paritas Antarmuka Admin & Mahasiswa ---\n";
$adminContent = file_get_contents(__DIR__ . '/../admin/tutorial-rpl-cbt.php');
assert(str_contains($adminContent, 'tab=packages'), 'Admin memuat tab Paket Soal');
assert(str_contains($adminContent, 'Paket Soal'), 'Label tab pertama adalah Paket Soal');
assert(str_contains($adminContent, 'tab=sessions'), 'Admin memuat tab Sesi Ujian');
assert(str_contains($adminContent, 'tab=import'), 'Admin memuat tab Impor Excel');
assert(str_contains($adminContent, 'tab=results'), 'Admin memuat tab Hasil Ujian');
assert(str_contains($adminContent, 'openPackageModal'), 'Admin memuat modal buat/edit paket soal');
assert(str_contains($adminContent, 'openPackageQuestionsModal'), 'Admin memuat modal kelola & koleksi soal paket (openPackageQuestionsModal)');
assert(str_contains($adminContent, 'modalPackageQuestions'), 'Admin memuat container modal modalPackageQuestions');
assert(str_contains($adminContent, 'ajax=package_questions'), 'Admin memuat endpoint AJAX package_questions');
assert(str_contains($adminContent, 'filterPackage'), 'Admin memuat filter Paket Soal');
assert(str_contains($adminContent, 'reset_pretes_parity_packages'), 'Admin memuat aksi reset & tindih soal CBT Pretes');
assert(str_contains($adminContent, 'generateRandomToken'), 'Admin memuat generator token acak');
assert(str_contains($adminContent, 'openReviewModal'), 'Admin memuat modal review lembar jawaban');

$studentContent = file_get_contents(__DIR__ . '/../pages/tutorial-rpl-cbt.php');
assert(str_contains($studentContent, 'cbt-shell'), 'Mahasiswa memuat container cbt-shell');
assert(str_contains($studentContent, 'cbt-timer'), 'Mahasiswa memuat countdown timer');
assert(str_contains($studentContent, 'cbt-completion'), 'Mahasiswa memuat bar terjawab');
assert(str_contains($studentContent, 'cbt-layout'), 'Mahasiswa memuat layout 2 kolom');
assert(str_contains($studentContent, 'cbt-option'), 'Mahasiswa memuat kartu opsi pilihan A-E');
assert(str_contains($studentContent, 'cbtFlag'), 'Mahasiswa memuat checkbox ragu-ragu');
assert(str_contains($studentContent, 'cbtNumbers'), 'Mahasiswa memuat grid navigasi nomor');
assert(str_contains($studentContent, 'ajax_action'), 'Mahasiswa memuat handler AJAX auto-save');
assert(str_contains($studentContent, 'cbtSessionSelect'), 'Mahasiswa memuat selektor sesi/paket adaptif');
echo "[PASS] Kelengkapan antarmuka Admin & Mahasiswa terverifikasi 100% berparitas dengan CBT Pretes.\n";

echo "\n=== SELURUH TES PARITAS CBT TUTORIAL RPL BERHASIL 100% (ALL PASS) ===\n";
exit(0);
