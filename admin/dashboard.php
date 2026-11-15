<?php
/**
 * LPPAI Corner - Admin Dashboard
 */
define('PAGE_TITLE', 'Dashboard Admin');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$user = getCurrentUser();
$pdo = getDBConnection();

// Format nomor WhatsApp internasional (62...)
if (!function_exists('formatWaDashboard')) {
    function formatWaDashboard($noHp) {
        $clean = formatNoHp($noHp);
        if (empty($clean) || strlen($clean) < 9) return '';
        return 'https://wa.me/' . $clean;
    }
}

// Deteksi Periode Aktif Pretes & Tutorial
$activePretes = null;
$activePretesPeriods = [];
try {
    $activePretesPeriods = $pdo->query("SELECT * FROM master_pretes WHERE status = 'aktif' ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $activePretes = $activePretesPeriods[0] ?? null;
} catch (Exception $e) {}

$activeTutorial = null;
$activeTutorialPeriods = [];
try {
    $activeTutorialPeriods = $pdo->query("SELECT * FROM master_gelombang WHERE status = 'aktif' ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $activeTutorial = $activeTutorialPeriods[0] ?? null;
} catch (Exception $e) {}

// Stats LPPAI
$totalMahasiswa = $pdo->query("SELECT COUNT(*) FROM users WHERE role='mahasiswa'")->fetchColumn();

// Pendaftar Pretes: Hitung periode aktif jika ada, fallback ke rentang tahun aktif
if ($activePretes) {
    $stmtPretesCount = $pdo->prepare("SELECT COUNT(*) FROM pretes_registrations WHERE periode = ?");
    $stmtPretesCount->execute([$activePretes['periode']]);
    $totalPretes = $stmtPretesCount->fetchColumn();
} else {
    $totalPretes = $pdo->query("SELECT COUNT(*) FROM pretes_registrations WHERE (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%')")->fetchColumn();
}

// Peserta Tutorial: Hitung peserta periode aktif jika ada, fallback ke rentang tahun aktif
if ($activeTutorial) {
    $targetGel = $activeTutorial['gelombang'];
    $targetThn = str_replace('/', '-', trim($activeTutorial['tahun_ajaran']));
    $stmtTutCount = $pdo->prepare("
        SELECT COUNT(DISTINCT tr.user_id) 
        FROM tutorial_registrations tr 
        LEFT JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
        WHERE (COALESCE(NULLIF(tc.gelombang, ''), tr.gelombang) = ? OR ? = '') 
          AND (REPLACE(TRIM(tr.tahun_ajaran), '/', '-') = ? OR REPLACE(TRIM(tc.semester), '/', '-') = ? OR tr.tahun_ajaran IS NULL OR tr.tahun_ajaran = '')
    ");
    $stmtTutCount->execute([$targetGel, $targetGel, $targetThn, $targetThn]);
    $totalTutorial = $stmtTutCount->fetchColumn();
} else {
    $totalTutorial = $pdo->query("SELECT COUNT(*) FROM tutorial_registrations WHERE (tahun_ajaran LIKE '%2025%' OR tahun_ajaran LIKE '%2026%' OR tahun_ajaran LIKE '%2027%' OR tahun_ajaran LIKE '%2028%' OR tahun_ajaran LIKE '%2029%' OR tahun_ajaran LIKE '%2030%')")->fetchColumn();
}

$totalKelas = $pdo->query("SELECT COUNT(*) FROM tutorial_classes")->fetchColumn();
$totalPengumuman = $pdo->query("SELECT COUNT(*) FROM announcements WHERE is_active=1")->fetchColumn();
$lulusPretes = $pdo->query("SELECT COUNT(*) FROM pretes_results WHERE status_lulus='lulus'")->fetchColumn();

require_once __DIR__ . '/../includes/admin-dashboard-registrations.php';
$registrationGroups = adminDashboardRegistrationGroups($pdo, $activeTutorialPeriods, $activePretesPeriods);

include __DIR__ . '/../includes/header.php';
?>

<!-- Welcome Card -->
<div class="card welcome-card">
    <div class="card-body" style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
        <div class="welcome-avatar" style="background:linear-gradient(135deg, #0b3b22, #156138);color:#ffffff;">
            🏛️
        </div>
        <div style="flex:1;min-width:240px;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
                <h2 style="font-size:22px;margin:0;font-weight:800;color:var(--primary-dark);letter-spacing:-0.3px;">Selamat Datang, <?= sanitize($user['nama_lengkap'] ?? 'Administrator LPPAI') ?>!</h2>
                <span class="badge badge-gold">Executive Console</span>
            </div>
            <div class="welcome-chips">
                <span class="welcome-chip">🛡️ Role: <strong>Administrator LPPAI</strong></span>
                <span class="welcome-chip">⚡ Status: <strong>Sistem Terpantau Stabil</strong></span>
                <?php if ($activePretes): ?>
                    <span class="welcome-chip">✍️ Pretes: <strong><?= sanitize($activePretes['periode']) ?></strong></span>
                <?php endif; ?>
                <?php if ($activeTutorial): ?>
                    <span class="welcome-chip">📚 Tutorial: <strong><?= sanitize($activeTutorial['tahun_ajaran']) ?> (<?= sanitize($activeTutorial['gelombang']) ?>)</strong></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Stat Grid Utama -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon green">👥</div>
        <div class="stat-info">
            <h3><?= number_format($totalMahasiswa) ?></h3>
            <p>Total Mahasiswa</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">✍️</div>
        <div class="stat-info">
            <h3><?= number_format($totalPretes) ?></h3>
            <p>Pendaftar Pretes</p>
            <?php if ($activePretes): ?>
                <span style="font-size:11px; color:#64748b; font-weight:500;">(<?= sanitize($activePretes['periode']) ?>)</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">🎓</div>
        <div class="stat-info">
            <h3><?= number_format($lulusPretes) ?></h3>
            <p>Lulus Pretes</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">📚</div>
        <div class="stat-info">
            <h3><?= number_format($totalTutorial) ?></h3>
            <p>Peserta Tutorial</p>
            <?php if ($activeTutorial): ?>
                <span style="font-size:11px; color:#64748b; font-weight:500;">(<?= sanitize($activeTutorial['tahun_ajaran']) ?> / <?= sanitize($activeTutorial['gelombang']) ?>)</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">🏫</div>
        <div class="stat-info">
            <h3><?= number_format($totalKelas) ?></h3>
            <p>Kelas Tutorial</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">📢</div>
        <div class="stat-info">
            <h3><?= number_format($totalPengumuman) ?></h3>
            <p>Pengumuman Aktif</p>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="card" style="margin-top:20px;">
    <div class="card-header">⚡ Aksi Cepat Administrasi</div>
    <div class="card-body">
        <div class="quick-actions-grid">
            <a href="<?= BASE_URL ?>/admin/tutorial-data-pendaftar.php" class="quick-action-btn success">👥 Data Pendaftar Tutorial</a>
            <a href="<?= BASE_URL ?>/admin/tutorial-hasil-plotting.php" class="quick-action-btn">📊 Hasil Plotting Tutorial</a>
            <a href="<?= BASE_URL ?>/admin/tutorial-kelas.php" class="quick-action-btn">🏫 Kelola Kelas</a>
            <a href="<?= BASE_URL ?>/admin/pretes-jadwal.php" class="quick-action-btn primary">📅 Kelola Jadwal Pretes</a>
            <a href="<?= BASE_URL ?>/admin/pretes-rekap-nilai.php" class="quick-action-btn warning">🎓 Rekapitulasi Nilai Pretes</a>
            <a href="<?= BASE_URL ?>/admin/pengumuman.php" class="quick-action-btn primary">📢 Kelola Pengumuman</a>
            <a href="<?= BASE_URL ?>/admin/pengambilan-sertifikat.php" class="quick-action-btn info">🎓 Pengambilan Sertifikat</a>
            <a href="<?= BASE_URL ?>/admin/inventaris-surat.php" class="quick-action-btn">📑 Inventaris Surat</a>
            <a href="<?= BASE_URL ?>/admin/log-aktivitas.php" class="quick-action-btn dark">📜 Log Aktivitas Pengguna</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin-dashboard-registrations-view.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
