<?php
/**
 * LPPAI Corner - Admin: Kelola Pengumuman
 */
define('PAGE_TITLE', 'Kelola Pengumuman');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();

// Auto add link_tujuan column if not exists
try {
    $pdo->exec("ALTER TABLE announcements ADD COLUMN link_tujuan VARCHAR(255) DEFAULT NULL AFTER konten");
} catch (Exception $e) {}

$user = getCurrentUser();
$message = '';
$msgType = '';

$tipeOptions = [
    'pendaftaran_gel1'       => 'Pendaftaran Tutorial Gel. 1 (Ganjil)',
    'pembagian_kelas_gel1'   => 'Pembagian Kelas Gel. 1 (Ganjil)',
    'kelulusan_gel1'         => 'Kelulusan Gel. 1 (Ganjil)',
    'pendaftaran_gel2'       => 'Pendaftaran Tutorial Gel. 2 (Genap)',
    'pembagian_kelas_gel2'   => 'Pembagian Kelas Gel. 2 (Genap)',
    'kelulusan_gel2'         => 'Kelulusan Gel. 2 (Genap)',
    'pendaftaran_mandiri'    => 'Pendaftaran Tutorial Mandiri',
    'pembagian_kelas_mandiri'=> 'Pembagian Kelas Mandiri',
    'umum'                   => 'Pengumuman Umum',
];

$linkOptions = [
    '' => '-- Tidak Ada Link --',
    '/pages/pretes-daftar.php' => 'Pendaftaran Pretes',
    '/pages/tutorial-pendaftaran.php' => 'Pendaftaran Tutorial',
    '/pages/tutorial-pembagian.php' => 'Cek Pembagian Kelas',
    '/pages/tutorial-kelulusan.php' => 'Cek Kelulusan',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        if ($action === 'create') {
            $judul  = trim($_POST['judul'] ?? '');
            $konten = trim($_POST['konten'] ?? '');
            $tipe   = $_POST['tipe'] ?? '';
            $link   = $_POST['link_tujuan'] ?? '';
            if ($link === '') $link = null;

            if (empty($judul) || empty($konten) || !isset($tipeOptions[$tipe])) {
                $message = 'Semua field harus diisi dengan benar.';
                $msgType = 'danger';
            } else {
                $pdo->prepare("INSERT INTO announcements (judul, konten, tipe, link_tujuan, created_by) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$judul, $konten, $tipe, $link, $user['id']]);
                $message = 'Pengumuman berhasil ditambahkan!';
                $msgType = 'success';
            }

        } elseif ($action === 'update') {
            $id     = (int)($_POST['id'] ?? 0);
            $judul  = trim($_POST['judul'] ?? '');
            $konten = trim($_POST['konten'] ?? '');
            $tipe   = $_POST['tipe'] ?? '';
            $link   = $_POST['link_tujuan'] ?? '';
            if ($link === '') $link = null;

            if ($id <= 0 || empty($judul) || empty($konten) || !isset($tipeOptions[$tipe])) {
                $message = 'Data tidak valid. Semua field harus diisi.';
                $msgType = 'danger';
            } else {
                $pdo->prepare("UPDATE announcements SET judul = ?, konten = ?, tipe = ?, link_tujuan = ? WHERE id = ?")
                    ->execute([$judul, $konten, $tipe, $link, $id]);
                $message = 'Pengumuman berhasil diperbarui!';
                $msgType = 'success';
            }

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM announcements WHERE id = ?")->execute([$id]);
            $message = 'Pengumuman berhasil dihapus.';
            $msgType = 'success';

        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE announcements SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            $message = 'Status pengumuman diperbarui.';
            $msgType = 'success';
        }
    }
}

