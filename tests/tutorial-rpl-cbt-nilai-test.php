<?php
/**
 * Automated Test: Tutorial RPL Online - Tahap 4 (CBT & Nilai Akhir)
 */

require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';

echo "=== STARTING TUTORIAL RPL CBT & NILAI TEST ===\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Mock users and classes
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        nama_lengkap TEXT NOT NULL,
        nim TEXT,
        program_studi TEXT,
        fakultas TEXT,
        no_hp TEXT,
        role TEXT NOT NULL DEFAULT 'mahasiswa'
    );
");

ensureTutorialRplTables($pdo);
ensureTutorialRplCbtTables($pdo);

// Insert sample users
$pdo->exec("
    INSERT INTO users (id, username, nama_lengkap, nim, program_studi, role) VALUES
    (101, 'mhs101', 'Ahmad Dahlan', '20260001', 'Pendidikan Agama Islam', 'mahasiswa'),
    (102, 'mhs102', 'Siti Walidah', '20260002', 'Hukum Ekonomi Syariah', 'mahasiswa'),
    (201, 'dsn201', 'Dr. H. Muhammad, M.Ag', '19800101', 'Tutor PAI', 'dosen');
");

// Insert class & plot members
$stmtCls = $pdo->prepare("
    INSERT INTO tutorial_rpl_classes (nama_kelompok, dosen_id, dosen_nama, kuota, drive_url)
    VALUES (?, ?, ?, ?, ?)
");
$stmtCls->execute(['Kelompok RPL Alpha', 201, 'Dr. H. Muhammad, M.Ag', 20, 'https://drive.google.com/drive/folders/test-alpha']);
$classId = (int)$pdo->lastInsertId();

tutorialRplBatchPlot($pdo, [101, 102], $classId);

// 1. Test Session Management
$session = tutorialRplCbtGetSession($pdo);
assert(!empty($session['title']), 'Session should have title');
assert($session['exam_open'] == 1, 'Default exam should be open');

tutorialRplCbtUpdateSession($pdo, [
    'title' => 'Ujian Tulis Tutorial RPL Gelombang 1',
    'duration_minutes' => 45,
    'passing_grade' => 80.00,
    'token' => 'LULUS26',
    'exam_open' => 1
]);

$sessionUpdated = tutorialRplCbtGetSession($pdo);
assert($sessionUpdated['title'] === 'Ujian Tulis Tutorial RPL Gelombang 1', 'Title must match updated');
assert((int)$sessionUpdated['duration_minutes'] === 45, 'Duration must be 45');
assert($sessionUpdated['token'] === 'LULUS26', 'Token must be LULUS26');
echo "[PASS] Sesi CBT Update & Retrieval verified\n";

// 2. Test Question Bank (Seed & CRUD)
$questions = tutorialRplCbtGetQuestions($pdo);
assert(count($questions) >= 8, 'Default questions should have at least 8 items');

$newQId = tutorialRplCbtSaveQuestion($pdo, [
    'category' => 'Ubudiyah',
    'question_text' => 'Berapa rakaat sholat tarawih yang umum diamalkan?',
    'options' => ['8 rakaat', '11 rakaat', '20 rakaat', '23 rakaat', 'Semua benar sesuai riwayat'],
    'correct_answer' => 'E'
]);
assert($newQId > 0, 'New question ID must be > 0');

tutorialRplCbtSaveQuestion($pdo, [
    'id' => $newQId,
    'category' => 'Ubudiyah Praktis',
    'question_text' => 'Berapa rakaat sholat tarawih menurut riwayat Umar bin Khattab?',
    'options' => ['8 rakaat', '11 rakaat', '20 rakaat', '23 rakaat', 'Semua benar sesuai riwayat'],
    'correct_answer' => 'C'
]);

$qListAfter = tutorialRplCbtGetQuestions($pdo, 'Ubudiyah Praktis');
assert(count($qListAfter) >= 1, 'Should find question in category Ubudiyah Praktis');

tutorialRplCbtDeleteQuestion($pdo, $newQId);
$qListDeleted = tutorialRplCbtGetQuestions($pdo);
$ids = array_column($qListDeleted, 'id');
assert(!in_array($newQId, $ids), 'Deleted question must not exist in list');
echo "[PASS] Question Bank CRUD & Category Filter verified\n";

// 3. Test Practical Grading + CBT Submission -> Final Grade Calculation
// Get member IDs:
$stmtMem = $pdo->prepare("SELECT id, user_id FROM tutorial_rpl_members WHERE class_id = ?");
$stmtMem->execute([$classId]);
$members = $stmtMem->fetchAll(PDO::FETCH_KEY_PAIR); // id => user_id or flipped
$userToMember = [];
foreach ($pdo->query("SELECT id, user_id FROM tutorial_rpl_members WHERE class_id = $classId") as $row) {
    $userToMember[$row['user_id']] = (int)$row['id'];
}

// Give practical scores first:
// User 101: Practical 86
tutorialRplSaveEvaluation($pdo, $userToMember[101], 86.0, 1, 'Bacaan sangat tartil dan tajwid tepat.');
// User 102: Practical 70
tutorialRplSaveEvaluation($pdo, $userToMember[102], 70.0, 1, 'Masih perlu perbaikan pada makhraj huruf.');

// Submit CBT:
// User 101: CBT score 90.0
tutorialRplCbtSubmitAttempt($pdo, 101, $classId, 90.0, 23, 25);
// User 102: CBT score 74.0
tutorialRplCbtSubmitAttempt($pdo, 102, $classId, 74.0, 18, 25);

// Verify Member 101: Final score = round((90 + 86)/2, 2) = 88.00 -> LULUS
$stmt101 = $pdo->prepare("SELECT * FROM tutorial_rpl_members WHERE user_id = ? AND class_id = ?");
$stmt101->execute([101, $classId]);
$m101 = $stmt101->fetch(PDO::FETCH_ASSOC);

assert((float)$m101['nilai_ujian_tulis'] === 90.0, 'Nilai ujian tulis 101 must be 90');
assert((float)$m101['nilai_praktik'] === 86.0, 'Nilai praktik 101 must be 86');
assert((float)$m101['nilai_akhir'] === 88.0, 'Nilai akhir 101 must be 88');
assert($m101['status_lulus'] === 'lulus', '101 status must be lulus');
assert((int)$m101['exam_submitted'] === 1, '101 exam_submitted must be 1');

// Verify Member 102: Final score = round((74 + 70)/2, 2) = 72.00 -> TIDAK_LULUS
$stmt102 = $pdo->prepare("SELECT * FROM tutorial_rpl_members WHERE user_id = ? AND class_id = ?");
$stmt102->execute([102, $classId]);
$m102 = $stmt102->fetch(PDO::FETCH_ASSOC);

assert((float)$m102['nilai_ujian_tulis'] === 74.0, 'Nilai ujian tulis 102 must be 74');
assert((float)$m102['nilai_praktik'] === 70.0, 'Nilai praktik 102 must be 70');
assert((float)$m102['nilai_akhir'] === 72.0, 'Nilai akhir 102 must be 72');
assert($m102['status_lulus'] === 'tidak_lulus', '102 status must be tidak_lulus');

echo "[PASS] Automatic Final Grade Calculation & Passing Grade (>= 80.00) verified\n";

// 4. Test Attempt List & Reset Attempt
$attempts = tutorialRplCbtGetAttempts($pdo, $classId);
assert(count($attempts) === 2, 'Should have 2 attempts recorded');

// Reset User 102 attempt
tutorialRplCbtResetAttempt($pdo, 102, $classId);

$attemptsAfterReset = tutorialRplCbtGetAttempts($pdo, $classId);
assert(count($attemptsAfterReset) === 1, 'Should have 1 attempt remaining');

$stmt102Reset = $pdo->prepare("SELECT * FROM tutorial_rpl_members WHERE user_id = ? AND class_id = ?");
$stmt102Reset->execute([102, $classId]);
$m102Reset = $stmt102Reset->fetch(PDO::FETCH_ASSOC);
assert($m102Reset['nilai_ujian_tulis'] === null, 'Nilai ujian tulis should be NULL after reset');
assert((int)$m102Reset['exam_submitted'] === 0, 'exam_submitted should be 0 after reset');
assert($m102Reset['status_lulus'] === 'proses', 'Status must return to proses after reset');
assert((float)$m102Reset['nilai_praktik'] === 70.0, 'Nilai praktik must remain preserved');

echo "[PASS] Attempt Reset & Member State Reversion verified\n";
echo "=== ALL TUTORIAL RPL CBT & NILAI TESTS PASSED (100%) ===\n";
