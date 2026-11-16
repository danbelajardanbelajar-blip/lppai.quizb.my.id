<?php
/**
 * Test kartu hasil CBT pretes di halaman peserta aktif (includes/pretes-cbt-result-card.php):
 * belum mulai / sedang berjalan / nilai < 80 (link Tutorial RPL) / nilai ≥ 80 (tugas pretes online).
 */
if (session_status() !== PHP_SESSION_ACTIVE) { $_SESSION = []; }
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test');
require_once __DIR__ . '/../includes/pretes-cbt.php';
require_once __DIR__ . '/../includes/pretes-cbt-result-card.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensurePretesCbtTables($pdo);

$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };
$render = function (array $r): string { ob_start(); renderPretesCbtResultCard($r, '/pretes-ujian.php', '/tutorial-rpl.php'); return ob_get_clean(); };
$now = time();

/** Mulai CBT lalu jawab $correct soal dengan benar, sisanya salah, kemudian kumpulkan. */
$finish = function (int $userId, int $regId, int $correct) use ($pdo, $now) {
    pretesCbtStart($pdo, $userId, $regId, $now);
    $attemptId = (int)$pdo->query("SELECT id FROM pretes_cbt_attempts WHERE user_id = $userId AND registration_id = $regId")->fetchColumn();
    $items = $pdo->query("SELECT position, correct_answer FROM pretes_cbt_items WHERE attempt_id = $attemptId ORDER BY position")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $i => $it) {
        $ans = $i < $correct ? $it['correct_answer'] : ($it['correct_answer'] === 'A' ? 'B' : 'A');
        $pdo->prepare('UPDATE pretes_cbt_items SET answer = ? WHERE attempt_id = ? AND position = ?')->execute([$ans, $attemptId, $it['position']]);
    }
    pretesCbtChange($pdo, $userId, $regId, 'submit', ['revision' => 0], $now + 60);
};

// Belum mulai
$check(pretesCbtResultFor($pdo, 1, 10) === null, 'Belum mulai → null (kotak akses soal biasa)');

// Sedang berjalan
pretesCbtStart($pdo, 2, 20, $now);
$run = pretesCbtResultFor($pdo, 2, 20);
$check($run && $run['status'] === 'in_progress', 'Sudah mulai, belum dikumpulkan → in_progress');
$html = $render($run);
$check(str_contains($html, 'Lanjutkan Ujian') && str_contains($html, 'data-state="in_progress"'), 'Kartu in_progress berisi tombol Lanjutkan Ujian');

// Nilai 100 → lulus tahap CBT
$finish(3, 30, 25);
$r100 = pretesCbtResultFor($pdo, 3, 30);
$check($r100['status'] === 'submitted' && $r100['score'] === 100.0 && $r100['submitted_at'], 'Nilai 100 tersimpan + waktu kumpul');
$h = $render($r100);
$check(pretesCbtResultPassed($r100) && str_contains($h, 'data-state="passed"'), '100 → lulus');
foreach (['Selamat, Saudara diperkenankan melanjutkan ke tahap pretes secara online', 'video rekaman hafalan', '11 kompetensi',
          'upload ke Google Drive', PRETES_RPL_DRIVE_URL, 'target="_blank" rel="noopener noreferrer"', 'Melaporkan ke Tutor', '22 November 2026', '#pretes-active-preparation'] as $needle) {
    $check(str_contains($h, htmlspecialchars($needle, ENT_QUOTES)) || str_contains($h, $needle), "Kartu lulus memuat: $needle");
}
$check(!str_contains($h, 'Mohon maaf') && !str_contains($h, '/tutorial-rpl.php'), 'Kartu lulus tanpa pesan maaf/link tutorial');

// Tepat 80 (20/25) → lulus (nilai minimal 80)
$finish(4, 40, 20);
$r80 = pretesCbtResultFor($pdo, 4, 40);
$check($r80['score'] === 80.0 && pretesCbtResultPassed($r80) && str_contains($render($r80), 'data-state="passed"'), 'Tepat 80 → lulus (memenuhi nilai minimal)');

// 76 (19/25) → tidak lulus
$finish(5, 50, 19);
$r76 = pretesCbtResultFor($pdo, 5, 50);
$hf = $render($r76);
$check($r76['score'] === 76.0 && !pretesCbtResultPassed($r76) && str_contains($hf, 'data-state="failed"'), '76 → tidak lulus');
$check(str_contains($hf, 'Mohon maaf, nilai CBT Pretes Anda tidak mencapai nilai minimal (80)')
    && str_contains($hf, 'Tutorial RPL Gelombang 1') && str_contains($hf, 'href="/tutorial-rpl.php"'), 'Kartu gagal: pesan maaf + link Pendaftaran Tutorial RPL Gelombang 1');
$check(!str_contains($hf, PRETES_RPL_DRIVE_URL), 'Kartu gagal tanpa link Google Drive tugas');

// Nilai 0 (tidak menjawab) → tidak lulus
pretesCbtStart($pdo, 6, 60, $now);
pretesCbtChange($pdo, 6, 60, 'submit', ['revision' => 0], $now + 30);
$r0 = pretesCbtResultFor($pdo, 6, 60);
$check($r0['score'] === 0.0 && !pretesCbtResultPassed($r0), 'Nilai 0 → tidak lulus');

// Integrasi
$root = dirname(__DIR__);
$view = file_get_contents("$root/includes/pretes-participant-view.php");
$check(strpos($view, 'renderPretesCbtResultCard(') !== false && strpos($view, 'renderPretesCbtResultCard(') < strpos($view, '<?php elseif ($isUjianDibuka): ?>'), 'Kartu hasil menggantikan kotak akses soal');
$check(str_contains($view, "=== 'already_passed' ? 'Anda Sudah Dinyatakan Lulus Pretes'"), 'Judul lulus pretes tidak lagi "Belum Dibuka"');
$active = file_get_contents("$root/includes/pretes-active-page.php");
$check(str_contains($active, 'pretesCbtResultFor($pdo, (int)$user[\'id\'], (int)($myReg[\'id\'] ?? 0))') && str_contains($active, "/tutorial-rpl.php"), 'Halaman aktif menghitung hasil CBT & link tutorial');
$page = file_get_contents("$root/pages/tutorial-rpl.php");
$check(str_contains($page, 'Pendaftaran Tutorial RPL Gelombang 1 dimulai tanggal <strong>23 sampai 27 November 2026</strong>') && str_contains($page, 'requireLogin()'), 'Halaman Pendaftaran Tutorial RPL Gelombang 1');

if ($failures) { echo "FAIL:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
echo "PASS: kartu hasil CBT pretes (berjalan, ≥80 tugas online, tepat 80, <80 link Tutorial RPL, 0) + halaman tutorial RPL.\n";
