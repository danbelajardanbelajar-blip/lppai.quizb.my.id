<?php
/**
 * LPPAI Corner - About Page
 */
define('PAGE_TITLE', 'Tentang Kami');
require_once __DIR__ . '/../includes/auth.php';

$loggedIn = isLoggedIn();

if ($loggedIn) {
    include __DIR__ . '/../includes/header.php';
} else {
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= PAGE_TITLE ?> - <?= APP_NAME ?? 'LPPAI Corner' ?></title>
        <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    </head>
    <body style="background: #f4f7f6; padding: 20px;">
        <div style="max-width: 800px; margin: 0 auto;">
            <div style="text-align: center; margin-bottom: 20px;">
                <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo" style="height: 60px;">
                <h2><?= APP_NAME ?? 'LPPAI Corner' ?></h2>
            </div>
            <a href="<?= BASE_URL ?>/index.php" class="btn btn-secondary" style="margin-bottom: 15px; display: inline-block; padding: 8px 16px; background: #e2e8f0; color: #334155; text-decoration: none; border-radius: 6px;">&larr; Kembali</a>
    <?php
}
?>

<div class="card" style="<?= !$loggedIn ? 'box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-radius: 8px; border: none;' : '' ?>">
    <div class="card-header" style="<?= !$loggedIn ? 'background: #fff; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; border-radius: 8px 8px 0 0;' : '' ?>">
        ℹ️ Tentang Kami
    </div>
    <div class="card-body" style="<?= !$loggedIn ? 'background: #fff; padding: 20px; border-radius: 0 0 8px 8px;' : '' ?>">
        <h3 style="margin-top: 0;">Lembaga Pengembangan Pendidikan Agama Islam (LPPAI)</h3>
        <p>LPPAI Corner adalah platform sistem informasi terpadu yang dirancang khusus untuk memfasilitasi berbagai kegiatan akademik dan non-akademik di lingkungan Lembaga Pengembangan Pendidikan Agama Islam.</p>
        
        <h4 style="margin-top: 20px;">Visi Kami</h4>
        <p>Menjadi pusat keunggulan dalam pengembangan pendidikan agama Islam yang inovatif, inklusif, dan berorientasi pada nilai-nilai keislaman serta kebangsaan.</p>

        <h4 style="margin-top: 20px;">Misi Kami</h4>
        <ul style="padding-left: 20px; line-height: 1.6;">
            <li>Menyelenggarakan program pendidikan dan pelatihan agama Islam yang berkualitas.</li>
            <li>Mengembangkan kurikulum dan metode pembelajaran agama Islam yang adaptif terhadap perkembangan zaman.</li>
            <li>Memfasilitasi kegiatan pengkajian dan penelitian di bidang pendidikan agama Islam.</li>
            <li>Menjalin kerja sama dengan berbagai pihak untuk meningkatkan mutu pendidikan agama Islam.</li>
        </ul>

        <h4 style="margin-top: 20px;">Layanan Kami</h4>
        <p>Melalui portal LPPAI Corner, mahasiswa dan dosen dapat mengakses berbagai layanan seperti:</p>
        <ul style="padding-left: 20px; line-height: 1.6;">
            <li>Pendaftaran dan pelaksanaan Pretes.</li>
            <li>Manajemen kelas Tutorial.</li>
            <li>Pengecekan kelulusan dan nilai.</li>
            <li>Absensi kegiatan seperti Al Khidmah dan lainnya.</li>
        </ul>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 14px; color: #666;">
            <p><strong>Kontak Kami:</strong></p>
            <p>Email: info@lppai.quizb.my.id<br>
            Alamat: Gedung LPPAI, Kampus Utama</p>
        </div>
    </div>
</div>

<?php
if ($loggedIn) {
    include __DIR__ . '/../includes/footer.php';
} else {
    ?>
        </div>
        <div style="text-align: center; margin-top: 20px; color: #666; font-size: 12px;">
            &copy; <?= date('Y') ?> <?= APP_NAME ?? 'LPPAI Corner' ?>. All rights reserved.
        </div>
    </body>
    </html>
    <?php
}
?>
