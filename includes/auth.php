<?php
/**
 * LPPAI Corner - Authentication Helper
 */

session_start();

require_once __DIR__ . '/../config/database.php';

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
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
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
            unset($_SESSION['admin_login_as']);
            header('Location: ' . BASE_URL . '/admin/users.php');
            exit;
        }
    }
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}
