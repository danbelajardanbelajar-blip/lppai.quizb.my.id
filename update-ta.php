<?php
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();
$stmt = $pdo->query("UPDATE tutorial_registrations SET tahun_ajaran = '2025-2026' WHERE tahun_ajaran IS NULL OR tahun_ajaran = ''");
$updatedCount = $stmt->rowCount();

$stmtInsert = $pdo->query("
    INSERT INTO tutorial_registrations (user_id, status, gelombang, tahun_ajaran)
    SELECT id, 'lulus', 'lawas', '2025-2026' 
    FROM users 
    WHERE role = 'mahasiswa' 
    AND id NOT IN (SELECT user_id FROM tutorial_registrations)
");
$insertedCount = $stmtInsert->rowCount();

echo "<h3>Berhasil!</h3>";
echo "Updated " . $updatedCount . " baris yang sudah ada.<br>";
echo "Menambahkan " . $insertedCount . " rekam jejak baru untuk mahasiswa yang sebelumnya belum memiliki riwayat nilai/registrasi.<br>";
echo "Total seluruh mahasiswa sekarang sudah memiliki Tahun Ajaran 2025-2026.";

