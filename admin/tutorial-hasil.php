<?php
/**
 * LPPAI Corner - Admin: Kelola Hasil Tutorial
 */
define('PAGE_TITLE', 'Kelola Hasil Tutorial');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
$message = '';
$msgType = '';

/* =============================================================
   POST HANDLERS
   ============================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        /* ---- INPUT / UPDATE HASIL ---- */
        if ($action === 'save_hasil') {
            $regId      = (int)($_POST['reg_id'] ?? 0);
            $status     = $_POST['status'] ?? '';
            $nilaiRaw   = $_POST['nilai_akhir'];
            $nilai      = ($nilaiRaw !== '' && $nilaiRaw !== null) ? (float)$nilaiRaw : null;
            $keterangan = trim($_POST['keterangan'] ?? '');

            $validStatus = ['terdaftar', 'aktif', 'lulus', 'tidak_lulus', 'mengundurkan_diri'];
            if ($regId <= 0 || !in_array($status, $validStatus)) {
                $message = 'Data tidak valid.';
                $msgType = 'danger';
            } else {
                $pdo->prepare("UPDATE tutorial_registrations SET status = ?, nilai_akhir = ?, keterangan = ? WHERE id = ?")
                    ->execute([$status, $nilai, $keterangan ?: null, $regId]);
                $message = 'Hasil tutorial berhasil disimpan!';
                $msgType = 'success';
            }

        /* ---- HAPUS HASIL (reset ke terdaftar) ---- */
        } elseif ($action === 'reset_hasil') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            if ($regId > 0) {
                $pdo->prepare("UPDATE tutorial_registrations SET status = 'terdaftar', nilai_akhir = NULL, keterangan = NULL WHERE id = ?")
                    ->execute([$regId]);
                $message = 'Hasil tutorial berhasil direset.';
                $msgType = 'success';
            }

        /* ---- HAPUS REGISTRASI ---- */
        } elseif ($action === 'delete') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            if ($regId > 0) {
                $pdo->prepare("DELETE FROM tutorial_registrations WHERE id = ?")->execute([$regId]);
                $message = 'Data registrasi berhasil dihapus.';
                $msgType = 'success';
            }
        }
    }
}

/* =============================================================
   DATA
   ============================================================= */
$gelLabels = [
    'gel1'    => 'Gelombang 1 (Ganjil)',
    'gel2'    => 'Gelombang 2 (Genap)',
    'mandiri' => 'Mandiri',
];

$statusLabels = [
    'terdaftar'          => 'Terdaftar',
    'aktif'              => 'Aktif',
    'lulus'              => 'Lulus',
    'tidak_lulus'        => 'Tidak Lulus',
    'mengundurkan_diri'  => 'Mengundurkan Diri',
];

$statusBadges = [
    'terdaftar'         => 'badge-info',
    'aktif'             => 'badge-primary',
    'lulus'             => 'badge-success',
    'tidak_lulus'       => 'badge-danger',
    'mengundurkan_diri' => 'badge-warning',
];

