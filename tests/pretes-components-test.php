<?php
/**
 * Test Kelola Tugas Pretes (includes/pretes-components.php, admin/pretes-tugas.php):
 * seed 11 komponen per program, tambah/edit/urut/nonaktif/aktif/hapus, komponen per program RPL vs Reguler,
 * status kelulusan & validasi nilai mengikuti program, dan render halaman admin (stub auth/header, SQLite).
 */
if (session_status() !== PHP_SESSION_ACTIVE) { $_SESSION = []; }
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test');
$root = dirname(__DIR__);
require_once "$root/includes/pretes-helper.php";

$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };

// Tanpa database → 12 komponen bawaan (kompatibel dengan perilaku lama)
$check(count(pretesComponents()) === 12 && array_key_first(pretesComponents()) === 'ujian_tulis', 'Tanpa DB: 12 komponen bawaan');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
ensurePretesFeatures($pdo);
pretesComponentsPdo($pdo);
pretesComponentsCache(null, null, true);
// Mode kelulusan RPL: bawaan "cukup CBT"; test komponen di bawah memakai mode "CBT + tugas online".
$check(pretesRplPassMode() === 'cbt_only' && pretesRplCbtOnly(), 'Mode kelulusan RPL bawaan: cukup CBT');
pretesSettingSet($pdo, 'rpl_pass_mode', 'cbt_tasks');
$check(pretesRplPassMode() === 'cbt_tasks' && pretesSettingGet('rpl_pass_mode') === 'cbt_tasks', 'Pengaturan mode tersimpan di pretes_settings');
pretesSettingSet($pdo, 'rpl_pass_mode', 'cbt_tasks'); // simpan ulang (update) tidak dobel
$check((int)$pdo->query("SELECT COUNT(*) FROM pretes_settings WHERE setting_key = 'rpl_pass_mode'")->fetchColumn() === 1, 'Simpan ulang pengaturan tidak dobel');

// Seed
$reg = pretesComponentRows($pdo, 'reguler', true);
$rpl = pretesComponentRows($pdo, 'rpl', true);
$check(count($reg) === 11 && count($rpl) === 11, 'Seed: 11 komponen per program');
$check(array_keys(pretesComponents('reguler')) === array_keys(pretesComponents('rpl')) && count(pretesComponents('rpl')) === 12, 'Seed: daftar sama dengan bawaan (12 termasuk Ujian tulis)');
$check($reg[0]['comp_key'] === 'praktik_wudlu' && str_contains((string)$reg[0]['video_url'], 'zvCIGrVTE-w'), 'Seed: video bawaan ikut tersimpan');
ensurePretesComponentTable($pdo);
$check(count(pretesComponentRows($pdo, 'reguler', true)) === 11, 'Seed tidak dobel');

// Tambah komponen RPL saja
$new = pretesComponentSave($pdo, 'rpl', ['label' => 'Praktik adzan & iqamah', 'bentuk' => 'Praktik', 'kategori' => PRETES_COMPONENT_CATEGORIES[0],
    'video_url' => 'https://www.youtube.com/watch?v=abcdefghijk', 'reading_text' => "Allahu akbar\nAllahu akbar"]);
$check($new['comp_key'] === 'praktik_adzan_iqamah' && (int)$new['is_active'] === 1, 'Tambah: kunci dibuat dari nama');
$check(isset(pretesComponents('rpl')['praktik_adzan_iqamah']) && !isset(pretesComponents('reguler')['praktik_adzan_iqamah']), 'Komponen baru hanya untuk program RPL');
$check(count(pretesComponents('rpl')) === 13 && count(pretesComponents()) === 13, 'RPL 13 komponen; gabungan 13');
$check(pretesPracticalCount('rpl') === 12 && pretesPracticalCount('reguler') === 11, 'Jumlah kompetensi praktik per program');
$dup = pretesComponentSave($pdo, 'rpl', ['label' => 'Praktik adzan & iqamah']);
$check($dup['comp_key'] === 'praktik_adzan_iqamah_2', 'Nama sama → kunci unik');
pretesComponentDelete($pdo, 'rpl', (int)$dup['id']);

// Validasi input
foreach ([['label' => ''], ['label' => 'X', 'video_url' => 'javascript:alert(1)'], ['label' => 'X', 'video_url' => 'ftp://a.b/c']] as $bad) {
    $ok = false;
    try { pretesComponentSave($pdo, 'rpl', $bad); } catch (InvalidArgumentException $e) { $ok = true; }
    $check($ok, 'Validasi menolak: ' . json_encode($bad));
}
$ok = false; try { pretesComponentSave($pdo, 'lainnya', ['label' => 'X']); } catch (InvalidArgumentException $e) { $ok = true; }
$check($ok, 'Program tidak dikenal ditolak');

