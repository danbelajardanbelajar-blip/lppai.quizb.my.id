<?php
/**
 * LPPAI Corner - Portal Ujian Tulis Tutorial RPL Online & Sistem Pengawasan AI (Proctoring)
 * Berparitas penuh dengan pages/pretes-ujian.php:
 * - Skenario A: Verifikasi Wajah di Awal (Gatekeeper Check-in).
 * - Skenario B: Pengawasan Kamera Berkala Setiap 30 Detik Selama Ujian Berlangsung.
 * - Engine CBT 2 kolom, timer server, auto-save, jeda & acak ulang anti-kecurangan.
 */

if (isset($_GET['face_api'])) {
    require __DIR__ . '/../api/tutorial-rpl-proctoring-check.php';
    exit;
}
if (isset($_GET['cbt_api'])) {
    require __DIR__ . '/../api/tutorial-rpl-cbt.php';
    exit;
}
if (isset($_GET['cbt_asset'])) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    readfile(__DIR__ . '/../assets/js/pretes-cbt.js');
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

define('PAGE_TITLE', 'Ujian Tulis Tutorial RPL & Pengawas AI');
define('HIDE_MESSAGE_WIDGET', true);
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user = getCurrentUser();
$pdo = getDBConnection();

require_once __DIR__ . '/../includes/pretes-helper.php';
require_once __DIR__ . '/../includes/tutorial-rpl-cbt-engine.php';
ensurePretesProctoringTables($pdo);
ensureTutorialRplCbtProctoringTables($pdo);

$userId = (int)($user['id'] ?? 0);
$membership = tutorialRplGetStudentMembership($pdo, $userId);

$isTesterPrivileged = (isset($user['username']) && (string)$user['username'] === '12345')
    || (isset($user['nim']) && (string)$user['nim'] === '12345')
    || ($userId === 12345);

$regId = $membership ? (int)$membership['id'] : 0;
$classId = $membership ? (int)$membership['class_id'] : 0;

if ($isTesterPrivileged && isset($_GET['action'])) {
    if ($_GET['action'] === 'reset_session' || $_GET['action'] === 'reset_cbt') {
        unset($_SESSION['tutorial_rpl_cbt_verified_' . $regId]);
        unset($_SESSION['tutorial_rpl_cbt_verified_v2_' . $regId]);
        unset($_SESSION['tutorial_rpl_cbt_verified_999999']);
        unset($_SESSION['tutorial_rpl_cbt_verified_v2_999999']);
        if ($_GET['action'] === 'reset_cbt') {
            try {
                $stmtAtt = $pdo->prepare('SELECT id FROM tutorial_rpl_cbt_attempts WHERE user_id = ?');
                $stmtAtt->execute([$userId]);
                $attIds = $stmtAtt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($attIds)) {
                    $placeholders = implode(',', array_fill(0, count($attIds), '?'));
                    $pdo->prepare("DELETE FROM tutorial_rpl_cbt_interruption_events WHERE attempt_id IN ($placeholders)")->execute($attIds);
                    $pdo->prepare("DELETE FROM tutorial_rpl_cbt_interruptions WHERE attempt_id IN ($placeholders)")->execute($attIds);
                    $pdo->prepare("DELETE FROM tutorial_rpl_cbt_items WHERE attempt_id IN ($placeholders)")->execute($attIds);
                    $pdo->prepare("DELETE FROM tutorial_rpl_cbt_attempts WHERE id IN ($placeholders)")->execute($attIds);
                }
                if ($classId > 0) {
                    $pdo->prepare("UPDATE tutorial_rpl_members SET exam_submitted = 0, nilai_ujian_tulis = NULL, nilai_akhir = NULL, status_lulus = 'proses' WHERE user_id = ? AND class_id = ?")
                        ->execute([$userId, $classId]);
                }
            } catch (Throwable $e) {}
        }
        header('Location: ' . BASE_URL . '/tutorial-rpl-cbt.php');
        exit;
    } elseif ($_GET['action'] === 'bypass_checkin' && getenv('PRETES_ALLOW_TEST_BYPASS') === '1') {
        $bypassId = $regId ?: 999999;
        $_SESSION['tutorial_rpl_cbt_verified_' . $bypassId] = time();
        $_SESSION['tutorial_rpl_cbt_verified_v2_' . $bypassId] = time();
        header('Location: ' . BASE_URL . '/tutorial-rpl-cbt.php');
        exit;
    }
}

