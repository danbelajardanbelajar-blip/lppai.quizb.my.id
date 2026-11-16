<?php
/**
 * LPPAI Corner - Admin: Pengaturan Sesi & Bank Soal Ujian Tulis Tutorial RPL (CBT)
 */
define('PAGE_TITLE', 'Ujian Tulis Tutorial RPL (CBT)');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
requireAdmin();

$pdo = getDBConnection();
ensureTutorialRplTables($pdo);
ensureTutorialRplCbtTables($pdo);

$message = '';
$msgType = '';

$activeTab = $_GET['tab'] ?? 'session';
if (!in_array($activeTab, ['session', 'questions', 'attempts'], true)) {
    $activeTab = 'session';
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_session') {
        $title = trim($_POST['title'] ?? '');
        $duration = (int)($_POST['duration_minutes'] ?? 30);
        $passingGrade = (float)($_POST['passing_grade'] ?? 80.0);
        $token = strtoupper(trim($_POST['token'] ?? 'TUTOR26'));
        $examOpen = isset($_POST['exam_open']) ? 1 : 0;

        $ok = tutorialRplCbtUpdateSession($pdo, [
            'title' => $title,
            'duration_minutes' => $duration,
            'passing_grade' => $passingGrade,
            'token' => $token,
            'exam_open' => $examOpen
        ]);

        if ($ok) {
            $message = 'Pengaturan sesi CBT Tutorial RPL berhasil disimpan.';
            $msgType = 'success';
        } else {
            $message = 'Gagal menyimpan pengaturan sesi.';
            $msgType = 'danger';
        }
        $activeTab = 'session';
    } elseif ($action === 'toggle_exam') {
        $session = tutorialRplCbtGetSession($pdo);
        $newStatus = empty($session['exam_open']) ? 1 : 0;
        tutorialRplCbtUpdateSession($pdo, array_merge($session, ['exam_open' => $newStatus]));
        $message = $newStatus ? 'Sesi Ujian Tulis Tutorial RPL berhasil DIBUKA.' : 'Sesi Ujian Tulis Tutorial RPL berhasil DITUTUP.';
        $msgType = 'success';
        $activeTab = 'session';
    } elseif ($action === 'save_question') {
        $qId = (int)($_POST['question_id'] ?? 0);
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
                    'category' => $category,
                    'question_text' => $questionText,
                    'options' => $options,
                    'correct_answer' => $correct
                ]);
                $message = ($qId > 0) ? 'Butir soal berhasil diperbarui.' : 'Butir soal baru berhasil ditambahkan ke bank soal.';
                $msgType = 'success';
            } catch (Exception $e) {
                $message = 'Error: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
        $activeTab = 'questions';
    } elseif ($action === 'delete_question') {
        $qId = (int)($_POST['question_id'] ?? 0);
        if ($qId > 0 && tutorialRplCbtDeleteQuestion($pdo, $qId)) {
            $message = 'Butir soal berhasil dihapus dari bank soal.';
            $msgType = 'success';
        } else {
            $message = 'Gagal menghapus butir soal.';
            $msgType = 'danger';
        }
        $activeTab = 'questions';
    } elseif ($action === 'reset_default_questions') {
        $inserted = tutorialRplCbtSeedQuestionsIfEmpty($pdo, true);
        $message = "Bank soal berhasil di-reset ke standar kurikulum LPPAI ({$inserted} butir soal).";
        $msgType = 'success';
        $activeTab = 'questions';
    } elseif ($action === 'reset_attempt') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        if ($userId > 0 && $classId > 0 && tutorialRplCbtResetAttempt($pdo, $userId, $classId)) {
            $message = 'Attempt ujian peserta berhasil di-reset. Peserta kini dapat mengikuti ujian kembali.';
            $msgType = 'success';
        } else {
            $message = 'Gagal me-reset attempt ujian peserta.';
            $msgType = 'danger';
        }
        $activeTab = 'attempts';
    }
}

$session = tutorialRplCbtGetSession($pdo);
$questions = tutorialRplCbtGetQuestions($pdo);
$attempts = tutorialRplCbtGetAttempts($pdo);

// Kategori unik untuk filter
$categories = [];
foreach ($questions as $q) {
    if (!empty($q['category']) && !in_array($q['category'], $categories, true)) {
        $categories[] = $q['category'];
    }
}
sort($categories);

