<?php
/**
 * LPPAI Corner - Database Configuration
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'quic1934_lppai');
define('DB_USER', 'quic1934_zenhkm');
define('DB_PASS', '03Maret1990');
define('DB_CHARSET', 'utf8mb4');

// Base URL (sesuaikan dengan server Anda)
define('BASE_URL', 'https://lppai.quizb.my.id');
define('APP_NAME', 'LPPAI Corner');

function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            die("Koneksi database gagal: " . $e->getMessage());
        }
    }
    return $pdo;
}
