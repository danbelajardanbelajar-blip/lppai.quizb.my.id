<?php
define('PAGE_TITLE', 'Pendaftaran Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/tutorial-rpl-periods.php';
$rplRegistrationPolicy = tutorialRplPeriodPolicy(null);
$unavailable = false;
try {
    $rplRegistrationPolicy = tutorialRplPeriodPolicy(tutorialRplActivePeriod(getDBConnection()));
} catch (Throwable $e) {
    error_log('RPL registration schedule: ' . $e->getMessage());
    $unavailable = true;
}
$status = tutorialRplRegistrationWindow(null, $rplRegistrationPolicy);
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$labels = ['inactive' => 'Belum ada periode aktif', 'soon' => 'Pendaftaran belum dibuka',
    'open' => 'Jadwal pendaftaran sedang berlangsung', 'closed' => 'Periode pendaftaran telah berakhir'];
include __DIR__ . '/../includes/header.php';
?>
<div class="tutorial-rpl-container" style="max-width:960px;margin:0 auto;">
    <div style="background:linear-gradient(135deg,var(--primary,#0b3b22),var(--primary-light,#156138));color:#fff;border-radius:18px;padding:30px 26px;text-align:center;box-shadow:0 12px 30px rgba(11,59,34,.16);margin-bottom:20px;">
        <div style="font-size:38px;margin-bottom:6px;" aria-hidden="true">📝</div>
        <h2 style="color:#fff;margin:0 0 8px;font-size:22px;font-weight:800;">Pendaftaran Tutorial RPL</h2>
        <p id="tutorial-rpl-period" style="margin:0;line-height:1.7;font-size:14px;opacity:.95;">
            <?php if ($status !== 'inactive'): ?>
                <strong><?= $escape($rplRegistrationPolicy['nama']) ?> · <?= $escape($rplRegistrationPolicy['gelombang']) ?></strong><br>
                <?= $escape(tutorialRplPeriodSchedule($rplRegistrationPolicy)) ?>
            <?php else: ?>Jadwal akan ditampilkan setelah admin menetapkan periode aktif.<?php endif; ?>
        </p>
    </div>
    <?php include __DIR__ . '/../includes/tutorial-rpl-registration-rules-view.php'; ?>
    <div class="card" style="margin-top:20px;">
        <div class="card-header">
            <span>Status Pendaftaran</span>
        </div>
        <div class="card-body">
            <h3 style="margin-top:0;font-size:17.5px;font-weight:700;color:var(--text-dark);"><?= $unavailable ? 'Jadwal belum dapat dimuat' : $escape($labels[$status]) ?></h3>
            <?php if ($unavailable): ?>
                <p>Silakan coba kembali atau hubungi admin LPPAI.</p>
            <?php elseif ($status === 'open'): ?>
                <p>Mahasiswa RPL yang belum pretes atau belum lulus dapat mendaftar. Kelulusan dan kuota diperiksa saat pendaftaran.</p>
                <a class="btn btn-primary" href="<?= BASE_URL ?>/tutorial-rpl-daftar.php">Daftar Tutorial RPL</a>
            <?php elseif ($status === 'soon'): ?>
                <p>Silakan kembali sesuai waktu buka yang tercantum di atas.</p>
            <?php else: ?>
                <p>Silakan hubungi admin LPPAI untuk informasi pendaftaran berikutnya.</p>
            <?php endif; ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;">
                <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-secondary">← Kembali ke Halaman Pretes</a>
                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary">Dashboard</a>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