include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0; font-size:22px; font-weight:800; color:var(--text-dark);">
            🧩 Ujian Tulis Tutorial RPL Online (CBT)
        </h2>
        <p style="margin:0; font-size:13.5px; color:#64748b;">
            Pengaturan sesi, manajemen bank soal, serta pemantauan dan reset attempt pengerjaan ujian daring tutorial mahasiswa RPL.
        </p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <form method="POST" style="margin:0;">
            <input type="hidden" name="action" value="toggle_exam">
            <?php if (!empty($session['exam_open'])): ?>
                <button type="submit" class="btn btn-danger" onclick="return confirm('Tutup akses ujian CBT Tutorial RPL sekarang?');">
                    🔒 Tutup Akses Ujian
                </button>
            <?php else: ?>
                <button type="submit" class="btn btn-success" onclick="return confirm('Buka akses ujian CBT Tutorial RPL sekarang?');">
                    🚀 Buka Akses Ujian
                </button>
            <?php endif; ?>
        </form>
        <a href="<?= BASE_URL ?>/admin/tutorial-rpl-nilai.php" class="btn btn-secondary">
            🎓 Rekap Nilai Akhir
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="margin-bottom:20px; border-radius:10px; font-size:14px;">
        <?= ($msgType === 'success' ? '✅ ' : '⚠️ ') . sanitize($message) ?>
    </div>
<?php endif; ?>

<!-- Summary Cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:24px;">
    <div class="card" style="margin:0; border-radius:12px; border-left:4px solid <?= !empty($session['exam_open']) ? '#10b981' : '#ef4444' ?>;">
        <div class="card-body" style="padding:16px 20px;">
            <span style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Status Gerbang Ujian</span>
            <div style="font-size:20px; font-weight:800; color:<?= !empty($session['exam_open']) ? '#15803d' : '#b91c1c' ?>; margin-top:4px;">
                <?= !empty($session['exam_open']) ? '🟢 UJIAN DIBUKA' : '🔴 UJIAN DITUTUP' ?>
            </div>
            <small style="color:#64748b;">Token: <code style="background:#e2e8f0; padding:2px 6px; border-radius:4px; font-weight:700; color:#0f172a;"><?= sanitize($session['token'] ?? 'TUTOR26') ?></code></small>
        </div>
    </div>
    <div class="card" style="margin:0; border-radius:12px; border-left:4px solid #6366f1;">
        <div class="card-body" style="padding:16px 20px;">
            <span style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Bank Soal Aktif</span>
            <div style="font-size:20px; font-weight:800; color:#4338ca; margin-top:4px;">
                <?= count($questions) ?> Butir Soal
            </div>
            <small style="color:#64748b;">Materi Ubudiyah &amp; Sholat Praktis</small>
        </div>
    </div>
    <div class="card" style="margin:0; border-radius:12px; border-left:4px solid #f59e0b;">
        <div class="card-body" style="padding:16px 20px;">
            <span style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Durasi &amp; Passing Grade</span>
            <div style="font-size:20px; font-weight:800; color:#b45309; margin-top:4px;">
                <?= (int)$session['duration_minutes'] ?> Menit · <?= (float)$session['passing_grade'] ?>
            </div>
            <small style="color:#64748b;">Bobot 50% Nilai Akhir RPL</small>
        </div>
    </div>
    <div class="card" style="margin:0; border-radius:12px; border-left:4px solid #06b6d4;">
        <div class="card-body" style="padding:16px 20px;">
            <span style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Peserta Selesai CBT</span>
            <div style="font-size:20px; font-weight:800; color:#0e7490; margin-top:4px;">
                <?= count($attempts) ?> Mahasiswa
            </div>
            <small style="color:#64748b;">Riwayat attempt tersimpan</small>
        </div>
    </div>
</div>

