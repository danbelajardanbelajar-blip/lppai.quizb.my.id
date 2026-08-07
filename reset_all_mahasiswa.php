<?php
/**
 * Script Reset Password Massal Mahasiswa
 * Harap HAPUS file ini setelah digunakan demi keamanan.
 */
set_time_limit(0); // Mencegah timeout jika jumlah mahasiswa sangat banyak

require_once __DIR__ . '/includes/auth.php';

// Pastikan hanya admin yang bisa menjalankan ini (jika dijalankan via web)
if (PHP_SAPI !== 'cli') {
    requireAdmin();
}

$pdo = getDBConnection();

echo "<h2>Mulai proses reset password massal untuk role 'mahasiswa'...</h2>";
ob_flush(); flush();

$stmt = $pdo->query("SELECT id, nim, tanggal_lahir FROM users WHERE role = 'mahasiswa'");
$users = $stmt->fetchAll();

$total = count($users);
echo "<p>Ditemukan total $total mahasiswa.</p>";
ob_flush(); flush();

$success = 0;
$failed = 0;

$updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");

foreach ($users as $index => $user) {
    if (!empty($user['tanggal_lahir'])) {
        try {
            $dt = new DateTime($user['tanggal_lahir']);
            $passwordRaw = $dt->format('dmY');
        } catch (Exception $e) {
            $passwordRaw = '123456';
        }
    } else {
        $passwordRaw = '123456';
    }
    
    $newPass = password_hash($passwordRaw, PASSWORD_DEFAULT);
    
    try {
        $updateStmt->execute([$newPass, $user['id']]);
        $success++;
    } catch (PDOException $e) {
        $failed++;
        echo "Gagal reset ID {$user['id']} (NIM: {$user['nim']}): " . htmlspecialchars($e->getMessage()) . "<br>";
    }

    // Tampilkan progress setiap 100 data
    if (($index + 1) % 100 == 0) {
        echo "Diproses: " . ($index + 1) . " / $total...<br>";
        ob_flush(); flush();
    }
}

echo "<h3>PROSES SELESAI!</h3>";
echo "Berhasil di-reset: <strong>$success</strong> mahasiswa.<br>";
echo "Gagal: <strong>$failed</strong> mahasiswa.<br>";
echo "<p style='color:red;'><strong>PENTING:</strong> Segera hapus file <code>reset_all_mahasiswa.php</code> ini dari server Anda setelah selesai digunakan untuk alasan keamanan.</p>";
?>
