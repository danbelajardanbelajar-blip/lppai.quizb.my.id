<?php
define('PAGE_TITLE', 'Bank Soal & Hasil CBT Pretes');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/pretes-cbt.php';
$pdo = getDBConnection();
ensurePretesCbtTables($pdo);
$bankUrl = BASE_URL . '/pretes-ujian.php?cbt_bank=1';
$message = '';
$form = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) throw new InvalidArgumentException('Sesi tidak valid. Muat ulang halaman.');
        pretesCbtSaveQuestion($pdo, $_POST);
        header('Location: ' . $bankUrl . '&saved=1');
        exit;
    } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
        $form = $_POST;
    } catch (Throwable $e) {
        error_log('CBT bank save: ' . $e->getMessage());
        $message = 'Soal belum dapat disimpan. Coba lagi.';
        $form = $_POST;
    }
}
if ($form === null && !empty($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM pretes_cbt_questions WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($form) foreach (json_decode($form['options_json'], true) as $letter => $text) $form['option_' . $letter] = $text;
}
$form ??= ['is_active' => 1, 'category' => 'Umum'];
$questions = $pdo->query('SELECT * FROM pretes_cbt_questions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$active = count(array_filter($questions, static fn($q) => (bool)$q['is_active']));
$expired = $pdo->prepare('SELECT user_id, registration_id FROM pretes_cbt_attempts WHERE submitted_at IS NULL AND deadline_at <= ? LIMIT 100');
$expired->execute([time()]);
foreach ($expired->fetchAll(PDO::FETCH_ASSOC) as $entry) pretesCbtState($pdo, (int)$entry['user_id'], (int)$entry['registration_id']);
$attempts = $pdo->query('SELECT a.*, u.nama_lengkap, u.nim FROM pretes_cbt_attempts a JOIN users u ON u.id = a.user_id ORDER BY a.started_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="margin-bottom:20px">
    <div class="card-header"><h3 style="margin:0">Bank Soal CBT Pretes</h3></div>
    <div class="card-body">
        <p><strong><?= count($questions) ?> soal</strong> · <?= $active ?> aktif · Paket ujian: <strong>25 soal acak, 30 menit, pilihan A–E</strong>.</p>
        <p>Bank awal berisi materi dasar ubudiyah. Tinjau materi dan kunci sebelum ujian resmi. Perubahan soal berlaku untuk paket baru; soal dan kunci paket yang sedang dikerjakan tetap tersimpan sesuai saat ujian dimulai.</p>
        <?php if ($active < PRETES_CBT_COUNT): ?><div class="alert alert-warning">Minimal 25 soal aktif diperlukan agar peserta dapat memulai ujian.</div><?php endif; ?>
        <?php if ($message): ?><div class="alert alert-danger"><?= sanitize($message) ?></div><?php endif; ?>
        <?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Soal berhasil disimpan.</div><?php endif; ?>
        <h4><?= empty($form['id']) ? 'Tambah Soal' : 'Edit Soal #' . (int)$form['id'] ?></h4>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="id" value="<?= (int)($form['id'] ?? 0) ?>">
            <label>Kategori <input class="form-control" name="category" maxlength="80" required value="<?= sanitize($form['category'] ?? '') ?>"></label>
            <div class="form-group" style="margin:14px 0"><label>Pertanyaan</label><textarea class="form-control" name="question_text" rows="3" maxlength="10000" required><?= sanitize($form['question_text'] ?? '') ?></textarea></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
                <?php foreach (range('A', 'E') as $letter): ?>
                    <label>Pilihan <?= $letter ?><textarea class="form-control" name="option_<?= $letter ?>" maxlength="2000" rows="2" required><?= sanitize($form['option_' . $letter] ?? '') ?></textarea></label>
                <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;margin:18px 0">
                <label>Kunci jawaban <select class="form-control" name="correct_answer" required><option value="">Pilih</option><?php foreach (range('A', 'E') as $letter): ?><option value="<?= $letter ?>" <?= ($form['correct_answer'] ?? '') === $letter ? 'selected' : '' ?>><?= $letter ?></option><?php endforeach; ?></select></label>
                <label><input type="checkbox" name="is_active" value="1" <?= !empty($form['is_active']) ? 'checked' : '' ?>> Soal aktif</label>
                <button class="btn btn-primary" type="submit">Simpan Soal</button>
                <a class="btn btn-secondary" href="<?= $bankUrl ?>">Form Soal Baru</a>
            </div>
        </form>
    </div>
</div>
<div class="card" style="margin-bottom:20px"><div class="card-header">Daftar Bank Soal</div><div class="card-body table-responsive">
    <table class="table"><thead><tr><th>No.</th><th>Kategori</th><th>Pertanyaan & Pilihan</th><th>Kunci</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($questions as $i => $question): ?><tr>
        <td><?= $i + 1 ?></td><td><?= sanitize($question['category']) ?></td>
        <td><strong><?= sanitize($question['question_text']) ?></strong><?php foreach (json_decode($question['options_json'], true) as $letter => $text): ?><div style="font-size:12px;margin-top:4px"><?= $letter ?>. <?= sanitize($text) ?></div><?php endforeach; ?></td>
        <td><?= sanitize($question['correct_answer']) ?></td><td><?= $question['is_active'] ? 'Aktif' : 'Nonaktif' ?></td>
        <td><a class="btn btn-sm btn-primary" href="<?= $bankUrl ?>&amp;edit=<?= (int)$question['id'] ?>">Edit</a></td>
    </tr><?php endforeach; ?></tbody></table>
</div></div>
<div class="card"><div class="card-header">100 Sesi CBT Terbaru</div><div class="card-body table-responsive">
    <p>Nilai CBT dicatat terpisah dari penilaian praktik pretes dan nilai impor lama.</p>
    <table class="table"><thead><tr><th>Nama</th><th>NIM</th><th>Mulai (WIB)</th><th>Status</th><th>Benar / 25</th><th>Nilai</th></tr></thead><tbody>
    <?php foreach ($attempts as $attempt): ?><tr>
        <td><?= sanitize($attempt['nama_lengkap']) ?></td><td><?= sanitize($attempt['nim'] ?? '-') ?></td>
        <td><?= (new DateTimeImmutable('@' . $attempt['started_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i') ?></td>
        <td><?= $attempt['submitted_at'] !== null ? 'Selesai' : (time() >= $attempt['deadline_at'] ? 'Waktu habis · menunggu finalisasi' : 'Berlangsung') ?></td>
        <td><?= $attempt['correct_count'] !== null ? (int)$attempt['correct_count'] : '-' ?></td><td><?= $attempt['score'] !== null ? number_format((float)$attempt['score'], 0) : '-' ?></td>
    </tr><?php endforeach; ?></tbody></table>
</div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