if (!$membership && $isTesterPrivileged) {
    $membership = [
        'id' => 999999,
        'user_id' => $userId,
        'class_id' => 999999,
        'nama_kelompok' => 'Kelompok Uji Coba RPL (Akun 12345)',
    ];
    $regId = 999999;
    $classId = 999999;
}

require_once __DIR__ . '/../includes/user-exam-photo.php';
$refPhoto = getUserExamReferencePhoto($pdo, $userId);

$isKristen = function_exists('isPretesUserKristen') ? isPretesUserKristen($pdo, $userId) : false;
$selectedSessionId = isset($_GET['session_id']) && (int)$_GET['session_id'] > 0
    ? (int)$_GET['session_id']
    : ($isKristen ? 2 : 1);

$existingAttempt = ($classId > 0) ? tutorialRplCbtGetStudentAttempt($pdo, $userId, $classId) : null;
$effectiveSessionId = $existingAttempt && !empty($existingAttempt['session_id'])
    ? (int)$existingAttempt['session_id']
    : $selectedSessionId;
$activeSession = tutorialRplCbtGetActiveSession($pdo, $effectiveSessionId);
$isCameraRequired = ($activeSession['camera_mode'] ?? 'tidak_aktif') === 'wajib';

$isVerified = ($regId > 0 && !empty($_SESSION['tutorial_rpl_cbt_verified_v2_' . $regId])
    && time() - (int)$_SESSION['tutorial_rpl_cbt_verified_v2_' . $regId] <= 14400);

if (!$isCameraRequired) {
    $isVerified = true;
}

$examStatus = tutorialRplCbtExamStatus($pdo);
$isSessionOpen = !empty($examStatus['open']) || $isTesterPrivileged;
$sessionNotice = $isSessionOpen ? '' : ($examStatus['notice'] ?? 'Ujian tulis Tutorial RPL belum dibuka.');
if (!$isSessionOpen && $existingAttempt) {
    $isSessionOpen = true;
}

$cbtViewOptions = [
    'style_url' => BASE_URL . '/tutorial-rpl-cbt.php?cbt_style=exam&amp;v=' . filemtime(__DIR__ . '/../assets/css/pretes-cbt-exam.css'),
    'aria_label' => 'CBT Ujian Tulis Tutorial RPL',
    'eyebrow' => 'LPPAI / Ruang Asesmen Tutorial RPL',
    'title' => 'CBT Tutorial RPL Online',
];

include __DIR__ . '/../includes/header.php';
?>

<style>
.face-check-loading{display:inline-flex;align-items:center;gap:8px;max-width:100%}
.face-check-spinner{display:inline-block;flex:0 0 auto;width:1em;height:1em;box-sizing:border-box;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:face-check-spin .75s linear infinite}
@keyframes face-check-spin{to{transform:rotate(360deg)}}
@media(prefers-reduced-motion:reduce){.face-check-spinner{animation:none}}
/* ── Styling Khusus Portal Proctoring Ujian CBT ── */
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
    <div style="background:#fffbeb;border:2px dashed #f59e0b;padding:14px 18px;border-radius:14px;margin-bottom:20px;box-shadow:0 2px 10px rgba(245,158,11,0.08);">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                <span style="font-size:26px;flex-shrink:0;">🧪</span>
                <div>
                    <strong style="color:#92400e;font-size:14px;display:block;">Panel Percobaan CBT Tutorial RPL (Hak Akses Akun: <?= sanitize($user['username'] ?? '12345') ?>)</strong>
                    <div style="color:#b45309;font-size:12px;margin-top:2px;">
                        Privilege aktif: Anda dapat menguji Skenario A (Check-in Awal) dan Skenario B (Pengawasan Kamera) secara langsung.
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="?action=reset_session" class="btn btn-sm btn-secondary" style="font-size:11.5px;padding:6px 12px;border-radius:8px;font-weight:700;">
                    🔄 Reset Sesi Kamera (Skenario A)
                </a>
                <a href="?action=reset_cbt" class="btn btn-sm" style="font-size:11.5px;padding:6px 12px;border-radius:8px;font-weight:700;background:#dc2626;color:#ffffff;border:none;" onclick="return confirm('Hapus lembar ujian CBT akun tester ini agar dapat menguji ulang dari layar intro dan pemilihan sesi?')">
                    🗑️ Reset Ujian CBT (Hapus Attempt & Pilih Sesi Baru)
                </a>
                <?php if (getenv('PRETES_ALLOW_TEST_BYPASS') === '1'): ?>
                <a href="?action=bypass_checkin" class="btn btn-sm" style="font-size:11.5px;padding:6px 12px;border-radius:8px;font-weight:700;background:#0f766e;color:#fff;">
                    ⚡ Buka Skenario B (Ruang Ujian)
                </a>
                <?php endif; ?>
                <button type="button" onclick="testFastPeriodicTimer()" class="btn btn-sm btn-warning" style="font-size:11.5px;padding:6px 12px;border-radius:8px;font-weight:700;">
                    ⏱️ Uji Timer Pengawasan
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

