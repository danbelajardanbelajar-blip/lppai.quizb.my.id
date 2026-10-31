<?php
define('PAGE_TITLE', 'Absensi Pretes');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pretes-helper.php';
header('Cache-Control: private, no-store');
$token = $_GET['token'] ?? '';
if (!is_string($token) || !preg_match('/^[a-f0-9]{48}$/D', $token)) { http_response_code(400); exit('QR absensi pretes tidak valid.'); }
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . '/index.php?redirect=' . rawurlencode('absensi-pretes.php?token=' . $token));
    exit;
}
requireLogin();
$user = getCurrentUser();
if ($user['role'] !== 'mahasiswa') { http_response_code(403); exit('Absensi hanya untuk mahasiswa peserta pretes.'); }
$pdo = getDBConnection();
ensurePretesFeatures($pdo);
$stmt = $pdo->prepare('SELECT *, (open_until > NOW()) AS is_open FROM pretes_attendance_sessions WHERE token = ?');
$stmt->execute([$token]);
$session = $stmt->fetch();
if (!$session) { http_response_code(404); exit('QR absensi pretes tidak ditemukan.'); }
$message = '';
$msgType = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) throw new InvalidArgumentException('Sesi tidak valid. Muat ulang halaman.');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT *, (open_until > NOW()) AS is_open FROM pretes_attendance_sessions WHERE id = ? FOR UPDATE');
        $stmt->execute([$session['id']]);
        $session = $stmt->fetch();
        if (!$session || !$session['is_open']) throw new InvalidArgumentException('Absensi ditutup. Silakan hubungi dosen penguji.');
        $stmt = $pdo->prepare('SELECT * FROM pretes_registrations WHERE user_id = ? AND periode = ? FOR UPDATE');
        $stmt->execute([$user['id'], $session['periode']]);
        $registration = null;
        foreach ($stmt->fetchAll() as $row) if (pretesScopeHash(pretesScope($row)) === $session['scope_hash']) $registration = $row;
        if (!$registration) throw new InvalidArgumentException('Anda tidak terdaftar pada hari, penguji, dan ruangan sesi ini.');
        $stmt = $pdo->prepare('INSERT INTO pretes_attendance_records (registration_id, session_id) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE session_id = VALUES(session_id)');
        $stmt->execute([$registration['id'], $session['id']]);
        $pdo->prepare("UPDATE pretes_registrations SET status = 'hadir' WHERE id = ? AND user_id = ?")->execute([$registration['id'], $user['id']]);
        $pdo->commit();
        $message = 'Kehadiran Anda berhasil dicatat. Absensi berulang tidak membuat data ganda.';
        $msgType = 'success';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Gagal merekam absensi. Silakan coba kembali.';
        if (!($e instanceof InvalidArgumentException)) error_log('Absensi pretes: ' . $e->getMessage());
        $msgType = 'danger';
    }
}
include __DIR__ . '/includes/header.php';
?>
<div class="card"><div class="card-header">Konfirmasi Kehadiran Pretes</div><div class="card-body">
    <?php if ($message): ?><div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div><?php endif; ?>
    <p>Nama: <strong><?= sanitize($user['nama_lengkap']) ?></strong></p>
    <p>Periode: <?= sanitize($session['periode']) ?><br>Hari: <?= sanitize($session['hari']) ?> · 13.00 WIB<br>Penguji: <?= sanitize($session['penguji'] ?: 'Belum ditentukan') ?><br>Ruangan: <?= sanitize($session['ruangan'] ?: 'Belum ditentukan') ?></p>
    <?php if ($session['is_open'] && $msgType !== 'success'): ?>
    <form method="POST" data-no-spa><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><button class="btn btn-success" style="width:auto;">Konfirmasi Saya Hadir</button></form>
    <?php elseif (!$session['is_open']): ?><div class="alert alert-info">Absensi ditutup. Minta dosen penguji membuka absensi sesi ini.</div><?php endif; ?>
</div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