<!-- Tab Navigasi -->
<div style="display:flex; border-bottom:2px solid #e2e8f0; margin-bottom:20px; gap:8px;">
    <a href="?tab=session" style="padding:10px 18px; font-size:14px; font-weight:700; text-decoration:none; border-bottom:3px solid <?= $activeTab === 'session' ? 'var(--primary, #0284c7)' : 'transparent' ?>; color:<?= $activeTab === 'session' ? 'var(--primary, #0284c7)' : '#64748b' ?>; display:flex; align-items:center; gap:8px;">
        ⚙️ Pengaturan Sesi
    </a>
    <a href="?tab=questions" style="padding:10px 18px; font-size:14px; font-weight:700; text-decoration:none; border-bottom:3px solid <?= $activeTab === 'questions' ? 'var(--primary, #0284c7)' : 'transparent' ?>; color:<?= $activeTab === 'questions' ? 'var(--primary, #0284c7)' : '#64748b' ?>; display:flex; align-items:center; gap:8px;">
        📚 Bank Soal (<?= count($questions) ?>)
    </a>
    <a href="?tab=attempts" style="padding:10px 18px; font-size:14px; font-weight:700; text-decoration:none; border-bottom:3px solid <?= $activeTab === 'attempts' ? 'var(--primary, #0284c7)' : 'transparent' ?>; color:<?= $activeTab === 'attempts' ? 'var(--primary, #0284c7)' : '#64748b' ?>; display:flex; align-items:center; gap:8px;">
        📊 Riwayat Attempt &amp; Reset (<?= count($attempts) ?>)
    </a>
</div>

