<?php
require __DIR__ . '/config/database.php';
$pdo = getDBConnection();

try {
    // 1. Fetch all existing tutors
    $stmt = $pdo->query("SELECT id, nama FROM tutors");
    $tutors = $stmt->fetchAll();
    
    $successCount = 0;
    $hash = password_hash('123456', PASSWORD_DEFAULT);
    
    $insertStmt = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nim, role) VALUES (?, ?, ?, ?, 'dosen') ON DUPLICATE KEY UPDATE role = 'dosen'");
    
    foreach ($tutors as $t) {
        $username = $t['id']; // Used as NIP/Username
        $nama = $t['nama'];
        
        // Ensure username is not empty
        if (empty($username)) {
            $username = 'tutor_' . uniqid();
        }
        
        $insertStmt->execute([$username, $hash, $nama, $username]);
        $successCount++;
    }
    echo "Successfully migrated $successCount tutors to users table.<br>";
    
    // 2. Drop the tutors table
    $pdo->exec("DROP TABLE IF EXISTS tutors");
    echo "Tutors table dropped successfully.<br>";
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "<br>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}

