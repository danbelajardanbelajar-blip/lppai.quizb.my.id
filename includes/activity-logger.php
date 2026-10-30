<?php
/**
 * LPPAI Corner - Activity Logger (Login & Click Tracking)
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Pastikan tabel log tersedia di database
 */
function ensureActivityLogTablesExist($pdo = null) {
    static $ensured = false;
    if ($ensured) return;

    if ($pdo === null) {
        $pdo = getDBConnection();
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_login_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                username VARCHAR(100) NOT NULL,
                nama_lengkap VARCHAR(150) NULL,
                role VARCHAR(50) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                device VARCHAR(50) NULL,
                browser VARCHAR(50) NULL,
                status ENUM('success', 'failed') NOT NULL DEFAULT 'success',
                keterangan VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_login_created_at (created_at),
                INDEX idx_login_user_id (user_id),
                INDEX idx_login_role (role),
                INDEX idx_login_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_click_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                username VARCHAR(100) NULL,
                nama_lengkap VARCHAR(150) NULL,
                role VARCHAR(50) NULL,
                element_text VARCHAR(255) NULL,
                element_type VARCHAR(50) NULL,
                element_id VARCHAR(100) NULL,
                element_class VARCHAR(255) NULL,
                target_url VARCHAR(255) NULL,
                page_url VARCHAR(255) NOT NULL,
                page_title VARCHAR(200) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_click_created_at (created_at),
                INDEX idx_click_user_id (user_id),
                INDEX idx_click_role (role),
                INDEX idx_click_page_url (page_url)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $ensured = true;
    } catch (Exception $e) {
        error_log("Gagal membuat tabel activity logs: " . $e->getMessage());
    }
}

/**
 * Dapatkan IP client yang akurat
 */
