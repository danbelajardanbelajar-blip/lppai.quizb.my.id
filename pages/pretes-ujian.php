<?php
/**
 * LPPAI Corner - Portal Ujian Pretes & Sistem Pengawasan AI (Proctoring)
 * Mengintegrasikan:
 * - Skenario A: Verifikasi Wajah di Awal (Gatekeeper Check-in).
 * - Skenario B: Pengawasan Kamera Berkala Setiap 30 Detik Selama Ujian Berlangsung.
 */

// Route the API through the same public page that served the camera UI.
// Some hosting layouts expose pages at / while the physical API lives under /web/.
// Dispatch before any HTML; the API retains its own login, CSRF and ownership checks.
if (isset($_GET['face_api'])) {
    require __DIR__ . '/../api/pretes-proctoring-check.php';
    exit;
}
if (isset($_GET['cbt_api'])) {
    require __DIR__ . '/../api/pretes-cbt.php';
    exit;
}
if (isset($_GET['cbt_asset'])) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    readfile(__DIR__ . '/../assets/js/pretes-cbt.js');
    exit;
}
if (isset($_GET['cbt_bank'])) {
    // Admin controller enforces requireAdmin() before rendering any keys/results.
    require __DIR__ . '/../admin/pretes-bank-soal.php';
    exit;
}
if (isset($_GET['cbt_style'])) {
    $styles = ['bank' => 'pretes-cbt-admin.css', 'exam' => 'pretes-cbt-exam.css'];
    $key = is_string($_GET['cbt_style']) ? $_GET['cbt_style'] : '';
    if (!isset($styles[$key])) { http_response_code(404); exit; }
    header('Content-Type: text/css; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    readfile(__DIR__ . '/../assets/css/' . $styles[$key]);
    exit;
}
if (isset($_GET['cbt_admin_asset'])) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    readfile(__DIR__ . '/../assets/js/pretes-cbt-admin.js');
    exit;
}

define('PAGE_TITLE', 'Ujian Pretes & Pengawas AI');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user = getCurrentUser();
$pdo  = getDBConnection();

require_once __DIR__ . '/../includes/pretes-helper.php';
ensurePretesProctoringTables($pdo);

// Ambil pendaftaran pretes mahasiswa aktif
$stmtMyReg = $pdo->prepare("
    SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan as ruangan_tes
    FROM pretes_registrations pr
    LEFT JOIN pretes_schedules ps ON pr.periode = ps.periode AND ps.status = 'aktif'
    WHERE pr.user_id = ?
    ORDER BY pr.tanggal_daftar DESC, pr.id DESC
    LIMIT 1
");
$stmtMyReg->execute([$user['id']]);
$myReg = $stmtMyReg->fetch(PDO::FETCH_ASSOC);

// ── Privilege Khusus Akun Tester (Username 12345) ────────────────────────────
$isTesterPrivileged = (isset($user['username']) && (string)$user['username'] === '12345') 
                   || (isset($user['nim']) && (string)$user['nim'] === '12345')
                   || ((int)($user['id'] ?? 0) === 12345);

$regId = $myReg ? (int)$myReg['id'] : 0;

// Handle Aksi Pintas Pengembang / Tester 12345
if ($isTesterPrivileged && isset($_GET['action'])) {
    if ($_GET['action'] === 'reset_session') {
        unset($_SESSION['pretes_cbt_verified_' . $regId]);
        unset($_SESSION['pretes_cbt_verified_v2_' . $regId]);
        unset($_SESSION['pretes_cbt_verified_999999']);
        unset($_SESSION['pretes_cbt_verified_v2_999999']);
        header('Location: ' . BASE_URL . '/pretes-ujian.php');
        exit;
    } elseif ($_GET['action'] === 'bypass_checkin' && getenv('PRETES_ALLOW_TEST_BYPASS') === '1') {
        $bypassId = $regId ?: 999999;
        $_SESSION['pretes_cbt_verified_' . $bypassId] = time();
        $_SESSION['pretes_cbt_verified_v2_' . $bypassId] = time();
        header('Location: ' . BASE_URL . '/pretes-ujian.php');
        exit;
    }
}

// Mock Registrasi Khusus Tester 12345 Jika Belum Terdaftar
if (!$myReg && $isTesterPrivileged) {
    $myReg = [
        'id'           => 999999,
        'user_id'      => $user['id'],
        'hari_pilihan' => 'Senin',
        'jam'          => '13.00 WIB (Jam 1)',
        'periode'      => 'Pretes Tutorial Ubudiyah 2026 (Mode Uji Coba)',
        'foto'         => !empty($user['foto']) ? $user['foto'] : '',
        'status'       => 'terdaftar',
        'username_tes' => ($user['nim'] ?? '12345') . '.22205',
        'password_tes' => '123456',
        'ruangan'      => 'Lab Komputer (Uji Coba Akun 12345)'
    ];
    $regId = 999999;
}

$refPhoto = $myReg ? trim((string)($myReg['foto'] ?? '')) : '';

// Sesi dari mesin lama harus diverifikasi ulang menggunakan model baru.
$isVerified = ($regId > 0 && !empty($_SESSION['pretes_cbt_verified_v2_' . $regId])
    && time() - (int)$_SESSION['pretes_cbt_verified_v2_' . $regId] <= 14400);

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Styling Khusus Portal Proctoring Ujian Pretes ── */
.proctoring-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12.5px;
    font-weight: 700;
}
.proctoring-badge.badge-active {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #86efac;
}
.proctoring-badge.badge-waiting {
    background: #fef3c7;
    color: #b45309;
    border: 1px solid #fde68a;
}
.proctoring-badge.badge-danger {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fca5a5;
}

.checkin-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 18px;
}

.checkin-card {
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}

.camera-guide-box {
    position: relative;
    width: 240px;
    height: 320px;
    margin: 0 auto 14px;
    border-radius: 12px;
    overflow: hidden;
    background: #0f172a;
    box-shadow: 0 4px 14px rgba(0,0,0,0.15);
}

.camera-guide-box video, .camera-guide-box canvas {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.camera-oval-overlay {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 160px;
    height: 220px;
    border: 2px dashed rgba(34, 197, 94, 0.85);
    border-radius: 50%;
    pointer-events: none;
    box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.35);
}

