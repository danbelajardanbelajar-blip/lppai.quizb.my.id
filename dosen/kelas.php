<?php
/**
 * LPPAI Corner - Daftar Kelas Dosen
 */
if (!defined('PAGE_TITLE')) define('PAGE_TITLE', 'Kelas Anda');
require_once __DIR__ . '/../includes/auth.php';
requireDosen();

$user = getCurrentUser();
$pdo = getDBConnection();

$stmt = $pdo->prepare("SELECT * FROM tutorial_classes WHERE dosen_pengampu = ? AND (semester LIKE '%2025%' OR semester LIKE '%2026%' OR semester LIKE '%2027%' OR semester LIKE '%2028%' OR semester LIKE '%2029%' OR semester LIKE '%2030%') ORDER BY semester DESC, created_at DESC");
$stmt->execute([$user['nama_lengkap']]);
$kelasData = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">📋 Daftar Kelas Anda (<?= count($kelasData) ?>)</div>
    <div class="card-body">
        <?php if (empty($kelasData)): ?>
            <div class="empty-state" style="padding:40px 20px; text-align:center;">
                <div class="icon" style="font-size:48px; margin-bottom:16px;">🏫</div>
                <h3 style="margin-bottom:8px;">Belum Ada Kelas</h3>
                <p style="color:#64748b;">Anda belum dijadwalkan untuk mengajar kelas apapun.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Nama Kelas</th>
                            <th>Jadwal</th>
                            <th>Ruangan</th>
                            <th>Semester</th>
                            <th>Gelombang</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($kelasData as $kelas): ?>
                        <tr>
                            <td><strong><?= sanitize($kelas['nama_kelas']) ?></strong></td>
                            <td>
                                <span class="badge" style="background:#e0f2fe;color:#0284c7;">
                                    <?= sanitize($kelas['hari']) ?>, <?= sanitize($kelas['jam']) ?>
                                </span>
                            </td>
                            <td><?= sanitize($kelas['ruangan']) ?></td>
                            <td><?= sanitize($kelas['semester']) ?></td>
                            <td><span class="badge"><?= sanitize($kelas['gelombang']) ?></span></td>
                            <td>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <a href="<?= BASE_URL ?>/dosen/detail-kelas.php?id=<?= $kelas['id'] ?>" class="btn btn-sm btn-primary">Lihat & Kelola</a>
                                    <a href="<?= BASE_URL ?>/dosen/detail-kelas.php?id=<?= $kelas['id'] ?>&tab=rekap_absensi" class="btn btn-sm btn-outline" style="border-color:#10b981; color:#047857;" title="Rekap Kehadiran">📊 Rekap</a>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
