<?php
/**
 * Script untuk mengupdate Tahun Ajaran dari 2025-2026 ke 2026-2027
 */
require_once __DIR__ . '/config/database.php';

echo "<h1>Setup Database: Update Tahun Ajaran</h1>";

try {
    $pdo = getDBConnection();
    
    // Mengeksekusi query update
    $stmt = $pdo->prepare("UPDATE tutorial_registrations SET tahun_ajaran = '2026-2027' WHERE tahun_ajaran = '2025-2026'");
    $stmt->execute();
    
    $affectedRows = $stmt->rowCount();
    
    echo "<p style='color: green;'>Berhasil mengupdate tahun ajaran!</p>";
    echo "<p>Total data mahasiswa yang diupdate: <strong>" . $affectedRows . "</strong> baris.</p>";
    echo "<p><a href='" . BASE_URL . "'>Kembali ke Beranda</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Gagal mengupdate tahun ajaran: " . $e->getMessage() . "</p>";
}
