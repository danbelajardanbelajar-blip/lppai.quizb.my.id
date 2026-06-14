<?php
require __DIR__ . '/config/database.php';
$pdo = getDBConnection();

try {
    $pdo->exec("ALTER TABLE tutorial_registrations 
                ADD COLUMN IF NOT EXISTS nilai_thaharah DECIMAL(5,2) DEFAULT NULL AFTER status,
                ADD COLUMN IF NOT EXISTS nilai_shalat DECIMAL(5,2) DEFAULT NULL AFTER nilai_thaharah,
                ADD COLUMN IF NOT EXISTS nilai_surat_pendek DECIMAL(5,2) DEFAULT NULL AFTER nilai_shalat,
                ADD COLUMN IF NOT EXISTS nilai_amaliyah DECIMAL(5,2) DEFAULT NULL AFTER nilai_surat_pendek,
                ADD COLUMN IF NOT EXISTS nilai_jenazah DECIMAL(5,2) DEFAULT NULL AFTER nilai_amaliyah,
                ADD COLUMN IF NOT EXISTS nilai_detail TEXT DEFAULT NULL AFTER nilai_akhir");
    
    // Drop mata_kuliah from tutorial_classes
    $pdo->exec("ALTER TABLE tutorial_classes DROP COLUMN IF EXISTS mata_kuliah");
    
    echo "Added detailed grade columns to tutorial_registrations successfully.<br>";
} catch (PDOException $e) {
    echo "Error adding columns: " . $e->getMessage() . "<br>";
}

