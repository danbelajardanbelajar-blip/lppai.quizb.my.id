<?php
/**
 * LPPAI Corner - Admin: Monitoring Verifikasi Tugas & Presensi Tutorial RPL Online (Tahap 3)
 */
define('PAGE_TITLE', 'Verifikasi Tugas & Presensi RPL Online');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
requireAdmin();

$pdo = getDBConnection();
ensureTutorialRplTables($pdo);

$message = '';
$msgType = '';

$selectedClassId = (int)($_GET['class_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? 'all');
$searchTerm = trim($_GET['q'] ?? '');

// Proses Action Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'verify_single') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $status = (int)($_POST['verify_status'] ?? 1);
        if ($memberId > 0) {
            try {
                tutorialRplBatchVerify($pdo, [$memberId], $status);
                $message = 'Status verifikasi tugas mahasiswa berhasil diperbarui.';
                $msgType = 'success';
            } catch (Throwable $e) {
                $message = 'Gagal memperbarui verifikasi: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'batch_verify') {
        $memberIds = $_POST['member_ids'] ?? [];
        $status = (int)($_POST['batch_status'] ?? 1);
        if (!is_array($memberIds) || empty($memberIds)) {
            $message = 'Pilih mahasiswa yang ingin diverifikasi tugasnya.';
            $msgType = 'warning';
        } else {
            try {
                $count = tutorialRplBatchVerify($pdo, $memberIds, $status);
                $label = ($status === 1) ? 'diverifikasi sah' : 'dibatalkan verifikasinya';
                $message = "Berhasil memperbarui $count tugas mahasiswa menjadi $label.";
                $msgType = 'success';
            } catch (Throwable $e) {
                $message = 'Gagal verifikasi massal: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

$classes = tutorialRplGetClasses($pdo);

// Kueri data presensi
$query = "
    SELECT m.*,
           u.nama_lengkap, u.nim, u.whatsapp, u.program_studi, u.fakultas,
           c.nama_kelompok, c.dosen_nama, c.dosen_wa, c.drive_url
    FROM tutorial_rpl_members m
    JOIN users u ON m.user_id = u.id
    JOIN tutorial_rpl_classes c ON m.class_id = c.id
    WHERE 1=1
";
$params = [];

if ($selectedClassId > 0) {
    $query .= " AND m.class_id = ? ";
    $params[] = $selectedClassId;
}

if ($statusFilter === 'hadir') {
    $query .= " AND m.drive_verified = 1 AND m.exam_submitted = 1 ";
} elseif ($statusFilter === 'belum') {
    $query .= " AND (m.drive_verified = 0 OR m.exam_submitted = 0) ";
}

if ($searchTerm !== '') {
    $term = '%' . $searchTerm . '%';
    $query .= " AND (u.nama_lengkap LIKE ? OR u.nim LIKE ? OR u.program_studi LIKE ?) ";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$query .= " ORDER BY c.nama_kelompok ASC, u.nama_lengkap ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Export CSV jika diminta
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=rekap_presensi_tutorial_rpl_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['No', 'Nama Mahasiswa', 'NIM', 'Program Studi', 'Kelompok', 'Tutor Pengampu', 'Tugas Drive', 'Catatan Tutor', 'Ujian Tulis CBT', 'Status Presensi']);
    foreach ($rows as $i => $r) {
        $isHadir = (!empty($r['drive_verified']) && !empty($r['exam_submitted']));
        fputcsv($out, [
            $i + 1,
            $r['nama_lengkap'],
            $r['nim'],
            $r['program_studi'],
            $r['nama_kelompok'],
            $r['dosen_nama'] ?: '-',
            $r['drive_verified'] ? 'Terverifikasi' : ($r['drive_submitted'] ? 'Menunggu' : 'Belum'),
            $r['drive_notes'] ?: '-',
            $r['exam_submitted'] ? 'Selesai (' . ($r['nilai_ujian_tulis'] !== null ? $r['nilai_ujian_tulis'] : '-') . ')' : 'Belum CBT',
            $isHadir ? 'Hadir Sah' : 'Belum Lengkap'
        ]);
    }
    fclose($out);
    exit;
}

// Ringkasan metrik
$totalPeserta = count($rows);
$tugasVerified = 0;
$cbtSelesai = 0;
$hadirLengkap = 0;

foreach ($rows as $r) {
    if (!empty($r['drive_verified'])) $tugasVerified++;
    if (!empty($r['exam_submitted'])) $cbtSelesai++;
    if (!empty($r['drive_verified']) && !empty($r['exam_submitted'])) $hadirLengkap++;
}
$persenHadir = $totalPeserta > 0 ? round(($hadirLengkap / $totalPeserta) * 100, 1) : 0;

include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0; font-size:22px; font-weight:800; color:var(--text-dark);">
            ✅ Verifikasi Tugas &amp; Presensi RPL Online
        </h2>
        <p style="margin:0; font-size:13.5px; color:#64748b;">
            Monitoring kehadiran daring: mahasiswa dinyatakan hadir sah apabila tugas video hafalan diverifikasi tutor dan ujian tulis CBT telah diselesaikan.
        </p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="?export=csv<?= $selectedClassId > 0 ? '&class_id=' . $selectedClassId : '' ?><?= $statusFilter !== 'all' ? '&status=' . $statusFilter : '' ?><?= $searchTerm !== '' ? '&q=' . urlencode($searchTerm) : '' ?>" class="btn btn-success" style="display:inline-flex; align-items:center; gap:6px;">
            <span>📥</span> Ekspor CSV Presensi
        </a>
        <a href="<?= BASE_URL ?>/admin/tutorial-rpl-kelas.php" class="btn btn-secondary">
            <span>🏫</span> Kelola Kelompok
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="margin-bottom:20px;">
        <?= sanitize($message) ?>
    </div>
<?php endif; ?>

<!-- Metrik Cards -->
<div class="stat-grid" style="margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-icon blue">👥</div>
        <div class="stat-info">
            <h3><?= $totalPeserta ?></h3>
            <p>Total Peserta RPL</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">📁</div>
        <div class="stat-info">
            <h3><?= $tugasVerified ?></h3>
            <p>Tugas Terverifikasi</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">🧩</div>
        <div class="stat-info">
            <h3><?= $cbtSelesai ?></h3>
            <p>CBT Diselesaikan</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon emerald">🎯</div>
        <div class="stat-info">
            <h3 style="color:#15803d;"><?= $hadirLengkap ?> (<?= $persenHadir ?>%)</h3>
            <p>Presensi Sah Lengkap</p>
        </div>
    </div>
</div>

<!-- Panel Filter & Pencarian -->
<div class="card" style="margin-bottom:20px; border-radius:12px;">
    <div class="card-body" style="padding:16px 20px;">
        <form method="GET" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
            <div style="flex:1; min-width:200px;">
                <input type="text" name="q" value="<?= sanitize($searchTerm) ?>" class="form-control" placeholder="Cari nama, NIM, atau prodi..." style="width:100%;">
            </div>
            <div style="min-width:200px;">
                <select name="class_id" class="form-control" style="width:100%;">
                    <option value="0">-- Semua Kelompok Kelas --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $selectedClassId === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= sanitize($c['nama_kelompok']) ?> (Tutor: <?= sanitize($c['dosen_nama'] ?: '-') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:170px;">
                <select name="status" class="form-control" style="width:100%;">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Semua Presensi</option>
                    <option value="hadir" <?= $statusFilter === 'hadir' ? 'selected' : '' ?>>✅ Hadir Sah (<?= $hadirLengkap ?>)</option>
                    <option value="belum" <?= $statusFilter === 'belum' ? 'selected' : '' ?>>⏳ Belum Lengkap (<?= $totalPeserta - $hadirLengkap ?>)</option>
                </select>
            </div>
            <div style="display:flex; gap:6px;">
                <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:4px;">
                    <span>🔍</span> Filter
                </button>
                <?php if ($searchTerm !== '' || $selectedClassId > 0 || $statusFilter !== 'all'): ?>
                    <a href="?" class="btn btn-secondary" title="Reset Filter">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Form Aksi Massal & Tabel Presensi Admin -->
<form method="POST" id="form-admin-presensi">
    <div id="admin-batch-bar" style="display:none; background:#0f172a; color:#fff; border-radius:12px; padding:12px 18px; margin-bottom:16px; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; box-shadow:0 8px 20px rgba(15,23,42,0.35);">
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="font-size:18px;">🔘</span>
            <span id="admin-selected-count" style="font-weight:700; font-size:14px;">0 Mahasiswa Terpilih</span>
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
                📊 Matriks Presensi &amp; Tugas Mahasiswa RPL (<?= count($rows) ?> data)
            </span>
            <span style="font-size:12px; color:#64748b;">
                Syarat Hadir: <strong>Verifikasi Tugas = Ya</strong> &amp; <strong>CBT Tutorial = Selesai</strong>
            </span>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($rows)): ?>
                <div style="text-align:center; padding:48px 20px; color:#64748b;">
                    <div style="font-size:36px; margin-bottom:8px;">📋</div>
                    <h4 style="margin:0 0 4px 0; color:#334155;">Tidak Ada Data Ditemukan</h4>
                    <p style="margin:0; font-size:13.5px;">Belum ada peserta yang sesuai dengan filter pencarian.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" style="margin:0; width:100%;">
                        <thead>
                            <tr style="background:#f1f5f9; text-align:left; font-size:12.5px; color:#475569;">
                                <th style="padding:12px 14px; width:36px; text-align:center;">
                                    <input type="checkbox" id="check-all-admin" onclick="toggleAdminSelectAll(this)" style="cursor:pointer; transform:scale(1.15);">
                                </th>
                                <th style="padding:12px 14px; width:36px;">No</th>
                                <th style="padding:12px 14px;">Mahasiswa</th>
                                <th style="padding:12px 14px;">Kelompok &amp; Tutor</th>
                                <th style="padding:12px 14px; text-align:center;">Tugas Google Drive</th>
                                <th style="padding:12px 14px; text-align:center;">Nilai Praktik</th>
                                <th style="padding:12px 14px; text-align:center;">Ujian Tulis Tutorial</th>
                                <th style="padding:12px 14px; text-align:center;">Status Presensi</th>
                                <th style="padding:12px 14px; text-align:center; width:110px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $idx => $r): ?>
                                <?php $isHadir = (!empty($r['drive_verified']) && !empty($r['exam_submitted'])); ?>
                                <tr style="border-bottom:1px solid #e2e8f0; font-size:13px; background:<?= $isHadir ? '#ffffff' : '#fffbeb' ?>;">
                                    <td style="padding:12px 14px; text-align:center; vertical-align:middle;">
                                        <input type="checkbox" name="member_ids[]" value="<?= (int)$r['id'] ?>" class="admin-checkbox" onchange="updateAdminSelectedCount()" style="cursor:pointer; transform:scale(1.15);">
                                    </td>
                                    <td style="padding:12px 14px; color:#64748b; vertical-align:middle;"><?= $idx + 1 ?></td>
                                    <td style="padding:12px 14px; vertical-align:middle;">
                                        <div style="font-weight:700; color:#0f172a;"><?= sanitize($r['nama_lengkap']) ?></div>
                                        <small style="color:#64748b;">NIM: <?= sanitize($r['nim'] ?: '-') ?> · <?= sanitize($r['program_studi'] ?: '') ?></small>
                                    </td>
                                    <td style="padding:12px 14px; vertical-align:middle;">
                                        <div style="font-weight:600; color:#0369a1;"><?= sanitize($r['nama_kelompok']) ?></div>
                                        <small style="color:#64748b;">👨‍🏫 <?= sanitize($r['dosen_nama'] ?: '-') ?></small>
                                    </td>
                                    <td style="padding:12px 14px; text-align:center; vertical-align:middle;">
                                        <?php if ($r['drive_verified']): ?>
                                            <span class="badge badge-emerald" style="font-size:11px; padding:3px 8px;">✅ Diverifikasi</span>
                                        <?php elseif ($r['drive_submitted']): ?>
                                            <span class="badge badge-info" style="font-size:11px; padding:3px 8px;">⏳ Menunggu</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning" style="font-size:11px; padding:3px 8px;">Belum Diunggah</span>
                                        <?php endif; ?>
                                        <?php if (!empty($r['drive_notes'])): ?>
                                            <small style="color:#64748b; display:block; font-size:11px; margin-top:2px;">
                                                📝 <?= sanitize($r['drive_notes']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:12px 14px; text-align:center; vertical-align:middle; font-weight:600;">
                                        <?= $r['nilai_praktik'] !== null ? (float)$r['nilai_praktik'] : '<span style="color:#94a3b8;">-</span>' ?>
                                    </td>
                                    <td style="padding:12px 14px; text-align:center; vertical-align:middle;">
                                        <?php if ($r['exam_submitted']): ?>
                                            <span class="badge badge-emerald" style="font-size:11px; padding:3px 8px;">
                                                ✅ Selesai (<?= $r['nilai_ujian_tulis'] !== null ? (float)$r['nilai_ujian_tulis'] : '-' ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary" style="font-size:11px; padding:3px 8px;">Belum CBT</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:12px 14px; text-align:center; vertical-align:middle;">
                                        <?php if ($isHadir): ?>
                                            <span class="badge badge-emerald" style="font-size:11.5px; font-weight:700; padding:4px 10px;">
                                                ✅ Hadir Sah
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-danger" style="font-size:11px; padding:3px 8px;">
                                                Belum Lengkap
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:12px 14px; text-align:center; vertical-align:middle;">
                                        <button type="button" class="btn btn-sm <?= $r['drive_verified'] ? 'btn-secondary' : 'btn-success' ?>" onclick="toggleSingleVerify(<?= (int)$r['id'] ?>, <?= $r['drive_verified'] ? 0 : 1 ?>)" style="padding:3px 8px; font-size:11px;">
                                            <?= $r['drive_verified'] ? 'Batal' : '✓ Verif' ?>
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

<!-- Form Tersembunyi untuk Verifikasi Satuan Admin -->
<form method="POST" id="form-single-verify" style="display:none;">
    <input type="hidden" name="action" value="verify_single">
    <input type="hidden" name="member_id" id="single-verify-member-id" value="">
    <input type="hidden" name="verify_status" id="single-verify-status" value="">
</form>

<script>
function toggleAdminSelectAll(master) {
    const boxes = document.querySelectorAll('.admin-checkbox');
    boxes.forEach(cb => cb.checked = master.checked);
    updateAdminSelectedCount();
}

function updateAdminSelectedCount() {
    const checked = document.querySelectorAll('.admin-checkbox:checked');
    const bar = document.getElementById('admin-batch-bar');
    const label = document.getElementById('admin-selected-count');

    if (checked.length > 0) {
        bar.style.display = 'flex';
        label.textContent = checked.length + ' Mahasiswa Terpilih';
    } else {
        bar.style.display = 'none';
        const master = document.getElementById('check-all-admin');
        if (master) master.checked = false;
    }
}

function toggleSingleVerify(memberId, newStatus) {
    document.getElementById('single-verify-member-id').value = memberId;
    document.getElementById('single-verify-status').value = newStatus;
    document.getElementById('form-single-verify').submit();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
