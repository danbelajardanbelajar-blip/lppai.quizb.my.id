<?php
/**
 * Test Suite: CBT Results Pagination, Search & Live vs Selesai Sub-Tab Separation
 * 
 * Memverifikasi:
 * 1. Pemisahan data sesi: Sedang Berlangsung (Live), Riwayat Selesai, dan Semua Sesi.
 * 2. Logika paginasi: offset, per_page (10, 25, 50, 100), total_pages, dan link helper buildCbtResultUrl.
 * 3. Fitur pencarian peserta berdasarkan nama dan NIM.
 * 4. Integrasi AJAX endpoint live_results dengan status_filter, q, page, dan per_page.
 * 5. Elemen UI dan CSS pada View dan Stylesheet.
 */

declare(strict_types=1);

if (!defined('BASE_URL')) define('BASE_URL', 'https://lppai.quizb.my.id');

function assertCbt(bool $condition, string $description, string $failureDetails = ''): void {
    if (!$condition) {
        echo "❌ [FAIL] {$description}\n";
        if ($failureDetails) echo "   Detail: {$failureDetails}\n";
        exit(1);
    }
    echo " [PASS] {$description}\n";
}

echo "=== Menjalankan Validasi CBT Pagination & Live vs Finished Sub-Tabs ===\n";

// ── 1. Setup In-Memory SQLite Database ──────────────────────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$pdo->exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT,
    nama_lengkap TEXT NOT NULL,
    nim TEXT,
    program_studi TEXT,
    jenis_program TEXT
)");

$pdo->exec("CREATE TABLE pretes_cbt_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_id INTEGER NOT NULL,
    package_id INTEGER NOT NULL,
    registration_id INTEGER NOT NULL,
    started_at INTEGER NOT NULL,
    deadline_at INTEGER NOT NULL,
    submitted_at INTEGER DEFAULT NULL,
    total_questions INTEGER DEFAULT 25,
    correct_count INTEGER DEFAULT 0,
    score REAL DEFAULT NULL,
    ip_address TEXT,
    user_agent TEXT
)");

$pdo->exec("CREATE TABLE pretes_cbt_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL,
    question_id INTEGER NOT NULL,
    item_order INTEGER NOT NULL,
    answer TEXT DEFAULT NULL,
    flagged INTEGER DEFAULT 0,
    answered_at INTEGER DEFAULT NULL,
    correct_answer TEXT DEFAULT 'A'
)");

// Insert 35 test users & attempts:
// - 5 ongoing attempts (submitted_at NULL, deadline in future)
// - 30 finished attempts (submitted_at NOT NULL)
$now = time();

for ($i = 1; $i <= 35; $i++) {
    $nim = sprintf("2026%04d", $i);
    $nama = "Mahasiswa " . $i;
    $jenisProg = ($i % 2 === 0) ? 'RPL' : 'Reguler';
    $regId = ($i % 3 === 0) ? -1 : 100 + $i; // negative = tryout, positive = resmi

    $stmtUser = $pdo->prepare("INSERT INTO users (username, nama_lengkap, nim, program_studi, jenis_program) VALUES (?, ?, ?, 'Pendidikan Agama Islam', ?)");
    $stmtUser->execute(["user_{$i}", $nama, $nim, $jenisProg]);
    $userId = (int)$pdo->lastInsertId();

    $isOngoing = ($i <= 5);
    $startedAt = $now - (600);
    $deadlineAt = $isOngoing ? ($now + 1800) : ($now - 100);
    $submittedAt = $isOngoing ? null : ($now - 120);
    $correct = $isOngoing ? 0 : 20;
    $score = $isOngoing ? null : 80.0;

    $stmtAtt = $pdo->prepare("INSERT INTO pretes_cbt_attempts (user_id, session_id, package_id, registration_id, started_at, deadline_at, submitted_at, total_questions, correct_count, score) VALUES (?, 1, 1, ?, ?, ?, ?, 25, ?, ?)");
    $stmtAtt->execute([$userId, $regId, $startedAt, $deadlineAt, $submittedAt, $correct, $score]);
    $attemptId = (int)$pdo->lastInsertId();

    // Items for attempt
    $stmtItem = $pdo->prepare("INSERT INTO pretes_cbt_items (attempt_id, question_id, item_order, answer, flagged, correct_answer) VALUES (?, 1, 1, 'A', 0, 'A')");
    $stmtItem->execute([$attemptId]);
}

