<?php
/**
 * LPPAI Corner - Admin: Review Lembar Jawaban CBT Peserta
 * Menampilkan rincian butir soal, jawaban peserta, kunci jawaban, dan status benar/salah
 * baik untuk Pretes Resmi maupun Try Out Mandiri, baik sesi yang sudah selesai maupun sedang berlangsung.
 */

define('PAGE_TITLE', 'Review Lembar Jawaban CBT Peserta');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/pretes-cbt.php';
require_once __DIR__ . '/../includes/pretes-tryout.php';
require_once __DIR__ . '/../includes/user-helper.php';

$pdo = getDBConnection();
ensurePretesCbtTables($pdo);

$attemptId = (int)($_GET['attempt_id'] ?? 0);
$filterParam = isset($_GET['result_filter']) && in_array($_GET['result_filter'], ['resmi', 'tryout', 'all'], true)
    ? '&result_filter=' . urlencode($_GET['result_filter'])
    : '';
$backUrl = BASE_URL . '/pretes-ujian.php?cbt_bank=1&tab=results' . $filterParam;

// Ambil data attempt dan data peserta
$stmtAttempt = $pdo->prepare("
    SELECT a.*, 
           u.nama_lengkap, u.nim, u.program_studi, u.jenis_program, u.no_hp, u.email
    FROM pretes_cbt_attempts a
    JOIN users u ON u.id = a.user_id
    WHERE a.id = ?
    LIMIT 1
");
$stmtAttempt->execute([$attemptId]);
$attempt = $stmtAttempt->fetch(PDO::FETCH_ASSOC);

$items = [];
$sessionInfo = null;
$packageInfo = null;

if ($attempt) {
    // Ambil info sesi CBT jika ada
    if (!empty($attempt['session_id'])) {
        $sessionInfo = pretesCbtGetSession($pdo, (int)$attempt['session_id']);
    }

    // Ambil butir soal dan jawaban peserta
    $stmtItems = $pdo->prepare("
        SELECT position, question_id, question_text, options_json, correct_answer, answer, flagged
        FROM pretes_cbt_items
        WHERE attempt_id = ?
        ORDER BY position ASC
    ");
    $stmtItems->execute([$attemptId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
}

// Helper parsing opsi jawaban A-E
function parseCbtItemOptions($rawJson): array {
    $parsed = is_string($rawJson) ? json_decode($rawJson, true) : $rawJson;
    if (!is_array($parsed)) return [];
    $result = [];
    $letters = ['A', 'B', 'C', 'D', 'E'];
    foreach ($letters as $i => $letter) {
        if (isset($parsed[$letter])) {
            $result[$letter] = (string)$parsed[$letter];
        } elseif (isset($parsed[strtolower($letter)])) {
            $result[$letter] = (string)$parsed[strtolower($letter)];
        } elseif (isset($parsed[$i])) {
            $result[$letter] = is_array($parsed[$i]) ? (string)($parsed[$i]['text'] ?? '') : (string)$parsed[$i];
        }
    }
    return $result;
}

// Perhitungan metrik lembar jawaban
$totalQuestions = count($items);
$correctCount = 0;
$wrongCount = 0;
$emptyCount = 0;
$flaggedCount = 0;

$now = time();
$isSubmitted = false;
$deadline = 0;
$isExpired = false;
$isOngoing = false;
$score = 0.0;
$jenisLabel = 'Resmi';
$jenisKind = 'resmi';

if ($attempt) {
    $isSubmitted = ($attempt['submitted_at'] !== null);
    $deadline = (int)$attempt['deadline_at'];
    $isExpired = (!$isSubmitted && $now >= $deadline);
    $isOngoing = (!$isSubmitted && !$isExpired);

    $tryoutCache = [];
    $jenisInfo = pretesTryoutAttemptLabel($pdo, $attempt, $tryoutCache);
    $jenisKind = $jenisInfo['kind'] ?? 'resmi';
    $jenisLabel = $jenisInfo['label'] ?? 'Resmi';

    foreach ($items as &$item) {
        $uAns = strtoupper(trim((string)($item['answer'] ?? '')));
        $cAns = strtoupper(trim((string)($item['correct_answer'] ?? '')));
        $isFlg = !empty($item['flagged']);

        if ($isFlg) $flaggedCount++;

        if ($uAns === '') {
            $item['_status'] = 'empty';
            $emptyCount++;
        } elseif ($uAns === $cAns) {
            $item['_status'] = 'correct';
            $correctCount++;
        } else {
            $item['_status'] = 'wrong';
            $wrongCount++;
        }

        $item['_user_answer'] = $uAns;
        $item['_correct_answer'] = $cAns;
        $item['_options'] = parseCbtItemOptions($item['options_json'] ?? '');
    }
    unset($item);

    if ($isSubmitted && $attempt['score'] !== null) {
        $score = (float)$attempt['score'];
    } else {
        $score = $totalQuestions > 0 ? round(($correctCount / $totalQuestions) * 100, 1) : 0.0;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<style>
/* Styling Halaman Review Result CBT */
.review-header-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
    padding-bottom: 14px;
    border-bottom: 1px solid #e2e8f0;
}
.btn-back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: #ffffff;
    color: #334155;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.btn-back-link:hover {
    background: #f8fafc;
    color: #0f172a;
    border-color: #94a3b8;
}
.btn-print-review {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #0f766e;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(15,118,110,0.3);
}
.btn-print-review:hover {
    background: #115e59;
}

/* Kartu Profil Peserta & Ujian */
.review-profile-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 22px 24px;
    margin-bottom: 22px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.profile-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 18px;
    align-items: center;
}
.student-avatar-box {
    width: 54px;
    height: 54px;
    border-radius: 12px;
    background: linear-gradient(135deg, #1e3a8a, #2563eb);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: 700;
    flex-shrink: 0;
}

/* Stat Metrics Grid */
.review-stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin-top: 18px;
    padding-top: 18px;
    border-top: 1px dashed #e2e8f0;
}
.review-stat-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 14px;
    text-align: center;
}
.review-stat-box .stat-val {
    font-size: 20px;
    font-weight: 800;
    line-height: 1.2;
    margin-bottom: 2px;
}
.review-stat-box .stat-lbl {
    font-size: 11.5px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

/* Quick Jump Number Palette */
.quick-jump-panel {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px 20px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.filter-tabs-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 14px;
    padding-bottom: 12px;
    border-bottom: 1px solid #f1f5f9;
}
.filter-btn {
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #334155;
    transition: all 0.2s ease;
}
.filter-btn:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}
.filter-btn.active {
    background: #0f172a;
    color: #ffffff;
    border-color: #0f172a;
}
.number-palette-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(42px, 1fr));
    gap: 8px;
}
.num-btn {
    position: relative;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13.5px;
    font-weight: 800;
    text-decoration: none;
    border: 1.5px solid transparent;
    transition: all 0.2s ease;
    cursor: pointer;
}
.num-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}
.num-correct {
    background: #dcfce7;
    color: #15803d;
    border-color: #86efac;
}
.num-wrong {
    background: #fee2e2;
    color: #b91c1c;
    border-color: #fca5a5;
}
.num-empty {
    background: #f1f5f9;
    color: #64748b;
    border-color: #cbd5e1;
}
.num-flag-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #eab308;
    border: 2px solid #ffffff;
}

