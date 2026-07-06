<?php
/**
 * Script cepat untuk menghapus kolom tahun_ajaran di tabel users
 */
require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDBConnection();
    $pdo->exec("ALTER TABLE users DROP COLUMN tahun_ajaran");
    echo "<h1>✅ Sukses!</h1>";
    echo "<p>Kolom <strong>tahun_ajaran</strong> telah berhasil dihapus dari tabel <strong>users</strong>.</p>";
    echo "<p style='color: red;'>⚠️ <strong>Sangat Penting:</strong> Segera hapus file ini (<code>drop_tahun_ajaran.php</code>) dari server Anda setelah digunakan agar tidak disalahgunakan.</p>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "Can't DROP") !== false || strpos($e->getMessage(), "check that column/key exists") !== false) {
        echo "<h1>⚠️ Info</h1>";
        echo "<p>Kolom <strong>tahun_ajaran</strong> sepertinya sudah tidak ada atau sudah pernah dihapus sebelumnya.</p>";
    } else {
        echo "<h1>❌ Gagal!</h1>";
        echo "<p>Terjadi kesalahan database: " . $e->getMessage() . "</p>";
    }
}
