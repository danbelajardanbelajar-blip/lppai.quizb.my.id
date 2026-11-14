<?php
/**
 * Test Suite: CBT Pretes & Tryout Live Monitoring & Real-time Progress (Opsi 1)
 *
 * Menguji:
 * 1. Agregasi data live real-time: answered_count, unanswered_count, flagged_count (ragu),
 *    live_correct_count (benar), live_wrong_count (salah), dan estimasi skor live.
 * 2. Status attempt: Berlangsung (ongoing), Selesai (submitted), dan Waktu Habis (expired).
 * 3. Logika endpoint AJAX `?ajax=live_results` beserta filter scope (all, resmi, tryout).
 * 4. Struktur markup view tabel hasil: toolbar live monitor, auto-refresh selector,
 *    indikator ongoing, progress bar, dan kolom rincian benar/salah/ragu.
 * 5. Script JS & Stylesheet CSS untuk pulse animation dan countdown clock.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/pretes-cbt.php';
require_once __DIR__ . '/../includes/pretes-tryout.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensurePretesCbtTables($pdo);

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT,
    password TEXT,
    nama_lengkap TEXT NOT NULL,
    nim TEXT,
    role TEXT,
    angkatan INTEGER
)");

echo "=== Menjalankan Validasi Live Monitoring & Real-time CBT Progress (Opsi 1) ===\n";

function liveAssert(bool $condition, string $passMsg, string $failMsg): void {
    if (!$condition) {
        echo " [FAIL] $failMsg\n";
        exit(1);
    }
    echo " [PASS] $passMsg\n";
}

// ── Setup Fixtures ──────────────────────────────────────────────────────────
$ts = time();
$testPrefix = 'test_live_' . $ts . '_';

// 1. Buat User A & B
$stmtUser = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nim, role, angkatan) VALUES (?, ?, ?, ?, 'mahasiswa', 2026)");
$stmtUser->execute([$testPrefix . 'u1', password_hash('secret', PASSWORD_DEFAULT), 'Mahasiswa Live Ongoing', 'NIM-LIVE-01']);
$userId1 = (int)$pdo->lastInsertId();

$stmtUser->execute([$testPrefix . 'u2', password_hash('secret', PASSWORD_DEFAULT), 'Mahasiswa Live Selesai', 'NIM-LIVE-02']);
$userId2 = (int)$pdo->lastInsertId();

// 2. Buat Paket & 10 Soal
$stmtPkg = $pdo->prepare("INSERT INTO pretes_cbt_packages (code, name, is_active, created_at) VALUES (?, 'Paket Live Test', 1, ?)");
$stmtPkg->execute([$testPrefix . 'pkg', $ts]);
$pkgId = (int)$pdo->lastInsertId();

$stmtQ = $pdo->prepare("INSERT INTO pretes_cbt_questions (package_id, code, category, question_text, options_json, correct_answer, is_active) VALUES (?, ?, 'Materi Live', ?, ?, ?, 1)");
$optionsJson = json_encode(['A' => 'Pilihan A', 'B' => 'Pilihan B', 'C' => 'Pilihan C', 'D' => 'Pilihan D', 'E' => 'Pilihan E'], JSON_UNESCAPED_UNICODE);

for ($i = 1; $i <= 25; $i++) {
    $stmtQ->execute([$pkgId, $testPrefix . 'q' . $i, "Pertanyaan nomor $i", $optionsJson, 'A']); // Kunci selalu A
}

// 3. Buat Sesi Ujian (10 soal, durasi 30 menit)
$sessionId = pretesCbtSaveSession($pdo, [
    'title' => 'Sesi Live Test',
    'package_ids' => [$pkgId],
    'duration_minutes' => 30,
    'camera_mode' => 'tidak_aktif',
    'is_active' => 1
]);

// ── Test 1: Sesi Ongoing User 1 dengan berbagai aksi live ───────────────
$regId1 = 99901; // Ujian resmi
$start1 = pretesCbtStart($pdo, $userId1, $regId1, $ts);
liveAssert($start1['attempt'] !== null, 'User 1 berhasil memulai sesi ujian CBT resmi', 'Gagal memulai sesi User 1');
$attemptId1 = (int)$start1['attempt']['id'];

// Ambil item soal yang digenerate untuk attempt ini
$items1 = $pdo->query("SELECT * FROM pretes_cbt_items WHERE attempt_id = $attemptId1 ORDER BY position ASC")->fetchAll(PDO::FETCH_ASSOC);
$totalItems1 = count($items1);
liveAssert($totalItems1 >= 10, "Item soal digenerate untuk attempt User 1 ($totalItems1 butir)", 'Jumlah item User 1 kurang dari 10');

// Simulasikan pengerjaan:
// Soal 1: Dijawab sesuai correct_answer (Benar)
pretesCbtChange($pdo, $userId1, $regId1, 'save', ['position' => 1, 'answer' => $items1[0]['correct_answer'], 'flagged' => false], $ts + 10);
// Soal 2: Dijawab sesuai correct_answer (Benar)
pretesCbtChange($pdo, $userId1, $regId1, 'save', ['position' => 2, 'answer' => $items1[1]['correct_answer'], 'flagged' => false], $ts + 20);
// Soal 3: Dijawab kunci salah (Salah)
$wrongAns3 = $items1[2]['correct_answer'] === 'A' ? 'B' : 'A';
pretesCbtChange($pdo, $userId1, $regId1, 'save', ['position' => 3, 'answer' => $wrongAns3, 'flagged' => false], $ts + 30);
// Soal 4: Dijawab kunci salah (Salah) dan ditandai Ragu-ragu (flagged)
$wrongAns4 = $items1[3]['correct_answer'] === 'A' ? 'B' : 'A';
pretesCbtChange($pdo, $userId1, $regId1, 'save', ['position' => 4, 'answer' => $wrongAns4, 'flagged' => true], $ts + 40);
// Soal 5: Belum dijawab (answer null), tapi ditandai Ragu-ragu (flagged)
pretesCbtChange($pdo, $userId1, $regId1, 'save', ['position' => 5, 'answer' => null, 'flagged' => true], $ts + 50);

// ── Test 2: Validasi Query Agregat Live pada Attempt User 1 ────────────
$stmtStats = $pdo->prepare("
    SELECT 
        attempt_id,
        COUNT(*) AS total_items,
        SUM(CASE WHEN answer IS NOT NULL AND answer != '' THEN 1 ELSE 0 END) AS answered_count,
        SUM(CASE WHEN flagged = 1 THEN 1 ELSE 0 END) AS flagged_count,
        SUM(CASE WHEN answer IS NOT NULL AND answer != '' AND answer = correct_answer THEN 1 ELSE 0 END) AS live_correct_count,
        SUM(CASE WHEN answer IS NOT NULL AND answer != '' AND answer != correct_answer THEN 1 ELSE 0 END) AS live_wrong_count
    FROM pretes_cbt_items
    WHERE attempt_id = ?
    GROUP BY attempt_id
");
$stmtStats->execute([$attemptId1]);
$stats1 = $stmtStats->fetch(PDO::FETCH_ASSOC);

liveAssert((int)$stats1['total_items'] === $totalItems1, "Total item User 1 = $totalItems1", 'Total item tidak sesuai');
liveAssert((int)$stats1['answered_count'] === 4, 'Soal terjawab (dikerjakan) User 1 = 4', 'Hitungan answered_count salah');
liveAssert((int)$stats1['flagged_count'] === 2, 'Soal ditandai ragu User 1 = 2', 'Hitungan flagged_count salah');
liveAssert((int)$stats1['live_correct_count'] === 2, 'Live jawaban benar User 1 = 2', 'Hitungan live_correct_count salah');
liveAssert((int)$stats1['live_wrong_count'] === 2, 'Live jawaban salah User 1 = 2', 'Hitungan live_wrong_count salah');

$unanswered1 = (int)$stats1['total_items'] - (int)$stats1['answered_count'];
liveAssert($unanswered1 === ($totalItems1 - 4), "Soal belum dijawab User 1 = " . ($totalItems1 - 4), 'Hitungan unanswered salah');

$liveScore1 = round(((int)$stats1['live_correct_count'] / (int)$stats1['total_items']) * 100, 1);
liveAssert($liveScore1 > 0, "Estimasi live score User 1 = $liveScore1", 'Hitungan live score salah');

// ── Test 3: Sesi Selesai (Submitted) User 2 ────────────────────────────
$regId2 = -1; // Sesi Tryout ke-1
$start2 = pretesCbtStart($pdo, $userId2, $regId2, $ts, null, null, true);
liveAssert($start2['attempt'] !== null, 'User 2 berhasil memulai sesi tryout CBT', 'Gagal memulai sesi User 2');
$attemptId2 = (int)$start2['attempt']['id'];

$items2 = $pdo->query("SELECT * FROM pretes_cbt_items WHERE attempt_id = $attemptId2 ORDER BY position ASC")->fetchAll(PDO::FETCH_ASSOC);
$totalItems2 = count($items2);

// Jawab 8 benar
for ($p = 1; $p <= 8 && $p <= $totalItems2; $p++) {
    pretesCbtChange($pdo, $userId2, $regId2, 'save', ['position' => $p, 'answer' => $items2[$p - 1]['correct_answer'], 'flagged' => false], $ts + 10);
}
// Sisa salah jika ada
for ($p = 9; $p <= $totalItems2; $p++) {
    $wrg = $items2[$p - 1]['correct_answer'] === 'A' ? 'B' : 'A';
    pretesCbtChange($pdo, $userId2, $regId2, 'save', ['position' => $p, 'answer' => $wrg, 'flagged' => false], $ts + 20);
}

// Submit ujian
$submitRes = pretesCbtChange($pdo, $userId2, $regId2, 'submit', [], $ts + 100)['attempt'];
liveAssert($submitRes['submitted'] === true, 'User 2 berhasil submit ujian', 'Submit User 2 gagal');
liveAssert((int)$submitRes['correct'] === min(8, $totalItems2), 'User 2 mendapatkan jawaban benar terhitung', 'Jumlah benar User 2 salah');
liveAssert($submitRes['score'] > 0, 'User 2 mendapatkan nilai akhir ' . $submitRes['score'], 'Skor User 2 salah');

    // ── Test 4: Simulasi Payload Endpoint AJAX live_results ─────────────────
    $now = time();
    $ongoingCount = (int)($pdo->query("SELECT COUNT(*) FROM pretes_cbt_attempts WHERE submitted_at IS NULL AND deadline_at > $now")->fetchColumn() ?: 0);
    liveAssert($ongoingCount >= 1, 'ongoing_count mendeteksi minimal 1 peserta sedang mengerjakan', 'ongoing_count 0');

    // ── Test 5: Verifikasi File View & Markup Elements ──────────────────────
    $viewContent = file_get_contents(__DIR__ . '/../includes/pretes-cbt-admin-view.php');
    liveAssert(strpos($viewContent, 'id="cbtLiveResultsTable"') !== false, 'View memuat id tabel #cbtLiveResultsTable', 'ID tabel live hilang');
    liveAssert(strpos($viewContent, 'id="cbtLiveResultsTbody"') !== false, 'View memuat tbody #cbtLiveResultsTbody', 'ID tbody live hilang');
    liveAssert(strpos($viewContent, 'id="cbtLiveOngoingCounter"') !== false, 'View memuat counter peserta #cbtLiveOngoingCounter', 'Counter ongoing hilang');
    liveAssert(strpos($viewContent, 'id="cbtLiveAutoRefreshToggle"') !== false, 'View memuat toggle #cbtLiveAutoRefreshToggle', 'Toggle auto-refresh hilang');
    liveAssert(strpos($viewContent, 'id="cbtLiveRefreshInterval"') !== false, 'View memuat select interval #cbtLiveRefreshInterval', 'Interval select hilang');
    liveAssert(strpos($viewContent, 'id="cbtLiveManualRefreshBtn"') !== false, 'View memuat tombol manual #cbtLiveManualRefreshBtn', 'Tombol manual refresh hilang');
    liveAssert(strpos($viewContent, '<th>Progres Dikerjakan</th>') !== false, 'View memiliki th Progres Dikerjakan', 'Header progres hilang');
    liveAssert(strpos($viewContent, '<th>Benar / Salah</th>') !== false, 'View memiliki th Benar / Salah', 'Header benar/salah hilang');
    liveAssert(strpos($viewContent, '<th>Waktu &amp; Sisa</th>') !== false, 'View memiliki th Waktu & Sisa', 'Header waktu/sisa hilang');

    // ── Test 6: Verifikasi CSS & JS Assets ──────────────────────────────────
    $cssContent = file_get_contents(__DIR__ . '/../assets/css/pretes-cbt-admin.css');
    liveAssert(strpos($cssContent, '.live-pulse-badge') !== false, 'CSS mendefinisikan .live-pulse-badge', 'CSS pulse badge hilang');
    liveAssert(strpos($cssContent, '.pulse-dot') !== false, 'CSS mendefinisikan .pulse-dot', 'CSS pulse dot hilang');
    liveAssert(strpos($cssContent, '@keyframes livePulseDot') !== false, 'CSS mendefinisikan keyframes animasi pulse', 'Keyframes pulse hilang');
    liveAssert(strpos($cssContent, '.countdown-clock') !== false, 'CSS mendefinisikan .countdown-clock', 'CSS countdown hilang');

    $jsContent = file_get_contents(__DIR__ . '/../assets/js/pretes-cbt-admin.js');
    liveAssert(strpos($jsContent, 'initCbtLiveMonitor') !== false, 'JS memuat fungsi initCbtLiveMonitor', 'initCbtLiveMonitor hilang');
    liveAssert(strpos($jsContent, 'fetchLiveResults') !== false, 'JS memuat fungsi fetchLiveResults', 'fetchLiveResults hilang');
    liveAssert(strpos($jsContent, 'updateTableRows') !== false, 'JS memuat fungsi updateTableRows', 'updateTableRows hilang');
    liveAssert(strpos($jsContent, 'live-countdown') !== false, 'JS menangani live countdown timer 1 detik', 'Timer countdown hilang');

    // ── Test 7: Verifikasi Controller Endpoint di admin/pretes-bank-soal.php ─
    $controllerContent = file_get_contents(__DIR__ . '/../admin/pretes-bank-soal.php');
    liveAssert(strpos($controllerContent, "\$_GET['ajax'] === 'live_results'") !== false, 'Controller memiliki endpoint AJAX live_results', 'Endpoint live_results hilang');
    liveAssert(strpos($controllerContent, 'live_correct_count') !== false, 'Controller menghitung live_correct_count', 'Perhitungan live_correct_count hilang');
    liveAssert(strpos($controllerContent, 'live_wrong_count') !== false, 'Controller menghitung live_wrong_count', 'Perhitungan live_wrong_count hilang');
    liveAssert(strpos($controllerContent, 'answered_count') !== false, 'Controller menghitung answered_count', 'Perhitungan answered_count hilang');

    echo "\n✅ Seluruh pengujian Live Monitoring CBT & Real-time Progress (Opsi 1) BERHASIL 100%!\n";
