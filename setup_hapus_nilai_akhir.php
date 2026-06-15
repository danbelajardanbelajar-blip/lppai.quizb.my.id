<?php
require_once __DIR__ . '/config/database.php';

echo "<h1>Setup Database: Hapus Kolom Nilai Akhir</h1>";

try {
    $pdo = getDBConnection();
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM tutorial_registrations LIKE 'nilai_akhir'");
    $exists = $stmt->fetch();
    
    if ($exists) {
        $sql = "ALTER TABLE tutorial_registrations DROP COLUMN nilai_akhir;";
        $pdo->exec($sql);
        echo "<p style='color: green;'>Kolom `nilai_akhir` berhasil dihapus dari tabel `tutorial_registrations`.</p>";
    } else {
        echo "<p style='color: orange;'>Kolom `nilai_akhir` sudah tidak ada di tabel `tutorial_registrations`.</p>";
    }
    
    echo "<p><a href='" . BASE_URL . "'>Kembali ke Beranda</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Gagal menghapus kolom: " . $e->getMessage() . "</p>";
}
