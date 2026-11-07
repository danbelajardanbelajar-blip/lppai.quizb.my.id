<?php
/**
 * LPPAI Corner - Halaman Absensi Pretes Mahasiswa
 * Menangani scan kamera mandiri (in-app camera scanner) dan konfirmasi token QR absensi.
 */
define('PAGE_TITLE', 'Absensi Pretes');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pretes-helper.php';
header('Cache-Control: private, no-store');

$token = trim((string)($_GET['token'] ?? ''));

// =========================================================================
// KASUS 1: Dibuka via Scan URL Eksternal (membawa parameter ?token=...)
// =========================================================================
if (!empty($token)) {
    if (!preg_match('/^[a-f0-9]{48}$/D', $token)) {
        http_response_code(400);
        exit('QR absensi pretes tidak valid.');
    }
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/index.php?redirect=' . rawurlencode('absensi-pretes.php?token=' . $token));
        exit;
    }
    requireLogin();
    $user = getCurrentUser();
    if ($user['role'] !== 'mahasiswa') {
        http_response_code(403);
        exit('Absensi hanya untuk mahasiswa peserta pretes.');
    }
    $pdo = getDBConnection();
    ensurePretesFeatures($pdo);

    $stmt = $pdo->prepare('SELECT *, (open_until > NOW()) AS is_open FROM pretes_attendance_sessions WHERE token = ?');
    $stmt->execute([$token]);
    $session = $stmt->fetch();
    if (!$session) {
        http_response_code(404);
        exit('QR absensi pretes tidak ditemukan.');
    }

    $message = '';
    $msgType = 'info';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) {
                throw new InvalidArgumentException('Sesi tidak valid. Muat ulang halaman.');
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT *, (open_until > NOW()) AS is_open FROM pretes_attendance_sessions WHERE id = ? FOR UPDATE');
            $stmt->execute([$session['id']]);
            $session = $stmt->fetch();
            if (!$session || !$session['is_open']) {
                throw new InvalidArgumentException('Absensi ditutup. Silakan hubungi dosen penguji di ruangan.');
            }
            $stmt = $pdo->prepare('SELECT * FROM pretes_registrations WHERE user_id = ? AND periode = ? FOR UPDATE');
            $stmt->execute([$user['id'], $session['periode']]);
            $registration = null;
            foreach ($stmt->fetchAll() as $row) {
                if (pretesScopeHash(pretesScope($row)) === $session['scope_hash']) {
                    $registration = $row;
                }
            }
            if (!$registration) {
                throw new InvalidArgumentException('Anda tidak terdaftar pada hari, penguji, dan ruangan sesi ini.');
            }
            $stmt = $pdo->prepare('INSERT INTO pretes_attendance_records (registration_id, session_id, attended_at) VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE session_id = VALUES(session_id), attended_at = NOW()');
            $stmt->execute([$registration['id'], $session['id']]);
            $pdo->prepare("UPDATE pretes_registrations SET status = 'hadir' WHERE id = ? AND user_id = ?")->execute([$registration['id'], $user['id']]);
            $pdo->commit();
            $message = 'Kehadiran Anda berhasil dicatat. Status absensi: HADIR.';
            $msgType = 'success';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Gagal merekam absensi. Silakan coba kembali.';
            if (!($e instanceof InvalidArgumentException)) error_log('Absensi pretes: ' . $e->getMessage());
            $msgType = 'danger';
        }
    }

    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card" style="max-width: 650px; margin: 20px auto;">
        <div class="card-header" style="background:#10b981; color:white;">
            📷 Konfirmasi Kehadiran Pretes
        </div>
        <div class="card-body">
            <?php if ($message): ?>
                <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
            <?php endif; ?>

            <p style="margin-bottom:8px;">Nama: <strong><?= sanitize($user['nama_lengkap']) ?></strong> (<?= sanitize($user['nim']) ?>)</p>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin: 14px 0; font-size:13.5px; line-height:1.7;">
                Periode: <strong><?= sanitize($session['periode']) ?></strong><br>
                Hari: <strong><?= sanitize($session['hari']) ?></strong> · 13.00 WIB<br>
                Penguji: <strong><?= sanitize($session['penguji'] ?: 'Belum ditentukan') ?></strong><br>
                Ruangan: <strong><?= sanitize($session['ruangan'] ?: 'Belum ditentukan') ?></strong>
            </div>

            <?php if ($session['is_open'] && $msgType !== 'success'): ?>
                <form method="POST" data-no-spa>
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <button class="btn btn-success" style="width:100%; padding:10px; font-weight:700;">✅ Konfirmasi Saya Hadir</button>
                </form>
            <?php elseif (!$session['is_open']): ?>
                <div class="alert alert-warning">⏳ Sesi absensi ini saat ini sedang ditutup. Minta dosen penguji membuka absensi di ruangan.</div>
            <?php endif; ?>

            <div style="margin-top:20px; text-align:center;">
                <a href="<?= BASE_URL ?>/absensi-pretes.php" class="btn btn-sm btn-secondary">🔄 Buka Kamera Scanner</a>
                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-sm btn-outline-primary" style="margin-left:8px;">🏠 Kembali ke Dashboard</a>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// =========================================================================
