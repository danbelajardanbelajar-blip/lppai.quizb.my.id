<?php
require __DIR__ . '/config/database.php';
$pdo = getDBConnection();

try {
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('mahasiswa', 'admin', 'dosen') NOT NULL DEFAULT 'mahasiswa'");
    echo "users table altered successfully.\n";
} catch (PDOException $e) {
    echo "Error altering users table: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tutorial_class_id INT NOT NULL,
        user_id INT NOT NULL,
        pertemuan_ke INT NOT NULL,
        tanggal DATE NOT NULL,
        status_hadir ENUM('hadir', 'absen', 'izin', 'sakit') DEFAULT 'hadir',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tutorial_class_id) REFERENCES tutorial_classes(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_attendance (tutorial_class_id, user_id, pertemuan_ke)
    ) ENGINE=InnoDB");
    echo "tutorial_attendance table created successfully.\n";
} catch (PDOException $e) {
    echo "Error creating tutorial_attendance table: " . $e->getMessage() . "\n";
}
