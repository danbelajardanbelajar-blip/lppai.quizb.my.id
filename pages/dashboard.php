<?php
/**
 * LPPAI Corner - Dashboard Mahasiswa
 */
define('PAGE_TITLE', 'Dashboard Mahasiswa');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pretes-helper.php';
requireLogin();

// Redirect link scan lama (?scan=pretes) ke halaman mandiri absensi-pretes.php
if (isset($_GET['scan']) && $_GET['scan'] === 'pretes') {
    header('Location: ' . BASE_URL . '/absensi-pretes.php');
    exit;
}

if (isAdmin()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$user = getCurrentUser();
$pdo = getDBConnection();
require_once __DIR__ . '/../includes/tutorial-membership.php';
$hidePretesMenus = studentHasActiveTutorialRegistration($pdo, $user);

// Get pretes status
$stmt = $pdo->prepare("SELECT pr.*, ps.tanggal, ps.ruangan FROM pretes_results pr LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id WHERE pr.user_id = ? ORDER BY pr.created_at DESC LIMIT 1");
$stmt->execute([$user['id']]);
$pretesResult = $stmt->fetch();

// Get active registrations
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pretes_registrations WHERE user_id = ? AND (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%')");
$stmt->execute([$user['id']]);
$pretesRegistered = $stmt->fetchColumn();

// Get pretes registration details for student
$stmt = $pdo->prepare("
    SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan as ruangan_jadwal
    FROM pretes_registrations pr
    LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
    WHERE pr.user_id = ?
    ORDER BY pr.tanggal_daftar DESC
    LIMIT 1
");
$stmt->execute([$user['id']]);
$myPretesReg = $stmt->fetch();

// Cek status periode pretes aktif & kelayakan program mahasiswa
$activePretes = getActivePretesPeriod($pdo);
$isEligibleActivePretes = isUserEligibleForPretesPeriod($user, $activePretes);
$pretesRegisteredActive = 0;
if ($activePretes) {
    $stmtActiveReg = $pdo->prepare("SELECT COUNT(*) FROM pretes_registrations WHERE user_id = ? AND periode = ?");
    $stmtActiveReg->execute([$user['id'], $activePretes['periode']]);
    $pretesRegisteredActive = (int)$stmtActiveReg->fetchColumn();
}

// Pretes dinyatakan selesai jika tidak ada periode master_pretes yang aktif atau status periode mahasiswa sudah nonaktif
$isPretesSelesai = false;
if (!$activePretes) {
    $isPretesSelesai = true;
} elseif ($myPretesReg && !empty($myPretesReg['periode'])) {
    try {
        $stmtPer = $pdo->prepare("SELECT status FROM master_pretes WHERE periode = ? ORDER BY id DESC LIMIT 1");
        $stmtPer->execute([$myPretesReg['periode']]);
        $perStatus = $stmtPer->fetchColumn();
        if ($perStatus !== false && $perStatus !== 'aktif') {
            $isPretesSelesai = true;
        }
    } catch (Exception $e) {}
}

// Cek kelulusan pretes
$isPretesLulus = ($pretesResult && ($pretesResult['status_lulus'] ?? '') === 'lulus');
if (!$isPretesLulus) {
    $isPretesLulus = isUserLulusPretes($pdo, $user['id']);
}

// Tentukan teks status pretes di dashboard
if ($isPretesSelesai) {
    // Karena periode pretes sudah selesai, status definitif: Lulus atau Tidak Lulus (tidak ada status menunggu)
    $pretesStatusText = $isPretesLulus ? '✅ Lulus' : '❌ Tidak Lulus';
} else {
    if ($isPretesLulus) {
        $pretesStatusText = '✅ Lulus';
    } elseif ($pretesResult && ($pretesResult['status_lulus'] ?? '') === 'tidak_lulus') {
        $pretesStatusText = '❌ Belum Lulus';
    } elseif ($myPretesReg || $pretesResult) {
        $pretesStatusText = '⏳ Menunggu';
    } else {
        $pretesStatusText = '-';
    }
}

// Cek status kehadiran pretes
$isHadirPretes = false;
$pretesAttendedAt = null;
if ($myPretesReg) {
    if (($myPretesReg['status'] ?? '') === 'hadir') {
        $isHadirPretes = true;
    }
    try {
        $stmtAtt = $pdo->prepare("SELECT attended_at FROM pretes_attendance_records WHERE registration_id = ?");
        $stmtAtt->execute([$myPretesReg['id']]);
        $pretesAttendedAt = $stmtAtt->fetchColumn();
        if ($pretesAttendedAt) {
            $isHadirPretes = true;
        }
    } catch (Exception $e) {}
}

// Gating & Privilege Ujian Pretes
// Link ujian dibuka/ditutup admin (Bank Soal CBT › Sesi Ujian › Buka/Tutup Ujian), bukan tanggal di kode
$isTesterPrivileged  = (isset($user['username']) && (string)$user['username'] === '12345') 
                    || (isset($user['nim']) && (string)$user['nim'] === '12345')
                    || ((int)($user['id'] ?? 0) === 12345);
$isUjianPretesDibuka = $isTesterPrivileged;
try {
    require_once __DIR__ . '/../includes/pretes-cbt.php';
    $isUjianPretesDibuka = pretesCbtExamStatus($pdo)['open'] || $isTesterPrivileged;
} catch (Throwable $e) {}

// Get tutorial registrations (gunakan LEFT JOIN agar pendaftar yang belum di-plotting kelas tetap muncul hari pilihannya)
$stmt = $pdo->prepare("
    SELECT tr.*, tc.nama_kelas, tc.gelombang as kelas_gelombang, tc.hari as kelas_hari, tc.jam as kelas_jam, tc.ruangan as kelas_ruangan, tc.dosen_pengampu as kelas_dosen_pengampu 
    FROM tutorial_registrations tr 
    LEFT JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id 
    WHERE tr.user_id = ? AND (tr.tahun_ajaran LIKE '%2026%' OR tr.tahun_ajaran LIKE '%2027%' OR tr.tahun_ajaran LIKE '%2028%' OR tr.tahun_ajaran LIKE '%2029%' OR tr.tahun_ajaran LIKE '%2030%') 
    ORDER BY tr.created_at DESC
");
$stmt->execute([$user['id']]);
$tutorialRegs = $stmt->fetchAll();

require_once __DIR__ . '/../includes/announcement-helper.php';
ensureAnnouncementTargetColumns($pdo);

$stmt = $pdo->query("SELECT * FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 20");
$allAnnouncements = $stmt->fetchAll();

// Cek status kelulusan dan gelombang terdaftar
$isLulusTutorial = false;
$registeredGels = [];
foreach ($tutorialRegs as $reg) {
    if (!empty($reg['nomor_sertifikat'])) {
        $isLulusTutorial = true;
    }
    $gVal = $reg['gelombang'] ?: ($reg['kelas_gelombang'] ?? '');
    if ($gVal) {
        $registeredGels[] = $gVal; // 'gel1', 'gel2', 'mandiri'
    }
}

$annContext = [
    'is_pretes_lulus'  => $isPretesLulus,
    'has_pretes_reg'   => ($pretesRegisteredActive > 0 || $pretesRegistered > 0 || !empty($myPretesReg) || !empty($pretesResult)),
    'has_tutorial_reg' => !empty($tutorialRegs),
];

$recentAnnouncements = [];
foreach ($allAnnouncements as $ann) {
    // Filter ketat target penerima pengumuman (Program: Semua/RPL/Reguler, dan Sasaran Peserta)
    if (!isUserEligibleForAnnouncement($user, $ann, $annContext)) {
        continue;
    }

    $tipe = $ann['tipe'];
    if ($hidePretesMenus && (stripos($tipe, 'pretes') !== false
        || preg_match('~(?:^|/)(?:pretes[^/?]*|absensi-pretes)\.php(?:[?#]|$)~i', (string)($ann['link_tujuan'] ?? '')))) {
        continue;
    }
    
    // 1. Jika sudah lulus tutorial ATAU sudah lulus pretes, jangan tampilkan pengumuman pendaftaran tutorial
    $isTutorialRegAnnouncement = (
        (strpos($tipe, 'pendaftaran_') === 0 && $tipe !== 'pendaftaran_pretes')
        || stripos($ann['judul'], 'pendaftaran tutorial') !== false
        || (!empty($ann['link_tujuan']) && stripos($ann['link_tujuan'], 'tutorial-pendaftaran') !== false)
    );

    if (($isLulusTutorial || $isPretesLulus) && $isTutorialRegAnnouncement) {
        continue;
    }
    
    // 1b. Sembunyikan pengumuman pendaftaran pretes jika tidak berhak (mis. khusus RPL), sudah terdaftar di periode aktif, atau sudah lulus pretes
    if ($tipe === 'pendaftaran_pretes') {
        $alreadyRegisteredPretes = $activePretes ? ($pretesRegisteredActive > 0) : ($pretesRegistered > 0);
        if ($hidePretesMenus || !$isEligibleActivePretes || $alreadyRegisteredPretes || ($pretesResult && ($pretesResult['status_lulus'] ?? '') === 'lulus')) {
            continue;
        }
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
<div class="card welcome-card">
    <div class="card-body" style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
        <div class="welcome-avatar">
            👋
        </div>
        <div style="flex:1;min-width:240px;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
                <h2 style="font-size:22px;margin:0;font-weight:800;color:var(--primary-dark);letter-spacing:-0.3px;">Assalamu'alaikum, <?= sanitize($user['nama_lengkap']) ?>!</h2>
                <span class="badge badge-emerald">Mahasiswa Aktif</span>
            </div>
            <div class="welcome-chips">
                <span class="welcome-chip">🆔 NIM: <strong><?= sanitize($user['nim']) ?></strong></span>
                <span class="welcome-chip">🎓 <strong><?= sanitize($user['program_studi']) ?></strong> <?= renderJenisProgramBadge($user['jenis_program'] ?? 'Reguler', true) ?></span>
                <span class="welcome-chip">🏛️ <?= sanitize($user['fakultas']) ?></span>
            </div>
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

<!-- Stats -->
<div class="stat-grid">
    <?php if (!$hidePretesMenus): ?>
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
            <h3 style="<?= $pretesStatusText === '❌ Tidak Lulus' ? 'color:#dc2626;' : ($pretesStatusText === '✅ Lulus' ? 'color:#16a34a;' : '') ?>">
                <?= $pretesStatusText ?>
            </h3>
            <p>Status Pretes</p>
        </div>
    </div>
    <?php endif; ?>
    <div class="stat-card">
        <div class="stat-icon orange">📚</div>
        <div class="stat-info">
            <?php if (!empty($tutorialRegs)): ?>
                <?php 
                $firstTutor = $tutorialRegs[0];
                $hariPilihanTutor = !empty($firstTutor['hari_pilihan']) ? $firstTutor['hari_pilihan'] : (!empty($firstTutor['kelas_hari']) ? $firstTutor['kelas_hari'] : '');
                ?>
                <h3 style="font-size:17px; font-weight:700; color:var(--primary);">
                    <?= $hariPilihanTutor ? 'Hari ' . sanitize($hariPilihanTutor) : 'Terdaftar' ?>
                </h3>
                <p>Pilihan Hari Tutorial</p>
            <?php else: ?>
                <h3>0</h3>
                <p>Kelas Tutorial</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Pretes Registration & Schedule (Disembunyikan jika pretes dinyatakan selesai) -->
<?php if (!$hidePretesMenus && $myPretesReg && !$isPretesSelesai): ?>
<div class="card" style="margin-bottom: 24px; border-left: 4px solid var(--primary); box-shadow: var(--shadow);">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; background:#f8fafc;">
        <div style="display:flex; align-items:center; gap:10px;">
            <span style="font-size:22px;">✍️</span>
            <div>
                <h3 style="margin:0; font-size:17px; font-weight:700; color:var(--text-dark);">
                    Jadwal & Status Pretes Anda
                </h3>
                <small style="color:var(--text-muted); font-size:12px;">
                    Periode: <strong><?= sanitize($myPretesReg['periode'] ?? 'Periode Aktif') ?></strong>
                </small>
            </div>
        </div>
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <?php if ($isHadirPretes): ?>
                <span id="badge-pretes-status-hadir" class="badge badge-success" style="font-size:13px; padding:6px 14px;">✅ Hadir Pretes</span>
            <?php else: ?>
                <span id="badge-pretes-status-hadir" class="badge badge-warning" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; font-size:13px; padding:6px 14px;">⏳ Belum Absen</span>
            <?php endif; ?>

            <?php if ($pretesResult && ($pretesResult['status_lulus'] ?? '') === 'lulus'): ?>
                <span class="badge badge-success" style="font-size:13px; padding:6px 14px;">✅ Lulus Pretes<?= $pretesResult['nilai'] !== null ? ' (Nilai: ' . number_format($pretesResult['nilai'], 1) . ')' : '' ?></span>
            <?php elseif ($pretesResult && ($pretesResult['status_lulus'] ?? '') === 'tidak_lulus'): ?>
                <span class="badge badge-danger" style="font-size:13px; padding:6px 14px;">❌ Belum Lulus<?= $pretesResult['nilai'] !== null ? ' (Nilai: ' . number_format($pretesResult['nilai'], 1) . ')' : '' ?></span>
            <?php else: ?>
                <span class="badge badge-primary" style="font-size:13px; padding:6px 14px;">Terdaftar Pretes</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body" style="padding: 20px;">
        <!-- Grid Detail Jadwal -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:18px;">
            <!-- Hari Pilihan -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                    <span style="font-size:18px;">📅</span>
                    <span style="font-size:11px; font-weight:700; color:var(--text-muted); letter-spacing:0.5px; text-transform:uppercase;">Hari Pilihan</span>
                </div>
                <div style="font-size:17px; font-weight:700; color:var(--primary);">
                    <?= sanitize($myPretesReg['hari_pilihan'] ?: 'Jadwal Reguler') ?>
                </div>
            </div>

            <!-- Jam Pelaksanaan -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                    <span style="font-size:18px;">🕐</span>
                    <span style="font-size:11px; font-weight:700; color:var(--text-muted); letter-spacing:0.5px; text-transform:uppercase;">Jam Pelaksanaan</span>
                </div>
                <div style="font-size:16px; font-weight:700; color:#1e293b;">
                    <?= sanitize($myPretesReg['jam'] ?: '13.00 WIB (Jam 1)') ?>
                </div>
            </div>

            <!-- Ruangan Ujian -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                    <span style="font-size:18px;">🏢</span>
                    <span style="font-size:11px; font-weight:700; color:var(--text-muted); letter-spacing:0.5px; text-transform:uppercase;">Ruangan Ujian</span>
                </div>
                <div style="font-size:15px; font-weight:600; color:#1e293b;">
                    <?= sanitize($myPretesReg['ruangan'] ?? ($myPretesReg['ruangan_jadwal'] ?? 'Menunggu pembagian')) ?>
                </div>
            </div>

            <!-- Dosen Penguji -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                    <span style="font-size:18px;">👨‍🏫</span>
                    <span style="font-size:11px; font-weight:700; color:var(--text-muted); letter-spacing:0.5px; text-transform:uppercase;">Dosen Penguji</span>
                </div>
                <div style="font-size:15px; font-weight:600; color:#1e293b;">
                    <?= renderDosenWithWa($pdo, $myPretesReg['dosen_penguji'] ?? null, $user, null, 'Menunggu pembagian') ?>
                </div>
            </div>
        </div>

        <!-- ===================================================
             BANNER & MODUL SCANNER ABSENSI PRETES
             =================================================== -->
        <div id="pretes-absensi-status-box" style="background: <?= $isHadirPretes ? '#f0fdf4' : 'linear-gradient(135deg, #0284c7 0%, #0369a1 100%)' ?>; color: <?= $isHadirPretes ? '#166534' : '#ffffff' ?>; border: 1.5px solid <?= $isHadirPretes ? '#86efac' : '#0284c7' ?>; border-radius:12px; padding:16px 20px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; box-shadow: <?= $isHadirPretes ? 'none' : '0 4px 14px rgba(2,132,199,0.22)' ?>;">
            <div style="display:flex; align-items:center; gap:14px;">
                <div style="width:48px; height:48px; background: <?= $isHadirPretes ? '#dcfce7' : 'rgba(255,255,255,0.2)' ?>; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:24px; flex-shrink:0;">
                    <?= $isHadirPretes ? '🎉' : '📷' ?>
                </div>
                <div>
                    <div style="font-weight:800; font-size:15px; margin-bottom:2px;">
                        <?= $isHadirPretes ? 'Kehadiran Pretes Anda Telah Tercatat (Hadir)' : 'Scan Absensi Kehadiran Pretes' ?>
                    </div>
                    <div style="font-size:12.5px; opacity:0.95; line-height:1.4; color: <?= $isHadirPretes ? '#15803d' : '#f0f9ff' ?>;">
                        <?php if ($isHadirPretes): ?>
                            Anda telah melakukan absensi kehadiran pretes.<?= $pretesAttendedAt ? ' Waktu: ' . date('d M Y, H:i', strtotime($pretesAttendedAt)) . ' WIB.' : '' ?>
                        <?php else: ?>
                            Sudah di ruangan ujian? Buka kamera scanner di bawah ini dan arahkan ke QR Code yang ditampilkan oleh Dosen Penguji di ruangan.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <button type="button" class="btn" id="btn-toggle-pretes-scanner" onclick="togglePretesScanner()" style="background:#ffffff; color: <?= $isHadirPretes ? '#166534' : '#0369a1' ?>; font-weight:800; font-size:13.5px; padding:9px 18px; border-radius:8px; border:1px solid <?= $isHadirPretes ? '#86efac' : '#ffffff' ?>; box-shadow:0 2px 8px rgba(0,0,0,0.12); display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
                <span>📷</span> <?= $isHadirPretes ? 'Scan Ulang Absensi' : 'Buka Scanner Absensi' ?>
            </button>
        </div>

        <!-- Wadah Scanner Kamera Pretes (Muncul saat tombol diklik) -->
        <div id="pretes-scanner-wrapper" class="pretes-scanner-wrapper" style="display:none; background:#ffffff; border:1.5px solid #cbd5e1; border-radius:14px; padding:18px; margin-bottom:18px; box-shadow:0 4px 20px rgba(0,0,0,0.06);">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; padding-bottom:12px; margin-bottom:14px; border-bottom:1px solid #e2e8f0;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:20px;">📷</span>
                    <strong style="font-size:15px; color:#1e293b;">Kamera Pemindai Absensi Pretes</strong>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="switchPretesCamera()" id="btn-switch-pretes-cam" style="padding:5px 12px; font-size:12px; font-weight:600;">
                        🔄 Ganti Kamera
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="stopPretesScanner()" style="padding:5px 12px; font-size:12px; font-weight:600;">
                        ✖️ Tutup Scanner
                    </button>
                </div>
            </div>

            <!-- Viewport Kamera -->
            <div style="width:100%; max-width:440px; margin:0 auto 14px auto; border-radius:12px; overflow:hidden; background:#0f172a; box-shadow:0 4px 12px rgba(0,0,0,0.15); min-height:260px; position:relative;">
                <div id="reader_pretes_dashboard" style="width:100%;"></div>
            </div>

            <p style="text-align:center; font-size:12.5px; color:#64748b; margin-bottom:14px;">
                🔍 Arahkan kamera HP Anda tepat ke QR Code absensi pretes yang disediakan oleh penguji di ruangan.
            </p>

            <!-- Box Feedback & Status Pemindaian -->
            <div id="pretes-scan-feedback" style="display:none; margin-bottom:14px;"></div>

            <!-- Input Manual / Alternatif jika kamera bermasalah -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px;">
                <label for="manualPretesCode" style="font-size:12px; font-weight:700; color:#475569; margin-bottom:6px; display:block;">
                    ⌨️ Atau Masukkan URL / Token QR Absensi Secara Manual:
                </label>
                <div style="display:flex; gap:8px;">
                    <input type="text" id="manualPretesCode" class="form-control" placeholder="Tempel / ketik token atau link absensi pretes..." style="font-size:13px;">
                    <button type="button" class="btn btn-primary" onclick="submitPretesManualCode()" style="white-space:nowrap; padding:8px 16px; font-weight:700; font-size:13px;">
                        Kirim Absen
                    </button>
                </div>
            </div>
        </div>

        <!-- Status Metode Masuk Pretes: Scan Verifikasi Wajah (Face Recognition) -->
        <div style="background: <?= !empty($myPretesReg['foto']) ? '#f0fdf4' : '#fffbeb' ?>; border: 1.5px solid <?= !empty($myPretesReg['foto']) ? '#86efac' : '#fde68a' ?>; border-radius: 12px; padding: 14px 18px; margin-bottom: 14px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center; gap:12px;">
                <span style="font-size:24px;"><?= !empty($myPretesReg['foto']) ? '📸' : '⚠️' ?></span>
                <div>
                    <div style="font-size:13.5px; font-weight:800; color: <?= !empty($myPretesReg['foto']) ? '#15803d' : '#92400e' ?>;">
                        Metode Masuk Ujian: Scan Verifikasi Wajah (Face Recognition)
                    </div>
                    <div style="font-size:12.5px; color: <?= !empty($myPretesReg['foto']) ? '#166534' : '#78350f' ?>; margin-top:2px; line-height:1.45;">
                        <?php if (!empty($myPretesReg['foto'])): ?>
                            ✅ Pasfoto wajah Anda <strong>sudah terverifikasi</strong>. Akses ujian dibuka langsung melalui pemindaian kamera peramban tanpa memerlukan username &amp; password.
                        <?php else: ?>
                            Pasfoto wajah pendaftaran belum tersedia. Harap pastikan pasfoto resmi Anda telah terunggah pada akun pendaftaran.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div style="font-size:12px; color:var(--text-muted); font-weight:600;">
                ℹ️ Harap hadir 15 menit sebelum ujian dimulai
            </div>
        </div>

        <!-- Info Tata Tertib & Pengawasan AI Pretes -->
        <div style="background:#eff6ff; border:1.5px solid #bfdbfe; border-radius:12px; padding:14px 18px; margin-bottom:18px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:flex-start; gap:12px; max-width:820px;">
                <span style="font-size:24px; flex-shrink:0; line-height:1.2;">🛡️</span>
                <div style="font-size:12.5px; color:#1e40af; line-height:1.55;">
                    <strong style="color:#1e3a8a; font-size:13px; display:block; margin-bottom:3px;">
                        Tata Tertib &amp; Pengawasan Ujian Pretes:
                    </strong>
                    1. Berdoa &amp; belajar dengan sungguh-sungguh | 2. Wajib dikerjakan sendiri secara mandiri &amp; jujur | 3. Dilarang membuka web/aplikasi lain | 4. Dilarang menggunakan HP lain.
                    <div style="margin-top:4px; font-size:12px; color:#2563eb;">
                        🔍 Ujian diverifikasi di awal (Check-in) dan diawasi berkala setiap 5 menit dengan sistem kamera <strong>AI Face Recognition</strong>.
                    </div>
                </div>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <?php if ($isUjianPretesDibuka): ?>
                    <a href="<?= BASE_URL ?>/pretes-ujian.php" class="btn btn-sm btn-success" style="white-space:nowrap; padding:8px 16px; font-size:12.5px; font-weight:800; border-radius:8px; box-shadow:0 2px 8px rgba(22,163,74,0.3); text-decoration:none;">
                        🚀 Masuk Ujian Pretes ➔
                    </a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-sm btn-primary" style="white-space:nowrap; padding:8px 16px; font-size:12.5px; font-weight:700; border-radius:8px; background:#0f766e; border-color:#0f766e; text-decoration:none;">
                        Lihat Jadwal &amp; Panduan ➔
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tombol Aksi Cepat -->
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <button type="button" class="btn btn-primary" onclick="togglePretesScanner()" style="width:auto; display:inline-flex; align-items:center; gap:8px; padding:9px 18px; font-size:13px; font-weight:700; background:#0284c7; border-color:#0284c7; cursor:pointer;">
                📷 Scan Absensi Pretes
            </button>
            <?php if ($isUjianPretesDibuka): ?>
                <a href="<?= BASE_URL ?>/pretes-ujian.php" class="btn btn-success" style="width:auto; display:inline-flex; align-items:center; gap:8px; padding:9px 18px; font-size:13px; font-weight:800; text-decoration:none; background:#16a34a; border-color:#16a34a; box-shadow:0 2px 8px rgba(22,163,74,0.3);">
                    📝 Kerjakan Pretes (Scan Wajah)
                </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-light" style="width:auto; display:inline-flex; align-items:center; gap:8px; padding:9px 18px; font-size:13px; font-weight:600; text-decoration:none; border:1px solid #cbd5e1;">
                📋 Jadwal &amp; Panduan Pretes
            </a>
            <a href="<?= BASE_URL ?>/pretes-hasil.php" class="btn btn-secondary" style="width:auto; display:inline-flex; align-items:center; gap:8px; padding:9px 18px; font-size:13px; font-weight:600; text-decoration:none;">
                📊 Cek Hasil Pretes
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Banner Ringkasan Hasil Pretes (Tampil jika pretes dinyatakan selesai) -->
<?php if (!$hidePretesMenus && $myPretesReg && $isPretesSelesai): ?>
<div class="card" style="margin-bottom: 24px; border-left: 4px solid <?= $isPretesLulus ? '#10b981' : '#ef4444' ?>; box-shadow: var(--shadow);">
    <div class="card-body" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; padding:18px 22px;">
        <div style="display:flex; align-items:center; gap:14px;">
            <div style="width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:26px; background:<?= $isPretesLulus ? '#dcfce7' : '#fee2e2' ?>; flex-shrink:0;">
                <?= $isPretesLulus ? '🎉' : '📢' ?>
            </div>
            <div>
                <h3 style="margin:0 0 3px 0; font-size:16px; font-weight:700; color:#1e293b;">
                    Pretes Periode <?= sanitize($myPretesReg['periode'] ?? '2026/2027') ?> Telah Selesai
                </h3>
                <p style="margin:0; font-size:13.5px; color:#64748b; line-height:1.4;">
                    Status Akhir: <strong style="color:<?= $isPretesLulus ? '#16a34a' : '#dc2626' ?>;"><?= $isPretesLulus ? '✅ LULUS' : '❌ TIDAK LULUS' ?></strong>
                    <?php if (!$isPretesLulus): ?>
                        &bull; Anda diwajibkan untuk mendaftar dan mengikuti program <strong>Tutorial LPPAI</strong>.
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <a href="<?= BASE_URL ?>/pretes-hasil.php" class="btn btn-primary" style="text-decoration:none; padding:8px 18px; font-weight:700; font-size:13px; display:inline-flex; align-items:center; gap:6px;">
                📊 Lihat Nilai & Hasil Pretes
            </a>
            <?php if (!$isPretesLulus): ?>
                <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="btn btn-danger" style="text-decoration:none; padding:8px 18px; font-weight:700; font-size:13px; display:inline-flex; align-items:center; gap:6px; background:#dc2626; border-color:#dc2626;">
                    📝 Daftar Tutorial ➔
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Tutorial Registrations -->
<?php if (!empty($tutorialRegs)): ?>
<div class="card">
    <div class="card-header">📚 Kelas Tutorial Saya</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="no-datatable">
                <thead>
                    <tr>
                        <th>Kelas</th>
                        <th>Tutor / Dosen</th>
                        <th>Gelombang</th>
                        <th>Jadwal / Hari Pilihan</th>
                        <th>Ruangan</th>
                        <th>Status</th>
                        <th>Nilai</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tutorialRegs as $reg): ?>
                    <tr>
                        <td><strong><?= sanitize($reg['nama_kelas'] ?: 'Menunggu Pembagian Kelas') ?></strong></td>
                        <td><?= renderDosenWithWa($pdo, $reg['dosen_pengampu'] ?? ($reg['kelas_dosen_pengampu'] ?? null), $user, null, '-') ?></td>
                        <td>
                            <?php
                            $gelLabels = ['gel1' => 'Gelombang 1', 'gel2' => 'Gelombang 2', 'mandiri' => 'Mandiri'];
                            $gVal = $reg['gelombang'] ?: ($reg['kelas_gelombang'] ?? '');
                            echo $gelLabels[$gVal] ?? $gVal;
                            ?>
                        </td>
                        <td>
                            <?php if (!empty($reg['hari']) || !empty($reg['kelas_hari'])): ?>
                                <?= sanitize($reg['hari'] ?: $reg['kelas_hari']) ?>, <?= sanitize($reg['jam'] ?: ($reg['kelas_jam'] ?: '13.00 - 14.30')) ?>
                            <?php elseif (!empty($reg['hari_pilihan'])): ?>
                                Hari: <strong><?= sanitize($reg['hari_pilihan']) ?></strong> (13.00 - 14.30)
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= sanitize($reg['ruangan'] ?: ($reg['kelas_ruangan'] ?: 'Menunggu plotting')) ?></td>
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
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <a href="<?= BASE_URL ?>/tutorial-pengumuman.php" class="btn btn-sm btn-success" style="padding: 4px 10px; font-weight: bold; text-decoration: none;">🎉 Pengumuman</a>
                                    <a href="<?= BASE_URL ?>/ambil-sertifikat.php" class="btn btn-sm btn-primary" style="padding: 4px 10px; font-weight: bold; text-decoration: none;">📷 Scan Ambil Sertifikat</a>
                                </div>
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
            <?php
                $kontenDisplay = $ann['konten'];
                // Normalisasi peninggalan encoding non-utf8mb4 (tanda tanya ? pengganti emoji)
                $kontenDisplay = str_replace(
                    [
                        '? BAGI MAHASISWA YANG DINYATAKAN LULUS PRETES:',
                        '1. ? Mengikuti',
                        '2. ? Melakukan',
                        '3. ? Menyelesaikan',
                        '4. ? Mengambil',
                        '?? BAGI MAHASISWA YANG BELUM LULUS PRETES:',
                        '? BAGI MAHASISWA YANG BELUM LULUS PRETES:',
                        '? BAGI MAHASISWA YANG TIDAK LULUS PRETES:'
                    ],
                    [
                        '🎉 BAGI MAHASISWA YANG DINYATAKAN LULUS PRETES:',
                        '1. 🕌 Mengikuti',
                        '2. 📱 Melakukan',
                        '3. 💳 Menyelesaikan',
                        '4. 🎓 Mengambil',
                        '⚠️ BAGI MAHASISWA YANG BELUM LULUS PRETES:',
                        '⚠️ BAGI MAHASISWA YANG BELUM LULUS PRETES:',
                        '⚠️ BAGI MAHASISWA YANG TIDAK LULUS PRETES:'
                    ],
                    $kontenDisplay
                );
            ?>
            <div class="announcement-card">
                <div class="ann-title"><?= sanitize($ann['judul']) ?></div>
                <div class="ann-date">🕐 <?= date('d M Y, H:i', strtotime($ann['created_at'])) ?></div>
                <div class="ann-content"><?= nl2br(sanitize($kontenDisplay)) ?></div>
                <?php if (!empty($ann['link_tujuan'])): ?>
                <?php
                    $targetUrl = $ann['link_tujuan'];
                    if ($ann['tipe'] === 'hasil_pretes' || stripos($ann['judul'], 'hasil pretes') !== false) {
                        if (strpos($targetUrl, 'pretes-peserta') !== false || empty($targetUrl)) {
                            $targetUrl = '/pages/pretes-hasil.php';
                        }
                    }
                ?>
                <div style="margin-top: 15px;">
                    <a href="<?= BASE_URL . sanitize($targetUrl) ?>" class="btn btn-primary" style="display:inline-block; padding:8px 16px; font-size:14px; width:auto; text-decoration:none; background:var(--primary); color:#fff; border-radius:6px; font-weight:600;">
                        Buka Halaman ➔
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
var html5QrCodePretes = null;
var isScanningPretes = false;
var currentPretesFacingMode = "environment"; // default kamera belakang HP

// Feedback Audio Synthesizer (Web Audio API)
function playPretesBeep(type) {
    try {
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);

        if (type === 'success') {
            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
            osc.frequency.setValueAtTime(880, ctx.currentTime + 0.1); // A5
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
            osc.start();
            osc.stop(ctx.currentTime + 0.35);
        } else if (type === 'error') {
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(220, ctx.currentTime);
            osc.frequency.setValueAtTime(146.83, ctx.currentTime + 0.12);
            gain.gain.setValueAtTime(0.3, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
            osc.start();
            osc.stop(ctx.currentTime + 0.35);
        }
    } catch(e) {}
}

// Muat pustaka Html5Qrcode secara dinamis jika belum tersedia
function loadHtml5Qrcode(callback) {
    if (window.Html5Qrcode) {
        callback();
        return;
    }
    var script = document.createElement('script');
    script.src = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
    script.onload = function() {
        callback();
    };
    script.onerror = function() {
        var feedback = document.getElementById('pretes-scan-feedback');
        if (feedback) {
            feedback.style.display = 'block';
            feedback.className = 'alert alert-danger';
            feedback.innerHTML = '❌ Gagal memuat library pemindai kamera. Pastikan perangkat Anda terhubung ke internet.';
        }
    };
    document.head.appendChild(script);
}

function startPretesScanner() {
    var readerEl = document.getElementById("reader_pretes_dashboard");
    if (!readerEl) return;

    if (html5QrCodePretes) {
        try { html5QrCodePretes.stop(); } catch(e) {}
    }

    html5QrCodePretes = new Html5Qrcode("reader_pretes_dashboard");
    var config = {
        fps: 15,
        qrbox: { width: 250, height: 250 },
        aspectRatio: 1.0
    };

    html5QrCodePretes.start(
        { facingMode: currentPretesFacingMode },
        config,
        onPretesScanSuccess,
        function(error) {
            // scan failure callback diabaikan agar tidak spamming console
        }
    ).then(function() {
        isScanningPretes = true;
    }).catch(function(err) {
        console.warn("Kamera belakang tidak tersedia, mencoba kamera depan/webcam:", err);
        if (currentPretesFacingMode === "environment") {
            currentPretesFacingMode = "user";
            startPretesScanner();
        } else {
            var feedback = document.getElementById('pretes-scan-feedback');
            if (feedback) {
                feedback.style.display = 'block';
                feedback.className = 'alert alert-warning';
                feedback.innerHTML = '⚠️ <strong>Izin Kamera Ditolak / Tidak Ditemukan.</strong><br>Pastikan Anda mengizinkan akses kamera di browser, atau masukkan URL/token QR secara manual di bawah.';
            }
        }
    });
}

function stopPretesScanner() {
    if (html5QrCodePretes) {
        try {
            html5QrCodePretes.stop().then(function() {
                html5QrCodePretes = null;
            }).catch(function() {
                html5QrCodePretes = null;
            });
        } catch(e) {
            html5QrCodePretes = null;
        }
    }
    isScanningPretes = false;
    var wrapper = document.getElementById('pretes-scanner-wrapper');
    if (wrapper) wrapper.style.display = 'none';
}

function togglePretesScanner() {
    var wrapper = document.getElementById('pretes-scanner-wrapper');
    if (!wrapper) return;

    if (wrapper.style.display === 'none' || wrapper.style.display === '') {
        wrapper.style.display = 'block';
        wrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
        loadHtml5Qrcode(startPretesScanner);
    } else {
        stopPretesScanner();
    }
}

function switchPretesCamera() {
    currentPretesFacingMode = (currentPretesFacingMode === "environment") ? "user" : "environment";
    startPretesScanner();
}

function resumePretesScanner() {
    var feedback = document.getElementById('pretes-scan-feedback');
    if (feedback) feedback.style.display = 'none';

    if (html5QrCodePretes) {
        try {
            html5QrCodePretes.resume();
            isScanningPretes = true;
            return;
        } catch(e) {}
    }
    startPretesScanner();
}

function onPretesScanSuccess(decodedText) {
    if (!isScanningPretes) return;
    isScanningPretes = false;
    sendPretesAttendance(decodedText);
}

function submitPretesManualCode() {
    var inputEl = document.getElementById('manualPretesCode');
    if (!inputEl) return;
    var code = inputEl.value.trim();
    if (!code) {
        alert('Silakan masukkan token atau tautan absensi pretes terlebih dahulu.');
        return;
    }
    sendPretesAttendance(code);
}

function sendPretesAttendance(code) {
    if (!code) return;

    // Jeda scanner saat memproses
    if (html5QrCodePretes) {
        try { html5QrCodePretes.pause(); } catch(e) {}
    }

    var feedbackEl = document.getElementById('pretes-scan-feedback');
    if (feedbackEl) {
        feedbackEl.style.display = 'block';
        feedbackEl.className = 'alert alert-info';
        feedbackEl.innerHTML = '⏳ <strong>Sedang memverifikasi QR Code...</strong> Mohon tunggu sebentar.';
    }

    var formData = new FormData();
    formData.append('csrf_token', '<?= csrfToken() ?>');
    formData.append('qr_data', code);

    fetch('<?= BASE_URL ?>/ajax-pretes-scan.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(function(res) {
        return res.json();
    })
    .then(function(data) {
        if (data && data.success) {
            playPretesBeep('success');
            var sess = data.session || {};
            if (feedbackEl) {
                feedbackEl.className = 'alert alert-success';
                feedbackEl.innerHTML = '🎉 <strong>' + data.message + '</strong><br>' +
                    'Sesi: <strong>' + (sess.hari || '-') + '</strong> · Ruang: <strong>' + (sess.ruangan || '-') + '</strong> · Penguji: <strong>' + (sess.penguji || '-') + '</strong><br>' +
                    '<small style="color:#166534;font-size:12px;">Waktu Absensi: ' + (sess.waktu || '-') + '</small>';
            }

            // Perbarui badge kehadiran di header card
            var badgeHadir = document.getElementById('badge-pretes-status-hadir');
            if (badgeHadir) {
                badgeHadir.style.display = 'inline-block';
                badgeHadir.className = 'badge badge-success';
                badgeHadir.style.background = '';
                badgeHadir.style.color = '';
                badgeHadir.style.border = '';
                badgeHadir.textContent = '✅ Hadir Pretes';
            }

            // Perbarui banner status kehadiran
            var statusBox = document.getElementById('pretes-absensi-status-box');
            if (statusBox) {
                statusBox.innerHTML = '<div style="display:flex;align-items:center;gap:12px;">' +
                    '<span style="font-size:24px;">🎉</span>' +
                    '<div><strong style="color:#166534;font-size:14px;">Kehadiran Pretes Anda Telah Tercatat (Hadir)</strong>' +
                    '<div style="font-size:12.5px;color:#15803d;">Sesi: ' + (sess.hari || '-') + ' | Ruang: ' + (sess.ruangan || '-') + ' | Penguji: ' + (sess.penguji || '-') + '</div></div></div>' +
                    '<button type="button" class="btn btn-sm" onclick="togglePretesScanner()" style="background:#fff;color:#166534;border:1px solid #166534;font-weight:700;padding:6px 14px;border-radius:6px;cursor:pointer;">🔄 Buka Scanner</button>';
                statusBox.style.background = '#f0fdf4';
                statusBox.style.borderColor = '#86efac';
                statusBox.style.color = '#166534';
            }

            if (typeof showToast === 'function') {
                showToast(data.message, 'success', 5000);
            }

            // Matikan kamera setelah sukses tercatat
            setTimeout(function() {
                stopPretesScanner();
            }, 3000);
        } else {
            playPretesBeep('error');
            var errMsg = (data && data.message) ? data.message : 'QR Code tidak valid atau sesi absensi ditutup.';
            if (feedbackEl) {
                feedbackEl.className = 'alert alert-danger';
                feedbackEl.innerHTML = '❌ <strong>Gagal Merekam Kehadiran:</strong><br>' + errMsg +
                    '<div style="margin-top:10px;"><button type="button" class="btn btn-sm btn-secondary" onclick="resumePretesScanner()">🔄 Coba Scan Ulang</button></div>';
            }
            if (typeof showToast === 'function') {
                showToast(errMsg.replace(/<[^>]+>/g, ''), 'danger', 5000);
            }
        }
    })
    .catch(function(err) {
        playPretesBeep('error');
        if (feedbackEl) {
            feedbackEl.className = 'alert alert-danger';
            feedbackEl.innerHTML = '❌ Terjadi gangguan koneksi: ' + (err.message || 'Gagal menghubungi server') +
                '<div style="margin-top:10px;"><button type="button" class="btn btn-sm btn-secondary" onclick="resumePretesScanner()">🔄 Coba Scan Ulang</button></div>';
        }
    });
}

// Buka scanner otomatis jika URL mengandung parameter ?scan=pretes atau #scan-pretes
(function() {
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('scan') === 'pretes' || window.location.hash === '#scan-pretes') {
        setTimeout(function() {
            var wrapper = document.getElementById('pretes-scanner-wrapper');
            if (!wrapper || wrapper.style.display === 'none') {
                togglePretesScanner();
            }
        }, 350);
    }
})();

window.addEventListener('beforeunload', function() {
    stopPretesScanner();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>