<?php if ($activeTab === 'session'): ?>
    <!-- TAB 1: PENGATURAN SESI -->
    <div class="card" style="box-shadow:var(--shadow); border-radius:12px; margin-bottom:24px;">
        <div class="card-header" style="background:#f8fafc; font-weight:700; color:var(--text-dark);">
            ⚙️ Konfigurasi Parameter Ujian Tulis Tutorial RPL
        </div>
        <div class="card-body" style="padding:24px;">
            <form method="POST">
                <input type="hidden" name="action" value="update_session">
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:20px; margin-bottom:20px;">
                    <div>
                        <label style="font-size:13.5px; font-weight:600; color:#334155; margin-bottom:6px; display:block;">
                            Judul Sesi Ujian:
                        </label>
                        <input type="text" name="title" class="form-control" value="<?= sanitize($session['title'] ?? 'Ujian Tulis Tutorial RPL') ?>" required>
                        <small style="color:#64748b;">Ditampilkan pada lembar kerja ujian mahasiswa RPL.</small>
                    </div>
                    <div>
                        <label style="font-size:13.5px; font-weight:600; color:#334155; margin-bottom:6px; display:block;">
                            Token Masuk Ujian:
                        </label>
                        <input type="text" name="token" class="form-control" value="<?= sanitize($session['token'] ?? 'TUTOR26') ?>" required style="text-transform:uppercase; font-weight:700; letter-spacing:1px;">
                        <small style="color:#64748b;">Kode token verifikasi yang dibagikan kepada peserta.</small>
                    </div>
                    <div>
                        <label style="font-size:13.5px; font-weight:600; color:#334155; margin-bottom:6px; display:block;">
                            Durasi Pengerjaan (Menit):
                        </label>
                        <input type="number" name="duration_minutes" class="form-control" value="<?= (int)($session['duration_minutes'] ?? 30) ?>" min="5" max="180" required>
                        <small style="color:#64748b;">Timer hitung mundur otomatis di browser mahasiswa.</small>
                    </div>
                    <div>
                        <label style="font-size:13.5px; font-weight:600; color:#334155; margin-bottom:6px; display:block;">
                            Passing Grade (Batas Kelulusan):
                        </label>
                        <input type="number" step="0.1" name="passing_grade" class="form-control" value="<?= (float)($session['passing_grade'] ?? 80.0) ?>" min="0" max="100" required>
                        <small style="color:#64748b;">Standar kelulusan LPPAI (Rekomendasi: 80.00).</small>
                    </div>
                </div>

                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:24px;">
                    <label style="display:flex; align-items:center; gap:12px; cursor:pointer; margin:0;">
                        <input type="checkbox" name="exam_open" value="1" <?= !empty($session['exam_open']) ? 'checked' : '' ?> style="width:18px; height:18px;">
                        <div>
                            <span style="font-size:14.5px; font-weight:700; color:#1e293b;">Buka Akses Pengerjaan Ujian (Live)</span>
                            <div style="font-size:12.5px; color:#64748b;">Jika dicentang, peserta tutorial RPL yang sah dapat memasukkan token dan mengerjakan soal ujian.</div>
                        </div>
                    </label>
                </div>

                <div style="text-align:right;">
                    <button type="submit" class="btn btn-primary" style="padding:10px 24px;">
                        💾 Simpan Pengaturan Sesi
                    </button>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($activeTab === 'questions'): ?>
    <!-- TAB 2: BANK SOAL -->
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
        <div style="display:flex; align-items:center; gap:10px;">
            <label style="font-size:13.5px; font-weight:600; color:#334155;">Filter Kategori:</label>
            <select id="filterCategory" class="form-control" onchange="filterQuestions(this.value);" style="min-width:200px;">
                <option value="">-- Semua Kategori (<?= count($questions) ?> Soal) --</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= sanitize($cat) ?>"><?= sanitize($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex; gap:10px;">
            <form method="POST" style="margin:0;" onsubmit="return confirm('Kembalikan bank soal ke paket standar 10 butir soal kurikulum LPPAI? Semua butir soal custom akan di-reset.');">
                <input type="hidden" name="action" value="reset_default_questions">
                <button type="submit" class="btn btn-secondary btn-sm">
                    🔄 Pulihkan Soal Standar
                </button>
            </form>
            <button type="button" class="btn btn-primary btn-sm" onclick="openAddModal();">
                ➕ Tambah Butir Soal
            </button>
        </div>
    </div>

    <div class="card" style="box-shadow:var(--shadow); border-radius:12px; overflow:hidden;">
        <div class="card-body" style="padding:0;">
            <?php if (empty($questions)): ?>
                <div style="text-align:center; padding:40px 20px; color:#64748b;">
                    <div style="font-size:36px; margin-bottom:8px;">📚</div>
                    <h4 style="margin:0 0 4px 0; color:#334155;">Bank Soal Masih Kosong</h4>
                    <p style="margin:0; font-size:13.5px;">Klik tombol "Pulihkan Soal Standar" atau "Tambah Butir Soal" di atas untuk mengisi materi.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" style="margin:0; width:100%;">
                        <thead>
                            <tr style="background:#f1f5f9; text-align:left; font-size:12.5px; color:#475569;">
                                <th style="padding:12px 16px; width:40px;">No</th>
                                <th style="padding:12px 16px; width:150px;">Kategori</th>
                                <th style="padding:12px 16px;">Teks Pertanyaan &amp; Opsi Pilihan</th>
                                <th style="padding:12px 16px; width:90px; text-align:center;">Kunci</th>
                                <th style="padding:12px 16px; width:120px; text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $idx => $q):
                                $opts = json_decode($q['options_json'], true) ?: [];
                                $labels = ['A', 'B', 'C', 'D', 'E'];
                            ?>
                                <tr class="question-row" data-category="<?= sanitize($q['category']) ?>" style="border-bottom:1px solid #e2e8f0; font-size:13.5px;">
                                    <td style="padding:14px 16px; color:#64748b; vertical-align:top; font-weight:700;"><?= $idx + 1 ?></td>
                                    <td style="padding:14px 16px; vertical-align:top;">
                                        <span class="badge badge-primary" style="font-size:11.5px;"><?= sanitize($q['category']) ?></span>
                                    </td>
                                    <td style="padding:14px 16px; vertical-align:top;">
                                        <div style="font-weight:700; color:#0f172a; margin-bottom:8px; line-height:1.5;">
                                            <?= nl2br(sanitize($q['question_text'])) ?>
                                        </div>
                                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:6px; font-size:12.5px;">
                                            <?php foreach ($labels as $li => $lbl):
                                                if (!isset($opts[$li]) || $opts[$li] === '') continue;
                                                $isCorrect = ($q['correct_answer'] === $lbl);
                                            ?>
                                                <div style="padding:5px 8px; border-radius:6px; border:1px solid <?= $isCorrect ? '#86efac' : '#e2e8f0' ?>; background:<?= $isCorrect ? '#f0fdf4' : '#f8fafc' ?>; color:<?= $isCorrect ? '#166534' : '#475569' ?>;">
                                                    <strong style="color:<?= $isCorrect ? '#15803d' : '#0f172a' ?>;"><?= $lbl ?>.</strong> <?= sanitize($opts[$li]) ?>
                                                    <?= $isCorrect ? ' <span style="font-weight:700; color:#15803d;">(Kunci)</span>' : '' ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td style="padding:14px 16px; vertical-align:top; text-align:center;">
                                        <span class="badge badge-emerald" style="font-size:14px; font-weight:800; padding:6px 12px;">
                                            <?= sanitize($q['correct_answer']) ?>
                                        </span>
                                    </td>
                                    <td style="padding:14px 16px; vertical-align:top; text-align:center;">
                                        <div style="display:flex; justify-content:center; gap:6px;">
                                            <button type="button" class="btn btn-secondary btn-sm" onclick='openEditModal(<?= json_encode($q, JSON_HEX_APOS | JSON_HEX_QUOT) ?>);' title="Edit Soal">
                                                ✏️
                                            </button>
                                            <form method="POST" style="margin:0;" onsubmit="return confirm('Hapus butir soal ini?');">
                                                <input type="hidden" name="action" value="delete_question">
                                                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="Hapus Soal">
                                                    🗑️
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Tambah/Edit Soal -->
    <div id="questionModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#ffffff; border-radius:12px; width:95%; max-width:680px; max-height:90vh; overflow-y:auto; box-shadow:var(--shadow-lg); padding:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h3 id="modalTitle" style="margin:0; font-size:18px; font-weight:800; color:#0f172a;">➕ Tambah Butir Soal Baru</h3>
                <button type="button" onclick="closeModal();" style="border:none; background:transparent; font-size:20px; cursor:pointer; color:#64748b;">✕</button>
            </div>
            <form method="POST" id="questionForm">
                <input type="hidden" name="action" value="save_question">
                <input type="hidden" name="question_id" id="formQuestionId" value="0">

                <div style="margin-bottom:14px;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Kategori Materi:</label>
                    <input type="text" name="category" id="formCategory" class="form-control" placeholder="Contoh: Thaharah, Sholat Wajib, Zikir & Doa" required>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Teks Pertanyaan Soal:</label>
                    <textarea name="question_text" id="formQuestionText" class="form-control" rows="3" placeholder="Tuliskan butir soal pertanyaan di sini..." required></textarea>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                    <div>
                        <label style="font-size:12.5px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Opsi A:</label>
                        <input type="text" name="opt_a" id="formOptA" class="form-control" required>
                    </div>
                    <div>
                        <label style="font-size:12.5px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Opsi B:</label>
                        <input type="text" name="opt_b" id="formOptB" class="form-control" required>
                    </div>
                    <div>
                        <label style="font-size:12.5px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Opsi C:</label>
                        <input type="text" name="opt_c" id="formOptC" class="form-control">
                    </div>
                    <div>
                        <label style="font-size:12.5px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Opsi D:</label>
                        <input type="text" name="opt_d" id="formOptD" class="form-control">
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="font-size:12.5px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Opsi E:</label>
                        <input type="text" name="opt_e" id="formOptE" class="form-control">
                    </div>
                </div>

                <div style="margin-bottom:20px;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Kunci Jawaban Benar:</label>
                    <select name="correct_answer" id="formCorrectAnswer" class="form-control" required style="font-weight:700;">
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                        <option value="D">D</option>
                        <option value="E">E</option>
                    </select>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeModal();" class="btn btn-secondary">Batal</button>
                    <button type="submit" class="btn btn-primary">💾 Simpan Butir Soal</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function filterQuestions(cat) {
        const rows = document.querySelectorAll('.question-row');
        rows.forEach(r => {
            if (!cat || r.getAttribute('data-category') === cat) {
                r.style.display = '';
            } else {
                r.style.display = 'none';
            }
        });
    }

    function openAddModal() {
        document.getElementById('modalTitle').textContent = '➕ Tambah Butir Soal Baru';
        document.getElementById('formQuestionId').value = '0';
        document.getElementById('formCategory').value = 'Sholat Wajib';
        document.getElementById('formQuestionText').value = '';
        document.getElementById('formOptA').value = '';
        document.getElementById('formOptB').value = '';
        document.getElementById('formOptC').value = '';
        document.getElementById('formOptD').value = '';
        document.getElementById('formOptE').value = '';
        document.getElementById('formCorrectAnswer').value = 'A';
        const modal = document.getElementById('questionModal');
        modal.style.display = 'flex';
    }

    function openEditModal(q) {
        document.getElementById('modalTitle').textContent = '✏️ Edit Butir Soal';
        document.getElementById('formQuestionId').value = q.id;
        document.getElementById('formCategory').value = q.category || '';
        document.getElementById('formQuestionText').value = q.question_text || '';
        let opts = [];
        try {
            opts = JSON.parse(q.options_json) || [];
        } catch(e) {}
        document.getElementById('formOptA').value = opts[0] || '';
        document.getElementById('formOptB').value = opts[1] || '';
        document.getElementById('formOptC').value = opts[2] || '';
        document.getElementById('formOptD').value = opts[3] || '';
        document.getElementById('formOptE').value = opts[4] || '';
        document.getElementById('formCorrectAnswer').value = q.correct_answer || 'A';
        const modal = document.getElementById('questionModal');
        modal.style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('questionModal').style.display = 'none';
    }
    </script>

