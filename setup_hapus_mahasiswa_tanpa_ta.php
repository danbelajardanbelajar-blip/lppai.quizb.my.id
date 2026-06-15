<?php
/**
 * Script untuk menghapus mahasiswa yang tidak memiliki data registrasi / nilai (tidak ada tahun ajaran)
 */
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

try {
    $pdo->beginTransaction();
    
    // Menghapus users (mahasiswa) yang tidak memiliki relasi di tabel tutorial_registrations
    $stmt = $pdo->prepare("
        DELETE u 
        FROM users u 
        LEFT JOIN tutorial_registrations tr ON u.id = tr.user_id 
        WHERE u.role = 'mahasiswa' AND tr.id IS NULL
    ");
    $stmt->execute();
    $count = $stmt->rowCount();
    
    $pdo->commit();
    echo "<div style='font-family: sans-serif; padding: 40px;'>";
    echo "<h1 style='color: green;'>Penghapusan Mahasiswa Berhasil! ✅</h1>";
    echo "<p>Berhasil menghapus <strong>$count</strong> akun mahasiswa yang tidak memiliki nilai/tahun ajaran di sistem.</p>";
    echo "<a href='admin/kelola-nilai.php' style='display:inline-block; padding:10px 20px; background:#3b82f6; color:#fff; text-decoration:none; border-radius:5px;'>Kembali ke Kelola Nilai</a>";
    echo "</div>";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<div style='font-family: sans-serif; padding: 40px; color: red;'>";
    echo "<h1>Gagal</h1>";
    echo "<p>Terjadi kesalahan: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
