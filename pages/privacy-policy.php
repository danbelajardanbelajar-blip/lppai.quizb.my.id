<?php
/**
 * LPPAI Corner - Privacy Policy Page
 */
define('PAGE_TITLE', 'Kebijakan Privasi');
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
        🔒 Kebijakan Privasi
    </div>
    <div class="card-body" style="<?= !$loggedIn ? 'background: #fff; padding: 20px; border-radius: 0 0 8px 8px;' : '' ?>">
        <p>Terakhir diperbarui: <?= date('d F Y') ?></p>
        
        <p>Lembaga Pengembangan Pendidikan Agama Islam (LPPAI) menghargai privasi Anda. Kebijakan Privasi ini menjelaskan bagaimana kami mengumpulkan, menggunakan, dan melindungi informasi pribadi Anda saat menggunakan aplikasi LPPAI Corner.</p>
        
        <h4 style="margin-top: 20px;">1. Informasi yang Kami Kumpulkan</h4>
        <p>Kami mengumpulkan beberapa jenis informasi untuk memberikan dan meningkatkan layanan kami kepada Anda:</p>
        <ul style="padding-left: 20px; line-height: 1.6;">
            <li><strong>Informasi Profil:</strong> Nama lengkap, NIM/NIP, Fakultas, Program Studi, dan peran (Mahasiswa/Dosen/Admin).</li>
            <li><strong>Informasi Kontak:</strong> Nomor WhatsApp / HP untuk keperluan aktivasi dan notifikasi.</li>
            <li><strong>Data Akademik:</strong> Nilai pretes, kelas tutorial, kelulusan, dan catatan absensi.</li>
            <li><strong>Data Sistem:</strong> Waktu akses, log aktivitas, dan informasi perangkat/browser secara anonim.</li>
        </ul>

        <h4 style="margin-top: 20px;">2. Penggunaan Informasi</h4>
        <p>Informasi yang kami kumpulkan digunakan untuk berbagai tujuan:</p>
        <ul style="padding-left: 20px; line-height: 1.6;">
            <li>Menyediakan dan memelihara aplikasi LPPAI Corner.</li>
            <li>Memproses pendaftaran dan penempatan kelas tutorial.</li>
            <li>Melakukan rekapitulasi nilai dan absensi secara akurat.</li>
            <li>Memberikan pemberitahuan terkait pengumuman, jadwal, dan aktivasi.</li>
            <li>Memantau penggunaan aplikasi untuk meningkatkan kualitas layanan.</li>
        </ul>

        <h4 style="margin-top: 20px;">3. Keamanan Data</h4>
        <p>Kami menerapkan langkah-langkah keamanan yang ketat untuk melindungi informasi pribadi Anda. Kata sandi (password) disimpan dalam bentuk enkripsi (hash). Kami tidak pernah menyimpan kata sandi dalam bentuk teks biasa. Akses ke data pribadi dibatasi hanya untuk pihak yang berwenang (seperti Admin dan Dosen terkait).</p>

        <h4 style="margin-top: 20px;">4. Pembagian Informasi</h4>
        <p>Kami tidak menjual, menyewakan, atau membagikan informasi pribadi Anda kepada pihak ketiga untuk tujuan komersial. Informasi Anda hanya digunakan untuk kepentingan internal akademik dan pelaporan sesuai dengan kebijakan institusi.</p>
        
        <h4 style="margin-top: 20px;">5. Perubahan pada Kebijakan Privasi</h4>
        <p>Kami dapat memperbarui Kebijakan Privasi ini dari waktu ke waktu. Kami akan memberi tahu Anda tentang perubahan apa pun dengan memasang Kebijakan Privasi yang baru di halaman ini. Anda disarankan untuk meninjau Kebijakan Privasi ini secara berkala untuk setiap perubahan.</p>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 14px; color: #666;">
            <p><strong>Hubungi Kami:</strong></p>
            <p>Jika Anda memiliki pertanyaan tentang Kebijakan Privasi ini, silakan hubungi kami melalui Administrator LPPAI.</p>
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
