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

// Ambil hasil pretes resmi jika sudah disinkronkan oleh admin
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
$totalComponents = count($components);

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
        'is_good' => $isGood,
        'is_low' => $isLow,
        'is_cbt' => ($key === 'ujian_tulis')
    ];
}

// Hitung rata-rata dan status
$avgScore = ($filledCount > 0) ? round($sum / $filledCount, 2) : ($myResult['nilai'] ?? null);
$predikat = getPretesPredikat($avgScore);

// Tentukan status kelulusan
$rawStatus = $myResult['status_lulus'] ?? getPretesStatusLulus($scores);
if ($rawStatus === 'belum_diumumkan') {
    $rawStatus = 'menunggu';
}

$periodeDisplay = !empty($myReg['periode']) ? $myReg['periode'] : (!empty($myResult['schedule_periode']) ? $myResult['schedule_periode'] : 'Periode Aktif');

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
        <span style="font-weight:700; font-size:16px;">📊 Hasil & Nilai Keseluruhan Pretes Saya</span>
        <?php if ($filledCount > 0): ?>
            <span class="badge <?= $filledCount === $totalComponents ? 'badge-success' : 'badge-info' ?>" style="font-size:12px; padding:4px 10px;">
                <?= $filledCount ?> / <?= $totalComponents ?> Komponen Dinilai
            </span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (!$myResult && !$myReg && $filledCount === 0): ?>
            <div class="alert alert-info" style="margin-bottom:16px;">
                Anda belum memiliki hasil pretes. Pastikan Anda sudah mendaftar dan mengikuti rangkaian ujian pretes.
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
                        <p style="font-size:32px; font-weight:800; color:var(--primary); margin:2px 0 0 0; line-height:1.2;">
                            <?= $avgScore !== null ? number_format($avgScore, 1) : '-' ?>
                        </p>
                        <small style="color:var(--text-muted); display:block; margin-top:2px;">
                            Predikat: <strong><?= $predikat ?></strong> | Progres: <strong><?= $filledCount ?>/<?= $totalComponents ?></strong> komponen
                        </small>
                    </div>
                    <div>
                        <strong style="color:var(--text-muted); font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">Status Kelulusan</strong>
                        <p style="margin:6px 0 0 0;">
                            <?php if ($rawStatus === 'lulus'): ?>
                                <span class="badge badge-success" style="font-size:15px; padding:8px 18px; font-weight:700;">✅ LULUS</span>
                            <?php elseif ($rawStatus === 'tidak_lulus'): ?>
                                <span class="badge badge-danger" style="font-size:15px; padding:8px 18px; font-weight:700;">❌ BELUM LULUS</span>
                            <?php else: ?>
                                <span class="badge badge-warning" style="font-size:15px; padding:8px 18px; font-weight:700; background:#fef3c7; color:#92400e; border:1px solid #fde68a;">⏳ MENUNGGU LENGKAP</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <!-- Info Alert Sesuai Status -->
                <?php if ($rawStatus === 'lulus'): ?>
                    <div style="margin-top:18px; background:#ecfdf5; border:1.5px solid #a7f3d0; padding:14px 18px; border-radius:10px; color:#065f46; font-size:14px; line-height:1.5;">
                        🎉 <strong>Selamat, Anda Dinyatakan LULUS Pretes!</strong><br>
                        Seluruh 12 komponen penilaian pretes Anda telah memenuhi standar minimum (≥ 80). Anda dibebaskan dari kewajiban mengikuti program Tutorial LPPAI.
                    </div>
                <?php elseif ($rawStatus === 'tidak_lulus'): ?>
                    <div style="margin-top:18px; background:#fff1f2; border:1.5px solid #fecdd3; padding:14px 18px; border-radius:10px; color:#9f1239; font-size:14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; line-height:1.5;">
                        <div>
                            ⚠️ <strong>Pemberitahuan Kelulusan:</strong><br>
                            Anda belum memenuhi syarat kelulusan pretes (terdapat nilai komponen di bawah standar minimum 80). Anda diwajibkan mendaftar dan mengikuti program <strong>Tutorial LPPAI</strong> pada gelombang yang dibuka.
                        </div>
                        <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="btn btn-danger" style="text-decoration:none; white-space:nowrap; padding:9px 18px; font-weight:700;">
                            📝 Daftar Tutorial Sekarang ➔
                        </a>
                    </div>
                <?php else: ?>
                    <div style="margin-top:18px; background:#fffbeb; border:1.5px solid #fef3c7; padding:14px 18px; border-radius:10px; color:#92400e; font-size:13.5px; line-height:1.5;">
                        ⏳ <strong>Penilaian Sedang Berlangsung (<?= $filledCount ?>/<?= $totalComponents ?> Komponen Dinilai):</strong><br>
                        Hasil kelulusan resmi akan ditentukan setelah seluruh 12 komponen diujikan dan dinilai. Syarat kelulusan pretes adalah <strong>seluruh 12 komponen wajib bernilai minimal 80</strong>.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tabel Rincian Nilai Keseluruhan (12 Komponen) -->
            <div style="margin-top:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                    <h3 style="margin:0; font-size:16px; font-weight:700; color:#1e293b;">
                        📋 Rincian Nilai Keseluruhan (12 Komponen Pretes)
                    </h3>
                    <small style="color:var(--text-muted); font-size:12.5px;">
                        📌 Standar Lulus: Skor <strong>≥ 80</strong> untuk setiap komponen
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
                                <th style="width:160px; text-align:center; padding:10px;">Status Nilai</th>
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
                                        <span style="color:#94a3b8; font-style:italic;">Belum Ada</span>
                                    <?php endif; ?>
                                </td>
                                <td align="center" style="padding:10px;">
                                    <?php if ($detail['value'] === null): ?>
                                        <span class="badge" style="background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; font-size:11.5px;">⏳ Belum Dinilai</span>
                                    <?php elseif ($detail['is_good']): ?>
                                        <span class="badge badge-success" style="font-size:11.5px;">✅ Memenuhi (≥80)</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger" style="font-size:11.5px;">❌ Belum Memenuhi (&lt;80)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:#f8fafc; border-top:2px solid #e2e8f0; font-weight:700;">
                                <td colspan="4" style="text-align:right; padding:14px; font-size:14px; color:#1e293b;">
                                    RATA-RATA NILAI KESELURUHAN:
                                </td>
                                <td align="center" style="padding:14px; font-size:18px; color:var(--primary); font-weight:800;">
                                    <?= $avgScore !== null ? number_format($avgScore, 1) : '-' ?>
                                </td>
                                <td align="center" style="padding:14px;">
                                    <?php if ($rawStatus === 'lulus'): ?>
                                        <span class="badge badge-success" style="font-size:12.5px; padding:5px 12px;">✅ LULUS</span>
                                    <?php elseif ($rawStatus === 'tidak_lulus'): ?>
                                        <span class="badge badge-danger" style="font-size:12.5px; padding:5px 12px;">❌ TIDAK LULUS</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; font-size:12px; padding:5px 10px;">⏳ <?= $filledCount ?>/<?= $totalComponents ?> Terisi</span>
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
                <?php if ($rawStatus === 'tidak_lulus'): ?>
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
                        <td><strong><?= $r['nilai'] !== null ? number_format($r['nilai'], 1) : '-' ?></strong></td>
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
