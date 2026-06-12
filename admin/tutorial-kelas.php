<?php
/**
 * LPPAI Corner - Admin: Kelola Gelombang
 */
define('PAGE_TITLE', 'Kelola Gelombang Pendaftaran');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
$pdo->exec("CREATE TABLE IF NOT EXISTS master_gelombang (
    id INT AUTO_INCREMENT PRIMARY KEY,
    semester VARCHAR(50) NOT NULL,
    tahun_ajaran VARCHAR(50) NOT NULL,
    gelombang ENUM('gel1','gel2','mandiri') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
try {
    $pdo->exec("ALTER TABLE master_gelombang ADD COLUMN kuota_senin INT DEFAULT 0, ADD COLUMN kuota_selasa INT DEFAULT 0, ADD COLUMN kuota_rabu INT DEFAULT 0, ADD COLUMN kuota_kamis INT DEFAULT 0, ADD COLUMN kuota_jumat INT DEFAULT 0");
} catch (Exception $e) {}

try {
    $pdo->exec("ALTER TABLE master_gelombang ADD COLUMN tutors_senin VARCHAR(1000) DEFAULT NULL, ADD COLUMN tutors_selasa VARCHAR(1000) DEFAULT NULL, ADD COLUMN tutors_rabu VARCHAR(1000) DEFAULT NULL, ADD COLUMN tutors_kamis VARCHAR(1000) DEFAULT NULL, ADD COLUMN tutors_jumat VARCHAR(1000) DEFAULT NULL");
} catch (Exception $e) {}

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
            $ksenin = (int)($_POST['kuota_senin'] ?? 0);
            $kselasa = (int)($_POST['kuota_selasa'] ?? 0);
            $krabu = (int)($_POST['kuota_rabu'] ?? 0);
            $kkamis = (int)($_POST['kuota_kamis'] ?? 0);
            $kjumat = (int)($_POST['kuota_jumat'] ?? 0);
            $tsenin = implode(',', array_filter($_POST['tutors_senin'] ?? []));
            $tselasa = implode(',', array_filter($_POST['tutors_selasa'] ?? []));
            $trabu = implode(',', array_filter($_POST['tutors_rabu'] ?? []));
            $tkamis = implode(',', array_filter($_POST['tutors_kamis'] ?? []));
            $tjumat = implode(',', array_filter($_POST['tutors_jumat'] ?? []));

            if (empty($semester) || empty($tahun_ajaran) || empty($gelombang)) {
                $message = 'Semua field gelombang harus diisi.';
                $msgType = 'danger';
            } else {
                $pdo->prepare("INSERT INTO master_gelombang (semester, tahun_ajaran, gelombang, kuota_senin, kuota_selasa, kuota_rabu, kuota_kamis, kuota_jumat, tutors_senin, tutors_selasa, tutors_rabu, tutors_kamis, tutors_jumat) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$semester, $tahun_ajaran, $gelombang, $ksenin, $kselasa, $krabu, $kkamis, $kjumat, $tsenin, $tselasa, $trabu, $tkamis, $tjumat]);
                $message = 'Data Gelombang berhasil ditambahkan!';
                $msgType = 'success';
            }
        } elseif ($action === 'edit_gelombang') {
            $id = (int)($_POST['id'] ?? 0);
            $semester = $_POST['semester_tipe'] ?? '';
            $tahun_ajaran = $_POST['tahun_ajaran'] ?? '';
            $gelombang = $_POST['gelombang'] ?? '';
            $ksenin = (int)($_POST['kuota_senin'] ?? 0);
            $kselasa = (int)($_POST['kuota_selasa'] ?? 0);
            $krabu = (int)($_POST['kuota_rabu'] ?? 0);
            $kkamis = (int)($_POST['kuota_kamis'] ?? 0);
            $kjumat = (int)($_POST['kuota_jumat'] ?? 0);
            $tsenin = implode(',', array_filter($_POST['tutors_senin'] ?? []));
            $tselasa = implode(',', array_filter($_POST['tutors_selasa'] ?? []));
            $trabu = implode(',', array_filter($_POST['tutors_rabu'] ?? []));
            $tkamis = implode(',', array_filter($_POST['tutors_kamis'] ?? []));
            $tjumat = implode(',', array_filter($_POST['tutors_jumat'] ?? []));

            if ($id <= 0 || empty($semester) || empty($tahun_ajaran) || empty($gelombang)) {
                $message = 'Data tidak valid.';
                $msgType = 'danger';
            } else {
                $pdo->prepare("UPDATE master_gelombang SET semester=?, tahun_ajaran=?, gelombang=?, kuota_senin=?, kuota_selasa=?, kuota_rabu=?, kuota_kamis=?, kuota_jumat=?, tutors_senin=?, tutors_selasa=?, tutors_rabu=?, tutors_kamis=?, tutors_jumat=? WHERE id=?")
                    ->execute([$semester, $tahun_ajaran, $gelombang, $ksenin, $kselasa, $krabu, $kkamis, $kjumat, $tsenin, $tselasa, $trabu, $tkamis, $tjumat, $id]);
                $message = 'Gelombang berhasil diperbarui!';
                $msgType = 'success';
            }
        }
    }
}

