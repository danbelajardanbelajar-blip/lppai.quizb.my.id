<?php
/**
 * LPPAI Corner - Keuangan
 */
define('PAGE_TITLE', 'Keuangan');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$view = isset($_GET['view']) ? $_GET['view'] : 'rencana-anggaran';

$viewLabels = [
    'rencana-anggaran' => 'Rencana Anggaran',
    'pemasukan' => 'Pemasukan',
    'pengeluaran' => 'Pengeluaran',
    'laporan' => 'Laporan',
];

$viewTitle = $viewLabels[$view] ?? 'Keuangan';

include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">💰 Kelola Keuangan</div>
    <div class="card-body">
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
            <a href="<?= BASE_URL ?>/admin/keuangan.php?view=rencana-anggaran" class="btn btn-secondary" style="width:auto;">Rencana Anggaran</a>
            <a href="<?= BASE_URL ?>/admin/keuangan.php?view=pemasukan" class="btn btn-secondary" style="width:auto;">Pemasukan</a>
            <a href="<?= BASE_URL ?>/admin/keuangan.php?view=pengeluaran" class="btn btn-secondary" style="width:auto;">Pengeluaran</a>
            <a href="<?= BASE_URL ?>/admin/keuangan.php?view=laporan" class="btn btn-secondary" style="width:auto;">Laporan</a>
        </div>

        <h3><?= sanitize($viewTitle) ?></h3>
        <p>Halaman <?= sanitize($viewTitle) ?> siap digunakan untuk pengembangan fitur keuangan lanjutan.</p>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
