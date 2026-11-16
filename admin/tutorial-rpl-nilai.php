<?php
/**
 * LPPAI Corner - Admin: Rekapitulasi Nilai & Kelulusan Tutorial RPL Online
 */
define('PAGE_TITLE', 'Rekapitulasi Nilai Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
requireAdmin();

$pdo = getDBConnection();
ensureTutorialRplTables($pdo);

$selectedClassId = (int)($_GET['class_id'] ?? 0);
$classes = tutorialRplGetClasses($pdo);

$query = "
    SELECT m.*,
           u.nama_lengkap, u.nim, u.program_studi, u.fakultas,
           c.nama_kelompok, c.dosen_nama
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

// Export CSV jika diminta
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=rekap_nilai_tutorial_rpl_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['No', 'Nama Mahasiswa', 'NIM', 'Program Studi', 'Kelompok', 'Tutor Pengampu', 'Nilai CBT Tutorial', 'Nilai Praktik Hafalan', 'Nilai Akhir', 'Status Kelulusan']);
    foreach ($rows as $i => $r) {
        fputcsv($out, [
            $i + 1,
            $r['nama_lengkap'],
            $r['nim'],
            $r['program_studi'],
            $r['nama_kelompok'],
            $r['dosen_nama'] ?: '-',
            $r['nilai_ujian_tulis'] !== null ? $r['nilai_ujian_tulis'] : '-',
            $r['nilai_praktik'] !== null ? $r['nilai_praktik'] : '-',
            $r['nilai_akhir'] !== null ? $r['nilai_akhir'] : '-',
            ucfirst($r['status_lulus'] ?? 'proses')
        ]);
    }
    fclose($out);
    exit;
}

include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0; font-size:22px; font-weight:800; color:var(--text-dark);">
            🎓 Rekapitulasi Nilai &amp; Kelulusan Tutorial RPL
        </h2>
        <p style="margin:0; font-size:13.5px; color:#64748b;">
            Penggabungan nilai tugas evaluasi praktik hafalan dan ujian tulis CBT untuk mahasiswa program RPL.
        </p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="?export=csv<?= $selectedClassId > 0 ? '&class_id=' . $selectedClassId : '' ?>" class="btn btn-success" style="display:inline-flex; align-items:center; gap:6px;">
            <span>📥</span> Ekspor CSV
        </a>
    </div>
</div>

<!-- Filter Kelompok -->
<div class="card" style="margin-bottom:20px; border-radius:12px;">
    <div class="card-body" style="padding:16px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <label style="font-size:13.5px; font-weight:600; color:#334155;">Pilih Kelompok:</label>
            <select class="form-control" onchange="location.href='?class_id=' + this.value;" style="min-width:240px;">
                <option value="0">-- Semua Kelompok (<?= count($rows) ?> Mahasiswa) --</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $selectedClassId === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= sanitize($c['nama_kelompok']) ?> (Tutor: <?= sanitize($c['dosen_nama'] ?: '-') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <span class="badge badge-primary" style="font-size:12px;">
                Passing Grade Minimal: 80.0
            </span>
        </div>
    </div>
</div>

<!-- Tabel Rekapitulasi Nilai -->
<div class="card" style="box-shadow:var(--shadow); border-radius:12px; overflow:hidden;">
    <div class="card-header" style="background:#f8fafc; font-weight:700; color:var(--text-dark);">
        📋 Rekap Hasil Penilaian Tutorial RPL
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($rows)): ?>
            <div style="text-align:center; padding:40px 20px; color:#64748b;">
                <div style="font-size:36px; margin-bottom:8px;">🎓</div>
                <h4 style="margin:0 0 4px 0; color:#334155;">Belum Ada Data Penilaian</h4>
                <p style="margin:0; font-size:13.5px;">Plot peserta ke kelompok RPL untuk memulai proses pembelajaran dan penilaian.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" style="margin:0; width:100%;">
                    <thead>
                        <tr style="background:#f1f5f9; text-align:left; font-size:12.5px; color:#475569;">
                            <th style="padding:12px 16px; width:40px;">No</th>
                            <th style="padding:12px 16px;">Mahasiswa</th>
                            <th style="padding:12px 16px;">Kelompok / Tutor</th>
                            <th style="padding:12px 16px; text-align:center;">Nilai CBT Tutorial</th>
                            <th style="padding:12px 16px; text-align:center;">Nilai Praktik Hafalan</th>
                            <th style="padding:12px 16px; text-align:center;">Nilai Akhir</th>
                            <th style="padding:12px 16px; text-align:center;">Status Kelulusan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $idx => $r): ?>
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
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle; font-weight:600;">
                                    <?= $r['nilai_ujian_tulis'] !== null ? (float)$r['nilai_ujian_tulis'] : '<span style="color:#94a3b8;">-</span>' ?>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle; font-weight:600;">
                                    <?= $r['nilai_praktik'] !== null ? (float)$r['nilai_praktik'] : '<span style="color:#94a3b8;">-</span>' ?>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                    <?php if ($r['nilai_akhir'] !== null): ?>
                                        <strong style="color:<?= $r['nilai_akhir'] >= 80 ? '#15803d' : '#b91c1c' ?>; font-size:15px;">
                                            <?= (float)$r['nilai_akhir'] ?>
                                        </strong>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                    <?php if ($r['status_lulus'] === 'lulus'): ?>
                                        <span class="badge badge-emerald" style="font-size:12px; font-weight:700; padding:4px 10px;">LULUS</span>
                                    <?php elseif ($r['status_lulus'] === 'tidak_lulus'): ?>
                                        <span class="badge badge-danger" style="font-size:12px; font-weight:700; padding:4px 10px;">BELUM LULUS</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" style="font-size:11px; padding:4px 8px;">Dalam Proses</span>
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
