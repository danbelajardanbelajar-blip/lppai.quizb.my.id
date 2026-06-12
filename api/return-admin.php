<?php
require_once __DIR__ . '/../includes/auth.php';

if (isset($_SESSION['admin_login_as'])) {
    $adminId = $_SESSION['admin_login_as'];
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$adminId]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Pulihkan sesi admin
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
        $_SESSION['nim'] = $user['nim'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['program_studi'] = $user['program_studi'];
        $_SESSION['fakultas'] = $user['fakultas'];
        
        // Hapus penanda backup
        unset($_SESSION['admin_login_as']);
        
        // Redirect kembali ke halaman user admin
        header('Location: ' . BASE_URL . '/admin/users.php');
        exit;
    }
}

// Fallback jika tidak ada admin backup
header('Location: ' . BASE_URL . '/dashboard.php');
exit;
