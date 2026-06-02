<?php
/**
 * LPPAI Corner - Hasil Pretes
 */
define('PAGE_TITLE', 'Pengumuman Hasil Pretes');
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = getCurrentUser();
$pdo = getDBConnection();

// Get user's pretes result
$stmt = $pdo->prepare("
    SELECT pr.*, ps.tanggal, ps.ruangan, ps.periode
    FROM pretes_results pr
    LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
    WHERE pr.user_id = ?
    ORDER BY pr.created_at DESC
");
$stmt->execute([$user['id']]);
$myResults = $stmt->fetchAll();

// Get all published results (public announcement)
$allResults = $pdo->query("
    SELECT pr.*, u.nama_lengkap, u.nim, u.program_studi
    FROM pretes_results pr
    JOIN users u ON pr.user_id = u.id
    WHERE pr.status_lulus != 'belum_diumumkan'
    ORDER BY pr.nilai DESC
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- My Result -->
<div class="card">
    <div class="card-header">📊 Hasil Pretes Saya</div>
    <div class="card-body">
        <?php if (empty($myResults)): ?>
            <div class="alert alert-info">
                Anda belum memiliki hasil pretes. Pastikan Anda sudah mendaftar dan mengikuti pretes.
            </div>
        <?php else: ?>
            <?php foreach ($myResults as $result): ?>
            <div style="background:var(--bg-light);border-radius:var(--radius);padding:24px;margin-bottom:16px;">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
                    <div>
                        <strong style="color:var(--text-muted);font-size:12px;">PERIODE</strong>
                        <p style="font-size:16px;font-weight:600;"><?= sanitize($result['periode'] ?? '-') ?></p>
                    </div>
                    <div>
                        <strong style="color:var(--text-muted);font-size:12px;">NILAI</strong>
                        <p style="font-size:28px;font-weight:700;color:var(--primary);">
                            <?= $result['nilai'] !== null ? number_format($result['nilai'], 1) : 'Belum Keluar' ?>
                        </p>
                    </div>
                    <div>
                        <strong style="color:var(--text-muted);font-size:12px;">STATUS</strong>
                        <p>
                            <?php if ($result['status_lulus'] === 'lulus'): ?>
                                <span class="badge badge-success" style="font-size:16px;padding:8px 20px;">✅ LULUS</span>
                            <?php elseif ($result['status_lulus'] === 'tidak_lulus'): ?>
                                <span class="badge badge-danger" style="font-size:16px;padding:8px 20px;">❌ BELUM LULUS</span>
                            <?php else: ?>
                                <span class="badge badge-warning" style="font-size:16px;padding:8px 20px;">⏳ MENUNGGU</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <?php if ($result['keterangan']): ?>
                <div style="margin-top:16px;padding-top:16px;border-top:1px solid #e0e0e0;">
                    <strong>Keterangan:</strong> <?= sanitize($result['keterangan']) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- All Results -->
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

<?php include __DIR__ . '/includes/footer.php'; ?>
