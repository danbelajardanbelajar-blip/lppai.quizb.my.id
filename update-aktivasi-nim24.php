<?php
/**
 * Script untuk aktivasi massal akun mahasiswa ber-NIM awalan 24 (Angkatan 2024)
 */
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

try {
    $stmt = $pdo->prepare("UPDATE users SET is_active = 1 WHERE role = 'mahasiswa' AND (nim LIKE '24%' OR angkatan = '2024')");
    $stmt->execute();
    $updated = $stmt->rowCount();

    echo "<h3>Proses Aktivasi Akun NIM 24 Selesai!</h3>";
    echo "<p>Total Akun Diperbarui: <strong>$updated</strong> mahasiswa telah diatur menjadi aktif.</p>";
    echo "<br><p style='color:green;'>Mahasiswa dengan NIM awalan 24 sekarang dapat langsung login ke sistem tanpa aktivasi WhatsApp.</p>";
    echo "<br><p style='color:red;'>Silakan hapus file <b>update-aktivasi-nim24.php</b> ini dari server setelah selesai digunakan demi keamanan.</p>";
} catch (Exception $e) {
    echo "<h3>Terjadi Kesalahan:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
