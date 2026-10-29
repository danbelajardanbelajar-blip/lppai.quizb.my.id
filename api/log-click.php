<?php
/**
 * LPPAI Corner - API Endpoint: Log User Click Activity
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activity-logger.php';

header('Content-Type: application/json; charset=utf-8');

// Ambil payload JSON atau POST form
$raw = file_get_contents('php://input');
$data = [];

if (!empty($raw)) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

if (empty($data) && !empty($_POST)) {
    $data = $_POST;
}

if (empty($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

// Dapatkan data user saat ini jika sedang login
$currentUser = getCurrentUser();

$userId = $currentUser ? ($currentUser['id'] ?? null) : null;
$username = $currentUser ? ($currentUser['username'] ?? null) : 'guest';
$namaLengkap = $currentUser ? ($currentUser['nama_lengkap'] ?? null) : 'Tamu / Belum Login';
$role = $currentUser ? ($currentUser['role'] ?? 'guest') : 'guest';

// Bersihkan data klik
$elementText = isset($data['element_text']) ? trim((string)$data['element_text']) : '';
$elementType = isset($data['element_type']) ? trim((string)$data['element_type']) : 'click';
$elementId = isset($data['element_id']) ? trim((string)$data['element_id']) : '';
$elementClass = isset($data['element_class']) ? trim((string)$data['element_class']) : '';
$targetUrl = isset($data['target_url']) ? trim((string)$data['target_url']) : '';
$pageUrl = isset($data['page_url']) ? trim((string)$data['page_url']) : '';
$pageTitle = isset($data['page_title']) ? trim((string)$data['page_title']) : '';

// Jika tidak ada informasi klik yang berarti, abaikan
if (empty($elementText) && empty($elementId) && empty($targetUrl)) {
    echo json_encode(['success' => false, 'message' => 'Elemen klik kosong']);
    exit;
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
