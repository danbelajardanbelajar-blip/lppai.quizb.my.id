<?php
require_once __DIR__ . '/../includes/auth.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'dosen') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$class_id = (int)($_POST['class_id'] ?? 0);
$pertemuan_ke = (int)($_POST['pertemuan_ke'] ?? 0);

if (!$class_id || !$pertemuan_ke) {
    echo json_encode(['status' => 'error', 'message' => 'Data tidak lengkap.']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Validasi dosen berhak atas kelas ini
    $stmt = $pdo->prepare("SELECT id FROM tutorial_classes WHERE id = ? AND dosen_id = ?");
    $stmt->execute([$class_id, $_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Anda tidak memiliki akses ke kelas ini.']);
        exit;
    }
    
    // Cek apakah sudah ada sesi yang belum expired
    $stmt = $pdo->prepare("SELECT token, expires_at FROM tutorial_qr_sessions WHERE tutorial_class_id = ? AND pertemuan_ke = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $stmt->execute([$class_id, $pertemuan_ke]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing && !isset($_POST['regenerate'])) {
        // Return existing active token
        echo json_encode([
            'status' => 'success', 
            'token' => $existing['token'], 
            'expires_at' => $existing['expires_at'],
            'message' => 'Gunakan QR aktif saat ini.'
        ]);
        exit;
    }
    
    // Bikin token baru, set expiry 5 minutes (300 seconds)
    $token = bin2hex(random_bytes(16)); // 32 chars random
    
    // Update atau Insert
    $stmt = $pdo->prepare("INSERT INTO tutorial_qr_sessions (tutorial_class_id, pertemuan_ke, token, expires_at) 
                           VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))
                           ON DUPLICATE KEY UPDATE token = ?, expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE)");
    $stmt->execute([$class_id, $pertemuan_ke, $token, $token]);
    
    $stmt = $pdo->prepare("SELECT expires_at FROM tutorial_qr_sessions WHERE token = ?");
    $stmt->execute([$token]);
    $expires = $stmt->fetchColumn();
    
    echo json_encode([
        'status' => 'success',
        'token' => $token,
        'expires_at' => $expires,
        'message' => 'QR Code berhasil di-generate. (Berlaku 5 Menit)'
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
