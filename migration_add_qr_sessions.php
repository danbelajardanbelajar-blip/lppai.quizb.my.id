<?php
require_once __DIR__ . '/includes/auth.php';

try {
    $pdo = getDBConnection();
    
    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'tutorial_qr_sessions'");
    if ($stmt->rowCount() > 0) {
        echo "Tabel 'tutorial_qr_sessions' sudah ada.<br>";
    } else {
        $sql = "CREATE TABLE tutorial_qr_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tutorial_class_id INT NOT NULL,
            pertemuan_ke INT NOT NULL,
            token VARCHAR(100) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY (token),
            KEY (tutorial_class_id, pertemuan_ke)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        $pdo->exec($sql);
        echo "Tabel 'tutorial_qr_sessions' berhasil dibuat.<br>";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
?>
