<?php
/**
 * LPPAI Corner - API: Verifikasi Password Pengguna
 * Digunakan oleh halaman pretes-peserta (mahasiswa & admin)
 * untuk memastikan pengguna memasukkan password akun mereka
 * sendiri sebelum password tes tulis ditampilkan.
 */

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Harus sudah login
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Tidak terautentikasi.']);
    exit;
}

// Harus POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method tidak diizinkan.']);
    exit;
}

// Verifikasi CSRF
$token = $_POST['csrf_token'] ?? '';
if (!verifyCsrf($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Token tidak valid.']);
    exit;
}

$inputPassword = $_POST['password'] ?? '';
if ($inputPassword === '') {
    echo json_encode(['ok' => false, 'message' => 'Password tidak boleh kosong.']);
    exit;
}

// Ambil hash password dari database
$pdo  = getDBConnection();
$stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($user && password_verify($inputPassword, $user['password'])) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'message' => 'Password salah. Coba lagi.']);
}
