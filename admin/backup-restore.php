<?php
define('PAGE_TITLE', 'Backup & Restore Database');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/BackupManager.php';

$backupDir = __DIR__ . '/../backups/';
$error = '';
$success = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'backup') {
        $filename = 'manual_' . date('Y-m-d_H-i-s') . '.sql';
        try {
            BackupManager::exportDatabase($backupDir . $filename);
            $success = "Backup manual berhasil dibuat.";
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'delete') {
        $file = $_POST['file'] ?? '';
        $filepath = $backupDir . basename($file);
        if (file_exists($filepath) && is_file($filepath)) {
            unlink($filepath);
            $success = "File backup berhasil dihapus.";
        }
    } elseif ($action === 'restore_existing') {
        $file = $_POST['file'] ?? '';
        $filepath = $backupDir . basename($file);
        try {
            BackupManager::restoreDatabase($filepath);
            $success = "Database berhasil dipulihkan dari " . htmlspecialchars($file);
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'restore_upload') {
        if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['backup_file']['tmp_name'];
            try {
                BackupManager::restoreDatabase($tmpName);
                $success = "Database berhasil dipulihkan dari file unggahan.";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = "Gagal mengunggah file backup.";
        }
    }
}

// Handle Download
if (isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $filepath = $backupDir . $file;
    if (file_exists($filepath) && is_file($filepath)) {
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
}

// Get list of backups
$backups = [];
if (is_dir($backupDir)) {
    $files = glob($backupDir . '*.sql');
    if ($files) {
        foreach ($files as $f) {
            $backups[] = [
                'name' => basename($f),
                'size' => filesize($f),
                'date' => filemtime($f)
            ];
        }
        usort($backups, function($a, $b) {
            return $b['date'] - $a['date'];
        });
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2>Daftar Backup Database</h2>
        <form method="POST" style="margin: 0;">
            <input type="hidden" name="action" value="backup">
            <button type="submit" class="btn btn-primary" onclick="return confirm('Buat backup manual sekarang?');">📥 Backup Sekarang</button>
        </form>
    </div>
    <div class="card-body">
        <?php if ($success): ?>
            <div class="alert alert-success"><?= sanitize($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <table class="table">
            <thead>
                <tr>
                    <th>Nama File</th>
                    <th>Tanggal Dibuat</th>
                    <th>Ukuran</th>
                    <th style="width: 250px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($backups)): ?>
                <tr>
                    <td colspan="4" class="text-center">Belum ada file backup.</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($backups as $b): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($b['name']) ?></strong></td>
                        <td><?= date('d M Y H:i:s', $b['date']) ?></td>
                        <td><?= round($b['size'] / 1024, 2) ?> KB</td>
                        <td>
                            <div style="display: flex; gap: 5px;">
                                <a href="?download=<?= urlencode($b['name']) ?>" class="btn btn-sm btn-info">⬇️ Unduh</a>
                                
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="restore_existing">
                                    <input type="hidden" name="file" value="<?= htmlspecialchars($b['name']) ?>">
                                    <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('PERINGATAN! Merestore database akan menimpa seluruh data saat ini. Apakah Anda benar-benar yakin?');">🔄 Restore</button>
                                </form>

                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="file" value="<?= htmlspecialchars($b['name']) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Yakin ingin menghapus file backup ini?');">🗑️ Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <h2>Restore dari File Komputer</h2>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="restore_upload">
            <div class="form-group">
                <label>Pilih File SQL</label>
                <input type="file" name="backup_file" accept=".sql" required class="form-control" style="padding-bottom: 30px;">
            </div>
            <button type="submit" class="btn btn-danger" onclick="return confirm('PERINGATAN! Merestore database dari file unggahan akan menimpa seluruh data saat ini. Lanjutkan?');">🔄 Unggah & Restore</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
