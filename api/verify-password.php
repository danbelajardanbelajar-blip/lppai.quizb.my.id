<?php
/**
 * LPPAI Corner - API: Verifikasi Password Pengguna
 * Digunakan oleh halaman pretes-peserta (mahasiswa & admin)
 * untuk memastikan pengguna memasukkan password akun mereka
 * sendiri sebelum password tes tulis ditampilkan.
 *
 * CATATAN: ob_start() dipanggil paling awal untuk menangkap
 * segala PHP notice/warning agar tidak merusak output JSON.
 * session_start() hanya dipanggil jika belum aktif.
 */

ob_start(); // tangkap semua output sebelum header JSON

require_once __DIR__ . '/../includes/auth.php';

ob_clean(); // buang semua output PHP sebelum header
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Tidak terautentikasi.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method tidak diizinkan.']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!verifyCsrf($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Token tidak valid. Muat ulang halaman dan coba lagi.']);
    exit;
}

$inputPassword = $_POST['password'] ?? '';
if ($inputPassword === '') {
    echo json_encode(['ok' => false, 'message' => 'Password tidak boleh kosong.']);
    exit;
}

try {
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if ($user && password_verify($inputPassword, $user['password'])) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'message' => 'Password salah. Coba lagi.']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Kesalahan server. Coba lagi.']);
}
