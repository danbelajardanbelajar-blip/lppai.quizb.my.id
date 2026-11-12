<?php
/**
 * Migrasi database untuk menambahkan kolom `jenis_program` (`RPL` / `Reguler`) pada tabel `users`.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/user-helper.php';

try {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'jenis_program'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN jenis_program ENUM('RPL', 'Reguler') NOT NULL DEFAULT 'Reguler' AFTER program_studi");
        echo "Kolom 'jenis_program' (ENUM 'RPL', 'Reguler') berhasil ditambahkan ke tabel users.\n";
    } else {
        echo "Kolom 'jenis_program' sudah ada di tabel users.\n";
    }
    $pdo->exec("UPDATE users SET jenis_program = 'Reguler' WHERE jenis_program IS NULL OR jenis_program NOT IN ('RPL', 'Reguler')");
    echo "Migrasi jenis_program selesai.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
