<?php
/**
 * Test alur Pretes RPL berbasis CBT (includes/pretes-rpl-flow.php) + halaman Hasil Pretes (pages/pretes-hasil.php):
 *  - nilai CBT otomatis tertulis ke komponen Ujian tulis saat dikumpulkan (dan backfill bila belum);
 *  - tahap: none / cbt_failed (<80) / online_task (≥80, belum lengkap) / final_passed / final_failed;
 *  - peringatan batas pengumpulan 22 November 2026 23.59 WIB;
 *  - render halaman Hasil Pretes per tahap (stub auth/header, SQLite).
 */
if (session_status() !== PHP_SESSION_ACTIVE) { $_SESSION = []; }
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test');
$root = dirname(__DIR__);
require_once "$root/includes/pretes-cbt.php";
require_once "$root/includes/pretes-rpl-flow.php";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
ensurePretesCbtTables($pdo);
ensurePretesFeatures($pdo);
pretesComponentsPdo($pdo);
// Bagian 1–6 menguji alur lama "CBT + tugas online"; mode "cukup CBT" diuji di bagian 7.
pretesSettingSet($pdo, 'rpl_pass_mode', 'cbt_tasks');

$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };
$now = time();
$keys = array_keys(pretesComponents());

$finish = function (int $userId, int $regId, int $correct) use ($pdo, $now) {
    pretesCbtStart($pdo, $userId, $regId, $now);
    $attemptId = (int)$pdo->query("SELECT id FROM pretes_cbt_attempts WHERE user_id = $userId AND registration_id = $regId")->fetchColumn();
    $items = $pdo->query("SELECT position, correct_answer FROM pretes_cbt_items WHERE attempt_id = $attemptId ORDER BY position")->fetchAll();
    foreach ($items as $i => $it) {
        $ans = $i < $correct ? $it['correct_answer'] : ($it['correct_answer'] === 'A' ? 'B' : 'A');
        $pdo->prepare('UPDATE pretes_cbt_items SET answer = ? WHERE attempt_id = ? AND position = ?')->execute([$ans, $attemptId, $it['position']]);
    }
    pretesCbtChange($pdo, $userId, $regId, 'submit', ['revision' => 0], $now + 60);
};
$scoresOf = function (int $regId) use ($pdo): array {
    $raw = $pdo->query("SELECT scores_json FROM pretes_assessments WHERE registration_id = $regId")->fetchColumn();
    return $raw === false ? [] : (json_decode($raw, true) ?: []);
};
$setScores = function (int $regId, array $scores) use ($pdo) {
    $pdo->prepare('UPDATE pretes_assessments SET scores_json = ? WHERE registration_id = ?')->execute([json_encode($scores), $regId]);
};

// 1) Hook finalisasi: nilai CBT → Ujian tulis (baris baru, assessed_by 0)
$finish(1, 10, 19); // 76
$s = $scoresOf(10);
$check(($s['ujian_tulis'] ?? null) == 76.0 && count($s) === 1, 'Kumpulkan CBT 76 → scores_json.ujian_tulis = 76');
// Nilai praktik yang sudah ada tidak terhapus
$pdo->prepare('INSERT INTO pretes_assessments (registration_id, scores_json, assessed_by) VALUES (?, ?, 5)')->execute([20, json_encode(['praktik_wudlu' => 90])]);
$finish(2, 20, 25); // 100
$s = $scoresOf(20);
$check(($s['ujian_tulis'] ?? null) == 100.0 && ($s['praktik_wudlu'] ?? null) == 90, 'Nilai praktik lama tetap ada saat Ujian tulis ditulis');
// Tryout (registration_id negatif) tidak menulis asesmen
$check(pretesCbtWriteUjianTulis($pdo, -5, 90) === false, 'Registrasi tryout (negatif) tidak ditulis');