<?php if (!$membership): ?>
    <div class="card" style="border:2px solid #0f766e; border-radius:16px; padding:32px 20px; text-align:center; margin-top:20px;">
        <span style="font-size:48px; display:block; margin-bottom:12px;">⚠️</span>
        <h3 style="margin:0 0 8px; font-weight:800; color:#0f172a;">Anda Belum Terdaftar pada Kelompok Tutorial RPL yang Aktif</h3>
        <p style="color:#64748b; font-size:14px; max-width:520px; margin:0 auto 20px; line-height:1.5;">
            Ujian Tulis Tutorial RPL (CBT) hanya dapat diakses oleh mahasiswa yang sudah terdaftar dan diplot pada kelompok Tutorial RPL Online.
        </p>
        <a href="<?= BASE_URL ?>/tutorial-rpl.php" class="btn btn-primary" style="padding:10px 24px; font-weight:700; border-radius:10px;">
            👉 Kembali ke Info & Kelas RPL
        </a>
    </div>

<?php elseif (!$isSessionOpen): ?>
    <div class="card" style="border:2px solid #0284c7; border-radius:16px; padding:32px 20px; text-align:center; margin-top:20px; background:#f0f9ff;">
        <span style="font-size:48px; display:block; margin-bottom:12px;">🕒</span>
        <h3 style="margin:0 0 8px; font-weight:800; color:#0369a1;"><?= sanitize($activeSession['title'] ?? 'Sesi Ujian Tulis Tutorial RPL') ?></h3>
        <p style="color:#334155; font-size:14px; max-width:550px; margin:0 auto 16px; line-height:1.6;">
            <?= sanitize($sessionNotice) ?>
        </p>
        <div style="font-size:12.5px; color:#64748b; margin-top:8px;">
            Hubungi pengawas atau dosen tutor jika jadwal belum sesuai dengan sesi Anda.
        </div>
    </div>

