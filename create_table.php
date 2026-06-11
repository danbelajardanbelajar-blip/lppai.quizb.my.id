<?php
require __DIR__ . '/includes/auth.php';
$pdo = getDBConnection();
$pdo->exec("CREATE TABLE IF NOT EXISTS master_gelombang (
    id INT AUTO_INCREMENT PRIMARY KEY,
    semester VARCHAR(50) NOT NULL,
    tahun_ajaran VARCHAR(50) NOT NULL,
    gelombang ENUM('gel1','gel2','mandiri') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
echo "Success";
