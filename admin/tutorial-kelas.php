<?php
/**
 * LPPAI Corner - Admin: Kelola Kelas Tutorial
 */
define('PAGE_TITLE', 'Kelola Kelas Tutorial');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

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

        if ($action === 'create') {
            $namaKelas  = trim($_POST['nama_kelas'] ?? '');
            $mataKuliah = trim($_POST['mata_kuliah'] ?? '');
            $dosen      = trim($_POST['dosen_pengampu'] ?? '');
            $hari       = trim($_POST['hari'] ?? '');
            $jam        = trim($_POST['jam'] ?? '');
            $ruangan    = trim($_POST['ruangan'] ?? '');
            $gelombang  = $_POST['gelombang'] ?? '';
            $semester   = trim($_POST['semester'] ?? '');
            $kuota      = (int)($_POST['kuota'] ?? 0);

            if (empty($namaKelas) || empty($mataKuliah) || !in_array($gelombang, ['gel1','gel2','mandiri'])) {
                $message = 'Nama kelas, mata kuliah, dan gelombang harus diisi.';
                $msgType = 'danger';
            } else {
                $stmt = $pdo->prepare("INSERT INTO tutorial_classes (nama_kelas, mata_kuliah, dosen_pengampu, hari, jam, ruangan, gelombang, semester, kuota) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$namaKelas, $mataKuliah, $dosen, $hari, $jam, $ruangan, $gelombang, $semester, $kuota]);
                $message = 'Kelas tutorial berhasil ditambahkan!';
                $msgType = 'success';
            }

        } elseif ($action === 'update') {
            $id         = (int)($_POST['id'] ?? 0);
            $namaKelas  = trim($_POST['nama_kelas'] ?? '');
            $mataKuliah = trim($_POST['mata_kuliah'] ?? '');
            $dosen      = trim($_POST['dosen_pengampu'] ?? '');
            $hari       = trim($_POST['hari'] ?? '');
            $jam        = trim($_POST['jam'] ?? '');
            $ruangan    = trim($_POST['ruangan'] ?? '');
            $gelombang  = $_POST['gelombang'] ?? '';
            $semester   = trim($_POST['semester'] ?? '');
            $kuota      = (int)($_POST['kuota'] ?? 0);

            if ($id <= 0 || empty($namaKelas) || empty($mataKuliah) || !in_array($gelombang, ['gel1','gel2','mandiri'])) {
                $message = 'Data tidak valid. Nama kelas, mata kuliah, dan gelombang harus diisi.';
                $msgType = 'danger';
            } else {
                $pdo->prepare("UPDATE tutorial_classes SET nama_kelas=?, mata_kuliah=?, dosen_pengampu=?, hari=?, jam=?, ruangan=?, gelombang=?, semester=?, kuota=? WHERE id=?")
                    ->execute([$namaKelas, $mataKuliah, $dosen, $hari, $jam, $ruangan, $gelombang, $semester, $kuota, $id]);
                $message = 'Kelas tutorial berhasil diperbarui!';
                $msgType = 'success';
            }

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM tutorial_classes WHERE id = ?")->execute([$id]);
            $message = 'Kelas berhasil dihapus.';
            $msgType = 'success';
        }
    }
}

$classes   = $pdo->query("SELECT * FROM tutorial_classes ORDER BY gelombang, nama_kelas")->fetchAll();
$gelLabels = ['gel1' => 'Gelombang 1 (Ganjil)', 'gel2' => 'Gelombang 2 (Genap)', 'mandiri' => 'Mandiri'];

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<!-- ===================================================
     CARD: TAMBAH KELAS
     =================================================== -->
<div class="card">
    <div class="card-header">➕ Tambah Kelas Tutorial</div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
                <div class="form-group">
                    <label>Nama Kelas *</label>
                    <input type="text" name="nama_kelas" placeholder="Kelas A" required>
                </div>
                <div class="form-group">
                    <label>Mata Kuliah *</label>
                    <input type="text" name="mata_kuliah" placeholder="Bahasa Arab Dasar" required>
                </div>
                <div class="form-group">
                    <label>Dosen Pengampu</label>
                    <input type="text" name="dosen_pengampu" placeholder="Dr. Ahmad">
                </div>
                <div class="form-group">
                    <label>Gelombang *</label>
                    <select name="gelombang" style="width:100%;padding:12px;border:2px solid #e0e0e0;border-radius:10px;" required>
                        <option value="">-- Pilih --</option>
                        <option value="gel1">Gelombang 1 (Ganjil)</option>
                        <option value="gel2">Gelombang 2 (Genap)</option>
                        <option value="mandiri">Mandiri</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Hari</label>
                    <input type="text" name="hari" placeholder="Senin">
                </div>
                <div class="form-group">
                    <label>Jam</label>
                    <input type="text" name="jam" placeholder="08:00-09:30">
                </div>
                <div class="form-group">
                    <label>Ruangan</label>
                    <input type="text" name="ruangan" placeholder="Ruang 101">
                </div>
                <div class="form-group">
                    <label>Semester</label>
                    <input type="text" name="semester" placeholder="2025/2026-Ganjil">
                </div>
                <div class="form-group">
                    <label>Kuota</label>
                    <input type="number" name="kuota" min="0" placeholder="30">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:auto;margin-top:10px;">🏫 Tambah Kelas</button>
        </form>
    </div>
</div>

<!-- ===================================================
     CARD: DAFTAR KELAS
     =================================================== -->
