<?php
/**
 * Test: Paritas Engine CBT, Proctoring Kamera, Jeda Anti-Kecurangan, & Penilaian Tutorial RPL vs CBT Pretes
 */
require_once __DIR__ . '/../includes/tutorial-rpl-cbt-engine.php';

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        username TEXT,
        role TEXT,
        nama_lengkap TEXT,
        nim TEXT,
        no_hp TEXT,
        jenis_program TEXT,
        foto TEXT
    );
");

ensureTutorialRplTables($pdo);
ensureTutorialRplCbtProctoringTables($pdo);

$pdo->exec("
    INSERT INTO users (id, username, role, nama_lengkap, nim, no_hp, jenis_program)
    VALUES (10, 'dosen1', 'dosen', 'Dr. Zaid', '0010', '0812', 'Reguler'),
           (201, 'mhs201', 'mahasiswa', 'Ahmad RPL', '202601', '0813', 'RPL');
    INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, gelombang, dosen_id, dosen_nama, is_active)
    VALUES (10, 'Kelompok A', '2026/2027', 'Gelombang 1', 10, 'Dr. Zaid', 1);
    INSERT INTO tutorial_rpl_members (id, class_id, user_id, nilai_praktik)
    VALUES (50, 10, 201, 80.0);
");

// Gunakan 5 soal untuk test cepat
$pdo->exec("UPDATE tutorial_rpl_cbt_packages SET question_count = 5 WHERE id = 1");
$pdo->exec("UPDATE tutorial_rpl_cbt_sessions SET token_required = 0, camera_mode = 'wajib', duration_minutes = 30, passing_grade = 75.0 WHERE id = 1");

$now = 1700000000;
$state = tutorialRplCbtState($pdo, 201, 10, $now);
assert($state['attempt'] === null, 'Awalnya belum ada attempt');
assert(!empty($state['available_sessions']) && count($state['available_sessions']) >= 2, 'Multi-sesi aktif tersedia di state awal');

// 1. Mulai ujian
$started = tutorialRplCbtStart($pdo, 201, 10, $now, null, false, 1);
assert(!empty($started['attempt']['id']), 'Attempt terbuat');
assert(count($started['attempt']['questions']) === 5, '5 soal terbit');
assert($started['attempt']['remaining_seconds'] === 1800, 'Durasi 30 menit (1800 detik)');

// 2. Simpan jawaban di posisi 1 dan 2
$save1 = tutorialRplCbtChange($pdo, 201, 10, 'save', ['position' => 1, 'answer' => 'A', 'revision' => 0], $now + 10);
assert($save1['attempt']['answered'] === 1, '1 soal terjawab');
$save2 = tutorialRplCbtChange($pdo, 201, 10, 'save', ['position' => 2, 'answer' => 'B', 'revision' => 0], $now + 20);
assert($save2['attempt']['answered'] === 2, '2 soal terjawab');

// 3. Interupsi di posisi 1 (face_mismatch) -> soal posisi 1 dikosongkan, clock dibekukan, revision naik
$interrupted = tutorialRplCbtChange($pdo, 201, 10, 'interrupt', [
    'event_id' => 'evt_test_12345678',
    'reason' => 'face_mismatch',
    'position' => 1,
    'revision' => 0,
], $now + 30);
assert($interrupted['attempt']['paused'] === true, 'Ujian dijeda saat face_mismatch');
assert($interrupted['attempt']['revision'] === 1, 'Revision naik menjadi 1 setelah interupsi');
assert($interrupted['attempt']['remaining_seconds'] === 1770, 'Sisa waktu dibekukan di 1770 detik');
assert($interrupted['attempt']['answered'] === 1, 'Soal yang sedang terbuka dikosongkan sehingga terjawab tinggal 1');

// 4. Save saat paused harus ditolak
$blockedWhilePaused = false;
try {
    tutorialRplCbtChange($pdo, 201, 10, 'save', ['position' => 3, 'answer' => 'C', 'revision' => 1], $now + 40);
} catch (RuntimeException $e) {
    $blockedWhilePaused = str_contains($e->getMessage(), 'dijeda');
}
assert($blockedWhilePaused, 'Simpan jawaban ditolak saat ujian sedang dijeda');

// 5. Resume setelah 60 detik -> deadline bertambah 60 detik, sisa waktu tetap 1770 detik
$resumed = tutorialRplCbtChange($pdo, 201, 10, 'resume', [], $now + 90);
assert($resumed['attempt']['paused'] === false, 'Ujian kembali aktif setelah resume');
assert($resumed['attempt']['remaining_seconds'] === 1770, 'Sisa waktu tetap 1770 detik setelah jeda 60 detik');

// 6. Save dengan revision lama (0) ditolak; dengan revision baru (1) diterima
$staleBlocked = false;
try {
    tutorialRplCbtChange($pdo, 201, 10, 'save', ['position' => 3, 'answer' => 'C', 'revision' => 0], $now + 95);
} catch (RuntimeException $e) {
    $staleBlocked = str_contains($e->getMessage(), 'Urutan soal berubah');
}
assert($staleBlocked, 'Save dengan revision lama ditolak');

// 7. Jawab semua soal dengan kunci yang benar dan submit
$stmtKeys = $pdo->prepare('SELECT position, correct_answer FROM tutorial_rpl_cbt_items WHERE attempt_id = ? ORDER BY position');
$stmtKeys->execute([$started['attempt']['id']]);
foreach ($stmtKeys->fetchAll() as $k) {
    tutorialRplCbtChange($pdo, 201, 10, 'save', [
        'position' => (int)$k['position'],
        'answer' => $k['correct_answer'],
        'revision' => 1,
    ], $now + 100);
}
$submitted = tutorialRplCbtChange($pdo, 201, 10, 'submit', ['revision' => 1], $now + 120);
assert($submitted['attempt']['submitted'] === true, 'Submit ujian berhasil');
assert((float)$submitted['attempt']['score'] === 100.0, 'Skor 100.0 terhitung');

$mem = $pdo->query('SELECT exam_submitted, nilai_ujian_tulis, nilai_akhir, status_lulus FROM tutorial_rpl_members WHERE id = 50')->fetch();
assert((int)$mem['exam_submitted'] === 1, 'exam_submitted tersinkron ke tutorial_rpl_members');
assert((float)$mem['nilai_ujian_tulis'] === 100.0, 'nilai_ujian_tulis = 100.0');
assert((float)$mem['nilai_akhir'] === 90.0, 'nilai_akhir = (100 + 80)/2 = 90.0');
assert($mem['status_lulus'] === 'lulus', 'status_lulus = lulus');

// 8. Snapshot throttling test
$pdo->exec("INSERT INTO tutorial_rpl_proctoring_logs (member_id, class_id, user_id, event_type, similarity_score, status, snapshot_path) VALUES (50, 10, 201, 'periodic_check', 92.5, 'MATCH', 'uploads/pretes_proctoring/a.jpg')");
assert(tutorialRplPeriodicSnapshotNeeded($pdo, 50, true) === false, 'Snapshot MATCH dalam 5 menit tidak disimpan ulang');
assert(tutorialRplPeriodicSnapshotNeeded($pdo, 50, false) === true, 'Snapshot pelanggaran selalu disimpan');

echo "=== ALL TUTORIAL RPL CBT PROCTORING PARITY TESTS PASSED (100%) ===\n";
exit(0);
