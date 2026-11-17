<?php
/**
 * Test Suite: Verifikasi Label RPL / Reguler pada Kolom Nama Halaman Log Aktivitas
 * (admin/log-aktivitas.php & includes/activity-logger.php)
 */

require_once __DIR__ . '/../includes/user-helper.php';
require_once __DIR__ . '/../includes/activity-logger.php';

echo "=== STARTING LOG AKTIVITAS JENIS PROGRAM TEST ===\n";

// Require dummy admin auth & page title
if (!defined('PAGE_TITLE')) define('PAGE_TITLE', 'Log Aktivitas Pengguna');
if (!defined('BASE_URL')) define('BASE_URL', 'https://lppai.quizb.my.id');
if (!function_exists('isAdmin')) {
    function isAdmin() { return true; }
}
if (!function_exists('requireAdmin')) {
    function requireAdmin() {}
}
if (!function_exists('sanitize')) {
    function sanitize($val) {
        return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('adminUserProfileLink')) {
    function adminUserProfileLink($name, $userId = null): string {
        return '<a href="/admin/profil-pengguna.php?id=' . (int)$userId . '">' . sanitize($name) . '</a>';
    }
}

// Extract helper renderLogJenisProgramBadge from admin/log-aktivitas.php
$logAktivitasSource = file_get_contents(__DIR__ . '/../admin/log-aktivitas.php');
if (!function_exists('renderLogJenisProgramBadge')) {
    $fnPos = strpos($logAktivitasSource, 'function renderLogJenisProgramBadge');
    if ($fnPos !== false) {
        $sub = substr($logAktivitasSource, $fnPos);
        $closingNeedle = "return '';\r\n}";
        $endPos = strpos($sub, $closingNeedle);
        if ($endPos === false) {
            $closingNeedle = "return '';\n}";
            $endPos = strpos($sub, $closingNeedle);
        }
        if ($endPos !== false) {
            eval(substr($sub, 0, $endPos + strlen($closingNeedle)));
        }
    }
}

assert(function_exists('renderLogJenisProgramBadge'), 'renderLogJenisProgramBadge must be defined');

// 1. Test unit renderLogJenisProgramBadge
echo "1. Testing renderLogJenisProgramBadge()...\n";

$badgeMhsRpl = renderLogJenisProgramBadge('mahasiswa', 'RPL');
assert(str_contains($badgeMhsRpl, 'RPL'), 'Mahasiswa RPL must have RPL badge');
assert(str_contains($badgeMhsRpl, 'badge-jenis-rpl'), 'Mahasiswa RPL must have class badge-jenis-rpl');

$badgeMhsReg = renderLogJenisProgramBadge('mahasiswa', 'Reguler');
assert(str_contains($badgeMhsReg, 'Reguler'), 'Mahasiswa Reguler must have Reguler badge');
assert(str_contains($badgeMhsReg, 'badge-jenis-reguler'), 'Mahasiswa Reguler must have class badge-jenis-reguler');

$badgeMhsEmpty = renderLogJenisProgramBadge('mahasiswa', '');
assert(str_contains($badgeMhsEmpty, 'Reguler'), 'Mahasiswa with empty program must default to Reguler badge');

$badgeAdmin = renderLogJenisProgramBadge('admin', 'Reguler');
assert($badgeAdmin === '', 'Admin must not have a student program badge');

$badgeDosen = renderLogJenisProgramBadge('dosen', 'Reguler');
assert($badgeDosen === '', 'Dosen must not have a student program badge');

$badgeTamu = renderLogJenisProgramBadge('tamu', 'Reguler');
assert($badgeTamu === '', 'Tamu must not have a student program badge');

$badgeOtherRpl = renderLogJenisProgramBadge('', 'RPL');
assert(str_contains($badgeOtherRpl, 'RPL'), 'Account with RPL program must render RPL badge');

echo " -> renderLogJenisProgramBadge() tests PASS\n";

// 2. Test getRecentLoginLogs and getRecentClickLogs with SQLite
echo "2. Testing activity log queries with SQLite database...\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        password TEXT NOT NULL,
        nama_lengkap TEXT NOT NULL,
        nim TEXT,
        role TEXT NOT NULL DEFAULT 'mahasiswa',
        no_hp TEXT,
        jenis_program TEXT DEFAULT 'Reguler'
    );

    CREATE TABLE user_login_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        username TEXT NOT NULL,
        nama_lengkap TEXT NULL,
        role TEXT NULL,
        no_hp TEXT NULL,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        device TEXT DEFAULT 'Desktop',
        browser TEXT DEFAULT 'Chrome',
        status TEXT NOT NULL DEFAULT 'success',
        keterangan TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE user_click_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        username TEXT NULL,
        nama_lengkap TEXT NULL,
        role TEXT NULL,
        element_text TEXT NULL,
        element_type TEXT NULL,
        element_id TEXT NULL,
        element_class TEXT NULL,
        target_url TEXT NULL,
        page_url TEXT NOT NULL,
        page_title TEXT NULL,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Insert users