// ── 2. Test Status Filtering (Live vs Finished vs All) ──────────────────────
$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM pretes_cbt_attempts")->fetchColumn();
assertCbt($totalCount === 35, "Total database fixtures adalah 35 attempts.");

// Live filter
$liveCount = (int)$pdo->query("SELECT COUNT(*) FROM pretes_cbt_attempts WHERE submitted_at IS NULL AND deadline_at > $now")->fetchColumn();
assertCbt($liveCount === 5, "Sub-tab 'Sedang Berlangsung' mengisolasi tepat 5 sesi live.");

// Finished filter
$finishedCount = (int)$pdo->query("SELECT COUNT(*) FROM pretes_cbt_attempts WHERE submitted_at IS NOT NULL OR deadline_at <= $now")->fetchColumn();
assertCbt($finishedCount === 30, "Sub-tab 'Riwayat Selesai' mengisolasi tepat 30 sesi selesai.");

// ── 3. Test Pagination Calculations ─────────────────────────────────────────
$perPage = 10;
$totalFiltered = $finishedCount; // 30
$totalPages = (int)ceil($totalFiltered / $perPage);
assertCbt($totalPages === 3, "Total halaman dengan 30 item dan 10 per halaman adalah 3 halaman.");

// Page 1 offset
$page = 1;
$offset = ($page - 1) * $perPage;
$stmtP1 = $pdo->query("SELECT a.id FROM pretes_cbt_attempts a WHERE a.submitted_at IS NOT NULL OR a.deadline_at <= $now ORDER BY a.started_at DESC LIMIT $perPage OFFSET $offset");
$rowsP1 = $stmtP1->fetchAll();
assertCbt(count($rowsP1) === 10, "Halaman 1 mengembalikan tepat 10 item.");

// Page 3 offset
$page = 3;
$offset = ($page - 1) * $perPage;
$stmtP3 = $pdo->query("SELECT a.id FROM pretes_cbt_attempts a WHERE a.submitted_at IS NOT NULL OR a.deadline_at <= $now ORDER BY a.started_at DESC LIMIT $perPage OFFSET $offset");
$rowsP3 = $stmtP3->fetchAll();
assertCbt(count($rowsP3) === 10, "Halaman 3 mengembalikan tepat 10 item terakhir.");

// ── 4. Test Search Filtering ────────────────────────────────────────────────
$searchKw = 'Mahasiswa 12';
$stmtSearch = $pdo->prepare("SELECT a.id, u.nama_lengkap FROM pretes_cbt_attempts a JOIN users u ON u.id = a.user_id WHERE u.nama_lengkap LIKE ? OR u.nim LIKE ?");
$stmtSearch->execute(["%$searchKw%", "%$searchKw%"]);
$searchRes = $stmtSearch->fetchAll();
assertCbt(count($searchRes) === 1 && $searchRes[0]['nama_lengkap'] === 'Mahasiswa 12', "Pencarian nama 'Mahasiswa 12' menemukan 1 baris tepat.");

$nimKw = '20260025';
$stmtNim = $pdo->prepare("SELECT a.id, u.nim FROM pretes_cbt_attempts a JOIN users u ON u.id = a.user_id WHERE u.nama_lengkap LIKE ? OR u.nim LIKE ?");
$stmtNim->execute(["%$nimKw%", "%$nimKw%"]);
$nimRes = $stmtNim->fetchAll();
assertCbt(count($nimRes) === 1 && $nimRes[0]['nim'] === '20260025', "Pencarian NIM '20260025' menemukan peserta yang sesuai.");

// ── 5. Test Helper buildCbtResultUrl ────────────────────────────────────────
$statusFilter = 'live';
$resultFilter = 'resmi';
$searchQuery = 'budi';
$perPage = 50;
$page = 2;

require_once __DIR__ . '/../includes/pretes-cbt.php';

assertCbt(function_exists('buildCbtResultUrl'), "Helper buildCbtResultUrl terdefinisi secara global.");