/* Question Cards */
.review-question-card {
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px 22px;
    margin-bottom: 20px;
    transition: all 0.25s ease;
}
.review-question-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.review-question-card.status-correct {
    border-left: 5px solid #16a34a;
}
.review-question-card.status-wrong {
    border-left: 5px solid #dc2626;
}
.review-question-card.status-empty {
    border-left: 5px solid #94a3b8;
}

.question-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 1px solid #f1f5f9;
}
.badge-result-tag {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.badge-tag-correct {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #bbf7d0;
}
.badge-tag-wrong {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}
.badge-tag-empty {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
}
.badge-tag-flagged {
    background: #fef9c3;
    color: #854d0e;
    border: 1px solid #fde047;
}

/* Pilihan Jawaban A-E */
.options-review-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 14px;
}
.option-review-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 14px;
    border-radius: 9px;
    border: 1.5px solid #e2e8f0;
    background: #f8fafc;
    transition: all 0.2s ease;
}
.option-letter-circle {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 800;
    flex-shrink: 0;
    background: #ffffff;
    border: 1.5px solid #cbd5e1;
    color: #334155;
}

/* Opsi Kunci Jawaban Benar */
.opt-is-key {
    background: #f0fdf4 !important;
    border-color: #22c55e !important;
}
.opt-is-key .option-letter-circle {
    background: #16a34a !important;
    color: #ffffff !important;
    border-color: #16a34a !important;
}