<?php elseif ($isCameraRequired && empty($refPhoto)): ?>
    <div class="card" style="border:2px solid #eab308; border-radius:16px; padding:32px 20px; text-align:center; margin-top:20px;">
        <span style="font-size:48px; display:block; margin-bottom:12px;">📸</span>
        <h3 style="margin:0 0 8px; font-weight:800; color:#854d0e;">Foto Profil Acuan Belum Tersedia</h3>
        <p style="color:#64748b; font-size:14px; max-width:500px; margin:0 auto 20px; line-height:1.5;">
            Sistem pengawasan AI memerlukan pasfoto resmi acuan Anda untuk memverifikasi keaslian peserta saat ujian berlangsung.
        </p>
        <a href="<?= BASE_URL ?>/profil.php" class="btn btn-primary" style="padding:10px 24px; font-weight:700; border-radius:10px;">
            Perbarui Foto Profil ➔
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
                        <span>🛡️</span> <span>Verifikasi Identitas Peserta Ujian Tutorial RPL</span>
                    </h2>
                    <p style="margin:4px 0 0; color:#64748b; font-size:13.5px;">
                        Tahap 1 (Skenario A): Pengecekan wajah live mahasiswa terhadap foto profil acuan sebelum membuka soal.
                    </p>
                </div>
                <div class="proctoring-badge badge-waiting" id="badgeCheckinStatus">
                    ⏳ Menunggu Verifikasi Awal
                </div>
            </div>
        </div>

        <div class="card" style="border:1.5px solid #cbd5e1; border-radius:16px; overflow:hidden; box-shadow:0 4px 18px rgba(0,0,0,0.04);">
            <div style="background:#f8fafc; border-bottom:1.5px solid #e2e8f0; padding:14px 18px; font-size:13px; color:#334155;">
                <strong>Petunjuk:</strong> Pratinjau kamera tampil seperti cermin. <strong>Kanan dan kiri mengikuti tubuh Anda.</strong> Putar kepala mengikuti instruksi, tahan hingga hitung mundur selesai, lalu kembali ke posisi awal. Pastikan hanya Anda berada di kamera dengan pencahayaan terang.
            </div>

            <div class="card-body" style="padding:20px;">
                <div class="checkin-grid">
                    <div class="checkin-card">
                        <div style="font-size:12px; font-weight:800; color:#0f766e; text-transform:uppercase; margin-bottom:8px;">
                            Foto Profil Acuan Ujian
                        </div>
                        <div style="width:180px; height:240px; margin:0 auto 12px; border-radius:12px; overflow:hidden; border:2.5px solid #0f766e; box-shadow:0 4px 12px rgba(15,118,110,0.15);">
                            <?php if (!empty($refPhoto)): ?>
                            <img src="<?= BASE_URL ?>/<?= htmlspecialchars(ltrim(str_replace('\\', '/', $refPhoto), '/')) ?>"
                                 alt="Pasfoto Referensi"
                                 style="width:100%; height:100%; object-fit:cover; display:block;">
                            <?php endif; ?>
                        </div>
                        <div style="font-size:13px; font-weight:700; color:#0f172a;">
                            <?= sanitize($user['nama_lengkap'] ?? $user['name'] ?? 'Peserta Tutorial RPL') ?>
                        </div>
                        <div style="font-size:12px; color:#64748b;">NIM: <?= sanitize($user['nim'] ?? '-') ?></div>
                    </div>

                    <div class="checkin-card">
                        <div style="font-size:12px; font-weight:800; color:#0f766e; text-transform:uppercase; margin-bottom:8px;">
                            Kamera Langsung Peserta (Live Webcam)
                        </div>
                        <div style="font-size:11px;color:#64748b;margin-bottom:10px;">Mode cermin · arah kanan/kiri Anda</div>

                        <div class="camera-guide-box" id="checkinCameraBox" style="position:relative; width:240px; height:320px; margin:0 auto 14px; border-radius:12px; overflow:hidden; background:#0f172a; box-shadow:0 4px 14px rgba(0,0,0,0.15);">
                            <video id="checkinVideo" playsinline autoplay muted style="width:100%; height:100%; object-fit:cover; display:block;"></video>
                            <canvas id="checkinCanvas" style="display:none;"></canvas>
                            <div class="camera-oval-overlay" id="checkinOvalGuide" style="display:none;"></div>
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

                        <div id="checkinAlert" role="status" aria-live="polite" style="display:none; margin:10px auto; padding:12px 14px; border-radius:10px; font-size:12px; text-align:left; line-height:1.5;"></div>

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
         ═════════════════════════════════════════════════════════════════════ -->
    <div id="sectionExamRoom" style="<?= $isVerified ? 'display:block;' : 'display:none;' ?>">

        <div class="proctoring-monitor-bar">
            <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                <span style="font-size:28px;">🟢</span>
                <div>
                    <div style="font-size:14px; font-weight:800; display:flex; align-items:center; gap:8px;">
                        <span id="proctorStatusTitle">Kamera Siap untuk Ujian</span>
                        <span id="proctorFrequency" style="background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:12px; font-size:11px;">Pengawasan Kamera</span>
                    </div>
                    <div style="font-size:12px; color:#ccfbf1; margin-top:2px;" id="lastCheckInfo">
                        Sesi diverifikasi. Pemeriksaan berkala dimulai saat ujian dikerjakan.
                    </div>
                </div>
            </div>

            <div style="display:flex; align-items:center; gap:16px;">
                <div style="width:48px; height:64px; border-radius:6px; overflow:hidden; border:1.5px solid rgba(255,255,255,0.5); background:#000;">
                    <video id="proctorVideo" playsinline autoplay muted style="width:100%; height:100%; object-fit:cover;"></video>
                </div>
            </div>
        </div>

        <div id="proctorViolationAlert" style="display:none; background:#fef2f2; border:2px solid #ef4444; color:#991b1b; padding:16px 20px; border-radius:14px; margin-bottom:20px; box-shadow:0 4px 14px rgba(239,68,68,0.15);">
            <div style="font-size:15px; font-weight:800; display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <span>⚠️</span> <span>PERINGATAN PENGAWAS AI TUTORIAL RPL:</span>
            </div>
            <div id="proctorViolationMsg" style="font-size:13.5px; line-height:1.5;"></div>
            <div style="margin-top:8px; font-size:12px; color:#b91c1c; font-weight:600;">
                Perhatian: Pelanggaran dicatat pada sistem log audit proctoring pengawas ujian. Harap tetap berada di depan kamera.
            </div>
        </div>

        <?php require __DIR__ . '/../includes/pretes-cbt-view.php'; ?>

        <div class="card" style="border:1px solid #e2e8f0; border-radius:14px; padding:18px; background:#f8fafc;">
            <div style="font-size:13.5px; font-weight:800; color:#0f172a; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                <span>📋</span> <span>Tata Tertib Ujian Selama Proctoring Berlangsung:</span>
            </div>
            <ul style="margin:0; padding-left:20px; font-size:12.5px; color:#475569; display:flex; flex-direction:column; gap:4px; line-height:1.55;">
                <li>1. Wajib dikerjakan secara mandiri dan jujur tanpa bantuan orang lain (tidak memakai joki).</li>
                <li>2. Pastikan wajah selalu menghadap ke arah layar dan tidak meninggalkan kamera selama ujian berlangsung.</li>
                <li>3. Dilarang membuka website lain atau menggunakan HP tambahan saat mengerjakan soal.</li>
                <li>4. Sistem AI memeriksa kesesuaian wajah selama pengerjaan berlangsung.</li>
            </ul>
        </div>
    </div>