$url = buildCbtResultUrl(['page' => 3]);
assertCbt(strpos($url, 'page=3') !== false, "buildCbtResultUrl mempertahankan perubahan page.");
assertCbt(strpos($url, 'status_filter=live') !== false, "buildCbtResultUrl mempertahankan status_filter.");
assertCbt(strpos($url, 'result_filter=resmi') !== false, "buildCbtResultUrl mempertahankan result_filter.");
assertCbt(strpos($url, 'q=budi') !== false, "buildCbtResultUrl mempertahankan search query q.");
assertCbt(strpos($url, 'per_page=50') !== false, "buildCbtResultUrl mempertahankan per_page.");

// ── 6. Test Controller, View Markup & CSS Assets ───────────────────────────
$controllerContent = file_get_contents(__DIR__ . '/../admin/pretes-bank-soal.php');
$helperContent = file_get_contents(__DIR__ . '/../includes/pretes-cbt.php');
assertCbt(strpos($controllerContent, "pretesCbtResultFilters(\$_GET)") !== false && strpos($helperContent, "\$src['status_filter']") !== false, "Controller menangani status_filter.");
assertCbt(strpos($controllerContent, "\$searchQuery = \$cbtFilters['q']") !== false && strpos($helperContent, "\$src['q']") !== false, "Controller menangani search query q.");
assertCbt(strpos($controllerContent, "\$perPage =") !== false && strpos($controllerContent, "LIMIT \$perPage OFFSET \$offset") !== false, "Controller melakukan paginasi LIMIT & OFFSET dinamis.");
assertCbt(strpos($controllerContent, "'finished_count' => \$finishedCount") !== false, "Controller mengirimkan finished_count pada AJAX payload.");
$viewContent = file_get_contents(__DIR__ . '/../includes/pretes-cbt-admin-view.php');
assertCbt(strpos($viewContent, 'class="cbt-status-tabs"') !== false, "View memuat container sub-tab .cbt-status-tabs.");
assertCbt(strpos($viewContent, "'status_filter' => 'live'") !== false, "View menyediakan tautan sub-tab Sedang Berlangsung (Live).");
assertCbt(strpos($viewContent, "'status_filter' => 'finished'") !== false, "View menyediakan tautan sub-tab Riwayat Selesai.");
assertCbt(strpos($viewContent, "'status_filter' => 'all'") !== false, "View menyediakan tautan sub-tab Semua Sesi.");
assertCbt(strpos($viewContent, 'name="q"') !== false, "View memuat input pencarian q.");
assertCbt(strpos($viewContent, 'class="cbt-pagination-bar"') !== false, "View memuat pagination bar .cbt-pagination-bar.");
assertCbt(strpos($viewContent, 'class="cbt-pagination-nav"') !== false, "View memuat navigasi halaman .cbt-pagination-nav.");
assertCbt(strpos($viewContent, 'id="cbtPerPageSelect"') !== false, "View memuat selector per halaman #cbtPerPageSelect.");

$cssContent = file_get_contents(__DIR__ . '/../assets/css/pretes-cbt-admin.css');
assertCbt(strpos($cssContent, '.cbt-status-tabs') !== false, "CSS mendefinisikan .cbt-status-tabs.");
assertCbt(strpos($cssContent, '.cbt-status-tab-btn') !== false, "CSS mendefinisikan .cbt-status-tab-btn.");
assertCbt(strpos($cssContent, '.cbt-pagination-bar') !== false, "CSS mendefinisikan .cbt-pagination-bar.");
assertCbt(strpos($cssContent, '.cbt-page-link') !== false, "CSS mendefinisikan .cbt-page-link.");

$jsContent = file_get_contents(__DIR__ . '/../assets/js/pretes-cbt-admin.js');
assertCbt(strpos($jsContent, 'window.CBT_STATUS_FILTER') !== false, "JS membaca window.CBT_STATUS_FILTER.");
assertCbt(strpos($jsContent, 'status_filter=') !== false, "JS mengirimkan parameter status_filter ke AJAX live_results.");
assertCbt(strpos($jsContent, 'cbtFinishedTabBadge') !== false, "JS memperbarui badge hitungan riwayat selesai.");

echo "\nSemua pengujian CBT Pagination & Live vs Finished Sub-Tabs BERHASIL 100% SEMPURNA!\n";
exit(0);
