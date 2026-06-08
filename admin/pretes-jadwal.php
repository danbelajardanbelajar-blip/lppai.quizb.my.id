<?php
/**
 * LPPAI Corner - Admin: Kelola Jadwal Pretes
 */
define('PAGE_TITLE', 'Kelola Jadwal Pretes');
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
            $periode      = trim($_POST['periode'] ?? '');
            $tanggal      = $_POST['tanggal'] ?? '';
            $waktuMulai   = $_POST['waktu_mulai'] ?? '';
            $waktuSelesai = $_POST['waktu_selesai'] ?? '';
            $ruangan      = trim($_POST['ruangan'] ?? '');
            $kuota        = (int)($_POST['kuota'] ?? 0);

            if (empty($periode) || empty($tanggal) || empty($waktuMulai) || empty($waktuSelesai) || empty($ruangan) || $kuota <= 0) {
                $message = 'Semua field harus diisi.';
                $msgType = 'danger';
            } else {
                $stmt = $pdo->prepare("INSERT INTO pretes_schedules (periode, tanggal, waktu_mulai, waktu_selesai, ruangan, kuota) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$periode, $tanggal, $waktuMulai, $waktuSelesai, $ruangan, $kuota]);
                $message = 'Jadwal pretes berhasil ditambahkan!';
                $msgType = 'success';
            }

        } elseif ($action === 'update') {
            $id           = (int)($_POST['id'] ?? 0);
            $periode      = trim($_POST['periode'] ?? '');
            $tanggal      = $_POST['tanggal'] ?? '';
            $waktuMulai   = $_POST['waktu_mulai'] ?? '';
            $waktuSelesai = $_POST['waktu_selesai'] ?? '';
            $ruangan      = trim($_POST['ruangan'] ?? '');
            $kuota        = (int)($_POST['kuota'] ?? 0);

            if ($id <= 0 || empty($periode) || empty($tanggal) || empty($waktuMulai) || empty($waktuSelesai) || empty($ruangan) || $kuota <= 0) {
                $message = 'Semua field harus diisi dengan benar.';
                $msgType = 'danger';
            } else {
                $stmt = $pdo->prepare("UPDATE pretes_schedules SET periode=?, tanggal=?, waktu_mulai=?, waktu_selesai=?, ruangan=?, kuota=? WHERE id=?");
                $stmt->execute([$periode, $tanggal, $waktuMulai, $waktuSelesai, $ruangan, $kuota, $id]);
                $message = 'Jadwal pretes berhasil diperbarui!';
                $msgType = 'success';
            }

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM pretes_schedules WHERE id = ?")->execute([$id]);
            $message = 'Jadwal berhasil dihapus.';
            $msgType = 'success';

        } elseif ($action === 'update_status') {
            $id     = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if (in_array($status, ['aktif', 'selesai', 'dibatalkan'])) {
                $pdo->prepare("UPDATE pretes_schedules SET status = ? WHERE id = ?")->execute([$status, $id]);
                $message = 'Status jadwal diperbarui.';
                $msgType = 'success';
            }
        }
    }
}

$schedules = $pdo->query("SELECT * FROM pretes_schedules ORDER BY tanggal DESC, waktu_mulai")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">➕ Tambah Jadwal Pretes</div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
                <div class="form-group">
                    <label>Periode</label>
                    <input type="text" name="periode" placeholder="2025/2026-Ganjil" required>
                </div>
                <div class="form-group">
                    <label>Tanggal</label>
                    <input type="date" name="tanggal" required>
                </div>
                <div class="form-group">
                    <label>Waktu Mulai</label>
                    <input type="time" name="waktu_mulai" required>
                </div>
                <div class="form-group">
                    <label>Waktu Selesai</label>
                    <input type="time" name="waktu_selesai" required>
                </div>
                <div class="form-group">
                    <label>Ruangan</label>
                    <input type="text" name="ruangan" placeholder="Gedung A - Ruang 101" required>
                </div>
                <div class="form-group">
                    <label>Kuota</label>
                    <input type="number" name="kuota" min="1" placeholder="50" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:auto;margin-top:10px;">📅 Tambah Jadwal</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">📋 Daftar Jadwal Pretes</div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Ruangan</th>
                        <th>Kuota</th>
                        <th>Terisi</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $s): ?>
                    <tr>
                        <td><?= sanitize($s['periode']) ?></td>
                        <td><?= date('d M Y', strtotime($s['tanggal'])) ?></td>
                        <td><?= date('H:i', strtotime($s['waktu_mulai'])) ?> - <?= date('H:i', strtotime($s['waktu_selesai'])) ?></td>
                        <td><?= sanitize($s['ruangan']) ?></td>
                        <td><?= $s['kuota'] ?></td>
                        <td><?= $s['terisi'] ?></td>
                        <td>
                            <?php
                            $badges = ['aktif' => 'badge-success', 'selesai' => 'badge-info', 'dibatalkan' => 'badge-danger'];
                            ?>
                            <span class="badge <?= $badges[$s['status']] ?? 'badge-info' ?>"><?= ucfirst($s['status']) ?></span>
                        </td>
                        <td style="display:flex;gap:4px;flex-wrap:wrap;">
                            <!-- Tombol Edit — data-* dipakai agar tidak ada kutip ganda di atribut HTML -->
                            <button type="button" class="btn btn-sm btn-warning btn-edit-jadwal"
                                data-id="<?= $s['id'] ?>"
                                data-periode="<?= htmlspecialchars($s['periode'], ENT_QUOTES, 'UTF-8') ?>"
                                data-tanggal="<?= htmlspecialchars($s['tanggal'], ENT_QUOTES, 'UTF-8') ?>"
                                data-waktu-mulai="<?= htmlspecialchars($s['waktu_mulai'], ENT_QUOTES, 'UTF-8') ?>"
                                data-waktu-selesai="<?= htmlspecialchars($s['waktu_selesai'], ENT_QUOTES, 'UTF-8') ?>"
                                data-ruangan="<?= htmlspecialchars($s['ruangan'], ENT_QUOTES, 'UTF-8') ?>"
                                data-kuota="<?= (int)$s['kuota'] ?>">✏️ Edit</button>

                            <?php foreach (['aktif', 'selesai', 'dibatalkan'] as $st): ?>
                                <?php if ($s['status'] !== $st): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $st ?>">
                                    <button type="submit" class="btn btn-sm btn-secondary"><?= ucfirst($st) ?></button>
                                </form>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" data-confirm="Hapus jadwal ini?" data-table="pretes_schedules" data-id="<?= $s['id'] ?>">🗑️ Hapus</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ====== MODAL EDIT JADWAL ====== -->