// KASUS 2: Dibuka Langsung dari Menu Sidebar (Halaman Pemindai Kamera Mandiri)
// =========================================================================
requireLogin();

$user = getCurrentUser();
$isAdminUser = isAdmin();
$isDosenUser = isDosen();

$pdo = getDBConnection();
ensurePretesFeatures($pdo);

// Cek Periode Pretes Aktif
$activePretes = null;
try {
    $activePretes = $pdo->query("SELECT * FROM master_pretes WHERE status = 'aktif' ORDER BY id DESC LIMIT 1")->fetch();
} catch (Exception $e) {}

// Ambil data registrasi pretes mahasiswa
$myPretesReg = null;
try {
    $stmtReg = $pdo->prepare("
        SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan as ruangan_jadwal
        FROM pretes_registrations pr
        LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
        WHERE pr.user_id = ?
        ORDER BY pr.tanggal_daftar DESC
        LIMIT 1
    ");
    $stmtReg->execute([$user['id']]);
    $myPretesReg = $stmtReg->fetch();
} catch (Exception $e) {
    error_log("Pretes registration lookup error: " . $e->getMessage());
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

// Cek status kehadiran
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
    } catch (Exception $e) {
        error_log("Pretes attendance lookup error: " . $e->getMessage());
    }
}