$gelombangData = $pdo->query("SELECT * FROM master_gelombang ORDER BY created_at DESC")->fetchAll();
$gelLabels = ['gel1' => 'Gelombang 1 (Ganjil)', 'gel2' => 'Gelombang 2 (Genap)', 'mandiri' => 'Mandiri'];

include __DIR__ . '/../includes/header.php';

$tutorsList = $pdo->query("SELECT id, nama FROM tutors ORDER BY nama ASC")->fetchAll();
function renderTutorSelect($day, $tutorsList, $isEdit = false) {
    $idPrefix = $isEdit ? 'edit_' : '';
    ?>
    <div class="form-group" style="margin-bottom:0; display:flex; flex-direction:column; gap:8px;">
        <label style="font-weight:bold; font-size:14px; text-transform:capitalize;"><?= $day ?></label>
        <div id="<?= $idPrefix ?>tutors_<?= $day ?>_container" class="tutor-container" data-day="<?= $day ?>" data-edit="<?= $isEdit ? '1' : '0' ?>">
            <div class="tutor-row" style="display:flex; gap:4px; margin-bottom:4px;">
                <select name="tutors_<?= $day ?>[]" class="tutor-select" style="flex:1; padding:6px; border:1.5px solid #e5e7eb; border-radius:6px; font-size:13px;" onchange="calculateQuota('<?= $day ?>', <?= $isEdit ? 'true' : 'false' ?>)">
                    <option value="">- Tutor -</option>
                    <?php foreach($tutorsList as $t): ?>
                    <option value="<?= sanitize($t['nama']) ?>"><?= sanitize($t['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm btn-success add-tutor-btn" onclick="addTutorRow('<?= $day ?>', <?= $isEdit ? 'true' : 'false' ?>)" style="padding:0 8px;">+</button>
            </div>
        </div>
        <div style="font-size:12px; color:#64748b; margin-top:-4px;">Kuota:</div>
        <input type="number" name="kuota_<?= $day ?>" id="<?= $idPrefix ?>kuota_<?= $day ?>" class="kuota-input" data-day="<?= $day ?>" min="0" value="0" style="width:100%;padding:6px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:14px;background:#f8fafc;" readonly tabindex="-1">
    </div>
    <?php
}
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
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px; margin-top:16px; margin-bottom:16px; background:#f8fafc; padding:16px; border-radius:10px; border:1px solid #e2e8f0;">
                <?php
                renderTutorSelect('senin', $tutorsList);
                renderTutorSelect('selasa', $tutorsList);
                renderTutorSelect('rabu', $tutorsList);
                renderTutorSelect('kamis', $tutorsList);
                renderTutorSelect('jumat', $tutorsList);
                ?>
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
                        <th>Kuota (Sn-Jm)</th>
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
                        <td>
                            <div style="font-size:12px; color:#475569; display:grid; grid-template-columns:1fr 1fr; gap:4px;">
                                <span>Sn: <b><?= $g['kuota_senin'] ?? 0 ?></b></span>
                                <span>Sl: <b><?= $g['kuota_selasa'] ?? 0 ?></b></span>
                                <span>Rb: <b><?= $g['kuota_rabu'] ?? 0 ?></b></span>
                                <span>Km: <b><?= $g['kuota_kamis'] ?? 0 ?></b></span>
                                <span>Jm: <b><?= $g['kuota_jumat'] ?? 0 ?></b></span>
                            </div>
                        </td>
                        <td><?= date('d M Y H:i', strtotime($g['created_at'])) ?></td>
                        <td style="white-space:nowrap;">
                            <!-- Tombol Edit -->
                            <button type="button" class="btn btn-sm btn-warning btn-edit-gelombang"
                                data-id="<?= $g['id'] ?>"
                                data-semester="<?= htmlspecialchars($g['semester'], ENT_QUOTES, 'UTF-8') ?>"
                                data-tahun_ajaran="<?= htmlspecialchars($g['tahun_ajaran'], ENT_QUOTES, 'UTF-8') ?>"
                                data-gelombang="<?= htmlspecialchars($g['gelombang'], ENT_QUOTES, 'UTF-8') ?>"
                                data-kuota_senin="<?= $g['kuota_senin'] ?? 0 ?>"
                                data-kuota_selasa="<?= $g['kuota_selasa'] ?? 0 ?>"
                                data-kuota_rabu="<?= $g['kuota_rabu'] ?? 0 ?>"
                                data-kuota_kamis="<?= $g['kuota_kamis'] ?? 0 ?>"
                                data-kuota_jumat="<?= $g['kuota_jumat'] ?? 0 ?>"
                                data-tutors_senin="<?= htmlspecialchars($g['tutors_senin'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-tutors_selasa="<?= htmlspecialchars($g['tutors_selasa'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-tutors_rabu="<?= htmlspecialchars($g['tutors_rabu'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-tutors_kamis="<?= htmlspecialchars($g['tutors_kamis'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-tutors_jumat="<?= htmlspecialchars($g['tutors_jumat'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
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

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px; margin-top:16px; margin-bottom:16px; background:#f8fafc; padding:16px; border-radius:10px; border:1px solid #e2e8f0;">
                <?php
                renderTutorSelect('senin', $tutorsList, true);
                renderTutorSelect('selasa', $tutorsList, true);
                renderTutorSelect('rabu', $tutorsList, true);
                renderTutorSelect('kamis', $tutorsList, true);
                renderTutorSelect('jumat', $tutorsList, true);
                ?>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn btn-secondary btn-close-modal" style="width:auto;">Batal</button>
                <button type="submit" class="btn btn-primary" style="width:auto;">💾 Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
window.calculateQuota = function(day, isEdit) {
    const prefix = isEdit ? 'edit_' : '';
    const container = document.getElementById(prefix + 'tutors_' + day + '_container');
    const selects = container.querySelectorAll('select');
    let validTutors = 0;
    selects.forEach(sel => {
        if (sel.value.trim() !== '') validTutors++;
    });
    const kuotaInput = document.getElementById(prefix + 'kuota_' + day);
    if (kuotaInput) kuotaInput.value = validTutors * 30;
};

window.addTutorRow = function(day, isEdit) {
    const prefix = isEdit ? 'edit_' : '';
    const container = document.getElementById(prefix + 'tutors_' + day + '_container');
    const rows = container.querySelectorAll('.tutor-row');
    if (rows.length === 0) return;
    const firstRow = rows[0];
    const newRow = firstRow.cloneNode(true);
    const select = newRow.querySelector('select');
    select.value = ''; // Reset value
    const btn = newRow.querySelector('button');
    btn.textContent = 'x';
    btn.classList.replace('btn-success', 'btn-danger');
    btn.onclick = function() {
        newRow.remove();
        window.calculateQuota(day, isEdit);
    };
    container.appendChild(newRow);
};

window.populateTutors = function(day, tutorsString) {
    const isEdit = true;
    const prefix = 'edit_';
    const container = document.getElementById(prefix + 'tutors_' + day + '_container');
    const rows = container.querySelectorAll('.tutor-row');
    // Keep only the first row
    for(let i = 1; i < rows.length; i++) {
        rows[i].remove();
    }
    
    const firstSelect = container.querySelector('select');
    firstSelect.value = '';
    
    if (!tutorsString) {
        window.calculateQuota(day, isEdit);
        return;
    }
    
    const tutors = tutorsString.split(',');
    if (tutors.length > 0) {
        firstSelect.value = tutors[0];
    }
    
    for(let i = 1; i < tutors.length; i++) {
        const newRow = rows[0].cloneNode(true);
        const select = newRow.querySelector('select');
        select.value = tutors[i];
        const btn = newRow.querySelector('button');
        btn.textContent = 'x';
        btn.classList.replace('btn-success', 'btn-danger');
        btn.onclick = function() {
            newRow.remove();
            window.calculateQuota(day, isEdit);
        };
        container.appendChild(newRow);
    }
    window.calculateQuota(day, isEdit);
};

window.openGelombangModal = function(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('edit_semester_tipe').value = d.semester || '';
    document.getElementById('edit_tahun_ajaran').value = d.tahun_ajaran || '';
    document.getElementById('edit_gelombang').value = d.gelombang || '';
    
    // We update the kuota inputs dynamically based on the tutors list
    window.populateTutors('senin', d.tutors_senin);
    window.populateTutors('selasa', d.tutors_selasa);
    window.populateTutors('rabu', d.tutors_rabu);
    window.populateTutors('kamis', d.tutors_kamis);
    window.populateTutors('jumat', d.tutors_jumat);

    document.getElementById('editGelombangModal').classList.add('show');
};

window.closeGelombangModal = function() {
    var m = document.getElementById('editGelombangModal');
    if (m) m.classList.remove('show');
};

if (!window._editGelombangBound) {
    window._editGelombangBound = true;

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-edit-gelombang');
        if (btn) {
            var d = btn.dataset;
            window.openGelombangModal({
                id: d.id,
                semester: d.semester,
                tahun_ajaran: d.tahun_ajaran,
                gelombang: d.gelombang,
                tutors_senin: d.tutors_senin,
                tutors_selasa: d.tutors_selasa,
                tutors_rabu: d.tutors_rabu,
                tutors_kamis: d.tutors_kamis,
                tutors_jumat: d.tutors_jumat
            });
            return;
        }

        if (e.target.closest('.btn-close-modal')) {
            window.closeGelombangModal();
            return;
        }

        if (e.target && e.target.id === 'editGelombangModal') {
            window.closeGelombangModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') window.closeGelombangModal();
    });
}
</script>
