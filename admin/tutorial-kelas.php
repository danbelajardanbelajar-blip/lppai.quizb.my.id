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
                $pdo->prepare("INSERT INTO tutorial_classes (nama_kelas, mata_kuliah, dosen_pengampu, hari, jam, ruangan, gelombang, semester, kuota) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$namaKelas, $mataKuliah, $dosen, $hari, $jam, $ruangan, $gelombang, $semester, $kuota]);
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
$roomsList = $pdo->query("SELECT id, ruang FROM rooms ORDER BY ruang ASC")->fetchAll();
$tutorsList = $pdo->query("SELECT id, nama FROM tutors ORDER BY nama ASC")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<!-- ── Tambah Kelas ──────────────────────────────────────────── -->
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
                    <select name="dosen_pengampu">
                        <option value="">-- Pilih Dosen --</option>
                        <?php foreach ($tutorsList as $t): ?>
                        <option value="<?= sanitize($t['nama']) ?>"><?= sanitize($t['nama']) ?></option>
                        <?php endforeach; ?>
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
                <div class="form-group">
                    <label>Hari</label>
                    <select name="hari">
                        <option value="">-- Pilih --</option>
                        <option value="Senin">Senin</option>
                        <option value="Selasa">Selasa</option>
                        <option value="Rabu">Rabu</option>
                        <option value="Kamis">Kamis</option>
                        <option value="Jumat">Jumat</option>
                        <option value="Sabtu">Sabtu</option>
                        <option value="Ahad">Ahad</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Jam</label>
                    <input type="text" name="jam" placeholder="08:00-09:30">
                </div>
                <div class="form-group">
                    <label>Ruangan</label>
                    <select name="ruangan">
                        <option value="">-- Pilih Ruangan --</option>
                        <?php foreach ($roomsList as $rm): ?>
                        <option value="<?= sanitize($rm['ruang']) ?>"><?= sanitize($rm['ruang']) ?></option>
                        <?php endforeach; ?>
                    </select>
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

<!-- ── Daftar Kelas ──────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">📋 Daftar Kelas Tutorial (<?= count($classes) ?>)</div>
    <div class="card-body">
        <?php if (empty($classes)): ?>
            <div class="empty-state">
                <div class="icon">🏫</div>
                <h3>Belum ada kelas tutorial</h3>
                <p>Tambahkan kelas melalui form di atas.</p>
            </div>
        <?php else: ?>
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
                        <td><?= (int)$c['kuota'] ?></td>
                        <td style="white-space:nowrap;">
                            <!-- Tombol Edit — data-* attributes, aman dari XSS -->
                            <button type="button" class="btn btn-sm btn-warning btn-edit-kelas"
                                data-id="<?= (int)$c['id'] ?>"
                                data-nama-kelas="<?= htmlspecialchars($c['nama_kelas'], ENT_QUOTES, 'UTF-8') ?>"
                                data-mata-kuliah="<?= htmlspecialchars($c['mata_kuliah'], ENT_QUOTES, 'UTF-8') ?>"
                                data-dosen="<?= htmlspecialchars($c['dosen_pengampu'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-gelombang="<?= htmlspecialchars($c['gelombang'], ENT_QUOTES, 'UTF-8') ?>"
                                data-hari="<?= htmlspecialchars($c['hari'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-jam="<?= htmlspecialchars($c['jam'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-ruangan="<?= htmlspecialchars($c['ruangan'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-semester="<?= htmlspecialchars($c['semester'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-kuota="<?= (int)$c['kuota'] ?>"
                                style="margin-right:4px;">
                                ✏️ Edit
                            </button>
                            <!-- Tombol Hapus -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    data-confirm="Hapus kelas ini? Semua registrasi mahasiswa di kelas ini juga akan terhapus."
                                    data-table="tutorial_classes"
                                    data-id="<?= $c['id'] ?>">🗑️ Hapus</button>
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

<!-- ── Modal Edit Kelas ──────────────────────────────────────── -->
<div class="modal-backdrop" id="editKelasModal">
    <div class="modal-content" style="max-width:760px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h3 style="margin:0;">✏️ Edit Kelas Tutorial</h3>
            <button type="button" class="btn-close-modal"
                style="background:none;border:none;font-size:22px;cursor:pointer;color:#9ca3af;line-height:1;padding:0;"
                aria-label="Tutup">&times;</button>
        </div>

        <form method="POST" id="editKelasForm" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
                <div class="form-group">
                    <label>Nama Kelas *</label>
                    <input type="text" name="nama_kelas" id="edit_nama_kelas" placeholder="Kelas A" required>
                </div>
                <div class="form-group">
                    <label>Mata Kuliah *</label>
                    <input type="text" name="mata_kuliah" id="edit_mata_kuliah" placeholder="Bahasa Arab Dasar" required>
                </div>
                <div class="form-group">
                    <label>Dosen Pengampu</label>
                    <select name="dosen_pengampu" id="edit_dosen">
                        <option value="">-- Pilih Dosen --</option>
                        <?php foreach ($tutorsList as $t): ?>
                        <option value="<?= sanitize($t['nama']) ?>"><?= sanitize($t['nama']) ?></option>
                        <?php endforeach; ?>
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
                <div class="form-group">
                    <label>Hari</label>
                    <select name="hari" id="edit_hari">
                        <option value="">-- Pilih --</option>
                        <option value="Senin">Senin</option>
                        <option value="Selasa">Selasa</option>
                        <option value="Rabu">Rabu</option>
                        <option value="Kamis">Kamis</option>
                        <option value="Jumat">Jumat</option>
                        <option value="Sabtu">Sabtu</option>
                        <option value="Ahad">Ahad</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Jam</label>
                    <input type="text" name="jam" id="edit_jam" placeholder="08:00-09:30">
                </div>
                <div class="form-group">
                    <label>Ruangan</label>
                    <select name="ruangan" id="edit_ruangan">
                        <option value="">-- Pilih Ruangan --</option>
                        <?php foreach ($roomsList as $rm): ?>
                        <option value="<?= sanitize($rm['ruang']) ?>"><?= sanitize($rm['ruang']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Semester</label>
                    <input type="text" name="semester" id="edit_semester" placeholder="2025/2026-Ganjil">
                </div>
                <div class="form-group">
                    <label>Kuota</label>
                    <input type="number" name="kuota" id="edit_kuota" min="0" placeholder="30">
                </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn btn-secondary btn-close-modal" style="width:auto;">Batal</button>
                <button type="submit" class="btn btn-primary" style="width:auto;">💾 Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openKelasModal(d) {
    document.getElementById('edit_id').value          = d.id;
    document.getElementById('edit_nama_kelas').value  = d.namaKelas  || '';
    document.getElementById('edit_mata_kuliah').value = d.mataKuliah || '';
    document.getElementById('edit_dosen').value       = d.dosen      || '';
    document.getElementById('edit_gelombang').value   = d.gelombang  || '';
    document.getElementById('edit_hari').value        = d.hari       || '';
    document.getElementById('edit_jam').value         = d.jam        || '';
    document.getElementById('edit_ruangan').value     = d.ruangan    || '';
    document.getElementById('edit_semester').value    = d.semester   || '';
    document.getElementById('edit_kuota').value       = d.kuota      || 0;
    document.getElementById('editKelasModal').classList.add('show');
}

function closeKelasModal() {
    var m = document.getElementById('editKelasModal');
    if (m) m.classList.remove('show');
}

// Event delegation — baca dari data-* attributes (aman dari XSS, tidak ada JSON di onclick)
if (!window._editKelasBound) {
    window._editKelasBound = true;

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-edit-kelas');
        if (btn) {
            var d = btn.dataset;
            openKelasModal({
                id:         d.id,
                namaKelas:  d.namaKelas,
                mataKuliah: d.mataKuliah,
                dosen:      d.dosen,
                gelombang:  d.gelombang,
                hari:       d.hari,
                jam:        d.jam,
                ruangan:    d.ruangan,
                semester:   d.semester,
                kuota:      d.kuota,
            });
            return;
        }

        // Klik tombol Batal atau (X)
        if (e.target.closest('.btn-close-modal')) {
            closeKelasModal();
            return;
        }

        // Klik backdrop untuk tutup
        if (e.target && e.target.id === 'editKelasModal') closeKelasModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeKelasModal();
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
