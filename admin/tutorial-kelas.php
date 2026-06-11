<?php
/**
 * LPPAI Corner - Admin: Kelola Gelombang
 */
define('PAGE_TITLE', 'Kelola Gelombang Pendaftaran');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
// Ensure master table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS master_gelombang (
    id INT AUTO_INCREMENT PRIMARY KEY,
    semester VARCHAR(50) NOT NULL,
    tahun_ajaran VARCHAR(50) NOT NULL,
    gelombang ENUM('gel1','gel2','mandiri') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM master_gelombang WHERE id = ?")->execute([$id]);
            $message = 'Gelombang berhasil dihapus.';
            $msgType = 'success';
        } elseif ($action === 'create_gelombang') {
            $semester = $_POST['semester_tipe'] ?? '';
            $tahun_ajaran = $_POST['tahun_ajaran'] ?? '';
            $gelombang = $_POST['gelombang'] ?? '';

            if (empty($semester) || empty($tahun_ajaran) || empty($gelombang)) {
                $message = 'Semua field gelombang harus diisi.';
                $msgType = 'danger';
            } else {
                $pdo->prepare("INSERT INTO master_gelombang (semester, tahun_ajaran, gelombang) VALUES (?, ?, ?)")
                    ->execute([$semester, $tahun_ajaran, $gelombang]);
                $message = 'Data Gelombang berhasil ditambahkan!';
                $msgType = 'success';
            }
        } elseif ($action === 'edit_gelombang') {
            $id = (int)($_POST['id'] ?? 0);
            $semester = $_POST['semester_tipe'] ?? '';
            $tahun_ajaran = $_POST['tahun_ajaran'] ?? '';
            $gelombang = $_POST['gelombang'] ?? '';

            if ($id <= 0 || empty($semester) || empty($tahun_ajaran) || empty($gelombang)) {
                $message = 'Data tidak valid.';
                $msgType = 'danger';
            } else {
                $pdo->prepare("UPDATE master_gelombang SET semester=?, tahun_ajaran=?, gelombang=? WHERE id=?")
                    ->execute([$semester, $tahun_ajaran, $gelombang, $id]);
                $message = 'Gelombang berhasil diperbarui!';
                $msgType = 'success';
            }
        }
    }
}

$gelombangData = $pdo->query("SELECT * FROM master_gelombang ORDER BY created_at DESC")->fetchAll();
$gelLabels = ['gel1' => 'Gelombang 1 (Ganjil)', 'gel2' => 'Gelombang 2 (Genap)', 'mandiri' => 'Mandiri'];

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<!-- ── Tambah Gelombang ─────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">📅 Tambah Gelombang Pendaftaran</div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create_gelombang">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
                <div class="form-group">
                    <label>Semester *</label>
                    <select name="semester_tipe" required>
                        <option value="">-- Pilih --</option>
                        <option value="Ganjil">Ganjil</option>
                        <option value="Genap">Genap</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tahun Ajaran *</label>
                    <select name="tahun_ajaran" required>
                        <option value="">-- Pilih Tahun --</option>
                        <?php for($y=2017; $y<=2049; $y++): ?>
                        <option value="<?= $y . '/' . ($y+1) ?>"><?= $y . ' - ' . ($y+1) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Gelombang *</label>
                    <select name="gelombang" required>
                        <option value="">-- Pilih --</option>
                        <option value="gel1">Gelombang 1 (Ganjil)</option>
                        <option value="gel2">Gelombang 2 (Genap)</option>
                        <option value="mandiri">Mandiri</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:auto;margin-top:10px;">➕ Tambah Gelombang</button>
        </form>
    </div>
</div>

