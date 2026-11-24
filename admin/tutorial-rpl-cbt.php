<?php
/**
 * LPPAI Corner - Admin: Pengaturan Sesi & Paket Soal Ujian Tulis Tutorial RPL (CBT)
 * Arsitektur modern berparitas dengan CBT Pretes: 4 Tab (Paket Soal, Sesi Ujian, Impor Excel, Hasil Ujian).
 */
define('PAGE_TITLE', 'Paket Soal & Sesi CBT Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
require_once __DIR__ . '/../includes/pretes-cbt-xlsx.php';
requireAdmin();

$pdo = getDBConnection();
ensureTutorialRplTables($pdo);
ensureTutorialRplCbtTables($pdo);

// ── Penanganan Unduhan Berkas Excel ──────────────────────────────────────────
if (isset($_GET['download'])) {
    $dl = $_GET['download'];
    if ($dl === 'template') {
        $tplPath = __DIR__ . '/../downloads/template-bank-soal-cbt.xlsx';
        if (file_exists($tplPath)) {
            cbtSendXlsx($tplPath, 'template-bank-soal-tutorial-rpl.xlsx');
        } else {
            // Bangun template Excel secara on-the-fly
            $rows = [
                ['Kode Soal', 'Kategori', 'Pertanyaan', 'Pilihan A', 'Pilihan B', 'Pilihan C', 'Pilihan D', 'Pilihan E', 'Kunci', 'Status'],
                ['TUTOR-RPL-01', 'Thaharah', 'Rukun wudhu yang pertama sesuai syariat Islam adalah...', 'Niat', 'Membasuh muka', 'Membasuh kedua tangan', 'Mengusap kepala', 'Tertib', 'A', 'Aktif'],
                ['TUTOR-RPL-02', 'Sholat Wajib', 'Membaca Surat Al-Fatihah dalam setiap rakaat sholat fardhu termasuk...', 'Syarat sah sholat', 'Rukun sholat', 'Sunnah ab\'adh', 'Sunnah hai\'ah', 'Syarat wajib', 'B', 'Aktif']
            ];
            $gen = (function() use ($rows) { foreach ($rows as $r) yield $r; })();
            cbtSendXlsx(cbtWriteXlsx($gen, 'Template Bank Soal', [16, 20, 48, 24, 24, 24, 24, 24, 10, 12]), 'template-bank-soal-tutorial-rpl.xlsx', true);
        }
        exit;
    } elseif ($dl === 'bank') {
        $pkgId = isset($_GET['package_id']) && (int)$_GET['package_id'] > 0 ? (int)$_GET['package_id'] : null;
        $rows = tutorialRplCbtExportQuestions($pdo, $pkgId);
        $gen = (function() use ($rows) { foreach ($rows as $r) yield $r; })();
        $suffix = $pkgId ? '-paket-' . $pkgId : '';
        cbtSendXlsx(cbtWriteXlsx($gen, 'Bank Soal RPL', [16, 26, 20, 50, 24, 24, 24, 24, 24, 10, 12]), 'bank-soal-tutorial-rpl' . $suffix . '-' . date('Ymd') . '.xlsx', true);
        exit;
    } elseif ($dl === 'results') {
        $rows = tutorialRplCbtExportResults($pdo);
        $gen = (function() use ($rows) { foreach ($rows as $r) yield $r; })();
        cbtSendXlsx(cbtWriteXlsx($gen, 'Hasil CBT RPL', [14, 26, 22, 22, 22, 26, 24, 18, 18, 16, 14, 12, 12, 22]), 'hasil-cbt-tutorial-rpl-' . date('Ymd') . '.xlsx', true);
        exit;
    }
}

// ── Endpoint AJAX Responsif ──────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_GET['ajax'] === 'package_questions') {
        $pkgId = (int)($_GET['package_id'] ?? 0);
        $pkg = $pkgId > 0 ? tutorialRplCbtGetPackage($pdo, $pkgId) : null;
        $qList = tutorialRplCbtGetQuestions($pdo, null, $pkgId > 0 ? $pkgId : null);
        foreach ($qList as &$qItem) {
            $qItem['options'] = json_decode((string)$qItem['options_json'], true) ?: [];
        }
        unset($qItem);
        echo json_encode([
            'success' => true,
            'package' => $pkg,
            'questions' => $qList
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_GET['ajax'] === 'generate_token') {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $newToken = '';
        for ($i = 0; $i < 6; $i++) {
            $newToken .= $chars[random_int(0, strlen($chars) - 1)];
        }
        echo json_encode(['token' => $newToken]);
        exit;
    }
    if ($_GET['ajax'] === 'get_question') {
        $qId = (int)($_GET['id'] ?? 0);
        $q = tutorialRplCbtGetQuestion($pdo, $qId);
        if ($q) {
            $q['options'] = json_decode((string)$q['options_json'], true) ?: [];
            echo json_encode(['success' => true, 'question' => $q]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Soal tidak ditemukan.']);
        }
        exit;
    }
    if ($_GET['ajax'] === 'review_attempt') {
        $attId = (int)($_GET['id'] ?? 0);
        $att = tutorialRplCbtGetAttempt($pdo, $attId);
        if (!$att) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Attempt tidak ditemukan.']);
            exit;
        }
        $items = tutorialRplCbtGetAttemptItems($pdo, $attId);
        $formatted = [];
        foreach ($items as $it) {
            $opts = json_decode((string)$it['options_json'], true) ?: [];
            $formatted[] = [
                'position' => (int)$it['position'],
                'category' => $it['category'],
                'question_text' => $it['question_text'],
                'options' => $opts,
                'correct_answer' => $it['correct_answer'],
                'student_answer' => $it['answer'],
                'is_correct' => ($it['answer'] !== null && strtoupper($it['answer']) === strtoupper($it['correct_answer'])),
                'flagged' => (int)$it['flagged']
            ];
        }
        echo json_encode([
            'success' => true,
            'attempt' => $att,
            'items' => $formatted
        ]);
        exit;
    }
}

$message = '';
$msgType = '';

$allowedTabs = ['packages', 'sessions', 'import', 'results'];
$activeTab = $_GET['tab'] ?? 'packages';
if ($activeTab === 'questions') {
    $activeTab = 'packages';
} elseif ($activeTab === 'session') {
    $activeTab = 'sessions';
} elseif ($activeTab === 'attempts') {
    $activeTab = 'results';
}
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'packages';
}