.face-pose-guide {
    position: absolute;
    bottom: 8px;
    left: 8px;
    right: 8px;
    z-index: 12;
    display: none;
    grid-template-columns: 60px 1fr;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border: 2px solid #facc15;
    border-radius: 10px;
    background: rgba(15, 23, 42, 0.94);
    color: #fff;
    text-align: left;
    pointer-events: none;
}
.face-pose-guide-symbol {
    font-family: Arial, sans-serif;
    font-size: 60px;
    font-weight: 900;
    line-height: 1;
    text-align: center;
    color: #fde047;
}

/* Floating Proctoring Bar */
.proctoring-monitor-bar {
    background: linear-gradient(135deg, #0f766e 0%, #115e59 100%);
    color: #ffffff;
    border-radius: 14px;
    padding: 14px 18px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    box-shadow: 0 4px 16px rgba(15,118,110,0.2);
}

@media (max-width: 768px) {
    .checkin-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="container-fluid" style="max-width:1100px; margin:0 auto; padding:10px 14px 40px;">

<?php if ($isTesterPrivileged): ?>
    <!-- ── TOOLBAR PENGEMBANG / TESTER KHUSUS AKUN 12345 ── -->
    <div style="background:#fffbeb;border:2px dashed #f59e0b;padding:14px 18px;border-radius:14px;margin-bottom:20px;box-shadow:0 2px 10px rgba(245,158,11,0.08);">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                <span style="font-size:26px;flex-shrink:0;">🧪</span>
                <div>
                    <strong style="color:#92400e;font-size:14px;display:block;">Panel Percobaan Ujian Pretes (Hak Akses Akun: <?= sanitize($user['username'] ?? '12345') ?>)</strong>
                    <div style="color:#b45309;font-size:12px;margin-top:2px;">
                        Privilege aktif: Anda dapat menguji Skenario A (Check-in Awal) dan Skenario B (Pengawasan 30 Detik) secara langsung.
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="?action=reset_session" class="btn btn-sm btn-secondary" style="font-size:11.5px;padding:6px 12px;border-radius:8px;font-weight:700;">
                    🔄 Reset Sesi (Ulang Skenario A)
                </a>
                <?php if (getenv('PRETES_ALLOW_TEST_BYPASS') === '1'): ?>
                <a href="?action=bypass_checkin" class="btn btn-sm" style="font-size:11.5px;padding:6px 12px;border-radius:8px;font-weight:700;background:#0f766e;color:#fff;">
                    ⚡ Buka Skenario B (Ruang Ujian)
                </a>
                <?php endif; ?>
                <button type="button" onclick="testFastPeriodicTimer()" class="btn btn-sm btn-warning" style="font-size:11.5px;padding:6px 12px;border-radius:8px;font-weight:700;">
                    ⏱️ Set Timer 15 Detik
                </button>
                <button type="button" onclick="runPeriodicProctoringCheck()" class="btn btn-sm btn-success" style="font-size:11.5px;padding:6px 12px;border-radius:8px;font-weight:700;">
                    📸 Test Snapshot Sekarang
                </button>
                <button type="button" onclick="testWebcamDiagnostics()" class="btn btn-sm" style="font-size:11.5px;padding:6px 12px;border-radius:8px;font-weight:700;background:#0284c7;color:#fff;border:none;">
                    🔍 Diagnosa Kamera
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!$myReg): ?>
    <!-- ── State: Mahasiswa Belum Terdaftar Pretes ── -->
    <div class="card" style="border:2px solid #0f766e; border-radius:16px; padding:32px 20px; text-align:center; margin-top:20px;">
        <span style="font-size:48px; display:block; margin-bottom:12px;">⚠️</span>
        <h3 style="margin:0 0 8px; font-weight:800; color:#0f172a;">Anda Belum Terdaftar pada Sesi Pretes</h3>
        <p style="color:#64748b; font-size:14px; max-width:500px; margin:0 auto 20px; line-height:1.5;">
            Untuk dapat mengikuti ujian pretes dengan pengawasan AI, Anda wajib mendaftar dan mengunggah pasfoto resmi terlebih dahulu.
        </p>
        <a href="<?= BASE_URL ?>/pretes-daftar.php" class="btn btn-primary" style="padding:10px 24px; font-weight:700; border-radius:10px;">
            👉 Daftar Pretes Sekarang
        </a>
    </div>

<?php elseif (empty($refPhoto)): ?>
    <!-- ── State: Pasfoto Pendaftaran Belum Lengkap ── -->
    <div class="card" style="border:2px solid #eab308; border-radius:16px; padding:32px 20px; text-align:center; margin-top:20px;">
        <span style="font-size:48px; display:block; margin-bottom:12px;">📸</span>
        <h3 style="margin:0 0 8px; font-weight:800; color:#854d0e;">Pasfoto Resmi Belum Terdaftar</h3>
        <p style="color:#64748b; font-size:14px; max-width:500px; margin:0 auto 20px; line-height:1.5;">
            Sistem pengawasan AI memerlukan pasfoto resmi acuan Anda untuk memverifikasi keaslian peserta saat ujian berlangsung.
        </p>
        <a href="<?= BASE_URL ?>/pretes-daftar.php" class="btn btn-primary" style="padding:10px 24px; font-weight:700; border-radius:10px;">
            Perbarui Pasfoto Resmi ➔
        </a>
    </div>

<?php else: ?>

    <!-- ═════════════════════════════════════════════════════════════════════
         BAGIAN 1: SKENARIO A — CHECK-IN VERIFIKASI WAJAH DI AWAL UJIAN
         ═════════════════════════════════════════════════════════════════════ -->
    <div id="sectionCheckin" style="<?= $isVerified ? 'display:none;' : 'display:block;' ?>">
        
        <div style="margin-bottom:18px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <div>
                    <h2 style="margin:0; font-size:22px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                        <span>🛡️</span> <span>Verifikasi Identitas Peserta Ujian Pretes</span>
                    </h2>
                    <p style="margin:4px 0 0; color:#64748b; font-size:13.5px;">
                        Tahap 1 (Skenario A): Pengecekan wajah live mahasiswa terhadap pasfoto pendaftaran resmi sebelum membuka soal.
                    </p>
                </div>
                <div class="proctoring-badge badge-waiting" id="badgeCheckinStatus">
                    ⏳ Menunggu Verifikasi Awal
                </div>
            </div>
        </div>

        <div class="card" style="border:1.5px solid #cbd5e1; border-radius:16px; overflow:hidden; box-shadow:0 4px 18px rgba(0,0,0,0.04);">
            <div style="background:#f8fafc; border-bottom:1.5px solid #e2e8f0; padding:14px 18px; font-size:13px; color:#334155;">
                <strong>Petunjuk:</strong> Pastikan hanya wajah Anda berada di kamera dengan pencahayaan terang. Klik <strong>Verifikasi Wajah & Buka Ujian</strong>, ikuti panah untuk menggerakkan kepala, lalu kembali menghadap lurus. Tiga foto akan diperiksa oleh mesin pengenal wajah.
            </div>

            <div class="card-body" style="padding:20px;">
                <div class="checkin-grid">
                    <!-- Sisi Kiri: Pasfoto Referensi Pendaftaran -->
                    <div class="checkin-card">
                        <div style="font-size:12px; font-weight:800; color:#0f766e; text-transform:uppercase; margin-bottom:8px;">
                            Pasfoto Resmi Pendaftaran Anda
                        </div>
                        <div style="width:180px; height:240px; margin:0 auto 12px; border-radius:12px; overflow:hidden; border:2.5px solid #0f766e; box-shadow:0 4px 12px rgba(15,118,110,0.15);">
                            <img src="<?= BASE_URL ?>/<?= htmlspecialchars(ltrim(str_replace('\\', '/', $refPhoto), '/')) ?>" 
                                 alt="Pasfoto Referensi" 
                                 style="width:100%; height:100%; object-fit:cover; display:block;">
                        </div>
                        <div style="font-size:13px; font-weight:700; color:#0f172a;">
                            <?= sanitize($user['nama_lengkap'] ?? $user['name'] ?? 'Peserta Pretes') ?>
                        </div>
                        <div style="font-size:12px; color:#64748b;">NIM: <?= sanitize($user['nim'] ?? '-') ?></div>
                    </div>

                    <!-- Sisi Kanan: Kamera Live Mahasiswa -->
                    <div class="checkin-card">
                        <div style="font-size:12px; font-weight:800; color:#0f766e; text-transform:uppercase; margin-bottom:8px;">
                            Kamera Langsung Peserta (Live Webcam)
                        </div>
                        
                        <div class="camera-guide-box" id="checkinCameraBox" style="position:relative; width:240px; height:320px; margin:0 auto 14px; border-radius:12px; overflow:hidden; background:#0f172a; box-shadow:0 4px 14px rgba(0,0,0,0.15);">
                            <!-- Video Stream: Always in DOM render tree -->
                            <video id="checkinVideo" playsinline autoplay muted style="width:100%; height:100%; object-fit:cover; display:block;"></video>
                            <canvas id="checkinCanvas" style="display:none;"></canvas>
                            
                            <!-- Panduan Oval Wajah (Tampil saat video aktif) -->
                            <div class="camera-oval-overlay" id="checkinOvalGuide" style="display:none;"></div>
                            
                            <!-- Badge Status Kamera -->
                            <div id="checkinCameraBadge" style="display:none; position:absolute; top:8px; left:50%; transform:translateX(-50%); background:rgba(22,163,74,0.95); color:#fff; font-size:11px; font-weight:700; padding:3px 10px; border-radius:12px; z-index:10; white-space:nowrap; box-shadow:0 2px 6px rgba(0,0,0,0.3);">
                                🟢 Kamera Aktif (Siap Verifikasi)
                            </div>

                            <div id="facePoseGuide" class="face-pose-guide" role="status" aria-live="polite">
                                <span id="facePoseSymbol" class="face-pose-guide-symbol" aria-hidden="true"></span>
                                <div>
                                    <strong id="facePoseTitle" style="display:block; font-size:13px; line-height:1.3;"></strong>
                                    <span id="facePoseCountdown" aria-hidden="true" style="display:block; margin-top:4px; font-size:14px; font-weight:800; color:#fde047;"></span>
                                </div>
                            </div>

                            <!-- Placeholder Saat Kamera Belum Aktif -->
                            <div id="checkinCameraPlaceholder" style="position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:16px; text-align:center; background:#0f172a; color:#fff; z-index:5;">
                                <span style="font-size:38px; display:block; margin-bottom:8px;">📹</span>
                                <div style="font-size:13.5px; font-weight:800; color:#f8fafc; margin-bottom:4px;">Kamera Belum Aktif</div>
                                <p style="font-size:11px; color:#94a3b8; margin:0 0 14px; line-height:1.45;">
                                    Klik tombol di bawah untuk menyalakan kamera peramban Anda.
                                </p>
                                <button type="button" class="btn btn-primary" id="btnActivateCamera" onclick="startCheckinCamera(true)" 
                                    style="background:#0f766e; border:none; padding:9px 18px; border-radius:8px; font-size:12.5px; font-weight:800; display:inline-flex; align-items:center; gap:6px; cursor:pointer; box-shadow:0 4px 12px rgba(15,118,110,0.4);">
                                    <span>📹</span> <span id="btnActivateCameraText">Aktifkan Kamera</span>
                                </button>
                            </div>
                        </div>

                        <div id="checkinAlert" style="display:none; margin:10px auto; padding:12px 14px; border-radius:10px; font-size:12px; text-align:left; line-height:1.5;"></div>

                        <div style="display:flex; justify-content:center; gap:8px; flex-wrap:wrap; margin-top:10px;">
                            <button type="button" class="btn btn-secondary" onclick="startCheckinCamera(true)" style="font-size:11.5px; padding:7px 12px; border-radius:8px; font-weight:600;">
                                🔄 Nyalakan / Ulang
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="toggleCameraFace()" style="font-size:11.5px; padding:7px 12px; border-radius:8px; font-weight:600;">
                                📷 Balik Kamera
                            </button>
                            <button type="button" id="btnRunCheckin" class="btn btn-success" onclick="runInitialCheckin()" 
                                style="font-size:13.5px; font-weight:800; padding:9px 20px; border-radius:10px; box-shadow:0 4px 12px rgba(22,163,74,0.3); display:inline-flex; align-items:center; gap:8px;">
                                <span>🔍</span> <span>Verifikasi Wajah & Buka Ujian</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- ═════════════════════════════════════════════════════════════════════
         BAGIAN 2: SKENARIO B — RUANG PENGERJAAN UJIAN DENGAN PENGAWAS AI
         Pengecekan Wajah Berkala Setiap 30 Detik Selama Ujian Aktif
         ═════════════════════════════════════════════════════════════════════ -->
    <div id="sectionExamRoom" style="<?= $isVerified ? 'display:block;' : 'display:none;' ?>">
        
        <!-- Proctoring Floating Status Bar -->
        <div class="proctoring-monitor-bar">
            <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                <span style="font-size:28px;">🟢</span>
                <div>
                    <div style="font-size:14px; font-weight:800; display:flex; align-items:center; gap:8px;">
                        <span id="proctorStatusTitle">Kamera Siap untuk Ujian</span>
                        <span id="proctorFrequency" style="background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:12px; font-size:11px;">Pengecekan Tiap 30 Detik</span>
                    </div>
                    <div style="font-size:12px; color:#ccfbf1; margin-top:2px;" id="lastCheckInfo">
                        Sesi diverifikasi. Pemeriksaan berkala dimulai saat ujian dikerjakan.
                    </div>
                </div>
            </div>

            <!-- Countdown Timer 30 Detik -->
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="text-align:right;">
                    <div style="font-size:11px; color:#ccfbf1; text-transform:uppercase; font-weight:700;">Pengecekan Berikutnya:</div>
                    <div style="font-size:18px; font-weight:800; font-family:monospace;" id="proctorTimerDisplay">00:30</div>
                </div>
                <!-- Mini Video Stream Hidden/Background -->
                <div style="width:48px; height:64px; border-radius:6px; overflow:hidden; border:1.5px solid rgba(255,255,255,0.5); background:#000;">
                    <video id="proctorVideo" playsinline autoplay muted style="width:100%; height:100%; object-fit:cover;"></video>
                </div>
            </div>
        </div>

        <!-- Alert Peringatan Pelanggaran Pengawas AI -->
        <div id="proctorViolationAlert" style="display:none; background:#fef2f2; border:2px solid #ef4444; color:#991b1b; padding:16px 20px; border-radius:14px; margin-bottom:20px; box-shadow:0 4px 14px rgba(239,68,68,0.15);">
            <div style="font-size:15px; font-weight:800; display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <span>⚠️</span> <span>PERINGATAN PENGAWAS AI PRETES:</span>
            </div>
            <div id="proctorViolationMsg" style="font-size:13.5px; line-height:1.5;"></div>
            <div style="margin-top:8px; font-size:12px; color:#b91c1c; font-weight:600;">
                Perhatian: Pelanggaran dicatat pada sistem log audit proctoring pengawas ujian. Harap tetap berada di depan kamera.
            </div>
        </div>

        <?php require __DIR__ . '/../includes/pretes-cbt-view.php'; ?>

        <!-- 4 Tata Tertib Pengawasan -->
        <div class="card" style="border:1px solid #e2e8f0; border-radius:14px; padding:18px; background:#f8fafc;">
            <div style="font-size:13.5px; font-weight:800; color:#0f172a; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                <span>📋</span> <span>Tata Tertib Ujian Selama Proctoring Berlangsung:</span>
            </div>
            <ul style="margin:0; padding-left:20px; font-size:12.5px; color:#475569; display:flex; flex-direction:column; gap:4px; line-height:1.55;">
                <li>1. Wajib dikerjakan secara mandiri dan jujur tanpa bantuan orang lain (tidak memakai joki).</li>
                <li>2. Pastikan wajah selalu menghadap ke arah layar dan tidak meninggalkan kamera selama ujian berlangsung.</li>
                <li>3. Dilarang membuka website lain atau menggunakan HP tambahan saat mengerjakan soal.</li>
                <li>4. Sistem AI akan mengambil snapshot otomatis tiap 30 detik untuk memastikan kontinuitas peserta asli.</li>
            </ul>
        </div>

    </div>

<?php endif; ?>

</div>

<!-- ═══════════════════════════════════════════════════════════════════
     CLIENT SCRIPT SKENARIO A & B PROCTORING ENGINE
     ═══════════════════════════════════════════════════════════════════ -->
<script src="<?= BASE_URL ?>/pretes-ujian.php?cbt_asset=1&amp;v=<?= filemtime(__DIR__ . '/../assets/js/pretes-cbt.js') ?>"></script>
<script>
const CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
const REGISTRATION_ID = <?= (int)$regId ?>;
const API_URL = <?= json_encode(BASE_URL . '/pretes-ujian.php?face_api=1') ?>;
const CBT_API_URL = <?= json_encode(BASE_URL . '/pretes-ujian.php?cbt_api=1') ?>;

let proctorInterval = null;
let countdownTimer = null;
const PROCTOR_INTERVAL_SECONDS = 30;
let secondsRemaining = PROCTOR_INTERVAL_SECONDS;
let nextProctorCheckAt = 0;
let periodicCheckPending = false;
let proctorVisibilityBound = false;
let activeStream = null;
let currentFacingMode = 'user';
let cbtCompleted = false;

// Batasi seluruh request, termasuk pembacaan body respons, agar UI selalu pulih.
function requestProctoringCheck(payload, endpoint) {
    const controller = new AbortController();
    let timeoutId;
    const timeout = new Promise(function(resolve, reject) {
        timeoutId = setTimeout(function() {
            reject(new Error('Server belum merespons dalam 20 detik. Silakan coba lagi. Jika berulang, hubungi admin.'));
            controller.abort();
        }, 20000);
    });
    const request = Promise.resolve().then(function() {
        return fetch(endpoint || API_URL, {
            method: 'POST',
            credentials: 'same-origin',
            signal: controller.signal,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: CSRF_TOKEN, registration_id: REGISTRATION_ID }, payload))
        });
    }).then(async function(response) {
        let data;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error('Respons server tidak valid (HTTP ' + response.status + '). Muat ulang halaman atau hubungi admin.');
        }
        if (!response.ok) {
            throw new Error(data.message || 'Server gagal memproses verifikasi (HTTP ' + response.status + ').');
        }
        if (!data || typeof data !== 'object') throw new Error('Data verifikasi server tidak valid.');
        return data;
    });
    return Promise.race([request, timeout]).finally(function() { clearTimeout(timeoutId); });
}

