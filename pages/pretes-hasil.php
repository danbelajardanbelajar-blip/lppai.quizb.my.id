<?php
/**
 * LPPAI Corner - Hasil Pretes
 */
define('PAGE_TITLE', 'Pengumuman Hasil Pretes');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pretes-helper.php';
requireLogin();

$user = getCurrentUser();
$pdo = getDBConnection();

// Pastikan fitur pretes siap
ensurePretesFeatures($pdo);

// Ambil data pendaftaran & asesmen nilai rinci terbaru mahasiswa
$stmtReg = $pdo->prepare("
    SELECT pr.*, pa.scores_json, pa.updated_at as assessed_at,
           ps.tanggal as jadwal_tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan as jadwal_ruangan
    FROM pretes_registrations pr
    LEFT JOIN pretes_assessments pa ON pa.registration_id = pr.id
    LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
    WHERE pr.user_id = ?
    ORDER BY pr.id DESC
    LIMIT 1
");
$stmtReg->execute([$user['id']]);
$myReg = $stmtReg->fetch();

// Ambil hasil pretes resmi jika ada di database
$stmtRes = $pdo->prepare("
    SELECT pr.*, ps.tanggal, ps.ruangan, ps.periode as schedule_periode
    FROM pretes_results pr
    LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
    WHERE pr.user_id = ?
    ORDER BY pr.created_at DESC
    LIMIT 1
");
$stmtRes->execute([$user['id']]);
$myResult = $stmtRes->fetch();

// Parsing nilai rinci 12 komponen
$scores = [];
if (!empty($myReg['scores_json'])) {
    $scores = json_decode($myReg['scores_json'], true) ?: [];
}

$components = pretesComponents();
$componentDetails = [];
$sum = 0;
$filledCount = 0;
$totalComponents = count($components); // Tepat 12 komponen wajib

foreach ($components as $key => $label) {
    $val = (isset($scores[$key]) && $scores[$key] !== '' && is_numeric($scores[$key])) ? (float)$scores[$key] : null;
    $isGood = ($val !== null && $val >= 80);
    $isLow = ($val !== null && $val < 80);
    
    if ($val !== null) {
        $sum += $val;
        $filledCount++;
    }
    
    $componentDetails[$key] = [
        'label' => $label,
        'value' => $val,
        'effective_score' => ($val !== null) ? $val : 0.0,
        'is_good' => $isGood,
        'is_low' => $isLow,
        'is_cbt' => ($key === 'ujian_tulis')
    ];
}

// ── Perhitungan Rata-Rata Keseluruhan ─────────────────────────────────────────
// Karena seluruh 12 komponen bersifat WAJIB dan periode pretes telah selesai,
// maka nilai rata-rata adalah pembagian dari total 12 komponen (komponen kosong bernilai 0).
if ($filledCount > 0) {
    $avgScore = round($sum / $totalComponents, 2);
} elseif ($myResult && $myResult['nilai'] !== null) {
    // Jika tidak ada asesmen JSON tetapi ada record pretes_results lama
    $avgScore = round((float)$myResult['nilai'], 2);
} else {
    $avgScore = 0.00;
}

$predikat = getPretesPredikat($avgScore);

// ── Status Kelulusan (Hanya LULUS atau TIDAK LULUS) ──────────────────────────
// Karena periode pretes sudah selesai, status hanya LULUS atau TIDAK LULUS (tidak ada menunggu).
// Syarat LULUS: seluruh 12 komponen wajib terisi dan seluruhnya bernilai minimal 80.
$isLulus = ($filledCount === $totalComponents);
if ($isLulus) {
    foreach ($componentDetails as $ck => $detail) {
        if ($detail['value'] === null || $detail['value'] < 80) {
            $isLulus = false;
            break;
        }
    }
}
$statusLulus = $isLulus ? 'lulus' : 'tidak_lulus';

$periodeDisplay = !empty($myReg['periode']) ? $myReg['periode'] : (!empty($myResult['schedule_periode']) ? $myResult['schedule_periode'] : 'Pretes 2026/2027');

// Get all published results (public announcement) - hanya dimuat untuk Admin dan Dosen
$allResults = [];
if (isAdmin() || isDosen()) {
    $allResults = $pdo->query("
        SELECT pr.*, u.nama_lengkap, u.nim, u.program_studi
        FROM pretes_results pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.status_lulus != 'belum_diumumkan'
        ORDER BY pr.nilai DESC
    ")->fetchAll();
}

include __DIR__ . '/../includes/header.php';
?>

<!-- Hasil & Nilai Keseluruhan Pretes Saya -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <span style="font-weight:700; font-size:16px;">📊 Pengumuman Hasil Kelulusan Pretes</span>
        <span class="badge <?= $statusLulus === 'lulus' ? 'badge-success' : 'badge-danger' ?>" style="font-size:12.5px; padding:5px 12px; font-weight:700;">
            Status Akhir: <?= $statusLulus === 'lulus' ? 'LULUS' : 'TIDAK LULUS' ?>
        </span>
    </div>
    <div class="card-body">
        <?php if (!$myResult && !$myReg && $filledCount === 0): ?>
            <div class="alert alert-info" style="margin-bottom:16px;">
                Anda belum memiliki riwayat pretes. Silakan mendaftar pada periode pretes berikutnya atau hubungi LPPAI.
            </div>
            <div>
                <a href="<?= BASE_URL ?>/pretes-daftar.php" class="btn btn-primary" style="text-decoration:none;">📝 Daftar Pretes Sekarang</a>
                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary" style="text-decoration:none; margin-left:8px;">🏠 Kembali ke Dashboard</a>
            </div>
        <?php else: ?>
            <!-- Box Ringkasan Nilai Keseluruhan -->
            <div style="background:var(--bg-light); border-radius:12px; padding:24px; margin-bottom:20px; border:1px solid #e2e8f0;">
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:20px; align-items:center;">
                    <div>
                        <strong style="color:var(--text-muted); font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">Periode Pretes</strong>
                        <p style="font-size:18px; font-weight:700; color:#1e293b; margin:4px 0 0 0;"><?= sanitize($periodeDisplay) ?></p>
                        <?php if (!empty($myReg['hari_pilihan']) || !empty($myReg['ruangan'])): ?>
                            <small style="color:var(--text-muted); display:block; margin-top:2px;">
                                <?= sanitize($myReg['hari_pilihan'] ?: 'Reguler') ?> <?= !empty($myReg['ruangan']) ? ' - Ruang ' . sanitize($myReg['ruangan']) : '' ?>
                            </small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong style="color:var(--text-muted); font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">Nilai Rata-rata Keseluruhan</strong>
                        <p style="font-size:32px; font-weight:800; color:<?= $statusLulus === 'lulus' ? '#16a34a' : '#dc2626' ?>; margin:2px 0 0 0; line-height:1.2;">
                            <?= number_format($avgScore, 2) ?>
                        </p>
                        <small style="color:var(--text-muted); display:block; margin-top:2px;">
                            Predikat: <strong><?= $predikat ?></strong> | Total: <strong><?= number_format($sum, 1) ?> ÷ <?= $totalComponents ?> komponen wajib</strong>
                        </small>
                    </div>
                    <div>
                        <strong style="color:var(--text-muted); font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">Status Kelulusan</strong>
                        <p style="margin:6px 0 0 0;">
                            <?php if ($statusLulus === 'lulus'): ?>
                                <span class="badge badge-success" style="font-size:16px; padding:8px 20px; font-weight:700;">✅ LULUS</span>
                            <?php else: ?>
                                <span class="badge badge-danger" style="font-size:16px; padding:8px 20px; font-weight:700;">❌ TIDAK LULUS</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <!-- Info Alert Sesuai Status Kelulusan -->
                <?php if ($statusLulus === 'lulus'): ?>
                    <div style="margin-top:18px; background:#ecfdf5; border:1.5px solid #a7f3d0; padding:16px 20px; border-radius:10px; color:#065f46; font-size:14px; line-height:1.6;">
                        🎉 <strong>Selamat, Anda Dinyatakan LULUS Pretes!</strong><br>
                        Seluruh 12 komponen penilaian pretes Anda telah terpenuhi dengan nilai masing-masing minimal <strong>80</strong> (rata-rata keseluruhan: <strong><?= number_format($avgScore, 2) ?></strong>). Anda secara resmi dibebaskan dari kewajiban mengikuti program Tutorial LPPAI.
                    </div>
                <?php else: ?>
                    <div style="margin-top:18px; background:#fff1f2; border:1.5px solid #fecdd3; padding:16px 20px; border-radius:10px; color:#9f1239; font-size:14px; line-height:1.6; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
                        <div>
                            <strong style="font-size:15px; display:block; margin-bottom:4px;">❌ PEMBERITAHUAN: ANDA DINYATAKAN TIDAK LULUS PRETES</strong>
                            Periode pelaksanaan pretes telah selesai. Berdasarkan hasil evaluasi, Anda memperoleh nilai rata-rata keseluruhan <strong><?= number_format($avgScore, 2) ?></strong> (terpenuhi <strong><?= $filledCount ?> dari <?= $totalComponents ?></strong> komponen wajib). Sesuai standar kelulusan LPPAI, setiap peserta wajib menyelesaikan seluruh 12 komponen dengan skor minimal 80 untuk masing-masing komponen.<br>
                            Oleh karena itu, Anda <strong>diwajibkan untuk mendaftar dan mengikuti program Tutorial LPPAI</strong>.
                        </div>
                        <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="btn btn-danger" style="text-decoration:none; white-space:nowrap; padding:10px 20px; font-weight:700; font-size:14px; box-shadow:0 2px 6px rgba(220,38,38,0.25);">
                            📝 Daftar Tutorial Sekarang ➔
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tabel Rincian Nilai Keseluruhan (12 Komponen) -->
            <div style="margin-top:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                    <h3 style="margin:0; font-size:16px; font-weight:700; color:#1e293b;">
                        📋 Rincian Nilai Keseluruhan (12 Komponen Pretes Wajib)
                    </h3>
                    <small style="color:var(--text-muted); font-size:12.5px;">
                        📌 Standar Lulus: Seluruh 12 komponen wajib bernilai minimal <strong>80</strong>
                    </small>
                </div>
                
                <div class="table-responsive">
                    <table style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                                <th style="width:45px; text-align:center; padding:10px;">No</th>
                                <th style="padding:10px;">Materi / Komponen Ujian</th>
                                <th style="width:170px; text-align:center; padding:10px;">Bentuk Ujian</th>
                                <th style="width:120px; text-align:center; padding:10px;">Standar Min.</th>
                                <th style="width:120px; text-align:center; padding:10px;">Nilai Anda</th>
                                <th style="width:170px; text-align:center; padding:10px;">Status Komponen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = 1;
                            foreach ($componentDetails as $ck => $detail): 
                            ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td align="center" style="padding:10px; font-weight:600; color:#64748b;"><?= $no++ ?></td>
                                <td style="padding:10px; font-weight:600; color:#1e293b;">
                                    <?= sanitize($detail['label']) ?>
                                </td>
                                <td align="center" style="padding:10px;">
                                    <?php if ($detail['is_cbt']): ?>
                                        <span class="badge" style="background:#e0e7ff; color:#3730a3; font-size:11.5px; font-weight:600;">💻 CBT (Ujian Tulis)</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#f1f5f9; color:#475569; font-size:11.5px; font-weight:600;">🗣️ Lisan / Praktik</span>
                                    <?php endif; ?>
                                </td>
                                <td align="center" style="padding:10px; font-weight:600; color:#475569;">
                                    80
                                </td>
                                <td align="center" style="padding:10px;">
                                    <?php if ($detail['value'] !== null): ?>
                                        <strong style="font-size:15px; color: <?= $detail['is_good'] ? '#16a34a' : '#dc2626' ?>;">
                                            <?= number_format($detail['value'], 1) ?>
                                        </strong>
                                    <?php else: ?>
                                        <strong style="font-size:14px; color:#dc2626;">0.0</strong>
                                        <small style="color:#94a3b8; display:block; font-size:11px;">(Kosong)</small>
                                    <?php endif; ?>
                                </td>
                                <td align="center" style="padding:10px;">
                                    <?php if ($detail['is_good']): ?>
                                        <span class="badge badge-success" style="font-size:11.5px;">✅ Memenuhi (≥80)</span>
                                    <?php elseif ($detail['value'] !== null): ?>
                                        <span class="badge badge-danger" style="font-size:11.5px;">❌ Belum Memenuhi (&lt;80)</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger" style="font-size:11.5px;">❌ Tidak Terpenuhi (0)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:#f8fafc; border-top:2px solid #e2e8f0; font-weight:700;">
                                <td colspan="4" style="text-align:right; padding:14px; font-size:14px; color:#1e293b;">
                                    RATA-RATA NILAI KESELURUHAN (Total Nilai: <?= number_format($sum, 1) ?> ÷ <?= $totalComponents ?> Komponen):
                                </td>
                                <td align="center" style="padding:14px; font-size:18px; color:<?= $statusLulus === 'lulus' ? '#16a34a' : '#dc2626' ?>; font-weight:800;">
                                    <?= number_format($avgScore, 2) ?>
                                </td>
                                <td align="center" style="padding:14px;">
                                    <?php if ($statusLulus === 'lulus'): ?>
                                        <span class="badge badge-success" style="font-size:13px; padding:6px 14px;">✅ LULUS</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger" style="font-size:13px; padding:6px 14px;">❌ TIDAK LULUS</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Tombol Navigasi -->
            <div style="margin-top:24px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary" style="text-decoration:none;">🏠 Kembali ke Dashboard</a>
                <?php if ($statusLulus === 'tidak_lulus'): ?>
                    <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="btn btn-primary" style="text-decoration:none;">📝 Daftar Program Tutorial</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (isAdmin() || isDosen()): ?>
<!-- All Results (Hanya untuk Admin / Dosen) -->
<div class="card">
    <div class="card-header">📋 Daftar Hasil Pretes (Semua Peserta)</div>
    <div class="card-body">
        <?php if (empty($allResults)): ?>
            <div class="empty-state">
                <div class="icon">📋</div>
                <h3>Hasil pretes belum diumumkan</h3>
                <p>Silakan cek kembali nanti.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>NIM</th>
                        <th>Program Studi</th>
                        <th>Nilai</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allResults as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= sanitize($r['nama_lengkap']) ?></td>
                        <td><?= sanitize($r['nim']) ?></td>
                        <td><?= sanitize($r['program_studi']) ?></td>
                        <td><strong><?= $r['nilai'] !== null ? number_format($r['nilai'], 2) : '-' ?></strong></td>
                        <td>
                            <?php if ($r['status_lulus'] === 'lulus'): ?>
                                <span class="badge badge-success">Lulus</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Tidak Lulus</span>
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
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
