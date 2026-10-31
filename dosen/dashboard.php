<?php
/**
 * LPPAI Corner - Dashboard Dosen
 */
define('PAGE_TITLE', 'Dashboard Dosen');
require_once __DIR__ . '/../includes/auth.php';
requireDosen();

$user = getCurrentUser();
$pdo = getDBConnection();
require_once __DIR__ . '/../includes/pretes-helper.php';
ensurePretesFeatures($pdo);
$pretesPeriod = $pdo->query("SELECT id, periode FROM master_pretes ORDER BY (status = 'aktif') DESC, created_at DESC LIMIT 1")->fetch();
$pretesParticipants = [];
if ($pretesPeriod) {
    $stmt = $pdo->prepare('SELECT pr.dosen_penguji, pr.status, pa.scores_json FROM pretes_registrations pr
        JOIN users u ON u.id = pr.user_id
        LEFT JOIN pretes_assessments pa ON pa.registration_id = pr.id
        WHERE pr.periode = ? AND TRIM(pr.dosen_penguji) = ?');
    $stmt->execute([$pretesPeriod['periode'], trim($user['nama_lengkap'])]);
    $pretesParticipants = array_values(array_filter($stmt->fetchAll(), fn($row) => pretesCanAssess($user, $row)));
}
$pretesProgress = pretesProgress($pretesParticipants);
$pretesUrl = BASE_URL . '/dosen/pretes.php' . ($pretesPeriod ? '?pretes_id=' . (int)$pretesPeriod['id'] : '');

// Get tutorial classes taught by this dosen
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tutorial_classes WHERE dosen_pengampu = ? AND (semester LIKE '%2026%' OR semester LIKE '%2027%' OR semester LIKE '%2028%' OR semester LIKE '%2029%' OR semester LIKE '%2030%')");
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
$stmt = $pdo->prepare("SELECT * FROM tutorial_classes WHERE dosen_pengampu = ? AND (semester LIKE '%2026%' OR semester LIKE '%2027%' OR semester LIKE '%2028%' OR semester LIKE '%2029%' OR semester LIKE '%2030%') ORDER BY created_at DESC LIMIT 5");
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

<style>
    .pretes-dashboard { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr)); gap:18px; margin-bottom:24px; }
    .pretes-dashboard .card { margin:0; min-width:0; }
    .pretes-dashboard .pretes-total { display:flex; align-items:baseline; gap:8px; margin:12px 0; }
    .pretes-dashboard .pretes-total strong { font-size:32px; color:var(--primary); }
    .pretes-dashboard .pretes-total span { color:var(--text-muted); }
    .pretes-dashboard .btn { width:auto; min-height:44px; margin-top:12px; }
    .pretes-dashboard p { overflow-wrap:anywhere; }
</style>
<div class="pretes-dashboard">
    <div class="card" id="info-absensi-pretes"><div class="card-header">Info Absensi Pretes</div><div class="card-body">
        <p><?= sanitize($pretesPeriod['periode'] ?? 'Belum ada periode pretes') ?></p>
        <div class="pretes-total"><strong><?= $pretesProgress['hadir'] ?></strong><span>dari <?= $pretesProgress['total'] ?> peserta hadir</span></div>
        <p><?= $pretesProgress['total'] - $pretesProgress['hadir'] ?> peserta belum tercatat hadir.</p>
        <a href="<?= sanitize($pretesUrl) ?>#absensi-pretes" class="btn btn-primary" data-no-spa>Kelola Absensi & QR</a>
    </div></div>
    <div class="card" id="info-nilai-pretes"><div class="card-header">Info Input Nilai Pretes</div><div class="card-body">
        <p><?= sanitize($pretesPeriod['periode'] ?? 'Belum ada periode pretes') ?></p>
        <div class="pretes-total"><strong><?= $pretesProgress['lengkap'] ?></strong><span>dari <?= $pretesProgress['total'] ?> peserta dinilai lengkap</span></div>
        <p><?= $pretesProgress['sebagian'] ?> peserta diisi sebagian · <?= $pretesProgress['kosong'] ?> belum diisi.</p>
        <a href="<?= sanitize($pretesUrl) ?>#peserta-pretes" class="btn btn-primary" data-no-spa>Input Nilai Peserta</a>
    </div></div>
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
