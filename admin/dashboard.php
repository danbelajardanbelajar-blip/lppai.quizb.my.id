<?php
/**
 * LPPAI Corner - Admin Dashboard
 */
define('PAGE_TITLE', 'Dashboard Admin');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();

// Stats
$totalMahasiswa = $pdo->query("SELECT COUNT(*) FROM users WHERE role='mahasiswa'")->fetchColumn();
$totalPretes = $pdo->query("SELECT COUNT(*) FROM pretes_registrations WHERE (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%')")->fetchColumn();
$totalTutorial = $pdo->query("SELECT COUNT(*) FROM tutorial_registrations WHERE (tahun_ajaran LIKE '%2026%' OR tahun_ajaran LIKE '%2027%' OR tahun_ajaran LIKE '%2028%' OR tahun_ajaran LIKE '%2029%' OR tahun_ajaran LIKE '%2030%')")->fetchColumn();
$totalKelas = $pdo->query("SELECT COUNT(*) FROM tutorial_classes")->fetchColumn();
$totalPengumuman = $pdo->query("SELECT COUNT(*) FROM announcements WHERE is_active=1")->fetchColumn();
$lulusPretes = $pdo->query("SELECT COUNT(*) FROM pretes_results WHERE status_lulus='lulus'")->fetchColumn();

// Recent registrations
$recentRegs = $pdo->query("
    SELECT pr.*, u.nama_lengkap, u.nim
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    ORDER BY pr.tanggal_daftar DESC LIMIT 10
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon green">👥</div>
        <div class="stat-info">
            <h3><?= $totalMahasiswa ?></h3>
            <p>Total Mahasiswa</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">✍️</div>
        <div class="stat-info">
            <h3><?= $totalPretes ?></h3>
            <p>Pendaftar Pretes</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">🎓</div>
        <div class="stat-info">
            <h3><?= $lulusPretes ?></h3>
            <p>Lulus Pretes</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">📚</div>
        <div class="stat-info">
            <h3><?= $totalTutorial ?></h3>
            <p>Peserta Tutorial</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">🏫</div>
        <div class="stat-info">
            <h3><?= $totalKelas ?></h3>
            <p>Kelas Tutorial</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">📢</div>
        <div class="stat-info">
            <h3><?= $totalPengumuman ?></h3>
            <p>Pengumuman Aktif</p>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="card">
    <div class="card-header">⚡ Aksi Cepat</div>
    <div class="card-body" style="display:flex;gap:12px;flex-wrap:wrap;">
        <a href="<?= BASE_URL ?>/admin/pengumuman.php" class="btn btn-primary" style="width:auto;">📢 Kelola Pengumuman</a>
        <a href="<?= BASE_URL ?>/admin/pretes-jadwal.php" class="btn btn-success" style="width:auto;">📅 Kelola Jadwal Pretes</a>
        <a href="<?= BASE_URL ?>/admin/pretes-hasil.php" class="btn btn-warning" style="width:auto;">📝 Input Hasil Pretes</a>
        <a href="<?= BASE_URL ?>/admin/tutorial-kelas.php" class="btn btn-secondary" style="width:auto;">🏫 Kelola Kelas</a>
        <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-secondary" style="width:auto;">👤 Kelola User</a>
    </div>
</div>

<!-- Recent Pretes Registrations -->
<div class="card">
    <div class="card-header">📋 Pendaftaran Pretes Terbaru</div>
    <div class="card-body">
        <?php if (empty($recentRegs)): ?>
            <div class="empty-state">
                <div class="icon">📋</div>
                <h3>Belum ada pendaftaran</h3>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>NIM</th>
                        <th>Periode</th>
                        <th>Tgl Daftar</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentRegs as $r): ?>
                    <tr>
                        <td><?= sanitize($r['nama_lengkap']) ?></td>
                        <td><?= sanitize($r['nim']) ?></td>
                        <td><?= sanitize($r['periode']) ?></td>
                        <td><?= date('d M Y H:i', strtotime($r['tanggal_daftar'])) ?></td>
                        <td><span class="badge badge-success"><?= ucfirst($r['status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
