<?php
if (empty($active_gel)) {
    http_response_code(404);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') http_response_code(403);
header('Cache-Control: private, no-store');
include __DIR__ . '/header.php';
?>
<h2 class="page-title"><?= PAGE_TITLE ?></h2>
<section class="card" id="tutorial-registration-closed" aria-labelledby="tutorial-registration-closed-title" style="border-top:4px solid #d97706;">
    <div class="card-body" style="text-align:center;padding:32px 24px;">
        <div style="font-size:36px;margin-bottom:12px;" aria-hidden="true">🔒</div>
        <h3 id="tutorial-registration-closed-title" style="color:#92400e;margin:0 0 12px;">Pendaftaran telah ditutup</h3>
        <p style="margin:0 0 12px;line-height:1.7;">Jadwal tutorial untuk periode ini sudah diplot. Mahasiswa tidak dapat melakukan pendaftaran baru.</p>
        <p style="color:#64748b;margin:0 0 24px;"><?= sanitize($active_gel['tahun_ajaran'] . ' / ' . $active_gel['semester'] . ' / ' . $active_gel['gelombang']) ?></p>
        <div style="display:flex;justify-content:center;flex-wrap:wrap;gap:12px;">
            <a href="<?= BASE_URL ?>/tutorial-info.php" class="btn btn-primary" style="width:auto;">Lihat Info Tutorial</a>
            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary" style="width:auto;">Kembali ke Dashboard</a>
        </div>
    </div>
</section>
<?php include __DIR__ . '/footer.php'; ?>
