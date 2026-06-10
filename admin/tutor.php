<?php
/**
 * LPPAI Corner - Admin: Kelola Tutor
 */
define('PAGE_TITLE', 'Kelola Tutor');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

// Require PhpSpreadsheet dari direktori vendor
require_once __DIR__ . '/../../../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$pdo = getDBConnection();
$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        // Tambah Tutor Manual
        if ($action === 'create') {
            $id = trim($_POST['id'] ?? '');
            $nama = trim($_POST['nama'] ?? '');

            if (empty($id) || empty($nama)) {
                $message = 'ID dan Nama Tutor wajib diisi.';
                $msgType = 'danger';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO tutors (id, nama) VALUES (?, ?)");
                    $stmt->execute([$id, $nama]);
                    $message = 'Tutor berhasil ditambahkan.';
                    $msgType = 'success';
                } catch (Exception $e) {
                    $message = 'Gagal menambah tutor. Pastikan ID unik. Error: ' . $e->getMessage();
                    $msgType = 'danger';
                }
            }
        }
        
        // Update Tutor
        elseif ($action === 'update') {
            $old_id = trim($_POST['old_id'] ?? '');
            $id = trim($_POST['id'] ?? '');
            $nama = trim($_POST['nama'] ?? '');

            if (empty($old_id) || empty($id) || empty($nama)) {
                $message = 'ID dan Nama Tutor wajib diisi.';
                $msgType = 'danger';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE tutors SET id = ?, nama = ? WHERE id = ?");
                    $stmt->execute([$id, $nama, $old_id]);
                    $message = 'Tutor berhasil diperbarui.';
                    $msgType = 'success';
                } catch (Exception $e) {
                    $message = 'Gagal memperbarui tutor. Error: ' . $e->getMessage();
                    $msgType = 'danger';
                }
            }
        }
        
        // Hapus Tutor
        elseif ($action === 'delete') {
            $id = trim($_POST['id'] ?? '');
            if (!empty($id)) {
                $stmt = $pdo->prepare("DELETE FROM tutors WHERE id = ?");
                $stmt->execute([$id]);
                $message = 'Tutor berhasil dihapus.';
                $msgType = 'success';
            }
        }
        
        // Import Excel
        elseif ($action === 'import') {
            if (isset($_FILES['file_excel']) && $_FILES['file_excel']['error'] === UPLOAD_ERR_OK) {
                $fileInfo = pathinfo($_FILES['file_excel']['name']);
                if (strtolower($fileInfo['extension']) === 'xlsx') {
                    try {
                        $spreadsheet = IOFactory::load($_FILES['file_excel']['tmp_name']);
                        $worksheet = $spreadsheet->getActiveSheet();
                        $rows = $worksheet->toArray();
                        
                        $berhasil = 0;
                        $gagal = 0;
                        $isHeader = true;
                        
                        foreach ($rows as $r) {
                            if ($isHeader) {
                                // Jika sel A1 = 'id' dan B1 = 'nama' abaikan
                                if (strtolower(trim($r[0] ?? '')) == 'id' || strtolower(trim($r[1] ?? '')) == 'nama') {
                                    $isHeader = false;
                                    continue;
                                }
                                $isHeader = false;
                            }
                            
                            $id = trim($r[0] ?? '');
                            $nama = trim($r[1] ?? '');
                            
                            if (!empty($id) && !empty($nama)) {
                                try {
                                    $stmt = $pdo->prepare("INSERT INTO tutors (id, nama) VALUES (?, ?) ON DUPLICATE KEY UPDATE nama = ?");
                                    $stmt->execute([$id, $nama, $nama]);
                                    $berhasil++;
                                } catch (Exception $e) {
                                    $gagal++;
                                }
                            }
                        }
                        $message = "Import selesai. Berhasil: $berhasil baris. Gagal: $gagal baris.";
                        $msgType = 'success';
                    } catch (Exception $e) {
                        $message = 'Gagal membaca file Excel. ' . $e->getMessage();
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

$tutors = $pdo->query("SELECT * FROM tutors ORDER BY nama ASC")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px;">

    <!-- Form Tambah Manual -->
    <div class="card" style="flex: 1; min-width: 300px;">
        <div class="card-header">➕ Tambah Tutor Manual</div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>ID / NIP Tutor</label>
                    <input type="text" name="id" placeholder="Contoh: 123456" required>
                </div>
                <div class="form-group">
                    <label>Nama Tutor</label>
                    <input type="text" name="nama" placeholder="Contoh: Dr. Ahmad" required>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:10px;">💾 Simpan Tutor</button>
            </form>
        </div>
    </div>

    <!-- Form Import Excel -->
    <div class="card" style="flex: 1; min-width: 300px;">
        <div class="card-header">📤 Import dari Excel</div>
        <div class="card-body">
            <p style="font-size:14px; color:#6b7280; margin-bottom:15px;">
                Upload file berformat <strong>.xlsx</strong>. File harus memiliki dua kolom:<br>
                Kolom A: <strong>ID</strong> (misal: 101)<br>
                Kolom B: <strong>Nama</strong> (misal: Ustadz Ali)<br>
                <small>*Baris pertama yang berisi teks "id" dan "nama" akan diabaikan.</small>
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

<!-- Daftar Tutor -->
<div class="card">
    <div class="card-header">📋 Daftar Tutor (<?= count($tutors) ?>)</div>
    <div class="card-body">
        <?php if (empty($tutors)): ?>
            <div class="empty-state">
                <div class="icon">👨‍🏫</div>
                <h3>Belum ada data tutor</h3>
                <p>Tambahkan secara manual atau import melalui file Excel.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID / NIP</th>
                        <th>Nama Tutor</th>
                        <th style="width:150px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tutors as $t): ?>
                    <tr>
                        <td><strong><?= sanitize($t['id']) ?></strong></td>
                        <td><?= sanitize($t['nama']) ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-warning btn-edit-tutor"
                                data-id="<?= htmlspecialchars($t['id'], ENT_QUOTES) ?>"
                                data-nama="<?= htmlspecialchars($t['nama'], ENT_QUOTES) ?>">✏️ Edit</button>
                            
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($t['id'], ENT_QUOTES) ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    data-confirm="Hapus tutor ini?">🗑️ Hapus</button>
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

<!-- Modal Edit Tutor -->
<div class="modal-backdrop" id="editTutorModal">
    <div class="modal-content" style="max-width:500px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h3 style="margin:0;">✏️ Edit Tutor</h3>
            <button type="button" class="btn-close-modal" onclick="closeTutorModal()"
                style="background:none;border:none;font-size:22px;cursor:pointer;color:#9ca3af;line-height:1;padding:0;">&times;</button>
        </div>

        <form method="POST" id="editTutorForm" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="old_id" id="edit_old_id">

            <div class="form-group">
                <label>ID / NIP Tutor *</label>
                <input type="text" name="id" id="edit_id" required>
            </div>
            <div class="form-group">
                <label>Nama Tutor *</label>
                <input type="text" name="nama" id="edit_nama" required>
            </div>
            <button type="submit" class="btn btn-primary">💾 Simpan Perubahan</button>
        </form>
    </div>
</div>

<script>
function closeTutorModal() {
    var m = document.getElementById('editTutorModal');
    if (m) m.classList.remove('show');
}

document.addEventListener('click', function(e) {
    var btn = e.target.closest('.btn-edit-tutor');
    if (btn) {
        document.getElementById('edit_old_id').value = btn.dataset.id;
        document.getElementById('edit_id').value = btn.dataset.id;
        document.getElementById('edit_nama').value = btn.dataset.nama;
        document.getElementById('editTutorModal').classList.add('show');
    }
    
    if (e.target && e.target.id === 'editTutorModal') {
        closeTutorModal();
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
