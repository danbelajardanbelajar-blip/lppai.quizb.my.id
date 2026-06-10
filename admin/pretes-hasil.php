<?php
/**
 * LPPAI Corner - Admin: Kelola Hasil Pretes
 */
define('PAGE_TITLE', 'Kelola Hasil Pretes');
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

        if ($action === 'add_result') {
            $userId     = (int)($_POST['user_id'] ?? 0);
            $scheduleId = (int)($_POST['schedule_id'] ?? 0);
            $nilai      = $_POST['nilai'] !== '' ? (float)$_POST['nilai'] : null;
            $status     = $_POST['status_lulus'] ?? 'belum_diumumkan';
            $keterangan = trim($_POST['keterangan'] ?? '');

            if ($userId <= 0) {
                $message = 'Pilih mahasiswa.';
                $msgType = 'danger';
            } else {
                $check = $pdo->prepare("SELECT id FROM pretes_results WHERE user_id = ?");
                $check->execute([$userId]);
                if ($check->fetch()) {
                    $pdo->prepare("UPDATE pretes_results SET nilai = ?, status_lulus = ?, keterangan = ?, pretes_schedule_id = ? WHERE user_id = ?")
                        ->execute([$nilai, $status, $keterangan, $scheduleId ?: null, $userId]);
                    $message = 'Hasil pretes berhasil diperbarui!';
                } else {
                    $pdo->prepare("INSERT INTO pretes_results (user_id, pretes_schedule_id, nilai, status_lulus, keterangan) VALUES (?,?,?,?,?)")
                        ->execute([$userId, $scheduleId ?: null, $nilai, $status, $keterangan]);
                    $message = 'Hasil pretes berhasil ditambahkan!';
                }
                $msgType = 'success';
            }

        } elseif ($action === 'update') {
            $id         = (int)($_POST['id'] ?? 0);
            $scheduleId = (int)($_POST['schedule_id'] ?? 0);
            $nilai      = $_POST['nilai'] !== '' ? (float)$_POST['nilai'] : null;
            $status     = $_POST['status_lulus'] ?? 'belum_diumumkan';
            $keterangan = trim($_POST['keterangan'] ?? '');

            if ($id <= 0) {
                $message = 'ID tidak valid.';
                $msgType = 'danger';
            } else {
                $pdo->prepare("UPDATE pretes_results SET nilai = ?, status_lulus = ?, keterangan = ?, pretes_schedule_id = ? WHERE id = ?")
                    ->execute([$nilai, $status, $keterangan, $scheduleId ?: null, $id]);
                $message = 'Hasil pretes berhasil diperbarui!';
                $msgType = 'success';
            }

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM pretes_results WHERE id = ?")->execute([$id]);
            $message = 'Hasil pretes berhasil dihapus.';
            $msgType = 'success';
        }
    }
}

