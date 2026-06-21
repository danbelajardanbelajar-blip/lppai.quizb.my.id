<?php
/**
 * API untuk Menghapus Data Nilai (Lama)
 */
require_once __DIR__ . '/../includes/auth.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!verifyCsrf($token)) {
    echo json_encode(['success' => false, 'message' => 'Sesi tidak valid atau kadaluarsa.']);
    exit;
}

$pdo = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'delete') {
    $regId = (int)($_POST['reg_id'] ?? 0);
    if ($regId > 0) {
        $stmt = $pdo->prepare("DELETE FROM tutorial_registrations WHERE id = ?");
        if ($stmt->execute([$regId])) {
            echo json_encode(['success' => true, 'message' => 'Data nilai berhasil dihapus.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus data nilai.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
    }
    exit;
}

if ($action === 'delete_bulk') {
    $regIds = $_POST['reg_ids'] ?? [];
    if (!is_array($regIds) || empty($regIds)) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada data yang dipilih.']);
        exit;
    }
    
    // Filter to ensure all are integers
    $cleanIds = array_filter(array_map('intval', $regIds), function($id) { return $id > 0; });
    
    if (empty($cleanIds)) {
        echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
    $stmt = $pdo->prepare("DELETE FROM tutorial_registrations WHERE id IN ($placeholders)");
    
    if ($stmt->execute($cleanIds)) {
        echo json_encode(['success' => true, 'message' => count($cleanIds) . ' data nilai berhasil dihapus.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus data nilai.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']);
exit;
