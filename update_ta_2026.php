<?php
require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDBConnection();
    
    // Update tahun ajaran yang kosong menjadi 2026-2027
    $sql = "UPDATE tutorial_registrations 
            SET tahun_ajaran = '2026-2027' 
            WHERE tahun_ajaran IS NULL 
               OR trim(tahun_ajaran) = ''";
               
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    
    $updatedCount = $stmt->rowCount();

    echo "<h3>Eksekusi Berhasil!</h3>";
    echo "Sebanyak " . $updatedCount . " data tahun ajaran mahasiswa yang kosong telah diubah menjadi 2026-2027.<br>";

} catch (PDOException $e) {
    echo "<h3>Error!</h3>";
    echo "Terjadi kesalahan pada database: " . $e->getMessage();
}
?>