function waitForFacePose(milliseconds) {
    return new Promise(function(resolve) { setTimeout(resolve, milliseconds); });
}

async function guideFacePose(symbol, title, instruction, seconds) {
    const guide = document.getElementById('facePoseGuide');
    const icon = document.getElementById('facePoseSymbol');
    const label = document.getElementById('facePoseTitle');
    const countdown = document.getElementById('facePoseCountdown');
    const alertBox = document.getElementById('checkinAlert');
    if (guide) guide.style.display = 'grid';
    if (icon) icon.textContent = symbol;
    if (label) label.textContent = title;
    if (alertBox) alertBox.textContent = instruction;
    for (let remaining = seconds; remaining > 0; remaining--) {
        if (countdown) countdown.textContent = 'Tahan ' + remaining + ' detik';
        await waitForFacePose(1000);
    }
    if (countdown) countdown.textContent = 'Foto diambil';
}

function captureFaceFrame(video) {
    if (!video.videoWidth || !video.videoHeight) throw new Error('Kamera belum siap. Silakan nyalakan ulang kamera.');
    const canvas = document.createElement('canvas');
    const scale = Math.min(1, 640 / Math.max(video.videoWidth, video.videoHeight));
    canvas.width = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL('image/jpeg', 0.85);
}

// ── Global Webcam Handler & Engine Skenario A & B ──
window.startCheckinCamera = function(isUserAction) {
    console.log("[Webcam] startCheckinCamera dipanggil, userAction:", isUserAction);

    const video = document.getElementById('checkinVideo');
    const placeholder = document.getElementById('checkinCameraPlaceholder');
    const ovalGuide = document.getElementById('checkinOvalGuide');
    const cameraBadge = document.getElementById('checkinCameraBadge');
    const alertBox = document.getElementById('checkinAlert');
    const btnText = document.getElementById('btnActivateCameraText');

    // Respon visual instan agar pengguna tahu sistem langsung bereaksi
    if (btnText) btnText.textContent = '⏳ Membuka Kamera...';
    if (alertBox) {
        alertBox.style.display = 'block';
        alertBox.style.background = '#eff6ff';
        alertBox.style.border = '1.5px solid #bfdbfe';
        alertBox.style.color = '#1e40af';
        alertBox.innerHTML = '<strong>⏳ Sedang Menghubungkan Kamera...</strong><div style="font-size:11.5px;margin-top:3px;color:#2563eb;">Jika peramban Anda memunculkan pop-up izin, harap klik <strong>"Allow / Izinkan"</strong>.</div>';
    }

    if (!video) {
        alert("Elemen video kamera tidak ditemukan pada halaman.");
        return;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        const notSupportedMsg = "Peramban ini tidak mendukung akses kamera WebRTC atau halaman tidak diakses via HTTPS aman.";
        if (alertBox) {
            alertBox.style.background = '#fef2f2';
            alertBox.style.border = '1.5px solid #fca5a5';
            alertBox.style.color = '#991b1b';
            alertBox.innerHTML = '<strong>❌ Tidak Mendukung Kamera:</strong> ' + notSupportedMsg;
        }
        alert(notSupportedMsg);
        if (btnText) btnText.textContent = 'Aktifkan Kamera';
        return;
    }

    // Bersihkan stream aktif sebelumnya bila ada
    if (activeStream) {
        try {
            activeStream.getTracks().forEach(function(t) { t.stop(); });
        } catch(e) {}
        activeStream = null;
    }

    function onStreamSuccess(stream) {
        console.log("[Webcam] Stream berhasil didapatkan:", stream);
        activeStream = stream;
        video.srcObject = stream;
        video.setAttribute('playsinline', 'true');
        video.setAttribute('autoplay', 'true');
        video.muted = true;

        var playPromise = video.play();
        if (playPromise !== undefined) {
            playPromise.catch(function(e) {
                console.warn("[Webcam] video.play() warning:", e);
            });
        }

        if (placeholder) placeholder.style.display = 'none';
        if (ovalGuide) ovalGuide.style.display = 'block';
        if (cameraBadge) cameraBadge.style.display = 'block';
        if (btnText) btnText.textContent = 'Aktifkan Kamera';

        if (alertBox) {
            alertBox.style.display = 'block';
            alertBox.style.background = '#f0fdf4';
            alertBox.style.border = '1.5px solid #86efac';
            alertBox.style.color = '#15803d';
            alertBox.innerHTML = '<strong>🟢 Kamera Aktif & Siap Verifikasi!</strong><div style="font-size:11.5px;margin-top:2px;">Posisikan wajah Anda tegak di dalam oval, lalu klik tombol <strong>"Verifikasi Wajah & Buka Ujian"</strong> di bawah.</div>';
        }
    }

    function onStreamError(err) {
        console.error("[Webcam] getUserMedia error:", err);
        if (placeholder) placeholder.style.display = 'flex';
        if (ovalGuide) ovalGuide.style.display = 'none';
        if (cameraBadge) cameraBadge.style.display = 'none';
        if (btnText) btnText.textContent = 'Coba Aktifkan Lagi';

        let errTitle = "Akses Kamera Ditolak / Gagal";
        let errMsg = err.message || "Akses kamera tidak diizinkan oleh peramban.";
        let errTip = "Pastikan Anda memilih <strong>'Izinkan / Allow'</strong> saat peramban meminta izin kamera.";

        if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
            errTitle = "Izin Kamera Terblokir di Browser";
            errMsg = "Peramban Anda saat ini memblokir izin akses kamera untuk https://lppai.quizb.my.id.";
            errTip = "Klik ikon <strong>Gembok (🔒)</strong> atau ikon kamera di bilah alamat URL peramban Anda (kiri atas), ubah Kamera menjadi <strong>Izinkan (Allow)</strong>, lalu klik tombol ini lagi.";
        } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
            errTitle = "Perangkat Kamera Tidak Ditemukan";
            errMsg = "Tidak ada webcam yang terdeteksi di laptop atau komputer Anda.";
            errTip = "Pastikan webcam tercolok dengan baik atau driver kamera internal aktif di Pengaturan Windows.";
        } else if (err.name === 'NotReadableError' || err.name === 'TrackStartError') {
            errTitle = "Kamera Sedang Digunakan Aplikasi Lain";
            errMsg = "Kamera terkunci oleh aplikasi lain (misal Zoom, Google Meet, Skype, atau tab lain).";
            errTip = "Tutup aplikasi yang sedang menggunakan kamera, lalu coba klik aktifkan kembali.";
        }

        if (alertBox) {
            alertBox.style.display = 'block';
            alertBox.style.background = '#fef2f2';
            alertBox.style.border = '1.5px solid #fca5a5';
            alertBox.style.color = '#991b1b';
            alertBox.innerHTML = '<strong style="display:block;margin-bottom:3px;font-size:13px;">❌ ' + errTitle + '</strong>' +
                '<div style="font-size:12px;color:#b91c1c;">' + errMsg + '</div>' +
                '<div style="margin-top:8px;font-size:11.5px;color:#7f1d1d;line-height:1.45;background:#fff;padding:8px 10px;border-radius:6px;border:1px dashed #fca5a5;">💡 ' + errTip + '</div>';
        }

        if (isUserAction) {
            alert(errTitle + ":\n" + errMsg + "\n\n" + errTip.replace(/<[^>]*>/g, ''));
        }
    }

    // Strategi 1: Constraints resolusi ideal
    const c1 = { audio: false, video: { facingMode: currentFacingMode, width: { ideal: 640 }, height: { ideal: 480 } } };
    // Strategi 2: Constraints universal
    const c2 = { audio: false, video: true };

    navigator.mediaDevices.getUserMedia(c1)
        .then(onStreamSuccess)
        .catch(function(e1) {
            console.warn("[Webcam] Constraint c1 gagal, mencoba fallback c2 universal...", e1);
            navigator.mediaDevices.getUserMedia(c2)
                .then(onStreamSuccess)
                .catch(onStreamError);
        });
};

