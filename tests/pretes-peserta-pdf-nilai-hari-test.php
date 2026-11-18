<?php
/**
 * Test: Admin Peserta Pretes — kolom Nilai pada ekspor PDF & filter hari multi-pilih.
 * - pretesParseHariFilter(): "Senin,Rabu" / array → daftar hari valid berurutan.
 * - pretesOfficialScoresByRegistration(): CBT resmi terkumpul (terakhir), cadangan ujian_tulis, belum → tidak ada.
 * - pretesFormatScore(): format desimal Indonesia.
 * - Markup admin/pretes-peserta.php, pretes-peserta-pdf.php, pretes-peserta-export.php.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$t = function (bool $ok, string $msg) use (&$fail): void {
    echo ($ok ? ' [PASS] ' : '❌ [FAIL] ') . $msg . "\n";
    if (!$ok) $fail++;
};

require_once $root . '/includes/pretes-helper.php';

// 1) Parser hari
$t(pretesParseHariFilter('') === [], 'Hari kosong → semua hari (daftar kosong)');
$t(pretesParseHariFilter('Rabu,senin') === ['Senin', 'Rabu'], 'Hari "Rabu,senin" → [Senin, Rabu] (urut & kapitalisasi)');
$t(pretesParseHariFilter(['Jumat', 'Kamis', 'Kamis']) === ['Kamis', 'Jumat'], 'Array hari[] tanpa duplikat');
$t(pretesParseHariFilter('Sabtu, Minggu, x') === [], 'Hari di luar Senin–Jumat diabaikan');
$t(pretesParseHariFilter(' Selasa ') === ['Selasa'], 'Satu hari (format lama) tetap didukung');

// 2) Format nilai
$t(pretesFormatScore(92.0) === '92' && pretesFormatScore(81.5) === '81,5' && pretesFormatScore(79.99) === '79,99', 'Format nilai 92 / 81,5 / 79,99');

// 3) Nilai resmi per pendaftaran
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE pretes_cbt_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, registration_id INT, started_at INT, deadline_at INT, submitted_at INT NULL, score REAL NULL)');
$pdo->exec('CREATE TABLE pretes_assessments (registration_id INT PRIMARY KEY, scores_json TEXT, assessed_by INT)');
$ins = $pdo->prepare('INSERT INTO pretes_cbt_attempts (user_id, registration_id, started_at, deadline_at, submitted_at, score) VALUES (?,?,?,?,?,?)');
$ins->execute([1, 1, 100, 200, 150, 72.0]);   // reg 1: dua kali dikumpulkan → pakai terakhir (92)
$ins->execute([1, 1, 300, 400, 350, 92.0]);
$ins->execute([2, 2, 100, 999999, null, null]); // reg 2: masih berlangsung → belum
$ins->execute([3, -3, 100, 200, 150, 95.0]);  // tryout (negatif) tidak dihitung untuk reg 3
$pdo->exec("INSERT INTO pretes_assessments VALUES (4, '{\"ujian_tulis\":81.5}', 1)"); // reg 4: impor ujian tulis
$pdo->exec("INSERT INTO pretes_assessments VALUES (1, '{\"ujian_tulis\":10}', 1)");   // reg 1: CBT lebih diutamakan
$pdo->exec("INSERT INTO pretes_assessments VALUES (5, '{\"praktik_wudlu\":90}', 1)"); // reg 5: tanpa ujian tulis → belum
$sc = pretesOfficialScoresByRegistration($pdo, [1, 2, 3, 4, 5, '1', 0]);
$t(($sc[1]['score'] ?? null) === 92.0 && $sc[1]['source'] === 'CBT', 'CBT resmi terkumpul terakhir dipakai (92) dan diutamakan atas ujian_tulis');
$t(!isset($sc[2]), 'CBT masih berlangsung → belum ada nilai ("-")');
$t(!isset($sc[3]), 'Tryout tidak dihitung sebagai nilai resmi');
$t(($sc[4]['score'] ?? null) === 81.5 && $sc[4]['source'] === 'Ujian tulis', 'Cadangan nilai ujian_tulis (impor) dipakai');
$t(!isset($sc[5]), 'Asesmen tanpa ujian_tulis → belum ada nilai');
$t(pretesOfficialScoresByRegistration($pdo, []) === [], 'Daftar kosong → hasil kosong');

// 4) Markup
$admin = file_get_contents($root . '/admin/pretes-peserta.php');
$t(str_contains($admin, 'pretesOfficialScoresByRegistration($pdo, array_column($participants, \'reg_id\'))'), 'Halaman admin memakai helper nilai resmi');
$t(str_contains($admin, 'id="filter-hari-group"') && substr_count($admin, 'class="filter-hari-cb"') === 1 && str_contains($admin, '<input type="hidden" id="filter-hari"'), 'Filter hari berupa checkbox multi-pilih + nilai tersembunyi');
$t(!str_contains($admin, '<select id="filter-hari"'), 'Dropdown hari tunggal sudah diganti');
$t(str_contains($admin, "hariList.map(function(d) { return $.fn.dataTable.util.escapeRegex(d); }).join('|')"), 'DataTables memakai regex alternatif untuk beberapa hari');
$t(str_contains($admin, "if (hariVal) params.set('hari', hariVal);"), 'Ekspor sesuai filter mengirim hari (dipisah koma)');

$pdf = file_get_contents($root . '/admin/pretes-peserta-pdf.php');
$t(str_contains($pdf, '<th class="col-nilai">Nilai Pretes</th>') && str_contains($pdf, 'colspan="6"'), 'PDF memiliki kolom Nilai Pretes (6 kolom)');
$t(str_contains($pdf, 'pretesParseHariFilter($_GET[\'hari\'] ?? \'\')') && str_contains($pdf, 'LOWER(TRIM(pr.hari_pilihan)) IN ('), 'PDF mendukung filter beberapa hari (IN)');
$t(str_contains($pdf, 'Belum Pretes:'), 'Rekap PDF memuat jumlah sudah/belum pretes');

$xlsx = file_get_contents($root . '/admin/pretes-peserta-export.php');
$t(str_contains($xlsx, 'pretesParseHariFilter($_GET[\'hari\'] ?? \'\')') && str_contains($xlsx, "implode('-', \$filterHariList)"), 'Ekspor Excel juga mendukung beberapa hari');

// 5) Filter tunggal "Status Pretes Resmi" menggantikan Status Hadir & Status Credentials
$t(str_contains($admin, '<select id="filter-pretes"') && str_contains($admin, '<option value="sudah">Sudah Pretes Resmi') && str_contains($admin, '<option value="belum">Belum Pretes Resmi'), 'Filter Status Pretes Resmi (sudah/belum) tersedia');
$t(!str_contains($admin, 'id="filter-status"') && !str_contains($admin, 'id="filter-cred"') && !str_contains($admin, "#filter-status") && !str_contains($admin, "#filter-cred"), 'Filter Status Hadir & Status Credentials dihapus (tanpa sisa referensi JS)');
$t(str_contains($admin, 'data-pretes="<?= isset($officialScores[(int)$p[\'reg_id\']]) ? \'sudah\' : \'belum\' ?>"') && str_contains($admin, 'rowNode.getAttribute(\'data-pretes\')'), 'Baris tabel ber-atribut data-pretes dan disaring DataTables');
$t(str_contains($admin, 'if (pretesVal) params.set(\'pretes\', pretesVal);'), 'Ekspor sesuai filter mengirim parameter pretes');
foreach (['pdf' => $pdf, 'xlsx' => $xlsx] as $k => $src) {
    $t(str_contains($src, 'in_array($_GET[\'pretes\'] ?? \'\', [\'sudah\', \'belum\'], true)') && str_contains($src, '($filterPretes === \'sudah\')'), "Ekspor $k memfilter sudah/belum pretes resmi");
}
$pdf2 = file_get_contents($root . '/admin/pretes-peserta-pdf.php');
$t(strpos($pdf2, 'if ($filterPretes !== \'\')') < strpos($pdf2, '$totalPeserta = count($rows);'), 'PDF menghitung total setelah filter pretes');

if ($fail) { echo "\n$fail test gagal\n"; exit(1); }
echo "\nSemua test kolom nilai PDF & filter hari multi-pilih lulus.\n";
