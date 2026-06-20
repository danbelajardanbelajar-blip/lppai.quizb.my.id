<?php
/**
 * Script untuk menambahkan kolom tahun_ajaran ke tabel users
 */
require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDBConnection();
    
    // Cek apakah kolom sudah ada
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'tahun_ajaran'");
    if ($stmt->fetch()) {
        echo "Kolom 'tahun_ajaran' sudah ada di tabel 'users'.<br>";
    } else {
        $pdo->exec("ALTER TABLE users ADD COLUMN tahun_ajaran VARCHAR(50) NULL");
        echo "Berhasil menambahkan kolom 'tahun_ajaran' ke tabel 'users'.<br>";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