window.toggleCameraFace = function() {
    currentFacingMode = (currentFacingMode === 'user') ? 'environment' : 'user';
    window.startCheckinCamera(true);
};

// ── Diagnosa Kamera Lengkap Khusus Pengembang / Tester ──
window.testWebcamDiagnostics = function() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
        alert("Diagnosa: navigator.mediaDevices tidak didukung di peramban ini.");
        return;
    }
    navigator.mediaDevices.enumerateDevices().then(function(devices) {
        var videoDevices = devices.filter(d => d.kind === 'videoinput');
        var report = "=== HASIL DIAGNOSA KAMERA WEBRTC ===\n";
        report += "• Jumlah Kamera Terdeteksi: " + videoDevices.length + "\n";
        videoDevices.forEach(function(d, idx) {
            report += "  " + (idx + 1) + ". Label: " + (d.label || '(Nama belum diizinkan oleh browser)') + " (ID: " + d.deviceId.substring(0, 10) + "...)\n";
        });
        report += "• Protokol URL: " + window.location.protocol + " (Wajib HTTPS)\n";
        report += "• Status Stream Aktif: " + (activeStream ? "Aktif" : "Belum Aktif") + "\n";

        if (navigator.permissions && navigator.permissions.query) {
            navigator.permissions.query({ name: 'camera' }).then(function(p) {
                report += "• Izin Browser (Permissions API): " + p.state.toUpperCase() + "\n\n";
                if (p.state === 'denied') {
                    report += "⚠️ PERHATIAN: Akses kamera saat ini berstatus DENIED (Diblokir). Klik ikon Gembok di bilah URL Chrome untuk mengizinkan!";
                } else {
                    report += "Klik 'OK' untuk langsung mencoba membuka kamera.";
                }
                alert(report);
                if (p.state !== 'denied') {
                    window.startCheckinCamera(true);
                }
            });
        } else {
            alert(report);
            window.startCheckinCamera(true);
        }
    }).catch(function(err) {
        alert("Diagnosa Gagal: " + err.message);
    });
};

