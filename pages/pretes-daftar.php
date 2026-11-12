<?php
/**
 * LPPAI Corner - Daftar Pretes (Alur Baru Berbasis Pilihan Hari & Jam 13.00)
 */
define('PAGE_TITLE', 'Daftar Pretes');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pretes-helper.php';
requireLogin();

$user = getCurrentUser();
$pdo  = getDBConnection();
$message = '';
$msgType = '';

// Auto-migration tabel master_pretes dan kolom pretes_registrations
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS master_pretes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            periode VARCHAR(100) NOT NULL,
            tahun_ajaran VARCHAR(50) NOT NULL,
            target_program ENUM('Semua','RPL','Reguler') NOT NULL DEFAULT 'Semua',
            tanggal_mulai DATE DEFAULT NULL,
            status ENUM('aktif','selesai','nonaktif') NOT NULL DEFAULT 'aktif',
            jam VARCHAR(50) DEFAULT '13.00 WIB (Jam 1)',
            kuota_senin INT DEFAULT 0,
            kuota_selasa INT DEFAULT 0,
            kuota_rabu INT DEFAULT 0,
            kuota_kamis INT DEFAULT 0,
            kuota_jumat INT DEFAULT 0,
            penguji_senin VARCHAR(1000) DEFAULT NULL,
            penguji_selasa VARCHAR(1000) DEFAULT NULL,
            penguji_rabu VARCHAR(1000) DEFAULT NULL,
            penguji_kamis VARCHAR(1000) DEFAULT NULL,
            penguji_jumat VARCHAR(1000) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {}
ensurePretesPeriodTargetColumns($pdo);

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("ALTER TABLE pretes_registrations MODIFY pretes_schedule_id INT NULL");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN hari_pilihan VARCHAR(20) DEFAULT NULL");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN jam VARCHAR(50) DEFAULT '13.00 WIB (Jam 1)'");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN dosen_penguji VARCHAR(150) DEFAULT NULL");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN ruangan VARCHAR(100) DEFAULT NULL");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN foto VARCHAR(255) DEFAULT NULL");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
} catch (Exception $e) {}
ensurePretesRegistrationPhotoColumn($pdo);

