<?php
/**
 * Script untuk menghapus SELURUH data mahasiswa beserta nilainya dari database
 */
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

try {
    $pdo->beginTransaction();
    
    // Menghapus semua users dengan role 'mahasiswa'. 
    // Karena adanya sistem CASCADE di database, ini akan otomatis menghapus semua nilainya di tabel tutorial_registrations
    $stmt = $pdo->prepare("DELETE FROM users WHERE role = 'mahasiswa'");
    $stmt->execute();
    $count = $stmt->rowCount();
    
    $pdo->commit();
    echo "<div style='font-family: sans-serif; padding: 40px;'>";
    echo "<h1 style='color: green;'>Pembersihan Total Berhasil! ✅</h1>";
    echo "<p>Berhasil menghapus <strong>$count</strong> akun mahasiswa secara keseluruhan beserta data nilainya.</p>";
    echo "<a href='admin/kelola-nilai.php' style='display:inline-block; padding:10px 20px; background:#3b82f6; color:#fff; text-decoration:none; border-radius:5px;'>Kembali ke Kelola Nilai</a>";
    echo "</div>";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<div style='font-family: sans-serif; padding: 40px; color: red;'>";
    echo "<h1>Gagal</h1>";
    echo "<p>Terjadi kesalahan: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