function getActivityClientIp() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'
    ];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ipList = explode(',', $_SERVER[$header]);
            $ip = trim($ipList[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Deteksi perangkat dan peramban sederhana
 */
function getActivityDeviceBrowser($userAgent = null) {
    $ua = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
    
    $browser = 'Lainnya';
    if (stripos($ua, 'Edg') !== false) {
        $browser = 'Edge';
    } elseif (stripos($ua, 'Chrome') !== false || stripos($ua, 'CriOS') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'Safari') !== false) {
        $browser = 'Safari';
    } elseif (stripos($ua, 'Firefox') !== false || stripos($ua, 'FxiOS') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($ua, 'Opera') !== false || stripos($ua, 'OPR') !== false) {
        $browser = 'Opera';
    } elseif (stripos($ua, 'AppleWebKit') !== false && preg_match('/(iphone|ipad|ipod)/i', $ua)) {
        $browser = 'Mobile Safari';
    }

    $device = 'Desktop';
    if (preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i', $ua)) {
        $device = 'Mobile';
    } elseif (preg_match('/(ipad|tablet|(android(?!.*mobile))|(windows(?!.*phone)(.*touch))|kindle|playbook|silk|(puffin(?!.*(IP|AP|WP))))/i', $ua)) {
        $device = 'Tablet';
    }

    return [
        'browser' => $browser,
        'device' => $device,
        'raw_ua' => substr($ua, 0, 255)
    ];
}

/**
 * Catat aktivitas login
 */
function recordLoginLog(array $data) {
    try {
        $pdo = getDBConnection();
        ensureActivityLogTablesExist($pdo);

        $uaInfo = getActivityDeviceBrowser();
        $ip = getActivityClientIp();

        $userId = !empty($data['user_id']) ? (int)$data['user_id'] : null;
        $username = trim($data['username'] ?? '');
        $namaLengkap = !empty($data['nama_lengkap']) ? trim($data['nama_lengkap']) : null;
        $role = !empty($data['role']) ? trim($data['role']) : null;
        $status = ($data['status'] ?? 'success') === 'failed' ? 'failed' : 'success';
        $keterangan = !empty($data['keterangan']) ? substr(trim($data['keterangan']), 0, 255) : null;

        $stmt = $pdo->prepare("
            INSERT INTO user_login_logs 
            (user_id, username, nama_lengkap, role, ip_address, user_agent, device, browser, status, keterangan) 
            VALUES (:user_id, :username, :nama_lengkap, :role, :ip_address, :user_agent, :device, :browser, :status, :keterangan)
        ");

        return $stmt->execute([
            'user_id' => $userId,
            'username' => $username,
            'nama_lengkap' => $namaLengkap,
            'role' => $role,
            'ip_address' => $ip,
            'user_agent' => $uaInfo['raw_ua'],
            'device' => $uaInfo['device'],
            'browser' => $uaInfo['browser'],
            'status' => $status,
            'keterangan' => $keterangan,
        ]);
    } catch (Exception $e) {
        error_log("Gagal mencatat login log: " . $e->getMessage());
        return false;
    }
}

/**
 * Catat aktivitas klik pengguna
 */
function recordClickLog(array $data) {
    try {
        $pdo = getDBConnection();
        ensureActivityLogTablesExist($pdo);

        $uaInfo = getActivityDeviceBrowser();
        $ip = getActivityClientIp();

        $userId = !empty($data['user_id']) ? (int)$data['user_id'] : null;
        $username = !empty($data['username']) ? trim($data['username']) : null;
        $namaLengkap = !empty($data['nama_lengkap']) ? trim($data['nama_lengkap']) : null;
        $role = !empty($data['role']) ? trim($data['role']) : null;

        $elementText = !empty($data['element_text']) ? substr(trim($data['element_text']), 0, 255) : null;
        $elementType = !empty($data['element_type']) ? substr(trim($data['element_type']), 0, 50) : 'click';
        $elementId = !empty($data['element_id']) ? substr(trim($data['element_id']), 0, 100) : null;
        $elementClass = !empty($data['element_class']) ? substr(trim($data['element_class']), 0, 255) : null;
        $targetUrl = !empty($data['target_url']) ? substr(trim($data['target_url']), 0, 255) : null;
        $pageUrl = !empty($data['page_url']) ? substr(trim($data['page_url']), 0, 255) : '/';
        $pageTitle = !empty($data['page_title']) ? substr(trim($data['page_title']), 0, 200) : null;

        $stmt = $pdo->prepare("
            INSERT INTO user_click_logs 
            (user_id, username, nama_lengkap, role, element_text, element_type, element_id, element_class, target_url, page_url, page_title, ip_address, user_agent) 
            VALUES (:user_id, :username, :nama_lengkap, :role, :element_text, :element_type, :element_id, :element_class, :target_url, :page_url, :page_title, :ip_address, :user_agent)
        ");

        return $stmt->execute([
            'user_id' => $userId,
            'username' => $username,
            'nama_lengkap' => $namaLengkap,
            'role' => $role,
            'element_text' => $elementText,
            'element_type' => $elementType,
            'element_id' => $elementId,
            'element_class' => $elementClass,
            'target_url' => $targetUrl,
            'page_url' => $pageUrl,
            'page_title' => $pageTitle,
            'ip_address' => $ip,
            'user_agent' => $uaInfo['raw_ua'],
        ]);
    } catch (Exception $e) {
        error_log("Gagal mencatat click log: " . $e->getMessage());
        return false;
    }
}

/**
 * Ambil data log login terbaru
 */
function getRecentLoginLogs($limit = 50) {
    try {
        $pdo = getDBConnection();
        ensureActivityLogTablesExist($pdo);
        $lim = (int)$limit;
        if ($lim <= 0) $lim = 50;
        $stmt = $pdo->query("
            SELECT * FROM user_login_logs 
            ORDER BY created_at DESC 
            LIMIT $lim
        ");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        error_log("Error getRecentLoginLogs: " . $e->getMessage());
        return [];
    }
}

/**
 * Ambil data log klik terbaru
 */
function getRecentClickLogs($limit = 100) {
    try {
        $pdo = getDBConnection();
        ensureActivityLogTablesExist($pdo);
        $lim = (int)$limit;
        if ($lim <= 0) $lim = 100;
        $stmt = $pdo->query("
            SELECT * FROM user_click_logs 
            ORDER BY created_at DESC 
            LIMIT $lim
        ");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        error_log("Error getRecentClickLogs: " . $e->getMessage());
        return [];
    }
}

/**
 * Ambil statistik ringkasan log aktivitas
 */
function getActivityLogStats() {
    try {
        $pdo = getDBConnection();
        ensureActivityLogTablesExist($pdo);
        $today = date('Y-m-d');

        $stmt1 = $pdo->prepare("SELECT COUNT(*) FROM user_login_logs WHERE DATE(created_at) = :today");
        $stmt1->execute(['today' => $today]);
        $loginToday = (int)$stmt1->fetchColumn();

        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM user_click_logs WHERE DATE(created_at) = :today");
        $stmt2->execute(['today' => $today]);
        $clickToday = (int)$stmt2->fetchColumn();

        $totalLogin = (int)$pdo->query("SELECT COUNT(*) FROM user_login_logs")->fetchColumn();
        $totalClick = (int)$pdo->query("SELECT COUNT(*) FROM user_click_logs")->fetchColumn();

        return [
            'login_today' => $loginToday,
            'click_today' => $clickToday,
            'total_login' => $totalLogin,
            'total_click' => $totalClick,
        ];
    } catch (Exception $e) {
        return [
            'login_today' => 0,
            'click_today' => 0,
            'total_login' => 0,
            'total_click' => 0,
        ];
    }
}
