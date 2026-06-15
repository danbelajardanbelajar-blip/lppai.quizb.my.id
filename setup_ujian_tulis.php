<?php
require_once __DIR__ . '/config/database.php';

echo "<h1>Setup Database: Tambah Kolom Ujian Tulis</h1>";

try {
    $pdo = getDBConnection();
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM tutorial_registrations LIKE 'nilai_ujian_tulis'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        $sql = "ALTER TABLE tutorial_registrations ADD COLUMN nilai_ujian_tulis DECIMAL(5,2) DEFAULT NULL AFTER nilai_jenazah;";
        $pdo->exec($sql);
        echo "<p style='color: green;'>Kolom `nilai_ujian_tulis` berhasil ditambahkan ke tabel `tutorial_registrations`.</p>";
    } else {
        echo "<p style='color: orange;'>Kolom `nilai_ujian_tulis` sudah ada di tabel `tutorial_registrations`.</p>";
    }
    
    echo "<p><a href='" . BASE_URL . "'>Kembali ke Beranda</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Gagal menambahkan kolom: " . $e->getMessage() . "</p>";
}
