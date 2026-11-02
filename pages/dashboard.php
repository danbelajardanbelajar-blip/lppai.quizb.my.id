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
    if ($isLulusTutorial && strpos($tipe, 'pendaftaran_') === 0 && $tipe !== 'pendaftaran_pretes') {
        continue;
    }
    
    // 1b. Sembunyikan pengumuman pendaftaran pretes jika mahasiswa sudah terdaftar pretes atau sudah lulus pretes
    if ($tipe === 'pendaftaran_pretes') {
        if ($pretesRegistered > 0 || ($pretesResult && ($pretesResult['status_lulus'] ?? '') === 'lulus')) {
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

<!-- Pretes Registration & Schedule (Usulan 1) -->
<?php if ($myPretesReg): ?>
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
                    <?= sanitize($myPretesReg['dosen_penguji'] ?: 'Menunggu pembagian') ?>
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

        <!-- Status Akun CBT & Catatan -->
        <div style="background: <?= !empty($myPretesReg['username_tes']) ? '#eff6ff' : '#fffbeb' ?>; border: 1px solid <?= !empty($myPretesReg['username_tes']) ? '#bfdbfe' : '#fde68a' ?>; border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:20px;"><?= !empty($myPretesReg['username_tes']) ? '🔑' : '⏳' ?></span>
                <span style="font-size:13px; color: <?= !empty($myPretesReg['username_tes']) ? '#1e40af' : '#92400e' ?>; font-weight:500;">
                    <?php if (!empty($myPretesReg['username_tes'])): ?>
                        Akun Tes Tulis CBT Anda <strong>sudah siap</strong>. Username: <code style="background:#dbeafe; padding:2px 6px; border-radius:4px; font-weight:700; color:#1e40af;"><?= sanitize($myPretesReg['username_tes']) ?></code>
                    <?php else: ?>
                        Akun Tes Tulis CBT <strong>sedang diproses TU</strong> dan akan dibagikan menjelang hari pelaksanaan.
                    <?php endif; ?>
                </span>
            </div>
            <div style="font-size:12px; color:var(--text-muted);">
                ℹ️ Harap hadir 15 menit sebelum ujian dimulai
            </div>
        </div>

        <!-- Info Wajib Try Out & Exam Browser -->
        <div style="background:#fff1f2; border:1.5px solid #fecdd3; border-radius:12px; padding:12px 16px; margin-bottom:18px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:20px;">🚨</span>
                <div style="font-size:12.5px; color:#9f1239; line-height:1.5;">
                    <strong>Info Wajib Pretes:</strong> 📝 <strong>Wajib catat Username & Password di kertas</strong> (HP terkunci saat ujian) | Try Out Mandiri dari rumah <strong>Malam Ini s.d. Malam Senin</strong> | Wajib Install <strong>E-Ujian Browser (Android)</strong> atau <strong>Safe Exam Browser (iPhone)</strong> | Kode Lembaga: <strong>22205</strong> | <strong>Dilarang membawa 2 HP di meja ujian</strong> (dimatikan & tas di depan).
                </div>
            </div>
            <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-sm btn-danger" style="white-space:nowrap; padding:6px 12px; font-size:12px; font-weight:700;">
                Lihat Panduan & Akun ➔
            </a>
        </div>

        <!-- Banner Khusus Pengguna iPhone (Config SEB) -->
        <div style="background:#f0fdfa; border:1.5px solid #99f6e4; border-radius:12px; padding:12px 16px; margin-bottom:18px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:22px;">🍏</span>
                <div style="font-size:12.5px; color:#134e4a; line-height:1.5;">
                    <strong style="color:#0f766e;">Pengguna iPhone (iOS)?</strong> Wajib pasang file konfigurasi <code>seb-config-lppai.seb</code> agar Safe Exam Browser otomatis terhubung ke ujian.
                </div>
            </div>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <a href="<?= BASE_URL ?>/assets/downloads/seb-config-lppai.seb" download="seb-config-lppai.seb" class="btn btn-sm" style="background:#0f766e; color:#ffffff; padding:6px 12px; font-size:12px; font-weight:700; border-radius:6px; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                    📥 Unduh File .seb
                </a>
                <a href="<?= BASE_URL ?>/pretes-peserta.php#panduan-seb-iphone" class="btn btn-sm btn-light" style="padding:6px 12px; font-size:12px; border:1px solid #99f6e4; color:#0f766e; font-weight:600; border-radius:6px; text-decoration:none;">
                    📖 Panduan iPhone ➔
                </a>
            </div>
        </div>

        <!-- Tombol Aksi Cepat -->
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <button type="button" class="btn btn-primary" onclick="togglePretesScanner()" style="width:auto; display:inline-flex; align-items:center; gap:8px; padding:9px 18px; font-size:13px; font-weight:700; background:#0284c7; border-color:#0284c7; cursor:pointer;">
                📷 Scan Absensi Pretes
            </button>
            <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-light" style="width:auto; display:inline-flex; align-items:center; gap:8px; padding:9px 18px; font-size:13px; font-weight:600; text-decoration:none; border:1px solid #cbd5e1;">
                🔑 Buka Akun CBT & Jadwal Lengkap
            </a>
            <a href="<?= BASE_URL ?>/pretes-hasil.php" class="btn btn-secondary" style="width:auto; display:inline-flex; align-items:center; gap:8px; padding:9px 18px; font-size:13px; font-weight:600; text-decoration:none;">
                📊 Cek Hasil Pretes
            </a>
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


