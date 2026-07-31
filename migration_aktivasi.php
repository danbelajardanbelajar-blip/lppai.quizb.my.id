<?php
/**
 * Migration Script for Mahasiswa Account Activation
 */
define('PAGE_TITLE', 'Migration Account Activation');
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
$message = '';
$msgType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'])) {
        $message = "Sesi tidak valid.";
        $msgType = "danger";
    } else {
        try {
            // Check if column is_active exists
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 0 AFTER role");
                $message .= "Kolom 'is_active' berhasil ditambahkan. ";
            } else {
                $message .= "Kolom 'is_active' sudah ada. ";
            }

            // Check if column activation_token exists
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'activation_token'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN activation_token VARCHAR(128) NULL AFTER is_active");
                $message .= "Kolom 'activation_token' berhasil ditambahkan. ";
            } else {
                $message .= "Kolom 'activation_token' sudah ada. ";
            }

            // Set Admin and Dosen as active by default so they aren't locked out
            $pdo->exec("UPDATE users SET is_active = 1 WHERE role IN ('admin', 'dosen')");
            $message .= "Akun Admin dan Dosen diatur menjadi aktif secara default.";
            
            $msgType = 'success';
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $msgType = 'danger';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header">🛠️ Migrasi Database Aktivasi Akun</div>
    <div class="card-body">
        <?php if ($message): ?>
            <div class="alert alert-<?= $msgType ?>">
                <?= sanitize($message) ?>
            </div>
        <?php endif; ?>
        
        <p>Migrasi ini akan menambahkan kolom <code>is_active</code> dan <code>activation_token</code> ke dalam tabel <code>users</code> untuk mendukung fitur aktivasi akun via WhatsApp.</p>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <button type="submit" class="btn btn-primary">Jalankan Migrasi</button>
        </form>

        <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-secondary mt-3">Kembali ke Dashboard</a>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
