<?php
/**
 * Test: filter Hasil Ujian CBT — hari (WIB), lulus/tidak lulus, pencarian.
 * Juga memastikan tiap placeholder bernama hanya muncul sekali (wajib untuk
 * PDO::ATTR_EMULATE_PREPARES => false di MySQL; penyebab pencarian error).
 */
declare(strict_types=1);

if (!defined('BASE_URL')) define('BASE_URL', 'https://lppai.quizb.my.id');

$fail = 0;
function t(bool $ok, string $msg): void {
    global $fail;
    echo ($ok ? ' [PASS] ' : '❌ [FAIL] ') . $msg . "\n";
    if (!$ok) $fail++;
}

require_once __DIR__ . '/../includes/pretes-cbt.php';
require_once __DIR__ . '/../includes/pretes-cbt-xlsx.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, nama_lengkap TEXT, nim TEXT, program_studi TEXT, jenis_program TEXT)');
$pdo->exec('CREATE TABLE pretes_cbt_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, registration_id INTEGER, session_id INTEGER DEFAULT 1, started_at INTEGER, deadline_at INTEGER, submitted_at INTEGER NULL, correct_count INTEGER NULL, score REAL NULL)');
$pdo->exec('CREATE TABLE pretes_cbt_items (id INTEGER PRIMARY KEY, attempt_id INTEGER)');
$pdo->exec('CREATE TABLE pretes_cbt_sessions (id INTEGER PRIMARY KEY, title TEXT)');

$tz = new DateTimeZone('Asia/Jakarta');
$d1 = (new DateTimeImmutable('2026-11-16 08:00:00', $tz))->getTimestamp();
$d1late = (new DateTimeImmutable('2026-11-16 23:59:30', $tz))->getTimestamp();
$d2 = (new DateTimeImmutable('2026-11-17 00:00:10', $tz))->getTimestamp();
$now = (new DateTimeImmutable('2026-11-17 09:00:00', $tz))->getTimestamp();

$users = [[1, 'budi', 'Budi Santoso', '2026001'], [2, 'siti', 'Siti Aminah', '2026002'], [3, 'rina', 'Rina 100%_Test', '2026003'], [4, 'andi', 'Andi', '2026004']];
foreach ($users as $u) $pdo->prepare('INSERT INTO users (id, username, nama_lengkap, nim) VALUES (?,?,?,?)')->execute($u);
$ins = $pdo->prepare('INSERT INTO pretes_cbt_attempts (user_id, registration_id, started_at, deadline_at, submitted_at, score) VALUES (?,?,?,?,?,?)');
$ins->execute([1, 10, $d1, $d1 + 3600, $d1 + 1000, 85.0]);      // 16 Nov, lulus, resmi
$ins->execute([2, 11, $d1late, $d1late + 3600, $d1late + 50, 60.0]); // 16 Nov 23:59 WIB, tidak lulus
$ins->execute([3, 12, $d2, $d2 + 3600, $d2 + 900, 80.0]);       // 17 Nov 00:00 WIB, lulus (batas 80)
$ins->execute([4, -1, $d2, $d2 + 3600, $d2 + 900, 40.0]);       // tryout, tidak lulus
$ins->execute([2, 13, $now - 60, $now + 3000, null, null]);     // live