/* Opsi Pilihan Mahasiswa Benar */
.opt-user-correct {
    background: #ecfdf5 !important;
    border-color: #16a34a !important;
    box-shadow: 0 0 0 1px #16a34a;
}
.opt-user-correct .option-letter-circle {
    background: #15803d !important;
    color: #ffffff !important;
    border-color: #15803d !important;
}

/* Opsi Pilihan Mahasiswa Salah */
.opt-user-wrong {
    background: #fef2f2 !important;
    border-color: #ef4444 !important;
    box-shadow: 0 0 0 1px #ef4444;
}
.opt-user-wrong .option-letter-circle {
    background: #dc2626 !important;
    color: #ffffff !important;
    border-color: #dc2626 !important;
}

.opt-badge-pill {
    margin-left: auto;
    font-size: 10.5px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 4px;
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.pill-key-ok {
    background: #22c55e;
    color: #ffffff;
}
.pill-user-bad {
    background: #dc2626;
    color: #ffffff;
}

/* Question Footer */
.question-footer-summary {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 14px;
    padding-top: 10px;
    border-top: 1px dashed #e2e8f0;
    font-size: 12.5px;
    color: #64748b;
}

@media print {
    .review-header-bar, .quick-jump-panel, header, footer, .sidebar, .sidebar-overlay, .topbar, .navbar {
        display: none !important;
    }
    /* Layout aplikasi (flex + overflow-x:hidden + animasi transform) memotong hasil cetak
       setelah beberapa halaman; ratakan menjadi block agar SEMUA butir soal ikut tercetak. */
    html, body, .app-wrapper, .main-content, .content-area, .content-wrapper, #questionsContainer {
        display: block !important;
        overflow: visible !important;
        height: auto !important;
        min-height: 0 !important;
        max-height: none !important;
        margin-left: 0 !important;
        transform: none !important;
        animation: none !important;
    }
    .content-area, .content-wrapper {
        padding: 0 !important;
    }
    /* Kartu soal selalu tampil saat dicetak walau filter tampilan sedang aktif. */
    .review-question-card {
        display: block !important;
        page-break-inside: avoid;
        break-inside: avoid;
        padding: 12px 14px !important;
        margin-bottom: 10px !important;
        border: 1px solid #cbd5e1 !important;
        box-shadow: none !important;
    }
    .options-review-list {
        gap: 4px !important;
        margin-top: 8px !important;
    }
    .option-review-item {
        padding: 5px 10px !important;
    }
}
</style>

