<?php
/**
 * LPPAI Corner - Ujian Tulis Tutorial RPL Online (CBT Mahasiswa)
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

// Handle Token Verification & Exam Submission
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
                header('Location: ' . BASE_URL . '/tutorial-rpl.php');
                exit;
            }
        }
    }
}

$isTokenVerified = !empty($_SESSION['tutorial_rpl_cbt_token_verified']);
$questions = [];
if ($isTokenVerified && $membership && !empty($session['exam_open']) && empty($membership['exam_submitted'])) {
    $questions = tutorialRplCbtGetQuestions($pdo);
    if (empty($questions)) {
        tutorialRplCbtSeedQuestionsIfEmpty($pdo);
        $questions = tutorialRplCbtGetQuestions($pdo);
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="tutorial-rpl-container" style="max-width:960px;margin:0 auto;">
    <div style="background:linear-gradient(135deg,var(--primary,#0b3b22),var(--primary-light,#156138));color:#fff;border-radius:18px;padding:26px;margin-bottom:20px;box-shadow:0 12px 30px rgba(11,59,34,.16);">
        <h2 style="color:#fff;margin:0 0 6px;font-size:22px;font-weight:800;">🧩 Ujian Tulis Tutorial RPL Online (CBT)</h2>
        <p style="margin:0;font-size:14px;opacity:.95;">Evaluasi pemahaman materi fiqih ibadah, thaharah, shalat fardhu/jenazah, dan ubudiyah amaliyah.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><?= $escape($error) ?></div>
    <?php endif; ?>

    <?php if (!$isRpl): ?>
        <div class="card" style="padding:40px 20px; text-align:center;">
            <p>Halaman ini dikhususkan bagi mahasiswa program Rekognisi Pembelajaran Lampau (RPL).</p>
            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary">Kembali ke Dashboard</a>
        </div>
    <?php elseif (!$membership): ?>
        <div class="card" style="padding:40px 20px; text-align:center;">
            <div style="font-size:42px; margin-bottom:12px;">🏫</div>
            <h3 style="margin:0 0 8px;">Belum Terplot ke Kelompok RPL</h3>
            <p style="color:var(--text-muted, #64748b); max-width:540px; margin:0 auto 16px;">
                Anda belum ditempatkan ke dalam kelompok Tutorial RPL oleh administrator. Ujian CBT baru dapat diakses setelah penempatan kelompok selesai.
            </p>
            <a href="<?= BASE_URL ?>/tutorial-rpl.php" class="btn btn-primary">Lihat Status RPL</a>
        </div>
    <?php elseif (!empty($membership['exam_submitted'])): ?>
        <!-- Mahasiswa Sudah Menyelesaikan CBT -->
        <div class="card" style="margin-bottom:20px; border-left:4px solid var(--primary);">
            <div class="card-header">
                <span>Hasil Ujian Tulis Tutorial RPL</span>
            </div>
            <div class="card-body" style="padding:24px; text-align:center;">
                <div style="font-size:48px; margin-bottom:12px;">🎉</div>
                <h3 style="margin:0 0 6px; font-size:20px; font-weight:800; color:var(--text-dark);">
                    Ujian Tulis Telah Selesai Dikerjakan
                </h3>
                <p style="color:var(--text-muted, #64748b); font-size:14px; margin-bottom:20px;">
                    Kelompok: <strong><?= $escape($membership['nama_kelompok']) ?></strong> · Periode: <?= $escape($membership['periode']) ?>
                </p>

                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; max-width:360px; margin:0 auto 24px;">
                    <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Nilai Ujian Tulis (CBT)</div>
                    <div style="font-size:38px; font-weight:800; color:var(--primary, #0f172a); margin:6px 0;">
                        <?= (float)$membership['nilai_ujian_tulis'] ?>
                    </div>
                    <div style="font-size:12.5px; color:#64748b;">
                        Passing Grade Kelulusan: <strong><?= (float)$session['passing_grade'] ?></strong>
                    </div>
                    <div style="margin-top:10px;">
                        <?php if ((float)$membership['nilai_ujian_tulis'] >= (float)$session['passing_grade']): ?>
                            <span class="badge badge-success" style="font-size:12px; padding:4px 12px;">✅ Memenuhi Standar Minimal</span>
                        <?php else: ?>
                            <span class="badge badge-warning" style="font-size:12px; padding:4px 12px;">⚠️ Belum Memenuhi Standar</span>
                        <?php endif; ?>
                    </div>
                </div>

                <a href="<?= BASE_URL ?>/tutorial-rpl.php" class="btn btn-primary" style="padding:10px 22px; font-weight:700;">
                    ← Kembali ke Info &amp; Kelas RPL
                </a>
            </div>
        </div>
    <?php elseif (empty($session['exam_open'])): ?>
        <!-- Akses CBT Sedang Ditutup -->
        <div class="card" style="padding:40px 20px; text-align:center;">
            <div style="font-size:42px; margin-bottom:12px;">🔒</div>
            <h3 style="margin:0 0 8px; color:#b91c1c;">Gerbang Ujian Tulis Sedang Ditutup</h3>
            <p style="color:var(--text-muted, #64748b); max-width:540px; margin:0 auto 20px;">
                Administrator LPPAI saat ini belum membuka akses gerbang ujian CBT Tutorial RPL. Silakan cek berkala atau hubungi tutor bimbingan Anda.
            </p>
            <a href="<?= BASE_URL ?>/tutorial-rpl.php" class="btn btn-secondary">Kembali ke Info Kelas</a>
        </div>
    <?php elseif (!$isTokenVerified): ?>
        <!-- Form Verifikasi Token Masuk Ujian -->
        <div class="card" style="max-width:500px; margin:0 auto 24px;">
            <div class="card-header">
                <span>🔐 Masukkan Token Masuk Ujian</span>
            </div>
            <div class="card-body" style="padding:24px;">
                <p style="font-size:13.5px; color:var(--text-muted, #64748b); margin-top:0;">
                    Sesi: <strong><?= $escape($session['title'] ?? 'Ujian Tulis Tutorial RPL') ?></strong><br>
                    Durasi: <strong><?= (int)$session['duration_minutes'] ?> Menit</strong> · Passing Grade: <strong><?= (float)$session['passing_grade'] ?></strong>
                </p>
                <form method="POST" action="<?= BASE_URL ?>/tutorial-rpl-cbt.php">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                    <input type="hidden" name="action" value="verify_token">
                    <div class="form-group" style="margin-bottom:16px;">
                        <label for="cbt-token" style="display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:#334155;">
                            Token Ujian:
                        </label>
                        <input type="text" id="cbt-token" name="token" class="form-control" placeholder="Contoh: TUTOR26" required style="text-transform:uppercase; font-size:16px; font-weight:700; letter-spacing:1.5px; text-align:center;">
                        <small style="color:#64748b; display:block; margin-top:4px;">Token resmi diumumkan oleh admin LPPAI atau tutor bimbingan.</small>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%; padding:10px; font-weight:700;">
                        Mulai Pengerjaan Ujian 🚀
                    </button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <!-- Lembar Pengerjaan Soal CBT -->
        <form method="POST" action="<?= BASE_URL ?>/tutorial-rpl-cbt.php" id="form-exam" onsubmit="return confirm('Apakah Anda yakin ingin mengakhiri dan mengirim jawaban ujian CBT ini?');">
            <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
            <input type="hidden" name="action" value="submit_exam">

            <div class="card" style="margin-bottom:20px; background:#f8fafc; border-left:4px solid var(--primary);">
                <div class="card-body" style="padding:14px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div>
                        <strong style="font-size:14.5px; color:var(--text-dark);"><?= $escape($session['title']) ?></strong>
                        <div style="font-size:12px; color:#64748b;">Jumlah: <?= count($questions) ?> Soal · Durasi: <?= (int)$session['duration_minutes'] ?> Menit</div>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-success" style="font-weight:700; padding:8px 18px;">
                            💾 Kirim Jawaban Ujian
                        </button>
                    </div>
                </div>
            </div>

            <?php foreach ($questions as $idx => $q): ?>
                <?php
                $opts = json_decode((string)$q['options_json'], true);
                if (!is_array($opts)) $opts = [];
                $letters = ['A', 'B', 'C', 'D', 'E'];
                ?>
                <div class="card" style="margin-bottom:18px;">
                    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-weight:700;">Soal No. <?= $idx + 1 ?></span>
                        <span class="badge badge-info" style="font-size:11px;"><?= $escape($q['category']) ?></span>
                    </div>
                    <div class="card-body" style="padding:20px;">
                        <p style="font-size:15px; font-weight:600; color:var(--text-dark); line-height:1.6; margin-top:0; margin-bottom:16px;">
                            <?= nl2br($escape($q['question_text'])) ?>
                        </p>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($opts as $optIdx => $optText): ?>
                                <?php $let = $letters[$optIdx] ?? chr(65 + $optIdx); ?>
                                <label style="display:flex; align-items:flex-start; gap:10px; padding:10px 14px; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer; background:#fff; transition:0.15s;" class="cbt-opt-label">
                                    <input type="radio" name="answers[<?= (int)$q['id'] ?>]" value="<?= $let ?>" style="margin-top:3px; transform:scale(1.2);">
                                    <div style="font-size:14px; color:#1e293b; line-height:1.4;">
                                        <strong><?= $let ?>.</strong> <?= $escape($optText) ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div style="text-align:center; margin-bottom:40px;">
                <button type="submit" class="btn btn-success" style="padding:12px 28px; font-size:15px; font-weight:800;">
                    ✓ Selesaikan &amp; Kirim Jawaban Ujian
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