function run(PDO $pdo, array $get, int $now): array {
    $f = pretesCbtResultFilters($get);
    [$where, $params] = pretesCbtResultWhere($f, $now);
    $st = $pdo->prepare("SELECT a.id FROM pretes_cbt_attempts a JOIN users u ON u.id = a.user_id $where ORDER BY a.id");
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->execute();
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// Normalisasi
$f = pretesCbtResultFilters(['day' => '2026-02-30', 'pass_filter' => 'x', 'status_filter' => 'zzz', 'q' => "  budi   s  "]);
t($f['day'] === '' && $f['pass_filter'] === 'all' && $f['status_filter'] === 'all' && $f['q'] === 'budi s', 'Input filter tidak valid dinormalkan, spasi kata kunci dirapikan');
$f = pretesCbtResultFilters(['status_filter' => 'live', 'pass_filter' => 'lulus']);
t($f['status_filter'] === 'finished', 'Filter lulus pada tab Live dialihkan ke sesi selesai');

$f = pretesCbtResultFilters(['status_filter' => 'live', 'day' => '2026-11-16']);
t($f['status_filter'] === 'all', 'Filter hari pada tab Live dialihkan ke Semua Sesi');

// Placeholder unik
$f = pretesCbtResultFilters(['status_filter' => 'finished', 'result_filter' => 'resmi', 'q' => 'x', 'day' => '2026-11-16', 'pass_filter' => 'lulus']);
[$where, $params] = pretesCbtResultWhere($f, $now);
preg_match_all('/:[a-z_0-9]+/', $where, $m);
t(count($m[0]) === count(array_unique($m[0])), 'Setiap placeholder bernama hanya dipakai sekali (aman untuk native prepare MySQL)');
t(count($m[0]) === count($params), 'Jumlah placeholder sama dengan jumlah parameter');

// Hari (batas WIB)
t(run($pdo, ['day' => '2026-11-16'], $now) === [1, 2], 'Filter hari 16 Nov memuat sesi 08:00 dan 23:59 WIB');
t(run($pdo, ['day' => '2026-11-17'], $now) === [3, 4, 5], 'Filter hari 17 Nov memuat sesi mulai 00:00 WIB');

// Lulus / tidak lulus
t(run($pdo, ['pass_filter' => 'lulus'], $now) === [1, 3], 'Filter Lulus: nilai >= 80 (batas 80 termasuk lulus)');
t(run($pdo, ['pass_filter' => 'tidak_lulus'], $now) === [2, 4], 'Filter Tidak Lulus: nilai < 80, sesi live tidak ikut');
t(run($pdo, ['pass_filter' => 'tidak_lulus', 'result_filter' => 'resmi'], $now) === [2], 'Filter Tidak Lulus + Ujian Resmi');
t(run($pdo, ['pass_filter' => 'lulus', 'day' => '2026-11-17'], $now) === [3], 'Kombinasi hari + lulus');

// Pencarian
t(run($pdo, ['q' => 'budi'], $now) === [1], 'Cari nama (case-insensitive)');
t(run($pdo, ['q' => '2026002'], $now) === [2, 5], 'Cari NIM');
t(run($pdo, ['q' => 'rina'], $now) === [3], 'Cari username/nama');
t(run($pdo, ['q' => '100%'], $now) === [3], 'Karakter % di kata kunci dicari apa adanya');
t(run($pdo, ['q' => '%'], $now) === [3], 'Kata kunci "%" tidak menjadi wildcard semua baris');

// Hitungan lulus/tidak lulus
$pc = pretesCbtResultPassCounts($pdo, pretesCbtResultFilters(['result_filter' => 'all']), $now);
t($pc === ['lulus' => 2, 'tidak_lulus' => 2], 'Hitungan Lulus/Tidak Lulus untuk semua tipe');
$pc = pretesCbtResultPassCounts($pdo, pretesCbtResultFilters(['day' => '2026-11-16', 'pass_filter' => 'lulus']), $now);
t($pc === ['lulus' => 1, 'tidak_lulus' => 1], 'Hitungan mengabaikan filter lulus tetapi mengikuti filter hari');

$pc = pretesCbtResultPassCounts($pdo, ['status_filter' => 'live', 'result_filter' => 'all', 'q' => '', 'day' => '', 'pass_filter' => 'all'], $now);
t($pc === ['lulus' => 2, 'tidak_lulus' => 2], 'Hitungan di tab Live tetap menghitung sesi selesai (sesuai tujuan klik chip)');

// Opsi hari
$opts = pretesCbtResultDayOptions($pdo, 'all');
t(count($opts) === 2 && $opts[0]['date'] === '2026-11-17' && $opts[0]['count'] === 3 && $opts[1]['count'] === 2, 'Opsi hari terurut terbaru dengan jumlah sesi');
t($opts[0]['label'] === 'Selasa, 17 Nov 2026', 'Label hari berbahasa Indonesia');
$opts = pretesCbtResultDayOptions($pdo, 'tryout');
t(count($opts) === 1 && $opts[0]['count'] === 1, 'Opsi hari mengikuti filter tipe');

// URL builder
$statusFilter = 'finished'; $resultFilter = 'resmi'; $searchQuery = 'budi'; $perPage = 25; $page = 1; $dayFilter = '2026-11-16'; $passFilter = 'lulus';
$url = buildCbtResultUrl(['page' => 2]);
t(strpos($url, 'day=2026-11-16') !== false && strpos($url, 'pass_filter=lulus') !== false, 'buildCbtResultUrl mempertahankan day dan pass_filter');
$url = buildCbtResultUrl(['day' => '', 'pass_filter' => 'all']);
t(strpos($url, 'day=') === false && strpos($url, 'pass_filter') === false, 'buildCbtResultUrl dapat menghapus day dan pass_filter');

// Ekspor .xlsx mengikuti filter
$exp = function (string $type, array $extra) use ($pdo): array {
    $rows = iterator_to_array(cbtResultsExportRows($pdo, $type, $extra), false);
    array_shift($rows);
    return array_map(fn($r) => (string)$r[0], $rows);
};
t($exp('all', []) === ['2026002', '2026003', '2026004', '2026002', '2026001'], 'Ekspor tanpa filter tambahan tetap memuat semua sesi');
t($exp('resmi', ['pass_filter' => 'lulus']) === ['2026003', '2026001'], 'Ekspor Resmi + Lulus');
t($exp('all', ['pass_filter' => 'tidak_lulus']) === ['2026004', '2026002'], 'Ekspor Tidak Lulus (sesi live tidak ikut)');
t($exp('resmi', ['day' => '2026-11-16']) === ['2026002', '2026001'], 'Ekspor mengikuti filter hari WIB');
t($exp('all', ['q' => 'budi']) === ['2026001'], 'Ekspor mengikuti pencarian');
t($exp('tryout', ['day' => '2026-11-17', 'pass_filter' => 'tidak_lulus']) === ['2026004'], 'Ekspor Tryout + hari + tidak lulus');

// Markup
$view = file_get_contents(__DIR__ . '/../includes/pretes-cbt-admin-view.php');
t(strpos($view, 'id="cbtDayFilter"') !== false && strpos($view, 'data-pass-filter="tidak_lulus"') !== false, 'View memuat filter hari dan kelulusan');
t(strpos($view, 'id="cbtResultSearchForm" data-no-spa') !== false, 'Form pencarian memakai navigasi penuh (data-no-spa)');
$ctrl = file_get_contents(__DIR__ . '/../admin/pretes-bank-soal.php');
t(strpos($ctrl, ':search_kw') === false, 'Controller tidak lagi memakai placeholder :search_kw ganda');

t(strpos($view, 'data-export-scope="resmi"') !== false && strpos($view, '$exportSuffix') !== false, 'Tombol ekspor meneruskan filter aktif');
t(strpos($ctrl, 'cbtResultsExportRows($pdo, $filter, $exportExtra)') !== false, 'Handler unduhan meneruskan filter ke ekspor');

if ($fail) { echo "\n$fail test gagal\n"; exit(1); }
echo "\nSemua test filter hasil CBT lulus.\n";
