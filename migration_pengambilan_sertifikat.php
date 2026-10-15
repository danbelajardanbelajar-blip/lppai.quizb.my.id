<?php
/**
 * LPPAI Corner - Migration for Pengambilan Sertifikat Table
 */
require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDBConnection();

    // 1. Buat tabel pengambilan_sertifikat
    $sql = "
    CREATE TABLE IF NOT EXISTS pengambilan_sertifikat (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tutorial_registration_id INT NULL,
        nim VARCHAR(50) NOT NULL,
        nama_mahasiswa VARCHAR(150) NOT NULL,
        program_studi VARCHAR(100) NULL,
        nomor_sertifikat VARCHAR(100) NOT NULL,
        tahun_ajaran VARCHAR(50) NULL,
        status_pengambilan ENUM('Sudah Diambil', 'Belum Diambil') NOT NULL DEFAULT 'Sudah Diambil',
        tanggal_pengambilan DATETIME NOT NULL,
        metode_pengambilan ENUM('Scan QR', 'Manual Admin', 'Barcode/Scanner') NOT NULL DEFAULT 'Scan QR',
        nama_penerima VARCHAR(150) NULL,
        hubungan_penerima VARCHAR(50) NULL DEFAULT 'Sendiri',
        foto_bukti VARCHAR(255) NULL,
        tanda_tangan_digital MEDIUMTEXT NULL,
        catatan TEXT NULL,
        petugas_id INT NULL,
        petugas_nama VARCHAR(150) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_nim (nim),
        INDEX idx_nomor_sertifikat (nomor_sertifikat),
        INDEX idx_tanggal_pengambilan (tanggal_pengambilan),
        INDEX idx_status (status_pengambilan)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);
    echo "Tabel 'pengambilan_sertifikat' berhasil dibuat atau sudah ada.\n";

    // 2. Tambahkan kolom status di tutorial_registrations jika belum ada
    try {
        $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN is_sertifikat_diambil TINYINT(1) DEFAULT 0 AFTER nomor_sertifikat");
        echo "Kolom 'is_sertifikat_diambil' berhasil ditambahkan ke 'tutorial_registrations'.\n";
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN tgl_sertifikat_diambil DATETIME NULL AFTER is_sertifikat_diambil");
        echo "Kolom 'tgl_sertifikat_diambil' berhasil ditambahkan ke 'tutorial_registrations'.\n";
    } catch (Exception $e) {}

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
