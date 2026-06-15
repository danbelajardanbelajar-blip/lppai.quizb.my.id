<?php
/**
 * Script untuk menghapus data nilai / registrasi tutorial dengan Tahun Ajaran 2025-2026
 */
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

try {
    $pdo->beginTransaction();
    
    // Menghapus data pendaftaran / nilai tutorial yang tahun ajarannya 2025-2026
    $stmt = $pdo->prepare("DELETE FROM tutorial_registrations WHERE tahun_ajaran = '2025-2026'");
    $stmt->execute();
    $count = $stmt->rowCount();
    
    $pdo->commit();
    echo "<div style='font-family: sans-serif; padding: 40px;'>";
    echo "<h1 style='color: green;'>Penghapusan Berhasil! ✅</h1>";
    echo "<p>Berhasil menghapus <strong>$count</strong> baris data nilai/registrasi untuk Tahun Ajaran 2025-2026.</p>";
    echo "<a href='admin/kelola-nilai.php' style='display:inline-block; padding:10px 20px; background:#3b82f6; color:#fff; text-decoration:none; border-radius:5px;'>Kembali ke Kelola Nilai</a>";
    echo "</div>";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<div style='font-family: sans-serif; padding: 40px; color: red;'>";
    echo "<h1>Gagal</h1>";
    echo "<p>Terjadi kesalahan: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
