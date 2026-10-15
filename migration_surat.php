<?php
/**
 * LPPAI Corner - Migration for Inventaris Surat Table
 */
require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDBConnection();

    $sql = "
    CREATE TABLE IF NOT EXISTS surat_inventaris (
        id INT AUTO_INCREMENT PRIMARY KEY,
        jenis_surat ENUM('masuk', 'keluar') NOT NULL DEFAULT 'masuk',
        nomor_surat VARCHAR(100) NOT NULL,
        nomor_agenda VARCHAR(50) NULL,
        tanggal_surat DATE NOT NULL,
        tanggal_terima_kirim DATE NOT NULL,
        pengirim VARCHAR(150) NULL,
        penerima VARCHAR(150) NULL,
        perihal VARCHAR(255) NOT NULL,
        kategori VARCHAR(80) NOT NULL DEFAULT 'Umum',
        sifat VARCHAR(30) NOT NULL DEFAULT 'Biasa',
        lokasi_arsip VARCHAR(100) NULL,
        disposisi_kepada VARCHAR(150) NULL,
        disposisi_instruksi TEXT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'Arsip',
        keterangan TEXT NULL,
        file_surat VARCHAR(255) NULL,
        file_nama_asli VARCHAR(255) NULL,
        file_ukuran INT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_jenis_surat (jenis_surat),
        INDEX idx_tanggal_surat (tanggal_surat),
        INDEX idx_nomor_surat (nomor_surat),
        INDEX idx_kategori (kategori)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $pdo->exec($sql);
    echo "Table 'surat_inventaris' created or already exists.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