<?php elseif ($activeTab === 'attempts'): ?>
    <!-- TAB 3: RIWAYAT ATTEMPT & RESET -->
    <div class="card" style="box-shadow:var(--shadow); border-radius:12px; overflow:hidden;">
        <div class="card-header" style="background:#f8fafc; font-weight:700; color:var(--text-dark); display:flex; justify-content:space-between; align-items:center;">
            <span>📊 Riwayat Pengerjaan CBT Peserta Tutorial RPL</span>
            <small style="color:#64748b;">Total: <?= count($attempts) ?> Attempt</small>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($attempts)): ?>
                <div style="text-align:center; padding:40px 20px; color:#64748b;">
                    <div style="font-size:36px; margin-bottom:8px;">📝</div>
                    <h4 style="margin:0 0 4px 0; color:#334155;">Belum Ada Riwayat Ujian</h4>
                    <p style="margin:0; font-size:13.5px;">Peserta yang telah mengumpulkan lembar jawaban CBT tutorial akan otomatis tercatat di sini.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" style="margin:0; width:100%;">
                        <thead>
                            <tr style="background:#f1f5f9; text-align:left; font-size:12.5px; color:#475569;">
                                <th style="padding:12px 16px; width:40px;">No</th>
                                <th style="padding:12px 16px;">Mahasiswa</th>
                                <th style="padding:12px 16px;">Kelompok / Tutor</th>
                                <th style="padding:12px 16px; text-align:center;">Skor Benar</th>
                                <th style="padding:12px 16px; text-align:center;">Nilai CBT</th>
                                <th style="padding:12px 16px; text-align:center;">Waktu Kumpul</th>
                                <th style="padding:12px 16px; text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attempts as $idx => $a):
                                $score = (float)($a['score'] ?? 0);
                                $isPass = ($score >= (float)($session['passing_grade'] ?? 80.0));
                            ?>
                                <tr style="border-bottom:1px solid #e2e8f0; font-size:13.5px;">
                                    <td style="padding:14px 16px; color:#64748b; vertical-align:middle;"><?= $idx + 1 ?></td>
                                    <td style="padding:14px 16px; vertical-align:middle;">
                                        <div style="font-weight:700; color:#0f172a;"><?= sanitize($a['nama_lengkap']) ?></div>
                                        <small style="color:#64748b;">NIM: <?= sanitize($a['nim'] ?: '-') ?> · <?= sanitize($a['program_studi'] ?: '') ?></small>
                                    </td>
                                    <td style="padding:14px 16px; vertical-align:middle;">
                                        <div style="font-weight:600; color:#0369a1;"><?= sanitize($a['nama_kelompok']) ?></div>
                                        <small style="color:#64748b;">👨‍🏫 <?= sanitize($a['dosen_nama'] ?: '-') ?></small>
                                    </td>
                                    <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                        <span class="badge badge-secondary" style="font-size:12px;">
                                            <?= (int)($a['correct_count'] ?? 0) ?> / <?= (int)($a['total_questions'] ?? 25) ?> Soal
                                        </span>
                                    </td>
                                    <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                        <strong style="color:<?= $isPass ? '#15803d' : '#b91c1c' ?>; font-size:16px;">
                                            <?= $score ?>
                                        </strong>
                                    </td>
                                    <td style="padding:14px 16px; text-align:center; vertical-align:middle; color:#64748b; font-size:12.5px;">
                                        <?= !empty($a['submitted_at']) ? date('d M Y, H:i', (int)$a['submitted_at']) . ' WIB' : '-' ?>
                                    </td>
                                    <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Apakah Anda yakin ingin me-reset attempt ujian peserta ini? Nilai CBT tutorial mahasiswa akan dihapus dan mahasiswa diizinkan mengerjakan ulang.');">
                                            <input type="hidden" name="action" value="reset_attempt">
                                            <input type="hidden" name="user_id" value="<?= (int)$a['user_id'] ?>">
                                            <input type="hidden" name="class_id" value="<?= (int)$a['class_id'] ?>">
                                            <button type="submit" class="btn btn-warning btn-sm" style="display:inline-flex; align-items:center; gap:4px; font-weight:700;">
                                                <span>🔄</span> Izin Ulang
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
