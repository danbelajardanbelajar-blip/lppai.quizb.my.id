<?php
/**
 * LPPAI Corner - Dosen: Bimbingan & Penilaian Tutorial RPL Online (Tahap 3)
 */
define('PAGE_TITLE', 'Tutorial RPL Online (Bimbingan Dosen)');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
requireDosen();

$user = getCurrentUser();
$pdo = getDBConnection();
ensureTutorialRplTables($pdo);

$userId = (int)($user['id'] ?? 0);
$nim = trim((string)($user['nim'] ?? ''));
$nama = trim((string)($user['nama_lengkap'] ?? ''));

// Ambil kelompok yang diampu dosen login (atau semua jika tester 12345)
$isTester = ($nim === '12345' || (string)($user['username'] ?? '') === '12345' || $userId === 12345);

$sql = "
    SELECT c.*,
           (SELECT COUNT(*) FROM tutorial_rpl_members m WHERE m.class_id = c.id) AS terisi,
           (SELECT COUNT(*) FROM tutorial_rpl_members m WHERE m.class_id = c.id AND m.drive_verified = 1) AS tugas_selesai,
           (SELECT COUNT(*) FROM tutorial_rpl_members m WHERE m.class_id = c.id AND m.exam_submitted = 1) AS cbt_selesai
    FROM tutorial_rpl_classes c
    WHERE c.is_active = 1
";
$params = [];
if (!$isTester) {
    $sql .= " AND (c.dosen_id = ? OR (c.dosen_nim IS NOT NULL AND c.dosen_nim = ?) OR c.dosen_nama = ?)";
    $params = [$userId, $nim, $nama];
}
$sql .= " ORDER BY c.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$myClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selectedClassId = (int)($_GET['class_id'] ?? ($myClasses[0]['id'] ?? 0));
$currentClass = null;
foreach ($myClasses as $c) {
    if ((int)$c['id'] === $selectedClassId) {
        $currentClass = $c;
        break;
    }
}

