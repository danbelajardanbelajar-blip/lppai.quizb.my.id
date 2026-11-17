<?php
/**
 * LPPAI Corner - Dashboard Dosen
 */
if (!defined('PAGE_TITLE')) define('PAGE_TITLE', 'Dashboard Dosen');
require_once __DIR__ . '/../includes/auth.php';
requireDosen();

$user = getCurrentUser();
$pdo = getDBConnection();
require_once __DIR__ . '/../includes/pretes-helper.php';
ensurePretesFeatures($pdo);
require_once __DIR__ . '/../includes/pretes-examiner.php';
pretesExaminerEnsure($pdo);

$isPretesExaminer = pretesExaminerIsScheduled($pdo, $user);
$pretesPeriod = null;
$pretesParticipants = [];
$pretesProgress = ['total' => 0, 'hadir' => 0, 'lengkap' => 0, 'sebagian' => 0, 'kosong' => 0];
$pretesUrl = '';

if ($isPretesExaminer) {
    $pretesPeriod = $pdo->query("SELECT id, periode FROM master_pretes ORDER BY (status = 'aktif') DESC, created_at DESC LIMIT 1")->fetch();
    if ($pretesPeriod) {
        $stmt = $pdo->prepare('SELECT pr.dosen_penguji, pr.dosen_penguji_nim, pr.status, pa.scores_json FROM pretes_registrations pr
            JOIN users u ON u.id = pr.user_id
            LEFT JOIN pretes_assessments pa ON pa.registration_id = pr.id
            WHERE pr.periode = ? AND pr.dosen_penguji_nim = ?');
        $stmt->execute([$pretesPeriod['periode'], pretesExaminerUserNim($user) !== '' ? pretesExaminerUserNim($user) : '__tanpa_nim__']);
        $pretesParticipants = array_values(array_filter($stmt->fetchAll(), fn($row) => pretesCanAssess($user, $row)));
    }
    $pretesProgress = pretesProgress($pretesParticipants);
    $pretesUrl = BASE_URL . '/dosen/pretes.php' . ($pretesPeriod ? '?pretes_id=' . (int)$pretesPeriod['id'] : '');
}

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
<div class="card welcome-card">
    <div class="card-body" style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
        <div class="welcome-avatar" style="background:linear-gradient(135deg, #0b3b22, #156138);color:#ffffff;">
            👨‍🏫
        </div>
        <div style="flex:1;min-width:240px;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
                <h2 style="font-size:22px;margin:0;font-weight:800;color:var(--primary-dark);letter-spacing:-0.3px;">Assalamu'alaikum, <?= sanitize($user['nama_lengkap']) ?>!</h2>
                <span class="badge badge-emerald"><?= $isPretesExaminer ? 'Dosen & Penguji' : 'Dosen Pengampu' ?></span>
            </div>
            <div class="welcome-chips">
                <span class="welcome-chip">📖 Peran: <strong><?= $isPretesExaminer ? 'Dosen Pengampu & Penguji Pretes' : 'Dosen Pengampu Tutorial' ?></strong></span>
                <span class="welcome-chip">🏫 Kelas Aktif: <strong><?= $totalClasses ?> Kelas</strong></span>
                <span class="welcome-chip">👥 Bimbingan: <strong><?= $totalStudents ?> Mahasiswa</strong></span>
            </div>
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

    <a href="<?= BASE_URL ?>/dosen/diktat.php" class="stat-card" style="text-decoration:none; color:inherit; transition:transform 0.2s, box-shadow 0.2s;" title="Buka Diktat LPPAI 2026">
        <div class="stat-icon green" style="background:#dcfce7; color:#15803d;">📖</div>
        <div class="stat-info">
            <h3 style="color:#15803d; font-size:18px; margin:2px 0 0 0;">Diktat LPPAI</h3>
            <p>Pegangan Materi Tutorial</p>
        </div>
    </a>
</div>

<?php if ($isPretesExaminer): ?>
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
<?php endif; ?>

<style>
/* Diktat Educational Prompt Banner */
.ti-diktat-prompt { background: linear-gradient(135deg, #064e3b 0%, #065f46 60%, #047857 100%); color: #ffffff; border-radius: 16px; padding: 24px 26px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; gap: 24px; box-shadow: 0 10px 25px rgba(6, 78, 59, 0.22); }
.ti-diktat-prompt-left { flex: 1; min-width: 0; }
.ti-diktat-badge { display: inline-block; background: rgba(255, 255, 255, 0.16); border: 1px solid rgba(255, 255, 255, 0.28); color: #fef08a; font-size: 11px; font-weight: 800; padding: 4px 12px; border-radius: 999px; letter-spacing: 0.05em; margin-bottom: 10px; }
.ti-diktat-prompt h3 { margin: 0 0 8px; font-size: 18.5px; font-weight: 800; color: #ffffff; line-height: 1.35; }
.ti-diktat-prompt p { margin: 0 0 14px; font-size: 13.5px; line-height: 1.6; color: #e2e8f0; }
.ti-diktat-topics { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
.ti-topic-tag { background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.22); border-radius: 8px; padding: 5px 11px; font-size: 12px; font-weight: 600; color: #f0fdf4; }
.ti-diktat-action { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.btn-diktat-cta { background: #facc15; color: #713f12; border: none; padding: 11px 22px; border-radius: 12px; font-weight: 800; font-size: 14px; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 4px 14px rgba(250, 204, 21, 0.35); display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
.btn-diktat-cta:hover { background: #fde047; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(250, 204, 21, 0.45); color: #422006; }
.ti-diktat-subnote { font-size: 12px; opacity: 0.85; color: #d1fae5; }
.ti-diktat-prompt-visual { display: flex; align-items: center; justify-content: center; flex: 0 0 140px; }
.ti-diktat-book-card { width: 125px; height: 170px; background: #ffffff; border-radius: 10px; padding: 14px 12px; box-shadow: -6px 8px 24px rgba(0, 0, 0, 0.35); border-left: 7px solid #facc15; display: flex; flex-direction: column; justify-content: space-between; text-align: center; color: #064e3b; transform: rotate(-3deg); transition: transform 0.25s ease; user-select: none; }
.ti-diktat-book-card:hover { transform: rotate(0deg) scale(1.06); }
.ti-diktat-book-title { font-size: 11px; font-weight: 800; letter-spacing: 0.06em; color: #064e3b; margin-top: 4px; }
.ti-diktat-book-sub { font-size: 13px; font-weight: 900; color: #047857; margin-top: 2px; }
.ti-diktat-book-logo { font-size: 28px; margin: 8px 0; }
.ti-diktat-book-label { font-size: 10px; font-weight: 700; color: #64748b; background: #f1f5f9; padding: 3px 6px; border-radius: 6px; }

@media (max-width: 768px) {
    .ti-diktat-prompt { flex-direction: column; align-items: flex-start; padding: 20px; }
    .ti-diktat-prompt-visual { display: none; }
}
</style>

<!-- Banner Edukasi & Pegangan Diktat Tutorial -->
<div class="ti-diktat-prompt" id="dosen-diktat-card">
    <div class="ti-diktat-prompt-left">
        <div class="ti-diktat-badge">📖 BUKU PEGANGAN DOSEN &amp; TUTOR</div>
        <h3>Diktat Resmi LPPAI 2026 (Pedoman Tutorial Ubudiyah &amp; PAI)</h3>
        <p>Buku materi resmi sebagai pedoman pembelajaran tatap muka di kelas tutorial bersama mahasiswa bimbingan Anda. Mencakup seluruh standar kompetensi praktikum ibadah, thaharah, shalat, wirid, serta bacaan tartil Al-Qur'an.</p>

        <!-- Poin Pokok Materi Diktat -->
        <div class="ti-diktat-topics">
            <div class="ti-topic-tag">🕌 Thaharah &amp; Wudhu Sempurna</div>
            <div class="ti-topic-tag">🤲 Praktik Shalat Fardhu &amp; Jenazah</div>
            <div class="ti-topic-tag">📿 Dzikir &amp; Wirid Sesudah Shalat</div>
            <div class="ti-topic-tag">📖 Kaidah Tajwid &amp; Tartil Al-Qur'an</div>
        </div>

        <div class="ti-diktat-action">
            <a href="<?= BASE_URL ?>/dosen/diktat.php" class="btn-diktat-cta" id="btn-buka-diktat-dosen">
                📖 Buka &amp; Pelajari Diktat Tutorial →
            </a>
            <span class="ti-diktat-subnote">Dapat dibaca langsung di peramban secara daring tanpa perlu mengunduh file.</span>
        </div>
    </div>
    <div class="ti-diktat-prompt-visual">
        <div class="ti-diktat-book-card">
            <div class="ti-diktat-book-title">DIKTAT TUTORIAL</div>
            <div class="ti-diktat-book-sub">LPPAI 2026</div>
            <div class="ti-diktat-book-logo">📗</div>
            <div class="ti-diktat-book-label">Reader Daring Aktif</div>
        </div>
    </div>
</div>

<!-- Recent Classes -->
<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
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
            <div class="table-responsive dosen-table-wrap">
                <table class="no-datatable dosen-stack-table dosen-class-table">
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
                            <td data-label="Nama Kelas" class="dosen-cell-title"><strong><?= sanitize($kelas['nama_kelas']) ?></strong></td>
                            <td data-label="Jadwal">
                                <span class="badge" style="background:#e0f2fe;color:#0284c7;">
                                    <?= sanitize($kelas['hari']) ?>, <?= sanitize($kelas['jam']) ?>
                                </span>
                            </td>
                            <td data-label="Ruangan"><?= sanitize($kelas['ruangan']) ?></td>
                            <td data-label="Semester"><?= sanitize($kelas['semester']) ?></td>
                            <td data-label="Aksi" class="dosen-cell-actions">
                                <div class="dosen-action-group">
                                    <a href="<?= BASE_URL ?>/dosen/detail-kelas.php?id=<?= $kelas['id'] ?>" class="btn btn-sm btn-primary">Kelola Kelas</a>
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
