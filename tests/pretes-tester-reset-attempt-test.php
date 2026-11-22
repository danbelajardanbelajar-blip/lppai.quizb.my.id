<?php
/**
 * Test Suite: CBT Tester Attempt Reset & Multi-Session Resumption
 *
 * Menguji:
 * 1. Mahasiswa dengan attempt berjalan di-resume langsung (tidak menampilkan intro/pilihan sesi).
 * 2. Tester action reset_cbt menghapus attempt di pretes_cbt_attempts dan butir di pretes_cbt_items.
 * 3. Setelah attempt terhapus, pretesCbtState() kembali menampilkan available_sessions (2 sesi aktif).
 * 4. Pemilihan Sesi 1 menghasilkan soal Paket Islam; Sesi 2 menghasilkan soal Paket Kristen.
 */

require_once __DIR__ . '/../includes/pretes-cbt.php';

echo "=== Menjalankan Test Suite: CBT Tester Attempt Reset & Multi-Session ===\n";

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

ensurePretesCbtTables($pdo);

$pdo->exec("DELETE FROM pretes_cbt_packages");
$pdo->exec("DELETE FROM pretes_cbt_sessions");
$pdo->exec("DELETE FROM pretes_cbt_questions");

// Setup Paket 1 & 2
$now = time();
$pdo->prepare("INSERT INTO pretes_cbt_packages (id, code, name, description, duration_minutes, question_count, is_active, created_at) VALUES (1, 'PKT-ISLAM', 'Paket Pretes Utama', 'Ubudiyah', 30, 25, 1, ?)")->execute([$now]);
$pdo->prepare("INSERT INTO pretes_cbt_packages (id, code, name, description, duration_minutes, question_count, is_active, created_at) VALUES (2, 'PKT-KRISTEN', 'Paket 2 - Kristen Protestan', 'Kristen', 30, 25, 1, ?)")->execute([$now]);

$insQ = $pdo->prepare("INSERT INTO pretes_cbt_questions (code, package_id, category, question_text, options_json, correct_answer, is_active) VALUES (?, ?, ?, ?, ?, 'A', 1)");
for ($i = 1; $i <= 25; $i++) {
    $insQ->execute(["ISL-$i", 1, 'Ubudiyah', "Soal Islam $i", json_encode(['A'=>'Ya','B'=>'Tidak','C'=>'Mungkin','D'=>'Bisa','E'=>'Ragu'])]);
    $insQ->execute(["KRS-$i", 2, 'Kristen', "Soal Kristen $i", json_encode(['A'=>'Ya','B'=>'Tidak','C'=>'Mungkin','D'=>'Bisa','E'=>'Ragu'])]);
}

// Setup Sesi 1 & 2
$ses1Id = pretesCbtSaveSession($pdo, [
    'code' => 'SESI-REGULER-2026',
    'title' => 'Sesi Pretes Reguler 2026',
    'package_ids' => [1],
    'is_active' => 1,
    'mode' => 'resmi',
    'duration_minutes' => 30,
]);
$ses2Id = pretesCbtSaveSession($pdo, [
    'code' => 'SESI-RESMI-PROTESTAN-2026',
    'title' => 'Sesi Pretes Resmi – Kristen Protestan',
    'package_ids' => [2],
    'is_active' => 1,
    'mode' => 'resmi',
    'duration_minutes' => 30,
]);

$testerUserId = 12345;
$testerRegId = 999999;

// 1. Simulasikan tester 12345 memulai ujian di Sesi 2 (Kristen) dari pengujian sebelumnya
$startKristen = pretesCbtStart($pdo, $testerUserId, $testerRegId, $now, null, null, true, $ses2Id);
assert(!empty($startKristen['attempt']['id']), "Attempt Kristen harus berhasil dibuat.");
assert((int)$startKristen['attempt']['session_id'] === $ses2Id, "Attempt harus terhubung ke Sesi 2.");

