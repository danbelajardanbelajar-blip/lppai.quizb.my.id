<?php
/**
 * Migration Script for Absensi Al Khidmah
 */
define('PAGE_TITLE', 'Migration Al Khidmah');
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
$message = '';

try {
    $sql = "
    CREATE TABLE IF NOT EXISTS absensi_alkhidmah (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nim VARCHAR(50) NOT NULL,
        tanggal DATE NOT NULL,
        waktu_hadir TIME NULL,
        foto_hadir VARCHAR(255) NULL,
        waktu_pulang TIME NULL,
        foto_pulang VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_nim_tanggal (nim, tanggal)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    $pdo->exec($sql);
    $message = "Tabel 'absensi_alkhidmah' berhasil dibuat atau sudah ada.";
} catch (PDOException $e) {
    $message = "Error: " . $e->getMessage();
}

include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header">🛠️ Migrasi Database Al Khidmah</div>
    <div class="card-body">
        <div class="alert alert-info">
            <?= sanitize($message) ?>
        </div>
        <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-primary mt-3">Kembali ke Dashboard</a>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