// Cek hasil kelulusan pretes jika ada
$pretesResult = null;
try {
    $stmtHasil = $pdo->prepare("SELECT * FROM pretes_results WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmtHasil->execute([$user['id']]);
    $pretesResult = $stmtHasil->fetch();
} catch (Exception $e) {
    try {
        $stmtHasil = $pdo->prepare("SELECT * FROM pretes_results WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmtHasil->execute([$user['id']]);
        $pretesResult = $stmtHasil->fetch();
    } catch (Exception $e2) {}
}

include __DIR__ . '/includes/header.php';
?>

<div style="max-width: 800px; margin: 0 auto;">
    <!-- Header Halaman -->
    <div style="margin-bottom: 20px;">
        <h2 style="font-size: 22px; font-weight: 800; color: #1e293b; margin: 0 0 6px 0;">
            📷 Absensi Pretes
        </h2>
        <p style="color: #64748b; font-size: 14px; margin: 0;">
            Pindai QR Code absensi pretes yang disediakan oleh Dosen Penguji di dalam ruangan ujian.
        </p>
    </div>

    <?php if ($isAdminUser): ?>
        <!-- NOTIFIKASI MODE PRATINJAU ADMIN -->
        <div class="alert alert-info" style="margin-bottom: 20px; border-left: 4px solid #3b82f6; background: #eff6ff; color: #1e40af; border-radius: 10px; padding: 14px 18px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div>
                    <strong>👑 Mode Pratinjau Administrator:</strong> Halaman ini adalah antarmuka pemindai mandiri bagi mahasiswa.
                    <div style="font-size: 13px; margin-top: 3px; color: #1e3a8a;">
                        Mahasiswa menggunakan halaman ini untuk memindai QR Code absensi di ruangan ujian menggunakan kamera smartphone.
                    </div>
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="<?= BASE_URL ?>/admin/pretes-peserta.php" class="btn btn-sm btn-primary">📋 Data Peserta Pretes</a>
                    <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-sm btn-outline-primary">Dashboard Admin</a>
                </div>
            </div>
        </div>
    <?php elseif ($isDosenUser): ?>
        <!-- NOTIFIKASI MODE PRATINJAU DOSEN -->
        <div class="alert alert-info" style="margin-bottom: 20px; border-left: 4px solid #3b82f6; background: #eff6ff; color: #1e40af; border-radius: 10px; padding: 14px 18px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div>
                    <strong>👨‍🏫 Mode Pratinjau Dosen Penguji:</strong> Halaman ini adalah tampilan scan QR mahasiswa.
                    <div style="font-size: 13px; margin-top: 3px; color: #1e3a8a;">
                        Untuk membuka sesi QR Code dan melakukan penilaian pretes di ruangan Anda, silakan gunakan menu Penguji Pretes.
                    </div>
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="<?= BASE_URL ?>/dosen/pretes.php" class="btn btn-sm btn-primary">📷 Buka Sesi QR Absensi</a>
                    <a href="<?= BASE_URL ?>/dosen/dashboard.php" class="btn btn-sm btn-outline-primary">Dashboard Dosen</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isAdminUser || $isDosenUser): ?>
        <!-- MODUL SCANNER KAMERA PRETES (MODE PRATINJAU PETUGAS / DOSEN) -->
        <div class="card" style="box-shadow: 0 4px 20px rgba(0,0,0,0.06); border-radius: 14px; margin-bottom: 20px;">
            <div class="card-header" style="background: #10b981; color: white; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <span style="font-weight: 700; font-size: 15px;">📷 Kamera Pemindai QR Absensi Pretes (Mode Uji Coba)</span>
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="btn btn-sm" onclick="switchCameraPretes()" style="background: rgba(255,255,255,0.25); color: #fff; border: none; font-size: 12px; padding: 4px 10px; border-radius: 6px;">
                        🔄 Ganti Kamera
                    </button>
                </div>
            </div>
            <div class="card-body" style="padding: 24px; text-align: center;">
                <div id="pretes-scan-feedback" style="display: none; margin-bottom: 16px; text-align: left;"></div>

                <div id="scanner-viewport-box" style="width: 100%; max-width: 440px; margin: 0 auto 16px auto; border-radius: 12px; overflow: hidden; background: #0f172a; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-height: 280px; position: relative;">
                    <div id="reader_pretes_page" style="width: 100%;"></div>
                </div>

                <p style="color: #64748b; font-size: 13px; margin: 0 0 16px 0;">
                    🔍 Kamera aktif untuk menguji pemindaian QR Code absensi pretes.
                </p>

                <div style="display: flex; justify-content: center; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="restartScannerPretes()">
                        🔄 Muat Ulang Kamera
                    </button>
                    <a href="<?= BASE_URL ?>/<?= $isAdminUser ? 'admin/dashboard.php' : 'dosen/dashboard.php' ?>" class="btn btn-outline-primary btn-sm">
                        🏠 Kembali ke Dashboard
                    </a>
                </div>
            </div>
        </div>

    <?php elseif ($isPretesSelesai): ?>
        <!-- PEMBERITAHUAN JIKA PERIODE PRETES SUDAH SELESAI -->
        <div class="card" style="border-left: 4px solid #f59e0b; margin-bottom: 24px;">
            <div class="card-body" style="padding: 24px;">
                <div style="display: flex; gap: 16px; align-items: flex-start;">
                    <div style="width: 50px; height: 50px; border-radius: 12px; background: #fef3c7; color: #b45309; display: flex; align-items: center; justify-content: center; font-size: 26px; flex-shrink: 0;">
                        ⏳
                    </div>
                    <div>
                        <h3 style="margin: 0 0 6px 0; font-size: 17px; font-weight: 700; color: #92400e;">
                            Periode Pretes Telah Selesai
                        </h3>
                        <p style="margin: 0 0 14px 0; color: #78350f; font-size: 13.5px; line-height: 1.6;">
                            Sesi absensi pretes saat ini telah ditutup karena pelaksanaan pretes telah berakhir. Sistem LPPAI saat ini sedang menjalankan periode Tutorial.
                        </p>
                        
                        <?php if ($myPretesReg): ?>
                            <div style="background: #ffffff; border: 1px solid #fde68a; border-radius: 10px; padding: 14px; margin-bottom: 16px; font-size: 13px; line-height: 1.8;">
                                <strong>Riwayat Pendaftaran Pretes Anda:</strong><br>
                                • Periode: <?= sanitize($myPretesReg['periode']) ?><br>
                                • Hari / Jadwal: <?= sanitize($myPretesReg['hari_pilihan'] ?: 'Reguler') ?> (<?= sanitize($myPretesReg['jam'] ?: '13.00 WIB') ?>)<br>
                                • Dosen Penguji: <?= sanitize($myPretesReg['dosen_penguji'] ?: 'Dosen Penguji') ?><br>
                                • Ruangan: <?= sanitize($myPretesReg['ruangan'] ?? ($myPretesReg['ruangan_jadwal'] ?? '-')) ?><br>
                                • Status Kehadiran: 
                                <?php if ($isHadirPretes): ?>
                                    <span class="badge badge-success">✅ Hadir</span>
                                <?php else: ?>
                                    <span class="badge badge-warning" style="background:#fef3c7; color:#92400e;">⏳ Tidak Hadir / Belum Absen</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <a href="<?= BASE_URL ?>/pretes-hasil.php" class="btn btn-primary" style="font-size: 13px; padding: 7px 16px;">
                                📊 Lihat Hasil Pretes &rarr;
                            </a>
                            <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="btn btn-success" style="font-size: 13px; padding: 7px 16px;">
                                📝 Pendaftaran Tutorial &rarr;
                            </a>
                            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary" style="font-size: 13px; padding: 7px 16px;">
                                🏠 Ke Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif (!$myPretesReg): ?>
        <!-- PEMBERITAHUAN JIKA MAHASISWA BELUM MENDAFTAR PRETES -->
        <div class="card" style="border-left: 4px solid #ef4444; margin-bottom: 24px;">
            <div class="card-body" style="padding: 24px;">
                <div style="display: flex; gap: 16px; align-items: flex-start;">
                    <div style="width: 50px; height: 50px; border-radius: 12px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 26px; flex-shrink: 0;">
                        ⚠️
                    </div>
                    <div>
                        <h3 style="margin: 0 0 6px 0; font-size: 17px; font-weight: 700; color: #991b1b;">
                            Anda Belum Terdaftar di Pretes Periode Aktif
                        </h3>
                        <p style="margin: 0 0 14px 0; color: #7f1d1d; font-size: 13.5px; line-height: 1.6;">
                            Fitur absensi pretes hanya diperuntukkan bagi mahasiswa yang telah melakukan pendaftaran pretes pada periode yang sedang berjalan.
                        </p>
                        <a href="<?= BASE_URL ?>/pretes-daftar.php" class="btn btn-primary" style="font-size: 13px; padding: 7px 16px;">
                            ✍️ Daftar Pretes Sekarang &rarr;
                        </a>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- STATUS JADWAL PRETES MAHASISWA -->
        <div class="card" style="margin-bottom: 20px; border-left: 4px solid var(--primary);">
            <div class="card-body" style="padding: 16px 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div>
                        <div style="font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;">Jadwal Pretes Anda</div>
                        <div style="font-size:16px; font-weight:800; color:#1e293b; margin-top:2px;">
                            Hari <?= sanitize($myPretesReg['hari_pilihan'] ?: 'Reguler') ?> · <?= sanitize($myPretesReg['jam'] ?: '13.00 WIB') ?>
                        </div>
                        <div style="font-size:13px; color:#475569; margin-top:2px;">
                            Ruang: <strong><?= sanitize($myPretesReg['ruangan'] ?? ($myPretesReg['ruangan_jadwal'] ?? '-')) ?></strong> · Penguji: <strong><?= sanitize($myPretesReg['dosen_penguji'] ?: 'Dosen Penguji') ?></strong>
                        </div>
                    </div>
                    <div id="box-status-hadir">
                        <?php if ($isHadirPretes): ?>
                            <span class="badge badge-success" style="font-size:13px; padding:6px 14px;">✅ Hadir Pretes</span>
                        <?php else: ?>
                            <span class="badge badge-warning" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; font-size:13px; padding:6px 14px;">⏳ Belum Absen</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODUL SCANNER KAMERA PRETES -->
        <div class="card" style="box-shadow: 0 4px 20px rgba(0,0,0,0.06); border-radius: 14px;">
            <div class="card-header" style="background: #10b981; color: white; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <span style="font-weight: 700; font-size: 15px;">📷 Kamera Pemindai QR Absensi Pretes</span>
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="btn btn-sm" onclick="switchCameraPretes()" style="background: rgba(255,255,255,0.25); color: #fff; border: none; font-size: 12px; padding: 4px 10px; border-radius: 6px;">
                        🔄 Ganti Kamera
                    </button>
                </div>
            </div>
            <div class="card-body" style="padding: 24px; text-align: center;">
                <div id="pretes-scan-feedback" style="display: none; margin-bottom: 16px; text-align: left;"></div>

                <div id="scanner-viewport-box" style="width: 100%; max-width: 440px; margin: 0 auto 16px auto; border-radius: 12px; overflow: hidden; background: #0f172a; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-height: 280px; position: relative;">
                    <div id="reader_pretes_page" style="width: 100%;"></div>
                </div>

                <p style="color: #64748b; font-size: 13px; margin: 0 0 16px 0;">
                    🔍 Arahkan kamera HP Anda ke QR Code yang ditampilkan oleh Dosen Penguji di depan ruangan ujian.
                </p>

                <div style="display: flex; justify-content: center; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="restartScannerPretes()">
                        🔄 Muat Ulang Kamera
                    </button>
                    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-primary btn-sm">
                        🏠 Kembali ke Dashboard
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php if (($isAdminUser || $isDosenUser) || (!$isPretesSelesai && $myPretesReg)): ?>
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script>
var html5QrCodePretes = null;
var currentCamIndex = 0;
var availableCameras = [];
var isProcessing = false;

function playAudioBeep(type) {
    try {
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        if (type === 'success') {
            osc.frequency.setValueAtTime(587.33, ctx.currentTime);
            osc.frequency.setValueAtTime(880, ctx.currentTime + 0.1);
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.3);
        } else {
            osc.frequency.setValueAtTime(220, ctx.currentTime);
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.3);
        }
    } catch(e) {}
}