// Deteksi awal izin kamera di peramban saat halaman dimuat
function inspectCameraPermissions() {
    if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'camera' }).then(function(p) {
            console.log("[Webcam] Initial permission state:", p.state);
            if (p.state === 'denied') {
                const alertBox = document.getElementById('checkinAlert');
                if (alertBox) {
                    alertBox.style.display = 'block';
                    alertBox.style.background = '#fef2f2';
                    alertBox.style.border = '1.5px solid #fca5a5';
                    alertBox.style.color = '#991b1b';
                    alertBox.innerHTML = '<strong style="display:block;margin-bottom:3px;font-size:13px;">⚠️ Izin Kamera Terblokir di Pengaturan Browser</strong>' +
                        '<div style="font-size:12px;color:#b91c1c;">Peramban Anda sebelumnya menandai izin kamera sebagai "Ditolak / Blokir".</div>' +
                        '<div style="margin-top:8px;font-size:11.5px;color:#7f1d1d;line-height:1.45;background:#fff;padding:8px 10px;border-radius:6px;border:1px dashed #fca5a5;">' +
                        '👉 <strong>Cara Mengaktifkan:</strong> Klik ikon <strong>Gembok (🔒)</strong> di sebelah kiri alamat URL peramban di atas, ubah izin <strong>Kamera</strong> menjadi <strong>Izinkan (Allow)</strong>, lalu klik tombol Aktifkan Kamera di bawah.</div>';
                }
            } else if (p.state === 'granted') {
                // Jika sudah diizinkan, buka kamera otomatis
                window.startCheckinCamera(false);
            }
        }).catch(function(e) {});
    }
}

