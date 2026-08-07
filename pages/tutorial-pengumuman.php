<?php
/**
 * LPPAI Corner - Pengumuman Kelulusan Tutorial
 */
define('PAGE_TITLE', 'Pengumuman Kelulusan Tutorial');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$user = getCurrentUser();
$pdo  = getDBConnection();

// Cek pendaftaran tutorial terakhir (terbaru)
$stmt = $pdo->prepare("
    SELECT tr.*, tc.nama_kelas, tc.gelombang
    FROM tutorial_registrations tr
    LEFT JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
    WHERE tr.user_id = ?
    ORDER BY tr.created_at DESC
    LIMIT 1
");
$stmt->execute([$user['id']]);
$latestReg = $stmt->fetch();

// Cek kehadiran Al Khidmah
$hasAlkhidmah = false;
if (!empty($user['nim'])) {
    try {
        $stmtAk = $pdo->prepare("SELECT id FROM absensi_alkhidmah WHERE nim = ? LIMIT 1");
        $stmtAk->execute([$user['nim']]);
        if ($stmtAk->fetch()) {
            $hasAlkhidmah = true;
        }
    } catch (Exception $e) {
        // Abaikan jika tabel belum di-migrate
    }
}

// Cek ketersediaan pendaftaran aktif saat ini
$active_gel = $pdo->query("SELECT * FROM master_gelombang ORDER BY created_at DESC LIMIT 1")->fetch();

include __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width: 700px; margin: 0 auto; overflow: hidden; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
    <?php if ($latestReg && (!empty($latestReg['nomor_sertifikat']) || $latestReg['status'] === 'lulus')): ?>
        <!-- Tampilan Jika Lulus (Pengganti Modal) -->
        <div style="background-color: #10b981; color: white; padding: 30px 20px; text-align: center;">
            <h2 style="margin: 0; font-size: 28px;">🎉 PENGUMUMAN KELULUSAN 🎉</h2>
            <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 16px;">Lembaga Pengembangan Pendidikan Agama Islam</p>
        </div>
        <div style="padding: 30px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h3 style="margin: 0 0 10px 0; color: #1f2937; font-size: 22px;">Selamat, <?= sanitize($user['nama_lengkap']) ?>!</h3>
                <p style="margin: 0; color: #4b5563; font-size: 16px;">Anda dinyatakan <strong style="color: #10b981; font-size: 20px;">LULUS</strong> pada program Tutorial LPPAI.</p>
                <p style="margin: 10px 0 0 0; font-size: 15px; color: #6b7280;">No. Sertifikat: <strong><?= sanitize($latestReg['nomor_sertifikat'] ?? '-') ?></strong></p>
                <p style="margin: 5px 0 0 0; font-size: 15px; color: #6b7280;">Gelombang: <strong><?= sanitize(ucfirst($latestReg['gelombang'])) ?></strong></p>
            </div>
            
            <h4 style="margin: 0 0 15px 0; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px; color: #374151;">Rincian Nilai Akhir:</h4>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 15px;">
                <tbody>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 12px 0; color: #4b5563;">Nilai Thaharah</td>
                        <td style="padding: 12px 0; text-align: right; font-weight: bold;"><?= (float)($latestReg['nilai_thaharah'] ?? 0) ?></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 12px 0; color: #4b5563;">Nilai Shalat</td>
                        <td style="padding: 12px 0; text-align: right; font-weight: bold;"><?= (float)($latestReg['nilai_shalat'] ?? 0) ?></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 12px 0; color: #4b5563;">Nilai Surat Pendek</td>
                        <td style="padding: 12px 0; text-align: right; font-weight: bold;"><?= (float)($latestReg['nilai_surat_pendek'] ?? 0) ?></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 12px 0; color: #4b5563;">Nilai Praktik Amaliyah</td>
                        <td style="padding: 12px 0; text-align: right; font-weight: bold;"><?= (float)($latestReg['nilai_amaliyah'] ?? 0) ?></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 12px 0; color: #4b5563;">Nilai Perawatan Jenazah</td>
                        <td style="padding: 12px 0; text-align: right; font-weight: bold;"><?= (float)($latestReg['nilai_jenazah'] ?? 0) ?></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 12px 0; color: #4b5563;">Nilai Ujian Tulis</td>
                        <td style="padding: 12px 0; text-align: right; font-weight: bold;"><?= (float)($latestReg['nilai_ujian_tulis'] ?? 0) ?></td>
                    </tr>
                    <tr style="background-color: #f9fafb;">
                        <?php 
                        $akhir = ($latestReg['nilai_thaharah'] + $latestReg['nilai_shalat'] + $latestReg['nilai_surat_pendek'] + $latestReg['nilai_amaliyah'] + $latestReg['nilai_jenazah'] + $latestReg['nilai_ujian_tulis']) / 6;
                        ?>
                        <td style="padding: 15px 8px; color: #111827; font-weight: bold;">NILAI AKHIR RATA-RATA</td>
                        <td style="padding: 15px 8px; text-align: right; font-weight: bold; color: #10b981; font-size: 20px;"><?= number_format($akhir, 2) ?></td>
                    </tr>
                </tbody>
            </table>
            
            <div style="text-align: left; margin-top: 30px; background-color: #eff6ff; border: 1px solid #bfdbfe; padding: 20px; border-radius: 8px;">
                <h4 style="margin: 0 0 10px 0; color: #1e3a8a; font-size: 18px;">📌 Informasi Penting Tindak Lanjut:</h4>
                <?php if (!$hasAlkhidmah): ?>
                    <p style="margin: 0; color: #1e40af; font-size: 15px; line-height: 1.5;">
                        Bagi mahasiswa yang telah dinyatakan lulus, Anda <strong>WAJIB mengikuti kegiatan Al Khidmah</strong> yang dilaksanakan pada Hari Jum'at awal bulan, yaitu pada tanggal:
                        <br><br>
                        <strong>📅 7 Agustus 2026</strong> dan <strong>📅 4 September 2026</strong>.
                    </p>
                <?php else: ?>
                    <p style="margin: 0; color: #1e40af; font-size: 15px; line-height: 1.5;">
                        Karena Anda telah mengikuti kegiatan Al Khidmah, selanjutnya Anda <strong>WAJIB membayar biaya LPPAI di Bank Jatim</strong> dan <strong>WAJIB membawa pas foto ukuran 3x4 ke kantor LPPAI</strong> untuk keperluan administrasi dan pencetakan sertifikat.
                    </p>
                <?php endif; ?>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-primary" style="padding: 10px 24px; font-size: 16px;">🏠 Kembali ke Dashboard</a>
            </div>
        </div>

    <?php else: ?>
        <!-- Tampilan Jika Belum Lulus atau Belum Ada Data -->
        <div style="background-color: #f59e0b; color: white; padding: 30px 20px; text-align: center;">
            <h2 style="margin: 0; font-size: 26px;">⚠️ STATUS KELULUSAN</h2>
            <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 16px;">Lembaga Pengembangan Pendidikan Agama Islam</p>
        </div>
        <div style="padding: 40px 30px; text-align: center;">
            <?php if (!$latestReg): ?>
                <h3 style="color: #1f2937; margin-bottom: 15px;">Belum Ada Riwayat Pendaftaran</h3>
                <p style="color: #4b5563; font-size: 16px; margin-bottom: 25px;">Anda belum memiliki riwayat pendaftaran atau kelulusan pada program Tutorial LPPAI.</p>
            <?php else: ?>
                <h3 style="color: #dc2626; margin-bottom: 15px;">Belum Dinyatakan Lulus</h3>
                <p style="color: #4b5563; font-size: 16px; margin-bottom: 15px;">Status terakhir Anda pada program Tutorial <strong><?= sanitize(ucfirst($latestReg['gelombang'])) ?></strong> adalah <span class="badge badge-danger" style="font-size: 14px;"><?= ucfirst(str_replace('_', ' ', $latestReg['status'])) ?></span>.</p>
                <p style="color: #4b5563; font-size: 16px; margin-bottom: 25px;">Oleh karena itu, Anda belum berhak mendapatkan sertifikat kelulusan.</p>
            <?php endif; ?>

            <?php if ($active_gel): ?>
                <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; padding: 20px; border-radius: 8px; margin-top: 20px;">
                    <h4 style="color: #166534; margin-top: 0;">Pendaftaran Tutorial Sedang Dibuka!</h4>
                    <p style="color: #15803d; margin-bottom: 15px;">Gelombang <strong><?= sanitize(ucfirst($active_gel['nama_gelombang'] ?? 'Baru')) ?></strong> sedang aktif.</p>
                    <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="btn btn-success" style="padding: 12px 24px; font-size: 16px; font-weight: bold; background-color: #16a34a; display: inline-block;">
                        📝 Daftarkan Diri Anda Sekarang
                    </a>
                </div>
            <?php else: ?>
                <div style="background-color: #f3f4f6; border: 1px solid #e5e7eb; padding: 20px; border-radius: 8px; margin-top: 20px;">
                    <p style="color: #6b7280; margin: 0;">Saat ini belum ada gelombang pendaftaran tutorial yang dibuka.</p>
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 30px;">
                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary">Kembali ke Dashboard</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