<?php endif; ?>

</div>

<script src="<?= BASE_URL ?>/tutorial-rpl-cbt.php?cbt_asset=1&amp;v=<?= filemtime(__DIR__ . '/../assets/js/pretes-cbt.js') ?>"></script>
<script>
const CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
const REGISTRATION_ID = <?= (int)$regId ?>;
const API_URL = <?= json_encode(BASE_URL . '/tutorial-rpl-cbt.php?face_api=1') ?>;
const CBT_API_URL = <?= json_encode(BASE_URL . '/tutorial-rpl-cbt.php?cbt_api=1') ?>;
const IS_CAMERA_REQUIRED = <?= json_encode($isCameraRequired ?? false) ?>;
window.TOKEN_REQUIRED = <?= json_encode(!empty($activeSession['token_required']) && !empty($activeSession['token'])) ?>;

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

function pretesRequestError(message, code, status) {
    const error = new Error(message);
    error.code = code;
    if (status) error.status = status;
    return error;
}

function requestProctoringCheck(payload, endpoint) {
    const controller = new AbortController();
    let timeoutId;
    const timeout = new Promise(function(resolve, reject) {
        timeoutId = setTimeout(function() {
            reject(pretesRequestError('Server belum merespons dalam 20 detik. Silakan coba lagi. Jika berulang, hubungi admin.', 'TIMEOUT'));
            controller.abort();
        }, 20000);
    });
    const request = Promise.resolve().then(function() {
        return fetch(endpoint || API_URL, {
            method: 'POST',
            keepalive: payload.action === 'interrupt',
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
            throw pretesRequestError('Respons server tidak valid (HTTP ' + response.status + '). Muat ulang halaman atau hubungi admin.', 'INVALID_RESPONSE', response.status);
        }
        if (!response.ok) {
            throw pretesRequestError(data.message || 'Server gagal memproses verifikasi (HTTP ' + response.status + ').', 'HTTP_ERROR', response.status);
        }
        if (!data || typeof data !== 'object') throw new Error('Data verifikasi server tidak valid.');
        return data;
    });
    return Promise.race([request, timeout]).finally(function() { clearTimeout(timeoutId); });
}

function waitForFacePose(milliseconds) {
    return new Promise(function(resolve) { setTimeout(resolve, milliseconds); });
}

async function guideFacePose(symbol, title, instruction, seconds, sample) {
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
        if (sample && remaining <= 3) sample();
    }
    if (countdown) countdown.textContent = 'Foto diambil';
}

function captureFaceFrame(video) {
    if (!video.videoWidth || !video.videoHeight) throw pretesRequestError('Kamera belum siap. Silakan nyalakan ulang kamera.', 'CAMERA_UNAVAILABLE');
    const canvas = document.createElement('canvas');
    const scale = Math.min(1, 640 / Math.max(video.videoWidth, video.videoHeight));
    canvas.width = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL('image/jpeg', 0.85);
}

function mirrorCameraPreview(video) {
    if (video) video.style.transform = 'scaleX(-1)';
}

function facePosePrompt(rawDirection) {
    return rawDirection === 'left'
        ? { symbol: '→', label: 'KANAN Anda', side: 'kanan' }
        : { symbol: '←', label: 'KIRI Anda', side: 'kiri' };
}