<div class="content-wrapper" style="max-width:1150px; margin:0 auto; padding:15px;">

    <!-- Baris Navigasi Atas -->
    <div class="review-header-bar">
        <div>
            <a href="<?= $backUrl ?>" class="btn-back-link">
                ← Kembali ke Hasil Ujian CBT
            </a>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
            <button type="button" class="btn-print-review" onclick="window.print()">
                🖨️ Cetak / Simpan PDF
            </button>
        </div>
    </div>

    <?php if (!$attempt): ?>
        <div class="card" style="padding:40px 20px; text-align:center;">
            <div style="font-size:48px; margin-bottom:12px;">⚠️</div>
            <h3 style="color:#0f172a; margin-bottom:8px;">Data Sesi Ujian CBT Tidak Ditemukan</h3>
            <p style="color:#64748b; max-width:500px; margin:0 auto 20px;">
                Sesi ujian dengan ID #<?= $attemptId ?> tidak ditemukan di database atau telah dihapus oleh administrator.
            </p>
            <a href="<?= $backUrl ?>" class="btn btn-primary" style="display:inline-block; width:auto;">
                ← Kembali ke Daftar Hasil Ujian
            </a>
        </div>
    <?php else: ?>

        <!-- Kartu Profil Mahasiswa & Sesi Ujian -->
        <div class="review-profile-card">
            <div class="profile-meta-grid">
                <div style="display:flex; align-items:center; gap:16px;">
                    <div class="student-avatar-box">
                        <?= strtoupper(substr($attempt['nama_lengkap'] ?? 'M', 0, 1)) ?>
                    </div>
                    <div>
                        <h2 style="font-size:19px; font-weight:800; color:#0f172a; margin:0 0 4px 0;">
                            <?= adminUserProfileLink($attempt['nama_lengkap'], $attempt['user_id']) ?>
                        </h2>
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:13px; color:#475569;">
                            <span>NIM: <strong><?= sanitize($attempt['nim'] ?: '-') ?></strong></span>
                            <span>•</span>
                            <span>Prodi: <strong><?= sanitize($attempt['program_studi'] ?: '-') ?></strong></span>
                            <span>•</span>
                            <?= renderJenisProgramBadge($attempt['jenis_program'] ?? 'Reguler', true) ?>
                        </div>
                    </div>
                </div>

                <div style="text-align:right;">
                    <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:6px;">
                        <span class="bank-badge" style="<?= $jenisKind === 'tryout' ? 'background:#dbeafe;color:#1e3a8a;border:1px solid #bfdbfe;' : 'background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;' ?> font-size:12px; font-weight:700; padding:4px 10px; border-radius:6px;">
                            <?= $jenisKind === 'tryout' ? '🎯 ' : '🏛️ ' ?> <?= sanitize($jenisLabel) ?>
                        </span>
                        <?php if ($sessionInfo): ?>
                            <span class="badge badge-secondary" style="font-size:11.5px;"><?= sanitize($sessionInfo['title']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:12.5px; color:#64748b;">
                        Mulai: <strong><?= (new DateTimeImmutable('@' . $attempt['started_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d M Y, H:i') ?> WIB</strong>
                    </div>
                    <?php if ($isSubmitted): ?>
                        <?php 
                            $durSecs = max(0, (int)$attempt['submitted_at'] - (int)$attempt['started_at']);
                            $durM = floor($durSecs / 60);
                            $durS = $durSecs % 60;
                        ?>
                        <div style="font-size:12px; color:#15803d; font-weight:600; margin-top:2px;">
                            ✓ Selesai: <?= (new DateTimeImmutable('@' . $attempt['submitted_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d M Y, H:i') ?> WIB (Durasi: <?= $durM ?>m <?= $durS ?>s)
                        </div>
                    <?php elseif ($isOngoing): ?>
                        <div style="font-size:12px; color:#d97706; font-weight:700; margin-top:2px;">
                            ⏳ Sedang Berlangsung (Batas: <?= (new DateTimeImmutable('@' . $deadline))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('H:i') ?> WIB)
                        </div>
                    <?php else: ?>
                        <div style="font-size:12px; color:#dc2626; font-weight:600; margin-top:2px;">
                            ⌛ Waktu Habis (Belum Disubmit)
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Grid 6 Metrik Statistik Ujian -->
            <div class="review-stat-grid">
                <div class="review-stat-box" style="background:#f0fdf4; border-color:#bbf7d0;">
                    <div class="stat-val" style="color:#15803d; font-size:24px;">
                        <?= number_format($score, 1) ?>
                        <?php if (!$isSubmitted): ?>
                            <span style="font-size:11px; font-weight:600; color:#3b82f6; display:block;">(Live Estimasi)</span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-lbl" style="color:#166534;">
                        Nilai Ujian <?= ($score >= 80) ? '✓ Lulus' : 'Belum Lulus' ?>
                    </div>
                </div>

                <div class="review-stat-box">
                    <div class="stat-val" style="color:#0f172a;"><?= $totalQuestions ?></div>
                    <div class="stat-lbl">Total Butir Soal</div>
                </div>

                <div class="review-stat-box" style="background:#ecfdf5; border-color:#a7f3d0;">
                    <div class="stat-val" style="color:#059669;">✓ <?= $correctCount ?></div>
                    <div class="stat-lbl" style="color:#047857;">Jawaban Benar</div>
                </div>

                <div class="review-stat-box" style="background:#fef2f2; border-color:#fecaca;">
                    <div class="stat-val" style="color:#dc2626;">✕ <?= $wrongCount ?></div>
                    <div class="stat-lbl" style="color:#991b1b;">Jawaban Salah</div>
                </div>

                <div class="review-stat-box" style="background:#fefce8; border-color:#fef08a;">
                    <div class="stat-val" style="color:#ca8a04;">🟨 <?= $flaggedCount ?></div>
                    <div class="stat-lbl" style="color:#854d0e;">Ditandai Ragu</div>
                </div>

                <div class="review-stat-box">
                    <div class="stat-val" style="color:#64748b;">⚪ <?= $emptyCount ?></div>
                    <div class="stat-lbl">Belum Dijawab</div>
                </div>
            </div>
        </div>

        <!-- Palet Cepat & Filter Butir Soal -->
        <div class="quick-jump-panel">
            <div class="filter-tabs-row">
                <span style="font-size:13px; font-weight:700; color:#334155; margin-right:4px;">Filter Tampilan Soal:</span>
                <button type="button" class="filter-btn active" onclick="filterQuestions('all', this)">
                    Semua (<?= $totalQuestions ?>)
                </button>
                <button type="button" class="filter-btn" style="color:#15803d;" onclick="filterQuestions('correct', this)">
                    ✓ Benar (<?= $correctCount ?>)
                </button>
                <button type="button" class="filter-btn" style="color:#b91c1c;" onclick="filterQuestions('wrong', this)">
                    ✕ Salah (<?= $wrongCount ?>)
                </button>
                <button type="button" class="filter-btn" style="color:#854d0e;" onclick="filterQuestions('flagged', this)">
                    🟨 Ragu-ragu (<?= $flaggedCount ?>)
                </button>
                <button type="button" class="filter-btn" style="color:#64748b;" onclick="filterQuestions('empty', this)">
                    ⚪ Belum Dijawab (<?= $emptyCount ?>)
                </button>
            </div>

            <div style="font-size:12px; color:#64748b; margin-bottom:10px;">
                💡 <em>Klik nomor untuk langsung melompat ke butir soal yang bersangkutan:</em>
            </div>

            <div class="number-palette-grid">
                <?php foreach ($items as $item): 
                    $pos = (int)$item['position'];
                    $st = $item['_status'];
                    $btnClass = 'num-' . $st;
                    $isFlg = !empty($item['flagged']);
                ?>
                    <a href="#soal_<?= $pos ?>" 
                       class="num-btn <?= $btnClass ?>" 
                       data-num-pos="<?= $pos ?>"
                       data-num-status="<?= $st ?>"
                       data-num-flagged="<?= $isFlg ? '1' : '0' ?>"
                       title="Soal #<?= $pos ?>: <?= ucfirst($st) ?><?= $isFlg ? ' (Ragu-ragu)' : '' ?>"
                       onclick="highlightQuestion(<?= $pos ?>)">
                        <?= $pos ?>
                        <?php if ($isFlg): ?>
                            <span class="num-flag-badge" title="Ragu-ragu"></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Daftar Lembar Jawaban Soal per Soal -->
        <div id="questionsContainer">
            <?php foreach ($items as $item): 
                $pos = (int)$item['position'];
                $st = $item['_status'];
                $isFlg = !empty($item['flagged']);
                $uAns = $item['_user_answer'];
                $cAns = $item['_correct_answer'];
                $options = $item['_options'];
            ?>
                <div class="review-question-card status-<?= $st ?>" 
                     id="soal_<?= $pos ?>"
                     data-q-status="<?= $st ?>"
                     data-q-flagged="<?= $isFlg ? '1' : '0' ?>">
                    
                    <div class="question-header">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="font-size:16px; font-weight:800; color:#0f172a;">
                                Soal No. <?= $pos ?>
                            </span>
                            <?php if ($isFlg): ?>
                                <span class="badge-result-tag badge-tag-flagged">
                                    🟨 Ragu-Ragu
                                </span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <?php if ($st === 'correct'): ?>
                                <span class="badge-result-tag badge-tag-correct">
                                    ✓ Jawaban Benar (+1)
                                </span>
                            <?php elseif ($st === 'wrong'): ?>
                                <span class="badge-result-tag badge-tag-wrong">
                                    ✕ Jawaban Salah (0)
                                </span>
                            <?php else: ?>
                                <span class="badge-result-tag badge-tag-empty">
                                    ⚪ Tidak Menjawab (0)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Teks Pertanyaan -->
                    <div class="question-body-text" style="font-size:14.5px; line-height:1.6; color:#1e293b; font-weight:500;">
                        <?= nl2br(htmlspecialchars($item['question_text'])) ?>
                    </div>

                    <!-- Opsi Jawaban A - E -->
                    <div class="options-review-list">
                        <?php foreach (['A', 'B', 'C', 'D', 'E'] as $letter): 
                            if (!isset($options[$letter]) && empty($options[$letter])) continue;
                            
                            $optText = $options[$letter];
                            $isThisKey = ($letter === $cAns);
                            $isThisUser = ($letter === $uAns);

                            $optClass = '';
                            if ($isThisKey && $isThisUser) {
                                $optClass = 'opt-user-correct';
                            } elseif ($isThisKey) {
                                $optClass = 'opt-is-key';
                            } elseif ($isThisUser) {
                                $optClass = 'opt-user-wrong';
                            }
                        ?>
                            <div class="option-review-item <?= $optClass ?>">
                                <div class="option-letter-circle">
                                    <?= $letter ?>
                                </div>
                                <div style="flex:1; font-size:13.5px; color:#1e293b; line-height:1.5;">
                                    <?= htmlspecialchars($optText) ?>
                                </div>

                                <?php if ($isThisKey && $isThisUser): ?>
                                    <span class="opt-badge-pill pill-key-ok">
                                        ✓ Kunci &amp; Pilihan Mahasiswa (Tepat)
                                    </span>
                                <?php elseif ($isThisKey): ?>
                                    <span class="opt-badge-pill pill-key-ok">
                                        ✓ Kunci Jawaban Benar
                                    </span>
                                <?php elseif ($isThisUser): ?>
                                    <span class="opt-badge-pill pill-user-bad">
                                        ✕ Pilihan Mahasiswa (Salah)
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Footer Ringkasan Baris Soal -->
                    <div class="question-footer-summary">
                        <div>
                            Jawaban Mahasiswa: 
                            <strong style="color:<?= $st === 'correct' ? '#15803d' : ($st === 'wrong' ? '#dc2626' : '#64748b') ?>;">
                                <?= $uAns !== '' ? $uAns : '<em>Tidak Menjawab (Kosong)</em>' ?>
                            </strong>
                            &nbsp;•&nbsp;
                            Kunci Jawaban Resmi: <strong style="color:#15803d;"><?= $cAns ?></strong>
                        </div>
                        <div>
                            <?php if ($st === 'correct'): ?>
                                <span style="color:#15803d; font-weight:700;">✓ Nilai Poin: 1</span>
                            <?php elseif ($st === 'wrong'): ?>
                                <span style="color:#dc2626; font-weight:700;">✕ Nilai Poin: 0</span>
                            <?php else: ?>
                                <span style="color:#64748b;">⚪ Nilai Poin: 0</span>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>

<script>
// Filter Tampilan Soal
function filterQuestions(filterType, btnEl) {
    document.querySelectorAll('.filter-btn').forEach(function(b) {
        b.classList.remove('active');
    });
    if (btnEl) btnEl.classList.add('active');

    var cards = document.querySelectorAll('.review-question-card');
    cards.forEach(function(card) {
        var status = card.getAttribute('data-q-status');
        var flagged = card.getAttribute('data-q-flagged');

        var show = false;
        if (filterType === 'all') {
            show = true;
        } else if (filterType === 'correct' && status === 'correct') {
            show = true;
        } else if (filterType === 'wrong' && status === 'wrong') {
            show = true;
        } else if (filterType === 'empty' && status === 'empty') {
            show = true;
        } else if (filterType === 'flagged' && flagged === '1') {
            show = true;
        }

        card.style.display = show ? 'block' : 'none';
    });
}

// Highlight Butir Soal Saat Lompat Cepat
function highlightQuestion(pos) {
    var card = document.getElementById('soal_' + pos);
    if (!card) return;

    // Pastikan card terlihat jika sedang terfilter
    card.style.display = 'block';

    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    card.style.boxShadow = '0 0 0 3px #3b82f6';
    setTimeout(function() {
        card.style.boxShadow = '';
    }, 1500);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