<div class="card">
    <div class="card-header">📋 Daftar Kelas Tutorial (<?= count($classes) ?>)</div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Kelas</th>
                        <th>Mata Kuliah</th>
                        <th>Dosen</th>
                        <th>Gelombang</th>
                        <th>Jadwal</th>
                        <th>Ruangan</th>
                        <th>Kuota</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes as $c): ?>
                    <tr>
                        <td><strong><?= sanitize($c['nama_kelas']) ?></strong></td>
                        <td><?= sanitize($c['mata_kuliah']) ?></td>
                        <td><?= sanitize($c['dosen_pengampu'] ?? '-') ?></td>
                        <td><span class="badge badge-primary"><?= $gelLabels[$c['gelombang']] ?? $c['gelombang'] ?></span></td>
                        <td><?= sanitize(($c['hari'] ?? '-') . ', ' . ($c['jam'] ?? '-')) ?></td>
                        <td><?= sanitize($c['ruangan'] ?? '-') ?></td>
                        <td><?= $c['kuota'] ?></td>
                        <td style="white-space:nowrap;">
                            <!-- Tombol Edit -->
                            <button type="button" class="btn btn-sm btn-secondary"
                                onclick="openEditModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)"
                                style="margin-right:4px;">✏️ Edit</button>
                            <!-- Tombol Hapus -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    data-confirm="Hapus kelas ini?"
                                    data-table="tutorial_classes"
                                    data-id="<?= $c['id'] ?>">🗑️ Hapus</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ===================================================
     MODAL: EDIT KELAS
     =================================================== -->
<div id="editModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);
    backdrop-filter:blur(3px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;width:min(760px,96vw);max-height:90vh;
        overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);animation:modalIn .2s ease;">
        <!-- Header modal -->
        <div style="display:flex;align-items:center;justify-content:space-between;
            padding:20px 24px;border-bottom:1px solid #e5e7eb;">
            <h3 style="margin:0;font-size:18px;font-weight:700;color:#111827;">✏️ Edit Kelas Tutorial</h3>
            <button onclick="closeEditModal()"
                style="background:none;border:none;font-size:22px;cursor:pointer;color:#6b7280;line-height:1;">×</button>
        </div>
        <!-- Body modal -->
        <div style="padding:24px;">
            <form method="POST" id="editForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
                    <div class="form-group">
                        <label>Nama Kelas *</label>
                        <input type="text" name="nama_kelas" id="edit_nama_kelas" required
                            placeholder="Kelas A">
                    </div>
                    <div class="form-group">
                        <label>Mata Kuliah *</label>
                        <input type="text" name="mata_kuliah" id="edit_mata_kuliah" required
                            placeholder="Bahasa Arab Dasar">
                    </div>
                    <div class="form-group">
                        <label>Dosen Pengampu</label>
                        <input type="text" name="dosen_pengampu" id="edit_dosen_pengampu"
                            placeholder="Dr. Ahmad">
                    </div>
                    <div class="form-group">
                        <label>Gelombang *</label>
                        <select name="gelombang" id="edit_gelombang" required
                            style="width:100%;padding:12px;border:2px solid #e0e0e0;border-radius:10px;">
                            <option value="">-- Pilih --</option>
                            <option value="gel1">Gelombang 1 (Ganjil)</option>
                            <option value="gel2">Gelombang 2 (Genap)</option>
                            <option value="mandiri">Mandiri</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Hari</label>
                        <input type="text" name="hari" id="edit_hari" placeholder="Senin">
                    </div>
                    <div class="form-group">
                        <label>Jam</label>
                        <input type="text" name="jam" id="edit_jam" placeholder="08:00-09:30">
                    </div>
                    <div class="form-group">
                        <label>Ruangan</label>
                        <input type="text" name="ruangan" id="edit_ruangan" placeholder="Ruang 101">
                    </div>
                    <div class="form-group">
                        <label>Semester</label>
                        <input type="text" name="semester" id="edit_semester"
                            placeholder="2025/2026-Ganjil">
                    </div>
                    <div class="form-group">
                        <label>Kuota</label>
                        <input type="number" name="kuota" id="edit_kuota" min="0" placeholder="30">
                    </div>
                </div>

                <div style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary" style="width:auto;">
                        💾 Simpan Perubahan
                    </button>
                    <button type="button" class="btn btn-secondary" style="width:auto;"
                        onclick="closeEditModal()">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
@keyframes modalIn {
    from { opacity: 0; transform: translateY(-16px) scale(.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
</style>

<script>
function openEditModal(data) {
    document.getElementById('edit_id').value            = data.id;
    document.getElementById('edit_nama_kelas').value    = data.nama_kelas   || '';
    document.getElementById('edit_mata_kuliah').value   = data.mata_kuliah  || '';
    document.getElementById('edit_dosen_pengampu').value= data.dosen_pengampu || '';
    document.getElementById('edit_gelombang').value     = data.gelombang    || '';
    document.getElementById('edit_hari').value          = data.hari         || '';
    document.getElementById('edit_jam').value           = data.jam          || '';
    document.getElementById('edit_ruangan').value       = data.ruangan      || '';
    document.getElementById('edit_semester').value      = data.semester     || '';
    document.getElementById('edit_kuota').value         = data.kuota        || 0;

    var modal = document.getElementById('editModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    document.body.style.overflow = '';
}

// Tutup modal saat klik di luar area modal
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

// Tutup modal dengan tombol Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEditModal();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