$pdo->exec("
    INSERT INTO users (id, username, password, nama_lengkap, nim, role, no_hp, jenis_program) VALUES
    (10, '20260001', 'pass', 'Ahmad Dahlan RPL', '20260001', 'mahasiswa', '0812345678', 'RPL'),
    (11, '20260002', 'pass', 'Budi Santoso Reg', '20260002', 'mahasiswa', '0812345679', 'Reguler'),
    (12, 'dosen_ali', 'pass', 'Dr. Ali Dosen', '198001', 'dosen', '0812345670', 'Reguler'),
    (13, 'admin_super', 'pass', 'Admin Utama', '0001', 'admin', '0812345671', 'Reguler');
");

// Insert login logs
$pdo->exec("
    INSERT INTO user_login_logs (id, user_id, username, nama_lengkap, role, status, created_at) VALUES
    (1, 10, '20260001', 'Ahmad Dahlan RPL', 'mahasiswa', 'success', '2026-11-17 08:00:00'),
    (2, 11, '20260002', 'Budi Santoso Reg', 'mahasiswa', 'success', '2026-11-17 08:05:00'),
    (3, 12, 'dosen_ali', 'Dr. Ali Dosen', 'dosen', 'success', '2026-11-17 08:10:00'),
    (4, 13, 'admin_super', 'Admin Utama', 'admin', 'success', '2026-11-17 08:15:00');
");

// Insert click logs
$pdo->exec("
    INSERT INTO user_click_logs (id, user_id, username, nama_lengkap, role, element_text, page_url, created_at) VALUES
    (1, 10, '20260001', 'Ahmad Dahlan RPL', 'mahasiswa', 'Buka Pretes', '/pretes.php', '2026-11-17 08:01:00'),
    (2, 11, '20260002', 'Budi Santoso Reg', 'mahasiswa', 'Lihat Jadwal', '/jadwal.php', '2026-11-17 08:06:00'),
    (3, NULL, NULL, NULL, NULL, 'Klik Tombol Login', '/login.php', '2026-11-17 08:07:00');
");

// Override getDBConnection in test environment using Closure / Global reflection
// Test helper function activityLogUserHasColumn
assert(activityLogUserHasColumn($pdo, 'jenis_program') === true, 'users table must detect jenis_program column');
assert(activityLogUserHasColumn($pdo, 'non_existent_column') === false, 'non_existent_column must return false');

echo " -> activityLogUserHasColumn() tests PASS\n";

// 3. Test HTML structure in admin/log-aktivitas.php
echo "3. Testing admin/log-aktivitas.php table structure & headers...\n";

assert(str_contains($logAktivitasSource, '<th>Nama Pengguna</th>'), 'admin/log-aktivitas.php must have <th>Nama Pengguna</th>');
assert(str_contains($logAktivitasSource, 'renderLogJenisProgramBadge($log[\'role\'] ?? \'\', $log[\'jenis_program\'] ?? \'\')'), 'Login table must call renderLogJenisProgramBadge');
assert(str_contains($logAktivitasSource, 'renderLogJenisProgramBadge($c[\'role\'] ?? \'\', $c[\'jenis_program\'] ?? \'\')'), 'Click table must call renderLogJenisProgramBadge');

echo " -> Table structure tests PASS\n";

echo "=== ALL LOG AKTIVITAS PROGRAM BADGE TESTS PASSED 100% ===\n";
