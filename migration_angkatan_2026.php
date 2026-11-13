<?php
/**
 * Sinkronisasi data Mahasiswa Angkatan 2026 ke tabel `users`:
 * - Total data: 112 mahasiswa RPL dari ekspor SIAKAD UNISDA.
 * - Terkonfirmasi: jenis_program = 'RPL', angkatan = '2026', role = 'mahasiswa', is_active = 1.
 * - Password default: tanggal lahir format dmY (misal: 03111981) atau 123456 jika kosong (cost 8).
 * - Idempotent: aman dijalankan berulang kali.
 *
 * Dapat dijalankan via CLI (`php migration_angkatan_2026.php`) atau lewat browser oleh Admin.
 */

$isCli = (PHP_SAPI === 'cli');
if ($isCli) {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/user-helper.php';
} else {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/user-helper.php';
    requireAdmin();
}

$records = require __DIR__ . '/data/mahasiswa-angkatan-2026.php';
$pdo = getDBConnection();
$stats = upsertAngkatan2026Records($pdo, $records, 'RPL');

if ($isCli) {
    echo "=== SINKRONISASI MAHASISWA ANGKATAN 2026 (RPL) ===\n";
    echo "Total diproses : {$stats['total']}\n";
    echo "Baru di-insert : {$stats['inserted']}\n";
    echo "Di-update      : {$stats['updated']}\n";
    echo "Dilewati       : {$stats['skipped']}\n";
    if (!empty($stats['errors'])) {
        echo "Errors:\n - " . implode("\n - ", $stats['errors']) . "\n";
    }
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Sinkronisasi Mahasiswa Angkatan 2026 (RPL)</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; color: #1e293b; padding: 32px; }
        .box { max-width: 820px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 24px 28px; box-shadow: 0 8px 24px rgba(15, 23, 42, .08); }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 18px 0; }
        .stat { background: #f1f5f9; border-radius: 10px; padding: 14px; text-align: center; }
        .stat strong { display: block; font-size: 24px; color: #0f766e; }
        pre { background: #0f172a; color: #e2e8f0; padding: 14px; border-radius: 10px; max-height: 360px; overflow: auto; font-size: 12.5px; }
        a.btn { display: inline-block; margin-top: 12px; background: #14532d; color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: 600; }
    </style>
</head>
<body>
<div class="box">
    <h2 style="margin-top:0;">Sinkronisasi Mahasiswa Angkatan 2026 (RPL) Selesai</h2>
    <div class="stats">
        <div class="stat"><span>Total Data</span><strong><?= (int)$stats['total'] ?></strong></div>
        <div class="stat"><span>Insert Baru (RPL)</span><strong><?= (int)$stats['inserted'] ?></strong></div>
        <div class="stat"><span>Update</span><strong><?= (int)$stats['updated'] ?></strong></div>
        <div class="stat"><span>Dilewati (Sudah Ada)</span><strong><?= (int)$stats['skipped'] ?></strong></div>
    </div>
    <pre><?= htmlspecialchars(implode("\n", $stats['details']), ENT_QUOTES, 'UTF-8') ?></pre>
    <a class="btn" href="<?= BASE_URL ?>/admin/users.php">Kembali ke Kelola Pengguna</a>
</div>
</body>
</html>