// 2) Tahap
$reg = fn(int $id) => ['id' => $id];
$check(pretesRplStage($pdo, 9, $reg(90), [])['stage'] === 'none', 'Belum CBT → none');
pretesCbtStart($pdo, 8, 80, $now);
$check(pretesRplStage($pdo, 8, $reg(80), [])['stage'] === 'none', 'CBT berjalan → none');
$r = pretesRplStage($pdo, 1, $reg(10), $scoresOf(10));
$check($r['stage'] === 'cbt_failed' && $r['cbt']['score'] === 76.0, 'CBT 76 → cbt_failed');
$r = pretesRplStage($pdo, 2, $reg(20), $scoresOf(20));
$check($r['stage'] === 'online_task' && $r['practical_done'] === 1 && $r['practical_total'] === 11, 'CBT 100 + 1 praktik → online_task (1/11)');
// Backfill: asesmen tanpa ujian_tulis → ditulis dari CBT
$finish(3, 30, 20); // 80
$pdo->prepare('UPDATE pretes_assessments SET scores_json = ? WHERE registration_id = 30')->execute([json_encode(['praktik_wudlu' => 85])]);
$r = pretesRplStage($pdo, 3, $reg(30), $scoresOf(30));
$check($r['stage'] === 'online_task' && ($scoresOf(30)['ujian_tulis'] ?? null) == 80.0, 'Backfill Ujian tulis dari CBT 80 (tepat batas → lanjut online)');
// Lengkap semua ≥ 80 → final_passed; ada < 80 → final_failed
$full = array_fill_keys($keys, 85); $full['ujian_tulis'] = 100;
$setScores(20, $full);
$check(pretesRplStage($pdo, 2, $reg(20), $scoresOf(20))['stage'] === 'final_passed', 'Lengkap semua ≥80 → final_passed');
$full['praktik_wudlu'] = 70; $setScores(20, $full);
$check(pretesRplStage($pdo, 2, $reg(20), $scoresOf(20))['stage'] === 'final_failed', 'Lengkap ada <80 → final_failed');
// Override impor manual Ujian tulis tetap dihormati untuk tampilan, tapi tahap CBT memakai nilai CBT sistem
$check(pretesRplStage($pdo, 1, $reg(10), ['ujian_tulis' => 95])['stage'] === 'cbt_failed', 'Tahap CBT memakai nilai CBT sistem');

// 3) Batas waktu (WIB)
$deadline = (new DateTimeImmutable('2026-11-22 23:59:59', new DateTimeZone('Asia/Jakarta')))->getTimestamp();
$check(pretesRplStage($pdo, 3, $reg(30), $scoresOf(30), $deadline)['deadline_passed'] === false, 'Tepat 22 Nov 23:59:59 WIB → belum lewat');
$late = pretesRplStage($pdo, 3, $reg(30), $scoresOf(30), $deadline + 1);
$check($late['deadline_passed'] === true, '23 Nov 00:00 WIB → lewat batas');

// 4) Render panel
$panel = function (array $rpl, string $tutor = '') { ob_start(); renderPretesRplStagePanel($rpl, '/tutorial-rpl.php', $tutor); return ob_get_clean(); };
$hFail = $panel(pretesRplStage($pdo, 1, $reg(10), $scoresOf(10)));
$check(str_contains($hFail, 'data-stage="cbt_failed"') && str_contains($hFail, 'Tidak Lulus Tahap CBT') && str_contains($hFail, 'href="/tutorial-rpl.php"')
    && str_contains($hFail, 'Pendaftaran Tutorial RPL Gelombang 1') && !str_contains($hFail, PRETES_RPL_DRIVE_URL), 'Panel CBT gagal: maaf + Tutorial RPL, tanpa Drive');
$check(substr_count($hFail, 'data-step-state="skip"') === 2 && substr_count($hFail, 'Tidak dilanjutkan') === 2 && substr_count($hFail, '✕') === 4 && !str_contains($hFail, '–</span>'), 'Panel CBT gagal: langkah upload & penilaian bertanda silang (Tidak dilanjutkan)');
$hTask = $panel(pretesRplStage($pdo, 3, $reg(30), $scoresOf(30), $deadline - 86400), '<strong>Ust. Tutor</strong>');
foreach (['data-stage="online_task"', 'Tahap 2 — Tugas Pretes Online', 'video rekaman hafalan', PRETES_RPL_DRIVE_URL, 'target="_blank" rel="noopener noreferrer"',
          'Melaporkan ke Tutor', 'Ust. Tutor', '22 November 2026', 'id="pretes-rpl-countdown"', '1/11 kompetensi'] as $n) {
    $check(str_contains($hTask, $n), "Panel tugas online memuat: $n");
}
$check(!str_contains($hTask, '/tutorial-rpl.php'), 'Panel tugas online tanpa link tutorial');
$hLate = $panel($late);
$check(str_contains($hLate, 'Batas pengumpulan tugas telah lewat') && !str_contains($hLate, 'id="pretes-rpl-countdown"'), 'Lewat batas → peringatan, tanpa hitung mundur');
$setScores(20, $full);
$hFinalFail = $panel(pretesRplStage($pdo, 2, $reg(20), $scoresOf(20)));
$check(str_contains($hFinalFail, 'Belum Lulus Pretes') && str_contains($hFinalFail, 'href="/tutorial-rpl.php"'), 'Panel final_failed → Tutorial RPL');

