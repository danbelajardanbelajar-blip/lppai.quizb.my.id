<?php
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

$sql = "
CREATE TABLE IF NOT EXISTS jaminan_mahasiswa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nim VARCHAR(50) NOT NULL,
    jaminan_atas ENUM('Validasi', 'Al Khidmah', 'Sertifikat') NOT NULL,
    jenis_jaminan ENUM('KTP', 'SIM', 'STNK', 'BPKB') NOT NULL,
    foto_jaminan VARCHAR(255) NULL,
    status_pengambilan TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
$pdo->exec($sql);
echo 'Table jaminan_mahasiswa created successfully.';
