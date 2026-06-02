<?php
/**
 * LPPAI Corner - Dashboard Mahasiswa
 */
define('PAGE_TITLE', 'Dashboard Mahasiswa');
require_once __DIR__ . '/includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$user = getCurrentUser();
$pdo = getDBConnection();

// Get pretes status
$stmt = $pdo->prepare("SELECT pr.*, ps.tanggal, ps.ruangan FROM pretes_results pr LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id WHERE pr.user_id = ? ORDER BY pr.created_at DESC LIMIT 1");
$stmt->execute([$user['id']]);
$pretesResult = $stmt->fetch();

// Get active registrations
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pretes_registrations WHERE user_id = ?");
$stmt->execute([$user['id']]);
$pretesRegistered = $stmt->fetchColumn();

// Get tutorial registrations
$stmt = $pdo->prepare("SELECT tr.*, tc.nama_kelas, tc.mata_kuliah, tc.gelombang, tc.hari, tc.jam, tc.ruangan FROM tutorial_registrations tr JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id WHERE tr.user_id = ? ORDER BY tr.created_at DESC");
$stmt->execute([$user['id']]);
$tutorialRegs = $stmt->fetchAll();

// Get recent announcements
$stmt = $pdo->query("SELECT * FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 5");
$recentAnnouncements = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Welcome Card -->
<div class="card" style="border-left: 4px solid var(--primary); margin-bottom: 24px;">
    <div class="card-body" style="display:flex;align-items:center;gap:20px;">
        <div class="stat-icon green" style="width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;">
            👋
        </div>
        <div>
            <h2 style="font-size:22px;margin-bottom:4px;">Assalamu'alaikum, <?= sanitize($user['nama_lengkap']) ?>!</h2>
            <p style="color:var(--text-muted);font-size:14px;">
                NIM: <?= sanitize($user['nim']) ?> | <?= sanitize($user['program_studi']) ?> - <?= sanitize($user['fakultas']) ?>
            </p>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon green">✍️</div>
        <div class="stat-info">
            <h3><?= $pretesRegistered ?></h3>
            <p>Pretes Terdaftar</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">📝</div>
        <div class="stat-info">
            <h3><?= $pretesResult ? ($pretesResult['status_lulus'] === 'lulus' ? '✅ Lulus' : ($pretesResult['status_lulus'] === 'tidak_lulus' ? '❌ Belum Lulus' : '⏳ Menunggu')) : '-' ?></h3>
            <p>Status Pretes</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">📚</div>
        <div class="stat-info">
            <h3><?= count($tutorialRegs) ?></h3>
            <p>Kelas Tutorial</p>
        </div>
    </div>
</div>

<!-- Tutorial Registrations -->
<?php if (!empty($tutorialRegs)): ?>
<div class="card">
    <div class="card-header">📚 Kelas Tutorial Saya</div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Kelas</th>
                        <th>Mata Kuliah</th>
                        <th>Gelombang</th>
                        <th>Jadwal</th>
                        <th>Ruangan</th>
                        <th>Status</th>
                        <th>Nilai</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tutorialRegs as $reg): ?>
                    <tr>
                        <td><?= sanitize($reg['nama_kelas']) ?></td>
                        <td><?= sanitize($reg['mata_kuliah']) ?></td>
                        <td>
                            <?php
                            $gelLabels = ['gel1' => 'Gelombang 1', 'gel2' => 'Gelombang 2', 'mandiri' => 'Mandiri'];
                            echo $gelLabels[$reg['gelombang']] ?? $reg['gelombang'];
                            ?>
                        </td>
                        <td><?= sanitize($reg['hari']) ?>, <?= sanitize($reg['jam']) ?></td>
                        <td><?= sanitize($reg['ruangan']) ?></td>
                        <td>
                            <?php
                            $statusBadge = [
                                'terdaftar' => 'badge-info',
                                'aktif' => 'badge-primary',
                                'lulus' => 'badge-success',
                                'tidak_lulus' => 'badge-danger',
                                'mengundurkan_diri' => 'badge-warning'
                            ];
                            $badge = $statusBadge[$reg['status']] ?? 'badge-info';
                            ?>
                            <span class="badge <?= $badge ?>"><?= ucfirst(str_replace('_', ' ', $reg['status'])) ?></span>
                        </td>
                        <td><?= $reg['nilai_akhir'] ? number_format($reg['nilai_akhir'], 1) : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Recent Announcements -->
<div class="card">
    <div class="card-header">📢 Pengumuman Terbaru</div>
    <div class="card-body">
        <?php if (empty($recentAnnouncements)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <h3>Belum ada pengumuman</h3>
                <p>Pengumuman baru akan tampil di sini.</p>
            </div>
        <?php else: ?>
            <?php foreach ($recentAnnouncements as $ann): ?>
            <div class="announcement-card">
                <div class="ann-title"><?= sanitize($ann['judul']) ?></div>
                <div class="ann-date">🕐 <?= date('d M Y, H:i', strtotime($ann['created_at'])) ?></div>
                <div class="ann-content"><?= nl2br(sanitize($ann['konten'])) ?></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
