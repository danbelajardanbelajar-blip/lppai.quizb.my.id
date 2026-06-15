<?php
require_once __DIR__ . '/config/database.php';

echo "<h1>Setup Database Session Table</h1>";

try {
    $pdo = getDBConnection();
    
    $sql = "
    CREATE TABLE IF NOT EXISTS sessions (
        id VARCHAR(128) NOT NULL PRIMARY KEY,
        data TEXT NOT NULL,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
    ";
    
    $pdo->exec($sql);
    
    echo "<p style='color: green;'>Tabel `sessions` berhasil dibuat atau sudah ada.</p>";
    echo "<p><a href='" . BASE_URL . "'>Kembali ke Beranda</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Gagal membuat tabel: " . $e->getMessage() . "</p>";
}
