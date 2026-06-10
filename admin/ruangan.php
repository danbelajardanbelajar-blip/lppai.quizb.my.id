<?php
/**
 * LPPAI Corner - Admin: Kelola Ruangan
 */
define('PAGE_TITLE', 'Kelola Ruangan');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

// Require SimpleXLSX if available
require_once __DIR__ . '/../includes/SimpleXLSX.php';

$pdo = getDBConnection();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS rooms (
        id VARCHAR(50) PRIMARY KEY,
        ruang VARCHAR(150) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        // Tambah Ruangan Manual
        if ($action === 'create') {
            $id = trim($_POST['id'] ?? '');
            $ruang = trim($_POST['ruang'] ?? '');

            if (empty($id) || empty($ruang)) {
                $message = 'ID dan Ruangan wajib diisi.';
                $msgType = 'danger';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO rooms (id, ruang) VALUES (?, ?)");
                    $stmt->execute([$id, $ruang]);
                    $message = 'Ruangan berhasil ditambahkan.';
                    $msgType = 'success';
                } catch (Exception $e) {
                    $message = 'Gagal menambah ruangan. Pastikan ID unik. Error: ' . $e->getMessage();
                    $msgType = 'danger';
                }
            }
        }
        
        // Update Ruangan
        elseif ($action === 'update') {
            $old_id = trim($_POST['old_id'] ?? '');
            $id = trim($_POST['id'] ?? '');
            $ruang = trim($_POST['ruang'] ?? '');

            if (empty($old_id) || empty($id) || empty($ruang)) {
                $message = 'ID dan Ruangan wajib diisi.';
                $msgType = 'danger';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE rooms SET id = ?, ruang = ? WHERE id = ?");
                    $stmt->execute([$id, $ruang, $old_id]);
                    $message = 'Ruangan berhasil diperbarui.';
                    $msgType = 'success';
                } catch (Exception $e) {
                    $message = 'Gagal memperbarui ruangan. Error: ' . $e->getMessage();
                    $msgType = 'danger';
                }
            }
        }
        
        // Hapus Ruangan
        elseif ($action === 'delete') {
            $id = trim($_POST['id'] ?? '');
            if (!empty($id)) {
                $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
                $stmt->execute([$id]);
                $message = 'Ruangan berhasil dihapus.';
                $msgType = 'success';
            }
        }
        
        // Import Excel
        elseif ($action === 'import') {
            if (isset($_FILES['file_excel']) && $_FILES['file_excel']['error'] === UPLOAD_ERR_OK) {
                $fileInfo = pathinfo($_FILES['file_excel']['name']);
                if (strtolower($fileInfo['extension']) === 'xlsx') {
                    if (class_exists('SimpleXLSX') && $xlsx = SimpleXLSX::parse($_FILES['file_excel']['tmp_name'])) {
                        $rows = $xlsx->rows();
                        $berhasil = 0;
                        $gagal = 0;
                        
                        // Melewati baris pertama jika dianggap sebagai header
                        $isHeader = true;
                        
                        foreach ($rows as $r) {
                            if ($isHeader) {
                                // Jika sel A1 = 'id' dan B1 = 'ruang' abaikan
                                if (strtolower(trim($r[0])) == 'id' || strtolower(trim($r[1])) == 'ruang') {
                                    $isHeader = false;
                                    continue;
                                }
                                $isHeader = false;
                            }
                            
                            $id = trim($r[0]);
                            $ruang = trim($r[1] ?? '');
                            
                            if (!empty($id) && !empty($ruang)) {
                                try {
                                    $stmt = $pdo->prepare("INSERT INTO rooms (id, ruang) VALUES (?, ?) ON DUPLICATE KEY UPDATE ruang = ?");
                                    $stmt->execute([$id, $ruang, $ruang]);
                                    $berhasil++;
                                } catch (Exception $e) {
                                    $gagal++;
                                }
                            }
                        }
                        $message = "Import selesai. Berhasil: $berhasil baris. Gagal: $gagal baris.";
                        $msgType = 'success';
                    } else {
                        $message = 'Gagal membaca file Excel. ' . (class_exists('SimpleXLSX') ? SimpleXLSX::parseError() : 'Library SimpleXLSX tidak ditemukan.');
                        $msgType = 'danger';
                    }
                } else {
                    $message = 'Format file tidak didukung. Harap upload file .xlsx';
                    $msgType = 'danger';
                }
            } else {
                $message = 'Gagal mengupload file.';
                $msgType = 'danger';
            }
        }
    }
}