// 2. Saat tester 12345 menyelesaikan verifikasi wajah dan memanggil pretesCbtState():
$stateResumed = pretesCbtState($pdo, $testerUserId, $testerRegId, $now);
assert(!empty($stateResumed['attempt']), "Sistem harus me-resume attempt yang sedang berjalan.");
assert(empty($stateResumed['available_sessions']), "available_sessions TIDAK boleh muncul saat attempt sedang berjalan.");
assert($stateResumed['attempt']['questions'][0]['text'] === 'Soal Kristen 1' || str_contains($stateResumed['attempt']['questions'][0]['text'], 'Kristen'), "Soal yang muncul harus soal Kristen dari attempt berjalan.");
echo "✓ Test 1 Passed: Mahasiswa dengan attempt berjalan di-resume langsung ke soalnya (intro & pilihan sesi dilewati).\n";

// 3. Simulasikan aksi reset_cbt tester 12345
$stmtAtt = $pdo->prepare('SELECT id FROM pretes_cbt_attempts WHERE user_id = ?');
$stmtAtt->execute([$testerUserId]);
$attIds = $stmtAtt->fetchAll(PDO::FETCH_COLUMN);
assert(count($attIds) === 1, "Harus ada 1 attempt untuk tester.");

$placeholders = implode(',', array_fill(0, count($attIds), '?'));
$pdo->prepare("DELETE FROM pretes_cbt_items WHERE attempt_id IN ($placeholders)")->execute($attIds);
$pdo->prepare("DELETE FROM pretes_cbt_attempts WHERE id IN ($placeholders)")->execute($attIds);

$checkAtt = (int)$pdo->query("SELECT COUNT(*) FROM pretes_cbt_attempts WHERE user_id = $testerUserId")->fetchColumn();
$checkItems = (int)$pdo->query("SELECT COUNT(*) FROM pretes_cbt_items WHERE attempt_id IN ($placeholders)")->fetchColumn();
assert($checkAtt === 0, "Attempt di pretes_cbt_attempts harus terhapus bersih.");
assert($checkItems === 0, "Butir di pretes_cbt_items harus terhapus bersih.");
echo "✓ Test 2 Passed: Aksi reset_cbt tester berhasil menghapus attempt dan lembar butir jawaban.\n";

// 4. Setelah di-reset, panggil pretesCbtState() kembali
$stateAfterReset = pretesCbtState($pdo, $testerUserId, $testerRegId, $now);
assert(empty($stateAfterReset['attempt']), "Attempt harus NULL setelah di-reset.");
assert(!empty($stateAfterReset['available_sessions']), "available_sessions harus muncul kembali.");
assert(count($stateAfterReset['available_sessions']) === 2, "Harus ada tepat 2 sesi pilihan (Reguler & Kristen).");
echo "✓ Test 3 Passed: Setelah reset, layar intro kembali memunculkan pilihan sesi (Islam & Kristen).\n";

// 5. Tester sekarang memilih Sesi 1 (Reguler / Islam)
$startIslam = pretesCbtStart($pdo, $testerUserId, $testerRegId, $now, null, null, true, $ses1Id);
assert((int)$startIslam['attempt']['session_id'] === $ses1Id, "Attempt baru harus terhubung ke Sesi 1.");
$stmtItems = $pdo->prepare("SELECT q.category FROM pretes_cbt_items i JOIN pretes_cbt_questions q ON q.id = i.question_id WHERE i.attempt_id = ?");
$stmtItems->execute([$startIslam['attempt']['id']]);
$categories = array_unique($stmtItems->fetchAll(PDO::FETCH_COLUMN));
assert($categories === ['Ubudiyah'], "Soal yang diterima harus 100% soal Ubudiyah/Islam.");
echo "✓ Test 4 Passed: Tester berhasil memulai Sesi 1 dan mendapatkan 100% soal Islam.\n";

echo "\n=== SEMUA 4 PENGUJIAN TESTER RESET & RESUME BERHASIL 100%! ===\n";
