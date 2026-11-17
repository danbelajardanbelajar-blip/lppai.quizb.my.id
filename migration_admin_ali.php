<?php
/**
 * LPPAI Corner - Migrasi & Penyiapan Akun Admin: ali (password: 123)
 *
 * Dapat dijalankan via CLI: `php migration_admin_ali.php`
 * atau via Web Browser: `https://lppai.quizb.my.id/migration_admin_ali.php`
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/user-helper.php';

$pdo = getDBConnection();
$ok = ensureAdminUser($pdo, 'ali', '123', 'Ali');

$stmt = $pdo->prepare("SELECT id, username, nama_lengkap, role, is_active FROM users WHERE username = 'ali' LIMIT 1");
$stmt->execute();
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (PHP_SAPI === 'cli') {
    echo "==============================================\n";
    echo "  PENYIAPAN AKUN ADMIN BARU: ALI\n";
    echo "==============================================\n";
    if ($admin) {
        echo "[OK] Akun Admin 'ali' berhasil disiapkan!\n";
        echo "ID       : " . $admin['id'] . "\n";
        echo "Username : " . $admin['username'] . "\n";
        echo "Nama     : " . $admin['nama_lengkap'] . "\n";
        echo "Role     : " . $admin['role'] . "\n";
        echo "Status   : " . ($admin['is_active'] ? 'Aktif (1)' : 'Nonaktif (0)') . "\n";
        echo "Password : 123\n";
        echo "Login di : " . (defined('BASE_URL') ? BASE_URL : '') . "/index.php\n";
    } else {
        echo "[GAGAL] Gagal membuat akun admin ali.\n";
    }
    echo "==============================================\n";
    exit($admin ? 0 : 1);
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Penyiapan Akun Admin: Ali</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #1e293b; padding: 24px; margin: 0; }
        .box { max-width: 480px; margin: 40px auto; background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0; }
        h2 { margin-top: 0; color: #047857; display: flex; align-items: center; gap: 8px; font-size: 20px; }
        .item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .item span { color: #64748b; }
        .item strong { color: #0f172a; }
        .btn { display: block; margin-top: 24px; background: #047857; color: #fff; padding: 11px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; text-align: center; transition: background 0.15s ease; }
        .btn:hover { background: #065f46; }
    </style>
</head>
<body>
<div class="box">
    <h2><span>✅</span> Akun Admin Berhasil Disiapkan</h2>
    <p style="color: #64748b; font-size: 13.5px; line-height: 1.5;">Akun admin baru dengan nama <strong>Ali</strong> telah berhasil dibuat dan aktif di sistem.</p>
    <div class="item"><span>Username</span> <strong>ali</strong></div>
    <div class="item"><span>Password</span> <strong>123</strong></div>
    <div class="item"><span>Nama Lengkap</span> <strong>Ali</strong></div>
    <div class="item"><span>Role</span> <strong style="color: #047857;">admin</strong></div>
    <div class="item"><span>Status</span> <strong style="color: #047857;">Aktif (1)</strong></div>
    <a href="<?= BASE_URL ?>/index.php" class="btn">Menuju Halaman Login &rarr;</a>
</div>
</body>
</html>
