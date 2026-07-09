<?php
/**
 * Script untuk update massal kolom 'angkatan' berdasarkan NIM
 * Cara kerja: Ambil 2 digit awal NIM, lalu tambahkan "20" di depannya (contoh: 24010006 -> 2024).
 */
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

try {
    // Ambil semua user (khusus mahasiswa atau semua user yang punya NIM angka)
    $stmt = $pdo->query("SELECT id, nim FROM users WHERE nim IS NOT NULL AND nim != '' AND role = 'mahasiswa'");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    $skipped = 0;

    foreach ($users as $u) {
        $nim = trim($u['nim']);
        
        // Cek 2 digit pertama
        $prefix = substr($nim, 0, 2);
        
        if (is_numeric($prefix) && strlen($prefix) == 2) {
            $angkatan = '20' . $prefix;
            
            // Update angkatan ke DB
            $updateStmt = $pdo->prepare("UPDATE users SET angkatan = ? WHERE id = ?");
            $updateStmt->execute([$angkatan, $u['id']]);
            $updated++;
        } else {
            $skipped++;
        }
    }

    echo "<h3>Proses Generate Angkatan Selesai!</h3>";
    echo "<p>Total Data Diperbarui: <strong>$updated</strong> pengguna</p>";
    if ($skipped > 0) {
        echo "<p>Data Dilewati (Format NIM tidak sesuai): <strong>$skipped</strong> pengguna</p>";
    }
    
    echo "<br><p style='color:red;'>Silakan hapus file <b>update-angkatan.php</b> ini dari server setelah selesai digunakan demi keamanan.</p>";

} catch (Exception $e) {
    echo "<h3>Terjadi Kesalahan:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
