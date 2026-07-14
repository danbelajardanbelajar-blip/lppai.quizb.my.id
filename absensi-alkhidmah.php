<?php
/**
 * LPPAI Corner - Absensi Al Khidmah
 */
define('PAGE_TITLE', 'Absensi Al Khidmah');
require_once __DIR__ . '/includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
} elseif (isDosen()) {
    header('Location: ' . BASE_URL . '/dosen/dashboard.php');
    exit;
}

$user = getCurrentUser();
$pdo = getDBConnection();

include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header">🕌 Absensi Al Khidmah</div>
    <div class="card-body">
        <div class="empty-state">
            <div class="icon" style="font-size: 48px; text-align: center; margin-bottom: 20px;">🚧</div>
            <h3 style="text-align: center;">Fitur Dalam Pengembangan</h3>
            <p style="text-align: center; color: #6b7280;">Halaman absensi Al Khidmah ini masih dalam tahap pengembangan dan akan segera hadir.</p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