<!-- ── Daftar Gelombang ──────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">📋 Daftar Gelombang Pendaftaran (<?= count($gelombangData) ?>)</div>
    <div class="card-body">
        <?php if (empty($gelombangData)): ?>
            <div class="empty-state">
                <div class="icon">🏫</div>
                <h3>Belum ada gelombang pendaftaran</h3>
                <p>Tambahkan gelombang melalui form di atas.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Tahun Ajaran</th>
                        <th>Semester</th>
                        <th>Gelombang</th>
                        <th>Ditambahkan Pada</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gelombangData as $g): ?>
                    <tr>
                        <td><strong><?= sanitize($g['tahun_ajaran']) ?></strong></td>
                        <td><?= sanitize($g['semester']) ?></td>
                        <td><span class="badge badge-primary"><?= $gelLabels[$g['gelombang']] ?? $g['gelombang'] ?></span></td>
                        <td><?= date('d M Y H:i', strtotime($g['created_at'])) ?></td>
                        <td style="white-space:nowrap;">
                            <!-- Tombol Edit -->
                            <button type="button" class="btn btn-sm btn-warning btn-edit-gelombang"
                                data-id="<?= $g['id'] ?>"
                                data-semester="<?= htmlspecialchars($g['semester'], ENT_QUOTES, 'UTF-8') ?>"
                                data-tahun_ajaran="<?= htmlspecialchars($g['tahun_ajaran'], ENT_QUOTES, 'UTF-8') ?>"
                                data-gelombang="<?= htmlspecialchars($g['gelombang'], ENT_QUOTES, 'UTF-8') ?>"
                                style="margin-right:4px;">
                                ✏️ Edit
                            </button>
                            <!-- Tombol Hapus -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    data-confirm="Hapus gelombang pendaftaran ini?"
                                    data-table="master_gelombang"
                                    data-id="<?= $g['id'] ?>">🗑️ Hapus</button>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>

<!-- ── Modal Edit Gelombang ──────────────────────────────────────── -->
<div class="modal-backdrop" id="editGelombangModal">
    <div class="modal-content" style="max-width:500px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h3 style="margin:0;">✏️ Edit Gelombang Pendaftaran</h3>
            <button type="button" class="btn-close-modal"
                style="background:none;border:none;font-size:22px;cursor:pointer;color:#9ca3af;line-height:1;padding:0;"
                aria-label="Tutup">&times;</button>
        </div>

        <form method="POST" id="editGelombangForm" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="edit_gelombang">
            <input type="hidden" name="id" id="edit_id">

            <div class="form-group">
                <label>Semester *</label>
                <select name="semester_tipe" id="edit_semester_tipe" required>
                    <option value="">-- Pilih --</option>
                    <option value="Ganjil">Ganjil</option>
                    <option value="Genap">Genap</option>
                </select>
            </div>
            <div class="form-group">
                <label>Tahun Ajaran *</label>
                <select name="tahun_ajaran" id="edit_tahun_ajaran" required>
                    <option value="">-- Pilih Tahun --</option>
                    <?php for($y=2017; $y<=2049; $y++): ?>
                    <option value="<?= $y . '/' . ($y+1) ?>"><?= $y . ' - ' . ($y+1) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Gelombang *</label>
                <select name="gelombang" id="edit_gelombang" required>
                    <option value="">-- Pilih --</option>
                    <option value="gel1">Gelombang 1 (Ganjil)</option>
                    <option value="gel2">Gelombang 2 (Genap)</option>
                    <option value="mandiri">Mandiri</option>
                </select>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn btn-secondary btn-close-modal" style="width:auto;">Batal</button>
                <button type="submit" class="btn btn-primary" style="width:auto;">💾 Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openGelombangModal(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('edit_semester_tipe').value = d.semester || '';
    document.getElementById('edit_tahun_ajaran').value = d.tahun_ajaran || '';
    document.getElementById('edit_gelombang').value = d.gelombang || '';
    document.getElementById('editGelombangModal').classList.add('show');
}

function closeGelombangModal() {
    var m = document.getElementById('editGelombangModal');
    if (m) m.classList.remove('show');
}

if (!window._editGelombangBound) {
    window._editGelombangBound = true;

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-edit-gelombang');
        if (btn) {
            var d = btn.dataset;
            openGelombangModal({
                id: d.id,
                semester: d.semester,
                tahun_ajaran: d.tahun_ajaran,
                gelombang: d.gelombang
            });
            return;
        }

        if (e.target.closest('.btn-close-modal')) {
            closeGelombangModal();
            return;
        }

        if (e.target && e.target.id === 'editGelombangModal') {
            closeGelombangModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeGelombangModal();
    });
}
</script>
