<?php
/**
 * LPPAI Corner - Admin: Monitoring Verifikasi Tugas & Presensi Tutorial RPL Online
 */
define('PAGE_TITLE', 'Verifikasi Tugas & Presensi RPL Online');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
requireAdmin();

$pdo = getDBConnection();
ensureTutorialRplTables($pdo);

$selectedClassId = (int)($_GET['class_id'] ?? 0);
$classes = tutorialRplGetClasses($pdo);

$query = "
    SELECT m.*,
           u.nama_lengkap, u.nim, u.whatsapp, u.program_studi,
           c.nama_kelompok, c.dosen_nama, c.drive_url
    FROM tutorial_rpl_members m
    JOIN users u ON m.user_id = u.id
    JOIN tutorial_rpl_classes c ON m.class_id = c.id
";
$params = [];
if ($selectedClassId > 0) {
    $query .= " WHERE m.class_id = ? ";
    $params[] = $selectedClassId;
}
$query .= " ORDER BY c.nama_kelompok ASC, u.nama_lengkap ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0; font-size:22px; font-weight:800; color:var(--text-dark);">
            ✅ Verifikasi Tugas & Presensi RPL Online
        </h2>
        <p style="margin:0; font-size:13.5px; color:#64748b;">
            Monitoring kehadiran daring berdasarkan validasi tugas video hafalan Google Drive dan penyelesaian Ujian Tulis Tutorial.
        </p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="<?= BASE_URL ?>/admin/tutorial-rpl-kelas.php" class="btn btn-secondary">
            <span>🏫</span> Kelola Kelompok
        </a>
    </div>
</div>

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
            <h3 style="color:#15803d;"><?= $hadirLengkap ?></h3>
            <p>Presensi Lengkap Sah</p>
        </div>
    </div>
</div>

<!-- Filter Kelompok -->
<div class="card" style="margin-bottom:20px; border-radius:12px;">
    <div class="card-body" style="padding:16px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <label style="font-size:13.5px; font-weight:600; color:#334155;">Pilih Kelompok:</label>
            <select class="form-control" onchange="location.href='?class_id=' + this.value;" style="min-width:240px;">
                <option value="0">-- Semua Kelompok (<?= $totalPeserta ?> Peserta) --</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $selectedClassId === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= sanitize($c['nama_kelompok']) ?> (Tutor: <?= sanitize($c['dosen_nama'] ?: '-') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="font-size:13px; color:#64748b;">
            💡 <em>Presensi dihitung sah (Hadir) jika kedua syarat terpenuhi (Tugas diverifikasi &amp; CBT selesai).</em>
        </div>
    </div>
</div>

<!-- Tabel Monitoring Presensi -->
<div class="card" style="box-shadow:var(--shadow); border-radius:12px; overflow:hidden;">
    <div class="card-header" style="background:#f8fafc; font-weight:700; color:var(--text-dark);">
        📊 Matriks Presensi &amp; Tugas Mahasiswa RPL
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($rows)): ?>
            <div style="text-align:center; padding:40px 20px; color:#64748b;">
                <div style="font-size:36px; margin-bottom:8px;">📋</div>
                <h4 style="margin:0 0 4px 0; color:#334155;">Belum Ada Data Peserta Terplot</h4>
                <p style="margin:0; font-size:13.5px;">Silakan plot peserta ke kelompok terlebih dahulu di menu Data Peserta &amp; Plotting.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" style="margin:0; width:100%;">
                    <thead>
                        <tr style="background:#f1f5f9; text-align:left; font-size:12.5px; color:#475569;">
                            <th style="padding:12px 16px; width:40px;">No</th>
                            <th style="padding:12px 16px;">Mahasiswa</th>
                            <th style="padding:12px 16px;">Kelompok / Tutor</th>
                            <th style="padding:12px 16px; text-align:center;">Tugas Google Drive</th>
                            <th style="padding:12px 16px; text-align:center;">Ujian Tulis Tutorial</th>
                            <th style="padding:12px 16px; text-align:center;">Status Presensi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $idx => $r): ?>
                            <?php $isHadir = (!empty($r['drive_verified']) && !empty($r['exam_submitted'])); ?>
                            <tr style="border-bottom:1px solid #e2e8f0; font-size:13.5px;">
                                <td style="padding:14px 16px; color:#64748b; vertical-align:middle;"><?= $idx + 1 ?></td>
                                <td style="padding:14px 16px; vertical-align:middle;">
                                    <div style="font-weight:700; color:#0f172a;"><?= sanitize($r['nama_lengkap']) ?></div>
                                    <small style="color:#64748b;">NIM: <?= sanitize($r['nim'] ?: '-') ?> · <?= sanitize($r['program_studi'] ?: '') ?></small>
                                </td>
                                <td style="padding:14px 16px; vertical-align:middle;">
                                    <div style="font-weight:600; color:#0369a1;"><?= sanitize($r['nama_kelompok']) ?></div>
                                    <small style="color:#64748b;">👨‍🏫 <?= sanitize($r['dosen_nama'] ?: '-') ?></small>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                    <?php if ($r['drive_verified']): ?>
                                        <span class="badge badge-emerald" style="font-size:11.5px; padding:4px 8px;">✅ Terverifikasi Tutor</span>
                                    <?php elseif ($r['drive_submitted']): ?>
                                        <span class="badge badge-info" style="font-size:11.5px; padding:4px 8px;">⏳ Menunggu Verifikasi</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning" style="font-size:11.5px; padding:4px 8px;">Belum Diunggah</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                    <?php if ($r['exam_submitted']): ?>
                                        <span class="badge badge-emerald" style="font-size:11.5px; padding:4px 8px;">
                                            ✅ Selesai (<?= $r['nilai_ujian_tulis'] !== null ? (float)$r['nilai_ujian_tulis'] : '-' ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" style="font-size:11.5px; padding:4px 8px;">Belum Ujian</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                    <?php if ($isHadir): ?>
                                        <span class="badge badge-emerald" style="font-size:12px; font-weight:700; padding:6px 12px;">
                                            ✅ Hadir Lengkap
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-danger" style="font-size:11.5px; padding:5px 10px;">
                                            Belum Lengkap
                                        </span>
                                    <?php endif; ?>
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
