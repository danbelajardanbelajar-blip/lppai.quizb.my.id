<?php
/**
 * LPPAI Corner - Hapus Akun (Account Deletion Request)
 */
define('PAGE_TITLE', 'Permintaan Hapus Akun');
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
        🗑️ Permintaan Hapus Akun dan Data
    </div>
    <div class="card-body" style="<?= !$loggedIn ? 'background: #fff; padding: 20px; border-radius: 0 0 8px 8px;' : '' ?>">
        <p>Sesuai dengan kebijakan Google Play Store dan komitmen kami terhadap privasi Anda, <strong>Lembaga Pengembangan Pendidikan Agama Islam (LPPAI)</strong> menyediakan fasilitas bagi pengguna (Mahasiswa/Dosen) untuk mengajukan penghapusan akun beserta data yang terkait dengan aplikasi <strong>LPPAI Corner</strong>.</p>
        
        <div style="background-color: #fff3cd; color: #856404; padding: 15px; border-radius: 6px; border: 1px solid #ffeeba; margin-top: 20px; margin-bottom: 20px;">
            <strong>⚠️ Perhatian:</strong> Penghapusan akun bersifat permanen. Jika akun dihapus, Anda akan kehilangan akses ke seluruh layanan akademik dan riwayat kegiatan (Pretes, Tutorial, dan Absensi).
        </div>

        <h4 style="margin-top: 20px;">Data yang Akan Dihapus</h4>
        <p>Jika pengajuan penghapusan akun disetujui, data berikut akan dihapus secara permanen dari sistem kami:</p>
        <ul style="padding-left: 20px; line-height: 1.6;">
            <li>Informasi profil pengguna (Nama, NIM, Fakultas, Program Studi)</li>
            <li>Nomor kontak dan informasi login (Password, dsb)</li>
            <li>Riwayat aktivitas dan sesi aplikasi</li>
        </ul>

        <h4 style="margin-top: 20px;">Data yang Tetap Disimpan (Retensi)</h4>
        <p>Beberapa data tidak dapat sepenuhnya dihapus untuk tujuan pelaporan akademik institusi sesuai aturan yang berlaku:</p>
        <ul style="padding-left: 20px; line-height: 1.6;">
            <li>Catatan kelulusan tutorial dan nilai pretes yang sudah terlaporkan ke sistem pusat perguruan tinggi.</li>
            <li>Log absensi historis yang diperlukan sebagai bukti penyelenggaraan kelas.</li>
        </ul>

        <h4 style="margin-top: 20px;">Langkah-langkah Mengajukan Penghapusan Akun</h4>
        <p>Untuk mengajukan penghapusan akun, silakan ikuti langkah berikut:</p>
        
        <?php if ($loggedIn && $_SESSION['role'] === 'mahasiswa'): ?>
            <?php
            // Generate link WhatsApp untuk Mahasiswa yang sedang login
            $user = getCurrentUser();
            $adminWa = '6281515726827';
            $waText = "Halo Ibu Umami,\n\n";
            $waText .= "Saya ingin mengajukan penghapusan akun LPPAI Corner saya.\n";
            $waText .= "NIM: " . $user['nim'] . "\n";
            $waText .= "Nama: " . $user['nama_lengkap'] . "\n\n";
            $waText .= "Mohon diproses untuk menghapus akun dan data pribadi saya sesuai kebijakan privasi. Terima kasih.";
            $waUrl = "https://wa.me/" . $adminWa . "?text=" . rawurlencode($waText);
            ?>
            <ol style="padding-left: 20px; line-height: 1.6;">
                <li>Klik tombol di bawah ini untuk mengirim pesan WhatsApp kepada Administrator (Ibu Umami).</li>
                <li>Pesan otomatis yang berisi NIM dan Nama Anda telah disiapkan.</li>
                <li>Admin akan memverifikasi permohonan Anda dan menghapus akun dalam waktu maksimal 7x24 jam kerja.</li>
            </ol>
            <div style="text-align: center; margin-top: 25px;">
                <a href="<?= $waUrl ?>" target="_blank" class="btn btn-primary" style="padding: 12px 24px; background-color: #dc3545; border-color: #dc3545; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;">
                    ⚠️ Hubungi Admin untuk Hapus Akun
                </a>
            </div>
        <?php else: ?>
            <ol style="padding-left: 20px; line-height: 1.6;">
                <li>Kirim pesan WhatsApp kepada Administrator (Ibu Umami) di nomor <strong>+62 815-1572-6827</strong>.</li>
                <li>Sertakan informasi lengkap Anda: <strong>Nama Lengkap</strong> dan <strong>NIM/NIP</strong>.</li>
                <li>Nyatakan dengan jelas bahwa Anda meminta penghapusan akun LPPAI Corner.</li>
                <li>Administrator akan melakukan proses verifikasi identitas (jika diperlukan) sebelum memproses penghapusan dalam batas waktu 7x24 jam kerja.</li>
            </ol>
        <?php endif; ?>

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