window.startCheckinCamera = function(isUserAction) {
    const video = document.getElementById('checkinVideo');
    const placeholder = document.getElementById('checkinCameraPlaceholder');
    const ovalGuide = document.getElementById('checkinOvalGuide');
    const cameraBadge = document.getElementById('checkinCameraBadge');
    const alertBox = document.getElementById('checkinAlert');
    const btnText = document.getElementById('btnActivateCameraText');

    if (btnText) btnText.textContent = '⏳ Membuka Kamera...';
    if (alertBox) {
        alertBox.style.display = 'block';
        alertBox.style.background = '#eff6ff';
        alertBox.style.border = '1.5px solid #bfdbfe';
        alertBox.style.color = '#1e40af';
        alertBox.innerHTML = '<strong>⏳ Sedang Menghubungkan Kamera...</strong><div style="font-size:11.5px;margin-top:3px;color:#2563eb;">Jika peramban Anda memunculkan pop-up izin, harap klik <strong>"Allow / Izinkan"</strong>.</div>';
    }

    if (!video) return;

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        const notSupportedMsg = "Peramban ini tidak mendukung akses kamera WebRTC atau halaman tidak diakses via HTTPS aman.";
        if (alertBox) {
            alertBox.style.background = '#fef2f2';
            alertBox.style.border = '1.5px solid #fca5a5';
            alertBox.style.color = '#991b1b';
            alertBox.innerHTML = '<strong>❌ Tidak Mendukung Kamera:</strong> ' + notSupportedMsg;
        }
        if (btnText) btnText.textContent = 'Aktifkan Kamera';
        return;
    }

    if (activeStream) {
        try { activeStream.getTracks().forEach(function(t) { t.stop(); }); } catch(e) {}
        activeStream = null;
    }

    function onStreamSuccess(stream) {
        activeStream = stream;
        video.srcObject = stream;
        mirrorCameraPreview(video);
        video.setAttribute('playsinline', 'true');
        video.setAttribute('autoplay', 'true');
        video.muted = true;

        var playPromise = video.play();
        if (playPromise !== undefined) {
            playPromise.catch(function() {});
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
        if (placeholder) placeholder.style.display = 'flex';
        if (ovalGuide) ovalGuide.style.display = 'none';
        if (cameraBadge) cameraBadge.style.display = 'none';
        if (btnText) btnText.textContent = 'Coba Aktifkan Lagi';

        let errTitle = "Akses Kamera Ditolak / Gagal";
        let errMsg = err.message || "Akses kamera tidak diizinkan oleh peramban.";
        let errTip = "Pastikan Anda memilih <strong>'Izinkan / Allow'</strong> saat peramban meminta izin kamera.";

        if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
            errTitle = "Izin Kamera Terblokir di Browser";
            errMsg = "Peramban Anda saat ini memblokir izin akses kamera.";
            errTip = "Klik ikon <strong>Gembok (🔒)</strong> di bilah alamat URL peramban Anda, ubah Kamera menjadi <strong>Izinkan (Allow)</strong>, lalu klik tombol ini lagi.";
        } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
            errTitle = "Perangkat Kamera Tidak Ditemukan";
            errMsg = "Tidak ada webcam yang terdeteksi di perangkat Anda.";
            errTip = "Pastikan webcam tercolok dengan baik atau kamera perangkat aktif.";
        } else if (err.name === 'NotReadableError' || err.name === 'TrackStartError') {
            errTitle = "Kamera Sedang Digunakan Aplikasi Lain";
            errMsg = "Kamera terkunci oleh aplikasi lain.";
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

    const c1 = { audio: false, video: { facingMode: currentFacingMode, width: { ideal: 640 }, height: { ideal: 480 } } };
    const c2 = { audio: false, video: true };

    navigator.mediaDevices.getUserMedia(c1)
        .then(onStreamSuccess)
        .catch(function() {
            navigator.mediaDevices.getUserMedia(c2)
                .then(onStreamSuccess)
                .catch(onStreamError);
        });
};

window.toggleCameraFace = function() {
    currentFacingMode = (currentFacingMode === 'user') ? 'environment' : 'user';
    window.startCheckinCamera(true);
};

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
            report += "  " + (idx + 1) + ". Label: " + (d.label || '(Nama belum diizinkan oleh browser)') + "\n";
        });
        report += "• Protokol URL: " + window.location.protocol + "\n";
        report += "• Status Stream Aktif: " + (activeStream ? "Aktif" : "Belum Aktif") + "\n";
        alert(report);
        window.startCheckinCamera(true);
    }).catch(function(err) {
        alert("Diagnosa Gagal: " + err.message);
    });
};

function inspectCameraPermissions() {
    if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'camera' }).then(function(p) {
            if (p.state === 'denied') {
                const alertBox = document.getElementById('checkinAlert');
                if (alertBox) {
                    alertBox.style.display = 'block';
                    alertBox.style.background = '#fef2f2';
                    alertBox.style.border = '1.5px solid #fca5a5';
                    alertBox.style.color = '#991b1b';
                    alertBox.innerHTML = '<strong style="display:block;margin-bottom:3px;font-size:13px;">⚠️ Izin Kamera Terblokir di Pengaturan Browser</strong>' +
                        '<div style="font-size:12px;color:#b91c1c;">Peramban Anda sebelumnya menandai izin kamera sebagai "Ditolak / Blokir".</div>';
                }
            } else if (p.state === 'granted') {
                window.startCheckinCamera(false);
            }
        }).catch(function() {});
    }
}

