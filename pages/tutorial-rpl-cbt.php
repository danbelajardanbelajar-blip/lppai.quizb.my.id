<?php
/**
 * LPPAI Corner - Ujian Tulis Tutorial RPL Online (CBT Mahasiswa)
 * Berparitas penuh dengan CBT Pretes: engine 2 kolom, timer server, auto-save per butir, flag ragu-ragu, dan navigasi nomor.
 */
define('PAGE_TITLE', 'Ujian Tulis Tutorial RPL (CBT)');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';

$user = getCurrentUser();
$pdo = getDBConnection();
ensureTutorialRplTables($pdo);
ensureTutorialRplCbtTables($pdo);

$userId = (int)($user['id'] ?? 0);
$isRpl = (strtoupper(trim((string)($user['jenis_program'] ?? ''))) === 'RPL');
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$membership = tutorialRplGetStudentMembership($pdo, $userId);
$session = tutorialRplCbtGetSession($pdo);
$error = '';
$notice = '';

// ── Penanganan Request AJAX (Auto-Save, Flag, Submit) ────────────────────────
if (isset($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $ajaxAction = $_POST['ajax_action'] ?? ($_GET['ajax'] ?? '');
    $attemptId = (int)($_POST['attempt_id'] ?? ($_GET['attempt_id'] ?? 0));

    // Validasi kepemilikan attempt oleh user yang sedang login
    if ($attemptId > 0) {
        $stmtChk = $pdo->prepare("SELECT user_id, submitted, deadline_at FROM tutorial_rpl_cbt_attempts WHERE id = ?");
        $stmtChk->execute([$attemptId]);
        $attCheck = $stmtChk->fetch(PDO::FETCH_ASSOC);

        if (!$attCheck || (int)$attCheck['user_id'] !== $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Akses attempt tidak sah.']);
            exit;
        }
    }

    if ($ajaxAction === 'save_answer') {
        $pos = (int)($_POST['position'] ?? 0);
        $ans = strtoupper(trim((string)($_POST['answer'] ?? '')));
        if ($attemptId <= 0 || $pos <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap.']);
            exit;
        }

        $ok = tutorialRplCbtSaveAnswer($pdo, $attemptId, $pos, $ans);
        echo json_encode(['success' => $ok, 'position' => $pos, 'answer' => $ans]);
        exit;
    }

    if ($ajaxAction === 'save_flag') {
        $pos = (int)($_POST['position'] ?? 0);
        $flagged = (int)($_POST['flagged'] ?? 0);
        if ($attemptId <= 0 || $pos <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap.']);
            exit;
        }

        $ok = tutorialRplCbtSaveFlag($pdo, $attemptId, $pos, $flagged);
        echo json_encode(['success' => $ok, 'position' => $pos, 'flagged' => $flagged]);
        exit;
    }

    if ($ajaxAction === 'finish_exam') {
        if ($attemptId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID Attempt tidak valid.']);
            exit;
        }

        try {
            $result = tutorialRplCbtFinishAttempt($pdo, $attemptId);
            unset($_SESSION['tutorial_rpl_cbt_token_verified']);
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Aksi AJAX tidak dikenal.']);
    exit;
}

// ── Penanganan POST Biasa (Verifikasi Token & Submit Tradisional) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba lagi.';
    } else {
        $action = trim($_POST['action'] ?? '');

        if ($action === 'verify_token') {
            $inputToken = strtoupper(trim((string)($_POST['token'] ?? '')));
            $validToken = strtoupper(trim((string)($session['token'] ?? 'TUTOR26')));
            if ($inputToken !== '' && $inputToken === $validToken) {
                $_SESSION['tutorial_rpl_cbt_token_verified'] = true;
                if ($membership) {
                    tutorialRplCbtGetOrCreateAttempt($pdo, $userId, (int)$membership['class_id'], $session);
                }
                header('Location: ' . BASE_URL . '/tutorial-rpl-cbt.php');
                exit;
            } else {
                $error = 'Token ujian tidak valid. Silakan periksa kembali token yang diberikan admin atau tutor.';
            }
        } elseif ($action === 'submit_exam') {
            if (!$membership) {
                $error = 'Anda belum terdaftar pada kelompok Tutorial RPL yang aktif.';
            } elseif (empty($session['exam_open'])) {
                $error = 'Akses ujian telah ditutup.';
            } else {
                $questions = tutorialRplCbtGetQuestions($pdo);
                if (empty($questions)) {
                    tutorialRplCbtSeedQuestionsIfEmpty($pdo);
                    $questions = tutorialRplCbtGetQuestions($pdo);
                }

                $totalQuestions = count($questions);
                $correctCount = 0;
                $answers = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];

                foreach ($questions as $q) {
                    $qid = (int)$q['id'];
                    $chosen = strtoupper(trim((string)($answers[$qid] ?? '')));
                    $correct = strtoupper(trim((string)$q['correct_answer']));
                    if ($chosen !== '' && $chosen === $correct) {
                        $correctCount++;
                    }
                }

                $score = ($totalQuestions > 0) ? round(($correctCount / $totalQuestions) * 100, 2) : 0.00;
                tutorialRplCbtSubmitAttempt($pdo, $userId, (int)$membership['class_id'], $score, $correctCount, $totalQuestions);
                unset($_SESSION['tutorial_rpl_cbt_token_verified']);

                $_SESSION['rpl_student_hub_notice'] = "Alhamdulillah! Pengerjaan Ujian Tulis Tutorial RPL berhasil dikirim. Nilai Anda: $score (Benar $correctCount dari $totalQuestions soal).";
                header('Location: ' . BASE_URL . '/tutorial-rpl-cbt.php');
                exit;
            }
        }
    }
}