// Inisialisasi binding event listener eksplisit
function initCameraBindings() {
    const isAlreadyVerified = <?= $isVerified ? 'true' : 'false' ?>;
    if (isAlreadyVerified) {
        initProctoringExamRoom();
        return;
    }

    inspectCameraPermissions();

    const btnActivate = document.getElementById('btnActivateCamera');
    // Tombol sudah memakai onclick; jangan meminta stream kamera dua kali per klik.

    const placeholder = document.getElementById('checkinCameraPlaceholder');
    if (placeholder) {
        placeholder.style.cursor = 'pointer';
        placeholder.addEventListener('click', function(e) {
            // Jika mengklik area box selain tombol, tetap aktifkan kamera
            if (!btnActivate || (e.target !== btnActivate && !btnActivate.contains(e.target))) {
                window.startCheckinCamera(true);
            }
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCameraBindings);
} else {
    initCameraBindings();
}

/**
 * ── Eksekusi Skenario A: Check-in Verifikasi Wajah Di Awal ──
 */
function runInitialCheckin() {
    const video = document.getElementById('checkinVideo');
    const alertBox = document.getElementById('checkinAlert');
    const btn = document.getElementById('btnRunCheckin');
    if (btn && btn.disabled) return;

    if (!video || !video.videoWidth || !activeStream) {
        alert("Kamera belum aktif. Klik tombol 'Aktifkan Kamera' terlebih dahulu dan pastikan wajah Anda tampak di layar.");
        window.startCheckinCamera(true);
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span>⏳</span> <span>Memeriksa Wajah...</span>';
    }

    if (alertBox) {
        alertBox.style.display = 'block';
        alertBox.style.background = '#eff6ff';
        alertBox.style.border = '1px solid #bfdbfe';
        alertBox.style.color = '#1e40af';
        alertBox.innerHTML = '<strong>⏳ Sedang Mencocokkan...</strong><br>Sistem AI sedang membandingkan wajah di kamera dengan pasfoto pendaftaran resmi Anda.';
    }

    // Sertakan kegagalan snapshot dalam jalur penanganan error yang sama.
    Promise.resolve().then(async function() {
        const challenge = await requestProctoringCheck({ action: 'challenge' });
        if (!challenge.challenge_id || !['left', 'right'].includes(challenge.direction)) {
            throw new Error('Tantangan wajah tidak valid. Silakan ulangi.');
        }
        await guideFacePose('◎', '1/3 · Wajah lurus', 'Hadapkan wajah lurus ke kamera. Foto diambil setelah hitung mundur selesai.', 3);
        const frames = [captureFaceFrame(video)];
        const left = challenge.direction === 'left';
        const direction = left ? 'KIRI layar' : 'KANAN layar';
        await guideFacePose(left ? '←' : '→', '2/3 · ' + direction,
            'Putar kepala sedikit: hidung pada video harus mengarah ke ' + direction + ', sesuai panah kuning besar di kamera. Tahan hingga hitung mundur selesai.', 4);
        frames.push(captureFaceFrame(video));
        await guideFacePose('◎', '3/3 · Kembali lurus', 'Kembali menghadap lurus ke kamera. Tahan hingga hitung mundur selesai.', 3);
        frames.push(captureFaceFrame(video));
        const guide = document.getElementById('facePoseGuide');
        if (guide) guide.style.display = 'none';
        if (alertBox) alertBox.textContent = 'Sedang memeriksa ciri wajah dan gerakan di server…';
        return requestProctoringCheck({
            action: 'initial_checkin',
            challenge_id: challenge.challenge_id,
            frames: frames,
            live_photo: frames[0]
        });
    })
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<span>🔍</span> <span>Verifikasi Wajah & Buka Ujian</span>';
        }

        if (data && data.verified) {
            // Skenario A Berhasil
            if (alertBox) {
                alertBox.style.background = '#f0fdf4';
                alertBox.style.border = '1px solid #86efac';
                alertBox.style.color = '#15803d';
                alertBox.textContent = 'Verifikasi berhasil. Indeks kemiripan: ' + data.score + '/100. ' + data.message;
            }

            const badge = document.getElementById('badgeCheckinStatus');
            if (badge) {
                badge.className = 'proctoring-badge badge-active';
                badge.textContent = '✓ Ciri Wajah Sesuai';
            }

            // Transisi ke Skenario B (Ruang Ujian) dalam 1.2 detik
            setTimeout(function() {
                const secCheckin = document.getElementById('sectionCheckin');
                const secExam = document.getElementById('sectionExamRoom');
                if (secCheckin) secCheckin.style.display = 'none';
                if (secExam) secExam.style.display = 'block';
                initProctoringExamRoom();
            }, 1200);

        } else {
            // Skenario A Gagal / Mismatch
            if (alertBox) {
                alertBox.style.background = '#fef2f2';
                alertBox.style.border = '1px solid #fca5a5';
                alertBox.style.color = '#991b1b';
                alertBox.textContent = 'Verifikasi belum lolos. ' + (data.message || 'Wajah belum sesuai dengan pasfoto pendaftaran.');
            }
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<span>🔍</span> <span>Verifikasi Wajah & Buka Ujian</span>';
        }
        if (alertBox) {
            alertBox.style.background = '#fef2f2';
            alertBox.style.border = '1px solid #fca5a5';
            alertBox.style.color = '#991b1b';
            alertBox.textContent = 'Verifikasi belum selesai: ' + err.message;
        }
    }).finally(function() {
        const guide = document.getElementById('facePoseGuide');
        if (guide) guide.style.display = 'none';
    });
}

