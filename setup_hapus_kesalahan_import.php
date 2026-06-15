<?php
/**
 * Script untuk menghapus data user mahasiswa yang salah import (nama: Mahasiswa {NIM})
 */
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

try {
    $pdo->beginTransaction();
    // Menghapus users yang namanya berawalan "Mahasiswa " dan di-create hari ini.
    // Karena ON DELETE CASCADE di database, ini akan otomatis menghapus nilainya juga di tutorial_registrations
    $stmt = $pdo->prepare("DELETE FROM users WHERE role = 'mahasiswa' AND nama_lengkap LIKE 'Mahasiswa %' AND DATE(created_at) = CURDATE()");
    $stmt->execute();
    $count = $stmt->rowCount();
    $pdo->commit();
    echo "<div style='font-family: sans-serif; padding: 40px;'>";
    echo "<h1 style='color: green;'>Pembersihan Berhasil! ✅</h1>";
    echo "<p>Berhasil menghapus <strong>$count</strong> data mahasiswa yang bermasalah akibat kesalahan import hari ini beserta nilainya.</p>";
    echo "<p>Bugs pada import Excel juga sudah diperbaiki, Anda dapat mengulang proses Import Nilai sekarang.</p>";
    echo "<a href='admin/kelola-nilai.php' style='display:inline-block; padding:10px 20px; background:#3b82f6; color:#fff; text-decoration:none; border-radius:5px;'>Kembali ke Kelola Nilai</a>";
    echo "</div>";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<div style='font-family: sans-serif; padding: 40px; color: red;'>";
    echo "<h1>Gagal</h1>";
    echo "<p>Terjadi kesalahan: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