$announcements = $pdo->query("
    SELECT a.*, u.nama_lengkap as author
    FROM announcements a
    LEFT JOIN users u ON a.created_by = u.id
    ORDER BY a.created_at DESC
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<!-- ── Tambah Pengumuman ─────────────────────────────────────── -->
<div class="card">
    <div class="card-header">➕ Tambah Pengumuman Baru</div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-group">
                <label>Tipe Pengumuman</label>
                <select name="tipe" required>
                    <option value="">-- Pilih Tipe --</option>
                    <?php foreach ($tipeOptions as $val => $label): ?>
                        <option value="<?= $val ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Judul</label>
                <input type="text" name="judul" placeholder="Judul pengumuman" required>
            </div>
            <div class="form-group">
                <label>Link Aksi Mahasiswa (Opsional)</label>
                <select name="link_tujuan">
                    <?php foreach ($linkOptions as $val => $label): ?>
                        <option value="<?= $val ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color:#6b7280;">Tambahkan tombol link pendaftaran di pengumuman untuk mahasiswa.</small>
            </div>
            <div class="form-group">
                <label>Konten</label>
                <textarea name="konten" rows="5" placeholder="Isi pengumuman..." required></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width:auto;">📢 Simpan Pengumuman</button>
        </form>
    </div>
</div>

<!-- ── Daftar Pengumuman ─────────────────────────────────────── -->
<div class="card">
    <div class="card-header">📋 Daftar Pengumuman (<?= count($announcements) ?>)</div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Judul</th>
                        <th>Tipe</th>
                        <th>Status</th>
                        <th>Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($announcements as $a): ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($a['judul']) ?></strong>
                            <?php if (!empty($a['konten'])): ?>
                            <div style="font-size:12px;color:#6b7280;margin-top:2px;max-width:300px;
                                        white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                <?= sanitize(mb_substr(strip_tags($a['konten']), 0, 80)) ?>…
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-info"><?= $tipeOptions[$a['tipe']] ?? $a['tipe'] ?></span></td>
                        <td>
                            <?php if ($a['is_active']): ?>
                                <span class="badge badge-success">Aktif</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Nonaktif</span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('d M Y', strtotime($a['created_at'])) ?></td>
                        <td style="white-space:nowrap;">
                            <!-- Tombol Edit -->
                            <button type="button" class="btn btn-sm btn-warning btn-edit-ann"
                                data-id="<?= $a['id'] ?>"
                                data-judul="<?= htmlspecialchars($a['judul'], ENT_QUOTES, 'UTF-8') ?>"
                                data-konten="<?= htmlspecialchars($a['konten'], ENT_QUOTES, 'UTF-8') ?>"
                                data-tipe="<?= htmlspecialchars($a['tipe'], ENT_QUOTES, 'UTF-8') ?>"
                                data-link_tujuan="<?= htmlspecialchars($a['link_tujuan'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                style="margin-right:4px;">
                                ✏️ Edit
                            </button>
                            <!-- Toggle Aktif/Nonaktif -->
                            <form method="POST" style="display:inline;margin-right:4px;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-secondary">
                                    <?= $a['is_active'] ? '🔕 Nonaktifkan' : '🔔 Aktifkan' ?>
                                </button>
                            </form>
                            <!-- Hapus -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    data-confirm="Yakin ingin menghapus pengumuman ini?"
                                    data-table="announcements"
                                    data-id="<?= $a['id'] ?>">🗑️ Hapus</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Modal Edit Pengumuman ─────────────────────────────────── -->
<div class="modal-backdrop" id="editAnnModal">
    <div class="modal-content" style="max-width:600px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h3 style="margin:0;">✏️ Edit Pengumuman</h3>
            <button type="button" onclick="closeAnnModal()"
                style="background:none;border:none;font-size:22px;cursor:pointer;color:#9ca3af;line-height:1;padding:0;"
                aria-label="Tutup">&times;</button>
        </div>

        <form method="POST" id="editAnnForm" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_ann_id">

            <div class="form-group">
                <label>Tipe Pengumuman</label>
                <select name="tipe" id="edit_ann_tipe" required>
                    <option value="">-- Pilih Tipe --</option>
                    <?php foreach ($tipeOptions as $val => $label): ?>
                        <option value="<?= $val ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Judul</label>
                <input type="text" name="judul" id="edit_ann_judul" placeholder="Judul pengumuman" required>
            </div>
            <div class="form-group">
                <label>Link Aksi Mahasiswa (Opsional)</label>
                <select name="link_tujuan" id="edit_ann_link">
                    <?php foreach ($linkOptions as $val => $label): ?>
                        <option value="<?= $val ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Konten</label>
                <textarea name="konten" id="edit_ann_konten" rows="6"
                    placeholder="Isi pengumuman..." required></textarea>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                <button type="button" class="btn btn-secondary" style="width:auto;"
                    onclick="closeAnnModal()">Batal</button>
                <button type="submit" class="btn btn-primary" style="width:auto;">💾 Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAnnModal(id, judul, konten, tipe, link) {
    document.getElementById('edit_ann_id').value    = id;
    document.getElementById('edit_ann_judul').value = judul;
    document.getElementById('edit_ann_konten').value= konten;
    document.getElementById('edit_ann_tipe').value  = tipe;
    document.getElementById('edit_ann_link').value  = link || '';
    document.getElementById('editAnnModal').classList.add('show');
}

function closeAnnModal() {
    var m = document.getElementById('editAnnModal');
    if (m) m.classList.remove('show');
}

if (!window._editAnnBound) {
    window._editAnnBound = true;

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-edit-ann');
        if (btn) {
            var d = btn.dataset;
            openAnnModal(d.id, d.judul, d.konten, d.tipe, d.link_tujuan);
            return;
        }
        if (e.target && e.target.id === 'editAnnModal') closeAnnModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeAnnModal();
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