$rooms = $pdo->query("SELECT * FROM rooms ORDER BY ruang ASC")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px;">

    <!-- Form Tambah Manual -->
    <div class="card" style="flex: 1; min-width: 300px;">
        <div class="card-header">➕ Tambah Ruangan Manual</div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>ID Ruangan</label>
                    <input type="text" name="id" placeholder="Contoh: R301" required>
                </div>
                <div class="form-group">
                    <label>Nama Ruang</label>
                    <input type="text" name="ruang" placeholder="Contoh: Gedung A Lt.3 - R.301" required>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:10px;">💾 Simpan Ruangan</button>
            </form>
        </div>
    </div>

    <!-- Form Import Excel -->
    <div class="card" style="flex: 1; min-width: 300px;">
        <div class="card-header">📤 Import dari Excel</div>
        <div class="card-body">
            <p style="font-size:14px; color:#6b7280; margin-bottom:15px;">
                Upload file berformat <strong>.xlsx</strong>. File harus memiliki dua kolom:<br>
                Kolom A: <strong>ID</strong> (misal: R101)<br>
                Kolom B: <strong>Ruang</strong> (misal: Ruang Kelas 101)<br>
                <small>*Baris pertama yang berisi teks "id" dan "ruang" akan diabaikan.</small>
            </p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="import">
                <div class="form-group">
                    <label>File Excel (.xlsx)</label>
                    <input type="file" name="file_excel" accept=".xlsx" required
                        style="padding:8px 12px; border:1px solid #d1d5db; border-radius:8px; width:100%;">
                </div>
                <button type="submit" class="btn btn-success" style="margin-top:10px;">📑 Import Excel</button>
            </form>
        </div>
    </div>

</div>

<!-- Daftar Ruangan -->
<div class="card">
    <div class="card-header">📋 Daftar Ruangan (<?= count($rooms) ?>)</div>
    <div class="card-body">
        <?php if (empty($rooms)): ?>
            <div class="empty-state">
                <div class="icon">🏢</div>
                <h3>Belum ada data ruangan</h3>
                <p>Tambahkan secara manual atau import melalui file Excel.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Ruangan</th>
                        <th style="width:150px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rooms as $r): ?>
                    <tr>
                        <td><strong><?= sanitize($r['id']) ?></strong></td>
                        <td><?= sanitize($r['ruang']) ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-warning btn-edit-ruangan"
                                data-id="<?= htmlspecialchars($r['id'], ENT_QUOTES) ?>"
                                data-ruang="<?= htmlspecialchars($r['ruang'], ENT_QUOTES) ?>">✏️ Edit</button>
                            
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($r['id'], ENT_QUOTES) ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    data-confirm="Hapus ruangan ini?">🗑️ Hapus</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Edit Ruangan -->
<div class="modal-backdrop" id="editRuanganModal">
    <div class="modal-content" style="max-width:500px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h3 style="margin:0;">✏️ Edit Ruangan</h3>
            <button type="button" class="btn-close-modal" onclick="closeRuanganModal()"
                style="background:none;border:none;font-size:22px;cursor:pointer;color:#9ca3af;line-height:1;padding:0;">&times;</button>
        </div>

        <form method="POST" id="editRuanganForm" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="old_id" id="edit_old_id">

            <div class="form-group">
                <label>ID Ruangan *</label>
                <input type="text" name="id" id="edit_id" required>
            </div>
            <div class="form-group">
                <label>Nama Ruang *</label>
                <input type="text" name="ruang" id="edit_ruang" required>
            </div>
            <button type="submit" class="btn btn-primary">💾 Simpan Perubahan</button>
        </form>
    </div>
</div>

<script>
function closeRuanganModal() {
    var m = document.getElementById('editRuanganModal');
    if (m) m.classList.remove('show');
}

document.addEventListener('click', function(e) {
    var btn = e.target.closest('.btn-edit-ruangan');
    if (btn) {
        document.getElementById('edit_old_id').value = btn.dataset.id;
        document.getElementById('edit_id').value = btn.dataset.id;
        document.getElementById('edit_ruang').value = btn.dataset.ruang;
        document.getElementById('editRuanganModal').classList.add('show');
    }
    
    if (e.target && e.target.id === 'editRuanganModal') {
        closeRuanganModal();
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
