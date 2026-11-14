<?php
/** Test tools/import-tryout-mudah.php + data/pretes-tryout-mudah.php (SQLite, mesin CBT asli). */
if (session_status() !== PHP_SESSION_ACTIVE) { $_SESSION = []; }
define('LPPAI_IMPORT_TRYOUT_LIB', true);
require __DIR__ . '/../tools/import-tryout-mudah.php';

$rows = require __DIR__ . '/../data/pretes-tryout-mudah.php';
$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };

// Validasi data soal
$check(count($rows) === 70, '70 soal');
$cats = []; $keys = []; $texts = [];
foreach ($rows as [$code, $cat, $q, $opts, $key]) {
    $cats[$cat] = ($cats[$cat] ?? 0) + 1; $keys[$key] = ($keys[$key] ?? 0) + 1;
    $check(preg_match('/^TRY-MDH-\d{3}$/', $code) === 1, "Kode $code valid");
    $check(count($opts) === 5 && count(array_unique(array_map('mb_strtolower', $opts))) === 5, "$code: 5 pilihan berbeda");
    $check(in_array($key, ['A', 'B', 'C', 'D', 'E'], true), "$code: kunci A–E");
    $check(trim($q) !== '' && mb_strlen($cat) <= 80, "$code: soal & kategori");
    $texts[] = mb_strtolower(trim($q));
}
$check(count($cats) === 11, '11 tema (ditemukan ' . count($cats) . ')');
$check(min($cats) >= 6 && max($cats) <= 7, 'Sebaran 6–7 soal per tema');
$check(count($keys) === 5 && min($keys) === 14, 'Kunci tersebar merata (14 per huruf)');
$check(count(array_unique($texts)) === 70, 'Tidak ada soal ganda');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensurePretesCbtTables($pdo);
$activePkgBefore = (int)$pdo->query('SELECT id FROM pretes_cbt_packages WHERE is_active = 1')->fetchColumn();
$resmiBefore = (int)(pretesCbtGetActiveSession($pdo)['id'] ?? 0);

$r1 = lppaiImportTryoutMudah($pdo, $rows, true);
$check($r1['added'] === 70 && $r1['updated'] === 0 && $r1['session_created'], 'Impor pertama: 70 ditambahkan + sesi dibuat');
$r2 = lppaiImportTryoutMudah($pdo, $rows, true);
$check($r2['added'] === 0 && $r2['updated'] === 70 && !$r2['session_created'] && $r2['session_id'] === $r1['session_id'], 'Impor ulang idempoten');

$pkgId = $r1['package_id'];
$check((int)$pdo->query("SELECT COUNT(*) FROM pretes_cbt_questions WHERE package_id = $pkgId AND is_active = 1")->fetchColumn() === 70, 'Paket tryout berisi 70 soal aktif');
$check((int)$pdo->query("SELECT COUNT(DISTINCT category) FROM pretes_cbt_questions WHERE package_id = $pkgId")->fetchColumn() === 11, '11 kategori di paket');
$check((int)$pdo->query('SELECT id FROM pretes_cbt_packages WHERE is_active = 1')->fetchColumn() === $activePkgBefore, 'Paket aktif resmi tidak berubah');
$check((int)(pretesCbtGetActiveSession($pdo)['id'] ?? 0) === $resmiBefore, 'Sesi resmi aktif tidak berubah');

$ses = pretesTryoutGetSession($pdo, (int)$r1['session_id']);
$check($ses && (int)$ses['is_active'] === 1 && $ses['max_attempts'] === 0 && $ses['camera_mode'] === 'tidak_aktif' && (int)$ses['duration_minutes'] === 70, 'Sesi tryout aktif, ulang ∞, tanpa kamera, 70 menit');
$check(count(pretesTryoutActiveSessions($pdo)) === 1, 'Sesi tryout tampil di daftar mahasiswa');

// Mulai tryout → 70 soal dari paket tryout, kunci tetap benar setelah pilihan diacak
$reg = pretesTryoutResolveRegId($pdo, 9, $ses);
$st = pretesCbtStart($pdo, 9, $reg, 5000000, null, $ses);
$check(count($st['attempt']['questions']) === 70, 'Tryout berisi 70 soal');
$attemptId = (int)$st['attempt']['id'];
$byCode = [];
foreach ($rows as [$code, , , $opts, $key]) $byCode[$code] = $opts[ord($key) - 65];
$bad = 0;
foreach ($pdo->query("SELECT i.options_json, i.correct_answer, q.code FROM pretes_cbt_items i JOIN pretes_cbt_questions q ON q.id = i.question_id WHERE i.attempt_id = $attemptId") as $it) {
    $o = json_decode($it['options_json'], true);
    if (($o[$it['correct_answer']] ?? null) !== $byCode[$it['code']]) $bad++;
}
$check($bad === 0, "Kunci jawaban tetap tepat setelah pilihan diacak ($bad salah)");
// Jawab semua benar → nilai 100
foreach ($pdo->query("SELECT position, correct_answer FROM pretes_cbt_items WHERE attempt_id = $attemptId")->fetchAll(PDO::FETCH_ASSOC) as $it) {
    $pdo->prepare('UPDATE pretes_cbt_items SET answer = ? WHERE attempt_id = ? AND position = ?')->execute([$it['correct_answer'], $attemptId, $it['position']]);
}
pretesCbtChange($pdo, 9, $reg, 'submit', ['revision' => 0], 5000100);
$check((float)$pdo->query("SELECT score FROM pretes_cbt_attempts WHERE id = $attemptId")->fetchColumn() === 100.0, 'Semua benar → nilai 100');

$check(strpos(file_get_contents(__DIR__ . '/../tools/import-tryout-mudah.php'), "PHP_SAPI !== 'cli'") !== false, 'Skrip impor hanya CLI');

if ($failures) { echo "FAIL:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
echo "PASS: 70 soal tryout mudah (11 tema) valid, impor idempoten, sesi tryout aktif, 70 soal & kunci tepat, nilai 100.\n";
