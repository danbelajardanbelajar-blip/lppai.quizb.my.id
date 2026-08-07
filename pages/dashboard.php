<?php
/**
 * LPPAI Corner - Dashboard Mahasiswa
 */
define('PAGE_TITLE', 'Dashboard Mahasiswa');
require_once __DIR__ . '/../includes/auth.php';
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
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pretes_registrations WHERE user_id = ? AND (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%')");
$stmt->execute([$user['id']]);
$pretesRegistered = $stmt->fetchColumn();

// Get tutorial registrations
$stmt = $pdo->prepare("SELECT tr.*, tc.nama_kelas, tc.gelombang, tc.hari, tc.jam, tc.ruangan FROM tutorial_registrations tr JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id WHERE tr.user_id = ? AND (tahun_ajaran LIKE '%2026%' OR tahun_ajaran LIKE '%2027%' OR tahun_ajaran LIKE '%2028%' OR tahun_ajaran LIKE '%2029%' OR tahun_ajaran LIKE '%2030%') ORDER BY tr.created_at DESC");
$stmt->execute([$user['id']]);
$tutorialRegs = $stmt->fetchAll();

$stmt = $pdo->query("SELECT * FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 10");
$allAnnouncements = $stmt->fetchAll();

// Cek status kelulusan dan gelombang terdaftar
$isLulusTutorial = false;
$registeredGels = [];
foreach ($tutorialRegs as $reg) {
    if (!empty($reg['nomor_sertifikat'])) {
        $isLulusTutorial = true;
    }
    $registeredGels[] = $reg['gelombang']; // 'gel1', 'gel2', 'mandiri'
}

$recentAnnouncements = [];
foreach ($allAnnouncements as $ann) {
    $tipe = $ann['tipe'];
    
    // 1. Jika sudah lulus, jangan tampilkan pengumuman pendaftaran tutorial
    if ($isLulusTutorial && strpos($tipe, 'pendaftaran_') === 0) {
        continue;
    }
    
    // 2. Jika sudah terdaftar di gelombang tertentu, jangan tampilkan pengumuman pendaftarannya
    if (in_array('gel1', $registeredGels) && $tipe === 'pendaftaran_gel1') continue;
    if (in_array('gel2', $registeredGels) && $tipe === 'pendaftaran_gel2') continue;
    if (in_array('mandiri', $registeredGels) && $tipe === 'pendaftaran_mandiri') continue;
    
    // 3. Jika NIM diawali dengan '23', jangan tampilkan pendaftaran mandiri
    if (strpos($user['nim'], '23') === 0 && $tipe === 'pendaftaran_mandiri') {
        continue;
    }
    
    $recentAnnouncements[] = $ann;
    
    if (count($recentAnnouncements) >= 5) break; // Batasi maksimal 5 pengumuman yang relevan
}

// Cek apakah hari ini adalah Jumat awal bulan
$isFirstFriday = (date('w') == 5 && date('j') <= 7);

include __DIR__ . '/../includes/header.php';
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

<!-- Al Khidmah Alert -->
<?php if ($isFirstFriday): ?>
<div class="card" style="border-left: 4px solid #10b981; margin-bottom: 24px; background-color: #f0fdf4;">
    <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:15px;">
        <div style="display:flex;align-items:center;gap:15px;">
            <div class="stat-icon" style="background:#d1fae5;color:#10b981;width:50px;height:50px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:24px;">
                🕌
            </div>
            <div>
                <h3 style="margin:0;font-size:18px;color:#065f46;">Absensi Al Khidmah</h3>
                <p style="margin:4px 0 0;color:#047857;font-size:14px;">Hari ini adalah Jum'at awal bulan. Jangan lupa untuk mengisi kehadiran Al Khidmah Anda.</p>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/absensi-alkhidmah.php" class="btn btn-success" style="white-space:nowrap;padding:10px 20px;font-weight:bold;">📍 Isi Absensi Sekarang</a>
    </div>
</div>
<?php endif; ?>

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
                        <th>Gelombang</th>
                        <th>Jadwal</th>
                        <th>Ruangan</th>
                        <th>Status</th>
                        <th>Nilai</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tutorialRegs as $reg): ?>
                    <tr>
                        <td><?= sanitize($reg['nama_kelas']) ?></td>
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
                        <td>
                            <?php if (!empty($reg['nomor_sertifikat'])): ?>
                                <a href="<?= BASE_URL ?>/tutorial-pengumuman.php" class="btn btn-sm btn-success" style="padding: 4px 10px; font-weight: bold; text-decoration: none;">🎉 Lihat Pengumuman Kelulusan</a>
                            <?php else: ?>
                                <span style="font-size: 12px; color: #888;">Belum tersedia</span>
                            <?php endif; ?>
                        </td>
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
                <?php if (!empty($ann['link_tujuan'])): ?>
                <div style="margin-top: 15px;">
                    <a href="<?= BASE_URL . sanitize($ann['link_tujuan']) ?>" class="btn btn-primary" style="display:inline-block; padding:8px 16px; font-size:14px; width:auto; text-decoration:none; background:var(--primary); color:#fff; border-radius:6px; font-weight:600;">
                        Buka Halaman ➔
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>


