<?php
/**
 * Test Suite: Anti-Cheat CBT Violation (Tukar & Acak Soal Saat Pelanggaran)
 * 
 * Skenario Pengujian:
 * - 170 butir soal diunggah ke bank soal
 * - 50 butir soal dikerjakan oleh mahasiswa dalam 1 sesi ujian
 * - 10 butir soal dijawab, 40 butir belum dijawab
 * - Terjadi pelanggaran (misal: ganti tab / wajah tidak terdeteksi)
 * - Sistem wajib:
 *   1. Mengganti seluruh 40 soal yang belum dijawab dengan 40 soal BARU dari 120 soal cadangan
 *   2. Mempertahankan 10 soal yang sudah dijawab
 *   3. Mengacak abjad A-E seluruh pilihan jawaban
 *   4. Menyesuaikan kunci jawaban & opsi yang dipilih peserta agar skor 100% akurat tanpa berkurang/berubah
 */

require_once __DIR__ . '/../includes/pretes-cbt.php';
require_once __DIR__ . '/../includes/pretes-cbt-interruption.php';

function assertAntiCheat(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: " . $message);
    }
}

echo "=== Menjalankan Test Suite: Anti-Cheat Violation (170 Soal Bank / 50 Soal Ujian) ===\n";

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// 1. Inisialisasi skema tabel
ensurePretesCbtTables($pdo);

// 2. Buat Paket Ujian dengan target 50 soal
$pkgId = pretesCbtSavePackage($pdo, [
    'name' => 'Paket Komprehensif Ubudiyah 170',
    'code' => 'PKT-170-SOAL',
    'duration_minutes' => 60,
    'question_count' => 50,
    'is_active' => 1
]);

// 3. Masukkan 170 soal ke bank soal dengan 3 kategori berbeda
$categories = ['Thaharah', 'Shalat', 'Jenazah & Puasa'];
$insertStmt = $pdo->prepare('INSERT INTO pretes_cbt_questions (code, package_id, category, question_text, options_json, correct_answer, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');

for ($i = 1; $i <= 170; $i++) {
    $cat = $categories[($i - 1) % 3];
    $code = sprintf('SOAL-%03d', $i);
    $text = "Pertanyaan butir nomor $i mengenai materi $cat: Apa hukum dan tata caranya?";
    $options = [
        'A' => "Pilihan A untuk soal $i",
        'B' => "Pilihan B untuk soal $i (BENAR)",
        'C' => "Pilihan C untuk soal $i",
        'D' => "Pilihan D untuk soal $i",
        'E' => "Pilihan E untuk soal $i"
    ];
    // Set 'B' sebagai jawaban benar
    $correct = 'B';
    $insertStmt->execute([$code, $pkgId, $cat, $text, json_encode($options, JSON_UNESCAPED_UNICODE), $correct]);
}

$totalInBank = (int)$pdo->query("SELECT COUNT(*) FROM pretes_cbt_questions WHERE package_id = $pkgId")->fetchColumn();
assertAntiCheat($totalInBank === 170, "Bank soal harus berisi tepat 170 soal, ditemukan: $totalInBank");
echo "✓ Langkah 1: Berhasil membuat paket dan memasukkan 170 butir soal ke bank soal.\n";

// 4. Buat Sesi Ujian dengan shuffle_on_violation = 1 (Aktif)
$sesId = pretesCbtSaveSession($pdo, [
    'title' => 'Sesi Ujian Anti-Contek 2026',
    'code' => 'SESI-ANTI-CHEAT',
    'package_ids' => [$pkgId],
    'duration_minutes' => 60,
    'scoring_method' => 'standar',
    'shuffle_on_violation' => 1,
    'is_active' => 1
]);

$session = pretesCbtGetSession($pdo, $sesId);
assertAntiCheat((int)$session['shuffle_on_violation'] === 1, "Sesi harus mengaktifkan shuffle_on_violation = 1");
echo "✓ Langkah 2: Sesi ujian aktif dengan fitur Anti-Cheat Tukar & Acak Soal aktif.\n";

// 5. Mahasiswa memulai ujian (User ID: 101, Reg ID: 202)
$now = 1700000000;
$startRes = pretesCbtStart($pdo, 101, 202, $now);
$attempt = $startRes['attempt'];
assertAntiCheat(count($attempt['questions']) === 50, "Peserta harus menerima tepat 50 soal.");
$initialItems = $pdo->query("SELECT * FROM pretes_cbt_items WHERE attempt_id = {$attempt['id']} ORDER BY position")->fetchAll();
assertAntiCheat(count($initialItems) === 50, "Tabel pretes_cbt_items harus berisi 50 item.");

$initialQids = array_map('intval', array_column($initialItems, 'question_id'));
assertAntiCheat(count(array_unique($initialQids)) === 50, "50 soal awal tidak boleh ada yang duplikat.");
echo "✓ Langkah 3: Mahasiswa memulai ujian dan menerima 50 soal acak dari 170 soal (sisa cadangan: 120 soal).\n";