function startScannerPretes() {
    var el = document.getElementById('reader_pretes_page');
    if (!el) return;

    Html5Qrcode.getCameras().then(function(cameras) {
        if (!cameras || cameras.length === 0) {
            showFeedback('❌ Kamera tidak ditemukan pada perangkat Anda. Pastikan izin kamera telah diberikan.', 'danger');
            return;
        }
        availableCameras = cameras;
        var camId = cameras[currentCamIndex].id;
        for (var i = 0; i < cameras.length; i++) {
            var label = (cameras[i].label || '').toLowerCase();
            if (label.indexOf('back') !== -1 || label.indexOf('belakang') !== -1 || label.indexOf('rear') !== -1 || label.indexOf('environment') !== -1) {
                camId = cameras[i].id;
                currentCamIndex = i;
                break;
            }
        }

        html5QrCodePretes = new Html5Qrcode('reader_pretes_page');
        html5QrCodePretes.start(
            camId,
            { fps: 10, qrbox: { width: 240, height: 240 }, aspectRatio: 1.0 },
            onScanSuccessPretes,
            function(err) {}
        ).catch(function(err) {
            showFeedback('❌ Gagal mengakses kamera: ' + (err.message || err), 'danger');
        });
    }).catch(function(err) {
        showFeedback('❌ Tidak dapat memuat daftar kamera: ' + (err.message || err), 'danger');
    });
}

