<?php
require_once __DIR__ . '/includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$token = $_POST['token'] ?? '';
$user_id = $_SESSION['user_id'];

if (empty($token)) {
    echo json_encode(['status' => 'error', 'message' => 'Token QR Code tidak valid.']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Cek token valid dan belum expire
    $stmt = $pdo->prepare("SELECT id, tutorial_class_id, pertemuan_ke, expires_at FROM tutorial_qr_sessions WHERE token = ?");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        echo json_encode(['status' => 'error', 'message' => 'QR Code tidak ditemukan atau tidak valid.']);
        exit;
    }
    
    if (strtotime($session['expires_at']) < time()) {
        echo json_encode(['status' => 'error', 'message' => 'QR Code sudah kadaluarsa. Silakan minta Dosen untuk memperbarui QR Code.']);
        exit;
    }
    
    // Cek apakah mahasiswa terdaftar di kelas ini
    $class_id = $session['tutorial_class_id'];
    $pertemuan_ke = $session['pertemuan_ke'];
    
    $stmt = $pdo->prepare("SELECT id, status FROM tutorial_registrations WHERE tutorial_class_id = ? AND user_id = ? AND (status = 'aktif' OR status = 'lulus')");
    $stmt->execute([$class_id, $user_id]);
    $registration = $stmt->fetch();
    
    if (!$registration) {
        echo json_encode(['status' => 'error', 'message' => 'Anda tidak terdaftar sebagai peserta aktif di kelas ini.']);
        exit;
    }
    
    // Insert atau update absensi menjadi hadir
    $tanggal = date('Y-m-d');
    
    // We need to set status_hadir = 'hadir'
    $stmt = $pdo->prepare("INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) 
                           VALUES (?, ?, ?, ?, 'hadir')
                           ON DUPLICATE KEY UPDATE status_hadir = 'hadir', tanggal = ?");
    $stmt->execute([$class_id, $user_id, $pertemuan_ke, $tanggal, $tanggal]);
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Absensi berhasil direkam! (Pertemuan Ke-' . $pertemuan_ke . ')'
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
