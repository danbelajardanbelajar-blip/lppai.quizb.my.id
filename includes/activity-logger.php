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
                no_hp VARCHAR(30) NULL,
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

        // Pastikan kolom no_hp ada pada tabel user_login_logs jika tabel sudah dibuat sebelumnya
        try {
            $colCheck = $pdo->query("SHOW COLUMNS FROM user_login_logs LIKE 'no_hp'");
            if ($colCheck && $colCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE user_login_logs ADD COLUMN no_hp VARCHAR(30) NULL AFTER role");
            }
        } catch (Exception $colEx) {}

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
        $noHp = !empty($data['no_hp']) ? trim($data['no_hp']) : null;
        $status = ($data['status'] ?? 'success') === 'failed' ? 'failed' : 'success';
        $keterangan = !empty($data['keterangan']) ? substr(trim($data['keterangan']), 0, 255) : null;

        // Ambil no_hp dari tabel users jika belum ada di data
        if (empty($noHp) && !empty($userId)) {
            try {
                $st = $pdo->prepare("SELECT no_hp FROM users WHERE id = ? LIMIT 1");
                $st->execute([$userId]);
                $noHp = $st->fetchColumn() ?: null;
            } catch (Exception $e) {}
        }

        $stmt = $pdo->prepare("
            INSERT INTO user_login_logs 
            (user_id, username, nama_lengkap, role, no_hp, ip_address, user_agent, device, browser, status, keterangan) 
            VALUES (:user_id, :username, :nama_lengkap, :role, :no_hp, :ip_address, :user_agent, :device, :browser, :status, :keterangan)
        ");

        return $stmt->execute([
            'user_id' => $userId,
            'username' => $username,
            'nama_lengkap' => $namaLengkap,
            'role' => $role,
            'no_hp' => $noHp,
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
 * Filter pembacaan log berdasarkan username tersimpan atau akun pemilik; tidak mengubah pencatatan.
 */
function activityLogUsernameFilter(string $alias, ?string $excludedUsername): array {
    $username = strtolower(trim((string)$excludedUsername));
    if ($username === '') return ['sql' => '', 'params' => []];
    return [
        'sql' => " AND LOWER(TRIM(COALESCE($alias.username, ''))) <> :excluded_log_username
            AND NOT EXISTS (SELECT 1 FROM users hidden_user WHERE hidden_user.id = $alias.user_id
                AND LOWER(TRIM(hidden_user.username)) = :excluded_account_username)",
        'params' => ['excluded_log_username' => $username, 'excluded_account_username' => $username],
    ];
}

/**
 * Periksa ketersediaan kolom pada tabel users secara aman (kompatibel MySQL dan SQLite).
 */
function activityLogUserHasColumn(PDO $pdo, string $columnName): bool {
    static $cache = [];
    $objId = function_exists('spl_object_id') ? spl_object_id($pdo) : 0;
    $cacheKey = $objId . ':' . strtolower($columnName);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    try {
        $driver = '';
        try {
            $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable $e) {
            $driver = 'mysql';
        }
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info(users)");
            $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($cols as $col) {
                if (strcasecmp((string)($col['name'] ?? ''), $columnName) === 0) {
                    return $cache[$cacheKey] = true;
                }
            }
            return $cache[$cacheKey] = false;
        } else {
            $quoted = $pdo->quote($columnName);
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE $quoted");
            return $cache[$cacheKey] = ($stmt && $stmt->rowCount() > 0);
        }
    } catch (Throwable $e) {
        return $cache[$cacheKey] = false;
    }
}

/** Ambil data log login terbaru, dengan filter tampilan opsional. */
function getRecentLoginLogs($limit = 50, ?string $excludedUsername = null) {
    try {
        $pdo = getDBConnection();
        ensureActivityLogTablesExist($pdo);
        $lim = (int)$limit;
        if ($lim <= 0) $lim = 50;
        $filter = activityLogUsernameFilter('l', $excludedUsername);

        $hasJenis = activityLogUserHasColumn($pdo, 'jenis_program');
        $hasRole = activityLogUserHasColumn($pdo, 'role');
        $hasNama = activityLogUserHasColumn($pdo, 'nama_lengkap');

        $jenisSelect = $hasJenis ? "COALESCE(NULLIF(u.jenis_program, ''), 'Reguler')" : "'Reguler'";
        $roleSelect = $hasRole ? "COALESCE(NULLIF(l.role, ''), u.role)" : "l.role";
        $namaSelect = $hasNama ? "COALESCE(NULLIF(l.nama_lengkap, ''), u.nama_lengkap)" : "l.nama_lengkap";

        $stmt = $pdo->prepare("
            SELECT l.*,
                   COALESCE(NULLIF(l.no_hp, ''), u.no_hp) AS no_hp,
                   {$namaSelect} AS nama_lengkap,
                   {$roleSelect} AS role,
                   {$jenisSelect} AS jenis_program
            FROM user_login_logs l
            LEFT JOIN users u ON ((l.user_id IS NOT NULL AND l.user_id > 0 AND l.user_id = u.id)
                               OR ((l.user_id IS NULL OR l.user_id = 0) AND l.username IS NOT NULL AND l.username <> '' AND l.username = u.username))
            WHERE 1 = 1 {$filter['sql']}
            ORDER BY l.created_at DESC
            LIMIT $lim
        ");
        $stmt->execute($filter['params']);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        error_log("Error getRecentLoginLogs: " . $e->getMessage());
        return [];
    }
}

/**
 * Ambil data log klik terbaru
 */
function getRecentClickLogs($limit = 100, ?string $excludedUsername = null) {
    try {
        $pdo = getDBConnection();
        ensureActivityLogTablesExist($pdo);
        $lim = (int)$limit;
        if ($lim <= 0) $lim = 100;
        $filter = activityLogUsernameFilter('c', $excludedUsername);

        $hasJenis = activityLogUserHasColumn($pdo, 'jenis_program');
        $hasRole = activityLogUserHasColumn($pdo, 'role');
        $hasNama = activityLogUserHasColumn($pdo, 'nama_lengkap');

        $jenisSelect = $hasJenis ? "COALESCE(NULLIF(u.jenis_program, ''), 'Reguler')" : "'Reguler'";
        $roleSelect = $hasRole ? "COALESCE(NULLIF(c.role, ''), u.role)" : "c.role";
        $namaSelect = $hasNama ? "COALESCE(NULLIF(c.nama_lengkap, ''), u.nama_lengkap)" : "c.nama_lengkap";

        $stmt = $pdo->prepare("
            SELECT c.*,
                   {$namaSelect} AS nama_lengkap,
                   {$roleSelect} AS role,
                   {$jenisSelect} AS jenis_program
            FROM user_click_logs c
            LEFT JOIN users u ON ((c.user_id IS NOT NULL AND c.user_id > 0 AND c.user_id = u.id)
                               OR ((c.user_id IS NULL OR c.user_id = 0) AND c.username IS NOT NULL AND c.username <> '' AND c.username = u.username))
            WHERE 1 = 1 {$filter['sql']}
            ORDER BY c.created_at DESC
            LIMIT $lim
        ");
        $stmt->execute($filter['params']);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        error_log("Error getRecentClickLogs: " . $e->getMessage());
        return [];
    }
}

/**
 * Ambil statistik ringkasan log aktivitas
 */
function getActivityLogStats(?string $excludedUsername = null) {
    try {
        $pdo = getDBConnection();
        ensureActivityLogTablesExist($pdo);
        $today = date('Y-m-d');
        $filter = activityLogUsernameFilter('l', $excludedUsername);

        $stmt1 = $pdo->prepare("SELECT COUNT(*) FROM user_login_logs l WHERE DATE(l.created_at) = :today {$filter['sql']}");
        $stmt1->execute(['today' => $today] + $filter['params']);
        $loginToday = (int)$stmt1->fetchColumn();

        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM user_click_logs l WHERE DATE(l.created_at) = :today {$filter['sql']}");
        $stmt2->execute(['today' => $today] + $filter['params']);
        $clickToday = (int)$stmt2->fetchColumn();

        $stmtTotalLogin = $pdo->prepare("SELECT COUNT(*) FROM user_login_logs l WHERE 1 = 1 {$filter['sql']}");
        $stmtTotalLogin->execute($filter['params']);
        $totalLogin = (int)$stmtTotalLogin->fetchColumn();
        $stmtTotalClick = $pdo->prepare("SELECT COUNT(*) FROM user_click_logs l WHERE 1 = 1 {$filter['sql']}");
        $stmtTotalClick->execute($filter['params']);
        $totalClick = (int)$stmtTotalClick->fetchColumn();

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
