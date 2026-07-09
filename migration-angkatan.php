<?php
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

try {
    // Check if column already exists
    $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'angkatan'");
    $stmt->execute();
    $columnExists = $stmt->fetch();

    if ($columnExists) {
        echo "<h3>Kolom 'angkatan' sudah ada di tabel users.</h3>";
        echo "<p>Anda tidak perlu menjalankan script ini lagi. Silakan hapus file ini demi keamanan.</p>";
    } else {
        $pdo->exec("ALTER TABLE users ADD COLUMN angkatan VARCHAR(10) NULL AFTER program_studi");
        echo "<h3>Berhasil! Kolom 'angkatan' telah ditambahkan ke tabel users.</h3>";
        echo "<p>Silakan hapus file script ini (migration-angkatan.php) dari server demi keamanan.</p>";
    }
} catch (Exception $e) {
    echo "<h3>Terjadi Kesalahan:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
