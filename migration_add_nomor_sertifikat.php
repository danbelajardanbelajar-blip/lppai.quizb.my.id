<?php
require_once __DIR__ . '/includes/auth.php';

try {
    $pdo = getDBConnection();
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM tutorial_registrations LIKE 'nomor_sertifikat'");
    if ($stmt->rowCount() == 0) {
        // Add column
        $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN nomor_sertifikat VARCHAR(255) NULL DEFAULT NULL AFTER nilai_ujian_tulis");
        echo "Kolom 'nomor_sertifikat' berhasil ditambahkan ke tabel 'tutorial_registrations'.\n";
    } else {
        echo "Kolom 'nomor_sertifikat' sudah ada di tabel 'tutorial_registrations'.\n";
    }

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
