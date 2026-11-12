<?php
/**
 * Migrasi skema `master_pretes`: menambahkan kolom `target_program` (`Semua` / `RPL` / `Reguler`)
 * dan `tanggal_mulai` (`DATE`) secara idempoten.
 *
 * Dapat dijalankan via CLI (`php migration_pretes_target_program.php`) atau lewat browser oleh Admin.
 */

$isCli = (PHP_SAPI === 'cli');
if ($isCli) {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/pretes-helper.php';
} else {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/pretes-helper.php';
    requireAdmin();
}

$pdo = getDBConnection();
ensurePretesPeriodTargetColumns($pdo);

$message = "Kolom target_program dan tanggal_mulai pada tabel master_pretes siap digunakan.";
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
    <title>Migrasi Target Program Pretes</title>
</head>
<body style="font-family:system-ui,sans-serif;padding:32px;background:#f8fafc;">
    <div style="max-width:600px;margin:0 auto;background:#fff;padding:24px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.08);">
        <h2 style="margin-top:0;">Migrasi Skema Pretes Selesai</h2>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <a href="<?= BASE_URL ?>/admin/pretes-dosen.php" style="display:inline-block;padding:10px 18px;background:#14532d;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">
            Buka Penguji &amp; Kuota Pretes
        </a>
    </div>
</body>
</html>
