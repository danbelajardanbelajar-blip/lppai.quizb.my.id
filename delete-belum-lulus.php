<?php
/**
 * Script untuk menghapus user (NIM) yang statusnya belum lulus / tidak lulus / belum lengkap
 * di halaman Kelola Nilai
 */
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

try {
    // Logika Kelulusan sesuai dengan admin/ajax-kelola-nilai.php
    $isCompleteSQL = "(tr.nilai_thaharah IS NOT NULL AND tr.nilai_shalat IS NOT NULL AND tr.nilai_surat_pendek IS NOT NULL AND tr.nilai_amaliyah IS NOT NULL AND tr.nilai_jenazah IS NOT NULL AND tr.nilai_ujian_tulis IS NOT NULL)";
    $minScoreSQL = "(CASE WHEN LOWER(tr.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)";
    $isLulusSQL = "($isCompleteSQL AND tr.nilai_thaharah >= $minScoreSQL AND tr.nilai_shalat >= $minScoreSQL AND tr.nilai_surat_pendek >= $minScoreSQL AND tr.nilai_amaliyah >= $minScoreSQL AND tr.nilai_jenazah >= $minScoreSQL AND tr.nilai_ujian_tulis >= $minScoreSQL)";

    $query = "
        SELECT u.id, u.nim, u.nama_lengkap 
        FROM users u
        JOIN tutorial_registrations tr ON tr.id = (
            SELECT MAX(id) FROM tutorial_registrations WHERE user_id = u.id
        )
        WHERE u.role = 'mahasiswa' 
        AND CAST(SUBSTRING(u.nim, 1, 2) AS UNSIGNED) <= 25
        AND NOT $isLulusSQL
    ";

    $stmt = $pdo->query($query);
    $usersToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
        $pdo->beginTransaction();
        $deletedCount = 0;
        
        if (count($usersToDelete) > 0) {
            $userIds = array_column($usersToDelete, 'id');
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));

            // Delete dari registrasi terlebih dahulu (mencegah constraint error)
            $pdo->prepare("DELETE FROM tutorial_registrations WHERE user_id IN ($placeholders)")->execute($userIds);
            
            // Delete dari tabel users utama
            $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)")->execute($userIds);
            $deletedCount = count($userIds);
        }
        $pdo->commit();

        echo "<h3 style='color:green;'>Proses Hapus Data Selesai!</h3>";
        echo "<p>Berhasil menghapus <strong>$deletedCount</strong> akun mahasiswa yang belum lulus.</p>";
        echo "<br><p style='color:red;'>Silakan hapus file <b>delete-belum-lulus.php</b> ini dari server setelah selesai digunakan demi keamanan.</p>";
        echo "<br><a href='/admin/kelola-nilai.php'>Kembali ke Halaman Kelola Nilai</a>";
    } else {
        echo "<div style='font-family: sans-serif; padding: 20px;'>";
        echo "<h3>Preview Data yang Akan Dihapus</h3>";
        echo "<p>Ditemukan <strong>" . count($usersToDelete) . "</strong> mahasiswa yang statusnya Belum Lulus / Tidak Lulus / Belum Lengkap pada halaman Kelola Nilai.</p>";
        
        if (count($usersToDelete) > 0) {
            echo "<table border='1' cellpadding='8' style='border-collapse: collapse; margin-bottom: 20px; width: 100%; max-width: 600px;'>
                    <tr style='background: #f4f4f4;'>
                        <th style='text-align: left;'>NIM</th>
                        <th style='text-align: left;'>Nama Lengkap</th>
                    </tr>";
            foreach ($usersToDelete as $u) {
                echo "<tr>
                        <td>" . htmlspecialchars($u['nim']) . "</td>
                        <td>" . htmlspecialchars($u['nama_lengkap']) . "</td>
                      </tr>";
            }
            echo "</table>";
            echo "<a href='?confirm=yes' style='background: #dc3545; color: white; padding: 12px 24px; text-decoration: none; font-weight: bold; border-radius: 6px; display: inline-block;'>🗑️ HAPUS SEMUA DATA DI ATAS</a>";
        } else {
            echo "<p style='color: green;'>Tidak ada data mahasiswa yang belum lulus (semua sudah lulus!).</p>";
        }
        echo "</div>";
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<h3>Terjadi Kesalahan:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