// ── Handle POST Actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_package') {
        $pkgId = (int)($_POST['package_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $duration = (int)($_POST['duration_minutes'] ?? 90);
        $questionCount = (int)($_POST['question_count'] ?? 25);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            $message = 'Nama paket soal wajib diisi.';
            $msgType = 'danger';
        } else {
            try {
                tutorialRplCbtSavePackage($pdo, [
                    'id' => $pkgId,
                    'code' => $code,
                    'name' => $name,
                    'description' => $desc,
                    'duration_minutes' => $duration,
                    'question_count' => $questionCount,
                    'is_active' => $isActive
                ]);
                $message = ($pkgId > 0) ? 'Paket soal berhasil diperbarui.' : 'Paket soal baru berhasil dibuat.';
                $msgType = 'success';
            } catch (Exception $e) {
                $message = 'Error: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
        $activeTab = 'packages';
    } elseif ($action === 'activate_package') {
        $pkgId = (int)($_POST['package_id'] ?? 0);
        if ($pkgId > 0 && tutorialRplCbtActivatePackage($pdo, $pkgId)) {
            $message = "Paket soal #{$pkgId} berhasil diaktifkan.";
            $msgType = 'success';
        }
        $activeTab = 'packages';
    } elseif ($action === 'delete_package') {
        $pkgId = (int)($_POST['package_id'] ?? 0);
        if ($pkgId > 2 && tutorialRplCbtDeletePackage($pdo, $pkgId)) {
            $message = "Paket soal #{$pkgId} berhasil dihapus.";
            $msgType = 'success';
        } else {
            $message = 'Paket inti utama tidak dapat dihapus demi integritas data ujian.';
            $msgType = 'danger';
        }
        $activeTab = 'packages';
    } elseif ($action === 'update_session') {
        $sesId = (int)($_POST['session_id'] ?? 1);
        $title = trim($_POST['title'] ?? '');
        $duration = (int)($_POST['duration_minutes'] ?? 90);
        $passingGrade = (float)($_POST['passing_grade'] ?? 70.0);
        $token = strtoupper(trim($_POST['token'] ?? 'TUTOR26'));
        $examOpen = isset($_POST['exam_open']) ? 1 : 0;
        $pkgId = (int)($_POST['package_id'] ?? ($sesId === 2 ? 2 : 1));

        $ok = tutorialRplCbtUpdateSession($pdo, [
            'id' => $sesId,
            'title' => $title,
            'package_ids_json' => json_encode([$pkgId]),
            'duration_minutes' => $duration,
            'passing_grade' => $passingGrade,
            'token' => $token,
            'exam_open' => $examOpen
        ]);

        if ($ok) {
            $message = "Pengaturan sesi #{$sesId} CBT Tutorial RPL berhasil disimpan.";
            $msgType = 'success';
        } else {
            $message = 'Gagal menyimpan pengaturan sesi.';
            $msgType = 'danger';
        }
        $activeTab = 'sessions';
    } elseif ($action === 'toggle_exam') {
        $sesId = (int)($_POST['session_id'] ?? 1);
        $sessionToToggle = tutorialRplCbtGetSession($pdo, $sesId);
        $newStatus = empty($sessionToToggle['exam_open']) ? 1 : 0;
        tutorialRplCbtUpdateSession($pdo, array_merge($sessionToToggle, ['exam_open' => $newStatus]));
        $statusLabel = $newStatus ? '🟢 DIBUKA' : '🔴 DITUTUP';
        $message = "Gerbang {$sessionToToggle['title']} berhasil {$statusLabel}.";
        $msgType = 'success';
        $activeTab = 'sessions';
    } elseif ($action === 'save_question') {
        $qId = (int)($_POST['question_id'] ?? 0);
        $packageId = max(1, (int)($_POST['package_id'] ?? 1));
        $category = trim($_POST['category'] ?? 'Umum');
        $questionText = trim($_POST['question_text'] ?? '');
        $options = [
            trim($_POST['opt_a'] ?? ''),
            trim($_POST['opt_b'] ?? ''),
            trim($_POST['opt_c'] ?? ''),
            trim($_POST['opt_d'] ?? ''),
            trim($_POST['opt_e'] ?? '')
        ];
        $correct = strtoupper(trim($_POST['correct_answer'] ?? 'A'));

        if ($questionText === '' || $options[0] === '' || $options[1] === '') {
            $message = 'Teks pertanyaan dan pilihan minimal A & B wajib diisi.';
            $msgType = 'danger';
        } else {
            try {
                tutorialRplCbtSaveQuestion($pdo, [
                    'id' => $qId,
                    'package_id' => $packageId,
                    'category' => $category,
                    'question_text' => $questionText,
                    'options' => $options,
                    'correct_answer' => $correct
                ]);
                $message = ($qId > 0) ? 'Butir soal berhasil diperbarui.' : 'Butir soal baru berhasil ditambahkan ke paket soal.';
                $msgType = 'success';
            } catch (Exception $e) {
                $message = 'Error: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
        $activeTab = 'packages';
    } elseif ($action === 'delete_question') {
        $qId = (int)($_POST['question_id'] ?? 0);
        $ok = ($qId > 0 && tutorialRplCbtDeleteQuestion($pdo, $qId));
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Butir soal berhasil dihapus.' : 'Gagal menghapus butir soal.']);
            exit;
        }
        if ($ok) {
            $message = 'Butir soal berhasil dihapus dari paket soal.';
            $msgType = 'success';
        } else {
            $message = 'Gagal menghapus butir soal.';
            $msgType = 'danger';
        }
        $activeTab = 'packages';
    } elseif ($action === 'reset_default_questions' || $action === 'reset_pretes_parity_packages') {
        $res = tutorialRplCbtSeedPackagesAndQuestions($pdo, true);
        $message = "Paket soal dan sesi CBT Tutorial RPL berhasil disinkronkan & ditindih dengan paket soal CBT Pretes ({$res['total_questions']} butir soal: {$res['questions_islam']} Muslim + {$res['questions_kristen']} Kristen Protestan).";
        $msgType = 'success';
        $activeTab = 'packages';
    } elseif ($action === 'import_xlsx') {
        $activeTab = 'import';
        $packageId = max(1, (int)($_POST['package_id'] ?? 1));
        $file = $_FILES['question_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $message = 'Gagal mengunggah file. Pastikan ukuran file tidak melebihi batas server.';
            $msgType = 'danger';
        } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx' || !is_uploaded_file($file['tmp_name'])) {
            $message = 'Gunakan berkas format Excel (.xlsx) sesuai template bank soal.';
            $msgType = 'danger';
        } else {
            try {
                $rows = cbtReadQuestionXlsx($file['tmp_name']);
                $updateExisting = isset($_POST['update_existing']);
                $res = tutorialRplCbtImportQuestions($pdo, $rows, $updateExisting, $packageId);
                if (!empty($res['errors'])) {
                    $message = "Impor selesai dengan catatan: {$res['added']} ditambah, {$res['updated']} diperbarui. Terdapat " . count($res['errors']) . " baris bermasalah.";
                    $msgType = 'warning';
                } else {
                    $message = "Sukses mengimpor bank soal ke Paket {$packageId}: {$res['added']} butir soal baru ditambahkan, {$res['updated']} diperbarui.";
                    $msgType = 'success';
                    $activeTab = 'packages';
                }
            } catch (Exception $e) {
                $message = 'Error impor Excel: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'reset_attempt') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        if ($userId > 0 && $classId > 0 && tutorialRplCbtResetAttempt($pdo, $userId, $classId)) {
            $message = 'Attempt ujian peserta berhasil di-reset. Peserta kini dapat mengulang ujian kembali.';
            $msgType = 'success';
        } else {
            $message = 'Gagal me-reset attempt ujian peserta.';
            $msgType = 'danger';
        }
        $activeTab = 'results';
    }
}

$packages = tutorialRplCbtGetPackages($pdo);
$sessions = tutorialRplCbtGetSessions($pdo);
$session = tutorialRplCbtGetSession($pdo, 1);
$sessionKristen = tutorialRplCbtGetSession($pdo, 2);

$selectedPackageId = (isset($_GET['package_id']) && $_GET['package_id'] !== '') ? (int)$_GET['package_id'] : null;
$questions = tutorialRplCbtGetQuestions($pdo, null, $selectedPackageId);
$attempts = tutorialRplCbtGetAttempts($pdo);

// Kategori unik untuk filter
$categories = [];
foreach ($questions as $q) {
    if (!empty($q['category']) && !in_array($q['category'], $categories, true)) {
        $categories[] = $q['category'];
    }
}
sort($categories);

// Statistik Hasil Ujian
$totalAttempts = count($attempts);
$passingGrade = (float)($session['passing_grade'] ?? 70.0);
$passCount = 0;
$failCount = 0;
$totalScore = 0.0;
foreach ($attempts as $a) {
    $sc = (float)($a['score'] ?? 0);
    $totalScore += $sc;
    if ($sc >= $passingGrade) {
        $passCount++;
    } else {
        $failCount++;
    }
}
$avgScore = $totalAttempts > 0 ? round($totalScore / $totalAttempts, 1) : 0.0;
$passPercent = $totalAttempts > 0 ? round(($passCount / $totalAttempts) * 100, 1) : 0.0;

include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pretes-cbt-admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/pretes-cbt-admin.css') ?>">

<div class="bank-app">
    <!-- Hero Header -->
    <div class="bank-hero" style="margin-bottom:20px;">
        <div>
            <div class="bank-eyebrow">LPPAI / CBT TUTORIAL RPL</div>
            <h2>🧩 Paket Soal &amp; Sesi Ujian Tulis Tutorial RPL</h2>
            <p>Kelola paket materi soal, sesi ujian 90 menit (passing grade <?= $passingGrade ?>), impor Excel, serta pantau hasil ujian mahasiswa secara real-time.</p>
        </div>
        <div class="bank-actions">
            <button type="button" class="bank-button primary" onclick="openAddModal();">
                ➕ Tambah Soal
            </button>
            <a href="?download=template" class="bank-button ghost">
                📥 Unduh Template (.xlsx)
            </a>
            <a href="?download=bank" class="bank-button ghost">
                📤 Ekspor Paket Soal (.xlsx)
            </a>
            <a href="?download=results" class="bank-button ghost">
                📊 Ekspor Hasil Ujian (.xlsx)
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="bank-notice <?= $msgType === 'danger' ? 'error' : ($msgType === 'warning' ? 'warning' : '') ?>" style="margin-bottom:20px;">
            <?= ($msgType === 'success' ? '✅ ' : '⚠️ ') . sanitize($message) ?>
        </div>
    <?php endif; ?>

    <!-- 4 Quick Metric Cards -->
    <div class="bank-stats">
        <div class="bank-stat">
            <small>Status Gerbang &amp; Token</small>
            <strong style="color:<?= !empty($session['exam_open']) ? '#15803d' : '#b91c1c' ?>;">
                <?= !empty($session['exam_open']) ? '🟢 BUKA' : '🔴 TUTUP' ?>
            </strong>
            <span>Token: <span class="bank-badge token-badge"><?= sanitize($session['token'] ?? 'TUTOR26') ?></span></span>
        </div>
        <div class="bank-stat">
            <small>Total Butir Soal</small>
            <strong style="color:#0f766e;"><?= count($questions) ?> Butir</strong>
            <span><?= count($packages) ?> Paket Soal · <?= count($categories) ?> Kategori</span>
        </div>
        <div class="bank-stat">
            <small>Durasi &amp; Standar Kelulusan</small>
            <strong style="color:#0369a1;"><?= (int)$session['duration_minutes'] ?> Menit</strong>
            <span>Passing Grade: <strong><?= $passingGrade ?></strong></span>
        </div>
        <div class="bank-stat">
            <small>Peserta Selesai &amp; Rata-rata</small>
            <strong style="color:#7c3aed;"><?= $totalAttempts ?> Mahasiswa</strong>
            <span>Lulus: <?= $passCount ?> (<?= $passPercent ?>%) · Rerata: <?= $avgScore ?></span>
        </div>
    </div>

    <!-- 4 Modern Tabs (Berparitas 100% dengan CBT Pretes) -->
    <div class="bank-tabs">
        <a href="?tab=packages" class="<?= $activeTab === 'packages' ? 'active' : '' ?>">
            📦 Paket Soal <span class="bank-tab-count"><?= count($packages) ?></span>
        </a>
        <a href="?tab=sessions" class="<?= $activeTab === 'sessions' ? 'active' : '' ?>">
            ⚙️ Sesi Ujian <span class="bank-tab-count"><?= count($sessions) ?></span>
        </a>
        <a href="?tab=import" class="<?= $activeTab === 'import' ? 'active' : '' ?>">
            📥 Impor Excel
        </a>
        <a href="?tab=results" class="<?= $activeTab === 'results' ? 'active' : '' ?>">
            📊 Hasil Ujian <span class="bank-tab-count"><?= $totalAttempts ?></span>
        </a>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         TAB 1: PAKET SOAL (Berparitas 100% dengan CBT Pretes)
         ═══════════════════════════════════════════════════════════════════════ -->
    <?php if ($activeTab === 'packages'): ?>
        <!-- Panel 1: Daftar Paket Soal (Muslim & Kristen Protestan) -->
        <div class="bank-panel" style="margin-bottom:24px;">
            <div class="bank-panel-head">
                <div>
                    <h3>Daftar Paket Soal CBT Tutorial RPL</h3>
                    <p>Kelola wadah materi soal ujian. Paket 1 Utama (Muslim) &amp; Paket 2 Kristen Protestan.</p>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <form method="POST" style="margin:0;" onsubmit="return confirm('Tindih seluruh data soal Tutorial RPL dengan butir soal resmi CBT Pretes (40 Muslim + 25 Kristen Protestan)? Tindakan ini akan menimpa data soal lama.');">
                        <input type="hidden" name="action" value="reset_pretes_parity_packages">
                        <button type="submit" class="bank-button" style="background:#fef3c7; border-color:#fde047; color:#92400e; font-weight:700;">
                            🔄 Pulihkan &amp; Tindih Soal CBT Pretes
                        </button>
                    </form>
                    <button type="button" class="bank-button gold" onclick="openPackageModal();" data-action="create-package">
                        📦 ＋ Buat Paket Baru
                    </button>
                    <button type="button" class="bank-button primary" onclick="openAddModal();" data-action="add-question">
                        ➕ Tambah Butir Soal
                    </button>
                </div>
            </div>

            <!-- Tabel Daftar Paket Soal -->
            <div class="bank-table-scroll">
                <table class="bank-table no-datatable">
                    <thead>
                        <tr>
                            <th style="width:40px;">No.</th>
                            <th style="width:130px;">Kode</th>
                            <th>Nama Paket &amp; Deskripsi</th>
                            <th style="text-align:center; width:100px;">Durasi</th>
                            <th style="text-align:center; width:110px;">Target Soal</th>
                            <th style="text-align:center; width:140px;">Soal Terdaftar</th>
                            <th style="text-align:center; width:130px;">Status</th>
                            <th style="text-align:right; width:220px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($packages)): ?>
                            <tr>
                                <td colspan="8" class="bank-empty">
                                    Belum ada paket soal dibuat. Klik tombol <strong>＋ Buat Paket Baru</strong> untuk membuat paket soal.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($packages as $pIdx => $pkg):
                                $isP2 = ((int)$pkg['id'] === 2);
                            ?>
                                <tr>
                                    <td style="color:#64748b; font-weight:700;"><?= $pIdx + 1 ?></td>
                                    <td><span class="bank-code"><?= sanitize($pkg['code']) ?></span></td>
                                    <td>
                                        <strong style="color:#0f172a; font-size:13.5px;"><?= sanitize($pkg['name']) ?></strong>
                                        <?php if (!empty($pkg['description'])): ?>
                                            <div style="font-size:12px; color:#64748b; margin-top:3px;"><?= sanitize($pkg['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;"><span class="bank-badge"><?= (int)$pkg['duration_minutes'] ?> Menit</span></td>
                                    <td style="text-align:center;"><span class="bank-badge"><?= (int)$pkg['question_count'] ?> Butir</span></td>
                                    <td style="text-align:center;">
                                        <strong style="color:#047857;"><?= (int)$pkg['question_count_actual'] ?></strong> aktif
                                        <span style="color:#64748b; font-size:11px;">(dari <?= (int)$pkg['total_questions'] ?> total)</span>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if (!empty($pkg['is_active'])): ?>
                                            <span class="bank-badge active">● SEDANG AKTIF</span>
                                        <?php else: ?>
                                            <span class="bank-badge inactive">Tidak Aktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <button type="button" class="bank-button" style="padding:4px 9px; font-size:11px; font-weight:700; background:#f0fdf4; border-color:#86efac; color:#166534;" onclick="openPackageQuestionsModal(<?= (int)$pkg['id'] ?>, <?= htmlspecialchars(json_encode($pkg['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($pkg['code'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>);" data-action="manage-questions" data-package-id="<?= (int)$pkg['id'] ?>" data-package-name="<?= sanitize($pkg['name']) ?>" data-package-code="<?= sanitize($pkg['code']) ?>" title="Lihat &amp; kelola butir pertanyaan paket ini">
                                            📋 Kelola Soal (<?= (int)$pkg['total_questions'] ?>)
                                        </button>
                                        <button type="button" class="bank-button" style="padding:4px 8px; font-size:11px; background:#fef3c7; border-color:#fde047; color:#92400e;" onclick="openAddModal(<?= (int)$pkg['id'] ?>);" data-action="add-question" data-package-id="<?= (int)$pkg['id'] ?>" title="Tambah butir soal ke paket ini">
                                            ＋ Soal
                                        </button>
                                        <button type="button" class="bank-button" style="padding:4px 8px; font-size:11px;" onclick='openEditPackageModal(<?= json_encode($pkg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>);' data-action="edit-package" title="Edit Paket Soal">
                                            ✏️ Edit
                                        </button>
                                        <?php if ((int)$pkg['id'] > 2): ?>
                                            <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('Hapus paket soal ini beserta seluruh butir soal di dalamnya?');">
                                                <input type="hidden" name="action" value="delete_package">
                                                <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
                                                <button type="submit" class="bank-button danger-sm" style="padding:4px 8px; font-size:11px;" title="Hapus Paket Soal">
                                                    🗑️
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Panel 2: Daftar Butir Soal per Paket -->
        <div class="bank-panel" id="questionsSection">
            <div class="bank-panel-head">
                <div>
                    <h3 id="panelQuestionsTitle">Daftar Butir Soal CBT: Semua Paket</h3>
                    <p>Total <?= count($questions) ?> butir soal terdaftar. Telusuri, saring per kategori/paket, atau tambahkan butir pertanyaan baru.</p>
                </div>
                <div style="display:flex; gap:8px;">
                    <a href="?download=bank" id="exportBankBtn" class="bank-button ghost" style="color:#0f766e; border-color:#0f766e;">
                        📤 Ekspor Butir Soal (.xlsx)
                    </a>
                    <button type="button" class="bank-button primary" onclick="openAddModal();">
                        ➕ Tambah Soal
                    </button>
                </div>
            </div>

            <!-- Toolbar Filter (Paket, Kategori, Search) -->
            <div class="bank-filters" style="grid-template-columns: minmax(180px, 1fr) minmax(180px, 1fr) minmax(200px, 1.5fr);">
                <div>
                    <select id="filterPackage" class="bank-input" onchange="filterQuestions();">
                        <option value="">-- Semua Paket Soal (<?= count($questions) ?> Soal) --</option>
                        <?php foreach ($packages as $pkg): ?>
                            <option value="<?= (int)$pkg['id'] ?>" <?= ($selectedPackageId === (int)$pkg['id']) ? 'selected' : '' ?>>
                                📦 <?= sanitize($pkg['name']) ?> (<?= (int)$pkg['total_questions'] ?> Soal)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <select id="filterCategory" class="bank-input" onchange="filterQuestions();">
                        <option value="">-- Semua Kategori --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= sanitize($cat) ?>"><?= sanitize($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <input type="text" id="searchQuestion" class="bank-input" placeholder="🔍 Cari teks pertanyaan atau kata kunci..." oninput="filterQuestions();">
                </div>
            </div>

            <div class="bank-table-scroll">
                <table class="bank-table no-datatable">
                    <thead>
                        <tr>
                            <th style="width:40px;">No</th>
                            <th style="width:160px;">Paket &amp; Kategori</th>
                            <th>Teks Pertanyaan &amp; Opsi Pilihan</th>
                            <th style="width:60px; text-align:center;">Kunci</th>
                            <th style="width:110px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="questionTableBody">
                        <?php if (empty($questions)): ?>
                            <tr>
                                <td colspan="5" class="bank-empty">
                                    Butir soal masih kosong. Klik "Pulihkan &amp; Tindih Soal CBT Pretes" atau "Tambah Soal" untuk mengisi bank soal.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($questions as $idx => $q):
                                $opts = json_decode((string)$q['options_json'], true) ?: [];
                                $labels = ['A', 'B', 'C', 'D', 'E'];
                                $isPkg2 = ((int)($q['package_id'] ?? 1) === 2);
                            ?>
                                <tr class="question-row"
                                    data-package="<?= (int)($q['package_id'] ?? 1) ?>"
                                    data-category="<?= sanitize($q['category']) ?>"
                                    data-text="<?= strtolower(sanitize($q['question_text'] . ' ' . $q['code'])) ?>">
                                    <td style="font-weight:700; color:#64748b;"><?= $idx + 1 ?></td>
                                    <td>
                                        <span class="bank-badge" style="font-size:10.5px; margin-bottom:4px; display:inline-block; font-weight:700; background:<?= $isPkg2 ? '#eff6ff' : '#ecfdf5' ?>; color:<?= $isPkg2 ? '#1d4ed8' : '#047857' ?>; border:1px solid <?= $isPkg2 ? '#bfdbfe' : '#a7f3d0' ?>;">
                                            <?= $isPkg2 ? 'Paket 2 (Kristen)' : 'Paket 1 (Muslim)' ?>
                                        </span>
                                        <span class="bank-badge package" style="font-size:11px;"><?= sanitize($q['category']) ?></span>
                                        <span class="bank-code"><?= sanitize($q['code']) ?></span>
                                    </td>
                                    <td>
                                        <div class="bank-question"><?= nl2br(sanitize($q['question_text'])) ?></div>
                                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:6px; margin-top:8px;">
                                            <?php foreach ($labels as $li => $lbl):
                                                $optVal = $opts[$lbl] ?? ($opts[$li] ?? '');
                                                if ($optVal === '') continue;
                                                $isCorrect = ($q['correct_answer'] === $lbl);
                                            ?>
                                                <div class="bank-choice <?= $isCorrect ? 'correct' : '' ?>">
                                                    <strong><?= $lbl ?>.</strong> <?= sanitize($optVal) ?>
                                                    <?= $isCorrect ? ' <span style="font-weight:700; color:#15803d;">✓ Kunci</span>' : '' ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td style="text-align:center;">
                                        <span class="bank-key"><?= sanitize($q['correct_answer']) ?></span>
                                    </td>
                                    <td style="text-align:center;">
                                        <div style="display:inline-flex; gap:6px;">
                                            <button type="button" class="bank-button" style="padding:4px 8px; min-height:30px;" onclick='openEditModal(<?= json_encode($q, JSON_HEX_APOS | JSON_HEX_QUOT) ?>);' title="Edit Soal">
                                                ✏️
                                            </button>
                                            <form method="POST" style="margin:0;" onsubmit="return confirm('Hapus butir soal ini dari paket soal?');">
                                                <input type="hidden" name="action" value="delete_question">
                                                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                                                <button type="submit" class="bank-button danger-sm" title="Hapus Soal">
                                                    🗑️
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         TAB 2: SESI UJIAN (90 MENIT)
         ═══════════════════════════════════════════════════════════════════════ -->
    <?php if ($activeTab === 'sessions'): ?>
        <div class="bank-panel">
            <div class="bank-panel-head">
                <div>
                    <h3>Daftar &amp; Konfigurasi Sesi Ujian Tulis Tutorial RPL</h3>
                    <p>Berparitas dengan CBT Pretes: Sesi 1 Utama (Muslim) &amp; Sesi 2 Kristen Protestan (Durasi 90 Menit, Passing Grade 70.00).</p>
                </div>
            </div>

            <!-- Tabel Ringkasan Sesi Ujian -->
            <div class="bank-table-scroll" style="margin-bottom:24px;">
                <table class="bank-table no-datatable">
                    <thead>
                        <tr>
                            <th style="width:40px;">ID</th>
                            <th>Nama Sesi Ujian</th>
                            <th>Paket Terhubung</th>
                            <th style="text-align:center;">Durasi</th>
                            <th style="text-align:center;">Passing Grade</th>
                            <th style="text-align:center;">Token Masuk</th>
                            <th style="text-align:center;">Status Gerbang</th>
                            <th style="text-align:center; width:130px;">Aksi Cepat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $s):
                            $sOpen = !empty($s['exam_open']);
                            $sPkgIds = json_decode((string)($s['package_ids_json'] ?? '[]'), true) ?: [1];
                            $sPkgDesc = (in_array(2, $sPkgIds, true) || str_contains($s['code'], 'PROTESTAN')) ? 'Paket 2 (Kristen)' : 'Paket 1 (Muslim)';
                        ?>
                            <tr>
                                <td style="font-weight:700; color:#64748b;"><?= (int)$s['id'] ?></td>
                                <td>
                                    <div style="font-weight:700; color:#0f172a;"><?= sanitize($s['title']) ?></div>
                                    <span class="bank-code"><?= sanitize($s['code']) ?></span>
                                </td>
                                <td>
                                    <span class="bank-badge package"><?= $sPkgDesc ?></span>
                                </td>
                                <td style="text-align:center; font-weight:700; color:#0369a1;">
                                    <?= (int)$s['duration_minutes'] ?> Menit
                                </td>
                                <td style="text-align:center; font-weight:700; color:#0f766e;">
                                    <?= (float)$s['passing_grade'] ?>
                                </td>
                                <td style="text-align:center;">
                                    <span class="bank-badge token-badge"><?= sanitize($s['token']) ?></span>
                                </td>
                                <td style="text-align:center;">
                                    <span class="bank-badge <?= $sOpen ? 'active' : 'danger-sm' ?>" style="font-weight:800;">
                                        <?= $sOpen ? '🟢 BUKA' : '🔴 TUTUP' ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="toggle_exam">
                                        <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                                        <?php if ($sOpen): ?>
                                            <button type="submit" class="bank-button danger-sm" onclick="return confirm('Tutup gerbang sesi <?= sanitize($s['title']) ?>?');">
                                                🔒 Tutup
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="bank-button" style="background:#dcfce7; border-color:#86efac; color:#166534; font-weight:700;" onclick="return confirm('Buka gerbang sesi <?= sanitize($s['title']) ?>?');">
                                                🚀 Buka
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Formulir Edit Sesi -->
            <div class="bank-panel-body" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px;">
                <h4 style="margin:0 0 16px; color:#0f172a;">⚙️ Edit Rincian Sesi Ujian</h4>
                <form method="POST">
                    <input type="hidden" name="action" value="update_session">
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:18px; margin-bottom:18px;">
                        <div class="bank-field">
                            <label class="bank-label">Pilih Sesi yang Dikonfigurasi:</label>
                            <select name="session_id" id="editSessionSelect" class="bank-input" onchange="loadSessionConfig(this.value);" style="font-weight:700;">
                                <?php foreach ($sessions as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>"><?= (int)$s['id'] ?> - <?= sanitize($s['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="bank-field">
                            <label class="bank-label">Paket Soal Terhubung:</label>
                            <select name="package_id" id="sessionPackageSelect" class="bank-input" style="font-weight:700;">
                                <option value="1">📦 Paket 1: Utama (Ubudiyah / Muslim)</option>
                                <option value="2">📦 Paket 2: Kristen Protestan</option>
                            </select>
                        </div>
                        <div class="bank-field">
                            <label class="bank-label">Judul Sesi Ujian:</label>
                            <input type="text" name="title" id="sessionTitleInput" class="bank-input" value="<?= sanitize($session['title'] ?? 'Ujian Tulis Tutorial RPL 2026/2027 (Utama / Muslim)') ?>" required>
                        </div>
                        <div class="bank-field">
                            <label class="bank-label">Token Masuk Ujian:</label>
                            <div class="bank-token-group">
                                <input type="text" id="sessionTokenInput" name="token" class="bank-input" value="<?= sanitize($session['token'] ?? 'TUTOR26') ?>" required style="text-transform:uppercase; font-weight:800; letter-spacing:2px;">
                                <button type="button" class="bank-button" onclick="generateRandomToken();" title="Buat Token Acak">
                                    🎲 Acak
                                </button>
                                <button type="button" class="bank-button" onclick="copyTokenToClipboard();" title="Salin Token">
                                    📋 Salin
                                </button>
                            </div>
                        </div>
                        <div class="bank-field">
                            <label class="bank-label">Durasi Pengerjaan (Menit):</label>
                            <input type="number" name="duration_minutes" id="sessionDurationInput" class="bank-input" value="<?= (int)($session['duration_minutes'] ?? 90) ?>" min="5" max="180" required>
                        </div>
                        <div class="bank-field">
                            <label class="bank-label">Passing Grade (Batas Kelulusan):</label>
                            <input type="number" step="0.1" name="passing_grade" id="sessionPassingGradeInput" class="bank-input" value="<?= (float)($session['passing_grade'] ?? 70.0) ?>" min="0" max="100" required>
                        </div>
                    </div>

                    <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:16px; margin-bottom:20px;">
                        <label class="bank-toggle" style="cursor:pointer;">
                            <input type="checkbox" name="exam_open" id="sessionExamOpenCheck" value="1" <?= !empty($session['exam_open']) ? 'checked' : '' ?> style="width:20px; height:20px;">
                            <div>
                                <strong style="font-size:14px; color:#0f172a;">Buka Gerbang Pengerjaan Sesi Ini (Live)</strong>
                                <div class="bank-note">Saat dicentang, peserta yang dijadwalkan dapat memasukkan token dan mengerjakan soal sesi ini.</div>
                            </div>
                        </label>
                    </div>

                    <div style="display:flex; justify-content:flex-end;">
                        <button type="submit" class="bank-button primary" style="padding:12px 28px; font-size:14px;">
                            💾 Simpan Konfigurasi Sesi
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════════
         TAB 3: IMPOR EXCEL (.XLSX)
         ═══════════════════════════════════════════════════════════════════════ -->
    <?php if ($activeTab === 'import'): ?>
        <div class="bank-panel">
            <div class="bank-panel-head">
                <div>
                    <h3>Impor Bank Soal dari Berkas Excel (.xlsx)</h3>
                    <p>Unggah file Excel berformat template LPPAI untuk menambahkan atau memperbarui butir soal secara massal.</p>
                </div>
                <div>
                    <a href="?download=template" class="bank-button ghost" style="color:#0f766e; border-color:#0f766e;">
                        📥 Unduh Template (.xlsx)
                    </a>
                </div>
            </div>

            <div class="bank-panel-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_xlsx">

                    <div class="bank-import-grid">
                        <div>
                            <div class="bank-field" style="margin-bottom:18px;">
                                <label class="bank-label">Pilih Paket Soal Tujuan Impor:</label>
                                <select name="package_id" class="bank-input" style="font-weight:700;">
                                    <option value="1">📦 Paket 1: Utama (Ubudiyah / Muslim)</option>
                                    <option value="2">📦 Paket 2: Kristen Protestan</option>
                                </select>
                                <span class="bank-note">Butir soal dalam berkas Excel akan dimasukkan ke paket yang dipilih.</span>
                            </div>

                            <label class="bank-upload" id="uploadDropzone">
                                <span class="bank-upload-icon">📊</span>
                                <strong>Pilih atau Tarik Berkas Excel (.xlsx) ke Sini</strong>
                                <span>Gunakan format template standar LPPAI dengan header kolom A–I</span>
                                <input type="file" name="question_file" accept=".xlsx" required style="margin-top:16px;">
                            </label>

                            <div style="margin-top:16px;">
                                <label class="bank-toggle" style="cursor:pointer;">
                                    <input type="checkbox" name="update_existing" value="1" checked>
                                    <div>
                                        <strong>Perbarui butir soal jika Kode Soal sudah ada di sistem (Update Existing)</strong>
                                        <div class="bank-note">Jika kode soal cocok, teks pertanyaan dan pilihan jawaban akan diperbarui. Jika kode belum ada, akan ditambahkan sebagai soal baru.</div>
                                    </div>
                                </label>
                            </div>

                            <div style="margin-top:20px;">
                                <button type="submit" class="bank-button primary" style="width:100%; min-height:44px; font-size:14px;">
                                    🚀 Unggah &amp; Mulai Impor Bank Soal
                                </button>
                            </div>
                        </div>

                        <div>
                            <div class="bank-form-aside">
                                <h4>📋 Petunjuk Susunan Kolom Template:</h4>
                                <ul class="bank-steps" style="font-size:12px;">
                                    <li><b>A</b> <div><strong>Kode Soal</strong> Cth: <code>TUTOR-RPL-01</code> (opsional, jika kosong dibuat otomatis).</div></li>
                                    <li><b>B</b> <div><strong>Kategori</strong> Cth: <code>Thaharah</code>, <code>Sholat Wajib</code>, <code>Ubudiyah</code>.</div></li>
                                    <li><b>C</b> <div><strong>Teks Pertanyaan</strong> Pertanyaan lengkap butir soal.</div></li>
                                    <li><b>D</b> <div><strong>Pilihan A</strong> Teks opsi A.</div></li>
                                    <li><b>E</b> <div><strong>Pilihan B</strong> Teks opsi B.</div></li>
                                    <li><b>F</b> <div><strong>Pilihan C</strong> Teks opsi C (opsional).</div></li>
                                    <li><b>G</b> <div><strong>Pilihan D</strong> Teks opsi D (opsional).</div></li>
                                    <li><b>H</b> <div><strong>Pilihan E</strong> Teks opsi E (opsional).</div></li>
                                    <li><b>I</b> <div><strong>Kunci Jawaban</strong> Huruf kapital <code>A</code>, <code>B</code>, <code>C</code>, <code>D</code>, atau <code>E</code>.</div></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════════
         TAB 4: HASIL UJIAN & MONITORING REAL-TIME
         ═══════════════════════════════════════════════════════════════════════ -->
    <?php if ($activeTab === 'results'): ?>
        <div class="bank-panel">
            <div class="bank-panel-head">
                <div>
                    <h3>Monitoring Hasil &amp; Riwayat Pengerjaan CBT Mahasiswa RPL</h3>
                    <p>Pantau skor nilai, review lembar jawaban per mahasiswa, atau reset attempt ujian jika mahasiswa mengalami kendala.</p>
                </div>
                <div style="display:flex; gap:8px;">
                    <a href="?download=results" class="bank-button ghost" style="color:#0f766e; border-color:#0f766e;">
                        📊 Ekspor ke Excel (.xlsx)
                    </a>
                </div>
            </div>

            <!-- Toolbar Filter Hasil -->
            <div class="bank-filters" style="grid-template-columns: minmax(180px, 1.5fr) minmax(150px, 1fr) minmax(150px, 1fr) minmax(150px, 1fr);">
                <div>
                    <input type="text" id="searchAttempt" class="bank-input" placeholder="🔍 Cari nama mahasiswa / NIM..." oninput="filterAttempts();">
                </div>
                <div>
                    <select id="filterPackageAttempt" class="bank-input" onchange="filterAttempts();">
                        <option value="">-- Semua Sesi / Paket --</option>
                        <option value="1">Paket 1 (Muslim)</option>
                        <option value="2">Paket 2 (Kristen)</option>
                    </select>
                </div>
                <div>
                    <select id="filterPassStatus" class="bank-input" onchange="filterAttempts();">
                        <option value="">-- Semua Status Kelulusan --</option>
                        <option value="lulus">🟢 Lulus (Nilai &ge; <?= $passingGrade ?>)</option>
                        <option value="tidak_lulus">🔴 Belum Memenuhi (&lt; <?= $passingGrade ?>)</option>
                    </select>
                </div>
                <div>
                    <select id="filterClassGroup" class="bank-input" onchange="filterAttempts();">
                        <option value="">-- Semua Kelompok RPL --</option>
                        <?php
                        $groupList = [];
                        foreach ($attempts as $a) {
                            if (!empty($a['nama_kelompok']) && !in_array($a['nama_kelompok'], $groupList, true)) {
                                $groupList[] = $a['nama_kelompok'];
                            }
                        }
                        sort($groupList);
                        foreach ($groupList as $grp):
                        ?>
                            <option value="<?= sanitize($grp) ?>"><?= sanitize($grp) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="bank-table-scroll">
                <table class="bank-table no-datatable">
                    <thead>
                        <tr>
                            <th style="width:35px;">No</th>
                            <th>Mahasiswa</th>
                            <th>Sesi &amp; Paket</th>
                            <th>Kelompok &amp; Tutor</th>
                            <th style="text-align:center;">Mulai &amp; Selesai (WIB)</th>
                            <th style="text-align:center;">Skor Benar</th>
                            <th style="text-align:center;">Nilai CBT</th>
                            <th style="text-align:center;">Status</th>
                            <th style="text-align:center; width:150px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="attemptTableBody">
                        <?php if (empty($attempts)): ?>
                            <tr>
                                <td colspan="9" class="bank-empty">
                                    Belum ada mahasiswa yang mengikuti atau mengumpulkan ujian CBT Tutorial RPL.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($attempts as $idx => $a):
                                $score = (float)($a['score'] ?? 0);
                                $isPass = ($score >= $passingGrade);
                                $isSubmitted = !empty($a['submitted']);
                                $startedWib = (new DateTimeImmutable('@' . $a['started_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i');
                                $submittedWib = !empty($a['submitted_at']) ? (new DateTimeImmutable('@' . $a['submitted_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i') : '-';
                                $aPkgId = (int)($a['package_id'] ?? 1);
                                $isPkg2 = ($aPkgId === 2);
                            ?>
                                <tr class="attempt-row"
                                    data-search="<?= strtolower(sanitize(($a['nama_lengkap'] ?? '') . ' ' . ($a['nim'] ?? ''))) ?>"
                                    data-package="<?= $aPkgId ?>"
                                    data-pass="<?= $isPass ? 'lulus' : 'tidak_lulus' ?>"
                                    data-group="<?= sanitize($a['nama_kelompok'] ?? '') ?>">
                                    <td style="font-weight:700; color:#64748b;"><?= $idx + 1 ?></td>
                                    <td>
                                        <div style="font-weight:700; color:#0f172a; font-size:13px;"><?= sanitize($a['nama_lengkap']) ?></div>
                                        <small style="color:#64748b;">NIM: <strong><?= sanitize($a['nim'] ?: '-') ?></strong> · <?= sanitize($a['program_studi'] ?: '') ?></small>
                                    </td>
                                    <td>
                                        <span class="bank-badge" style="font-size:10.5px; font-weight:700; background:<?= $isPkg2 ? '#eff6ff' : '#ecfdf5' ?>; color:<?= $isPkg2 ? '#1d4ed8' : '#047857' ?>; border:1px solid <?= $isPkg2 ? '#bfdbfe' : '#a7f3d0' ?>;">
                                            <?= $isPkg2 ? 'Paket 2 (Kristen)' : 'Paket 1 (Muslim)' ?>
                                        </span>
                                        <div style="font-size:11px; color:#64748b; margin-top:2px;"><?= sanitize($a['session_title'] ?? ($isPkg2 ? 'Sesi Kristen' : 'Sesi Utama')) ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight:600; color:#0369a1;"><?= sanitize($a['nama_kelompok'] ?: '-') ?></div>
                                        <small style="color:#64748b;">👨‍🏫 <?= sanitize($a['dosen_nama'] ?: '-') ?></small>
                                    </td>
                                    <td style="text-align:center; font-size:11.5px; color:#475569;">
                                        <div>Mulai: <?= $startedWib ?></div>
                                        <div>Selesai: <?= $submittedWib ?></div>
                                    </td>
                                    <td style="text-align:center;">
                                        <span class="bank-badge" style="font-size:11px;">
                                            <?= (int)($a['correct_count'] ?? 0) ?> / <?= (int)($a['total_questions'] ?? 25) ?> Soal
                                        </span>
                                    </td>
                                    <td style="text-align:center;">
                                        <strong style="color:<?= $isPass ? '#15803d' : '#b91c1c' ?>; font-size:16px;">
                                            <?= $score ?>
                                        </strong>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($isSubmitted): ?>
                                            <?php if ($isPass): ?>
                                                <span class="bank-badge active" style="font-weight:800;">🟢 LULUS</span>
                                            <?php else: ?>
                                                <span class="bank-badge danger-sm" style="font-weight:800; background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; padding:3px 8px; border-radius:6px;">🔴 BELUM MEMENUHI</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="bank-badge" style="background:#fef3c7; color:#b45309;">Sedang Ujian</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <div style="display:inline-flex; gap:6px;">
                                            <button type="button" class="bank-button" style="padding:4px 8px; min-height:28px; font-size:11px;" onclick="openReviewModal(<?= (int)$a['id'] ?>);" title="Review Lembar Jawaban">
                                                👁️ Review
                                            </button>
                                            <form method="POST" style="margin:0;" onsubmit="return confirm('Reset attempt ujian mahasiswa <?= sanitize($a['nama_lengkap']) ?>? Mahasiswa akan diizinkan memulai ujian kembali dari awal.');">
                                                <input type="hidden" name="action" value="reset_attempt">
                                                <input type="hidden" name="user_id" value="<?= (int)$a['user_id'] ?>">
                                                <input type="hidden" name="class_id" value="<?= (int)$a['class_id'] ?>">
                                                <button type="submit" class="bank-button danger-sm" title="Izinkan Ujian Ulang">
                                                    🔄 Reset
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL TAMBAH / EDIT PAKET SOAL
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="bank-modal-backdrop" id="packageModalBackdrop" style="display:none;pointer-events:none;visibility:hidden" role="dialog" aria-modal="true" aria-labelledby="packageModalTitle">
    <div class="bank-modal" style="max-width:620px;">
        <div class="bank-modal-head" style="background:#eff6ff;">
            <div>
                <h3 id="packageModalTitle" style="color:#1e40af;">📦 Buat Paket Soal Baru</h3>
                <div style="font-size:12px; color:#475569; margin-top:2px;">Wadah materi butir soal ujian tulis Tutorial RPL</div>
            </div>
            <button type="button" class="bank-modal-close" onclick="closePackageModal();">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="save_package">
            <input type="hidden" name="package_id" id="pkgModalId" value="0">
            <div class="bank-modal-body" style="padding:20px;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                    <div class="bank-field">
                        <label class="bank-label">Kode Paket *</label>
                        <input type="text" name="code" id="pkgModalCode" class="bank-input" placeholder="Cth: PKT-TUTOR-UTAMA" required>
                    </div>
                    <div class="bank-field">
                        <label class="bank-label">Nama Paket Soal *</label>
                        <input type="text" name="name" id="pkgModalName" class="bank-input" placeholder="Cth: Paket 1 - Utama (Muslim)" required>
                    </div>
                </div>
                <div class="bank-field" style="margin-bottom:14px;">
                    <label class="bank-label">Deskripsi Materi Paket</label>
                    <textarea name="description" id="pkgModalDesc" class="bank-input" rows="2" placeholder="Cth: Materi Fiqih, Ubudiyah, Thaharah, Sholat Wajib..."></textarea>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                    <div class="bank-field">
                        <label class="bank-label">Durasi Paket (Menit) *</label>
                        <input type="number" name="duration_minutes" id="pkgModalDuration" class="bank-input" value="90" min="5" max="300" required>
                    </div>
                    <div class="bank-field">
                        <label class="bank-label">Target Butir Soal *</label>
                        <input type="number" name="question_count" id="pkgModalQuestionCount" class="bank-input" value="25" min="1" max="200" required>
                    </div>
                </div>
                <label class="bank-toggle" style="cursor:pointer; margin-top:8px;">
                    <input type="checkbox" name="is_active" id="pkgModalIsActive" value="1" checked>
                    <div>
                        <strong>Aktifkan Paket Soal Ini</strong>
                        <div class="bank-note">Paket aktif dapat dipilih dan digunakan pada sesi ujian tutorial.</div>
                    </div>
                </label>
            </div>
            <div class="bank-modal-foot">
                <button type="button" class="bank-button" onclick="closePackageModal();">Batal</button>
                <button type="submit" class="bank-button primary">💾 Simpan Paket Soal</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL KOLEKSI & KELOLA SOAL PAKET (Identik dengan CBT Pretes)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="bank-modal-backdrop" id="modalPackageQuestions" style="display:none;pointer-events:none;visibility:hidden" role="dialog" aria-modal="true" aria-labelledby="pkgQuestionsTitle">
    <div class="bank-modal extra-large" style="max-width:920px; width:95%; max-height:90vh; display:flex; flex-direction:column; padding:0; overflow:hidden;">
        <div class="modal-header-banner" style="background:linear-gradient(135deg, #0f766e 0%, #115e59 100%); color:#ffffff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div>
                <h3 style="margin:0; font-size:17px; color:#ffffff; display:flex; align-items:center; gap:8px;">
                    📋 Koleksi Soal — <span id="pkgQuestionsTitle">Paket Soal</span>
                </h3>
                <div style="font-size:12px; opacity:0.95; margin-top:4px; display:flex; align-items:center; gap:8px;">
                    <span id="pkgQuestionsCodeBadge" class="bank-code" style="background:rgba(255,255,255,0.2); color:#ffffff; border-color:rgba(255,255,255,0.3);"></span>
                    <span id="pkgQuestionsCountBadge" style="font-weight:600;">Memuat butir soal...</span>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <button type="button" class="bank-button gold" id="btnModalAddQuestion" style="min-height:34px; font-size:12px; font-weight:700;">
                    ＋ Tambah Soal Baru
                </button>
                <a class="bank-button" id="btnModalImportExcel" href="?tab=import" style="min-height:34px; font-size:12px; background:rgba(255,255,255,0.15); color:#ffffff; border-color:rgba(255,255,255,0.3);">
                    📥 Impor Excel
                </a>
                <a class="bank-button" id="btnModalExportExcel" href="?download=bank" style="min-height:34px; font-size:12px; background:rgba(255,255,255,0.15); color:#ffffff; border-color:rgba(255,255,255,0.3);">
                    📤 Ekspor .xlsx
                </a>
                <button type="button" class="bank-modal-close" onclick="closePackageQuestionsModal();" aria-label="Tutup" style="color:#ffffff; font-size:20px; margin-left:4px;">✕</button>
            </div>
        </div>

        <div class="modal-q-toolbar" style="padding:12px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div class="modal-q-toolbar-left" style="display:flex; gap:10px; flex-wrap:wrap; flex:1;">
                <input id="modalQSearch" class="bank-input" type="search" placeholder="🔍 Cari butir pertanyaan, kode, kategori…" style="max-width:280px; padding:7px 12px;" oninput="filterModalQuestions();">
                <select id="modalQCategory" class="bank-input" style="max-width:180px; padding:7px 12px;" onchange="filterModalQuestions();">
                    <option value="">Semua Kategori</option>
                </select>
                <select id="modalQStatus" class="bank-input" style="max-width:140px; padding:7px 12px;" onchange="filterModalQuestions();">
                    <option value="">Semua Status</option>
                    <option value="aktif">Aktif</option>
                    <option value="nonaktif">Nonaktif</option>
                </select>
            </div>
            <span id="modalQCountBadge" style="font-size:12px; color:#64748b; font-weight:600;"></span>
        </div>

        <div class="modal-q-list" id="modalQListContainer" style="flex:1; overflow-y:auto; padding:20px; max-height:58vh;">
            <!-- Dynamic question cards rendered by JS -->
        </div>

        <div class="bank-modal-foot" style="justify-content:space-between; padding:12px 20px; background:#f8fafc; border-top:1px solid #e2e8f0;">
            <div style="font-size:12px; color:#64748b;">
                💡 <em>Pilihan jawaban yang berlatar hijau adalah <strong>Kunci Jawaban</strong> yang benar.</em>
            </div>
            <button type="button" class="bank-button" onclick="closePackageQuestionsModal();">Tutup Jendela</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL TAMBAH / EDIT BUTIR SOAL
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="bank-modal-backdrop" id="questionModalBackdrop" style="display:none;pointer-events:none;visibility:hidden" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="bank-modal large">
        <div class="bank-modal-head">
            <h3 id="modalTitle">➕ Tambah Butir Soal Baru</h3>
            <button type="button" class="bank-modal-close" onclick="closeQuestionModal();">✕</button>
        </div>
        <form method="POST" id="questionForm">
            <input type="hidden" name="action" value="save_question">
            <input type="hidden" name="question_id" id="formQuestionId" value="0">
            <div class="bank-modal-body">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div class="bank-field">
                        <label class="bank-label">Paket Soal:</label>
                        <select name="package_id" id="formPackageId" class="bank-input" required style="font-weight:700;">
                            <option value="1">📦 Paket 1: Utama (Ubudiyah / Muslim)</option>
                            <option value="2">📦 Paket 2: Kristen Protestan</option>
                        </select>
                    </div>
                    <div class="bank-field">
                        <label class="bank-label">Kategori Materi:</label>
                        <input type="text" name="category" id="formCategory" class="bank-input" placeholder="Contoh: Thaharah, Sholat Wajib, Ubudiyah Praktis" required>
                    </div>
                </div>

                <div class="bank-field">
                    <label class="bank-label">Teks Pertanyaan Soal:</label>
                    <textarea name="question_text" id="formQuestionText" class="bank-input" rows="4" placeholder="Tuliskan pertanyaan butir soal di sini..." required style="resize:vertical;"></textarea>
                </div>

                <div class="bank-options">
                    <div>
                        <label class="bank-option-label"><span class="bank-letter">A</span> Pilihan A *</label>
                        <input type="text" name="opt_a" id="formOptA" class="bank-input" placeholder="Pilihan jawaban A" required>
                    </div>
                    <div>
                        <label class="bank-option-label"><span class="bank-letter">B</span> Pilihan B *</label>
                        <input type="text" name="opt_b" id="formOptB" class="bank-input" placeholder="Pilihan jawaban B" required>
                    </div>
                    <div>
                        <label class="bank-option-label"><span class="bank-letter">C</span> Pilihan C</label>
                        <input type="text" name="opt_c" id="formOptC" class="bank-input" placeholder="Pilihan jawaban C">
                    </div>
                    <div>
                        <label class="bank-option-label"><span class="bank-letter">D</span> Pilihan D</label>
                        <input type="text" name="opt_d" id="formOptD" class="bank-input" placeholder="Pilihan jawaban D">
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label class="bank-option-label"><span class="bank-letter">E</span> Pilihan E</label>
                        <input type="text" name="opt_e" id="formOptE" class="bank-input" placeholder="Pilihan jawaban E">
                    </div>
                </div>

                <div class="bank-field" style="margin-top:16px;">
                    <label class="bank-label">Kunci Jawaban Benar:</label>
                    <select name="correct_answer" id="formCorrectAnswer" class="bank-input" required style="font-weight:800; max-width:200px;">
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                        <option value="D">D</option>
                        <option value="E">E</option>
                    </select>
                </div>
            </div>
            <div class="bank-modal-foot">
                <button type="button" class="bank-button" onclick="closeQuestionModal();">Batal</button>
                <button type="submit" class="bank-button primary">💾 Simpan Butir Soal</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL REVIEW LEMBAR JAWABAN PESERTA
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="bank-modal-backdrop" id="reviewModalBackdrop" style="display:none;pointer-events:none;visibility:hidden" role="dialog" aria-modal="true" aria-labelledby="reviewModalTitle">
    <div class="bank-modal large" style="max-width:860px;">
        <div class="bank-modal-head" style="background:#f0fdf4;">
            <div>
                <h3 id="reviewModalTitle" style="color:#166534;">👁️ Lembar Jawaban Peserta CBT</h3>
                <div id="reviewModalMeta" style="font-size:12px; color:#475569; margin-top:2px;">Memuat rincian ujian...</div>
            </div>
            <button type="button" class="bank-modal-close" onclick="closeReviewModal();">✕</button>
        </div>
        <div class="bank-modal-body" id="reviewModalContent" style="padding:20px; max-height:75vh; overflow-y:auto;">
            <div style="text-align:center; padding:30px; color:#64748b;">Memuat data butir soal...</div>
        </div>
        <div class="bank-modal-foot">
            <button type="button" class="bank-button" onclick="closeReviewModal();">Tutup</button>
        </div>
    </div>
</div>

<script>
const SESSIONS_DATA = <?= json_encode($sessions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

function loadSessionConfig(sessionId) {
    sessionId = parseInt(sessionId, 10);
    const s = SESSIONS_DATA.find(item => parseInt(item.id, 10) === sessionId);
    if (!s) return;

    if (document.getElementById('sessionTitleInput')) document.getElementById('sessionTitleInput').value = s.title || '';
    if (document.getElementById('sessionTokenInput')) document.getElementById('sessionTokenInput').value = s.token || '';
    if (document.getElementById('sessionDurationInput')) document.getElementById('sessionDurationInput').value = s.duration_minutes || 90;
    if (document.getElementById('sessionPassingGradeInput')) document.getElementById('sessionPassingGradeInput').value = s.passing_grade || 70.0;
    if (document.getElementById('sessionExamOpenCheck')) document.getElementById('sessionExamOpenCheck').checked = (parseInt(s.exam_open, 10) === 1);

    if (document.getElementById('sessionPackageSelect')) {
        let pkgIds = [1];
        try {
            pkgIds = JSON.parse(s.package_ids_json) || [1];
        } catch(e) {}
        document.getElementById('sessionPackageSelect').value = pkgIds[0] || (sessionId === 2 ? '2' : '1');
    }
}

// Filter Bank Soal
function filterQuestions() {
    const pkg = document.getElementById('filterPackage') ? document.getElementById('filterPackage').value : '';
    const cat = document.getElementById('filterCategory') ? document.getElementById('filterCategory').value : '';
    const q = document.getElementById('searchQuestion') ? document.getElementById('searchQuestion').value.toLowerCase().trim() : '';
    const rows = document.querySelectorAll('.question-row');

    rows.forEach(r => {
        const rowPkg = r.getAttribute('data-package') || '';
        const rowCat = r.getAttribute('data-category') || '';
        const rowText = r.getAttribute('data-text') || '';
        const matchPkg = (!pkg || rowPkg === pkg);
        const matchCat = (!cat || rowCat === cat);
        const matchQ = (!q || rowText.includes(q));
        r.style.display = (matchPkg && matchCat && matchQ) ? '' : 'none';
    });
}

// Filter Riwayat Attempt
function filterAttempts() {
    const search = document.getElementById('searchAttempt') ? document.getElementById('searchAttempt').value.toLowerCase().trim() : '';
    const pkg = document.getElementById('filterPackageAttempt') ? document.getElementById('filterPackageAttempt').value : '';
    const pass = document.getElementById('filterPassStatus') ? document.getElementById('filterPassStatus').value : '';
    const grp = document.getElementById('filterClassGroup') ? document.getElementById('filterClassGroup').value : '';
    const rows = document.querySelectorAll('.attempt-row');

    rows.forEach(r => {
        const s = r.getAttribute('data-search') || '';
        const p = r.getAttribute('data-pass') || '';
        const g = r.getAttribute('data-group') || '';
        const rPkg = r.getAttribute('data-package') || '';

        const matchS = (!search || s.includes(search));
        const matchPkg = (!pkg || rPkg === pkg);
        const matchP = (!pass || p === pass);
        const matchG = (!grp || g === grp);

        r.style.display = (matchS && matchPkg && matchP && matchG) ? '' : 'none';
    });
}

// Modal Paket Soal
function openPackageModal() {
    document.getElementById('packageModalTitle').textContent = '📦 Buat Paket Soal Baru';
    document.getElementById('pkgModalId').value = '0';
    document.getElementById('pkgModalCode').value = '';
    document.getElementById('pkgModalName').value = '';
    document.getElementById('pkgModalDesc').value = '';
    document.getElementById('pkgModalDuration').value = '90';
    document.getElementById('pkgModalQuestionCount').value = '25';
    document.getElementById('pkgModalIsActive').checked = true;
    const el = document.getElementById('packageModalBackdrop');
    if (el) {
        el.style.display = 'flex';
        el.style.pointerEvents = 'auto';
        el.style.visibility = 'visible';
        el.classList.add('open');
    }
}

function openEditPackageModal(pkg) {
    document.getElementById('packageModalTitle').textContent = '✏️ Edit Paket Soal (' + (pkg.code || 'ID: ' + pkg.id) + ')';
    document.getElementById('pkgModalId').value = pkg.id;
    document.getElementById('pkgModalCode').value = pkg.code || '';
    document.getElementById('pkgModalName').value = pkg.name || '';
    document.getElementById('pkgModalDesc').value = pkg.description || '';
    document.getElementById('pkgModalDuration').value = pkg.duration_minutes || 90;
    document.getElementById('pkgModalQuestionCount').value = pkg.question_count || 25;
    document.getElementById('pkgModalIsActive').checked = (parseInt(pkg.is_active, 10) === 1);
    const el = document.getElementById('packageModalBackdrop');
    if (el) {
        el.style.display = 'flex';
        el.style.pointerEvents = 'auto';
        el.style.visibility = 'visible';
        el.classList.add('open');
    }
}

function closePackageModal() {
    const el = document.getElementById('packageModalBackdrop');
    if (el) {
        el.classList.remove('open');
        el.style.display = 'none';
        el.style.pointerEvents = 'none';
        el.style.visibility = 'hidden';
    }
}

// ── Package Questions Manager Modal (Paritas Penuh dengan CBT Pretes) ───
window._currentPackageQuestions = [];
window._currentPackageId = 0;
window._currentPackageName = '';
window._currentPackageCode = '';

function openPackageQuestionsModal(packageId, packageName, packageCode) {
    window._currentPackageId = packageId;
    window._currentPackageName = packageName || 'Semua Bank Soal';
    window._currentPackageCode = packageCode || 'SEMUA';

    const titleEl = document.getElementById('pkgQuestionsTitle');
    const codeBadge = document.getElementById('pkgQuestionsCodeBadge');
    const countBadge = document.getElementById('pkgQuestionsCountBadge');
    const container = document.getElementById('modalQListContainer');

    if (titleEl) titleEl.textContent = window._currentPackageName;
    if (codeBadge) codeBadge.textContent = window._currentPackageCode;
    if (countBadge) countBadge.textContent = 'Memuat butir soal…';

    const btnAdd = document.getElementById('btnModalAddQuestion');
    if (btnAdd) {
        btnAdd.onclick = () => openAddModal(packageId);
    }
    const btnExport = document.getElementById('btnModalExportExcel');
    if (btnExport) {
        btnExport.href = '?download=bank&package_id=' + packageId;
    }
    const btnImport = document.getElementById('btnModalImportExcel');
    if (btnImport) {
        btnImport.href = '?tab=import&package_id=' + packageId;
    }

    if (container) {
        container.innerHTML = '<div style="padding:40px;text-align:center;color:#64748b;"><div style="font-size:24px;margin-bottom:8px">⏳</div>Memuat butir pertanyaan…</div>';
    }

    const modal = document.getElementById('modalPackageQuestions');
    if (modal) {
        modal.style.display = 'flex';
        modal.style.pointerEvents = 'auto';
        modal.style.visibility = 'visible';
        modal.classList.add('open');
    }

    // Sinkronkan filter di tabel halaman utama
    const select = document.getElementById('filterPackage');
    if (select) {
        select.value = String(packageId);
        filterQuestions();
    }
    const label = document.getElementById('panelQuestionsTitle');
    if (label) {
        label.textContent = 'Daftar Butir Soal CBT: ' + window._currentPackageName;
    }

    fetch('?ajax=package_questions&package_id=' + (packageId || 0))
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                window._currentPackageQuestions = data.questions || [];
                if (countBadge) {
                    countBadge.textContent = window._currentPackageQuestions.length + ' butir soal';
                }
                const catSelect = document.getElementById('modalQCategory');
                if (catSelect) {
                    const cats = Array.from(new Set(window._currentPackageQuestions.map(q => q.category).filter(Boolean))).sort();
                    let catHtml = '<option value="">Semua Kategori</option>';
                    cats.forEach(c => {
                        catHtml += '<option value="' + String(c).replace(/"/g, '&quot;') + '">' + String(c).replace(/</g, '&lt;') + '</option>';
                    });
                    catSelect.innerHTML = catHtml;
                }
                renderModalQuestions('', '', '');
            } else {
                if (container) container.innerHTML = '<div style="padding:30px;color:#b91c1c;text-align:center;">Gagal memuat soal: ' + (data.message || 'Terjadi kesalahan') + '</div>';
            }
        })
        .catch(err => {
            if (container) container.innerHTML = '<div style="padding:30px;color:#b91c1c;text-align:center;">Gagal memuat soal: ' + err.message + '</div>';
        });
}

function closePackageQuestionsModal() {
    const modal = document.getElementById('modalPackageQuestions');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        modal.style.visibility = 'hidden';
    }
}

function renderModalQuestions(query, catFilter, statusFilter) {
    const container = document.getElementById('modalQListContainer');
    const countBadge = document.getElementById('modalQCountBadge');
    if (!container) return;

    query = (query || '').toLowerCase().trim();
    catFilter = catFilter || '';
    statusFilter = statusFilter || '';

    const list = window._currentPackageQuestions || [];
    const filtered = list.filter(q => {
        const matchQ = !query ||
            (q.question_text || '').toLowerCase().includes(query) ||
            (q.code || '').toLowerCase().includes(query) ||
            (q.category || '').toLowerCase().includes(query);
        const matchCat = !catFilter || q.category === catFilter;
        const matchStat = !statusFilter || (statusFilter === 'aktif' ? Number(q.is_active) === 1 : Number(q.is_active) === 0);
        return matchQ && matchCat && matchStat;
    });

    if (countBadge) {
        countBadge.textContent = filtered.length + ' dari ' + list.length + ' soal';
    }

    if (filtered.length === 0) {
        container.innerHTML = `
            <div style="padding:40px 20px;text-align:center;color:#64748b;background:#f8fafc;border-radius:12px;border:1.5px dashed #cbd5e1;">
                <div style="font-size:32px;margin-bottom:8px">📭</div>
                <strong style="font-size:15px;color:#334155;display:block;margin-bottom:4px">Tidak Ada Butir Soal Ditemukan</strong>
                <p style="font-size:13px;margin:0 0 14px 0">Belum ada butir soal atau tidak cocok dengan filter pencarian.</p>
                <button type="button" class="bank-button primary" onclick="openAddModal(window._currentPackageId)">＋ Tambah Soal ke Paket Ini</button>
            </div>
        `;
        return;
    }

    let html = '';
    filtered.forEach((q, idx) => {
        const isActive = Number(q.is_active) === 1;
        let opts = q.options || [];
        if (typeof opts === 'string') {
            try { opts = JSON.parse(opts); } catch(e) { opts = []; }
        }
        const qJson = JSON.stringify(q).replace(/"/g, '&quot;');

        html += '<div class="bank-qcard" id="qcard_' + q.id + '">';
        html += '<div class="bank-qcard-head">';
        html += '<div class="bank-qcard-meta">';
        html += '<span class="bank-qcard-num">#' + (idx + 1) + '</span>';
        html += '<span class="bank-code">' + String(q.code || '').replace(/</g, '&lt;') + '</span>';
        html += '<span class="bank-badge package">' + String(q.category || 'Umum').replace(/</g, '&lt;') + '</span>';
        if (isActive) {
            html += '<span class="bank-badge active">● Aktif</span>';
        } else {
            html += '<span class="bank-badge inactive">Nonaktif</span>';
        }
        html += '</div>';
        html += '<div style="display:flex;gap:6px">';
        html += '<button type="button" class="bank-button" style="min-height:26px;padding:3px 8px;font-size:11px" onclick=\'openEditModal(' + qJson + ')\'>✏️ Edit</button>';
        html += '<button type="button" class="bank-button danger-sm" style="min-height:26px;padding:3px 8px;font-size:11px" onclick="deleteModalQuestion(' + q.id + ')">🗑️ Hapus</button>';
        html += '</div>';
        html += '</div>';

        html += '<div class="bank-qcard-text">' + String(q.question_text || '').replace(/\n/g, '<br>') + '</div>';

        html += '<div class="bank-qcard-options">';
        ['A', 'B', 'C', 'D', 'E'].forEach((letter, lIdx) => {
            const optText = (Array.isArray(opts) ? opts[lIdx] : (opts[letter] || opts[lIdx])) || '';
            if (!optText) return;
            const isCorrect = (q.correct_answer === letter);
            html += '<div class="bank-qcard-opt' + (isCorrect ? ' is-key' : '') + '">';
            html += '<span class="bank-qcard-opt-letter">' + letter + '</span>';
            html += '<span style="flex:1">' + String(optText).replace(/</g, '&lt;') + '</span>';
            if (isCorrect) {
                html += '<span style="font-size:11px;font-weight:700;color:#059669;margin-left:auto">✓ Kunci</span>';
            }
            html += '</div>';
        });
        html += '</div>';
        html += '</div>';
    });

    container.innerHTML = html;
}

function filterModalQuestions() {
    const query = document.getElementById('modalQSearch') ? document.getElementById('modalQSearch').value : '';
    const cat = document.getElementById('modalQCategory') ? document.getElementById('modalQCategory').value : '';
    const stat = document.getElementById('modalQStatus') ? document.getElementById('modalQStatus').value : '';
    renderModalQuestions(query, cat, stat);
}

function deleteModalQuestion(questionId) {
    if (!confirm('Yakin ingin menghapus butir soal ini dari paket?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_question');
    formData.append('question_id', String(questionId));

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res && res.success) {
            window._currentPackageQuestions = window._currentPackageQuestions.filter(q => Number(q.id) !== Number(questionId));
            renderModalQuestions();
            const card = document.getElementById('qcard_' + questionId);
            if (card) card.remove();
        } else {
            alert('Gagal menghapus soal: ' + (res.message || 'Terjadi kesalahan.'));
        }
    })
    .catch(() => {
        window.location.reload();
    });
}

function focusPackageQuestions(pkgId, pkgName) {
    openPackageQuestionsModal(pkgId, pkgName, 'PKT-' + pkgId);
}

// Modal Tambah & Edit Soal
function openAddModal(defaultPkgId) {
    document.getElementById('modalTitle').textContent = '➕ Tambah Butir Soal Baru';
    document.getElementById('formQuestionId').value = '0';
    const filterPkg = document.getElementById('filterPackage') ? document.getElementById('filterPackage').value : '';
    document.getElementById('formPackageId').value = defaultPkgId ? String(defaultPkgId) : (filterPkg || '1');
    document.getElementById('formCategory').value = 'Sholat Wajib';
    document.getElementById('formQuestionText').value = '';
    document.getElementById('formOptA').value = '';
    document.getElementById('formOptB').value = '';
    document.getElementById('formOptC').value = '';
    document.getElementById('formOptD').value = '';
    document.getElementById('formOptE').value = '';
    document.getElementById('formCorrectAnswer').value = 'A';
    const el = document.getElementById('questionModalBackdrop');
    if (el) {
        el.style.display = 'flex';
        el.style.pointerEvents = 'auto';
        el.style.visibility = 'visible';
        el.classList.add('open');
    }
}

function openEditModal(q) {
    document.getElementById('modalTitle').textContent = '✏️ Edit Butir Soal (' + (q.code || 'ID: ' + q.id) + ')';
    document.getElementById('formQuestionId').value = q.id;
    document.getElementById('formPackageId').value = q.package_id || '1';
    document.getElementById('formCategory').value = q.category || '';
    document.getElementById('formQuestionText').value = q.question_text || '';
    let opts = [];
    try {
        opts = JSON.parse(q.options_json) || [];
    } catch(e) {}
    document.getElementById('formOptA').value = opts[0] || opts['A'] || '';
    document.getElementById('formOptB').value = opts[1] || opts['B'] || '';
    document.getElementById('formOptC').value = opts[2] || opts['C'] || '';
    document.getElementById('formOptD').value = opts[3] || opts['D'] || '';
    document.getElementById('formOptE').value = opts[4] || opts['E'] || '';
    document.getElementById('formCorrectAnswer').value = q.correct_answer || 'A';
    const el = document.getElementById('questionModalBackdrop');
    if (el) {
        el.style.display = 'flex';
        el.style.pointerEvents = 'auto';
        el.style.visibility = 'visible';
        el.classList.add('open');
    }
}

function closeQuestionModal() {
    const el = document.getElementById('questionModalBackdrop');
    if (el) {
        el.classList.remove('open');
        el.style.display = 'none';
        el.style.pointerEvents = 'none';
        el.style.visibility = 'hidden';
    }
}

// Generator Token Acak
function generateRandomToken() {
    fetch('?ajax=generate_token')
        .then(r => r.json())
        .then(data => {
            if (data.token) {
                document.getElementById('sessionTokenInput').value = data.token;
            }
        })
        .catch(() => {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            let t = '';
            for (let i = 0; i < 6; i++) t += chars.charAt(Math.floor(Math.random() * chars.length));
            document.getElementById('sessionTokenInput').value = t;
        });
}

function copyTokenToClipboard() {
    const el = document.getElementById('sessionTokenInput');
    if (!el) return;
    el.select();
    navigator.clipboard.writeText(el.value).then(() => {
        alert('Token ' + el.value + ' berhasil disalin ke clipboard!');
    });
}

// Modal Review Lembar Jawaban
function openReviewModal(attemptId) {
    const backdrop = document.getElementById('reviewModalBackdrop');
    const content = document.getElementById('reviewModalContent');
    const meta = document.getElementById('reviewModalMeta');

    if (backdrop) {
        backdrop.style.display = 'flex';
        backdrop.style.pointerEvents = 'auto';
        backdrop.style.visibility = 'visible';
        backdrop.classList.add('open');
    }
    content.innerHTML = '<div style="text-align:center; padding:30px; color:#64748b;">Memuat data lembar jawaban...</div>';

    fetch('?ajax=review_attempt&id=' + attemptId)
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                content.innerHTML = '<div class="bank-notice error">' + (res.message || 'Gagal memuat.') + '</div>';
                return;
            }
            const att = res.attempt;
            const items = res.items;

            meta.innerHTML = `<strong>${att.nama_lengkap}</strong> (${att.nim || '-'}) · Kelompok: <strong>${att.nama_kelompok || '-'}</strong> · Nilai: <strong style="font-size:14px; color:${att.score >= 70 ? '#15803d' : '#b91c1c'};">${att.score}</strong> (${att.correct_count || 0}/${att.total_questions || items.length} Benar)`;

            let html = '<div style="display:flex; flex-direction:column; gap:16px;">';
            items.forEach((it, idx) => {
                const isCorrect = it.is_correct;
                const studentAns = it.student_answer || '-';
                const correctAns = it.correct_answer;
                const statusBadge = isCorrect
                    ? '<span class="bank-badge active">✓ BENAR</span>'
                    : '<span class="bank-badge danger-sm" style="background:#fee2e2; color:#b91c1c;">✗ SALAH</span>';

                html += `
                    <div style="border:1px solid ${isCorrect ? '#bbf7d0' : '#fecaca'}; border-radius:10px; padding:14px; background:${isCorrect ? '#f0fdf4' : '#fef2f2'};">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <span style="font-weight:700; font-size:13px; color:#0f172a;">No. ${it.position} [${it.category}]</span>
                            <div>${statusBadge}</div>
                        </div>
                        <div style="font-size:13.5px; font-weight:600; color:#1e293b; margin-bottom:10px; line-height:1.5;">
                            ${it.question_text.replace(/\n/g, '<br>')}
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:6px; font-size:12px;">
                `;

                const letters = ['A', 'B', 'C', 'D', 'E'];
                letters.forEach((l, lIdx) => {
                    const optText = it.options[lIdx] || '';
                    if (!optText) return;
                    let optStyle = 'padding:6px 10px; border-radius:6px; border:1px solid #e2e8f0; background:#ffffff; color:#334155;';
                    if (l === correctAns) {
                        optStyle = 'padding:6px 10px; border-radius:6px; border:1.5px solid #16a34a; background:#dcfce7; color:#14532d; font-weight:700;';
                    }
                    if (l === studentAns && l !== correctAns) {
                        optStyle = 'padding:6px 10px; border-radius:6px; border:1.5px solid #dc2626; background:#fee2e2; color:#991b1b; font-weight:700;';
                    }
                    const ansColor = isCorrect ? '#15803d' : '#b91c1c';
                    html += `
                        <div style="${optStyle}">
                            <strong>${l}.</strong> ${optText}
                            ${l === correctAns ? ' <span style="color:#15803d;">(Kunci)</span>' : ''}
                            ${l === studentAns ? `<span style="color:${ansColor}; font-weight:800;">[Pilihan Mahasiswa]</span>` : ''}
                        </div>
                    `;
                });

                html += `
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            content.innerHTML = html;
        })
        .catch(err => {
            content.innerHTML = '<div class="bank-notice error">Error memuat data: ' + err.message + '</div>';
        });
}

function closeReviewModal() {
    const backdrop = document.getElementById('reviewModalBackdrop');
    if (backdrop) {
        backdrop.classList.remove('open');
        backdrop.style.display = 'none';
        backdrop.style.pointerEvents = 'none';
        backdrop.style.visibility = 'hidden';
    }
}

// Sanitasi backdrop: pindahkan ke document.body agar tidak terpengaruh CSS transform parent (.content-area)
function sanitizeBackdrops() {
    document.querySelectorAll('.bank-modal-backdrop').forEach(function(el) {
        if (el.parentElement !== document.body) {
            document.body.appendChild(el);
        }
        if (!el.classList.contains('open')) {
            el.style.display = 'none';
            el.style.pointerEvents = 'none';
            el.style.visibility = 'hidden';
        }
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sanitizeBackdrops);
} else {
    sanitizeBackdrops();
}

// Global event delegation untuk seluruh aksi tombol
document.addEventListener('click', function(e) {
    // Tombol Kelola Soal Paket
    const btnManage = e.target.closest('[data-action="manage-questions"]');
    if (btnManage) {
        e.preventDefault();
        const pId = parseInt(btnManage.getAttribute('data-package-id') || '0', 10);
        const pName = btnManage.getAttribute('data-package-name') || '';
        const pCode = btnManage.getAttribute('data-package-code') || '';
        openPackageQuestionsModal(pId, pName, pCode);
        return;
    }

    // Tombol Tambah Soal Paket
    const btnAddQ = e.target.closest('[data-action="add-question"]');
    if (btnAddQ) {
        e.preventDefault();
        const pId = parseInt(btnAddQ.getAttribute('data-package-id') || '0', 10);
        openAddModal(pId);
        return;
    }

    // Tombol Buat Paket Baru
    const btnCreatePkg = e.target.closest('[data-action="create-package"]');
    if (btnCreatePkg) {
        e.preventDefault();
        openPackageModal();
        return;
    }

    // Tombol Tutup Modal (via data-close-modal atau tombol silang ✕)
    const btnClose = e.target.closest('[data-close-modal], .bank-modal-close');
    if (btnClose) {
        e.preventDefault();
        const backdrop = btnClose.closest('.bank-modal-backdrop');
        if (backdrop) {
            backdrop.classList.remove('open');
            backdrop.style.display = 'none';
            backdrop.style.pointerEvents = 'none';
            backdrop.style.visibility = 'hidden';
            document.body.style.overflow = '';
        }
        return;
    }

    // Klik backdrop luar untuk menutup modal
    if (e.target && e.target.classList.contains('bank-modal-backdrop') && e.target.classList.contains('open')) {
        e.target.classList.remove('open');
        e.target.style.display = 'none';
        e.target.style.pointerEvents = 'none';
        e.target.style.visibility = 'hidden';
        document.body.style.overflow = '';
    }
});

// Tutup modal aktif dengan tombol Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const openBackdrop = document.querySelector('.bank-modal-backdrop.open');
        if (openBackdrop) {
            openBackdrop.classList.remove('open');
            openBackdrop.style.display = 'none';
            openBackdrop.style.pointerEvents = 'none';
            openBackdrop.style.visibility = 'hidden';
            document.body.style.overflow = '';
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