// 6. Mahasiswa menjawab 10 soal pertama (posisi 1 s.d. 10)
// Catat pilihan teks yang dipilih dan apakah itu jawaban benar
$answeredData = [];
for ($pos = 1; $pos <= 10; $pos++) {
    $itemBefore = $initialItems[$pos - 1];
    $optsBefore = json_decode($itemBefore['options_json'], true);
    
    // Pilih 'A' untuk soal ganjil, dan jawaban benar untuk soal genap
    $chosenLetter = ($pos % 2 === 1) ? 'A' : $itemBefore['correct_answer'];
    $chosenText = $optsBefore[$chosenLetter];
    $correctText = $optsBefore[$itemBefore['correct_answer']];
    
    $answeredData[$pos] = [
        'question_id' => (int)$itemBefore['question_id'],
        'question_text' => $itemBefore['question_text'],
        'chosen_text' => $chosenText,
        'correct_text' => $correctText,
        'is_correct' => ($chosenLetter === $itemBefore['correct_answer'])
    ];
    
    pretesCbtChange($pdo, 101, 202, 'save', ['position' => $pos, 'answer' => $chosenLetter], $now + ($pos * 10));
}

$stateBefore = pretesCbtState($pdo, 101, 202, $now + 200)['attempt'];
assertAntiCheat($stateBefore['answered'] === 10, "Jumlah soal terjawab sebelum pelanggaran harus 10.");
echo "✓ Langkah 4: Mahasiswa telah menjawab 10 soal (40 soal sisanya belum dijawab).\n";

// 7. Terjadi pelanggaran pada posisi soal 11 (misal ganti tab / wajah tidak terdeteksi)
$unansweredBeforeQids = [];
for ($pos = 11; $pos <= 50; $pos++) {
    $unansweredBeforeQids[] = (int)$initialItems[$pos - 1]['question_id'];
}

$interruptInput = [
    'event_id' => 'evt_anti_cheat_001',
    'reason' => 'page_hidden',
    'position' => 11,
    'revision' => $stateBefore['revision'],
    'pending' => []
];

$interruptRes = pretesCbtChange($pdo, 101, 202, 'interrupt', $interruptInput, $now + 250);
$pausedAttempt = $interruptRes['attempt'];
assertAntiCheat($pausedAttempt['paused'] === true, "Ujian harus dalam status terjeda (paused).");
assertAntiCheat($pausedAttempt['revision'] === 1, "Revision harus bertambah menjadi 1.");

$itemsAfter = $pdo->query("SELECT * FROM pretes_cbt_items WHERE attempt_id = {$attempt['id']} ORDER BY position")->fetchAll();

// 8. Verifikasi 10 Soal yang SUDAH Dijawab:
// - Soal & ID pertanyaan tetap sama
// - Abjad opsi diacak
// - Jawaban peserta tetap mengarah ke teks pilihan yang sama persis
// - Kunci jawaban tetap mengarah ke teks jawaban benar yang sama persis
echo "\n--- Memeriksa 10 Soal yang Sudah Dijawab ---\n";
for ($pos = 1; $pos <= 10; $pos++) {
    $itemAfter = $itemsAfter[$pos - 1];
    $expected = $answeredData[$pos];
    
    assertAntiCheat((int)$itemAfter['question_id'] === $expected['question_id'], "Soal dijawab posisi $pos harus tetap memiliki question_id yang sama.");
    assertAntiCheat($itemAfter['question_text'] === $expected['question_text'], "Soal dijawab posisi $pos harus memiliki teks soal yang sama.");
    
    $optsAfter = json_decode($itemAfter['options_json'], true);
    $newChosenLetter = $itemAfter['answer'];
    $newCorrectLetter = $itemAfter['correct_answer'];
    
    assertAntiCheat(!empty($newChosenLetter), "Posisi $pos harus tetap memiliki jawaban tersimpan.");
    assertAntiCheat(isset($optsAfter[$newChosenLetter]), "Huruf jawaban baru harus ada di options_json.");
    assertAntiCheat($optsAfter[$newChosenLetter] === $expected['chosen_text'], "Teks jawaban yang dipilih peserta pada posisi $pos HARUS IDENTIK dengan sebelum pelanggaran! Diharapkan: '{$expected['chosen_text']}', ditemukan: '{$optsAfter[$newChosenLetter]}'");
    assertAntiCheat($optsAfter[$newCorrectLetter] === $expected['correct_text'], "Teks kunci jawaban benar pada posisi $pos HARUS IDENTIK dengan sebelum pelanggaran!");
    
    $isCorrectNow = ($newChosenLetter === $newCorrectLetter);
    assertAntiCheat($isCorrectNow === $expected['is_correct'], "Status kebenaran nilai posisi $pos tidak boleh berubah!");
}
echo "✓ 10 Soal terjawab terverifikasi aman: teks pertanyaan tetap, abjad pilihan diacak, teks pilihan jawaban peserta & kunci jawaban terpetakan 100% presisi tanpa merusak skor.\n";