// Proses Form Penilaian & Verifikasi
$message = '';
$msgType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'save_evaluation') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $driveVerified = (int)($_POST['drive_verified'] ?? 1);
        $notes = trim($_POST['drive_notes'] ?? '');
        $nilaiPraktikRaw = trim($_POST['nilai_praktik'] ?? '');
        $nilaiPraktik = ($nilaiPraktikRaw !== '') ? max(0, min(100, (float)$nilaiPraktikRaw)) : null;

        if ($memberId > 0) {
            try {
                tutorialRplSaveEvaluation($pdo, $memberId, $nilaiPraktik, $driveVerified, $notes);
                $message = 'Evaluasi dan verifikasi tugas mahasiswa berhasil disimpan.';
                $msgType = 'success';
            } catch (Throwable $e) {
                $message = 'Gagal menyimpan evaluasi: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'batch_verify') {
        $selectedMemberIds = $_POST['member_ids'] ?? [];
        $verifyStatus = (int)($_POST['batch_status'] ?? 1);

        if (!is_array($selectedMemberIds) || empty($selectedMemberIds)) {
            $message = 'Pilih mahasiswa yang ingin diverifikasi tugasnya.';
            $msgType = 'warning';
        } else {
            try {
                $count = tutorialRplBatchVerify($pdo, $selectedMemberIds, $verifyStatus);
                $label = ($verifyStatus === 1) ? 'diverifikasi sah' : 'dibatalkan verifikasinya';
                $message = "Berhasil memperbarui $count tugas mahasiswa menjadi $label.";
                $msgType = 'success';
            } catch (Throwable $e) {
                $message = 'Gagal verifikasi massal: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

$members = [];
if ($currentClass) {
    $members = tutorialRplGetMembers($pdo, (int)$currentClass['id']);
}

// Filter pencarian di sisi PHP jika ada parameter search
$searchTerm = trim($_GET['q'] ?? '');
if ($searchTerm !== '') {
    $lowTerm = mb_strtolower($searchTerm, 'UTF-8');
    $members = array_values(array_filter($members, function($m) use ($lowTerm) {
        return str_contains(mb_strtolower($m['nama_lengkap'] ?? '', 'UTF-8'), $lowTerm)
            || str_contains(mb_strtolower($m['nim'] ?? '', 'UTF-8'), $lowTerm)
            || str_contains(mb_strtolower($m['program_studi'] ?? '', 'UTF-8'), $lowTerm);
    }));
}

// Metrik ringkasan kelas bimbingan aktif
$totalMembers = count($members);
$tugasVerifiedCount = 0;
$cbtSubmittedCount = 0;
$hadirSahCount = 0;
foreach ($members as $m) {
    if (!empty($m['drive_verified'])) $tugasVerifiedCount++;
    if (!empty($m['exam_submitted'])) $cbtSubmittedCount++;
    if (!empty($m['drive_verified']) && !empty($m['exam_submitted'])) $hadirSahCount++;
}

include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0; font-size:22px; font-weight:800; color:var(--text-dark);">
            🌐 Tutorial RPL Online — Bimbingan Dosen
        </h2>
        <p style="margin:0; font-size:13.5px; color:#64748b;">
            Tinjau video rekaman hafalan di Google Drive, berikan penilaian evaluasi praktik, serta verifikasi kelengkapan tugas mahasiswa bimbingan.
        </p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="margin-bottom:20px;">
        <?= sanitize($message) ?>
    </div>
<?php endif; ?>

<?php if (empty($myClasses)): ?>
    <div class="card" style="padding:48px 20px; text-align:center; color:#64748b; border-radius:12px;">
        <div style="font-size:42px; margin-bottom:12px;">🏫</div>
        <h3 style="margin:0 0 6px 0; color:#334155;">Belum Ada Kelompok Tutorial RPL</h3>
        <p style="margin:0; font-size:14px;">
            Anda belum ditugaskan sebagai tutor pada kelompok Tutorial RPL Online yang aktif.<br>
            Silakan hubungi administrator LPPAI jika Anda telah dijadwalkan mengampu kelompok RPL.
        </p>
    </div>
<?php else: ?>
    <!-- Pilihan Tab Kelompok Dosen -->
    <div style="display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap;">
        <?php foreach ($myClasses as $cls): ?>
            <?php $isActiveTab = ((int)$cls['id'] === $selectedClassId); ?>
            <a href="?class_id=<?= (int)$cls['id'] ?>" class="btn <?= $isActiveTab ? 'btn-primary' : 'btn-secondary' ?>" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; text-decoration:none;">
                <span>🏫</span> <?= sanitize($cls['nama_kelompok']) ?>
                <span class="badge" style="background:<?= $isActiveTab ? 'rgba(255,255,255,0.25)' : '#e2e8f0' ?>; color:<?= $isActiveTab ? '#fff' : '#475569' ?>; font-size:11px;">
                    <?= (int)$cls['terisi'] ?> Mhs
                </span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($currentClass): ?>
        <!-- Metrik Progres Bimbingan Kelas Aktif -->
        <div class="stat-grid" style="margin-bottom:20px;">
            <div class="stat-card">
                <div class="stat-icon blue">👥</div>
                <div class="stat-info">
                    <h3><?= $totalMembers ?></h3>
                    <p>Mahasiswa Bimbingan</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">📁</div>
                <div class="stat-info">
                    <h3><?= $tugasVerifiedCount ?> / <?= $totalMembers ?></h3>
                    <p>Tugas Diverifikasi</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">🧩</div>
                <div class="stat-info">
                    <h3><?= $cbtSubmittedCount ?> / <?= $totalMembers ?></h3>
                    <p>CBT Selesai</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon emerald">🎯</div>
                <div class="stat-info">
                    <h3 style="color:#15803d;"><?= $hadirSahCount ?></h3>
                    <p>Presensi Sah Lengkap</p>
                </div>
            </div>
        </div>

        <!-- Card Folder Google Drive Kelas -->
        <div class="card" style="margin-bottom:20px; border-left:4px solid #059669; border-radius:12px; background:#f0fdf4;">
            <div class="card-body" style="padding:16px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
                <div>
                    <h3 style="margin:0 0 4px 0; font-size:17px; font-weight:700; color:#065f46;">
                        <?= sanitize($currentClass['nama_kelompok']) ?>
                    </h3>
                    <p style="margin:0; font-size:13px; color:#64748b;">
                        <?= sanitize($currentClass['periode']) ?> · <?= sanitize($currentClass['gelombang'] ?? 'Gelombang 1') ?>
                    </p>
                </div>
                <div>
                    <?php if (!empty($currentClass['drive_url'])): ?>
                        <a href="<?= sanitize($currentClass['drive_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px; font-weight:700;">
                            <span>📁</span> Buka Folder Tugas Google Drive ↗
                        </a>
                    <?php else: ?>
                        <span style="font-size:12.5px; color:#94a3b8; font-style:italic;">Folder Google Drive belum diatur oleh admin</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Form Pencarian Mahasiswa Bimbingan -->
        <div class="card" style="margin-bottom:20px; border-radius:12px;">
            <div class="card-body" style="padding:14px 20px;">
                <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <input type="hidden" name="class_id" value="<?= (int)$currentClass['id'] ?>">
                    <div style="flex:1; min-width:220px;">
                        <input type="text" name="q" value="<?= sanitize($searchTerm) ?>" class="form-control" placeholder="Cari nama, NIM, atau prodi..." style="width:100%;">
                    </div>
                    <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:4px;">
                        <span>🔍</span> Cari
                    </button>
                    <?php if ($searchTerm !== ''): ?>
                        <a href="?class_id=<?= (int)$currentClass['id'] ?>" class="btn btn-secondary">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Form Aksi Massal & Tabel Mahasiswa Bimbingan -->
        <form method="POST" id="form-tutor-batch">
            <input type="hidden" name="class_id" value="<?= (int)$currentClass['id'] ?>">

            <div id="tutor-batch-bar" style="display:none; background:#065f46; color:#fff; border-radius:12px; padding:12px 18px; margin-bottom:16px; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; box-shadow:0 8px 20px rgba(6,95,70,0.35);">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:18px;">🔘</span>
                    <span id="tutor-selected-count" style="font-weight:700; font-size:14px;">0 Mahasiswa Terpilih</span>
                </div>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <input type="hidden" name="action" value="batch_verify">
                    <button type="submit" name="batch_status" value="1" class="btn btn-success btn-sm" style="font-weight:700;">
                        ✓ Verifikasi Tugas Terpilih
                    </button>
                    <button type="submit" name="batch_status" value="0" class="btn btn-secondary btn-sm" style="font-weight:700;">
                        ✕ Batal Verifikasi
                    </button>
                </div>
            </div>

            <div class="card" style="box-shadow:var(--shadow); border-radius:12px; overflow:hidden;">
                <div class="card-header" style="background:#f8fafc; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <span style="font-weight:700; color:var(--text-dark);">
                        📋 Daftar Mahasiswa Bimbingan (<?= count($members) ?>)
                    </span>
                    <span style="font-size:12px; color:#64748b;">
                        <em>Klik tombol 📝 Evaluasi pada baris mahasiswa untuk input nilai praktik &amp; catatan bimbingan.</em>
                    </span>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($members)): ?>
                        <div style="text-align:center; padding:40px 20px; color:#64748b;">
                            <p style="margin:0;">Tidak ada mahasiswa yang sesuai di kelompok ini.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table" style="margin:0; width:100%;">
                                <thead>
                                    <tr style="background:#f1f5f9; text-align:left; font-size:12.5px; color:#475569;">
                                        <th style="padding:12px 14px; width:36px; text-align:center;">
                                            <input type="checkbox" id="check-all-tutor" onclick="toggleTutorSelectAll(this)" style="cursor:pointer; transform:scale(1.15);">
                                        </th>
                                        <th style="padding:12px 14px; width:36px;">No</th>
                                        <th style="padding:12px 14px;">Mahasiswa</th>
                                        <th style="padding:12px 14px;">NIM &amp; Program Studi</th>
                                        <th style="padding:12px 14px; text-align:center;">Verifikasi Tugas Drive</th>
                                        <th style="padding:12px 14px; text-align:center;">Nilai Praktik</th>
                                        <th style="padding:12px 14px; text-align:center;">Ujian Tulis Tutorial</th>
                                        <th style="padding:12px 14px; text-align:center;">Status Presensi</th>
                                        <th style="padding:12px 14px; text-align:center; width:120px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($members as $idx => $m): ?>
                                        <?php $isHadir = (!empty($m['drive_verified']) && !empty($m['exam_submitted'])); ?>
                                        <tr style="border-bottom:1px solid #e2e8f0; font-size:13px;">
                                            <td style="padding:12px 14px; text-align:center; vertical-align:middle;">
                                                <input type="checkbox" name="member_ids[]" value="<?= (int)$m['id'] ?>" class="tutor-checkbox" onchange="updateTutorSelectedCount()" style="cursor:pointer; transform:scale(1.15);">
                                            </td>
                                            <td style="padding:12px 14px; color:#64748b; vertical-align:middle;"><?= $idx + 1 ?></td>
                                            <td style="padding:12px 14px; vertical-align:middle;">
                                                <div style="font-weight:700; color:#0f172a;"><?= sanitize($m['nama_lengkap']) ?></div>
                                                <?php $wa = !empty($m['no_hp']) ? $m['no_hp'] : (!empty($m['whatsapp']) ? $m['whatsapp'] : ''); ?>
                                                <?php if (!empty($wa)): ?>
                                                    <a href="https://wa.me/<?= preg_replace('/\D/', '', $wa) ?>" target="_blank" rel="noopener noreferrer" style="font-size:11.5px; color:#15803d; text-decoration:none; display:inline-flex; align-items:center; gap:3px;">
                                                        <span>💬</span> <?= sanitize($wa) ?>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:12px 14px; vertical-align:middle;">
                                                <strong><?= sanitize($m['nim'] ?: '-') ?></strong>
                                                <small style="color:#64748b; display:block; font-size:11.5px;"><?= sanitize($m['program_studi'] ?: '') ?></small>
                                            </td>
                                            <td style="padding:12px 14px; text-align:center; vertical-align:middle;">
                                                <?php if ($m['drive_verified']): ?>
                                                    <span class="badge badge-emerald" style="font-size:11px; padding:3px 8px;">✅ Terverifikasi</span>
                                                <?php elseif ($m['drive_submitted']): ?>
                                                    <span class="badge badge-info" style="font-size:11px; padding:3px 8px;">⏳ Menunggu Verifikasi</span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning" style="font-size:11px; padding:3px 8px;">Belum Diunggah</span>
                                                <?php endif; ?>
                                                <?php if (!empty($m['drive_notes'])): ?>
                                                    <small style="color:#64748b; display:block; font-size:11px; margin-top:2px;">
                                                        📝 <?= sanitize($m['drive_notes']) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:12px 14px; text-align:center; vertical-align:middle;">
                                                <?php if ($m['nilai_praktik'] !== null): ?>
                                                    <strong style="color:#0f172a; font-size:14px;"><?= (float)$m['nilai_praktik'] ?></strong>
                                                <?php else: ?>
                                                    <span style="color:#94a3b8; font-size:12px;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:12px 14px; text-align:center; vertical-align:middle;">
                                                <?php if ($m['exam_submitted']): ?>
                                                    <span class="badge badge-emerald" style="font-size:11px; padding:3px 8px;">
                                                        ✅ Selesai (<?= $m['nilai_ujian_tulis'] !== null ? (float)$m['nilai_ujian_tulis'] : '-' ?>)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary" style="font-size:11px; padding:3px 8px;">Belum CBT</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:12px 14px; text-align:center; vertical-align:middle;">
                                                <?php if ($isHadir): ?>
                                                    <span class="badge badge-emerald" style="font-size:11.5px; font-weight:700; padding:4px 10px;">✅ Hadir Sah</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger" style="font-size:11px; padding:3px 8px;">Belum Lengkap</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:12px 14px; text-align:center; vertical-align:middle;">
                                                <button type="button" class="btn btn-sm btn-primary" onclick="openEvaluationModal(<?= htmlspecialchars(json_encode($m), ENT_QUOTES) ?>)" style="padding:4px 8px; font-size:11.5px; font-weight:600;">
                                                    📝 Evaluasi
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>

<!-- Modal Evaluasi & Verifikasi Mahasiswa -->
<div id="modal-eval" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center; padding:16px;">
    <div style="background:#fff; border-radius:14px; width:100%; max-width:460px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); overflow:hidden;">
        <div style="padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin:0; font-size:15px; font-weight:700; color:#1e293b;">📝 Evaluasi Tugas &amp; Praktik Hafalan</h4>
            <button type="button" onclick="document.getElementById('modal-eval').style.display='none';" style="background:none; border:none; font-size:18px; cursor:pointer; color:#64748b;">✕</button>
        </div>
        <form method="POST" style="padding:20px;">
            <input type="hidden" name="action" value="save_evaluation">
            <input type="hidden" name="member_id" id="eval-member-id" value="">

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:12.5px; color:#64748b; margin-bottom:2px;">Mahasiswa:</label>
                <div id="eval-student-name" style="font-size:15px; font-weight:700; color:#0f172a;"></div>
                <small id="eval-student-nim" style="color:#64748b; font-size:12px;"></small>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Status Verifikasi Tugas Google Drive</label>
                <select name="drive_verified" id="eval-drive-verified" class="form-control" style="width:100%;">
                    <option value="1">✅ Terverifikasi (Tugas Video Hafalan Sah)</option>
                    <option value="0">⏳ Belum Diverifikasi / Perlu Revisi</option>
                </select>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Nilai Praktik Hafalan (0 - 100)</label>
                <input type="number" step="0.1" min="0" max="100" name="nilai_praktik" id="eval-nilai-praktik" class="form-control" placeholder="Contoh: 85.0" style="width:100%;">
                <small style="color:#64748b; font-size:11.5px; display:block; margin-top:2px;">Passing grade kelulusan: 80.0</small>
            </div>

            <div style="margin-bottom:18px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Catatan / Umpan Balik Tutor</label>
                <textarea name="drive_notes" id="eval-drive-notes" class="form-control" rows="3" placeholder="Catatan kelancaran makhraj, tajwid, atau hafalan..." style="width:100%;"></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:14px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('modal-eval').style.display='none';">Batal</button>
                <button type="submit" class="btn btn-primary btn-sm">Simpan Penilaian</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleTutorSelectAll(master) {
    const boxes = document.querySelectorAll('.tutor-checkbox');
    boxes.forEach(cb => cb.checked = master.checked);
    updateTutorSelectedCount();
}

function updateTutorSelectedCount() {
    const checked = document.querySelectorAll('.tutor-checkbox:checked');
    const bar = document.getElementById('tutor-batch-bar');
    const label = document.getElementById('tutor-selected-count');

    if (checked.length > 0) {
        bar.style.display = 'flex';
        label.textContent = checked.length + ' Mahasiswa Terpilih';
    } else {
        bar.style.display = 'none';
        const master = document.getElementById('check-all-tutor');
        if (master) master.checked = false;
    }
}

function openEvaluationModal(data) {
    document.getElementById('eval-member-id').value = data.id || '';
    document.getElementById('eval-student-name').textContent = data.nama_lengkap || '-';
    document.getElementById('eval-student-nim').textContent = 'NIM: ' + (data.nim || '-') + ' · ' + (data.program_studi || '');
    document.getElementById('eval-drive-verified').value = (data.drive_verified == 1) ? '1' : '0';
    document.getElementById('eval-nilai-praktik').value = (data.nilai_praktik !== null) ? data.nilai_praktik : '';
    document.getElementById('eval-drive-notes').value = data.drive_notes || '';
    document.getElementById('modal-eval').style.display = 'flex';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
