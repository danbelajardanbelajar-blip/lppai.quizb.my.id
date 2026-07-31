<?php
/**
 * LPPAI Corner - Authentication Helper
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SessionDatabaseHandler.php';

if (session_status() === PHP_SESSION_NONE) {
    $pdo = getDBConnection();
    $sessionHandler = new SessionDatabaseHandler($pdo);
    session_set_save_handler($sessionHandler, true);
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isAdmin() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isDosen() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'dosen';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
    
    // Jika mahasiswa dan belum aktif, paksa ke halaman aktivasi
    // Cegah redirect loop jika sudah berada di halaman aktivasi.php
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'mahasiswa' && isset($_SESSION['is_active']) && $_SESSION['is_active'] == 0) {
        $current_script = basename($_SERVER['PHP_SELF']);
        if ($current_script !== 'aktivasi.php') {
            header('Location: ' . BASE_URL . '/aktivasi.php');
            exit;
        }
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
    // Auto Backup hook
    require_once __DIR__ . '/BackupManager.php';
    BackupManager::autoBackup();
}

function requireDosen() {
    requireLogin();
    if (!isDosen()) {
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
}

function loginUser($username, $password) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
        $_SESSION['nim'] = $user['nim'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['program_studi'] = $user['program_studi'];
        $_SESSION['fakultas'] = $user['fakultas'];
        
        // Cek kolom is_active jika ada di array user (setelah migrasi), jika belum ada anggap 1 agar aman sementara.
        // Tapi kita akan paksa 0 untuk mahasiswa berdasarkan plan jika belum ada (walaupun setelah migrasi default 0).
        // Lebih baik:
        $_SESSION['is_active'] = isset($user['is_active']) ? (int)$user['is_active'] : 0;
        // Dosen/Admin otomatis aktif jika kolom is_active belum terupdate
        if ($_SESSION['role'] !== 'mahasiswa') {
            $_SESSION['is_active'] = 1;
        }

        return true;
    }
    return false;
}

function logoutUser() {
    session_unset();
    session_destroy();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'nama_lengkap' => $_SESSION['nama_lengkap'],
        'nim' => $_SESSION['nim'] ?? '',
        'role' => $_SESSION['role'],
        'program_studi' => $_SESSION['program_studi'] ?? '',
        'fakultas' => $_SESSION['fakultas'] ?? '',
        'is_active' => $_SESSION['is_active'] ?? 0,
    ];
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Global handler for Return to Admin
if (isset($_GET['action']) && $_GET['action'] === 'return_admin') {
    if (isset($_SESSION['admin_login_as'])) {
        $adminId = $_SESSION['admin_login_as'];
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$adminId]);
        $user = $stmt->fetch();
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
            $_SESSION['nim'] = $user['nim'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['program_studi'] = $user['program_studi'];
            $_SESSION['fakultas'] = $user['fakultas'];
            $_SESSION['is_active'] = isset($user['is_active']) ? (int)$user['is_active'] : 1;
            unset($_SESSION['admin_login_as']);
            header('Location: ' . BASE_URL . '/admin/users.php');
            exit;
        }
    }
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}