function initCameraBindings() {
    const isAlreadyVerified = <?= $isVerified ? 'true' : 'false' ?>;
    if (isAlreadyVerified) {
        initProctoringExamRoom();
        return;
    }

    inspectCameraPermissions();

    const btnActivate = document.getElementById('btnActivateCamera');
    const placeholder = document.getElementById('checkinCameraPlaceholder');
    if (placeholder) {
        placeholder.style.cursor = 'pointer';
        placeholder.addEventListener('click', function(e) {
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

function runInitialCheckin() {
    const video = document.getElementById('checkinVideo');
    const alertBox = document.getElementById('checkinAlert');
    const btn = document.getElementById('btnRunCheckin');
    let motionGuide = null;
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
        alertBox.innerHTML = '<strong>⏳ Sedang Mencocokkan...</strong><br>Sistem AI sedang membandingkan wajah di kamera dengan foto profil acuan Anda.';
    }

    Promise.resolve().then(async function() {
        const challenge = await requestProctoringCheck({ action: 'challenge' });
        if (!challenge.challenge_id || !['left', 'right'].includes(challenge.direction)) {
            throw new Error('Tantangan wajah tidak valid. Silakan ulangi.');
        }
        const frames = [];
        const sample = function() { frames.push(captureFaceFrame(video)); };
        await guideFacePose('◎', '1/3 · Wajah lurus', 'Hadapkan wajah lurus ke kamera. Tahan posisi; kamera mengambil beberapa sampel selama hitung mundur.', 3, sample);
        motionGuide = facePosePrompt(challenge.direction);
        await guideFacePose(motionGuide.symbol, '2/3 · ' + motionGuide.label,
            'Tolehkan kepala ke ' + motionGuide.side + ' Anda, seperti saat bercermin. Ikuti panah kuning dan tahan hingga hitung mundur selesai. Jangan hanya menggeser badan.', 4, sample);
        await guideFacePose('◎', '3/3 · Kembali lurus', 'Kembalikan kepala ke posisi seperti langkah pertama. Tahan hingga hitung mundur selesai.', 3, sample);
        const guide = document.getElementById('facePoseGuide');
        if (guide) guide.style.display = 'none';
        if (alertBox) alertBox.innerHTML = '<span class="face-check-loading"><span class="face-check-spinner" aria-hidden="true"></span><span>Sedang memeriksa ciri wajah dan gerakan di server…</span></span>';
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

            setTimeout(function() {
                const secCheckin = document.getElementById('sectionCheckin');
                const secExam = document.getElementById('sectionExamRoom');
                if (secCheckin) secCheckin.style.display = 'none';
                if (secExam) secExam.style.display = 'block';
                initProctoringExamRoom();
            }, 1200);
        } else {
            if (alertBox) {
                alertBox.style.background = '#fef2f2';
                alertBox.style.border = '1px solid #fca5a5';
                alertBox.style.color = '#991b1b';
                let reason = data.message || 'Wajah belum sesuai dengan foto profil acuan.';
                if (data.status === 'MOTION_REQUIRED' && data.failed_step === 2 && motionGuide) {
                    reason = 'Langkah 2: gerakan menoleh ke ' + motionGuide.side + ' Anda belum cukup terbaca. Putar kepala seperti saat bercermin dan tahan hingga hitung mundur selesai.';
                }
                alertBox.textContent = 'Verifikasi belum lolos. ' + reason;
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

function initProctoringExamRoom() {
    const video = document.getElementById('proctorVideo');
    mirrorCameraPreview(video);
    if (!IS_CAMERA_REQUIRED) {
        const title = document.getElementById('proctorStatusTitle');
        if (title) title.textContent = 'Ujian Mandiri Berlangsung';
        const freq = document.getElementById('proctorFrequency');
        if (freq) freq.textContent = 'Tanpa Kamera';
        const info = document.getElementById('lastCheckInfo');
        if (info) info.textContent = 'Sesi ujian ini tidak mewajibkan pengawasan kamera web.';
    } else if (video) {
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
            verifyAttention: async function() {
                if (!IS_CAMERA_REQUIRED) return;
                const camera = document.getElementById('proctorVideo');
                if (!camera) throw pretesRequestError('Kamera ujian tidak tersedia. Muat ulang halaman.', 'CAMERA_UNAVAILABLE');
                if (!camera.videoWidth || !activeStream || activeStream.getVideoTracks?.().some(track => track.readyState === 'ended')) {
                    if (!navigator.mediaDevices?.getUserMedia) throw pretesRequestError('Aktifkan izin kamera untuk melanjutkan ujian.', 'CAMERA_UNAVAILABLE');
                    const stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: true });
                    if (activeStream) activeStream.getTracks().forEach(track => track.stop());
                    activeStream = stream;
                    camera.srcObject = stream;
                    await camera.play();
                }
                const result = await requestProctoringCheck({ action: 'periodic_check', live_photo: captureFaceFrame(camera) });
                if (!result.verified) throw pretesRequestError(result.message || 'Hadapkan wajah ke kamera untuk melanjutkan ujian.', result.status || 'VERIFICATION_ERROR');
            },
            onStarted: function() {
                if (IS_CAMERA_REQUIRED) {
                    activeStream?.getVideoTracks?.().forEach(track => track.addEventListener?.('ended', function() {
                        if (!cbtCompleted) recordProctoringViolation('Kamera terputus saat ujian berlangsung.');
                    }));
                    const title = document.getElementById('proctorStatusTitle');
                    if (title) title.textContent = 'Pengawas Kamera Aktif';
                    const info = document.getElementById('lastCheckInfo');
                    if (info) info.textContent = 'Pemeriksaan wajah aktif selama ujian berlangsung.';
                    startProctoringTimer();
                }
            },
            onSubmitted: function() {
                cbtCompleted = true;
                if (countdownTimer) clearInterval(countdownTimer);
                if (proctorInterval) clearInterval(proctorInterval);
                if (activeStream) activeStream.getTracks().forEach(function(track) { track.stop(); });
                activeStream = null;
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

function scheduleProctoringCheck(seconds) {
    nextProctorCheckAt = Date.now() + seconds * 1000;
    secondsRemaining = seconds;
}

function tickProctoringTimer() {
    if (cbtCompleted || periodicCheckPending || !nextProctorCheckAt) return;
    secondsRemaining = Math.max(0, Math.ceil((nextProctorCheckAt - Date.now()) / 1000));
    if (secondsRemaining <= 0) { runPeriodicProctoringCheck(); return; }
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

function runPeriodicProctoringCheck() {
    if (cbtCompleted || periodicCheckPending || window.PretesCbt?.isPaused?.()) return;
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
            if (lastInfo) {
                lastInfo.textContent = 'Pemeriksaan terakhir: ' + (data.checked_at || 'Baru saja') + ' · Ciri wajah sesuai (indeks ' + data.score + '/100).';
            }
            if (alertBox) alertBox.style.display = 'none';
        } else {
            if (lastInfo) {
                lastInfo.textContent = 'Pemeriksaan terakhir: ' + (data.checked_at || 'Baru saja') + ' · Wajah perlu diperiksa kembali.';
            }
            if (data && ['NO_FACE', 'MISMATCH', 'MULTIPLE_FACES'].includes(data.status)) {
                window.PretesCbt?.interrupt?.(data.status === 'MISMATCH' ? 'face_mismatch' : 'face_missing');
            }
            if (alertBox && alertMsg) {
                alertBox.style.display = 'block';
                alertMsg.textContent = (data.message || 'Wajah belum terdeteksi atau belum sesuai pasfoto.') + ' Hadapkan wajah ke kamera dengan pencahayaan yang cukup.';
            }
        }
    })
    .catch(err => {
        const lastInfo = document.getElementById('lastCheckInfo');
        if (!cbtCompleted && lastInfo) lastInfo.textContent = 'Pemeriksaan tertunda (bukan pelanggaran): ' + err.message;
    }).finally(function() {
        periodicCheckPending = false;
        if (!cbtCompleted) scheduleProctoringCheck(PROCTOR_INTERVAL_SECONDS);
    });
}

function recordProctoringViolation(msg) {
    window.PretesCbt?.interrupt?.('camera_unavailable');
    const alertBox = document.getElementById('proctorViolationAlert');
    const alertMsg = document.getElementById('proctorViolationMsg');
    if (alertBox && alertMsg) {
        alertBox.style.display = 'block';
        alertMsg.textContent = msg;
    }
}

function testFastPeriodicTimer() {
    scheduleProctoringCheck(15);
    alert("⏱️ Timer pengawasan disetel untuk pengujian cepat!");
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
