<?php
/**
 * LPPAI Corner - Daftar Pretes
 */
define('PAGE_TITLE', 'Daftar Pretes');
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = getCurrentUser();
$pdo = getDBConnection();
$message = '';
$msgType = '';

// Get active schedules
$stmt = $pdo->query("SELECT * FROM pretes_schedules WHERE status = 'aktif' AND tanggal >= CURDATE() ORDER BY tanggal, waktu_mulai");
$schedules = $stmt->fetchAll();

// Check if user already registered
$stmt = $pdo->prepare("SELECT * FROM pretes_registrations WHERE user_id = ?");
$stmt->execute([$user['id']]);
$existingReg = $stmt->fetch();

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['daftar'])) {
    $token = $_POST['csrf_token'] ?? '';
    $scheduleId = (int)($_POST['schedule_id'] ?? 0);

    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } elseif ($existingReg) {
        $message = 'Anda sudah terdaftar untuk pretes.';
        $msgType = 'warning';
    } elseif ($scheduleId <= 0) {
        $message = 'Pilih jadwal pretes.';
        $msgType = 'danger';
    } else {
        // Check schedule exists and has capacity
        $stmt = $pdo->prepare("SELECT * FROM pretes_schedules WHERE id = ? AND status = 'aktif'");
        $stmt->execute([$scheduleId]);
        $schedule = $stmt->fetch();

        if (!$schedule) {
            $message = 'Jadwal tidak ditemukan.';
            $msgType = 'danger';
        } elseif ($schedule['terisi'] >= $schedule['kuota']) {
            $message = 'Kuota jadwal ini sudah penuh.';
            $msgType = 'danger';
        } else {
            $stmt = $pdo->prepare("INSERT INTO pretes_registrations (user_id, periode) VALUES (?, ?)");
            $stmt->execute([$user['id'], $schedule['periode']]);

            $pdo->prepare("UPDATE pretes_schedules SET terisi = terisi + 1 WHERE id = ?")->execute([$scheduleId]);

            $message = 'Pendaftaran pretes berhasil!';
            $msgType = 'success';

            // Refresh registration status
            $stmt = $pdo->prepare("SELECT * FROM pretes_registrations WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            $existingReg = $stmt->fetch();
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<?php if ($existingReg): ?>
<div class="card">
    <div class="card-header">✅ Status Pendaftaran Pretes</div>
    <div class="card-body">
        <div class="alert alert-success" style="margin-bottom:0;">
            <strong>Anda sudah terdaftar pretes!</strong><br>
            Periode: <?= sanitize($existingReg['periode']) ?><br>
            Tanggal Daftar: <?= date('d M Y, H:i', strtotime($existingReg['tanggal_daftar'])) ?><br>
            Status: <span class="badge badge-success"><?= ucfirst($existingReg['status']) ?></span>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">📅 Jadwal Pretes Tersedia</div>
    <div class="card-body">
        <?php if (empty($schedules)): ?>
            <div class="empty-state">
                <div class="icon">📅</div>
                <h3>Belum ada jadwal pretes</h3>
                <p>Jadwal pretes akan ditampilkan ketika tersedia.</p>
            </div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <?php if (!$existingReg): ?><th>Pilih</th><?php endif; ?>
                                <th>Periode</th>
                                <th>Tanggal</th>
                                <th>Waktu</th>
                                <th>Ruangan</th>
                                <th>Kuota</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($schedules as $s): ?>
                            <tr>
                                <?php if (!$existingReg): ?>
                                <td>
                                    <?php if ($s['terisi'] < $s['kuota']): ?>
                                        <input type="radio" name="schedule_id" value="<?= $s['id'] ?>" required>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Penuh</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td><?= sanitize($s['periode']) ?></td>
                                <td><?= date('d M Y', strtotime($s['tanggal'])) ?></td>
                                <td><?= date('H:i', strtotime($s['waktu_mulai'])) ?> - <?= date('H:i', strtotime($s['waktu_selesai'])) ?></td>
                                <td><?= sanitize($s['ruangan']) ?></td>
                                <td><?= $s['terisi'] ?>/<?= $s['kuota'] ?></td>
                                <td>
                                    <?php if ($s['terisi'] >= $s['kuota']): ?>
                                        <span class="badge badge-danger">Penuh</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">Tersedia</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$existingReg): ?>
                <div style="margin-top: 20px;">
                    <button type="submit" name="daftar" class="btn btn-primary" style="width:auto;">
                        ✍️ Daftar Pretes Sekarang
                    </button>
                </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
