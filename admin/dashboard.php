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
try {
    $activePretes = $pdo->query("SELECT * FROM master_pretes WHERE status = 'aktif' ORDER BY created_at DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$activeTutorial = null;
try {
    $activeTutorial = $pdo->query("SELECT * FROM master_gelombang WHERE status = 'aktif' ORDER BY created_at DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
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

// Tentukan tab aktif default berdasarkan periode yang sedang berjalan
$defaultTab = 'tutorial';
if ($activeTutorial && !$activePretes) {
    $defaultTab = 'tutorial';
} elseif ($activePretes && !$activeTutorial) {
    $defaultTab = 'pretes';
} elseif ($activeTutorial && $activePretes) {
    $defaultTab = 'tutorial';
} else {
    $defaultTab = 'tutorial';
}

if (isset($_GET['tab']) && in_array($_GET['tab'], ['tutorial', 'pretes'])) {
    $defaultTab = $_GET['tab'];
}

// 1. Data Pendaftaran Tutorial Terbaru
$recentTutorialRegs = [];
if ($activeTutorial) {
    $targetGelombang = $activeTutorial['gelombang'];
    $targetTahun = str_replace('/', '-', trim($activeTutorial['tahun_ajaran']));
    try {
        $stmtTutorial = $pdo->prepare("
            SELECT tr.*, u.nama_lengkap, u.nim, u.program_studi, u.no_hp,
                   tc.nama_kelas, tc.hari as kelas_hari, tc.ruangan, tc.dosen_pengampu
            FROM tutorial_registrations tr
            JOIN users u ON tr.user_id = u.id
            LEFT JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
            WHERE (
                tr.gelombang = ? OR tc.gelombang = ?
                OR (tr.gelombang IS NULL AND tc.id IS NULL)
            )
            AND (
                REPLACE(TRIM(tr.tahun_ajaran), '/', '-') = ?
                OR REPLACE(TRIM(tc.semester), '/', '-') = ?
                OR tr.tahun_ajaran IS NULL
                OR tr.tahun_ajaran = ''
            )
            ORDER BY tr.id DESC LIMIT 10
        ");
        $stmtTutorial->execute([$targetGelombang, $targetGelombang, $targetTahun, $targetTahun]);
        $recentTutorialRegs = $stmtTutorial->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

if (empty($recentTutorialRegs)) {
    try {
        $recentTutorialRegs = $pdo->query("
            SELECT tr.*, u.nama_lengkap, u.nim, u.program_studi, u.no_hp,
                   tc.nama_kelas, tc.hari as kelas_hari, tc.ruangan, tc.dosen_pengampu
            FROM tutorial_registrations tr
            JOIN users u ON tr.user_id = u.id
            LEFT JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
            ORDER BY tr.id DESC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// 2. Data Pendaftaran Pretes Terbaru
$recentPretesRegs = [];
if ($activePretes) {
    try {
        $stmtPretes = $pdo->prepare("
            SELECT pr.*, u.nama_lengkap, u.nim, u.program_studi, u.no_hp
            FROM pretes_registrations pr
            JOIN users u ON pr.user_id = u.id
            WHERE pr.periode = ?
            ORDER BY pr.id DESC LIMIT 10
        ");
        $stmtPretes->execute([$activePretes['periode']]);
        $recentPretesRegs = $stmtPretes->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

if (empty($recentPretesRegs)) {
    try {
        $recentPretesRegs = $pdo->query("
            SELECT pr.*, u.nama_lengkap, u.nim, u.program_studi, u.no_hp
            FROM pretes_registrations pr
            JOIN users u ON pr.user_id = u.id
            ORDER BY pr.id DESC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

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

<!-- Tabel Pendaftaran Dinamis Periode Aktif (Tutorial / Pretes) -->
<div class="card" style="margin-top:24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <span style="font-size:16px; font-weight:700;">📋 Pendaftaran Terbaru</span>
            
            <?php if ($activeTutorial): ?>
                <span id="badgeActiveTut" class="badge" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-size:12px; font-weight:600; padding:4px 10px; border-radius:20px; display:<?= $defaultTab === 'tutorial' ? 'inline-flex' : 'none' ?>; align-items:center; gap:5px;">
                    <span style="width:7px; height:7px; border-radius:50%; background:#16a34a; display:inline-block;"></span>
                    Periode Aktif Tutorial: <?= sanitize($activeTutorial['tahun_ajaran']) ?> (<?= sanitize($activeTutorial['semester']) ?> / <?= sanitize($activeTutorial['gelombang']) ?>)
                </span>
            <?php endif; ?>
            <?php if ($activePretes): ?>
                <span id="badgeActivePre" class="badge" style="background:#dbeafe; color:#1d4ed8; border:1px solid #bfdbfe; font-size:12px; font-weight:600; padding:4px 10px; border-radius:20px; display:<?= $defaultTab === 'pretes' ? 'inline-flex' : 'none' ?>; align-items:center; gap:5px;">
                    <span style="width:7px; height:7px; border-radius:50%; background:#2563eb; display:inline-block;"></span>
                    Periode Aktif Pretes: <?= sanitize($activePretes['periode']) ?>
                </span>
            <?php endif; ?>
        </div>

        <!-- Tab Switcher -->
        <div style="display:inline-flex; background:#f1f5f9; padding:4px; border-radius:8px; gap:4px; border:1px solid #e2e8f0;">
            <button type="button" id="tabBtnTutorial" onclick="switchDashboardTab('tutorial')" class="btn btn-sm" style="border:none; padding:6px 14px; border-radius:6px; font-weight:600; font-size:12.5px; cursor:pointer; transition:all 0.2s; <?= $defaultTab === 'tutorial' ? 'background:#059669; color:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'background:transparent; color:#64748b;' ?>">
                📚 Tutorial <?= $activeTutorial ? '<span style="font-size:10px; background:' . ($defaultTab === 'tutorial' ? '#047857' : '#10b981') . '; color:#fff; padding:1px 6px; border-radius:10px; margin-left:4px;">Aktif</span>' : '' ?>
            </button>
            <button type="button" id="tabBtnPretes" onclick="switchDashboardTab('pretes')" class="btn btn-sm" style="border:none; padding:6px 14px; border-radius:6px; font-weight:600; font-size:12.5px; cursor:pointer; transition:all 0.2s; <?= $defaultTab === 'pretes' ? 'background:#2563eb; color:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'background:transparent; color:#64748b;' ?>">
                ✍️ Pretes <?= $activePretes ? '<span style="font-size:10px; background:' . ($defaultTab === 'pretes' ? '#1d4ed8' : '#3b82f6') . '; color:#fff; padding:1px 6px; border-radius:10px; margin-left:4px;">Aktif</span>' : '' ?>
            </button>
        </div>
    </div>
    
    <div class="card-body">
        <!-- TAB KONTEN TUTORIAL -->
        <div id="tabContentTutorial" style="display: <?= $defaultTab === 'tutorial' ? 'block' : 'none' ?>;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
                <div style="font-size:13px; color:#64748b;">
                    Menampilkan 10 pendaftaran tutorial terbaru <?= $activeTutorial ? 'pada periode aktif <strong>' . sanitize($activeTutorial['tahun_ajaran']) . ' (' . sanitize($activeTutorial['gelombang']) . ')</strong>' : '' ?>.
                </div>
                <a href="<?= BASE_URL ?>/admin/tutorial-data-pendaftar.php" class="btn btn-sm btn-outline-primary" style="padding:5px 12px; font-size:12px; text-decoration:none; border-radius:6px; font-weight:600;">
                    Buka Data Lengkap Pendaftar Tutorial &rarr;
                </a>
            </div>

            <?php if (empty($recentTutorialRegs)): ?>
                <div class="empty-state" style="text-align:center; padding:32px;">
                    <div style="font-size:40px; margin-bottom:8px;">📚</div>
                    <h3 style="color:#64748b; font-size:16px;">Belum ada pendaftaran tutorial pada periode ini</h3>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #e2e8f0;">
                                <th style="padding:10px 14px;">Nama Mahasiswa</th>
                                <th style="padding:10px 14px;">NIM</th>
                                <th style="padding:10px 14px;">Program Studi</th>
                                <th style="padding:10px 14px;">Pilihan Hari / Kelas</th>
                                <th style="padding:10px 14px;">Tgl Daftar</th>
                                <th style="padding:10px 14px;">Status Plotting</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTutorialRegs as $r): ?>
                            <tr style="border-bottom:1px solid #e2e8f0;">
                                <td style="padding:10px 14px; font-weight:600; color:#1e293b;">
                                    <?= sanitize($r['nama_lengkap']) ?>
                                    <?php $waUrl = formatWaDashboard($r['no_hp'] ?? ''); ?>
                                    <?php if (!empty($waUrl)): ?>
                                        <a href="<?= $waUrl ?>" target="_blank" rel="noopener noreferrer" title="Chat WhatsApp" style="margin-left:6px; color:#16a34a; text-decoration:none; font-size:13px;" onclick="event.stopPropagation();">
                                            💬
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 14px; color:#475569;"><?= sanitize($r['nim']) ?></td>
                                <td style="padding:10px 14px; color:#475569;"><?= sanitize($r['program_studi'] ?? '-') ?></td>
                                <td style="padding:10px 14px;">
                                    <?php if (!empty($r['nama_kelas'])): ?>
                                        <span class="badge" style="background:#dbeafe; color:#1e40af; padding:4px 8px; border-radius:6px; font-weight:600; font-size:12px;">
                                            🏫 <?= sanitize($r['nama_kelas']) ?> (<?= sanitize($r['kelas_hari'] ?: $r['hari_pilihan']) ?>)
                                        </span>
                                    <?php elseif (!empty($r['hari_pilihan'])): ?>
                                        <span class="badge" style="background:#f1f5f9; color:#334155; padding:4px 8px; border-radius:6px; font-weight:600; font-size:12px;">
                                            📅 Hari: <?= ucfirst(sanitize($r['hari_pilihan'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 14px; color:#64748b; font-size:12px;">
                                    <?= !empty($r['created_at']) ? date('d M Y H:i', strtotime($r['created_at'])) : '-' ?>
                                </td>
                                <td style="padding:10px 14px;">
                                    <?php if (!empty($r['tutorial_class_id'])): ?>
                                        <span class="badge" style="background:#dcfce7; color:#15803d; padding:4px 8px; border-radius:6px; font-weight:600; font-size:11.5px;">
                                            ✓ Sudah Diplot
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#fef3c7; color:#b45309; padding:4px 8px; border-radius:6px; font-weight:600; font-size:11.5px;">
                                            ⏳ Menunggu Plotting
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB KONTEN PRETES -->
        <div id="tabContentPretes" style="display: <?= $defaultTab === 'pretes' ? 'block' : 'none' ?>;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
                <div style="font-size:13px; color:#64748b;">
                    Menampilkan 10 pendaftaran pretes terbaru <?= $activePretes ? 'pada periode aktif <strong>' . sanitize($activePretes['periode']) . '</strong>' : '' ?>.
                </div>
                <a href="<?= BASE_URL ?>/admin/pretes-peserta.php" class="btn btn-sm btn-outline-primary" style="padding:5px 12px; font-size:12px; text-decoration:none; border-radius:6px; font-weight:600;">
                    Buka Data Lengkap Peserta Pretes &rarr;
                </a>
            </div>

            <?php if (empty($recentPretesRegs)): ?>
                <div class="empty-state" style="text-align:center; padding:32px;">
                    <div style="font-size:40px; margin-bottom:8px;">📋</div>
                    <h3 style="color:#64748b; font-size:16px;">Belum ada pendaftaran pretes pada periode ini</h3>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #e2e8f0;">
                                <th style="padding:10px 14px;">Nama Mahasiswa</th>
                                <th style="padding:10px 14px;">NIM</th>
                                <th style="padding:10px 14px;">Program Studi</th>
                                <th style="padding:10px 14px;">Periode Pretes</th>
                                <th style="padding:10px 14px;">Pilihan Hari</th>
                                <th style="padding:10px 14px;">Tgl Daftar</th>
                                <th style="padding:10px 14px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentPretesRegs as $r): ?>
                            <tr style="border-bottom:1px solid #e2e8f0;">
                                <td style="padding:10px 14px; font-weight:600; color:#1e293b;">
                                    <?= sanitize($r['nama_lengkap']) ?>
                                    <?php $waUrl = formatWaDashboard($r['no_hp'] ?? ''); ?>
                                    <?php if (!empty($waUrl)): ?>
                                        <a href="<?= $waUrl ?>" target="_blank" rel="noopener noreferrer" title="Chat WhatsApp" style="margin-left:6px; color:#16a34a; text-decoration:none; font-size:13px;" onclick="event.stopPropagation();">
                                            💬
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 14px; color:#475569;"><?= sanitize($r['nim']) ?></td>
                                <td style="padding:10px 14px; color:#475569;"><?= sanitize($r['program_studi'] ?? '-') ?></td>
                                <td style="padding:10px 14px; color:#475569;"><?= sanitize($r['periode']) ?></td>
                                <td style="padding:10px 14px;">
                                    <?php if (!empty($r['hari_pilihan'])): ?>
                                        <span class="badge" style="background:#f1f5f9; color:#334155; padding:4px 8px; border-radius:6px; font-weight:600; font-size:12px;">
                                            📅 <?= ucfirst(sanitize($r['hari_pilihan'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 14px; color:#64748b; font-size:12px;">
                                    <?= !empty($r['tanggal_daftar']) ? date('d M Y H:i', strtotime($r['tanggal_daftar'])) : (!empty($r['created_at']) ? date('d M Y H:i', strtotime($r['created_at'])) : '-') ?>
                                </td>
                                <td style="padding:10px 14px;">
                                    <span class="badge badge-success"><?= ucfirst(sanitize($r['status'] ?? 'terdaftar')) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function switchDashboardTab(tab) {
    const tabTutorial = document.getElementById('tabContentTutorial');
    const tabPretes = document.getElementById('tabContentPretes');
    const btnTutorial = document.getElementById('tabBtnTutorial');
    const btnPretes = document.getElementById('tabBtnPretes');
    const badgeActiveTut = document.getElementById('badgeActiveTut');
    const badgeActivePre = document.getElementById('badgeActivePre');
    
    if (tab === 'tutorial') {
        if (tabTutorial) tabTutorial.style.display = 'block';
        if (tabPretes) tabPretes.style.display = 'none';
        if (btnTutorial) {
            btnTutorial.style.background = '#059669';
            btnTutorial.style.color = '#fff';
            btnTutorial.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
        }
        if (btnPretes) {
            btnPretes.style.background = 'transparent';
            btnPretes.style.color = '#64748b';
            btnPretes.style.boxShadow = 'none';
        }
        if (badgeActiveTut) badgeActiveTut.style.display = 'inline-flex';
        if (badgeActivePre) badgeActivePre.style.display = 'none';
    } else {
        if (tabTutorial) tabTutorial.style.display = 'none';
        if (tabPretes) tabPretes.style.display = 'block';
        if (btnPretes) {
            btnPretes.style.background = '#2563eb';
            btnPretes.style.color = '#fff';
            btnPretes.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
        }
        if (btnTutorial) {
            btnTutorial.style.background = 'transparent';
            btnTutorial.style.color = '#64748b';
            btnTutorial.style.boxShadow = 'none';
        }
        if (badgeActiveTut) badgeActiveTut.style.display = 'none';
        if (badgeActivePre) badgeActivePre.style.display = 'inline-flex';
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