/**
 * ── Inisialisasi Skenario B: Pengawasan Berkala Tiap 30 Detik ──
 */
function initProctoringExamRoom() {
    const video = document.getElementById('proctorVideo');
    if (video) {
        if (activeStream) {
            video.srcObject = activeStream;
            video.play();
        } else if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ audio: false, video: true })
                .then(function(s) {
                    if (cbtCompleted) { s.getTracks().forEach(function(track) { track.stop(); }); return; }
                    activeStream = s;
                    video.srcObject = s;
                    video.play();
                }).catch(e => console.warn("Proctor camera stream:", e));
        }
    }

    if (window.PretesCbt) {
        window.PretesCbt.init({
            request: function(payload) { return requestProctoringCheck(payload, CBT_API_URL); },
            onStarted: function() {
                const title = document.getElementById('proctorStatusTitle');
                if (title) title.textContent = 'Pengawas Kamera Aktif';
                const info = document.getElementById('lastCheckInfo');
                if (info) info.textContent = 'Pemeriksaan wajah setiap 30 detik selama ujian aktif.';
                startProctoringTimer();
            },
            onSubmitted: function() {
                cbtCompleted = true;
                if (countdownTimer) clearInterval(countdownTimer);
                if (proctorInterval) clearInterval(proctorInterval);
                if (activeStream) activeStream.getTracks().forEach(function(track) { track.stop(); });
                activeStream = null;
                const timer = document.getElementById('proctorTimerDisplay');
                if (timer) timer.textContent = 'Selesai';
                const title = document.getElementById('proctorStatusTitle');
                if (title) title.textContent = 'Ujian Selesai';
                const frequency = document.getElementById('proctorFrequency');
                if (frequency) frequency.textContent = 'Kamera dihentikan';
                const info = document.getElementById('lastCheckInfo');
                if (info) info.textContent = 'Hasil CBT tersimpan. Pengawasan kamera telah selesai.';
            }
        });
    } else {
        const message = document.getElementById('cbtMessage');
        if (message) message.textContent = 'Modul CBT belum termuat. Muat ulang halaman untuk mencoba lagi.';
    }
}