// 5) Render halaman Hasil Pretes (pages/pretes-hasil.php) dengan auth/header stub
$pdo->exec("CREATE TABLE master_pretes (id INTEGER PRIMARY KEY AUTOINCREMENT, periode TEXT, status TEXT, tanggal_mulai TEXT)");
$pdo->exec("INSERT INTO master_pretes (periode, status) VALUES ('Pretes RPL 2026/2027', 'aktif')");
$pdo->exec("CREATE TABLE pretes_schedules (id INTEGER PRIMARY KEY AUTOINCREMENT, periode TEXT, tanggal TEXT, waktu_mulai TEXT, waktu_selesai TEXT, ruangan TEXT, kuota INT, terisi INT, status TEXT)");
$pdo->exec("CREATE TABLE pretes_results (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, pretes_schedule_id INT, nilai REAL, created_at TEXT)");
$pdo->exec("CREATE TABLE pretes_registrations (id INTEGER PRIMARY KEY, user_id INT, periode TEXT, hari_pilihan TEXT, jam TEXT, ruangan TEXT, dosen_penguji TEXT, status TEXT, pretes_schedule_id INT)");
foreach ([[10, 1], [20, 2], [30, 3], [90, 9]] as [$rid, $uid]) {
    $pdo->prepare("INSERT INTO pretes_registrations VALUES (?, ?, 'Pretes RPL 2026/2027', 'Senin', '13.00', 'Lab 1', 'Ust. Tutor', 'terdaftar', NULL)")->execute([$rid, $uid]);
}
$GLOBALS['__testPdo'] = $pdo;
$stub = sys_get_temp_dir() . '/pretes-hasil-auth-stub-' . getmypid() . '.php';
file_put_contents($stub, '<?php
function requireLogin() {}
function getCurrentUser() { return $GLOBALS["__testUser"]; }
function getDBConnection() { return $GLOBALS["__testPdo"]; }
function isAdmin() { return false; }
function isDosen() { return false; }
if (!function_exists("sanitize")) { function sanitize($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); } }
if (!function_exists("renderDosenWithWa")) { function renderDosenWithWa($pdo, $n, $s = null, $k = null, $f = "-") { return "TUTOR:" . ($n ?: $f); } }
');
$src = file_get_contents("$root/pages/pretes-hasil.php");
$src = str_replace("require_once __DIR__ . '/../includes/auth.php';", 'require_once ' . var_export($stub, true) . ';', $src);
$src = preg_replace("#include __DIR__ \\. '/\\.\\./includes/(header|footer)\\.php';#", '/* $1 */', $src);
$src = str_replace("__DIR__ . '/../includes/", var_export("$root/includes/", true) . " . '", $src);
$src = str_replace("define('PAGE_TITLE'", "defined('PAGE_TITLE') || define('PAGE_TITLE'", $src);
$page = sys_get_temp_dir() . '/pretes-hasil-page-' . getmypid() . '.php';
file_put_contents($page, $src);
$renderPage = function (int $uid) use ($page): string {
    $GLOBALS['__testUser'] = ['id' => $uid, 'role' => 'mahasiswa', 'nama_lengkap' => 'Mhs', 'nim' => (string)(2400 + $uid), 'username' => (string)(2400 + $uid), 'jenis_program' => 'RPL', 'program_studi' => 'X'];
    ob_start();
    try { (function () use ($page) { include $page; })(); } catch (Throwable $e) { echo 'EXC:' . $e->getMessage() . ' @' . $e->getLine(); }
    return ob_get_clean();
};
$p1 = $renderPage(1);
$check(str_contains($p1, 'TIDAK LULUS TAHAP CBT') && str_contains($p1, 'data-stage="cbt_failed"') && !str_contains($p1, 'data-pretes-component=') && !str_contains($p1, 'Rincian Nilai Keseluruhan')
    && str_contains($p1, '/tutorial-rpl.php') && !str_contains($p1, 'MENUNGGU PENILAIAN') && !str_contains($p1, '/tutorial-pendaftaran.php'), 'Halaman: CBT <80 → tidak lulus tahap CBT + Tutorial RPL');
