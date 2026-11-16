<?php
/**
 * LPPAI Corner - Admin: Kelola Kelompok & Tutor Tutorial RPL Online
 */
define('PAGE_TITLE', 'Kelola Kelompok & Tutor RPL Online');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
requireAdmin();

$pdo = getDBConnection();
ensureTutorialRplTables($pdo);

$message = '';
$msgType = '';

// Proses form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'create_class') {
        $namaKelompok = trim($_POST['nama_kelompok'] ?? '');
        $periode = trim($_POST['periode'] ?? TUTORIAL_RPL_DEFAULT_PERIODE);
        $gelombang = trim($_POST['gelombang'] ?? TUTORIAL_RPL_DEFAULT_GELOMBANG);
        $dosenId = (int)($_POST['dosen_id'] ?? 0);
        $driveUrl = trim($_POST['drive_url'] ?? '');
        $kuota = max(1, (int)($_POST['kuota'] ?? 50));
        $catatan = trim($_POST['catatan'] ?? '');

        if ($namaKelompok === '') {
            $message = 'Nama kelompok kelas wajib diisi.';
            $msgType = 'danger';
        } else {
            // Ambil data dosen
            $dosenNama = null;
            $dosenNim = null;
            $dosenWa = null;
            if ($dosenId > 0) {
                $stmtD = $pdo->prepare("SELECT id, nim, nama_lengkap, no_hp, no_hp AS whatsapp FROM users WHERE id = ? AND role = 'dosen' LIMIT 1");
                $stmtD->execute([$dosenId]);
                $dosen = $stmtD->fetch(PDO::FETCH_ASSOC);
                if ($dosen) {
                    $dosenNama = $dosen['nama_lengkap'];
                    $dosenNim = $dosen['nim'];
                    $dosenWa = $dosen['no_hp'] ?? ($dosen['whatsapp'] ?? null);
                }
            }

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO tutorial_rpl_classes 
                    (nama_kelompok, periode, gelombang, dosen_id, dosen_nim, dosen_nama, dosen_wa, drive_url, kuota, catatan, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $namaKelompok, $periode, $gelombang,
                    $dosenId ?: null, $dosenNim, $dosenNama, $dosenWa,
                    $driveUrl ?: null, $kuota, $catatan ?: null
                ]);
                $message = "Kelompok '$namaKelompok' berhasil ditambahkan.";
                $msgType = 'success';
            } catch (Throwable $e) {
                $message = 'Gagal menyimpan kelompok: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'update_class') {
        $classId = (int)($_POST['class_id'] ?? 0);
        $namaKelompok = trim($_POST['nama_kelompok'] ?? '');
        $periode = trim($_POST['periode'] ?? TUTORIAL_RPL_DEFAULT_PERIODE);
        $gelombang = trim($_POST['gelombang'] ?? TUTORIAL_RPL_DEFAULT_GELOMBANG);
        $dosenId = (int)($_POST['dosen_id'] ?? 0);
        $driveUrl = trim($_POST['drive_url'] ?? '');
        $kuota = max(1, (int)($_POST['kuota'] ?? 50));
        $catatan = trim($_POST['catatan'] ?? '');

        if ($classId <= 0 || $namaKelompok === '') {
            $message = 'Data kelompok tidak valid.';
            $msgType = 'danger';
        } else {
            $dosenNama = null;
            $dosenNim = null;
            $dosenWa = null;
            if ($dosenId > 0) {
                $stmtD = $pdo->prepare("SELECT id, nim, nama_lengkap, no_hp, no_hp AS whatsapp FROM users WHERE id = ? AND role = 'dosen' LIMIT 1");
                $stmtD->execute([$dosenId]);
                $dosen = $stmtD->fetch(PDO::FETCH_ASSOC);
                if ($dosen) {
                    $dosenNama = $dosen['nama_lengkap'];
                    $dosenNim = $dosen['nim'];
                    $dosenWa = $dosen['no_hp'] ?? ($dosen['whatsapp'] ?? null);
                }
            }

            try {
                $stmt = $pdo->prepare("
                    UPDATE tutorial_rpl_classes SET
                        nama_kelompok = ?,
                        periode = ?,
                        gelombang = ?,
                        dosen_id = ?,
                        dosen_nim = ?,
                        dosen_nama = ?,
                        dosen_wa = ?,
                        drive_url = ?,
                        kuota = ?,
                        catatan = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $namaKelompok, $periode, $gelombang,
                    $dosenId ?: null, $dosenNim, $dosenNama, $dosenWa,
                    $driveUrl ?: null, $kuota, $catatan ?: null,
                    $classId
                ]);
                $message = 'Perubahan data kelompok berhasil disimpan.';
                $msgType = 'success';
            } catch (Throwable $e) {
                $message = 'Gagal memperbarui kelompok: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'toggle_status') {
        $classId = (int)($_POST['class_id'] ?? 0);
        if ($classId > 0) {
            try {
                $pdo->prepare("UPDATE tutorial_rpl_classes SET is_active = (CASE WHEN is_active = 1 THEN 0 ELSE 1 END) WHERE id = ?")->execute([$classId]);
                $message = 'Status keaktifan kelompok berhasil diperbarui.';
                $msgType = 'success';
            } catch (Throwable $e) {
                $message = 'Gagal mengubah status: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'delete_class') {
        $classId = (int)($_POST['class_id'] ?? 0);
        if ($classId > 0) {
            try {
                $pdo->prepare("DELETE FROM tutorial_rpl_members WHERE class_id = ?")->execute([$classId]);
                $pdo->prepare("DELETE FROM tutorial_rpl_classes WHERE id = ?")->execute([$classId]);
                $message = 'Kelompok kelas beserta data anggotanya berhasil dihapus.';
                $msgType = 'success';
            } catch (Throwable $e) {
                $message = 'Gagal menghapus kelompok: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

$classes = tutorialRplGetClasses($pdo);
$tutors = tutorialRplGetTutors($pdo);

include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0; font-size:22px; font-weight:800; color:var(--text-dark);">
            🌐 Kelola Kelompok & Tutor Tutorial RPL Online
        </h2>
        <p style="margin:0; font-size:13.5px; color:#64748b;">
            Pengaturan kelas daring, penugasan tutor pengampu, link Google Drive penugasan, dan kapasitas kuota mahasiswa program RPL.
        </p>
    </div>
    <div>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('modal-add-class').style.display='flex';" style="display:inline-flex; align-items:center; gap:6px;">
            <span>➕</span> Tambah Kelompok RPL
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="margin-bottom:20px;">
        <?= sanitize($message) ?>
    </div>
<?php endif; ?>

<!-- Info Banner Alur RPL -->
<div class="card" style="margin-bottom:24px; border-left:4px solid #0284c7; background:#f0f9ff; border-radius:12px;">
    <div class="card-body" style="padding:16px 20px;">
        <div style="display:flex; align-items:flex-start; gap:14px;">
            <span style="font-size:26px;">ℹ️</span>
            <div style="flex:1; font-size:13.5px; color:#0369a1; line-height:1.6;">
                <strong>Panduan Alur Tutorial RPL Online:</strong>
                <ol style="margin:6px 0 0 0; padding-left:18px;">
                    <li>Setiap kelompok dibimbing oleh 1 orang Tutor (Dosen) yang ditugaskan khusus.</li>
                    <li>Folder Google Drive digunakan sebagai media pengumpulan video rekaman hafalan mahasiswa.</li>
                    <li>Presensi mahasiswa dihitung otomatis setelah mahasiswa mengunggah tugas yang diverifikasi oleh tutor dan menyelesaikan Ujian Tulis Tutorial.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Daftar Kelompok Kelas RPL -->
<div class="card" style="box-shadow:var(--shadow); border-radius:12px; overflow:hidden;">
    <div class="card-header" style="background:#f8fafc; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <span style="font-weight:700; color:var(--text-dark);">
            📋 Daftar Kelompok Kelas RPL (<?= count($classes) ?>)
        </span>
        <span class="badge badge-primary" style="font-size:12px;">
            <?= TUTORIAL_RPL_DEFAULT_PERIODE ?>
        </span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($classes)): ?>
            <div style="text-align:center; padding:48px 20px; color:#64748b;">
                <div style="font-size:42px; margin-bottom:10px;">🌐</div>
                <h4 style="margin:0 0 6px 0; color:#334155;">Belum Ada Kelompok Tutorial RPL</h4>
                <p style="margin:0 0 16px 0; font-size:13.5px;">Klik tombol "Tambah Kelompok RPL" di atas untuk membuat kelompok baru.</p>
                <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('modal-add-class').style.display='flex';">
                    ➕ Tambah Kelompok Sekarang
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" style="margin:0; width:100%;">
                    <thead>
                        <tr style="background:#f1f5f9; text-align:left; font-size:12.5px; color:#475569;">
                            <th style="padding:12px 16px; width:40px;">No</th>
                            <th style="padding:12px 16px;">Kelompok / Gelombang</th>
                            <th style="padding:12px 16px;">Tutor Pengampu</th>
                            <th style="padding:12px 16px;">Google Drive Tugas</th>
                            <th style="padding:12px 16px; text-align:center;">Kuota / Terisi</th>
                            <th style="padding:12px 16px; text-align:center;">Progres Tugas</th>
                            <th style="padding:12px 16px; text-align:center;">Status</th>
                            <th style="padding:12px 16px; text-align:center; width:140px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $idx => $cls): ?>
                            <tr style="border-bottom:1px solid #e2e8f0; font-size:13.5px;">
                                <td style="padding:14px 16px; color:#64748b; vertical-align:middle;"><?= $idx + 1 ?></td>
                                <td style="padding:14px 16px; vertical-align:middle;">
                                    <div style="font-weight:700; color:#0f172a; margin-bottom:3px;">
                                        <?= sanitize($cls['nama_kelompok']) ?>
                                    </div>
                                    <span class="badge badge-emerald" style="font-size:11px; padding:2px 8px;">
                                        <?= sanitize($cls['gelombang'] ?? 'Gelombang 1') ?>
                                    </span>
                                    <small style="color:#64748b; display:block; margin-top:2px; font-size:11.5px;">
                                        <?= sanitize($cls['periode']) ?>
                                    </small>
                                </td>
                                <td style="padding:14px 16px; vertical-align:middle;">
                                    <?php if (!empty($cls['dosen_nama'])): ?>
                                        <div style="font-weight:600; color:#1e293b; display:flex; align-items:center; gap:6px;">
                                            <span>👨‍🏫</span> <?= sanitize($cls['dosen_nama']) ?>
                                        </div>
                                        <?php if (!empty($cls['dosen_wa'])): ?>
                                            <a href="https://wa.me/<?= preg_replace('/\D/', '', $cls['dosen_wa']) ?>" target="_blank" rel="noopener noreferrer" style="display:inline-flex; align-items:center; gap:4px; font-size:11.5px; color:#15803d; text-decoration:none; margin-top:3px;">
                                                <span>💬</span> WA: <?= sanitize($cls['dosen_wa']) ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-style:italic;">Belum Ditentukan</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 16px; vertical-align:middle;">
                                    <?php if (!empty($cls['drive_url'])): ?>
                                        <a href="<?= sanitize($cls['drive_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-size:12px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:6px;">
                                            <span>📁</span> Buka Folder ↗
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                    <span style="font-weight:700; color:<?= ($cls['terisi'] >= $cls['kuota']) ? '#b91c1c' : '#15803d' ?>;">
                                        <?= (int)$cls['terisi'] ?>
                                    </span>
                                    <span style="color:#64748b;"> / <?= (int)$cls['kuota'] ?></span>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                    <span class="badge" style="background:#f1f5f9; color:#334155; font-size:11.5px;">
                                        <?= (int)$cls['tugas_selesai'] ?> / <?= (int)$cls['terisi'] ?> diverifikasi
                                    </span>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                    <?php if ($cls['is_active']): ?>
                                        <span class="badge badge-emerald" style="font-size:11px;">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" style="font-size:11px;">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                    <div style="display:flex; justify-content:center; gap:6px; flex-wrap:wrap;">
                                        <a href="<?= BASE_URL ?>/admin/tutorial-rpl-peserta.php?class_id=<?= (int)$cls['id'] ?>" class="btn btn-sm btn-info" title="Kelola Anggota Mahasiswa" style="padding:4px 8px; font-size:12px;">
                                            👥
                                        </a>
                                        <button type="button" class="btn btn-sm btn-warning" onclick="editClass(<?= htmlspecialchars(json_encode($cls), ENT_QUOTES) ?>)" title="Edit Kelompok" style="padding:4px 8px; font-size:12px;">
                                            ✏️
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus kelompok ini? Semua data pendaftaran anggota di kelompok ini juga akan terhapus.');">
                                            <input type="hidden" name="action" value="delete_class">
                                            <input type="hidden" name="class_id" value="<?= (int)$cls['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Hapus Kelompok" style="padding:4px 8px; font-size:12px;">
                                                🗑️
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Tambah Kelompok -->
<div id="modal-add-class" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center; padding:16px;">
    <div style="background:#fff; border-radius:14px; width:100%; max-width:540px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); overflow:hidden;">
        <div style="padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin:0; font-size:16px; font-weight:700; color:#1e293b;">➕ Tambah Kelompok Tutorial RPL</h4>
            <button type="button" onclick="document.getElementById('modal-add-class').style.display='none';" style="background:none; border:none; font-size:18px; cursor:pointer; color:#64748b;">✕</button>
        </div>
        <form method="POST" style="padding:20px;">
            <input type="hidden" name="action" value="create_class">
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Nama Kelompok <span style="color:#ef4444;">*</span></label>
                <input type="text" name="nama_kelompok" class="form-control" placeholder="Contoh: Kelompok RPL 1 (Putra)" required style="width:100%;">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Periode</label>
                    <input type="text" name="periode" class="form-control" value="<?= TUTORIAL_RPL_DEFAULT_PERIODE ?>" required style="width:100%;">
                </div>
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Gelombang</label>
                    <input type="text" name="gelombang" class="form-control" value="<?= TUTORIAL_RPL_DEFAULT_GELOMBANG ?>" required style="width:100%;">
                </div>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Tutor Pengampu (Dosen)</label>
                <select name="dosen_id" class="form-control" style="width:100%;">
                    <option value="0">-- Pilih Dosen Tutor --</option>
                    <?php foreach ($tutors as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"><?= sanitize($t['nama_lengkap']) ?> (NIM/NIDN: <?= sanitize($t['nim'] ?: '-') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">URL Folder Google Drive Tugas</label>
                <input type="url" name="drive_url" class="form-control" placeholder="https://drive.google.com/drive/folders/..." style="width:100%;">
                <small style="color:#64748b; font-size:11.5px; display:block; margin-top:3px;">Folder tempat mahasiswa mengunggah tugas video hafalan RPL.</small>
            </div>
            <div style="display:grid; grid-template-columns:1fr 2fr; gap:12px; margin-bottom:18px;">
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Kuota Mahasiswa</label>
                    <input type="number" name="kuota" class="form-control" value="50" min="1" max="500" style="width:100%;">
                </div>
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Catatan Tambahan</label>
                    <input type="text" name="catatan" class="form-control" placeholder="Opsional (mis. Jadwal sinkronisasi daring)" style="width:100%;">
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:14px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modal-add-class').style.display='none';">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Kelompok</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Kelompok -->
<div id="modal-edit-class" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center; padding:16px;">
    <div style="background:#fff; border-radius:14px; width:100%; max-width:540px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); overflow:hidden;">
        <div style="padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin:0; font-size:16px; font-weight:700; color:#1e293b;">✏️ Edit Kelompok Tutorial RPL</h4>
            <button type="button" onclick="document.getElementById('modal-edit-class').style.display='none';" style="background:none; border:none; font-size:18px; cursor:pointer; color:#64748b;">✕</button>
        </div>
        <form method="POST" style="padding:20px;">
            <input type="hidden" name="action" value="update_class">
            <input type="hidden" name="class_id" id="edit-class-id" value="">
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Nama Kelompok <span style="color:#ef4444;">*</span></label>
                <input type="text" name="nama_kelompok" id="edit-nama-kelompok" class="form-control" required style="width:100%;">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Periode</label>
                    <input type="text" name="periode" id="edit-periode" class="form-control" required style="width:100%;">
                </div>
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Gelombang</label>
                    <input type="text" name="gelombang" id="edit-gelombang" class="form-control" required style="width:100%;">
                </div>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Tutor Pengampu (Dosen)</label>
                <select name="dosen_id" id="edit-dosen-id" class="form-control" style="width:100%;">
                    <option value="0">-- Pilih Dosen Tutor --</option>
                    <?php foreach ($tutors as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"><?= sanitize($t['nama_lengkap']) ?> (NIM/NIDN: <?= sanitize($t['nim'] ?: '-') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">URL Folder Google Drive Tugas</label>
                <input type="url" name="drive_url" id="edit-drive-url" class="form-control" style="width:100%;">
            </div>
            <div style="display:grid; grid-template-columns:1fr 2fr; gap:12px; margin-bottom:18px;">
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Kuota Mahasiswa</label>
                    <input type="number" name="kuota" id="edit-kuota" class="form-control" min="1" max="500" style="width:100%;">
                </div>
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Catatan Tambahan</label>
                    <input type="text" name="catatan" id="edit-catatan" class="form-control" style="width:100%;">
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:14px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modal-edit-class').style.display='none';">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function editClass(data) {
    document.getElementById('edit-class-id').value = data.id || '';
    document.getElementById('edit-nama-kelompok').value = data.nama_kelompok || '';
    document.getElementById('edit-periode').value = data.periode || '';
    document.getElementById('edit-gelombang').value = data.gelombang || '';
    document.getElementById('edit-dosen-id').value = data.dosen_id || 0;
    document.getElementById('edit-drive-url').value = data.drive_url || '';
    document.getElementById('edit-kuota').value = data.kuota || 50;
    document.getElementById('edit-catatan').value = data.catatan || '';
    document.getElementById('modal-edit-class').style.display = 'flex';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