// Semua registrasi beserta info mahasiswa & kelas
$registrations = $pdo->query("
    SELECT
        tr.id,
        tr.user_id,
        tr.tutorial_class_id,
        tr.status,
        tr.nilai_akhir,
        tr.keterangan,
        tr.created_at,
        u.nama_lengkap,
        u.nim,
        u.program_studi,
        tc.nama_kelas,
        tc.mata_kuliah,
        tc.dosen_pengampu,
        tc.gelombang,
        tc.semester
    FROM tutorial_registrations tr
    JOIN users u  ON tr.user_id = u.id
    JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
    ORDER BY tc.gelombang, tc.nama_kelas, u.nama_lengkap
")->fetchAll();

// Statistik ringkas
$stats = [
    'total'         => count($registrations),
    'lulus'         => count(array_filter($registrations, fn($r) => $r['status'] === 'lulus')),
    'tidak_lulus'   => count(array_filter($registrations, fn($r) => $r['status'] === 'tidak_lulus')),
    'belum_dinilai' => count(array_filter($registrations, fn($r) => $r['nilai_akhir'] === null)),
];

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<!-- ===== STAT CARDS ===== -->
<div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon blue">📋</div>
        <div class="stat-info">
            <h3><?= $stats['total'] ?></h3>
            <p>Total Peserta</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">🎓</div>
        <div class="stat-info">
            <h3><?= $stats['lulus'] ?></h3>
            <p>Lulus</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red">❌</div>
        <div class="stat-info">
            <h3><?= $stats['tidak_lulus'] ?></h3>
            <p>Tidak Lulus</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">⏳</div>
        <div class="stat-info">
            <h3><?= $stats['belum_dinilai'] ?></h3>
            <p>Belum Dinilai</p>
        </div>
    </div>
</div>

<!-- ===== TABEL HASIL ===== -->
<div class="card">
    <div class="card-header">
        🎓 Daftar Hasil Tutorial (<?= $stats['total'] ?> peserta)
    </div>
    <div class="card-body">
        <?php if (empty($registrations)): ?>
            <div class="empty-state">
                <div class="icon">📋</div>
                <h3>Belum ada data peserta tutorial</h3>
                <p>Tambahkan peserta melalui halaman <strong>Data Peserta Tutorial</strong> terlebih dahulu.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama Mahasiswa</th>
                        <th>NIM</th>
                        <th>Prodi</th>
                        <th>Kelas</th>
                        <th>Gel.</th>
                        <th>Nilai</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= sanitize($r['nama_lengkap']) ?></strong></td>
                        <td><?= sanitize($r['nim']) ?></td>
                        <td><?= sanitize($r['program_studi'] ?? '-') ?></td>
                        <td>
                            <span style="font-weight:600;"><?= sanitize($r['nama_kelas']) ?></span><br>
                            <small style="color:#6b7280;"><?= sanitize($r['mata_kuliah']) ?></small>
                        </td>
                        <td>
                            <span class="badge badge-primary"><?= $gelLabels[$r['gelombang']] ?? $r['gelombang'] ?></span>
                        </td>
                        <td>
                            <?php if ($r['nilai_akhir'] !== null): ?>
                                <strong style="font-size:15px;color:<?= $r['nilai_akhir'] >= 70 ? '#15803d' : '#b91c1c' ?>;">
                                    <?= number_format($r['nilai_akhir'], 1) ?>
                                </strong>
                            <?php else: ?>
                                <span style="color:#9ca3af;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $statusBadges[$r['status']] ?? 'badge-info' ?>">
                                <?= $statusLabels[$r['status']] ?? ucfirst($r['status']) ?>
                            </span>
                        </td>
                        <td style="display:flex;gap:4px;flex-wrap:wrap;">
                            <!-- Tombol Edit Hasil -->
                            <button type="button" class="btn btn-sm btn-warning btn-edit-hasil"
                                data-id="<?= $r['id'] ?>"
                                data-nama="<?= htmlspecialchars($r['nama_lengkap'], ENT_QUOTES, 'UTF-8') ?>"
                                data-kelas="<?= htmlspecialchars($r['nama_kelas'] . ' — ' . $r['mata_kuliah'], ENT_QUOTES, 'UTF-8') ?>"
                                data-status="<?= htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8') ?>"
                                data-nilai="<?= $r['nilai_akhir'] !== null ? (float)$r['nilai_akhir'] : '' ?>"
                                data-keterangan="<?= htmlspecialchars($r['keterangan'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                ✏️ Edit
                            </button>

                            <!-- Reset hasil -->
                            <?php if ($r['nilai_akhir'] !== null || $r['status'] !== 'terdaftar'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="reset_hasil">
                                <input type="hidden" name="reg_id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-secondary"
                                    data-confirm="Reset hasil tutorial mahasiswa ini ke status Terdaftar?">
                                    🔄 Reset
                                </button>
                            </form>
                            <?php endif; ?>

                            <!-- Hapus registrasi -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="reg_id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    data-confirm="Hapus registrasi mahasiswa ini dari kelas tutorial?"
                                    data-table="tutorial_registrations"
                                    data-id="<?= $r['id'] ?>">
                                    🗑️ Hapus
                                </button>
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

<!-- ===== MODAL EDIT HASIL ===== -->
<div class="modal-backdrop" id="editHasilModal">
    <div class="modal-content" style="max-width:520px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h3 style="margin:0;">✏️ Edit Hasil Tutorial</h3>
            <button type="button" onclick="closeHasilModal()"
                style="background:none;border:none;font-size:22px;cursor:pointer;color:#9ca3af;line-height:1;padding:0;"
                aria-label="Tutup">&times;</button>
        </div>

        <!-- Info mahasiswa (read-only) -->
        <div style="background:#f8fafc;border-radius:10px;padding:14px 16px;margin-bottom:20px;border:1px solid #e5e7eb;">
            <div style="font-size:12px;color:#6b7280;margin-bottom:2px;">Mahasiswa</div>
            <div id="modal-nama" style="font-weight:700;font-size:15px;color:#111827;"></div>
            <div id="modal-kelas" style="font-size:13px;color:#6b7280;margin-top:2px;"></div>
        </div>

        <form method="POST" id="editHasilForm" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_hasil">
            <input type="hidden" name="reg_id" id="modal_reg_id">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="form-group">
                    <label>Status Kelulusan *</label>
                    <select name="status" id="modal_status"
                        style="width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:inherit;" required>
                        <option value="terdaftar">Terdaftar</option>
                        <option value="aktif">Aktif</option>
                        <option value="lulus">Lulus</option>
                        <option value="tidak_lulus">Tidak Lulus</option>
                        <option value="mengundurkan_diri">Mengundurkan Diri</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nilai Akhir <span style="color:#9ca3af;font-weight:400;">(0–100)</span></label>
                    <input type="number" name="nilai_akhir" id="modal_nilai"
                        step="0.1" min="0" max="100" placeholder="Kosongkan jika belum ada">
                </div>
            </div>

            <div class="form-group">
                <label>Keterangan</label>
                <textarea name="keterangan" id="modal_keterangan" rows="3"
                    placeholder="Catatan tambahan (opsional)..."></textarea>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px;">
                <button type="button" class="btn btn-secondary" style="width:auto;" onclick="closeHasilModal()">Batal</button>
                <button type="submit" class="btn btn-primary" style="width:auto;">💾 Simpan Hasil</button>
            </div>
        </form>
    </div>
</div>

<script>
function openHasilModal(id, nama, kelas, status, nilai, keterangan) {
    document.getElementById('modal_reg_id').value      = id;
    document.getElementById('modal-nama').textContent  = nama;
    document.getElementById('modal-kelas').textContent = kelas;
    document.getElementById('modal_status').value      = status;
    document.getElementById('modal_nilai').value       = nilai;
    document.getElementById('modal_keterangan').value  = keterangan;
    document.getElementById('editHasilModal').classList.add('show');
}

function closeHasilModal() {
    var m = document.getElementById('editHasilModal');
    if (m) m.classList.remove('show');
}

if (!window._editHasilBound) {
    window._editHasilBound = true;

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-edit-hasil');
        if (btn) {
            var d = btn.dataset;
            openHasilModal(d.id, d.nama, d.kelas, d.status, d.nilai, d.keterangan);
            return;
        }
        if (e.target && e.target.id === 'editHasilModal') closeHasilModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeHasilModal();
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
