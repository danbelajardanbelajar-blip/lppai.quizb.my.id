<?php
/**
 * Script untuk Import Manual Kehadiran Al Khidmah
 */
require_once __DIR__ . '/includes/auth.php'; // untuk koneksi DB

$pdo = getDBConnection();

$csvFile = 'C:\Users\zenhk\OneDrive\Documents\data_nim_kehadiran.csv';
$tanggalKegiatan = '2026-08-07'; // Tanggal kegiatan, sesuaikan jika perlu
$waktuHadir = '07:00:00'; 
$waktuPulang = '10:00:00'; 

if (!file_exists($csvFile)) {
    die("File CSV tidak ditemukan di $csvFile\n");
}

$unregisteredNims = [];
$successCount = 0;
$duplicateCount = 0;

$file = fopen($csvFile, 'r');
if ($file) {
    echo "Memulai import data absensi Al Khidmah...\n";
    $pdo->beginTransaction();
    
    // Prepare statements
    $stmtUser = $pdo->prepare("SELECT id FROM users WHERE nim = ?");
    $stmtInsert = $pdo->prepare("
        INSERT INTO absensi_alkhidmah (nim, tanggal, waktu_hadir, waktu_pulang) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE waktu_hadir = VALUES(waktu_hadir), waktu_pulang = VALUES(waktu_pulang)
    ");
    
    while (($data = fgetcsv($file, 1000, ";")) !== FALSE) {
        $nim = trim($data[0]);
        if (empty($nim)) continue;
        
        // 1. Cek apakah NIM ada di table users
        $stmtUser->execute([$nim]);
        $userExists = $stmtUser->fetch();
        
        if (!$userExists) {
            $unregisteredNims[] = $nim;
        }
        
        // 2. Insert ke table absensi_alkhidmah (baik terdaftar atau belum terdaftar? 
        // User minta "lakukan pengecekan jika nim belum terdaftar ... maka tampilkan".
        // Biasanya kalau belum terdaftar di users, kita tetap masukkan ke absensi atau dilewati?
        // Saya akan tetap masukkan ke absensi_alkhidmah karena absensi_alkhidmah bisa saja menampung NIM yang belum daftar akun.
        try {
            $stmtInsert->execute([$nim, $tanggalKegiatan, $waktuHadir, $waktuPulang]);
            if ($stmtInsert->rowCount() > 0) {
                $successCount++;
            }
        } catch (PDOException $e) {
            echo "Error pada NIM $nim: " . $e->getMessage() . "\n";
        }
    }
    
    $pdo->commit();
    fclose($file);
    
    echo "\n=== HASIL IMPORT ===\n";
    echo "Berhasil diimport/diupdate: $successCount baris\n";
    
    if (count($unregisteredNims) > 0) {
        echo "\n⚠️ DAFTAR NIM YANG BELUM TERDAFTAR DI TABEL USER (" . count($unregisteredNims) . " NIM):\n";
        // Hapus duplikat barangkali ada
        $unregisteredNims = array_unique($unregisteredNims);
        foreach ($unregisteredNims as $uNim) {
            echo "- $uNim\n";
        }
    } else {
        echo "\n✅ Semua NIM sudah terdaftar di tabel user.\n";
    }
    
} else {
    echo "Gagal membuka file CSV.\n";
}
?>
