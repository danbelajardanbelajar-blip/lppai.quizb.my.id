<?php
/**
 * Skrip Update Status Mahasiswa Angkatan 2026 ke Program RPL:
 * - Menangani 112 mahasiswa baru Angkatan 2026 dari ekspor SIAKAD UNISDA.
 * - Mengubah status `jenis_program` dari 'Reguler' menjadi 'RPL' secara aman & idempotent.
 * - Jika data mahasiswa belum ada di DB, otomatis dibuatkan akun baru sebagai 'RPL' (is_active = 1, password format dmY).
 *
 * Dapat dijalankan via CLI: `php update_angkatan_2026_to_rpl.php`
 * atau via Web Browser oleh Admin: `https://lppai.quizb.my.id/update_angkatan_2026_to_rpl.php`
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
$stats = updateAngkatan2026ToRpl($pdo, $records);

if ($isCli) {
    echo "========================================================\n";
    echo "  UPDATE STATUS MAHASISWA ANGKATAN 2026 KE PROGRAM RPL\n";
    echo "========================================================\n";
    echo "Total data diproses : {$stats['total']}\n";
    echo "Di-update ke RPL    : {$stats['updated']}\n";
    echo "Baru di-insert RPL  : {$stats['inserted']}\n";
    echo "Dilewati (sudah RPL): {$stats['skipped']}\n";
    if (!empty($stats['errors'])) {
        echo "Errors:\n - " . implode("\n - ", $stats['errors']) . "\n";
    }
    echo "--------------------------------------------------------\n";
    echo "Rincian aksi:\n";
    foreach ($stats['details'] as $line) {
        echo " * $line\n";
    }
    echo "========================================================\n";
    echo "SELESAI: Seluruh mahasiswa Angkatan 2026 kini berstatus RPL.\n";
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Mahasiswa Angkatan 2026 ke RPL - <?= defined('APP_NAME') ? APP_NAME : 'LPPAI' ?></title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #1e293b; padding: 32px 16px; margin: 0; }
        .box { max-width: 860px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 28px; box-shadow: 0 10px 28px rgba(15, 23, 42, .08); border: 1px solid #e2e8f0; }
        .header-title { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
        .badge { background: #0f766e; color: #fff; font-size: 13px; font-weight: 700; padding: 4px 10px; border-radius: 6px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin: 20px 0; }
        .stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; text-align: center; }
        .stat span { display: block; font-size: 13px; color: #64748b; font-weight: 600; margin-bottom: 4px; }
        .stat strong { display: block; font-size: 28px; color: #0f766e; }
        .stat.highlight strong { color: #d97706; }
        pre { background: #0f172a; color: #f1f5f9; padding: 16px; border-radius: 10px; max-height: 380px; overflow: auto; font-size: 13px; line-height: 1.5; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        .actions { margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap; }
        a.btn { display: inline-flex; align-items: center; background: #14532d; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 14px; }
        a.btn:hover { background: #166534; }
        a.btn-secondary { background: #475569; }
        a.btn-secondary:hover { background: #334155; }
    </style>
</head>
<body>
<div class="box">
    <div class="header-title">
        <h2 style="margin:0; font-size:22px; color:#0f172a;">Update Status Mahasiswa Angkatan 2026</h2>
        <span class="badge">Program RPL</span>
    </div>
    <p style="margin:0 0 16px; color:#475569; font-size:14.5px;">
        Data mahasiswa baru Angkatan 2026 berhasil disinkronkan ke tabel <code>users</code> dengan status <strong>Program RPL</strong>.
    </p>

    <div class="stats">
        <div class="stat"><span>Total Mahasiswa</span><strong><?= (int)$stats['total'] ?></strong></div>
        <div class="stat highlight"><span>Diupdate ke RPL</span><strong><?= (int)$stats['updated'] ?></strong></div>
        <div class="stat"><span>Baru di-Insert (RPL)</span><strong><?= (int)$stats['inserted'] ?></strong></div>
        <div class="stat"><span>Dilewati (Sudah RPL)</span><strong><?= (int)$stats['skipped'] ?></strong></div>
    </div>

    <h4 style="margin:20px 0 8px; font-size:15px; color:#334155;">Log Rincian Aksi:</h4>
    <pre><?= htmlspecialchars(implode("\n", $stats['details']), ENT_QUOTES, 'UTF-8') ?></pre>

    <div class="actions">
        <a class="btn" href="<?= defined('BASE_URL') ? BASE_URL : '' ?>/admin/users.php">&larr; Kembali ke Kelola Pengguna</a>
        <a class="btn btn-secondary" href="<?= defined('BASE_URL') ? BASE_URL : '' ?>/admin/dashboard.php">Dashboard Admin</a>
    </div>
</div>
</body>
</html>
