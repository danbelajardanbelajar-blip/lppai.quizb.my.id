<?php
/**
 * Migrasi skema `announcements`: menambahkan kolom `target_program` (`Semua` / `RPL` / `Reguler`)
 * dan `target_peserta` (`semua` / `pretes` / `tutorial` / `belum_lulus_pretes` / `lulus_pretes`) secara idempoten.
 *
 * Dapat dijalankan via CLI (`php migration_announcement_target.php`) atau lewat browser oleh Admin.
 */

$isCli = (PHP_SAPI === 'cli');
if ($isCli) {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/announcement-helper.php';
} else {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/announcement-helper.php';
    requireAdmin();
}

$pdo = getDBConnection();
ensureAnnouncementTargetColumns($pdo);

$message = "Kolom target_program dan target_peserta pada tabel announcements berhasil diverifikasi dan pengumuman reguler/RPL telah disesuaikan.";
if ($isCli) {
    echo "OK: {$message}\n";
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Migrasi Target Pengumuman (RPL & Reguler)</title>
</head>
<body style="font-family:system-ui,sans-serif;padding:32px;background:#f8fafc;">
    <div style="max-width:600px;margin:0 auto;background:#fff;padding:24px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.08);">
        <h2 style="margin-top:0;">Migrasi Target Pengumuman Selesai</h2>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <a href="<?= BASE_URL ?>/admin/pengumuman.php" style="display:inline-block;padding:10px 18px;background:#14532d;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">
            Buka Kelola Pengumuman
        </a>
    </div>
</body>
</html>
