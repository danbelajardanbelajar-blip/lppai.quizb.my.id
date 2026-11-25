<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-rpl-cbt-engine.php';

function tutorialRplCbtReply(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) tutorialRplCbtReply(401, ['success' => false, 'message' => 'Sesi login berakhir. Silakan login kembali.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') tutorialRplCbtReply(405, ['success' => false, 'message' => 'Gunakan metode POST.']);

$raw = file_get_contents('php://input', false, null, 0, 32769);
if (strlen($raw) > 32768) tutorialRplCbtReply(413, ['success' => false, 'message' => 'Permintaan terlalu besar.']);
$input = json_decode($raw, true);
if (!is_array($input) || !is_string($input['csrf_token'] ?? null) || !verifyCsrf($input['csrf_token'])) {
    tutorialRplCbtReply(403, ['success' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman.']);
}

$user = getCurrentUser();
$userId = (int)$user['id'];
$regId = filter_var($input['registration_id'] ?? null, FILTER_VALIDATE_INT);
if (!$regId || $regId < 1) tutorialRplCbtReply(400, ['success' => false, 'message' => 'Keanggotaan Tutorial RPL tidak valid.']);

$pdo = getDBConnection();
ensureTutorialRplCbtProctoringTables($pdo);

$isTester = (string)($user['username'] ?? '') === '12345' || (string)($user['nim'] ?? '') === '12345' || $userId === 12345;
$membership = tutorialRplGetStudentMembership($pdo, $userId);
if ($membership && (int)$membership['id'] === (int)$regId) {
    $classId = (int)$membership['class_id'];
} elseif ($isTester && (int)$regId === 999999) {
    $classId = $membership ? (int)$membership['class_id'] : 999999;
} else {
    tutorialRplCbtReply(403, ['success' => false, 'message' => 'Keanggotaan Tutorial RPL bukan milik akun Anda.']);
}

$sessionIdInput = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$existingAttempt = tutorialRplCbtGetStudentAttempt($pdo, $userId, $classId);
$effectiveSessionId = $existingAttempt && !empty($existingAttempt['session_id'])
    ? (int)$existingAttempt['session_id']
    : $sessionIdInput;
$activeSession = tutorialRplCbtGetActiveSession($pdo, $effectiveSessionId);
$cameraRequired = ($activeSession['camera_mode'] ?? 'tidak_aktif') === 'wajib';

if ($cameraRequired) {
    $verifiedAt = (int)($_SESSION['tutorial_rpl_cbt_verified_v2_' . $regId] ?? 0);
    if (!$verifiedAt || time() - $verifiedAt > 14400) {
        tutorialRplCbtReply(403, ['success' => false, 'message' => 'Lakukan verifikasi wajah kembali untuk mengakses CBT.']);
    }
}

$action = $input['action'] ?? '';
if (!in_array($action, ['state', 'start', 'save', 'submit', 'interrupt', 'resume'], true)) {
    tutorialRplCbtReply(400, ['success' => false, 'message' => 'Aksi CBT tidak valid.']);
}

try {
    if ($action === 'state') {
        $data = tutorialRplCbtState($pdo, $userId, $classId);
    } elseif ($action === 'start') {
        $tokenInput = isset($input['token']) && is_string($input['token']) ? trim($input['token']) : null;
        $data = tutorialRplCbtStart($pdo, $userId, $classId, null, $tokenInput, $isTester, $sessionIdInput);
    } else {
        if ($action === 'resume' && $cameraRequired) {
            $state = tutorialRplCbtState($pdo, $userId, $classId);
            if (!empty($state['attempt']['paused'])) {
                $checkedAt = (int)($_SESSION['tutorial_rpl_cbt_attention_' . $regId] ?? 0);
                $guard = $pdo->prepare('SELECT paused_at FROM tutorial_rpl_cbt_interruptions WHERE attempt_id = ?');
                $guard->execute([$state['attempt']['id']]);
                $pausedAt = (int)$guard->fetchColumn();
                if (!$checkedAt || time() - $checkedAt > 30 || $checkedAt < $pausedAt) {
                    throw new RuntimeException('Periksa kembali wajah di kamera sebelum melanjutkan ujian.');
                }
            }
        }
        $data = tutorialRplCbtChange($pdo, $userId, $classId, $action, $input);
    }
    tutorialRplCbtReply(200, ['success' => true] + $data);
} catch (InvalidArgumentException $e) {
    tutorialRplCbtReply(422, ['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('Tutorial RPL CBT database error: ' . $e->getMessage());
    tutorialRplCbtReply(503, ['success' => false, 'message' => 'Penyimpanan ujian belum tersedia. Coba lagi; jawaban yang sudah tersimpan tetap aman.']);
} catch (Throwable $e) {
    error_log('Tutorial RPL CBT error: ' . $e->getMessage());
    tutorialRplCbtReply(409, ['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Ujian belum dapat diproses. Hubungi admin.']);
}
