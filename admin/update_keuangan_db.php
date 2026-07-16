<?php
/**
 * Script to add 'bukti' column to 'keuangan_transaksi' table
 * You can run this online by accessing https://lppai.quizb.my.id/admin/update_keuangan_db.php
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();

try {
    // Add bukti column for file uploads
    $sql = "ALTER TABLE keuangan_transaksi ADD COLUMN IF NOT EXISTS bukti VARCHAR(255) NULL";
    $pdo->exec($sql);
    
    echo "<h1>Database Update Berhasil!</h1>";
    echo "<p>Kolom 'bukti' berhasil ditambahkan ke tabel 'keuangan_transaksi'.</p>";
    echo "<a href='keuangan.php'>Kembali ke Keuangan</a>";
} catch (PDOException $e) {
    echo "<h1>Error Database</h1>";
    echo "<p>Gagal menambahkan kolom: " . htmlspecialchars($e->getMessage()) . "</p>";
}
