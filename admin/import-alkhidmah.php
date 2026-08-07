<?php
/**
 * LPPAI Corner - Import Kehadiran Al Khidmah
 */
define('PAGE_TITLE', 'Import Kehadiran Al Khidmah');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();

$message = '';
$msgType = '';
$unregisteredNims = [];
$successCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_import'])) {
    $file = $_FILES['file_import'];
    $tanggalKegiatan = $_POST['tanggal'] ?? date('Y-m-d');
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        
        // Memastikan file adalah CSV
        if (strtolower($ext) === 'csv') {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle) {
                try {
                    $pdo->beginTransaction();
                    
                    $stmtUser = $pdo->prepare("SELECT id FROM users WHERE nim = ?");
                    $stmtInsert = $pdo->prepare("
                        INSERT INTO absensi_alkhidmah (nim, tanggal, waktu_hadir) 
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE waktu_hadir = VALUES(waktu_hadir)
                    ");
                    
                    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                        // Fallback jika delimiter koma
                        if (count($data) == 1 && strpos($data[0], ',') !== false) {
                            $data = explode(',', $data[0]);
                        }
                        
                        $nim = trim($data[0]);
                        if (empty($nim) || strtolower($nim) == 'nim') continue; // Skip header/kosong
                        
                        // Cek di table users
                        $stmtUser->execute([$nim]);
                        if (!$stmtUser->fetch()) {
                            $unregisteredNims[] = $nim;
                        }
                        
                        // Eksekusi insert (waktu hadir default jam 07:00:00 jika manual)
                        $stmtInsert->execute([$nim, $tanggalKegiatan, '07:00:00']);
                        $successCount++;
                    }
                    
                    $pdo->commit();
                    $message = "Berhasil mengimpor $successCount baris data absensi.";
                    $msgType = "success";
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $message = "Error Database: " . $e->getMessage() . " (Pastikan Anda sudah menjalankan migration_alkhidmah.php)";
                    $msgType = "danger";
                }
                fclose($handle);
                
            } else {
                $message = "Gagal membaca file CSV.";
                $msgType = "danger";
            }
        } else {
            $message = "Format file tidak didukung. Harap unggah file CSV.";
            $msgType = "danger";
        }
    } else {
        $message = "Terjadi kesalahan saat mengunggah file.";
        $msgType = "danger";
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <span>📥 Import Absensi Al Khidmah</span>
        <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-secondary btn-sm">Kembali</a>
    </div>
    <div class="card-body">
        <?php if ($message): ?>
            <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
        <?php endif; ?>

        <?php if (!empty($unregisteredNims)): ?>
            <div class="alert alert-warning">
                <strong>⚠️ Peringatan:</strong> Terdapat <?= count(array_unique($unregisteredNims)) ?> NIM yang berhasil diimpor absensinya, namun <strong>belum terdaftar</strong> di tabel User:
                <ul style="margin-top: 10px; max-height: 200px; overflow-y: auto;">
                    <?php foreach (array_unique($unregisteredNims) as $uNim): ?>
                        <li><?= sanitize($uNim) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="mt-4">
            <div class="form-group mb-3">
                <label>Tanggal Kegiatan Al Khidmah</label>
                <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group mb-3">
                <label>File CSV</label>
                <input type="file" name="file_import" class="form-control" accept=".csv" required>
                <small class="text-muted">Pastikan file CSV menggunakan pemisah titik koma (;) atau koma (,), dan NIM berada di <strong>kolom pertama</strong>. <br><em>Catatan: Jika data Anda berformat Excel (.xlsx), mohon Save As ke format CSV (Comma delimited) terlebih dahulu.</em></small>
            </div>
            
            <button type="submit" class="btn btn-primary">Mulai Import</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
