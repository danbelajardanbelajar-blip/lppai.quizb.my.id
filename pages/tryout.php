<?php
/**
 * LPPAI Corner - Tryout CBT Pretes (Mahasiswa)
 *
 * - tryout.php                 : daftar tryout aktif + riwayat nilai
 * - tryout.php?sesi=ID         : ruang pengerjaan tryout (mesin CBT & sistem pengawas AI proctoring)
 * - tryout.php?api=1 (POST)    : API JSON tryout (state/start/save/submit/interrupt/resume/retake)
 * - tryout.php?face_api=1 (POST): API Proctoring Pengawas AI (challenge/initial_checkin/periodic_check)
 *
 * Attempt tryout terpisah dari ujian resmi (registration_id < 0), wajib terdaftar
 * sebagai peserta pada periode pretes yang sedang berlangsung, dan mengikuti aturan
 * kamera sesi (camera_mode = 'wajib' mewajibkan verifikasi wajah Skenario A & pengawasan berkala Skenario B).
 */

if (isset($_GET['face_api'])) {
    require __DIR__ . '/../api/pretes-proctoring-check.php';
    exit;
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pretes-tryout.php';
require_once __DIR__ . '/../includes/pretes-exam-access.php';
require_once __DIR__ . '/../includes/pretes-helper.php';

/** Tryout hanya untuk mahasiswa terdaftar periode pretes aktif (admin & akun tester 12345 untuk uji coba). */
function tryoutUserAllowed(PDO $pdo, array $user): bool {
    $role = strtolower((string)($user['role'] ?? 'mahasiswa'));
    if ($role === 'admin') return true;
    if ((string)($user['username'] ?? '') === '12345' || (string)($user['nim'] ?? '') === '12345' || (int)($user['id'] ?? 0) === 12345) return true;
    return $role === 'mahasiswa' && pretesExamIsRegisteredActive($pdo, $user);
}

// ── API JSON ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $reply = static function (int $status, array $data): void {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (!isLoggedIn()) $reply(401, ['success' => false, 'message' => 'Sesi login berakhir. Silakan login kembali.']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $reply(405, ['success' => false, 'message' => 'Gunakan metode POST.']);
    $raw = file_get_contents('php://input', false, null, 0, 32769);
    if (strlen((string)$raw) > 32768) $reply(413, ['success' => false, 'message' => 'Permintaan terlalu besar.']);
    $input = json_decode((string)$raw, true);
    if (!is_array($input) || !is_string($input['csrf_token'] ?? null) || !verifyCsrf($input['csrf_token'])) {
        $reply(403, ['success' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman.']);
    }
    $action = $input['action'] ?? '';
    if (!in_array($action, ['state', 'start', 'save', 'submit', 'interrupt', 'resume'], true)) {
        $reply(400, ['success' => false, 'message' => 'Aksi tryout tidak valid.']);
    }
    $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT);
    $pdo = getDBConnection();
    $session = $sessionId ? pretesTryoutGetSession($pdo, (int)$sessionId) : null;
    if (!$session) $reply(404, ['success' => false, 'message' => 'Tryout tidak ditemukan.']);
    $user = getCurrentUser();
    $userId = (int)$user['id'];
    if (!tryoutUserAllowed($pdo, $user)) {
        $reply(403, ['success' => false, 'message' => 'Try out hanya untuk mahasiswa yang terdaftar pada periode pretes yang sedang berlangsung.']);
    }

    try {
        $window = pretesTryoutWindow($session);
        if ($action === 'start' && !$window['open']) throw new RuntimeException($window['notice']);
        $fresh = !empty($input['fresh']) && in_array($action, ['state', 'start'], true);
        if ($fresh && !$window['open']) $fresh = false;
        $regId = pretesTryoutResolveRegId($pdo, $userId, $session, $fresh);
        $token = isset($input['token']) && is_string($input['token']) ? trim($input['token']) : null;
        $cameraRequired = ($session['camera_mode'] ?? 'wajib') === 'wajib';

        // Validasi Skenario A (Check-in Verifikasi Wajah) saat memulai tryout
        if ($action === 'start' && $cameraRequired) {
            $verifiedAt = (int)($_SESSION['pretes_cbt_verified_v2_' . $regId] ?? 0);
            if (!$verifiedAt || time() - $verifiedAt > 14400) {
                $reply(403, ['success' => false, 'message' => 'Lakukan verifikasi wajah kembali untuk mengakses tryout.']);
            }
        }

        if ($action === 'state') {
            $data = pretesCbtState($pdo, $userId, $regId);
        } elseif ($action === 'start') {
            $data = pretesCbtStart($pdo, $userId, $regId, null, $token, $session);
        } else {
            // Validasi Skenario B (Perhatian Wajah Kamera) saat melanjutkan tryout yang dijeda (resume)
            if ($action === 'resume' && $cameraRequired) {
                $state = pretesCbtState($pdo, $userId, $regId);
                if (!empty($state['attempt']['paused'])) {
                    $checkedAt = (int)($_SESSION['pretes_cbt_attention_' . $regId] ?? 0);
                    $guard = $pdo->prepare('SELECT paused_at FROM pretes_cbt_interruptions WHERE attempt_id = ?');
                    $guard->execute([$state['attempt']['id']]);
                    $pausedAt = (int)$guard->fetchColumn();
                    if (!$checkedAt || time() - $checkedAt > 30 || $checkedAt < $pausedAt) {
                        throw new RuntimeException('Periksa kembali wajah di kamera sebelum melanjutkan tryout.');
                    }
                }
            }
            $data = pretesCbtChange($pdo, $userId, $regId, $action, $input);
        }
        $attempts = pretesTryoutAttempts($pdo, $userId, (int)$session['id']);
        $data['tryout'] = [
            'attempt_no'  => pretesTryoutDecodeRegId($regId)['attempt_no'],
            'used'        => count($attempts),
            'max'         => (int)$session['max_attempts'],
            'can_retake'  => $window['open'] && pretesTryoutCanRetake($session, $attempts),
        ];
        $reply(200, ['success' => true] + $data);
    } catch (InvalidArgumentException $e) {
        $reply(422, ['success' => false, 'message' => $e->getMessage()]);
    } catch (PDOException $e) {
        error_log('Tryout database error: ' . $e->getMessage());
        $reply(503, ['success' => false, 'message' => 'Penyimpanan tryout belum tersedia. Coba lagi; jawaban yang sudah tersimpan tetap aman.']);
    } catch (Throwable $e) {
        error_log('Tryout error: ' . $e->getMessage());
        $reply(409, ['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Tryout belum dapat diproses. Hubungi admin.']);
    }
}

// ── Halaman ─────────────────────────────────────────────────────────────────
define('PAGE_TITLE', 'Tryout CBT Pretes');
requireLogin();
$user = getCurrentUser();
$pdo  = getDBConnection();
$userId = (int)$user['id'];
$tryoutAllowed = tryoutUserAllowed($pdo, $user);

$selectedId = filter_var($_GET['sesi'] ?? null, FILTER_VALIDATE_INT);
$isFresh = !empty($_GET['baru']);
$session = $selectedId ? pretesTryoutGetSession($pdo, (int)$selectedId) : null;
if (!$tryoutAllowed) { $session = null; }
$sessions = ($session || !$tryoutAllowed) ? [] : pretesTryoutActiveSessions($pdo);

$isTesterPrivileged = (isset($user['username']) && (string)$user['username'] === '12345') 
                   || (isset($user['nim']) && (string)$user['nim'] === '12345')
                   || ($userId === 12345);

$myReg = null;
$refPhoto = '';
$regId = 0;
$isCameraRequired = false;
$isVerified = false;

if ($session) {
    ensurePretesProctoringTables($pdo);
    $activeReg = pretesExamActiveRegistration($pdo, $user);
    $myReg = $activeReg ?: null;
    if (!$myReg) {
        $stmtMyReg = $pdo->prepare("SELECT * FROM pretes_registrations WHERE user_id = ? ORDER BY tanggal_daftar DESC, id DESC LIMIT 1");
        $stmtMyReg->execute([$userId]);
        $myReg = $stmtMyReg->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$myReg && $isTesterPrivileged) {
        $myReg = [
            'id'           => 999999,
            'user_id'      => $userId,
            'hari_pilihan' => 'Senin',
            'jam'          => '13.00 WIB (Jam 1)',
            'periode'      => 'Pretes Tutorial Ubudiyah 2026 (Mode Uji Coba Tryout)',
            'foto'         => !empty($user['foto']) ? $user['foto'] : '',
            'status'       => 'terdaftar',
            'username_tes' => ($user['nim'] ?? '12345') . '.22205',
            'password_tes' => '123456',
            'ruangan'      => 'Lab Komputer (Uji Coba Tryout Akun 12345)'
        ];
    }
    $refPhoto = $myReg ? trim((string)($myReg['foto'] ?? '')) : '';
    $regId = pretesTryoutResolveRegId($pdo, $userId, $session, $isFresh);

    // Handle Aksi Pintas Pengembang / Tester 12345
    if ($isTesterPrivileged && isset($_GET['action'])) {
        if ($_GET['action'] === 'reset_session') {
            unset($_SESSION['pretes_cbt_verified_' . $regId]);
            unset($_SESSION['pretes_cbt_verified_v2_' . $regId]);
            unset($_SESSION['pretes_cbt_attention_' . $regId]);
            header('Location: ' . BASE_URL . '/tryout.php?sesi=' . (int)$session['id'] . ($isFresh ? '&baru=1' : ''));
            exit;
        } elseif ($_GET['action'] === 'bypass_checkin' && getenv('PRETES_ALLOW_TEST_BYPASS') === '1') {
            $_SESSION['pretes_cbt_verified_' . $regId] = time();
            $_SESSION['pretes_cbt_verified_v2_' . $regId] = time();
            $_SESSION['pretes_cbt_attention_' . $regId] = time();
            header('Location: ' . BASE_URL . '/tryout.php?sesi=' . (int)$session['id'] . ($isFresh ? '&baru=1' : ''));
            exit;
        }
    }

    $isCameraRequired = ($session['camera_mode'] ?? 'wajib') === 'wajib';

    $attempts = pretesTryoutAttempts($pdo, $userId, (int)$session['id']);
    $isViewingSubmitted = false;
    if (!$isFresh && !empty($attempts)) {
        $latestAttempt = end($attempts);
        if (!empty($latestAttempt['submitted_at'])) {
            $isViewingSubmitted = true;
        }
    }

    // Jika kamera tidak wajib ATAU sedang melihat hasil attempt yang telah dikumpulkan,
    // tidak perlu check-in wajah
    $isVerified = !$isCameraRequired || $isViewingSubmitted || (
        !empty($_SESSION['pretes_cbt_verified_v2_' . $regId])
        && (time() - (int)$_SESSION['pretes_cbt_verified_v2_' . $regId] <= 14400)
    );
}

$fmtTime = static fn($ts) => $ts ? date('d/m/Y H:i', (int)$ts) : '-';
$scoreText = static fn($a) => $a['submitted_at'] !== null ? number_format((float)$a['score'], 1) : 'Berlangsung';

include __DIR__ . '/../includes/header.php';
?>
<style>
.to-hero{background:linear-gradient(135deg,#1e3a8a 0%,#2563eb 100%);color:#fff;border-radius:18px;padding:22px 26px;margin-bottom:20px;box-shadow:0 12px 30px rgba(30,58,138,.18)}
.to-hero h2{margin:0 0 6px;font-size:22px;font-weight:800;color:#fff}
.to-hero p{margin:0;opacity:.88;font-size:13.5px;line-height:1.6}
.to-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.to-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px;box-shadow:0 4px 14px rgba(15,23,42,.04);display:flex;flex-direction:column;gap:10px}
.to-card h3{margin:0;font-size:16.5px;font-weight:800;color:#0f172a}
.to-meta{display:flex;flex-wrap:wrap;gap:6px}
.to-chip{font-size:11.5px;font-weight:700;padding:4px 9px;border-radius:999px;background:#eff6ff;color:#1e40af}
.to-chip.warn{background:#fef3c7;color:#92400e}
.to-chip.ok{background:#dcfce7;color:#166534}
.to-desc{font-size:13px;color:#475569;margin:0;line-height:1.55}
.to-empty{text-align:center;padding:42px 20px;background:#fff;border:1px dashed #cbd5e1;border-radius:16px;color:#475569}
.to-history{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;margin-top:18px}
.to-history h4{margin:0 0 10px;font-size:15px;font-weight:800;color:#0f172a}
.to-history table{width:100%;border-collapse:collapse;font-size:13px}
.to-history th,.to-history td{padding:8px 10px;border-bottom:1px solid #eef2f7;text-align:left}
.to-history th{font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b}
.to-retake{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px}

/* ── Styling Skenario A & B Proctoring Engine ── */
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

<?php if ($isTesterPrivileged && $session): ?>
    <!-- ── TOOLBAR PENGEMBANG / TESTER KHUSUS AKUN 12345 ── -->
    <div style="background:#fffbeb;border:2px dashed #f59e0b;padding:14px 18px;border-radius:14px;margin-bottom:20px;box-shadow:0 2px 10px rgba(245,158,11,0.08);">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                <span style="font-size:26px;flex-shrink:0;">🧪</span>
                <div>
                    <strong style="color:#92400e;font-size:14px;display:block;">Panel Percobaan Tryout CBT (Hak Akses Akun: <?= sanitize($user['username'] ?? '12345') ?>)</strong>
                    <div style="color:#b45309;font-size:12px;margin-top:2px;">
                        Mode Kamera: <strong><?= $isCameraRequired ? 'Wajib Kamera (Skenario A & B)' : 'Tidak Aktif (Mandiri)' ?></strong> · RegID: <code><?= (int)$regId ?></code>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="?sesi=<?= (int)$session['id'] ?><?= $isFresh ? '&amp;baru=1' : '' ?>&amp;action=reset_session" class="btn btn-sm btn-secondary" style="font-size:11.5px;padding:6px 12px;border-radius:8px;font-weight:700;">
                    🔄 Reset Sesi (Ulang Skenario A)
                </a>
                <?php if (getenv('PRETES_ALLOW_TEST_BYPASS') === '1'): ?>
                <a href="?sesi=<?= (int)$session['id'] ?><?= $isFresh ? '&amp;baru=1' : '' ?>&amp;action=bypass_checkin" class="btn btn-sm" style="font-size:11.5px;padding:6px 12px;border-radius:8px;font-weight:700;background:#0f766e;color:#fff;">
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

<?php if (!$tryoutAllowed): ?>
    <div class="to-empty">
        <div style="font-size:40px">🔒</div>
        <h3 style="margin:8px 0 6px">Try Out Khusus Peserta Pretes</h3>
        <p style="margin:0 0 14px">Try out CBT hanya dapat dikerjakan oleh mahasiswa yang <strong>terdaftar pada periode pretes yang sedang berlangsung</strong>.</p>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/pretes-daftar.php">Daftar Pretes</a>
    </div>
<?php elseif (!$session): ?>
    <div class="to-hero">
        <h2>📝 Tryout CBT Pretes</h2>
        <p>Latihan ujian dengan sistem yang sama seperti CBT pretes resmi: soal dan pilihan acak, pengawasan kamera web (jika sesi mewajibkan), waktu dihitung server, nilai keluar otomatis. Nilai tryout <strong>tidak memengaruhi</strong> nilai atau kelulusan pretes resmi.</p>
    </div>
    <?php if (!$sessions): ?>
        <div class="to-empty">
            <div style="font-size:40px">🗓️</div>
            <h3 style="margin:8px 0 6px">Belum Ada Tryout yang Dibuka</h3>
            <p style="margin:0">Tryout akan muncul di sini saat dibuka oleh admin LPPAI.</p>
        </div>
    <?php else: ?>
        <div class="to-grid">
        <?php foreach ($sessions as $ses):
            $window = pretesTryoutWindow($ses);
            $attempts = pretesTryoutAttempts($pdo, $userId, (int)$ses['id']);
            $latest = $attempts ? end($attempts) : null;
            $best = null;
            foreach ($attempts as $a) if ($a['submitted_at'] !== null && ($best === null || (float)$a['score'] > $best)) $best = (float)$a['score'];
            $max = (int)$ses['max_attempts'];
            $camReq = ($ses['camera_mode'] ?? 'wajib') === 'wajib';
        ?>
            <div class="to-card">
                <h3><?= sanitize($ses['title']) ?></h3>
                <div class="to-meta">
                    <span class="to-chip">⏱️ <?= (int)$ses['duration_minutes'] ?> menit</span>
                    <span class="to-chip">🔁 <?= $max === 0 ? 'Bisa diulang' : ('Maks. ' . $max . '× pengerjaan') ?></span>
                    <span class="to-chip <?= $camReq ? 'ok' : '' ?>"><?= $camReq ? '📷 Pengawas Kamera' : '💻 Mandiri' ?></span>
                    <?php if (!empty($ses['token_required'])): ?><span class="to-chip warn">🔑 Perlu token</span><?php endif; ?>
                    <?php if ($best !== null): ?><span class="to-chip ok">🏆 Terbaik <?= number_format($best, 1) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($ses['description'])): ?><p class="to-desc"><?= sanitize($ses['description']) ?></p><?php endif; ?>
                <?php if (!empty($ses['start_time']) || !empty($ses['end_time'])): ?>
                    <p class="to-desc">🟢 <?= !empty($ses['start_time']) ? sanitize(date('d/m/Y H:i', strtotime($ses['start_time']))) : 'Langsung' ?> &nbsp;·&nbsp; 🔴 <?= !empty($ses['end_time']) ? sanitize(date('d/m/Y H:i', strtotime($ses['end_time']))) : 'Tanpa batas' ?></p>
                <?php endif; ?>
                <p class="to-desc">Sudah dikerjakan: <strong><?= count($attempts) ?>×</strong><?= $latest && $latest['submitted_at'] === null ? ' · <span style="color:#b45309;font-weight:700">ada pengerjaan yang belum dikumpulkan</span>' : '' ?></p>
                <?php if ($window['open']): ?>
                    <a class="btn btn-primary" style="text-align:center" href="<?= BASE_URL ?>/tryout.php?sesi=<?= (int)$ses['id'] ?>">
                        <?= !$attempts ? '▶ Mulai Tryout' : ($latest['submitted_at'] === null ? '▶ Lanjutkan Tryout' : '📊 Lihat Hasil / Ulangi') ?>
                    </a>
                <?php else: ?>
                    <div class="to-chip warn" style="text-align:center"><?= sanitize($window['notice']) ?></div>
                    <?php if ($attempts): ?><a class="btn btn-secondary" style="text-align:center" href="<?= BASE_URL ?>/tryout.php?sesi=<?= (int)$ses['id'] ?>">📊 Lihat Hasil</a><?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else:
    $window = pretesTryoutWindow($session);
    $attempts = pretesTryoutAttempts($pdo, $userId, (int)$session['id']);
    $max = (int)$session['max_attempts'];
?>
    <div style="margin-bottom:14px"><a href="<?= BASE_URL ?>/tryout.php" class="btn btn-secondary btn-sm">← Daftar Tryout</a></div>
    <div class="to-hero">
        <h2>📝 <?= sanitize($session['title']) ?></h2>
        <p><?= (int)$session['duration_minutes'] ?> menit · <?= $max === 0 ? 'boleh diulang tanpa batas' : ('maksimal ' . $max . '× pengerjaan') ?> · <?= $isCameraRequired ? 'pengawasan kamera AI aktif' : 'mode mandiri tanpa kamera' ?> · nilai tryout tidak memengaruhi pretes resmi.<?= !$window['open'] ? ' <strong>' . sanitize($window['notice']) . '</strong>' : '' ?></p>
    </div>

    <?php if (!$window['open'] && !$attempts): ?>
        <div class="to-empty"><div style="font-size:40px">⏳</div><h3 style="margin:8px 0 6px">Tryout Belum Bisa Dikerjakan</h3><p style="margin:0"><?= sanitize($window['notice']) ?></p></div>

    <?php elseif ($isCameraRequired && empty($refPhoto)): ?>
        <!-- ── State: Pasfoto Pendaftaran Belum Lengkap ── -->
        <div class="card" style="border:2px solid #eab308; border-radius:16px; padding:32px 20px; text-align:center; margin-top:20px; background:#fff;">
            <span style="font-size:48px; display:block; margin-bottom:12px;">📸</span>
            <h3 style="margin:0 0 8px; font-weight:800; color:#854d0e;">Pasfoto Resmi Belum Terdaftar</h3>
            <p style="color:#64748b; font-size:14px; max-width:500px; margin:0 auto 20px; line-height:1.5;">
                Sistem pengawasan AI memerlukan pasfoto resmi acuan Anda untuk memverifikasi keaslian peserta saat tryout berlangsung.
            </p>
            <a href="<?= BASE_URL ?>/pretes-daftar.php" class="btn btn-primary" style="padding:10px 24px; font-weight:700; border-radius:10px;">
                Perbarui Pasfoto Resmi ➔
            </a>
        </div>

    <?php else: ?>

        <!-- ═════════════════════════════════════════════════════════════════════
             BAGIAN 1: SKENARIO A — CHECK-IN VERIFIKASI WAJAH DI AWAL TRYOUT
             ═════════════════════════════════════════════════════════════════════ -->
        <div id="sectionCheckin" style="<?= $isVerified ? 'display:none;' : 'display:block;' ?>">
            <div style="margin-bottom:18px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                    <div>
                        <h2 style="margin:0; font-size:22px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                            <span>🛡️</span> <span>Verifikasi Identitas Peserta Tryout</span>
                        </h2>
                        <p style="margin:4px 0 0; color:#64748b; font-size:13.5px;">
                            Tahap 1 (Skenario A): Pengecekan wajah live mahasiswa terhadap pasfoto pendaftaran resmi sebelum membuka soal tryout.
                        </p>
                    </div>
                    <div class="proctoring-badge badge-waiting" id="badgeCheckinStatus">
                        ⏳ Menunggu Verifikasi Awal
                    </div>
                </div>
            </div>

            <div class="card" style="border:1.5px solid #cbd5e1; border-radius:16px; overflow:hidden; box-shadow:0 4px 18px rgba(0,0,0,0.04); background:#fff;">
                <div style="background:#f8fafc; border-bottom:1.5px solid #e2e8f0; padding:14px 18px; font-size:13px; color:#334155;">
                    <strong>Petunjuk:</strong> Pratinjau kamera tampil seperti cermin. <strong>Kanan dan kiri mengikuti tubuh Anda.</strong> Putar kepala mengikuti instruksi, tahan hingga hitung mundur selesai, lalu kembali ke posisi awal. Pastikan hanya Anda berada di kamera dengan pencahayaan terang.
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
             BAGIAN 2: SKENARIO B — RUANG PENGERJAAN TRYOUT DENGAN PENGAWAS AI
             ═════════════════════════════════════════════════════════════════════ -->
        <div id="sectionExamRoom" style="<?= $isVerified ? 'display:block;' : 'display:none;' ?>">
            
            <!-- Proctoring Floating Status Bar -->
            <div class="proctoring-monitor-bar">
                <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                    <span style="font-size:28px;">🟢</span>
                    <div>
                        <div style="font-size:14px; font-weight:800; display:flex; align-items:center; gap:8px;">
                            <span id="proctorStatusTitle"><?= $isCameraRequired ? 'Kamera Siap untuk Tryout' : 'Tryout Mandiri' ?></span>
                            <span id="proctorFrequency" style="background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:12px; font-size:11px;">
                                <?= $isCameraRequired ? 'Pengecekan Tiap 30 Detik' : 'Tanpa Kamera' ?>
                            </span>
                        </div>
                        <div style="font-size:12px; color:#ccfbf1; margin-top:2px;" id="lastCheckInfo">
                            <?= $isCameraRequired ? 'Sesi diverifikasi. Pemeriksaan berkala dimulai saat tryout dikerjakan.' : 'Sesi tryout ini tidak mewajibkan pengawasan kamera web.' ?>
                        </div>
                    </div>
                </div>

                <!-- Countdown Timer 30 Detik -->
                <div style="display:flex; align-items:center; gap:16px;">
                    <div style="text-align:right;">
                        <div style="font-size:11px; color:#ccfbf1; text-transform:uppercase; font-weight:700;">Pengecekan Berikutnya:</div>
                        <div style="font-size:18px; font-weight:800; font-family:monospace;" id="proctorTimerDisplay"><?= $isCameraRequired ? '00:30' : 'Mandiri' ?></div>
                    </div>
                    <!-- Mini Video Stream -->
                    <div style="width:48px; height:64px; border-radius:6px; overflow:hidden; border:1.5px solid rgba(255,255,255,0.5); background:#000; <?= $isCameraRequired ? '' : 'display:none;' ?>">
                        <video id="proctorVideo" playsinline autoplay muted style="width:100%; height:100%; object-fit:cover;"></video>
                    </div>
                </div>
            </div>

            <!-- Alert Peringatan Pelanggaran Pengawas AI -->
            <div id="proctorViolationAlert" style="display:none; background:#fef2f2; border:2px solid #ef4444; color:#991b1b; padding:16px 20px; border-radius:14px; margin-bottom:20px; box-shadow:0 4px 14px rgba(239,68,68,0.15);">
                <div style="font-size:15px; font-weight:800; display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                    <span>⚠️</span> <span>PERINGATAN PENGAWAS AI TRYOUT:</span>
                </div>
                <div id="proctorViolationMsg" style="font-size:13.5px; line-height:1.5;"></div>
                <div style="margin-top:8px; font-size:12px; color:#b91c1c; font-weight:600;">
                    Perhatian: Pelanggaran dicatat pada sistem log audit proctoring pengawas ujian. Harap tetap berada di depan kamera.
                </div>
            </div>

            <?php require __DIR__ . '/../includes/pretes-cbt-view.php'; ?>

            <!-- 4 Tata Tertib Pengawasan -->
            <div class="card" style="border:1px solid #e2e8f0; border-radius:14px; padding:18px; background:#f8fafc; margin-top:20px;">
                <div style="font-size:13.5px; font-weight:800; color:#0f172a; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                    <span>📋</span> <span>Tata Tertib Tryout Selama Proctoring Berlangsung:</span>
                </div>
                <ul style="margin:0; padding-left:20px; font-size:12.5px; color:#475569; display:flex; flex-direction:column; gap:4px; line-height:1.55;">
                    <li>1. Wajib dikerjakan secara mandiri dan jujur tanpa bantuan orang lain (sebagai simulasi ujian sesungguhnya).</li>
                    <li>2. Pastikan wajah selalu menghadap ke arah layar dan tidak meninggalkan kamera selama tryout berlangsung.</li>
                    <li>3. Dilarang berpindah tab browser atau meninggalkan aplikasi; perpindahan fokus akan memicu hukuman jeda dan pengacakan ulang soal.</li>
                    <li>4. Sistem AI akan mengambil snapshot otomatis tiap 30 detik untuk memastikan kontinuitas peserta asli.</li>
                </ul>
            </div>

            <div class="to-history" id="tryoutHistory">
                <h4>📈 Riwayat Tryout Anda</h4>
                <?php if (!$attempts): ?>
                    <p style="margin:0;color:#64748b;font-size:13px">Belum ada pengerjaan. Klik <strong>Mulai Ujian</strong> di atas untuk memulai tryout.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Ke-</th><th>Mulai</th><th>Selesai</th><th>Benar</th><th>Nilai</th></tr></thead>
                        <tbody>
                        <?php foreach ($attempts as $a): ?>
                            <tr>
                                <td><?= (int)$a['attempt_no'] ?></td>
                                <td><?= $fmtTime($a['started_at']) ?></td>
                                <td><?= $fmtTime($a['submitted_at']) ?></td>
                                <td><?= $a['submitted_at'] !== null ? (int)$a['correct_count'] : '-' ?></td>
                                <td><strong><?= $scoreText($a) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <div class="to-retake" id="tryoutRetakeBox" style="<?= $window['open'] && pretesTryoutCanRetake($session, $attempts) ? '' : 'display:none' ?>">
                    <a class="btn btn-primary" id="tryoutRetake" href="<?= BASE_URL ?>/tryout.php?sesi=<?= (int)$session['id'] ?>&amp;baru=1" onclick="return confirm('Mulai pengerjaan tryout baru? Soal dan pilihan jawaban akan diacak ulang.')">🔁 Ulangi Tryout (soal diacak ulang)</a>
                    <span style="font-size:12.5px;color:#64748b"><?= $max === 0 ? 'Tanpa batas pengulangan.' : ('Sisa kesempatan: ' . max(0, $max - count($attempts)) . '×') ?></span>
                </div>
            </div>

        </div>

        <script>window.TOKEN_REQUIRED = <?= !empty($session['token_required']) ? 'true' : 'false' ?>;</script>
        <script src="<?= BASE_URL ?>/pretes-ujian.php?cbt_asset=1&amp;v=<?= filemtime(__DIR__ . '/../assets/js/pretes-cbt.js') ?>"></script>
        <script>
        const CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
        const REGISTRATION_ID = <?= (int)$regId ?>;
        const API_URL = <?= json_encode(BASE_URL . '/tryout.php?face_api=1') ?>;
        const CBT_API_URL = <?= json_encode(BASE_URL . '/tryout.php?api=1') ?>;
        const IS_CAMERA_REQUIRED = <?= json_encode($isCameraRequired) ?>;
        const SESSION_ID = <?= (int)$session['id'] ?>;
        const FRESH = <?= $isFresh ? 'true' : 'false' ?>;

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
        let startedHere = false;

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

            if (activeStream) {
                try {
                    activeStream.getTracks().forEach(function(t) { t.stop(); });
                } catch(e) {}
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
                    errTip = "Klik ikon <strong>Gembok (🔒)</strong> atau ikon kamera di bilah alamat URL peramban Anda, ubah Kamera menjadi <strong>Izinkan (Allow)</strong>, lalu klik tombol ini lagi.";
                } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                    errTitle = "Perangkat Kamera Tidak Ditemukan";
                    errMsg = "Tidak ada webcam yang terdeteksi di laptop atau komputer Anda.";
                    errTip = "Pastikan webcam tercolok dengan baik atau driver kamera internal aktif.";
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

            const c1 = { audio: false, video: { facingMode: currentFacingMode, width: { ideal: 640 }, height: { ideal: 480 } } };
            const c2 = { audio: false, video: true };

            navigator.mediaDevices.getUserMedia(c1)
                .then(onStreamSuccess)
                .catch(function(e1) {
                    console.warn("[Webcam] Fallback c2 universal...", e1);
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
                report += "• Status Stream Aktif: " + (activeStream ? "Aktif" : "Belum Aktif") + "\n";
                alert(report);
            }).catch(function(err) {
                alert("Diagnosa Gagal: " + err.message);
            });
        };

        function inspectCameraPermissions() {
            if (navigator.permissions && navigator.permissions.query) {
                navigator.permissions.query({ name: 'camera' }).then(function(p) {
                    if (p.state === 'granted') {
                        window.startCheckinCamera(false);
                    }
                }).catch(function(e) {});
            }
        }

        function initCameraBindings() {
            const isAlreadyVerified = <?= $isVerified ? 'true' : 'false' ?>;
            if (isAlreadyVerified) {
                initProctoringExamRoom();
                return;
            }
            inspectCameraPermissions();
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
                alertBox.innerHTML = '<strong>⏳ Sedang Mencocokkan...</strong><br>Sistem AI sedang membandingkan wajah di kamera dengan pasfoto pendaftaran resmi Anda.';
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
                        let reason = data.message || 'Wajah belum sesuai dengan pasfoto pendaftaran.';
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
                if (title) title.textContent = 'Tryout Mandiri Berlangsung';
                const freq = document.getElementById('proctorFrequency');
                if (freq) freq.textContent = 'Tanpa Kamera';
                const info = document.getElementById('lastCheckInfo');
                if (info) info.textContent = 'Sesi tryout ini tidak mewajibkan pengawasan kamera web.';
                const timerDisp = document.getElementById('proctorTimerDisplay');
                if (timerDisp) timerDisp.textContent = 'Mandiri';
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

            // Sesuaikan heading CBT untuk tryout
            var h3 = document.querySelector('#pretesCbt .cbt-heading h3');
            if (h3) h3.textContent = 'Tryout: ' + <?= json_encode($session['title'], JSON_UNESCAPED_UNICODE) ?>;
            var eyebrow = document.querySelector('#pretesCbt .cbt-eyebrow');
            if (eyebrow) eyebrow.textContent = 'LPPAI / Tryout CBT Pretes';
            var intro = document.querySelectorAll('#cbtIntro p');
            if (intro[0]) intro[0].textContent = 'Anda mendapat soal dan pilihan jawaban acak. Jika Anda meninggalkan halaman atau kamera, ujian dijeda dan soal yang sedang terbuka dipindahkan serta pilihannya diacak ulang; jawaban soal lain tetap tersimpan.';
            if (intro[1]) intro[1].textContent = 'Gunakan nomor soal untuk berpindah dan tandai soal yang ingin diperiksa kembali. Setelah dikumpulkan, nilai langsung tampil. Tryout tidak memengaruhi nilai pretes resmi.';

            function request(payload) {
                return fetch(CBT_API_URL, {
                    method: 'POST', credentials: 'same-origin', keepalive: payload.action === 'interrupt',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(Object.assign({ csrf_token: CSRF_TOKEN, session_id: SESSION_ID, fresh: FRESH }, payload))
                }).then(function (res) {
                    return res.json().catch(function () { throw new Error('Respons server tidak valid (HTTP ' + res.status + ').'); })
                        .then(function (data) {
                            if (!res.ok || !data.success) throw new Error(data.message || ('Permintaan gagal (HTTP ' + res.status + ').'));
                            var box = document.getElementById('tryoutRetakeBox');
                            if (box && data.tryout) box.style.display = (data.tryout.can_retake && !FRESH) ? '' : 'none';
                            return data;
                        });
                });
            }

            if (window.PretesCbt) {
                window.PretesCbt.init({
                    request: request,
                    readyMessage: 'Tekan Mulai Ujian ketika Anda siap mengerjakan tryout.',
                    verifyAttention: async function() {
                        if (!IS_CAMERA_REQUIRED) return;
                        const camera = document.getElementById('proctorVideo');
                        if (!camera) throw pretesRequestError('Kamera tryout tidak tersedia. Muat ulang halaman.', 'CAMERA_UNAVAILABLE');
                        if (!camera.videoWidth || !activeStream || activeStream.getVideoTracks?.().some(track => track.readyState === 'ended')) {
                            if (!navigator.mediaDevices?.getUserMedia) throw pretesRequestError('Aktifkan izin kamera untuk melanjutkan tryout.', 'CAMERA_UNAVAILABLE');
                            const stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: true });
                            if (activeStream) activeStream.getTracks().forEach(track => track.stop());
                            activeStream = stream;
                            camera.srcObject = stream;
                            await camera.play();
                        }
                        const result = await requestProctoringCheck({ action: 'periodic_check', live_photo: captureFaceFrame(camera) });
                        if (!result.verified) throw pretesRequestError(result.message || 'Hadapkan wajah ke kamera untuk melanjutkan tryout.', result.status || 'VERIFICATION_ERROR');
                    },
                    onStarted: function () {
                        startedHere = true;
                        if (IS_CAMERA_REQUIRED) {
                            activeStream?.getVideoTracks?.().forEach(track => track.addEventListener?.('ended', function() {
                                if (!cbtCompleted) recordProctoringViolation('Kamera terputus saat tryout berlangsung.');
                            }));
                            const title = document.getElementById('proctorStatusTitle');
                            if (title) title.textContent = 'Pengawas Kamera Aktif';
                            const info = document.getElementById('lastCheckInfo');
                            if (info) info.textContent = 'Pemeriksaan wajah setiap 30 detik selama tryout aktif.';
                            startProctoringTimer();
                        }
                    },
                    onSubmitted: function () {
                        cbtCompleted = true;
                        if (countdownTimer) clearInterval(countdownTimer);
                        if (proctorInterval) clearInterval(proctorInterval);
                        if (activeStream) activeStream.getTracks().forEach(function(track) { track.stop(); });
                        activeStream = null;
                        const timer = document.getElementById('proctorTimerDisplay');
                        if (timer) timer.textContent = 'Selesai';
                        const title = document.getElementById('proctorStatusTitle');
                        if (title) title.textContent = 'Tryout Selesai';
                        const frequency = document.getElementById('proctorFrequency');
                        if (frequency) frequency.textContent = 'Kamera dihentikan';
                        const info = document.getElementById('lastCheckInfo');
                        if (info) info.textContent = 'Hasil CBT tersimpan. Pengawasan kamera telah selesai.';
                        if (!startedHere) return;
                        setTimeout(function () { location.href = <?= json_encode(BASE_URL . '/tryout.php?sesi=' . (int)$session['id']) ?>; }, 1800);
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
                console.error("Periodic proctoring error:", err);
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
            alert("⏱️ Timer pengawasan berkala disetel ke 15 detik untuk pengujian cepat!");
        }
        </script>
    <?php endif; ?>
<?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