// Edit
$edited = pretesComponentSave($pdo, 'rpl', ['label' => 'Praktik adzan', 'bentuk' => 'Lisan', 'kategori' => PRETES_COMPONENT_CATEGORIES[1]], (int)$new['id']);
$check($edited['label'] === 'Praktik adzan' && $edited['comp_key'] === 'praktik_adzan_iqamah' && $edited['kategori'] === PRETES_COMPONENT_CATEGORIES[1] && $edited['video_url'] === null, 'Edit: label/kategori berubah, kunci tetap');
$ok = false; try { pretesComponentSave($pdo, 'reguler', ['label' => 'Y'], (int)$new['id']); } catch (InvalidArgumentException $e) { $ok = true; }
$check($ok, 'Edit lintas program ditolak');

// Urutan
pretesComponentMove($pdo, 'rpl', (int)$new['id'], -1);
$keys = array_keys(pretesComponents('rpl'));
$check($keys[count($keys) - 2] === 'praktik_adzan_iqamah', 'Naikkan: pindah satu posisi');
pretesComponentMove($pdo, 'rpl', (int)$new['id'], 1);
$keys = array_keys(pretesComponents('rpl'));
$check(end($keys) === 'praktik_adzan_iqamah', 'Turunkan: kembali ke akhir');

// Status lulus mengikuti program
$scores = array_fill_keys(array_keys(pretesComponents('reguler')), 85);
$check(getPretesStatusLulus($scores, 'reguler') === 'lulus', 'Reguler: 12 komponen ≥80 → lulus');
$check(getPretesStatusLulus($scores, 'rpl') === 'menunggu', 'RPL: komponen tambahan belum dinilai → menunggu');
$scores['praktik_adzan_iqamah'] = 70;
$check(getPretesStatusLulus($scores, 'rpl') === 'tidak_lulus', 'RPL: komponen tambahan < 80 → tidak lulus');
$v = pretesValidateScores(['ujian_tulis' => '90', 'praktik_adzan_iqamah' => '88'], 'rpl');
$check(array_key_exists('praktik_adzan_iqamah', $v) && count($v) === 13, 'Validasi nilai RPL memuat komponen tambahan');
$v = pretesValidateScores(['praktik_adzan_iqamah' => '88'], 'reguler');
$check(!array_key_exists('praktik_adzan_iqamah', $v), 'Validasi nilai Reguler mengabaikan komponen khusus RPL');

// Nonaktif: hilang dari daftar, nilai tersimpan aman, hapus permanen ditolak bila sudah bernilai
$pdo->prepare('INSERT INTO pretes_assessments (registration_id, scores_json, assessed_by) VALUES (1, ?, 1)')->execute([json_encode(['al_alaq' => 90, 'praktik_adzan_iqamah' => 70])]);
$alaq = array_values(array_filter(pretesComponentRows($pdo, 'reguler', true), fn($r) => $r['comp_key'] === 'al_alaq'))[0];
pretesComponentSetActive($pdo, 'reguler', (int)$alaq['id'], false);
$check(!isset(pretesComponents('reguler')['al_alaq']) && count(pretesComponents('reguler')) === 11 && isset(pretesComponents('rpl')['al_alaq']), 'Nonaktif di Reguler: hilang dari Reguler saja');
$ok = false; try { pretesComponentDelete($pdo, 'reguler', (int)$alaq['id']); } catch (InvalidArgumentException $e) { $ok = str_contains($e->getMessage(), 'Nonaktifkan'); }
$check($ok, 'Hapus permanen ditolak karena sudah ada nilai');
$check(str_contains((string)$pdo->query('SELECT scores_json FROM pretes_assessments WHERE registration_id = 1')->fetchColumn(), '"al_alaq":90'), 'Nilai tersimpan tetap utuh');
pretesComponentSetActive($pdo, 'reguler', (int)$alaq['id'], true);
$check(isset(pretesComponents('reguler')['al_alaq']), 'Aktifkan kembali');
// Hapus permanen komponen tanpa nilai
$tmp = pretesComponentSave($pdo, 'reguler', ['label' => 'Komponen uji']);
pretesComponentDelete($pdo, 'reguler', (int)$tmp['id']);
$check(!isset(pretesComponents('reguler')['komponen_uji']), 'Hapus permanen komponen tanpa nilai');
// Minimal satu aktif
$rplRows = pretesComponentRows($pdo, 'rpl', true);
$blocked = false;
try { foreach ($rplRows as $r) pretesComponentSetActive($pdo, 'rpl', (int)$r['id'], false); } catch (InvalidArgumentException $e) { $blocked = true; }
$check($blocked && count(pretesComponents('rpl')) === 2, 'Minimal satu komponen praktik tetap aktif');
foreach ($rplRows as $r) pretesComponentSetActive($pdo, 'rpl', (int)$r['id'], true);

