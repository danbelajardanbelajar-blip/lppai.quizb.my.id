<?php
require_once __DIR__ . '/config/database.php';

echo "<h1>Setup Database: Tambah Kolom Tempat Lahir</h1>";

try {
    $pdo = getDBConnection();
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'tempat_lahir'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        $sql = "ALTER TABLE users ADD COLUMN tempat_lahir VARCHAR(100) DEFAULT NULL AFTER fakultas;";
        $pdo->exec($sql);
        echo "<p style='color: green;'>Kolom `tempat_lahir` berhasil ditambahkan ke tabel `users`.</p>";
    } else {
        echo "<p style='color: orange;'>Kolom `tempat_lahir` sudah ada di tabel `users`.</p>";
    }
    
    echo "<p><a href='" . BASE_URL . "'>Kembali ke Beranda</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Gagal menambahkan kolom: " . $e->getMessage() . "</p>";
}