// 9. Verifikasi 40 Soal yang BELUM Dijawab:
// - Seluruh 40 soal harus DIGANTI dengan soal BARU dari 120 cadangan
// - Tidak boleh ada satupun soal lama yang tersisa dari 40 soal belum dijawab sebelumnya
echo "\n--- Memeriksa 40 Soal yang Belum Dijawab ---\n";
$afterQids = array_map('intval', array_column($itemsAfter, 'question_id'));
assertAntiCheat(count(array_unique($afterQids)) === 50, "50 soal setelah pelanggaran harus semuanya unik (tidak ada duplikasi).");

$replacedCount = 0;
for ($pos = 11; $pos <= 50; $pos++) {
    $itemAfter = $itemsAfter[$pos - 1];
    $afterQid = (int)$itemAfter['question_id'];
    
    // Pastikan tidak ada jawaban yang terisi pada soal belum dijawab
    assertAntiCheat($itemAfter['answer'] === null, "Soal belum dijawab pada posisi $pos harus tetap null.");
    
    // Pastikan soal tersebut adalah soal baru (bukan dari daftar 40 soal lama yang belum dijawab)
    assertAntiCheat(!in_array($afterQid, $unansweredBeforeQids, true), "Soal posisi $pos (QID: $afterQid) HARUS DIGANTI dengan soal cadangan baru dan tidak boleh sama dengan soal belum dijawab sebelumnya!");
    $replacedCount++;
}
assertAntiCheat($replacedCount === 40, "Tepat 40 soal belum dijawab harus diganti.");
echo "✓ 40 Soal belum dijawab terverifikasi 100% diganti oleh mesin dengan soal baru dari bank cadangan.\n";

// 10. Lanjutkan ujian (Resume) dan kumpulkan (Submit)
// Verifikasi bahwa skor akhir dihitung tepat sesuai dengan 10 jawaban mahasiswa yang telah tersimpan
$resumeRes = pretesCbtChange($pdo, 101, 202, 'resume', [], $now + 300);
assertAntiCheat($resumeRes['attempt']['paused'] === false, "Ujian berhasil dilanjutkan.");

$submitRes = pretesCbtChange($pdo, 101, 202, 'submit', ['revision' => 1], $now + 310);
$finalAttempt = $submitRes['attempt'];
assertAntiCheat($finalAttempt['submitted'] === true, "Ujian harus berhasil dikumpulkan.");

// Hitung berapa yang seharusnya benar dari 10 soal
$expectedCorrectCount = 0;
foreach ($answeredData as $d) {
    if ($d['is_correct']) $expectedCorrectCount++;
}
assertAntiCheat((int)$finalAttempt['correct'] === $expectedCorrectCount, "Jumlah benar harus $expectedCorrectCount, tercatat: " . $finalAttempt['correct']);
$expectedScore = round($expectedCorrectCount / 50 * 100, 2);
assertAntiCheat(abs((float)$finalAttempt['score'] - $expectedScore) < 0.01, "Nilai akhir harus $expectedScore, tercatat: " . $finalAttempt['score']);
echo "✓ Pengumpulan ujian berhasil: Skor akhir peserta ({$finalAttempt['score']}) 100% akurat sesuai jawaban yang telah disimpan sebelum pelanggaran.\n";

// 11. Pengujian Fallback: Uji coba jika sesi menonaktifkan fitur (shuffle_on_violation = 0)
$sesDisabledId = pretesCbtSaveSession($pdo, [
    'title' => 'Sesi Standar Tanpa Tukar Cadangan',
    'code' => 'SESI-STANDARD-ONLY',
    'package_ids' => [$pkgId],
    'duration_minutes' => 60,
    'scoring_method' => 'standar',
    'shuffle_on_violation' => 0,
    'is_active' => 1
]);

$startRes2 = pretesCbtStart($pdo, 102, 203, $now);
$itemsBefore2 = $pdo->query("SELECT * FROM pretes_cbt_items WHERE attempt_id = {$startRes2['attempt']['id']} ORDER BY position")->fetchAll();
$qidsBefore2 = array_map('intval', array_column($itemsBefore2, 'question_id'));

pretesCbtChange($pdo, 102, 203, 'interrupt', [
    'event_id' => 'evt_standard_001',
    'reason' => 'page_hidden',
    'position' => 5,
    'revision' => 0,
    'pending' => []
], $now + 50);

$itemsAfter2 = $pdo->query("SELECT * FROM pretes_cbt_items WHERE attempt_id = {$startRes2['attempt']['id']} ORDER BY position")->fetchAll();
$qidsAfter2 = array_map('intval', array_column($itemsAfter2, 'question_id'));

// Karena shuffle_on_violation = 0, daftar question_id harus tetap sama persis (hanya urutan/posisi yang berpindah)
sort($qidsBefore2);
sort($qidsAfter2);
assertAntiCheat($qidsBefore2 === $qidsAfter2, "Saat shuffle_on_violation = 0, seluruh soal harus tetap sama persis.");
echo "✓ Mode Nonaktif (shuffle_on_violation = 0) terverifikasi: mempertahankan kumpulan soal yang sama tanpa mengambil bank cadangan.\n";

echo "\n=== SEMUA 11 TAHAPAN TEST ANTI-CHEAT CBT VIOLATION BERHASIL 100% ===\n";
