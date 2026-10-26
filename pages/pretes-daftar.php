<?php
/**
 * LPPAI Corner - Daftar Pretes (Alur Baru Berbasis Pilihan Hari & Jam 13.00)
 */
define('PAGE_TITLE', 'Daftar Pretes');
require_once __DIR__ . '/../includes/auth.php';
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

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("ALTER TABLE pretes_registrations MODIFY pretes_schedule_id INT NULL");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN hari_pilihan VARCHAR(20) DEFAULT NULL");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN jam VARCHAR(50) DEFAULT '13.00 WIB (Jam 1)'");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN dosen_penguji VARCHAR(150) DEFAULT NULL");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN ruangan VARCHAR(100) DEFAULT NULL");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
} catch (Exception $e) {}

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

// Ambil Periode Pretes Aktif
$active_pretes = $pdo->query("SELECT * FROM master_pretes WHERE status = 'aktif' ORDER BY created_at DESC LIMIT 1")->fetch();

// Cek Registrasi Pretes Mahasiswa Saat Ini
$stmt = $pdo->prepare("
    SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan as ruangan_jadwal
    FROM pretes_registrations pr
    LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
    WHERE pr.user_id = ?
    ORDER BY pr.tanggal_daftar DESC
    LIMIT 1
");
$stmt->execute([$user['id']]);
$existingReg = $stmt->fetch();

// Hitung Pendaftar per Hari untuk Menghitung Sisa Kuota
$registeredCounts = ['Senin' => 0, 'Selasa' => 0, 'Rabu' => 0, 'Kamis' => 0, 'Jumat' => 0];
if ($active_pretes) {
    $stmtCount = $pdo->prepare("
        SELECT hari_pilihan, COUNT(*) as cnt 
        FROM pretes_registrations 
        WHERE (periode = ? OR periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%')
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
    $validDays    = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];

    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid. Silakan muat ulang halaman.';
        $msgType = 'danger';
    } elseif ($existingReg) {
        $message = 'Anda sudah terdaftar untuk pretes.';
        $msgType = 'warning';
    } elseif (!$active_pretes) {
        $message = 'Pendaftaran pretes sedang ditutup atau belum dibuka.';
        $msgType = 'danger';
    } elseif (!in_array($hari_pilihan, $validDays)) {
        $message = 'Silakan pilih hari pelaksanaan yang valid.';
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
                INSERT INTO pretes_registrations (user_id, hari_pilihan, jam, periode, status) 
                VALUES (?, ?, ?, ?, 'terdaftar')
            ");
            $stmt->execute([$user['id'], $hari_pilihan, $jamPretes, $active_pretes['periode']]);

            $message = "Pendaftaran pretes berhasil! Anda telah memilih hari $hari_pilihan pukul 13.00 WIB (Jam 1).";
            $msgType = 'success';

            // Update counter lokal
            $registeredCounts[$hari_pilihan]++;

            // Refresh status registrasi
            $stmt = $pdo->prepare("
                SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan as ruangan_jadwal
                FROM pretes_registrations pr
                LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
                WHERE pr.user_id = ?
                ORDER BY pr.tanggal_daftar DESC
                LIMIT 1
            ");
            $stmt->execute([$user['id']]);
            $existingReg = $stmt->fetch();
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
                    <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px; padding:10px 20px; font-weight:600; text-decoration:none;">
                        🔑 Lihat Jadwal & Credentials Tes Tulis CBT
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     FORM PENDAFTARAN JIKA BELUM TERDAFTAR
     ═══════════════════════════════════════════════════════════════ -->
<?php elseif ($active_pretes): ?>
<div class="card" style="margin-bottom: 30px; border-top: 4px solid var(--primary);">
    <div class="card-header" style="background:#f8fafc;">
        <h3 style="margin:0; font-size:18px;">Pendaftaran Pretes - <?= sanitize($active_pretes['periode']) ?></h3>
    </div>
    <div class="card-body">
        
        <!-- Info Pengumuman Pelaksanaan & Jam (Jam 1 / 13.00) -->
        <div class="announcement-card" style="margin-bottom:24px; background:#f0f9ff; border-left:4px solid #0ea5e9; padding:18px 20px; border-radius:10px;">
            <div class="ann-title" style="color:#0284c7; font-weight:700; font-size:16px; margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                <span>📢</span> Informasi Pelaksanaan Pre-tes Mahasiswa
            </div>
            <div style="font-size:14px; color:#334155; line-height:1.6;">
                <p style="margin:0 0 8px;">
                    🕒 <strong>Waktu Pelaksanaan:</strong> Seluruh ujian pretes dilaksanakan pada <strong>Pukul 13.00 WIB (Jam 1 Siang)</strong>.
                </p>
                <p style="margin:0;">
                    👥 <strong>Penentuan Kuota:</strong> Kuota pada setiap hari ditentukan langsung berdasarkan <strong>jumlah Dosen Penguji</strong> yang bersedia menguji pada hari tersebut.
                </p>
            </div>
        </div>

        <!-- Form Pemilihan Hari -->
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="daftar_pretes">

            <label style="font-weight:600; margin-bottom:10px; display:block; font-size:15px; color:#1e293b;">
                Pilih Hari Pelaksanaan Pretes (Jam: 13.00 WIB / Jam 1) <span style="color:red">*</span>
            </label>

            <div class="form-group" style="margin-bottom:24px;">
                <select name="hari_pilihan" required style="width:100%; padding:14px 16px; border:2px solid #e2e8f0; border-radius:10px; font-size:15px; background-color:#fff; color:#334155; outline:none; cursor:pointer;">
                    <option value="">-- Pilih Hari --</option>
                    <?php 
                    $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
                    foreach ($days as $day): 
                        $dayLower = strtolower($day);
                        $kuota  = (int)($active_pretes["kuota_$dayLower"] ?? 0);
                        $terisi = $registeredCounts[$day] ?? 0;
                        $sisa   = $kuota - $terisi;
                        $isFull = ($kuota <= 0) || ($sisa <= 0);
                    ?>
                    <option value="<?= $day ?>" <?= $isFull ? 'disabled style="color:#94a3b8;"' : '' ?>>
                        <?= $day ?> <?= $isFull ? ($kuota <= 0 ? '(Belum ada dosen penguji/kuota)' : '(Penuh)') : "(Sisa $sisa kursi dari total kuota $kuota)" ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" name="daftar_pretes_btn" value="1" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 15px; font-weight: 600;">
                ✍️ Daftar Pretes Sekarang
            </button>
        </form>

    </div>
</div>

<?php else: ?>
<!-- Fallback jika belum ada periode pretes yang dibuka -->
<div class="empty-state card">
    <div class="icon">🔒</div>
    <h3>Pendaftaran Pretes Belum Dibuka</h3>
    <p>Saat ini belum ada periode pretes yang membuka pendaftaran. Silakan tunggu informasi dari admin/TU LPPAI.</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