<div class="modal-backdrop" id="editModal">
    <div class="modal-content" style="max-width:600px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h3 style="margin:0;">✏️ Edit Jadwal Pretes</h3>
            <button type="button" onclick="closeEditModal()"
                style="background:none;border:none;font-size:22px;cursor:pointer;color:#9ca3af;line-height:1;padding:0;"
                aria-label="Tutup">&times;</button>
        </div>

        <form method="POST" id="editForm" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
                <div class="form-group">
                    <label>Periode</label>
                    <input type="text" name="periode" id="edit_periode" placeholder="2025/2026-Ganjil" required>
                </div>
                <div class="form-group">
                    <label>Tanggal</label>
                    <input type="date" name="tanggal" id="edit_tanggal" required>
                </div>
                <div class="form-group">
                    <label>Waktu Mulai</label>
                    <input type="time" name="waktu_mulai" id="edit_waktu_mulai" required>
                </div>
                <div class="form-group">
                    <label>Waktu Selesai</label>
                    <input type="time" name="waktu_selesai" id="edit_waktu_selesai" required>
                </div>
                <div class="form-group">
                    <label>Ruangan</label>
                    <input type="text" name="ruangan" id="edit_ruangan" placeholder="Gedung A - Ruang 101" required>
                </div>
                <div class="form-group">
                    <label>Kuota</label>
                    <input type="number" name="kuota" id="edit_kuota" min="1" required>
                </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                <button type="button" class="btn btn-secondary" style="width:auto;" onclick="closeEditModal()">Batal</button>
                <button type="submit" class="btn btn-primary" style="width:auto;">💾 Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
// Fungsi helper — didefinisikan sekali, aman dipanggil berulang oleh SPA
function openEditModal(id, periode, tanggal, waktuMulai, waktuSelesai, ruangan, kuota) {
    document.getElementById('edit_id').value            = id;
    document.getElementById('edit_periode').value       = periode;
    document.getElementById('edit_tanggal').value       = tanggal;
    // Waktu dari DB bisa "HH:MM:SS" — potong ke "HH:MM" agar cocok input[type=time]
    document.getElementById('edit_waktu_mulai').value   = (waktuMulai   || '').slice(0, 5);
    document.getElementById('edit_waktu_selesai').value = (waktuSelesai || '').slice(0, 5);
    document.getElementById('edit_ruangan').value       = ruangan;
    document.getElementById('edit_kuota').value         = kuota;
    document.getElementById('editModal').classList.add('show');
}

function closeEditModal() {
    var m = document.getElementById('editModal');
    if (m) m.classList.remove('show');
}

// Delegasi klik tombol Edit — baca data dari data-* attributes
// Guard flag mencegah event listener menumpuk saat SPA swap halaman
if (!window._editJadwalBound) {
    window._editJadwalBound = true;

    document.addEventListener('click', function(e) {
        // Tombol Edit
        var btn = e.target.closest('.btn-edit-jadwal');
        if (btn) {
            var d = btn.dataset;
            openEditModal(
                d.id,
                d.periode,
                d.tanggal,
                d.waktuMulai,   // dataset otomatis camelCase dari data-waktu-mulai
                d.waktuSelesai,
                d.ruangan,
                d.kuota
            );
            return;
        }

        // Klik backdrop modal untuk menutup
        if (e.target && e.target.id === 'editModal') closeEditModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeEditModal();
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