$students  = $pdo->query("
    SELECT DISTINCT u.id, u.nama_lengkap, u.nim 
    FROM users u
    JOIN pretes_registrations pr ON u.id = pr.user_id
    WHERE u.role = 'mahasiswa' 
    ORDER BY u.nama_lengkap
")->fetchAll();
$schedules = $pdo->query("SELECT * FROM pretes_schedules ORDER BY tanggal DESC")->fetchAll();

$results = $pdo->query("
    SELECT pr.*, u.nama_lengkap, u.nim, u.program_studi, ps.periode, ps.tanggal as tgl_pretes
    FROM pretes_results pr
    JOIN users u ON pr.user_id = u.id
    LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
    ORDER BY pr.created_at DESC
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<!-- ── Input / Update Hasil ─────────────────────────────────── -->
<div class="card">
    <div class="card-header">➕ Input/Update Hasil Pretes</div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="add_result">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
                <div class="form-group">
                    <label>Mahasiswa *</label>
                    <select name="user_id" required>
                        <option value="">-- Pilih Mahasiswa --</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= sanitize($s['nama_lengkap']) ?> (<?= sanitize($s['nim']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Jadwal Pretes</label>
                    <select name="schedule_id">
                        <option value="">-- Pilih Jadwal --</option>
                        <?php foreach ($schedules as $sc): ?>
                            <option value="<?= $sc['id'] ?>"><?= sanitize($sc['periode']) ?> - <?= date('d M Y', strtotime($sc['tanggal'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nilai</label>
                    <input type="number" name="nilai" step="0.01" min="0" max="100" placeholder="85.50">
                </div>
                <div class="form-group">
                    <label>Status *</label>
                    <select name="status_lulus" required>
                        <option value="belum_diumumkan">Belum Diumumkan</option>
                        <option value="lulus">Lulus</option>
                        <option value="tidak_lulus">Tidak Lulus</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>Keterangan</label>
                    <input type="text" name="keterangan" placeholder="Keterangan tambahan">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:auto;margin-top:10px;">📝 Simpan Hasil</button>
        </form>
    </div>
</div>

<!-- ── Daftar Hasil ─────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">📋 Daftar Hasil Pretes (<?= count($results) ?>)</div>
    <div class="card-body">
        <?php if (empty($results)): ?>
            <div class="empty-state">
                <div class="icon">📋</div>
                <h3>Belum ada data hasil pretes</h3>
                <p>Tambahkan hasil melalui form di atas.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>NIM</th>
                        <th>Prodi</th>
                        <th>Periode</th>
                        <th>Nilai</th>
                        <th>Status</th>
                        <th>Keterangan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $statusBadge = [
                        'lulus'            => 'badge-success',
                        'tidak_lulus'      => 'badge-danger',
                        'belum_diumumkan'  => 'badge-warning',
                    ];
                    foreach ($results as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= sanitize($r['nama_lengkap']) ?></strong></td>
                        <td><?= sanitize($r['nim']) ?></td>
                        <td><?= sanitize($r['program_studi'] ?? '-') ?></td>
                        <td><?= sanitize($r['periode'] ?? '-') ?></td>
                        <td><strong><?= $r['nilai'] !== null ? number_format($r['nilai'], 1) : '-' ?></strong></td>
                        <td>
                            <span class="badge <?= $statusBadge[$r['status_lulus']] ?? 'badge-info' ?>">
                                <?= ucfirst(str_replace('_', ' ', $r['status_lulus'])) ?>
                            </span>
                        </td>
                        <td><?= sanitize($r['keterangan'] ?? '-') ?></td>
                        <td style="white-space:nowrap;">
                            <!-- Tombol Edit -->
                            <button type="button" class="btn btn-sm btn-warning btn-edit-result"
                                data-id="<?= $r['id'] ?>"
                                data-nama="<?= htmlspecialchars($r['nama_lengkap'], ENT_QUOTES, 'UTF-8') ?>"
                                data-nim="<?= htmlspecialchars($r['nim'], ENT_QUOTES, 'UTF-8') ?>"
                                data-schedule-id="<?= (int)($r['pretes_schedule_id'] ?? 0) ?>"
                                data-nilai="<?= $r['nilai'] !== null ? (float)$r['nilai'] : '' ?>"
                                data-status="<?= htmlspecialchars($r['status_lulus'], ENT_QUOTES, 'UTF-8') ?>"
                                data-keterangan="<?= htmlspecialchars($r['keterangan'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                style="margin-right:4px;">
                                ✏️ Edit
                            </button>
                            <!-- Hapus -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    data-confirm="Hapus hasil pretes ini?"
                                    data-table="pretes_results"
                                    data-id="<?= $r['id'] ?>">🗑️ Hapus</button>
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

<!-- ── Modal Edit Hasil Pretes ──────────────────────────────── -->
<div class="modal-backdrop" id="editResultModal">
    <div class="modal-content" style="max-width:560px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h3 style="margin:0;">✏️ Edit Hasil Pretes</h3>
            <button type="button" onclick="closeResultModal()"
                style="background:none;border:none;font-size:22px;cursor:pointer;color:#9ca3af;line-height:1;padding:0;"
                aria-label="Tutup">&times;</button>
        </div>

        <!-- Info mahasiswa (read-only) -->
        <div style="background:#f8fafc;border-radius:10px;padding:14px 16px;margin-bottom:20px;border:1px solid #e5e7eb;">
            <div style="font-size:12px;color:#6b7280;margin-bottom:2px;">Mahasiswa</div>
            <div id="modal_result_nama" style="font-weight:700;font-size:15px;color:#111827;"></div>
            <div id="modal_result_nim" style="font-size:13px;color:#6b7280;margin-top:2px;"></div>
        </div>

        <form method="POST" id="editResultForm" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="modal_result_id">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="form-group">
                    <label>Jadwal Pretes</label>
                    <select name="schedule_id" id="modal_result_schedule">
                        <option value="">-- Pilih Jadwal --</option>
                        <?php foreach ($schedules as $sc): ?>
                            <option value="<?= $sc['id'] ?>">
                                <?= sanitize($sc['periode']) ?> — <?= date('d M Y', strtotime($sc['tanggal'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nilai <span style="color:#9ca3af;font-weight:400;">(0–100)</span></label>
                    <input type="number" name="nilai" id="modal_result_nilai"
                        step="0.01" min="0" max="100" placeholder="Kosongkan jika belum ada">
                </div>
            </div>

            <div class="form-group">
                <label>Status Kelulusan *</label>
                <select name="status_lulus" id="modal_result_status" required>
                    <option value="belum_diumumkan">Belum Diumumkan</option>
                    <option value="lulus">Lulus</option>
                    <option value="tidak_lulus">Tidak Lulus</option>
                </select>
            </div>

            <div class="form-group">
                <label>Keterangan</label>
                <input type="text" name="keterangan" id="modal_result_keterangan"
                    placeholder="Keterangan tambahan (opsional)">
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                <button type="button" class="btn btn-secondary" style="width:auto;"
                    onclick="closeResultModal()">Batal</button>
                <button type="submit" class="btn btn-primary" style="width:auto;">💾 Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openResultModal(id, nama, nim, scheduleId, nilai, status, keterangan) {
    document.getElementById('modal_result_id').value       = id;
    document.getElementById('modal_result_nama').textContent = nama;
    document.getElementById('modal_result_nim').textContent  = nim;
    document.getElementById('modal_result_nilai').value    = nilai;
    document.getElementById('modal_result_status').value   = status;
    document.getElementById('modal_result_keterangan').value = keterangan;
    // Set jadwal
    var sel = document.getElementById('modal_result_schedule');
    sel.value = scheduleId || '';
    document.getElementById('editResultModal').classList.add('show');
}

function closeResultModal() {
    var m = document.getElementById('editResultModal');
    if (m) m.classList.remove('show');
}

if (!window._editResultBound) {
    window._editResultBound = true;

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-edit-result');
        if (btn) {
            var d = btn.dataset;
            openResultModal(d.id, d.nama, d.nim, d.scheduleId, d.nilai, d.status, d.keterangan);
            return;
        }
        if (e.target && e.target.id === 'editResultModal') closeResultModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeResultModal();
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