/**
 * ── Timer Hitung Mundur 30 Detik & Pemicu Snapshot Berkala ──
 */
function scheduleProctoringCheck(seconds) {
    nextProctorCheckAt = Date.now() + seconds * 1000;
    secondsRemaining = seconds;
    updateTimerDisplay();
}

function tickProctoringTimer() {
    if (cbtCompleted || periodicCheckPending || !nextProctorCheckAt) return;
    secondsRemaining = Math.max(0, Math.ceil((nextProctorCheckAt - Date.now()) / 1000));
    if (secondsRemaining <= 0) { runPeriodicProctoringCheck(); return; }
    updateTimerDisplay();
}

function startProctoringTimer() {
    if (cbtCompleted) return;
    if (countdownTimer) clearInterval(countdownTimer);
    if (proctorInterval) clearInterval(proctorInterval);
    scheduleProctoringCheck(PROCTOR_INTERVAL_SECONDS);
    countdownTimer = setInterval(tickProctoringTimer, 1000);
    if (!proctorVisibilityBound) {
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) tickProctoringTimer();
        });
        proctorVisibilityBound = true;
    }
}

function updateTimerDisplay() {
    const disp = document.getElementById('proctorTimerDisplay');
    if (!disp) return;
    const m = Math.floor(secondsRemaining / 60);
    const s = secondsRemaining % 60;
    disp.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
}

/**
 * ── Eksekusi Skenario B: Pengambilan Snapshot Berkala Tiap 30 Detik ──
 */
function runPeriodicProctoringCheck() {
    if (cbtCompleted || periodicCheckPending) return;
    const video = document.getElementById('proctorVideo') || document.getElementById('checkinVideo');
    if (!video || !video.videoWidth) {
        recordProctoringViolation("Kamera terputus atau belum aktif. Nyalakan kembali kamera agar pemeriksaan dapat berjalan.");
        scheduleProctoringCheck(PROCTOR_INTERVAL_SECONDS);
        return;
    }

    let livePhotoData;
    try {
        livePhotoData = captureFaceFrame(video);
    } catch (error) {
        recordProctoringViolation(error.message);
        scheduleProctoringCheck(PROCTOR_INTERVAL_SECONDS);
        return;
    }

    periodicCheckPending = true;
    const timer = document.getElementById('proctorTimerDisplay');
    if (timer) timer.textContent = 'Memeriksa…';
    return requestProctoringCheck({
        action: 'periodic_check',
        live_photo: livePhotoData
    })
    .then(data => {
        const lastInfo = document.getElementById('lastCheckInfo');
        if (cbtCompleted) return;
        const alertBox = document.getElementById('proctorViolationAlert');
        const alertMsg = document.getElementById('proctorViolationMsg');

        if (data && data.verified) {
            // Pemeriksaan berkala sesuai
            if (lastInfo) {
                lastInfo.textContent = 'Pemeriksaan terakhir: ' + (data.checked_at || 'Baru saja') + ' · Ciri wajah sesuai (indeks ' + data.score + '/100).';
            }
            if (alertBox) alertBox.style.display = 'none';
        } else {
            // Pemeriksaan berkala perlu perhatian (Mismatch atau Wajah Hilang)
            if (lastInfo) {
                lastInfo.textContent = 'Pemeriksaan terakhir: ' + (data.checked_at || 'Baru saja') + ' · Wajah perlu diperiksa kembali.';
            }
            if (alertBox && alertMsg) {
                alertBox.style.display = 'block';
                alertMsg.textContent = (data.message || 'Wajah belum terdeteksi atau belum sesuai pasfoto.') + ' Hadapkan wajah ke kamera dengan pencahayaan yang cukup.';
            }
        }
    })
    .catch(err => {
        console.error("Periodic proctoring error:", err);
        const lastInfo = document.getElementById('lastCheckInfo');
        if (!cbtCompleted && lastInfo) lastInfo.textContent = 'Pemeriksaan tertunda (bukan pelanggaran): ' + err.message;
    }).finally(function() {
        periodicCheckPending = false;
        if (!cbtCompleted) scheduleProctoringCheck(PROCTOR_INTERVAL_SECONDS);
    });
}

function recordProctoringViolation(msg) {
    const alertBox = document.getElementById('proctorViolationAlert');
    const alertMsg = document.getElementById('proctorViolationMsg');
    if (alertBox && alertMsg) {
        alertBox.style.display = 'block';
        alertMsg.textContent = msg;
    }
}

function testFastPeriodicTimer() {
    scheduleProctoringCheck(15);
    alert("⏱️ Timer pengawasan berkala disetel ke 15 detik untuk pengujian cepat!");
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