// Cek apakah tabel master_pretes kosong, inisiasi default aktif jika belum ada
try {
    $hasMaster = $pdo->query("SELECT COUNT(*) FROM master_pretes")->fetchColumn();
    if ($hasMaster == 0) {
        $curYear = (int)date('Y');
        $ta = $curYear . ' - ' . ($curYear + 1);
        $per = 'Pretes ' . $curYear . '/' . ($curYear + 1);
        $pdo->prepare("
            INSERT INTO master_pretes 
            (periode, tahun_ajaran, status, jam, kuota_senin, kuota_selasa, kuota_rabu, kuota_kamis, kuota_jumat) 
            VALUES (?, ?, 'aktif', '13.00 WIB (Jam 1)', 30, 30, 30, 30, 30)
        ")->execute([$per, $ta]);
    }
} catch (Exception $e) {}

// Ambil Periode Pretes Aktif & cek kelayakan program (Semua / RPL / Reguler)
$active_pretes = getActivePretesPeriod($pdo);
$isEligibleForPretes = isUserEligibleForPretesPeriod($user, $active_pretes);
$periodSchedule = getPretesPeriodDayLabels($active_pretes);

// Ambil Pengumuman Pendaftaran Pretes Aktif
$annPretes = null;
try {
    $stmtAnnPretes = $pdo->query("SELECT * FROM announcements WHERE tipe = 'pendaftaran_pretes' AND is_active = 1 ORDER BY created_at DESC LIMIT 1");
    if ($stmtAnnPretes) {
        $annPretes = $stmtAnnPretes->fetch();
    }
} catch (Exception $e) {}

// Cek Registrasi Pretes Mahasiswa Saat Ini (terisolasi pada periode aktif bila sedang ada periode aktif)
if ($active_pretes) {
    $stmt = $pdo->prepare("
        SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan as ruangan_jadwal
        FROM pretes_registrations pr
        LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
        WHERE pr.user_id = ? AND pr.periode = ?
        ORDER BY pr.tanggal_daftar DESC
        LIMIT 1
    ");
    $stmt->execute([$user['id'], $active_pretes['periode']]);
} else {
    $stmt = $pdo->prepare("
        SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan as ruangan_jadwal
        FROM pretes_registrations pr
        LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
        WHERE pr.user_id = ?
        ORDER BY pr.tanggal_daftar DESC
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
}
$existingReg = $stmt->fetch();

// Hitung Pendaftar per Hari khusus pada periode aktif (mencegah kuota tercampur antar periode di tahun ajaran sama)
$registeredCounts = ['Senin' => 0, 'Selasa' => 0, 'Rabu' => 0, 'Kamis' => 0, 'Jumat' => 0];
if ($active_pretes) {
    $stmtCount = $pdo->prepare("
        SELECT hari_pilihan, COUNT(*) as cnt 
        FROM pretes_registrations 
        WHERE periode = ?
        GROUP BY hari_pilihan
    ");
    $stmtCount->execute([$active_pretes['periode']]);
    foreach ($stmtCount->fetchAll() as $row) {
        $h = ucfirst(strtolower(trim($row['hari_pilihan'] ?? '')));
        if (isset($registeredCounts[$h])) {
            $registeredCounts[$h] = (int)$row['cnt'];
        }
    }
}

// Handle Form Submit Pendaftaran Pretes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'daftar_pretes' || ($_POST['action'] ?? '') === 'daftar')) {
    $token        = $_POST['csrf_token'] ?? '';
    $hari_pilihan = trim($_POST['hari_pilihan'] ?? '');
    $fotoData     = trim($_POST['foto_data'] ?? '');
    $fotoFile     = $_FILES['foto_file'] ?? null;
    $validDays    = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];

    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid. Silakan muat ulang halaman.';
        $msgType = 'danger';
    } elseif ($existingReg) {
        $message = 'Anda sudah terdaftar untuk pretes periode ini.';
        $msgType = 'warning';
    } elseif (!$active_pretes) {
        $message = 'Pendaftaran pretes sedang ditutup atau belum dibuka.';
        $msgType = 'danger';
    } elseif (!$isEligibleForPretes) {
        $targetProgramLabel = normalizePretesTargetProgram($active_pretes['target_program'] ?? 'Semua');
        $message = "Pendaftaran pretes periode ini khusus untuk mahasiswa program {$targetProgramLabel}.";
        $msgType = 'danger';
    } elseif (!in_array($hari_pilihan, $validDays)) {
        $message = 'Silakan pilih hari pelaksanaan yang valid.';
        $msgType = 'danger';
    } elseif (empty($fotoData) && (empty($fotoFile) || empty($fotoFile['tmp_name']))) {
        $message = 'Foto resmi peserta wajib diunggah atau diambil langsung dan terverifikasi jelas sebelum konfirmasi pendaftaran.';
        $msgType = 'danger';
    } else {
        $payloadToSave = !empty($fotoData) ? $fotoData : ($fotoFile['tmp_name'] ?? '');
        $savedPhotoPath = savePretesRegistrationPhoto($payloadToSave, $user['nim'] ?? 'peserta');

        if (!$savedPhotoPath) {
            $message = 'Format foto tidak valid atau gagal diproses oleh server. Pastikan foto berformat JPG/PNG/WEBP yang jelas.';
            $msgType = 'danger';
        } else {
            $dayLower   = strtolower($hari_pilihan);
            $totalKuota = (int)($active_pretes["kuota_$dayLower"] ?? 0);
            $terisi     = $registeredCounts[$hari_pilihan] ?? 0;

            if ($totalKuota <= 0 || $terisi >= $totalKuota) {
                $message = "Maaf, kuota pretes untuk hari $hari_pilihan sudah penuh.";
                $msgType = 'danger';
            } else {
                $jamPretes = $active_pretes['jam'] ?? '13.00 WIB (Jam 1)';
                $stmt = $pdo->prepare("
                    INSERT INTO pretes_registrations (user_id, hari_pilihan, jam, periode, foto, status) 
                    VALUES (?, ?, ?, ?, ?, 'terdaftar')
                ");
                $stmt->execute([$user['id'], $hari_pilihan, $jamPretes, $active_pretes['periode'], $savedPhotoPath]);

                $message = "Pendaftaran pretes berhasil! Anda telah memilih hari $hari_pilihan ($jamPretes) dan pasfoto resmi Anda telah terverifikasi.";
                $msgType = 'success';

                // Update counter lokal
                $registeredCounts[$hari_pilihan]++;

                // Refresh status registrasi
                $stmt = $pdo->prepare("
                    SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan as ruangan_jadwal
                    FROM pretes_registrations pr
                    LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
                    WHERE pr.user_id = ? AND pr.periode = ?
                    ORDER BY pr.tanggal_daftar DESC
                    LIMIT 1
                ");
                $stmt->execute([$user['id'], $active_pretes['periode']]);
                $existingReg = $stmt->fetch();
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
    <h2 class="page-title"><?= PAGE_TITLE ?></h2>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     STATUS JIKA SUDAH TERDAFTAR PRETES
     ═══════════════════════════════════════════════════════════════ -->
<?php if ($existingReg): ?>
<div class="card" style="margin-bottom: 30px; border-top: 4px solid var(--primary);">
    <div class="card-header" style="background:#f8fafc;">
        <h3 style="margin:0; font-size:18px;">Pretes - <?= sanitize($existingReg['periode'] ?? 'Periode Aktif') ?></h3>
    </div>
    <div class="card-body">
        <div style="padding:20px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px;">
            <h4 style="margin-top:0; color:#166534; font-size:16px;">✅ Status Pendaftaran: Terdaftar Pretes</h4>
            
            <?php if (!empty($existingReg['foto'])): ?>
            <div style="display:flex; align-items:center; gap:16px; margin:16px 0; padding:12px 16px; background:#fff; border:1px solid #bbf7d0; border-radius:10px;">
                <img src="<?= BASE_URL ?>/<?= htmlspecialchars(ltrim(str_replace('\\', '/', $existingReg['foto']), '/')) ?>" alt="Pasfoto Resmi" style="width:64px; height:84px; object-fit:cover; border-radius:6px; border:2px solid #16a34a; box-shadow:0 2px 6px rgba(0,0,0,0.08);">
                <div>
                    <div style="font-weight:700; color:#15803d; font-size:14.5px; display:flex; align-items:center; gap:6px;">
                        <span>✅</span> Pasfoto Resmi Terverifikasi AI
                    </div>
                    <div style="font-size:12.5px; color:#475569; margin-top:3px; line-height:1.4;">
                        Pasfoto resmi Anda telah disetujui dan akan dicetak otomatis pada Kartu Ujian Pretes fisik.
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:18px; margin-top:16px;">
                <div>
                    <strong style="font-size:12px; color:#166534; letter-spacing:0.5px;">HARI PILIHAN</strong>
                    <p style="margin:4px 0 0; font-size:16px; font-weight:bold; color:#14532d;">
                        <?= sanitize($existingReg['hari_pilihan'] ?: 'Jadwal Reguler') ?>
                    </p>
                </div>
                <div>
                    <strong style="font-size:12px; color:#166534; letter-spacing:0.5px;">JAM PELAKSANAAN</strong>
                    <p style="margin:4px 0 0; font-size:15px; font-weight:600; color:#14532d;">
                        <?= sanitize($existingReg['jam'] ?: '13.00 WIB (Jam 1)') ?>
                    </p>
                </div>
                <div>
                    <strong style="font-size:12px; color:#166534; letter-spacing:0.5px;">PERIODE</strong>
                    <p style="margin:4px 0 0; font-size:14px;">
                        <?= sanitize($existingReg['periode'] ?? '-') ?>
                    </p>
                </div>
                <div>
                    <strong style="font-size:12px; color:#166534; letter-spacing:0.5px;">DOSEN PENGUJI</strong>
                    <p style="margin:4px 0 0; font-size:14px; font-weight:600;">
                        <?= sanitize($existingReg['dosen_penguji'] ?: 'Menunggu pembagian') ?>
                    </p>
                </div>
                <div>
                    <strong style="font-size:12px; color:#166534; letter-spacing:0.5px;">RUANGAN</strong>
                    <p style="margin:4px 0 0; font-size:14px; font-weight:600;">
                        <?= sanitize($existingReg['ruangan'] ?? ($existingReg['ruangan_jadwal'] ?: 'Menunggu pembagian')) ?>
                    </p>
                </div>
                <div>
                    <strong style="font-size:12px; color:#166534; letter-spacing:0.5px;">STATUS</strong>
                    <p style="margin:4px 0 0;">
                        <span class="badge badge-success" style="font-size:13px; padding:4px 10px;">
                            <?= ucfirst(str_replace('_', ' ', $existingReg['status'])) ?>
                        </span>
                    </p>
                </div>

                <div style="grid-column: 1 / -1; margin-top:12px; padding-top:14px; border-top:1px dashed #86efac;">
                    <p style="margin:0 0 14px; color:#15803d; font-size:14px; line-height:1.5;">
                        <em>Anda telah memilih hari pelaksanaan pretes. Silakan tunggu admin membagikan dosen penguji dan ruangan ujian Anda. Pastikan untuk mengecek akun tes tulis Anda sebelum hari pelaksanaan.</em>
                    </p>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;">
                    <a href="<?= BASE_URL ?>/cetak-kartu-pretes.php" target="_blank" rel="noopener" data-no-spa class="btn btn-primary" style="display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:10px 20px;border-radius:10px;font-weight:700;text-decoration:none;" aria-label="Cetak kartu pretes (buka tab baru)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9V3h12v6M6 18H3V9h18v9h-3M6 14h12v7H6z"/><path d="M17 12h1"/></svg>
                        Cetak Kartu Pretes
                    </a>
                    <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px; padding:10px 20px; font-weight:600; text-decoration:none;">
                        🔑 Lihat Jadwal & Credentials Tes Tulis CBT
                    </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     FORM PENDAFTARAN JIKA BELUM TERDAFTAR & BERHAK MENGIKUTI PERIODE
     ═══════════════════════════════════════════════════════════════ -->
<?php elseif ($active_pretes && $isEligibleForPretes): ?>
<?php
    $jamTampil = trim((string)($active_pretes['jam'] ?? '')) ?: '13.00 WIB (Jam 1)';
    $rangeLabel = $periodSchedule['range_label'];
    $shortRange = $periodSchedule['short_range'];
    $dayLabels = $periodSchedule['days'];
?>
<div class="card" style="margin-bottom: 30px; border-top: 4px solid var(--primary);">
    <div class="card-header" style="background:#f8fafc; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <h3 style="margin:0; font-size:18px;">Pendaftaran Pretes - <?= sanitize($active_pretes['periode']) ?></h3>
        <?= renderPretesTargetProgramBadge($active_pretes['target_program'] ?? 'Semua') ?>
    </div>
    <div class="card-body">
        
        <?php if ($annPretes): ?>
        <!-- Pengumuman Resmi Pendaftaran Pretes dari Admin -->
        <div class="announcement-card" style="margin-bottom:24px; background:#f0f9ff; border-left:4px solid #0ea5e9; padding:18px 20px; border-radius:10px;">
            <div class="ann-title" style="color:#0284c7; font-weight:700; font-size:16px; margin-bottom:4px; display:flex; align-items:center; gap:8px;">
                <span>📢</span> <?= sanitize($annPretes['judul']) ?>
            </div>
            <div style="font-size:12px; color:#64748b; margin-bottom:10px;">
                🕐 Dipublikasikan pada: <?= date('d M Y, H:i', strtotime($annPretes['created_at'])) ?> WIB
            </div>
            <div style="font-size:14px; color:#334155; line-height:1.6;">
                <?= nl2br(sanitize($annPretes['konten'])) ?>
            </div>
            <div style="margin-top:12px; padding-top:10px; border-top:1px dashed #bae6fd; font-size:13px; color:#0369a1; line-height:1.6;">
                <?php if ($rangeLabel !== ''): ?>
                📅 <strong>Tanggal Pelaksanaan:</strong> Tanggal <strong><?= sanitize($rangeLabel) ?></strong> (Senin s.d. Jumat).<br>
                <?php endif; ?>
                🕒 <strong>Waktu Pelaksanaan:</strong> Seluruh ujian pretes dilaksanakan pada <strong><?= sanitize($jamTampil) ?></strong>.
            </div>
        </div>
        <?php else: ?>
        <!-- Info Pengumuman Pelaksanaan & Jam (Jam 1 / 13.00) -->
        <div class="announcement-card" style="margin-bottom:24px; background:#f0f9ff; border-left:4px solid #0ea5e9; padding:18px 20px; border-radius:10px;">
            <div class="ann-title" style="color:#0284c7; font-weight:700; font-size:16px; margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                <span>📢</span> Informasi Pelaksanaan Pre-tes Mahasiswa (Tutorial Ubudiyah Unisda)
            </div>
            <div style="font-size:14px; color:#334155; line-height:1.6;">
                <?php if ($rangeLabel !== ''): ?>
                <p style="margin:0 0 8px;">
                    📅 <strong>Tanggal Pelaksanaan:</strong> Tanggal <strong><?= sanitize($rangeLabel) ?></strong> (Senin s.d. Jumat).
                </p>
                <?php endif; ?>
                <p style="margin:0 0 8px;">
                    🕒 <strong>Waktu Pelaksanaan:</strong> Seluruh ujian pretes dilaksanakan pada <strong><?= sanitize($jamTampil) ?></strong>.
                </p>
                <p style="margin:0;">
                    👥 <strong>Penentuan Kuota:</strong> Kuota pada setiap hari ditentukan langsung berdasarkan <strong>jumlah Dosen Penguji</strong> yang bersedia menguji pada hari tersebut.
                </p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Form Pemilihan Hari & Unggah Pasfoto Resmi -->
        <form method="POST" enctype="multipart/form-data" id="formDaftarPretes">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="daftar_pretes">
            <input type="hidden" name="foto_data" id="foto_data_input" value="">

            <label style="font-weight:600; margin-bottom:10px; display:block; font-size:15px; color:#1e293b;">
                1. Pilih Hari <?= $shortRange !== '' ? '&amp; Tanggal ' : '' ?>Pelaksanaan Pretes (<?= $shortRange !== '' ? sanitize($shortRange) . ', ' : '' ?><?= sanitize($jamTampil) ?>) <span style="color:red">*</span>
            </label>

            <div class="form-group" style="margin-bottom:24px;">
                <select name="hari_pilihan" required style="width:100%; padding:14px 16px; border:2px solid #e2e8f0; border-radius:10px; font-size:15px; background-color:#fff; color:#334155; outline:none; cursor:pointer;">
                    <option value="">-- Pilih Hari &amp; Jadwal Ujian --</option>
                    <?php 
                    $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
                    foreach ($days as $day): 
                        $dayLower = strtolower($day);
                        $kuota  = (int)($active_pretes["kuota_$dayLower"] ?? 0);
                        $terisi = $registeredCounts[$day] ?? 0;
                        $sisa   = $kuota - $terisi;
                        $isFull = ($kuota <= 0) || ($sisa <= 0);
                        $labelDay = $dayLabels[$day] ?? $day;
                    ?>
                    <option value="<?= $day ?>" <?= $isFull ? 'disabled style="color:#94a3b8;"' : '' ?>>
                        <?= sanitize($labelDay) ?> <?= $isFull ? ($kuota <= 0 ? '(Belum ada dosen penguji/kuota)' : '(Penuh)') : "(Sisa $sisa kursi dari total kuota $kuota)" ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════
                 2. UNGGAH / AMBIL PASFOTO RESMI & VERIFIKASI WAJAH AI
                 ═══════════════════════════════════════════════════════════════ -->
            <label style="font-weight:600; margin-bottom:10px; display:block; font-size:15px; color:#1e293b;">
                2. Unggah / Ambil Pasfoto Resmi Peserta Pretes (Wajib) <span style="color:red">*</span>
            </label>

            <div class="card" style="margin-bottom:26px; border:1.5px solid #cbd5e1; border-radius:12px; background:#fff; overflow:hidden;">
                <div style="background:#f8fafc; padding:12px 18px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                    <span style="font-weight:700; color:#334155; font-size:13.5px;">📸 Ketentuan Pasfoto Resmi</span>
                    <span class="badge" style="background:#ecfdf5; color:#047857; font-weight:700; font-size:11px; border:1px solid #a7f3d0;">
                        AI Face Verification Aktif
                    </span>
                </div>
                <div style="padding:18px;">
                    <p style="margin:0 0 16px; font-size:13.5px; color:#475569; line-height:1.55;">
                        Harap gunakan <strong>pasfoto resmi terbaru</strong> perorangan (berbusana muslim rapi atau jas almamater). Pastikan <strong>seluruh bagian wajah menghadap depan dan tampak jelas</strong> (tidak memakai masker, kacamata hitam, dan tidak terpotong di tepi). Sistem AI akan memverifikasi kejelasan wajah secara otomatis sebelum Anda dapat menyelesaikan pendaftaran.
                    </p>

                    <!-- Pilihan Sumber Foto: Unggah File vs Kamera Langsung -->
                    <div style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;">
                        <button type="button" id="tabBtnUpload" class="btn btn-secondary" onclick="switchPhotoMode('upload')" style="display:inline-flex; align-items:center; gap:6px; padding:9px 16px; font-weight:700; font-size:13.5px; border-radius:8px; border:2px solid #0f766e; background:#f0fdf4; color:#115e59; cursor:pointer;">
                            📁 Unggah File Foto (Galeri/PC)
                        </button>
                        <button type="button" id="tabBtnCamera" class="btn btn-secondary" onclick="switchPhotoMode('camera')" style="display:inline-flex; align-items:center; gap:6px; padding:9px 16px; font-weight:700; font-size:13.5px; border-radius:8px; border:2px solid #e2e8f0; background:#fff; color:#475569; cursor:pointer;">
                            📷 Buka Kamera &amp; Ambil Foto Langsung
                        </button>
                    </div>

                    <!-- Panel Mode 1: Unggah File -->
                    <div id="panelUpload" style="display:block;">
                        <div id="dropzoneBox" style="border:2px dashed #94a3b8; border-radius:10px; padding:24px 16px; text-align:center; background:#f8fafc; cursor:pointer; transition:all 0.2s ease;">
                            <div style="font-size:36px; margin-bottom:8px;">📤</div>
                            <div style="font-weight:700; color:#1e293b; margin-bottom:4px; font-size:14px;">Klik di sini untuk memilih pasfoto dari perangkat Anda</div>
                            <div style="font-size:12px; color:#64748b;">Mendukung format JPG, JPEG, PNG, atau WEBP (Maksimal 5 MB)</div>
                            <input type="file" id="fileFotoInput" name="foto_file" accept="image/*" style="display:none;" onchange="handleFileSelected(this)">
                        </div>
                    </div>

                    <!-- Panel Mode 2: Kamera Langsung -->
                    <div id="panelCamera" style="display:none;">
                        <div style="background:#0f172a; border-radius:10px; padding:12px; text-align:center; position:relative; overflow:hidden; max-width:440px; margin:0 auto;">
                            <div style="position:relative; width:100%; height:320px; background:#000; border-radius:8px; overflow:hidden; display:flex; align-items:center; justify-content:center;">
                                <video id="cameraVideo" autoplay playsinline muted style="width:100%; height:100%; object-fit:cover;"></video>
                                <!-- Panduan Oval Wajah -->
                                <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); width:170px; height:230px; border:2.5px dashed rgba(255,255,255,0.85); border-radius:50%; box-shadow:0 0 0 9999px rgba(0,0,0,0.4); pointer-events:none;">
                                    <div style="position:absolute; bottom:-30px; left:50%; transform:translateX(-50%); white-space:nowrap; background:rgba(0,0,0,0.75); color:#fff; font-size:11px; font-weight:600; padding:3px 10px; border-radius:4px;">
                                        Arahkan wajah ke dalam oval
                                    </div>
                                </div>
                            </div>
                            <div style="display:flex; justify-content:center; gap:8px; margin-top:12px; flex-wrap:wrap;">
                                <button type="button" class="btn btn-success" onclick="takeSnapshot()" style="padding:10px 20px; font-weight:700; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
                                    📸 Jepret Foto
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="toggleCameraFace()" style="padding:10px 14px; font-weight:600; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
                                    🔄 Balik Kamera
                                </button>
                                <button type="button" class="btn btn-danger" onclick="closeCameraView()" style="padding:10px 14px; font-weight:600; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
                                    ❌ Batal
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Area Preview Hasil Foto & Status Verifikasi Wajah AI -->
                    <div id="previewContainer" style="display:none; margin-top:20px; padding-top:18px; border-top:1px dashed #cbd5e1;">
                        <div style="display:flex; gap:18px; align-items:flex-start; flex-wrap:wrap;">
                            <!-- Kotak Preview Pasfoto 3x4 -->
                            <div style="position:relative; width:120px; height:160px; border-radius:8px; overflow:hidden; border:2px solid #cbd5e1; box-shadow:0 2px 8px rgba(0,0,0,0.06); background:#f8fafc; flex-shrink:0;" id="photoPreviewBox">
                                <canvas id="photoPreviewCanvas" style="width:100%; height:100%; object-fit:cover; display:block;"></canvas>
                                <div id="verifBadge" style="display:none; position:absolute; bottom:6px; left:6px; right:6px; background:#16a34a; color:#fff; font-size:10px; font-weight:700; text-align:center; padding:3px 4px; border-radius:4px; box-shadow:0 2px 4px rgba(0,0,0,0.2);">
                                    ✓ Terverifikasi
                                </div>
                            </div>

                            <!-- Keterangan Status Hasil Verifikasi -->
                            <div style="flex:1; min-width:240px;">
                                <div id="verifStatusAlert" class="alert" style="margin:0 0 12px; padding:12px 14px; font-size:13.5px; line-height:1.55; border-radius:8px;">
                                    <!-- Diisi via JS -->
                                </div>
                                <button type="button" class="btn btn-secondary" onclick="resetPhotoState()" style="padding:8px 14px; font-size:13px; font-weight:600; border-radius:6px; display:inline-flex; align-items:center; gap:6px;">
                                    🔄 Ganti / Ambil Ulang Foto
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Tombol Konfirmasi Pendaftaran Pretes -->
            <button type="submit" id="btnSubmitDaftarPretes" name="daftar_pretes_btn" value="1" class="btn btn-primary" disabled style="width: 100%; padding: 14px; font-size: 15px; font-weight: 700; opacity: 0.55; cursor: not-allowed; transition: all 0.3s ease;">
                ✍️ Konfirmasi &amp; Daftar Pretes Sekarang
            </button>
            <div id="submitLockedNotice" style="margin-top:10px; font-size:13px; color:#b91c1c; text-align:center; display:flex; align-items:center; justify-content:center; gap:6px;">
                <span>🔒</span> Tombol pendaftaran terkunci. Silakan unggah atau ambil pasfoto resmi hingga terverifikasi jelas oleh sistem AI.
            </div>
        </form>

    </div>
</div>

<?php elseif ($active_pretes && !$isEligibleForPretes): ?>
<?php $targetLabel = normalizePretesTargetProgram($active_pretes['target_program'] ?? 'Semua'); ?>
<!-- Halaman tertutup bagi mahasiswa di luar program target periode aktif -->
<div class="empty-state card" style="padding:40px 20px; text-align:center;">
    <div class="icon" style="font-size:48px; margin-bottom:12px;">🔒</div>
    <h3 style="margin-bottom:8px;">Pendaftaran Pretes Periode Ini Khusus Program <?= sanitize($targetLabel) ?></h3>
    <p style="color:#64748b; max-width:560px; margin:0 auto 18px auto; line-height:1.6;">
        Pendaftaran pretes <strong><?= sanitize($active_pretes['periode']) ?></strong> yang sedang berlangsung saat ini hanya dibuka untuk mahasiswa program <strong><?= sanitize($targetLabel) ?></strong>. Status program Anda saat ini tercatat sebagai <strong><?= sanitize(normalizeJenisProgram($user['jenis_program'] ?? 'Reguler')) ?></strong>.
    </p>
    <div style="display:flex; justify-content:center; gap:10px; flex-wrap:wrap;">
        <a href="<?= BASE_URL ?>/pretes-hasil.php" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px; width:auto; text-decoration:none; padding:10px 20px; font-weight:600; border-radius:8px;">
            📊 Lihat Hasil Pretes
        </a>
        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:8px; width:auto; text-decoration:none; padding:10px 20px; font-weight:600; border-radius:8px;">
            🏠 Kembali ke Dashboard
        </a>
    </div>
</div>

<?php else: ?>
<!-- Fallback jika belum ada periode pretes yang dibuka atau periode telah selesai -->
<div class="empty-state card" style="padding:40px 20px; text-align:center;">
    <div class="icon" style="font-size:48px; margin-bottom:12px;">🔒</div>
    <h3 style="margin-bottom:8px;">Pendaftaran Pretes Sedang Ditutup / Telah Berakhir</h3>
    <p style="color:#64748b; max-width:550px; margin:0 auto 18px auto; line-height:1.6;">
        Saat ini pendaftaran pretes sedang ditutup karena periode pelaksanaan pretes telah berakhir. Bagi mahasiswa yang belum mengikuti atau belum lulus pretes, Anda diwajibkan untuk mendaftarkan diri pada program Tutorial LPPAI.
    </p>
    <div style="display:flex; justify-content:center; gap:10px; flex-wrap:wrap;">
        <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="btn btn-success" style="display:inline-flex; align-items:center; gap:8px; width:auto; text-decoration:none; padding:10px 22px; font-weight:700; border-radius:8px;">
            📝 Daftar Program Tutorial Gelombang 1 &rarr;
        </a>
        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:8px; width:auto; text-decoration:none; padding:10px 20px; font-weight:600; border-radius:8px;">
            🏠 Kembali ke Dashboard
        </a>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     SKRIP CLIENT-SIDE DETEKSI WAJAH & KAMERA LIVE PRETES
     ═══════════════════════════════════════════════════════════════ -->
<script src="<?= BASE_URL ?>/assets/js/pretes-face-verifier.js?v=<?= time() ?>"></script>
<script>
let currentPhotoMode = 'upload';

function switchPhotoMode(mode) {
    currentPhotoMode = mode;
    const btnUpload = document.getElementById('tabBtnUpload');
    const btnCamera = document.getElementById('tabBtnCamera');
    const panelUpload = document.getElementById('panelUpload');
    const panelCamera = document.getElementById('panelCamera');

    if (!btnUpload || !btnCamera) return;

    if (mode === 'upload') {
        PretesFaceVerifier.stopCamera();
        btnUpload.style.border = '2px solid #0f766e';
        btnUpload.style.background = '#f0fdf4';
        btnUpload.style.color = '#115e59';
        btnCamera.style.border = '2px solid #e2e8f0';
        btnCamera.style.background = '#fff';
        btnCamera.style.color = '#475569';
        panelUpload.style.display = 'block';
        panelCamera.style.display = 'none';
    } else {
        btnCamera.style.border = '2px solid #0f766e';
        btnCamera.style.background = '#f0fdf4';
        btnCamera.style.color = '#115e59';
        btnUpload.style.border = '2px solid #e2e8f0';
        btnUpload.style.background = '#fff';
        btnUpload.style.color = '#475569';
        panelUpload.style.display = 'none';
        panelCamera.style.display = 'block';
        startCameraView();
    }
}

function startCameraView() {
    const video = document.getElementById('cameraVideo');
    if (!video) return;
    PretesFaceVerifier.startCamera(video, null, function(err) {
        alert("Gagal mengakses kamera: " + (err.message || err) + "\nSilakan gunakan opsi Unggah File Foto.");
        switchPhotoMode('upload');
    });
}

function closeCameraView() {
    PretesFaceVerifier.stopCamera();
    switchPhotoMode('upload');
}

function toggleCameraFace() {
    const video = document.getElementById('cameraVideo');
    if (!video) return;
    PretesFaceVerifier.toggleFacingMode(video, null, function(err) {
        alert("Gagal beralih kamera: " + (err.message || err));
    });
}

function takeSnapshot() {
    const video = document.getElementById('cameraVideo');
    if (!video || !video.videoWidth) {
        alert("Kamera belum siap. Mohon tunggu video aktif.");
        return;
    }
    const canvas = PretesFaceVerifier.captureSnapshot(video);
    PretesFaceVerifier.stopCamera();
    const panelCam = document.getElementById('panelCamera');
    if (panelCam) panelCam.style.display = 'none';
    processAndVerifyImage(canvas);
}

// Buka file explorer saat dropzone diklik
document.addEventListener('DOMContentLoaded', function() {
    const dropzone = document.getElementById('dropzoneBox');
    const fileInput = document.getElementById('fileFotoInput');
    if (dropzone && fileInput) {
        dropzone.addEventListener('click', function() {
            fileInput.click();
        });
    }
});

function handleFileSelected(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (!file.type.startsWith('image/')) {
        alert("Harap pilih file gambar (JPG, PNG, atau WEBP).");
        return;
    }
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
            const canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth || img.width;
            canvas.height = img.naturalHeight || img.height;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0);
            processAndVerifyImage(canvas);
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function processAndVerifyImage(sourceCanvas) {
    const previewContainer = document.getElementById('previewContainer');
    const previewCanvas = document.getElementById('photoPreviewCanvas');
    const statusAlert = document.getElementById('verifStatusAlert');
    const badge = document.getElementById('verifBadge');
    const photoBox = document.getElementById('photoPreviewBox');
    const submitBtn = document.getElementById('btnSubmitDaftarPretes');
    const lockNotice = document.getElementById('submitLockedNotice');
    const fotoDataInput = document.getElementById('foto_data_input');

    if (!previewContainer || !previewCanvas) return;

    previewContainer.style.display = 'block';
    previewCanvas.width = 360;
    previewCanvas.height = 480;
    const ctx = previewCanvas.getContext('2d');
    ctx.drawImage(sourceCanvas, 0, 0, previewCanvas.width, previewCanvas.height);

    badge.style.display = 'none';
    photoBox.style.border = '2px solid #cbd5e1';
    submitBtn.disabled = true;
    submitBtn.style.opacity = '0.55';
    submitBtn.style.cursor = 'not-allowed';
    if (lockNotice) lockNotice.style.display = 'flex';
    fotoDataInput.value = '';

    statusAlert.className = 'alert alert-info';
    statusAlert.style.background = '#eff6ff';
    statusAlert.style.borderColor = '#bfdbfe';
    statusAlert.style.color = '#1e40af';
    statusAlert.innerHTML = '<strong>⏳ Memverifikasi Wajah...</strong><br>Sistem AI sedang memeriksa keberadaan dan kelengkapan wajah pada pasfoto Anda.';

    // Jalankan engine verifikasi wajah
    PretesFaceVerifier.verifyFace(sourceCanvas).then(function(res) {
        if (res.valid) {
            statusAlert.className = 'alert alert-success';
            statusAlert.style.background = '#f0fdf4';
            statusAlert.style.borderColor = '#86efac';
            statusAlert.style.color = '#15803d';
            statusAlert.innerHTML = '<strong>✅ Foto Resmi Terverifikasi!</strong><br>' + (res.message || 'Wajah terdeteksi jelas dan proporsional.');

            badge.style.display = 'block';
            photoBox.style.border = '2.5px solid #16a34a';

            // Beri kotak highlight wajah pada preview
            if (res.box) {
                const scaleX = previewCanvas.width / sourceCanvas.width;
                const scaleY = previewCanvas.height / sourceCanvas.height;
                ctx.strokeStyle = '#22c55e';
                ctx.lineWidth = 3;
                ctx.strokeRect(res.box.x * scaleX, res.box.y * scaleY, res.box.width * scaleX, res.box.height * scaleY);
            }

            // Simpan data base64 jpeg
            const jpegData = previewCanvas.toDataURL('image/jpeg', 0.92);
            fotoDataInput.value = jpegData;

            // Buka gembok tombol pendaftaran
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
            if (lockNotice) lockNotice.style.display = 'none';
        } else {
            statusAlert.className = 'alert alert-danger';
            statusAlert.style.background = '#fef2f2';
            statusAlert.style.borderColor = '#fca5a5';
            statusAlert.style.color = '#b91c1c';
            statusAlert.innerHTML = '<strong>❌ Foto Belum Memenuhi Syarat:</strong><br>' + (res.reason || 'Wajah tidak terdeteksi jelas.') + '<div style="margin-top:6px; font-size:12.5px;">Silakan klik tombol <em>Ganti / Ambil Ulang Foto</em> dan pastikan wajah berada di tengah dengan pencahayaan terang.</div>';

            badge.style.display = 'none';
            photoBox.style.border = '2.5px solid #ef4444';
            fotoDataInput.value = '';
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.55';
            submitBtn.style.cursor = 'not-allowed';
            if (lockNotice) lockNotice.style.display = 'flex';
        }
    });
}

function resetPhotoState() {
    PretesFaceVerifier.stopCamera();
    const previewContainer = document.getElementById('previewContainer');
    const fotoDataInput = document.getElementById('foto_data_input');
    const fileInput = document.getElementById('fileFotoInput');
    const submitBtn = document.getElementById('btnSubmitDaftarPretes');
    const lockNotice = document.getElementById('submitLockedNotice');

    if (previewContainer) previewContainer.style.display = 'none';
    if (fotoDataInput) fotoDataInput.value = '';
    if (fileInput) fileInput.value = '';
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.55';
        submitBtn.style.cursor = 'not-allowed';
    }
    if (lockNotice) lockNotice.style.display = 'flex';

    switchPhotoMode('upload');
}

window.addEventListener('beforeunload', function() {
    PretesFaceVerifier.stopCamera();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