function onScanSuccessPretes(decodedText) {
    if (isProcessing) return;
    isProcessing = true;

    if (html5QrCodePretes) {
        try { html5QrCodePretes.pause(); } catch(e) {}
    }

    showFeedback('⏳ <strong>Sedang memverifikasi QR Code...</strong> Mohon tunggu sebentar.', 'info');

    var formData = new FormData();
    formData.append('csrf_token', '<?= csrfToken() ?>');
    formData.append('qr_data', decodedText);

    fetch('<?= BASE_URL ?>/ajax-pretes-scan.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data && data.success) {
            playAudioBeep('success');
            var sess = data.session || {};
            showFeedback('🎉 <strong>' + data.message + '</strong><br>' +
                'Sesi: <strong>' + (sess.hari || '-') + '</strong> · Ruang: <strong>' + (sess.ruangan || '-') + '</strong> · Penguji: <strong>' + (sess.penguji || '-') + '</strong>', 'success');
            
            var boxStatus = document.getElementById('box-status-hadir');
            if (boxStatus) {
                boxStatus.innerHTML = '<span class="badge badge-success" style="font-size:13px; padding:6px 14px;">✅ Hadir Pretes</span>';
            }

            setTimeout(function() {
                if (html5QrCodePretes) html5QrCodePretes.stop();
            }, 3000);
        } else {
            playAudioBeep('error');
            var errMsg = (data && data.message) ? data.message : 'QR Code tidak valid atau sesi absensi ditutup.';
            showFeedback('❌ <strong>Gagal:</strong><br>' + errMsg + '<div style="margin-top:10px;"><button class="btn btn-sm btn-secondary" onclick="resumeScannerPretes()">🔄 Coba Lagi</button></div>', 'danger');
        }
    })
    .catch(function(err) {
        playAudioBeep('error');
        showFeedback('❌ Gangguan koneksi server: ' + (err.message || 'Gagal menghubungi server') + '<div style="margin-top:10px;"><button class="btn btn-sm btn-secondary" onclick="resumeScannerPretes()">🔄 Coba Lagi</button></div>', 'danger');
    });
}

function resumeScannerPretes() {
    isProcessing = false;
    if (html5QrCodePretes) {
        try { html5QrCodePretes.resume(); } catch(e) {}
    }
    var fb = document.getElementById('pretes-scan-feedback');
    if (fb) fb.style.display = 'none';
}

function switchCameraPretes() {
    if (!availableCameras || availableCameras.length <= 1) return;
    currentCamIndex = (currentCamIndex + 1) % availableCameras.length;
    if (html5QrCodePretes) {
        html5QrCodePretes.stop().then(function() {
            startScannerPretes();
        });
    }
}

function restartScannerPretes() {
    isProcessing = false;
    if (html5QrCodePretes) {
        html5QrCodePretes.stop().then(function() {
            startScannerPretes();
        });
    } else {
        startScannerPretes();
    }
}

function showFeedback(html, type) {
    var fb = document.getElementById('pretes-scan-feedback');
    if (!fb) return;
    fb.style.display = 'block';
    fb.className = 'alert alert-' + type;
    fb.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', function() {
    startScannerPretes();
});

window.addEventListener('beforeunload', function() {
    if (html5QrCodePretes) {
        try { html5QrCodePretes.stop(); } catch(e) {}
    }
});
</script>
<?php endif; ?>
