<?php
/**
 * LPPAI Corner - AJAX Endpoint: Log User Click Activity
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/activity-logger.php';

header('Content-Type: application/json; charset=utf-8');

// Ambil payload dari $_POST (FormData) atau JSON php://input
$data = [];
if (!empty($_POST)) {
    $data = $_POST;
} else {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
}

if (empty($data)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

$currentUser = getCurrentUser();
$userId = $currentUser ? ($currentUser['id'] ?? null) : null;
$username = $currentUser ? ($currentUser['username'] ?? null) : 'guest';
$namaLengkap = $currentUser ? ($currentUser['nama_lengkap'] ?? null) : 'Tamu / Belum Login';
$role = $currentUser ? ($currentUser['role'] ?? 'guest') : 'guest';

$elementText = isset($data['element_text']) ? trim((string)$data['element_text']) : '';
$elementType = isset($data['element_type']) ? trim((string)$data['element_type']) : 'click';
$elementId = isset($data['element_id']) ? trim((string)$data['element_id']) : '';
$elementClass = isset($data['element_class']) ? trim((string)$data['element_class']) : '';
$targetUrl = isset($data['target_url']) ? trim((string)$data['target_url']) : '';
$pageUrl = isset($data['page_url']) ? trim((string)$data['page_url']) : '';
$pageTitle = isset($data['page_title']) ? trim((string)$data['page_title']) : '';

// Jika benar-benar kosong semua, gunakan fallback
if (empty($elementText) && empty($elementId) && empty($targetUrl)) {
    $elementText = 'Klik Interaktif';
}

$ok = recordClickLog([
    'user_id' => $userId,
    'username' => $username,
    'nama_lengkap' => $namaLengkap,
    'role' => $role,
    'element_text' => $elementText,
    'element_type' => $elementType,
    'element_id' => $elementId,
    'element_class' => $elementClass,
    'target_url' => $targetUrl,
    'page_url' => $pageUrl ?: ($_SERVER['HTTP_REFERER'] ?? '/'),
    'page_title' => $pageTitle,
]);

echo json_encode(['success' => (bool)$ok]);
exit;