$check(str_contains($p1, 'Nilai CBT Pretes') && str_contains($p1, '76.00'), 'Halaman: ringkasan menampilkan nilai CBT 76');
$p3 = $renderPage(3);
$check(str_contains($p3, 'TAHAP 2 — TUGAS PRETES ONLINE') && str_contains($p3, 'data-stage="online_task"') && str_contains($p3, 'Menunggu penilaian tutor') && str_contains($p3, 'data-pretes-component="ujian_tulis"')
    && str_contains($p3, 'TUTOR:Ust. Tutor') && str_contains($p3, PRETES_RPL_DRIVE_URL), 'Halaman: CBT ≥80 → tahap 2 tugas online + tutor WA + Drive');
$p2 = $renderPage(2);
$check(str_contains($p2, 'data-stage="final_failed"') && str_contains($p2, '/tutorial-rpl.php'), 'Halaman: penilaian lengkap ada <80 → Tutorial RPL');
$setScores(20, array_merge($full, ['praktik_wudlu' => 90]));
$p2b = $renderPage(2);
$check(str_contains($p2b, 'Status Akhir: LULUS') && !str_contains($p2b, 'id="pretes-rpl-stage"'), 'Halaman: semua ≥80 → LULUS (alur lama Al Khidmah/sertifikat)');
$p9 = $renderPage(9);
$check(!str_contains($p9, 'id="pretes-rpl-stage"') && str_contains($p9, 'MENUNGGU PENILAIAN'), 'Halaman: belum CBT → tampilan menunggu lama');
foreach (['p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'p9' => $p9] as $k => $html) $check(!str_contains($html, 'EXC:') && !str_contains($html, 'Warning'), "Halaman $k tanpa error: " . substr(strstr($html, 'EXC:') ?: '', 0, 160));

// 7) Mode "cukup CBT" (bawaan): RPL CBT ≥ 80 langsung LULUS; sertifikat menunggu pengumuman
pretesSettingSet($pdo, 'rpl_pass_mode', 'cbt_only');
$check(pretesRplCbtOnly() && array_keys(pretesComponents('rpl')) === ['ujian_tulis'] && count(pretesComponents('reguler')) === 12, 'Mode cukup CBT: komponen wajib RPL hanya Ujian tulis, Reguler tetap 12');
$check(array_keys(pretesComponentCategoriesFor('rpl')) === ['Ujian CBT'], 'Mode cukup CBT: form nilai dosen RPL hanya Ujian CBT');
$check(getPretesStatusLulus(['ujian_tulis' => 80], 'rpl') === 'lulus' && getPretesStatusLulus(['ujian_tulis' => 79.9], 'rpl') === 'tidak_lulus'
    && getPretesStatusLulus([], 'rpl') === 'menunggu', 'Mode cukup CBT: status lulus RPL dari Ujian tulis (80 lulus, 79.9 tidak)');
$check(pretesRplStage($pdo, 3, $reg(30), $scoresOf(30), null, 'rpl')['stage'] === 'final_passed', 'Mode cukup CBT: CBT 80 → final_passed');
$check(pretesRplStage($pdo, 1, $reg(10), $scoresOf(10), null, 'rpl')['stage'] === 'cbt_failed', 'Mode cukup CBT: CBT 76 → tetap cbt_failed');
$pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, jenis_program TEXT)");
$pdo->exec("INSERT OR REPLACE INTO users (id, jenis_program) VALUES (1,'RPL'),(3,'RPL'),(4,'Reguler')");
$check(isUserLulusPretes($pdo, 3) === true && isUserLulusPretes($pdo, 1) === false, 'isUserLulusPretes: RPL CBT 80 lulus, CBT 76 tidak');
// Cadangan: nilai CBT belum tersalin ke asesmen
$pdo->exec("DELETE FROM pretes_assessments WHERE registration_id = 30");
$check(isUserLulusPretes($pdo, 3) === true, 'isUserLulusPretes: cadangan dari pretes_cbt_attempts bila asesmen kosong');
$pdo->prepare('INSERT INTO pretes_assessments (registration_id, scores_json, assessed_by) VALUES (30, ?, 0)')->execute([json_encode(['ujian_tulis' => 80])]);

$pdo->exec("CREATE TABLE IF NOT EXISTS announcements (id INTEGER PRIMARY KEY AUTOINCREMENT, judul TEXT, konten TEXT, tipe TEXT, link_tujuan TEXT, target_program TEXT DEFAULT 'Semua', target_peserta TEXT DEFAULT 'semua', is_active INT DEFAULT 1, created_by INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$p3c = $renderPage(3);
$check(str_contains($p3c, 'Status Akhir: LULUS') && str_contains($p3c, 'id="pretes-rpl-lulus-box"') && str_contains($p3c, 'data-cert-state="waiting"')
    && !str_contains($p3c, 'Majlis Dzikir Al Khidmah') && !str_contains($p3c, '/absensi-alkhidmah.php') && !str_contains($p3c, PRETES_RPL_DRIVE_URL)
    && !str_contains($p3c, '/ambil-sertifikat.php') && !str_contains($p3c, 'id="pretes-rpl-stage"'), 'Halaman cukup CBT: LULUS + menunggu pengumuman, tanpa Al Khidmah/Drive/tombol sertifikat');
$pdo->exec("INSERT INTO announcements (judul, konten, tipe, target_program, target_peserta, is_active) VALUES ('Sertifikat Reguler', 'x', 'pengambilan_sertifikat_pretes', 'Reguler', 'semua', 1)");
$check(str_contains($renderPage(3), 'data-cert-state="waiting"'), 'Pengumuman khusus Reguler tidak membuka sertifikat RPL');
$pdo->exec("INSERT INTO announcements (judul, konten, tipe, target_program, target_peserta, is_active) VALUES ('Pengambilan Sertifikat Pretes RPL', 'Mulai 1 Desember 2026 pukul 08.00-15.00 WIB', 'pengambilan_sertifikat_pretes', 'RPL', 'lulus_pretes', 1)");
$p3d = $renderPage(3);
foreach (['data-cert-state="announced"', 'Pengambilan Sertifikat Pretes RPL', 'Mulai 1 Desember 2026', 'Rp 50.000', 'Bank Jatim', 'pasfoto 3×4', 'Kantor LPPAI', '/ambil-sertifikat.php'] as $n) {
    $check(str_contains($p3d, $n), "Halaman cukup CBT setelah pengumuman memuat: $n");
}
$p1c = $renderPage(1);
$check(str_contains($p1c, 'TIDAK LULUS TAHAP CBT') && !str_contains($p1c, 'pretes-cert-steps'), 'CBT <80 tetap tidak lulus, tanpa info sertifikat');
foreach (['p3c' => $p3c, 'p3d' => $p3d, 'p1c' => $p1c] as $k => $html) $check(!str_contains($html, 'EXC:') && !str_contains($html, 'Warning'), "Halaman $k tanpa error: " . substr(strstr($html, 'EXC:') ?: (strstr($html, 'Warning') ?: ''), 0, 200));

// Kartu hasil CBT (dashboard/peserta) untuk RPL cukup CBT
$cbt3 = pretesCbtResultFor($pdo, 3, 30);
ob_start(); renderPretesCbtResultCard($cbt3, '/pretes-ujian.php', '/tutorial-rpl.php', ['id' => 3, 'jenis_program' => 'RPL', 'role' => 'mahasiswa']); $c3 = ob_get_clean();
$check(str_contains($c3, 'LULUS PRETES') && str_contains($c3, 'data-cert-state="announced"') && !str_contains($c3, 'pretes-rpl-online-tasks'), 'Kartu CBT RPL ≥80: LULUS + info sertifikat, tanpa tugas online');
ob_start(); renderPretesCbtResultCard($cbt3, '/pretes-ujian.php', '/tutorial-rpl.php'); $c3old = ob_get_clean();
$check(str_contains($c3old, 'pretes-rpl-online-tasks'), 'Kartu tanpa data user tetap memakai tampilan lama');
pretesSettingSet($pdo, 'rpl_pass_mode', 'cbt_tasks');
ob_start(); renderPretesCbtResultCard($cbt3, '/pretes-ujian.php', '/tutorial-rpl.php', ['id' => 3, 'jenis_program' => 'RPL']); $c3tasks = ob_get_clean();
$check(str_contains($c3tasks, 'pretes-rpl-online-tasks') && !str_contains($c3tasks, 'LULUS PRETES'), 'Mode CBT + tugas: kartu kembali menampilkan tugas online');
$check(count(pretesComponents('rpl')) === 12, 'Mode CBT + tugas: komponen RPL kembali 12');
@unlink($stub); @unlink($page);

// 6) Kartu peserta menautkan Hasil Pretes
$card = file_get_contents("$root/includes/pretes-cbt-result-card.php");
$check(str_contains($card, 'id="link-pretes-hasil-status"') && str_contains($card, '/pretes-hasil.php'), 'Kartu peserta menautkan halaman Hasil Pretes');

if ($failures) { echo "FAIL:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
echo "PASS: alur pretes RPL (Ujian tulis otomatis, tahap CBT/online/akhir, batas 22 Nov WIB) + halaman Hasil Pretes per tahap.\n";
