<?php
/**
 * LPPAI Corner - Migration for User Activity Logs (Login & Click Tracking)
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    define('PAGE_TITLE', 'Migrasi Tabel Log Aktivitas');
    require_once __DIR__ . '/includes/auth.php';
    requireAdmin();
} else {
    require_once __DIR__ . '/config/database.php';
}

require_once __DIR__ . '/includes/activity-logger.php';

$message = '';
$msgType = 'info';

function runMigration() {
    $pdo = getDBConnection();
    ensureActivityLogTablesExist($pdo);
    return "Tabel 'user_login_logs' dan 'user_click_logs' berhasil dibuat atau sudah ada.";
}

if ($isCli) {
    try {
        echo runMigration() . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $message = "Sesi tidak valid.";
        $msgType = "danger";
    } else {
        try {
            $message = runMigration();
            $msgType = "success";
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $msgType = "danger";
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="card" style="max-width: 650px; margin: 30px auto;">
    <div class="card-header">🛠️ Migrasi Tabel Log Aktivitas</div>
    <div class="card-body">
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $msgType ?>" style="margin-bottom: 20px; padding: 12px; border-radius: 8px;">
                <?= sanitize($message) ?>
            </div>
        <?php endif; ?>

        <p style="margin-bottom: 18px; color: #4b5563; line-height: 1.6;">
            Halaman ini digunakan untuk menyiapkan tabel <code>user_login_logs</code> dan <code>user_click_logs</code>
            guna merekam seluruh aktivitas login serta klik interaktif dari semua pengguna sistem (Admin, Dosen, Mahasiswa).
        </p>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px;">
                <span>🚀</span> Jalankan Migrasi Tabel Sekarang
            </button>
            <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-secondary" style="margin-left: 10px;">
                Kembali ke Dashboard
            </a>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
