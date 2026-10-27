<?php
/**
 * LPPAI Corner - Authentication Helper
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SessionDatabaseHandler.php';

if (session_status() === PHP_SESSION_NONE) {
    // Konfigurasi masa aktif sesi tahan lama (30 hari = 2.592.000 detik)
    $sessionLifetime = 30 * 24 * 60 * 60; // 30 hari
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    ini_set('session.cookie_lifetime', (string)$sessionLifetime);

    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
               || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
               || (defined('BASE_URL') && strpos(BASE_URL, 'https://') === 0);

    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

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
    
    // Mahasiswa dengan NIM berawalan 24 (angkatan 2024) otomatis aktif tanpa aktivasi
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'mahasiswa' && isset($_SESSION['is_active']) && $_SESSION['is_active'] == 0) {
        $nim = trim($_SESSION['nim'] ?? '');
        $angkatan = trim($_SESSION['angkatan'] ?? '');
        if (substr($nim, 0, 2) === '24' || $angkatan === '2024') {
            $_SESSION['is_active'] = 1;
            try {
                $pdo = getDBConnection();
                $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$_SESSION['user_id']]);
            } catch (Exception $e) {}
        }
    }

    // Sinkronisasi status is_active secara real-time dari DB jika di sesi masih 0
    // Mencegah mahasiswa harus logout/login ulang saat admin menyetujui akun
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'mahasiswa' && isset($_SESSION['is_active']) && $_SESSION['is_active'] == 0) {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $dbStatus = $stmt->fetchColumn();
            if ($dbStatus !== false && (int)$dbStatus == 1) {
                $_SESSION['is_active'] = 1;
            }
        } catch (Exception $e) {}
    }

    // Jika masih belum aktif, paksa ke halaman aktivasi
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
        $_SESSION['angkatan'] = $user['angkatan'] ?? '';
        
        // Cek kolom is_active jika ada di array user
        $isActive = isset($user['is_active']) ? (int)$user['is_active'] : 0;
        
        // Dosen/Admin otomatis aktif
        if ($_SESSION['role'] !== 'mahasiswa') {
            $isActive = 1;
        }

        // Mahasiswa dengan NIM berawalan 24 (angkatan 2024) otomatis aktif tanpa aktivasi
        $nim = trim($user['nim'] ?? '');
        $angkatan = trim($user['angkatan'] ?? '');
        if ($_SESSION['role'] === 'mahasiswa' && (substr($nim, 0, 2) === '24' || $angkatan === '2024')) {
            $isActive = 1;
            // Sinkronkan ke database jika di DB belum berstatus 1
            if (isset($user['is_active']) && (int)$user['is_active'] !== 1) {
                try {
                    $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$user['id']]);
                } catch (Exception $e) {}
            }
        }

        $_SESSION['is_active'] = $isActive;

        // Regenerasi ID sesi untuk keamanan (mencegah session fixation)
        session_regenerate_id(true);

        return true;
    }
    return false;
}

function logoutUser() {
    session_unset();
    session_destroy();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
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
        'angkatan' => $_SESSION['angkatan'] ?? '',
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

/**
 * Standarisasi format nomor HP/WhatsApp ke format 628...
 * Contoh: 0812... -> 62812..., 812... -> 62812..., +62812... -> 62812...
 */
function formatNoHp($noHp) {
    if (empty($noHp)) return '';
    $clean = preg_replace('/[^0-9]/', '', (string)$noHp);
    if (empty($clean)) return '';

    // Jika diawali 62...
    if (substr($clean, 0, 2) === '62') {
        return $clean;
    }
    // Jika diawali 0... (contoh 08...)
    if (substr($clean, 0, 1) === '0') {
        return '62' . substr($clean, 1);
    }
    // Jika langsung diawali 8... (contoh 812...)
    if (substr($clean, 0, 1) === '8') {
        return '62' . $clean;
    }

    return $clean;
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
            $_SESSION['angkatan'] = $user['angkatan'] ?? '';
            $_SESSION['is_active'] = isset($user['is_active']) ? (int)$user['is_active'] : 1;
            unset($_SESSION['admin_login_as']);
            header('Location: ' . BASE_URL . '/admin/users.php');
            exit;
        }
    }
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}
