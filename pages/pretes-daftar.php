<?php
/**
 * LPPAI Corner - Daftar Pretes
 */
define('PAGE_TITLE', 'Daftar Pretes');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user = getCurrentUser();
$pdo = getDBConnection();
$message = '';
$msgType = '';

// Get active schedules
$stmt = $pdo->query("SELECT * FROM pretes_schedules WHERE status = 'aktif' AND (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%') AND tanggal >= CURDATE() ORDER BY tanggal, waktu_mulai");
$schedules = $stmt->fetchAll();

// Check if user already registered
$stmt = $pdo->prepare("
    SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan
    FROM pretes_registrations pr
    LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
    WHERE pr.user_id = ?
    LIMIT 1
");
$stmt->execute([$user['id']]);
$existingReg = $stmt->fetch();

// Handle registration — gunakan hidden input 'action' agar kompatibel dengan SPA FormData
// (FormData dari JS tidak menyertakan value tombol submit, jadi cek name='daftar' selalu gagal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'daftar') {
    $token      = $_POST['csrf_token'] ?? '';
    $scheduleId = (int)($_POST['schedule_id'] ?? 0);

    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } elseif ($existingReg) {
        $message = 'Anda sudah terdaftar untuk pretes.';
        $msgType = 'warning';
    } elseif ($scheduleId <= 0) {
        $message = 'Pilih jadwal pretes terlebih dahulu.';
        $msgType = 'danger';
    } else {
        // Check schedule exists and has capacity
        $stmt = $pdo->prepare("SELECT * FROM pretes_schedules WHERE id = ? AND status = 'aktif'");
        $stmt->execute([$scheduleId]);
        $schedule = $stmt->fetch();

        if (!$schedule) {
            $message = 'Jadwal tidak ditemukan atau sudah tidak aktif.';
            $msgType = 'danger';
        } elseif ($schedule['kuota'] > 0 && $schedule['terisi'] >= $schedule['kuota']) {
            $message = 'Kuota jadwal ini sudah penuh.';
            $msgType = 'danger';
        } else {
            // Insert registrasi dengan schedule_id
            $stmt = $pdo->prepare("INSERT INTO pretes_registrations (user_id, pretes_schedule_id, periode) VALUES (?, ?, ?)");
            $stmt->execute([$user['id'], $scheduleId, $schedule['periode']]);

            // Tambah terisi
            $pdo->prepare("UPDATE pretes_schedules SET terisi = terisi + 1 WHERE id = ?")->execute([$scheduleId]);

            $message = 'Pendaftaran pretes berhasil! Silakan cek halaman Peserta & Jadwal Pretes.';
            $msgType = 'success';

            // Refresh registration status
            $stmt = $pdo->prepare("
                SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan
                FROM pretes_registrations pr
                LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
                WHERE pr.user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$user['id']]);
            $existingReg = $stmt->fetch();

            // Refresh daftar jadwal
            $stmt = $pdo->query("SELECT * FROM pretes_schedules WHERE status = 'aktif' AND (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%') AND tanggal >= CURDATE() ORDER BY tanggal, waktu_mulai");
            $schedules = $stmt->fetchAll();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<?php if ($existingReg): ?>
<div class="card">
    <div class="card-header">✅ Status Pendaftaran Pretes</div>
    <div class="card-body">
        <div class="alert alert-success" style="margin-bottom:0;flex-direction:column;align-items:flex-start;gap:6px;">
            <div><strong>✅ Anda sudah terdaftar pretes!</strong></div>
            <div>📆 <strong>Periode:</strong> <?= sanitize($existingReg['periode']) ?></div>
            <?php if (!empty($existingReg['tanggal'])): ?>
            <div>📅 <strong>Tanggal:</strong> <?= date('d M Y', strtotime($existingReg['tanggal'])) ?>
                | <?= date('H:i', strtotime($existingReg['waktu_mulai'])) ?> – <?= date('H:i', strtotime($existingReg['waktu_selesai'])) ?>
            </div>
            <div>🏫 <strong>Ruangan:</strong> <?= sanitize($existingReg['ruangan']) ?></div>
            <?php endif; ?>
            <div>📋 <strong>Status:</strong> <span class="badge badge-success"><?= ucfirst($existingReg['status']) ?></span></div>
            <div style="font-size:12px;color:#166534;margin-top:4px;">Terdaftar pada <?= date('d M Y, H:i', strtotime($existingReg['tanggal_daftar'])) ?></div>
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
                <!-- action=daftar dipakai agar SPA FormData tetap mendeteksi intent pendaftaran -->
                <input type="hidden" name="action" value="daftar">
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
                                    <?php if ($s['kuota'] <= 0 || $s['terisi'] < $s['kuota']): ?>
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
                                <td><?= $s['terisi'] ?>/<?= $s['kuota'] ?: '∞' ?></td>
                                <td>
                                    <?php if ($s['kuota'] > 0 && $s['terisi'] >= $s['kuota']): ?>
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
                    <button type="submit" class="btn btn-primary" style="width:auto;">
                        ✍️ Daftar Pretes Sekarang
                    </button>
                </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

