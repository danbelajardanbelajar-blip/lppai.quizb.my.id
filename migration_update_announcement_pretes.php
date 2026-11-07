<?php
/**
 * LPPAI Corner - Migration: Perbarui Pengumuman Hasil Pretes 2026/2027
 * Memperbarui redaksi pengumuman hasil pretes menjadi kalimat yang elegan, tidak berulang,
 * bebas typo, dilengkapi emoticon, dan mengarahkan link aksi ke pretes-hasil.php.
 */

require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDBConnection();

    // 1. Pastikan tabel announcements dan kolomnya menggunakan utf8mb4 (dukungan emoji 4-byte)
    try {
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->exec("ALTER TABLE announcements CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("ALTER TABLE announcements MODIFY COLUMN judul VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("ALTER TABLE announcements MODIFY COLUMN konten TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "Tabel 'announcements' berhasil dikonversi ke utf8mb4.\n";
    } catch (Exception $e) {
        echo "Catatan konversi charset: " . $e->getMessage() . "\n";
    }

    $cleanJudul = '📢 Pengumuman Hasil Kelulusan Pretes 2026/2027';
    $cleanKonten = "Berikut kami sampaikan Pengumuman Hasil Pretes 2026/2027:\n\n"
        . "🎉 BAGI MAHASISWA YANG DINYATAKAN LULUS PRETES:\n"
        . "Selamat atas kelulusan Anda! Harap menyelesaikan tahapan administrasi dan pengambilan sertifikat berikut:\n"
        . "1. 🕌 Mengikuti kegiatan rutin Majlis Dzikir Al Khidmah di Masjid KH. Soefyan AW setiap awal bulan.\n"
        . "2. 📱 Melakukan presensi online (kehadiran & kepulangan) melalui sistem serta mengunggah swafoto (selfie) yang jelas dan rapi.\n"
        . "3. 💳 Menyelesaikan pembayaran biaya administrasi kelulusan pretes sebesar Rp 50.000,-.\n"
        . "4. 🎓 Mengambil Sertifikat Kelulusan resmi di Kantor LPPAI (Lantai 2 Gedung Fakultas Hukum, sebelah timur kantin) dengan membawa Nota Pembayaran yang sah dan pasfoto 3×4.\n\n"
        . "⚠️ BAGI MAHASISWA YANG BELUM LULUS PRETES:\n"
        . "Bagi mahasiswa yang belum memenuhi standar kelulusan pretes, Anda diwajibkan untuk mendaftarkan diri pada program Tutorial LPPAI Gelombang 1 melalui link di bawah ini.";
    $cleanLink = '/pages/pretes-hasil.php';

    // Cek apakah pengumuman pretes sudah ada
    $stmt = $pdo->prepare("SELECT id FROM announcements WHERE tipe = 'hasil_pretes' OR judul LIKE '%Hasil Pretes%' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $existing = $stmt->fetch();

    if ($existing) {
        $updateStmt = $pdo->prepare("
            UPDATE announcements 
            SET judul = ?, konten = ?, tipe = 'hasil_pretes', link_tujuan = ? 
            WHERE id = ?
        ");
        $updateStmt->execute([$cleanJudul, $cleanKonten, $cleanLink, $existing['id']]);
        echo "Berhasil memperbarui pengumuman pretes (ID: {$existing['id']}).\n";
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO announcements (judul, konten, tipe, link_tujuan, is_active, created_at) 
            VALUES (?, ?, 'hasil_pretes', ?, 1, NOW())
        ");
        $insertStmt->execute([$cleanJudul, $cleanKonten, $cleanLink]);
        echo "Berhasil menambahkan pengumuman pretes baru.\n";
    }

} catch (Exception $e) {
    echo "Gagal migrasi pengumuman: " . $e->getMessage() . "\n";
}
