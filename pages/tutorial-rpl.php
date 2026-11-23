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
<div style="max-width:760px;margin:0 auto;">
    <div style="background:linear-gradient(135deg,#0b3b22,#156138);color:#fff;border-radius:18px;padding:30px 26px;text-align:center;">
        <div style="font-size:38px;" aria-hidden="true">📝</div>
        <h2 style="color:#fff;margin:8px 0;">Pendaftaran Tutorial RPL</h2>
        <p id="tutorial-rpl-period" style="margin:0;line-height:1.7;">
            <?php if ($status !== 'inactive'): ?>
                <strong><?= $escape($rplRegistrationPolicy['nama']) ?> · <?= $escape($rplRegistrationPolicy['gelombang']) ?></strong><br>
                <?= $escape(tutorialRplPeriodSchedule($rplRegistrationPolicy)) ?>
            <?php else: ?>Jadwal akan ditampilkan setelah admin menetapkan periode aktif.<?php endif; ?>
        </p>
    </div>
    <?php include __DIR__ . '/../includes/tutorial-rpl-registration-rules-view.php'; ?>
    <div class="card" style="margin-top:18px;border-radius:16px;padding:22px;">
        <h3><?= $unavailable ? 'Jadwal belum dapat dimuat' : $escape($labels[$status]) ?></h3>
        <?php if ($unavailable): ?>
            <p>Silakan coba kembali atau hubungi admin LPPAI.</p>
        <?php elseif ($status === 'open'): ?>
            <p>Formulir khusus RPL sedang disiapkan. Informasi pembukaan pendaftaran akan disampaikan admin.</p>
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
<?php include __DIR__ . '/../includes/footer.php'; ?>
