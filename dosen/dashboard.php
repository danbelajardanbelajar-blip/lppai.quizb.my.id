<?php
/**
 * LPPAI Corner - Dashboard Dosen
 */
define('PAGE_TITLE', 'Dashboard Dosen');
require_once __DIR__ . '/../includes/auth.php';
requireDosen();

$user = getCurrentUser();
$pdo = getDBConnection();

// Get tutorial classes taught by this dosen
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tutorial_classes WHERE dosen_pengampu = ?");
$stmt->execute([$user['nama_lengkap']]);
$totalClasses = $stmt->fetchColumn();

// Get total students in those classes
$stmt = $pdo->prepare("
    SELECT COUNT(tr.id) 
    FROM tutorial_registrations tr 
    JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id 
    WHERE tc.dosen_pengampu = ?
");
$stmt->execute([$user['nama_lengkap']]);
$totalStudents = $stmt->fetchColumn();

// Get recent classes
$stmt = $pdo->prepare("SELECT * FROM tutorial_classes WHERE dosen_pengampu = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user['nama_lengkap']]);
$recentClasses = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Welcome Card -->
<div class="card" style="border-left: 4px solid var(--primary); margin-bottom: 24px;">
    <div class="card-body" style="display:flex;align-items:center;gap:20px;">
        <div class="stat-icon green" style="width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;">
            👨‍🏫
        </div>
        <div>
            <h2 style="font-size:22px;margin-bottom:4px;">Assalamu'alaikum, <?= sanitize($user['nama_lengkap']) ?>!</h2>
            <p style="color:var(--text-muted);font-size:14px;">
                Selamat datang di Dashboard Dosen / Tutor LPPAI Corner.
            </p>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="stat-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-icon blue">🏫</div>
        <div class="stat-info">
            <h3><?= $totalClasses ?></h3>
            <p>Kelas yang Diampu</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon purple">👥</div>
        <div class="stat-info">
            <h3><?= $totalStudents ?></h3>
            <p>Total Mahasiswa</p>
        </div>
    </div>
</div>

<!-- Recent Classes -->
<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span>📋 Kelas Anda (Terbaru)</span>
        <a href="<?= BASE_URL ?>/dosen/kelas.php" class="btn btn-sm btn-primary">Lihat Semua Kelas</a>
    </div>
    <div class="card-body">
        <?php if (empty($recentClasses)): ?>
            <div class="empty-state" style="padding:40px 20px; text-align:center;">
                <div class="icon" style="font-size:48px; margin-bottom:16px;">🎓</div>
                <h3 style="margin-bottom:8px;">Belum Ada Kelas</h3>
                <p style="color:#64748b;">Anda belum dijadwalkan untuk mengajar kelas apapun.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Nama Kelas</th>
                            <th>Mata Kuliah</th>
                            <th>Jadwal</th>
                            <th>Ruangan</th>
                            <th>Semester</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentClasses as $kelas): ?>
                        <tr>
                            <td><strong><?= sanitize($kelas['nama_kelas']) ?></strong></td>
                            <td><?= sanitize($kelas['mata_kuliah']) ?></td>
                            <td>
                                <span class="badge" style="background:#e0f2fe;color:#0284c7;">
                                    <?= sanitize($kelas['hari']) ?>, <?= sanitize($kelas['jam']) ?>
                                </span>
                            </td>
                            <td><?= sanitize($kelas['ruangan']) ?></td>
                            <td><?= sanitize($kelas['semester']) ?></td>
                            <td>
                                <a href="<?= BASE_URL ?>/dosen/detail-kelas.php?id=<?= $kelas['id'] ?>" class="btn btn-sm btn-primary">Kelola Kelas</a>
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