$isTokenVerified = !empty($_SESSION['tutorial_rpl_cbt_token_verified']);
$attempt = null;
$items = [];
$remainingSeconds = 0;

if ($membership && empty($membership['exam_submitted'])) {
    $existingAttempt = tutorialRplCbtGetStudentAttempt($pdo, $userId, (int)$membership['class_id']);
    if ($existingAttempt) {
        $attempt = $existingAttempt;
        $isTokenVerified = true; // Langsung izinkan pengerjaan jika attempt sudah berjalan
        $now = time();
        $deadline = (int)($attempt['deadline_at'] ?? ($attempt['started_at'] + ((int)$session['duration_minutes'] * 60)));

        if (!empty($attempt['submitted'])) {
            // Sudah submitted di attempt, refresh membership
            $membership['exam_submitted'] = 1;
            $membership['nilai_ujian_tulis'] = $attempt['score'];
        } elseif ($now >= $deadline) {
            // Waktu habis otomatis
            $res = tutorialRplCbtFinishAttempt($pdo, (int)$attempt['id']);
            unset($_SESSION['tutorial_rpl_cbt_token_verified']);
            $membership['exam_submitted'] = 1;
            $membership['nilai_ujian_tulis'] = $res['score'];
        } else {
            $remainingSeconds = max(0, $deadline - $now);
            $items = tutorialRplCbtGetAttemptItems($pdo, (int)$attempt['id']);
        }
    } elseif ($isTokenVerified && !empty($session['exam_open'])) {
        $attempt = tutorialRplCbtGetOrCreateAttempt($pdo, $userId, (int)$membership['class_id'], $session);
        $deadline = (int)$attempt['deadline_at'];
        $remainingSeconds = max(0, $deadline - time());
        $items = tutorialRplCbtGetAttemptItems($pdo, (int)$attempt['id']);
    }
}

include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pretes-cbt-exam.css?v=<?= filemtime(__DIR__ . '/../assets/css/pretes-cbt-exam.css') ?>">