// Kelompok panel penilaian per program
$cats = pretesComponentCategoriesFor('rpl');
$check(isset($cats['Ujian CBT']['ujian_tulis']) && isset($cats[PRETES_COMPONENT_CATEGORIES[1]]['praktik_adzan_iqamah']), 'Kelompok panel memuat komponen baru pada kategorinya');
$check(pretesProgramKey('RPL') === 'rpl' && pretesProgramKey('Reguler') === 'reguler' && pretesProgramKey(null) === 'reguler', 'pretesProgramKey');

// Render halaman admin (stub auth/header)
$GLOBALS['__testPdo'] = $pdo;
$stub = sys_get_temp_dir() . '/pretes-tugas-auth-stub-' . getmypid() . '.php';
file_put_contents($stub, '<?php
function requireAdmin() {}
function getDBConnection() { return $GLOBALS["__testPdo"]; }
function csrfToken() { return "tok"; }
function verifyCsrf($t) { return $t === "tok"; }
');
$src = file_get_contents("$root/admin/pretes-tugas.php");
$src = str_replace("require_once __DIR__ . '/../includes/auth.php';", 'require_once ' . var_export($stub, true) . ';', $src);
$src = preg_replace("#include __DIR__ \\. '/\\.\\./includes/(header|footer)\\.php';#", '/* $1 */', $src);
$src = str_replace("__DIR__ . '/../includes/", var_export("$root/includes/", true) . " . '", $src);
$src = str_replace("define('PAGE_TITLE'", "defined('PAGE_TITLE') || define('PAGE_TITLE'", $src);
$page = sys_get_temp_dir() . '/pretes-tugas-page-' . getmypid() . '.php';
file_put_contents($page, $src);
$render = function (array $get) use ($page): string {
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    try { (function () use ($page) { include $page; })(); } catch (Throwable $e) { echo 'EXC:' . $e->getMessage() . ' @' . $e->getLine(); }
    return ob_get_clean();
};
$hReg = $render(['program' => 'reguler']);
$check(!str_contains($hReg, 'EXC:') && str_contains($hReg, 'Kelola Tugas Pretes (Selain CBT)') && str_contains($hReg, 'data-program-tab="rpl"')
    && str_contains($hReg, 'Daftar Komponen — Program Reguler') && substr_count($hReg, 'data-component-key=') === 11 && !str_contains($hReg, 'praktik_adzan_iqamah'), 'Halaman Reguler: 11 komponen, tanpa komponen RPL ' . substr(strstr($hReg, 'EXC:') ?: '', 0, 200));
$hRpl = $render(['program' => 'rpl']);
$check(str_contains($hRpl, 'data-component-key="praktik_adzan_iqamah"') && str_contains($hRpl, 'Aktif: 12 + Ujian tulis = 13'), 'Halaman RPL: komponen tambahan + hitungan');
$hEdit = $render(['program' => 'rpl', 'edit' => (string)$new['id']]);
$check(str_contains($hEdit, '✏️ Edit Komponen') && str_contains($hEdit, 'value="Praktik adzan"') && str_contains($hEdit, 'Simpan Perubahan'), 'Mode edit memuat isi komponen');
$check(str_contains($render(['program' => 'xx']), 'Program Reguler'), 'Program tidak dikenal → Reguler');
@unlink($stub); @unlink($page);

// Integrasi menu & pemakaian program
$sidebar = file_get_contents("$root/includes/sidebar.php");
$check(str_contains($sidebar, '/admin/pretes-tugas.php') && str_contains($sidebar, "'pretes-tugas.php'"), 'Menu Kelola Tugas Pretes di sidebar admin');
$panel = file_get_contents("$root/includes/pretes-panel.php");
$check(str_contains($panel, 'pretesValidateScores($_POST[\'scores\'] ?? null, $regProgram)') && str_contains($panel, 'array_merge($oldScores, $validated)'), 'Panel nilai: validasi per program & nilai lama dipertahankan');
$check(str_contains(file_get_contents("$root/admin/pretes-rekap-nilai.php"), 'getPretesStatusLulus($scores, $rowProgram)'), 'Rekap: status per program');

if ($failures) { echo "FAIL:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
echo "PASS: kelola tugas pretes per program (seed, tambah, edit, urut, nonaktif, hapus aman, status & validasi per program, halaman admin).\n";
