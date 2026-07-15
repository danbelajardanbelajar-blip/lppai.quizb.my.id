<?php
require_once __DIR__ . '/config/database.php';

$pdo = getDBConnection();

$sqls = [
    "CREATE TABLE IF NOT EXISTS keuangan_anggaran (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama VARCHAR(150) NOT NULL,
        periode VARCHAR(50) NOT NULL,
        total_anggaran DECIMAL(15,2) NOT NULL DEFAULT 0,
        deskripsi TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'aktif',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS keuangan_pemasukan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tanggal DATE NOT NULL,
        sumber VARCHAR(150) NOT NULL,
        jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
        kategori VARCHAR(50) NULL,
        keterangan TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS keuangan_pengeluaran (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tanggal DATE NOT NULL,
        tujuan VARCHAR(150) NOT NULL,
        jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
        kategori VARCHAR(50) NULL,
        keterangan TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS keuangan_rencana_pemasukan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        anggaran_id INT NOT NULL,
        nama VARCHAR(150) NOT NULL,
        jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
        keterangan TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (anggaran_id) REFERENCES keuangan_anggaran(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS keuangan_rencana_pengeluaran (
        id INT AUTO_INCREMENT PRIMARY KEY,
        anggaran_id INT NOT NULL,
        nama VARCHAR(150) NOT NULL,
        jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
        keterangan TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (anggaran_id) REFERENCES keuangan_anggaran(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

foreach ($sqls as $sql) {
    $pdo->exec($sql);
}

echo "Tabel keuangan berhasil dibuat.";
?>