<div class="tutorial-rpl-container" style="max-width:1080px; margin:0 auto; padding:10px 14px 40px;">

    <?php if ($error): ?>
        <div class="cbt-message error" role="alert" style="margin-bottom:20px;"><?= $escape($error) ?></div>
    <?php endif; ?>

    <?php if (!$isRpl): ?>
        <!-- Bukan Mahasiswa RPL -->
        <div class="card" style="padding:40px 20px; text-align:center; border-radius:16px;">
            <div style="font-size:42px; margin-bottom:12px;">🚫</div>
            <h3 style="margin:0 0 8px;">Khusus Program RPL</h3>
            <p style="color:#64748b; max-width:500px; margin:0 auto 16px;">
                Halaman asesmen ini dikhususkan bagi mahasiswa program Rekognisi Pembelajaran Lampau (RPL).
            </p>
            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary">Kembali ke Dashboard</a>
        </div>

    <?php elseif (!$membership): ?>
        <!-- Belum Terplot Kelompok -->
        <div class="card" style="padding:40px 20px; text-align:center; border-radius:16px;">
            <div style="font-size:42px; margin-bottom:12px;">🏫</div>
            <h3 style="margin:0 0 8px;">Belum Terplot ke Kelompok RPL</h3>
            <p style="color:var(--text-muted, #64748b); max-width:540px; margin:0 auto 16px;">
                Anda belum ditempatkan ke dalam kelompok Tutorial RPL oleh administrator. Ujian CBT baru dapat diakses setelah penempatan kelompok selesai.
            </p>
            <a href="<?= BASE_URL ?>/tutorial-rpl.php" class="btn btn-primary">Lihat Status RPL</a>
        </div>

    <?php elseif (!empty($membership['exam_submitted'])): ?>
        <!-- ═══════════════════════════════════════════════════════════════════
             LAYAR HASIL AKHIR UJIAN (RESULT SCREEN)
             ═══════════════════════════════════════════════════════════════════ -->
        <section class="cbt-shell" style="max-width:800px; margin:0 auto;">
            <div class="cbt-heading">
                <div>
                    <div class="cbt-eyebrow">LPPAI / HASIL ASESMEN</div>
                    <h3>Ujian Tulis Telah Selesai Dikerjakan</h3>
                    <p>Kelompok: <strong><?= $escape($membership['nama_kelompok'] ?? '-') ?></strong> · Periode: <?= $escape($membership['periode'] ?? '-') ?></p>
                </div>
            </div>
            <div class="cbt-body">
                <div class="cbt-result" style="display:block;">
                    <div style="font-size:48px; margin-bottom:8px;">🎉</div>
                    <div style="font-size:13px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:1px;">Nilai Ujian Tulis (CBT)</div>
                    <strong style="font-size:64px; color:var(--primary, #0f766e); margin:10px 0;">
                        <?= (float)$membership['nilai_ujian_tulis'] ?>
                    </strong>
                    <div style="font-size:13.5px; color:#475569; margin-bottom:14px;">
                        Standar Kelulusan Minimal (Passing Grade): <strong><?= (float)($session['passing_grade'] ?? 70.0) ?></strong>
                    </div>

                    <div style="margin-bottom:24px;">
                        <?php if ((float)$membership['nilai_ujian_tulis'] >= (float)($session['passing_grade'] ?? 70.0)): ?>
                            <span class="bank-badge active" style="font-size:14px; padding:6px 18px; border-radius:20px; font-weight:800;">
                                ✅ MEMENUHI STANDAR KELULUSAN (LULUS)
                            </span>
                        <?php else: ?>
                            <span class="bank-badge danger-sm" style="font-size:14px; padding:6px 18px; border-radius:20px; font-weight:800; background:#fee2e2; color:#b91c1c; border:1px solid #fecaca;">
                                ⚠️ BELUM MEMENUHI STANDAR KELULUSAN
                            </span>
                        <?php endif; ?>
                    </div>

                    <p style="max-width:520px; margin:0 auto 24px; font-size:13px; color:#64748b; line-height:1.6;">
                        Nilai ujian tulis telah otomatis tersimpan dan disinkronisasikan ke sistem penilaian akhir Tutorial RPL Anda.
                    </p>

                    <a href="<?= BASE_URL ?>/tutorial-rpl.php" class="btn btn-primary" style="padding:12px 28px; font-weight:800; border-radius:10px;">
                        ← Kembali ke Info &amp; Kelas RPL
                    </a>
                </div>
            </div>
        </section>

    <?php elseif (empty($session['exam_open'])): ?>
        <!-- Akses CBT Sedang Ditutup -->
        <div class="card" style="padding:40px 20px; text-align:center; border-radius:16px;">
            <div style="font-size:42px; margin-bottom:12px;">🔒</div>
            <h3 style="margin:0 0 8px; color:#b91c1c;">Gerbang Ujian Tulis Sedang Ditutup</h3>
            <p style="color:var(--text-muted, #64748b); max-width:540px; margin:0 auto 20px;">
                Administrator LPPAI saat ini belum membuka akses gerbang ujian CBT Tutorial RPL. Silakan cek berkala atau hubungi tutor bimbingan Anda.
            </p>
            <a href="<?= BASE_URL ?>/tutorial-rpl.php" class="btn btn-secondary">Kembali ke Info Kelas</a>
        </div>

    <?php elseif (!$isTokenVerified || empty($attempt)): ?>
        <!-- ═══════════════════════════════════════════════════════════════════
             LAYAR INTRO & VERIFIKASI TOKEN (GATEWAY)
             ═══════════════════════════════════════════════════════════════════ -->
        <section class="cbt-shell" style="max-width:680px; margin:0 auto;">
            <div class="cbt-heading">
                <div>
                    <div class="cbt-eyebrow">LPPAI / RUANG ASESMEN</div>
                    <h3>CBT Ujian Tulis Tutorial RPL</h3>
                    <p>Soal pilihan ganda · Waktu server 90 Menit · Jawaban tersimpan otomatis</p>
                </div>
            </div>
            <div class="cbt-body">
                <div id="cbtIntro">
                    <h4 style="margin-top:0;">Petunjuk Pengerjaan Ujian Tulis</h4>
                    <div class="cbt-intro-facts">
                        <span>Pilihan Ganda A–E</span>
                        <span>Durasi <strong><?= (int)$session['duration_minutes'] ?> Menit</strong></span>
                        <span>Passing Grade <strong><?= (float)$session['passing_grade'] ?></strong></span>
                    </div>
                    <p>
                        Ujian evaluasi ini menguji pemahaman materi fiqih ibadah, thaharah, shalat fardhu, shalat jenazah, dan ubudiyah amaliyah. Setiap pilihan jawaban yang Anda klik akan <strong>tersimpan otomatis secara real-time</strong> ke server. Anda dapat berpindah nomor soal secara bebas dan menandai soal ragu-ragu.
                    </p>

                    <form method="POST" action="<?= BASE_URL ?>/tutorial-rpl-cbt.php" style="margin-top:20px;">
                        <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                        <input type="hidden" name="action" value="verify_token">

                        <div id="cbtTokenBox" style="display:block; margin:16px 0; background:#fefce8; border:1.5px solid #fde047; border-radius:12px; padding:16px; text-align:left;">
                            <label for="cbtInputToken" style="display:block; font-size:12.5px; font-weight:800; color:#854d0e; margin-bottom:6px;">
                                🔑 Masukkan Token Masuk Ujian *
                            </label>
                            <input type="text" id="cbtInputToken" name="token" maxlength="32" placeholder="TOKEN UJIAN" required style="font-family:monospace; font-size:18px; font-weight:800; letter-spacing:2px; text-align:center; text-transform:uppercase; padding:10px 14px; border:1.5px solid #ca8a04; border-radius:8px; width:100%; box-sizing:border-box; background:#fff;">
                            <span style="font-size:11.5px; color:#a16207; display:block; margin-top:6px;">
                                Masukkan token resmi yang diumumkan oleh admin atau tutor Anda (Bawaan: <code><?= $escape($session['token'] ?? 'TUTOR26') ?></code>).
                            </span>
                        </div>

                        <button type="submit" class="btn btn-success" style="width:100%; min-height:46px; font-weight:800; font-size:14px; border-radius:10px;">
                            Mulai Pengerjaan Ujian 🚀
                        </button>
                    </form>
                </div>
            </div>
        </section>

    <?php else: ?>
        <!-- ═══════════════════════════════════════════════════════════════════
             RUANG UJIAN INTERAKTIF 2 KOLOM (MODERN CBT ENGINE)
             ═══════════════════════════════════════════════════════════════════ -->
        <?php
        $totalItems = count($items);
        $answeredInit = 0;
        foreach ($items as $it) {
            if (!empty($it['answer'])) $answeredInit++;
        }
        ?>

        <section class="cbt-shell" id="tutorialRplCbtExam">
            <!-- Header Bar Sticky -->
            <div class="cbt-heading">
                <div>
                    <div class="cbt-eyebrow">LPPAI / CBT TUTORIAL RPL</div>
                    <h3><?= $escape($session['title'] ?? 'Ujian Tulis Tutorial RPL') ?></h3>
                    <p>Kelompok: <strong><?= $escape($membership['nama_kelompok'] ?? '-') ?></strong> · Soal acak terstandar · Jawaban tersimpan otomatis</p>
                </div>
                <div class="cbt-time-box">
                    <small>Sisa Waktu Ujian</small>
                    <div class="cbt-timer" id="cbtTimer">--:--:--</div>
                </div>
            </div>

            <!-- Progress Bar Terjawab -->
            <div class="cbt-completion">
                <b>Terjawab <span id="cbtAnsweredCount"><?= $answeredInit ?>/<?= $totalItems ?></span></b>
                <div id="cbtCompletion" class="cbt-progress-track" role="progressbar" aria-label="Soal terjawab" aria-valuemin="0" aria-valuemax="<?= $totalItems ?>" aria-valuenow="<?= $answeredInit ?>">
                    <span id="cbtCompletionFill" style="width:<?= $totalItems > 0 ? round(($answeredInit / $totalItems) * 100) : 0 ?>%;"></span>
                </div>
            </div>

            <div class="cbt-body">
                <div id="cbtSaveStatus" class="cbt-message" style="display:none; padding:8px 14px; margin-bottom:12px; font-size:11.5px;"></div>

                <div class="cbt-layout">
                    <!-- Kolom Kiri: Lembar Soal -->
                    <div>
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                            <div id="cbtPosition" style="font-weight:700;">Soal No. 1 dari <?= $totalItems ?></div>
                            <span id="cbtCategoryPill" class="bank-badge package" style="font-size:11px;">Materi</span>
                        </div>

                        <div id="cbtQuestion" class="cbt-question"></div>

                        <fieldset id="cbtOptions" style="border:0; padding:0; margin:0;">
                            <legend class="sr-only" style="font-size:13px;">Pilih satu jawaban</legend>
                            <div id="cbtOptionsContainer"></div>
                        </fieldset>

                        <label class="cbt-review-label" style="cursor:pointer; margin-top:18px;">
                            <input type="checkbox" id="cbtFlag" onchange="toggleCurrentFlag(this.checked);">
                            <span>🚩 Tandai untuk diperiksa kembali (Ragu-ragu)</span>
                        </label>

                        <div class="cbt-actions">
                            <button type="button" class="btn btn-secondary" id="cbtPrevious" onclick="navigateQuestion(-1);">← Sebelumnya</button>
                            <button type="button" class="btn btn-primary" id="cbtNext" onclick="navigateQuestion(1);">Berikutnya →</button>
                            <button type="button" class="btn btn-secondary" id="cbtClear" onclick="clearCurrentAnswer();">Kosongkan Jawaban</button>
                            <span id="cbtAutoSavedNotice" style="display:none; color:#15803d; font-size:12px; font-weight:700; align-self:center; margin-left:8px;">✓ Tersimpan</span>
                        </div>
                    </div>

                    <!-- Kolom Kanan: Sidebar Navigasi Nomor -->
                    <aside class="cbt-sidebar">
                        <strong>Navigasi Soal</strong>
                        <div id="cbtNumbers" class="cbt-nav" aria-label="Nomor soal"></div>
                        <p id="cbtProgressText" style="font-size:12.5px; color:#475569; margin:12px 0 6px;"></p>
                        <p style="font-size:11.5px; color:#64748b; line-height:1.5;">
                            Hijau: dijawab · Kuning: ragu-ragu · Bingkai: aktif
                        </p>
                        <button type="button" id="cbtSubmit" class="btn btn-success" style="width:100%; font-weight:800; min-height:44px; margin-top:10px;" onclick="openConfirmSubmitModal();">
                            Kumpulkan Ujian
                        </button>
                    </aside>
                </div>
            </div>
        </section>

        <!-- Dialog Konfirmasi Kumpulkan Ujian -->
        <dialog id="cbtSubmitModal" class="bank-modal-backdrop" style="border:none; padding:0; background:rgba(15,23,42,0.65);">
            <div class="bank-modal" style="max-width:500px; margin:auto;">
                <div class="bank-modal-head" style="background:#f8fafc;">
                    <h3 style="color:#0f172a;">Konfirmasi Pengumpulan Ujian</h3>
                    <button type="button" class="bank-modal-close" onclick="closeConfirmSubmitModal();">✕</button>
                </div>
                <div class="bank-modal-body" style="padding:22px;">
                    <p style="font-size:14px; color:#334155; margin:0 0 16px;">
                        Apakah Anda yakin ingin mengakhiri dan mengumpulkan lembar jawaban ujian CBT Tutorial RPL ini?
                    </p>

                    <div style="background:#f1f5f9; border-radius:10px; padding:14px; margin-bottom:16px; font-size:13px; line-height:1.7;">
                        <div>✓ Soal Terjawab: <strong id="modalAnsweredCount" style="color:#15803d;">0</strong> butir</div>
                        <div>🚩 Soal Ragu-ragu: <strong id="modalFlaggedCount" style="color:#b45309;">0</strong> butir</div>
                        <div>⚠️ Belum Dijawab: <strong id="modalUnansweredCount" style="color:#b91c1c;">0</strong> butir</div>
                    </div>

                    <p style="font-size:12px; color:#64748b; margin:0;">
                        * Setelah dikumpulkan, lembar jawaban akan langsung dinilai oleh sistem dan tidak dapat diubah kembali.
                    </p>
                </div>
                <div class="bank-modal-foot">
                    <button type="button" class="bank-button" onclick="closeConfirmSubmitModal();">Periksa Kembali</button>
                    <button type="button" class="bank-button primary" id="btnConfirmSubmit" onclick="executeFinalSubmit();">
                        Ya, Kumpulkan Sekarang
                    </button>
                </div>
            </div>
        </dialog>

        <!-- Data State JSON untuk JS Engine -->
        <script>
        const CBT_STATE = {
            attemptId: <?= (int)$attempt['id'] ?>,
            totalQuestions: <?= $totalItems ?>,
            remainingSeconds: <?= (int)$remainingSeconds ?>,
            currentIndex: 0,
            items: <?= json_encode($items, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>
        };

        // Parse options_json jika berupa string
        CBT_STATE.items.forEach(it => {
            if (typeof it.options_json === 'string') {
                try {
                    it.options = JSON.parse(it.options_json);
                } catch(e) {
                    it.options = [];
                }
            } else {
                it.options = it.options_json || [];
            }
        });

        // ── Inisialisasi CBT Engine ──────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            renderNumbersGrid();
            renderQuestion(0);
            startCountdownTimer();
        });

        // Render Grid Nomor Soal
        function renderNumbersGrid() {
            const grid = document.getElementById('cbtNumbers');
            if (!grid) return;
            grid.innerHTML = '';

            let answered = 0;
            let flagged = 0;

            CBT_STATE.items.forEach((it, idx) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = idx + 1;
                btn.id = 'cbtNumBtn_' + idx;

                let cls = '';
                if (it.answer && it.answer !== '') {
                    cls += ' answered';
                    answered++;
                }
                if (parseInt(it.flagged, 10) === 1) {
                    cls += ' flagged';
                    flagged++;
                }
                if (idx === CBT_STATE.currentIndex) {
                    cls += ' current';
                }

                btn.className = cls.trim();
                btn.onclick = () => renderQuestion(idx);
                grid.appendChild(btn);
            });

            // Update Progress Bar
            document.getElementById('cbtAnsweredCount').textContent = `${answered}/${CBT_STATE.totalQuestions}`;
            const pct = CBT_STATE.totalQuestions > 0 ? Math.round((answered / CBT_STATE.totalQuestions) * 100) : 0;
            document.getElementById('cbtCompletionFill').style.width = pct + '%';
            document.getElementById('cbtProgressText').textContent = `Terjawab ${answered} dari ${CBT_STATE.totalQuestions} soal (${pct}%)`;
        }

        // Render Lembar Soal Aktif
        function renderQuestion(index) {
            if (index < 0 || index >= CBT_STATE.totalQuestions) return;
            CBT_STATE.currentIndex = index;

            const item = CBT_STATE.items[index];
            document.getElementById('cbtPosition').textContent = `Soal No. ${index + 1} dari ${CBT_STATE.totalQuestions}`;
            document.getElementById('cbtCategoryPill').textContent = item.category || 'Umum';
            document.getElementById('cbtQuestion').textContent = item.question_text || '';

            // Render Pilihan A–E
            const container = document.getElementById('cbtOptionsContainer');
            container.innerHTML = '';

            const letters = ['A', 'B', 'C', 'D', 'E'];
            const currentAnswer = (item.answer || '').toUpperCase();

            letters.forEach((letter, lIdx) => {
                const text = item.options[lIdx] || '';
                if (!text && lIdx >= 2) return; // opsi C, D, E opsional jika kosong

                const label = document.createElement('label');
                label.className = 'cbt-option' + (currentAnswer === letter ? ' selected' : '');
                label.innerHTML = `
                    <input type="radio" name="opt_choice" value="${letter}" ${currentAnswer === letter ? 'checked' : ''} onchange="handleAnswerSelect('${letter}');">
                    <div><strong>${letter}.</strong> ${escapeHtml(text)}</div>
                `;
                container.appendChild(label);
            });

            // Checkbox Flag Ragu-ragu
            document.getElementById('cbtFlag').checked = (parseInt(item.flagged, 10) === 1);

            // Navigasi Prev/Next
            document.getElementById('cbtPrevious').disabled = (index === 0);
            document.getElementById('cbtNext').disabled = (index === CBT_STATE.totalQuestions - 1);

            // Update border current di grid
            for (let i = 0; i < CBT_STATE.totalQuestions; i++) {
                const b = document.getElementById('cbtNumBtn_' + i);
                if (b) {
                    if (i === index) b.classList.add('current');
                    else b.classList.remove('current');
                }
            }
        }

        function navigateQuestion(step) {
            renderQuestion(CBT_STATE.currentIndex + step);
        }

        // Simpan Jawaban (Auto-Save Real-Time)
        function handleAnswerSelect(choice) {
            const item = CBT_STATE.items[CBT_STATE.currentIndex];
            item.answer = choice;

            // Highlight kartu terpilih
            const options = document.querySelectorAll('.cbt-option');
            options.forEach(opt => {
                const r = opt.querySelector('input[type="radio"]');
                if (r && r.value === choice) {
                    opt.classList.add('selected');
                } else {
                    opt.classList.remove('selected');
                }
            });

            showAutoSaveIndicator();
            renderNumbersGrid();

            // Kirim ke server via AJAX
            const fd = new FormData();
            fd.append('ajax_action', 'save_answer');
            fd.append('attempt_id', CBT_STATE.attemptId);
            fd.append('position', item.position);
            fd.append('answer', choice);

            fetch('<?= BASE_URL ?>/tutorial-rpl-cbt.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            }).catch(e => console.error('Save error:', e));
        }

        // Toggle Flag Ragu-ragu
        function toggleCurrentFlag(isFlagged) {
            const item = CBT_STATE.items[CBT_STATE.currentIndex];
            item.flagged = isFlagged ? 1 : 0;
            renderNumbersGrid();

            const fd = new FormData();
            fd.append('ajax_action', 'save_flag');
            fd.append('attempt_id', CBT_STATE.attemptId);
            fd.append('position', item.position);
            fd.append('flagged', isFlagged ? 1 : 0);

            fetch('<?= BASE_URL ?>/tutorial-rpl-cbt.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            }).catch(e => console.error('Flag error:', e));
        }

        // Kosongkan Jawaban
        function clearCurrentAnswer() {
            const item = CBT_STATE.items[CBT_STATE.currentIndex];
            item.answer = '';

            const radios = document.querySelectorAll('input[name="opt_choice"]');
            radios.forEach(r => r.checked = false);
            const options = document.querySelectorAll('.cbt-option');
            options.forEach(o => o.classList.remove('selected'));

            showAutoSaveIndicator('Jawaban dikosongkan');
            renderNumbersGrid();

            const fd = new FormData();
            fd.append('ajax_action', 'save_answer');
            fd.append('attempt_id', CBT_STATE.attemptId);
            fd.append('position', item.position);
            fd.append('answer', '');

            fetch('<?= BASE_URL ?>/tutorial-rpl-cbt.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            }).catch(e => console.error('Clear error:', e));
        }

        function showAutoSaveIndicator(msg) {
            const el = document.getElementById('cbtAutoSavedNotice');
            if (!el) return;
            el.textContent = '✓ ' + (msg || 'Tersimpan otomatis');
            el.style.display = 'inline-block';
            clearTimeout(window._saveTimer);
            window._saveTimer = setTimeout(() => {
                el.style.display = 'none';
            }, 1800);
        }

        // Countdown Timer
        function startCountdownTimer() {
            let left = CBT_STATE.remainingSeconds;
            const timerEl = document.getElementById('cbtTimer');

            function update() {
                if (left <= 0) {
                    timerEl.textContent = '00:00:00';
                    alert('Waktu ujian telah habis! Jawaban Anda akan otomatis dikumpulkan.');
                    executeFinalSubmit();
                    return;
                }

                const h = Math.floor(left / 3600);
                const m = Math.floor((left % 3600) / 60);
                const s = left % 60;
                timerEl.textContent = `${pad(h)}:${pad(m)}:${pad(s)}`;
                left--;
                setTimeout(update, 1000);
            }
            update();
        }

        function pad(n) {
            return n < 10 ? '0' + n : n;
        }

        // Modal Konfirmasi Pengumpulan
        function openConfirmSubmitModal() {
            let answered = 0;
            let flagged = 0;
            CBT_STATE.items.forEach(it => {
                if (it.answer && it.answer !== '') answered++;
                if (parseInt(it.flagged, 10) === 1) flagged++;
            });
            const unanswered = CBT_STATE.totalQuestions - answered;

            document.getElementById('modalAnsweredCount').textContent = answered;
            document.getElementById('modalFlaggedCount').textContent = flagged;
            document.getElementById('modalUnansweredCount').textContent = unanswered;

            const modal = document.getElementById('cbtSubmitModal');
            modal.style.display = 'flex';
        }

        function closeConfirmSubmitModal() {
            const modal = document.getElementById('cbtSubmitModal');
            modal.style.display = 'none';
        }

        // Eksekusi Submit Final
        function executeFinalSubmit() {
            const btn = document.getElementById('btnConfirmSubmit');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Mengumpulkan jawaban...';
            }

            const fd = new FormData();
            fd.append('ajax_action', 'finish_exam');
            fd.append('attempt_id', CBT_STATE.attemptId);

            fetch('<?= BASE_URL ?>/tutorial-rpl-cbt.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    window.location.reload();
                } else {
                    alert('Terjadi kesalahan saat pengumpulan: ' + (res.message || 'Coba lagi.'));
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Ya, Kumpulkan Sekarang';
                    }
                }
            })
            .catch(err => {
                console.error(err);
                // Fallback reload jika koneksi selesai
                window.location.reload();
            });
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
        </script>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
