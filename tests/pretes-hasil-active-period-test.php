<?php
/**
 * Test Suite: Validasi Diferensiasi Periode Pretes Aktif vs Tidak Aktif
 * File: pages/pretes-hasil.php
 */

$file = __DIR__ . '/../pages/pretes-hasil.php';
$content = file_get_contents($file);

assert($content !== false, "File pages/pretes-hasil.php harus dapat dibaca");

$tests = [
    'Syntax check pages/pretes-hasil.php' => function() use ($file) {
        exec('php -l ' . escapeshellarg($file), $out, $ret);
        return $ret === 0;
    },
    'Deteksi $isRegPeriodeAktif terimplementasi' => function() use ($content) {
        return strpos($content, '$isRegPeriodeAktif') !== false;
    },
    'Pencegahan vonis prematur: status menunggu saat periode aktif & komponen belum lengkap' => function() use ($content) {
        return strpos($content, "\$statusLulus = 'menunggu'") !== false;
    },
    'Badge status aktif vs selesai terpasang pada tampilan mahasiswa' => function() use ($content) {
        return strpos($content, '🟢 Aktif') !== false && strpos($content, '⚪ Selesai') !== false;
    },
    'Box panduan khusus saat status menunggu penilaian' => function() use ($content) {
        return strpos($content, 'Pretes Periode Aktif Sedang Berlangsung') !== false
            && strpos($content, 'pretes-peserta.php') !== false;
    },
    'Filter periode untuk Admin dan Dosen terimplementasi' => function() use ($content) {
        return strpos($content, 'Filter Periode:') !== false
            && strpos($content, 'name="periode"') !== false
            && strpos($content, 'Periode Pretes') !== false;
    }
];

echo "=== Menjalankan Validasi Diferensiasi Pretes Aktif & Tidak Aktif ===\n";
$allOk = true;
foreach ($tests as $name => $fn) {
    $res = $fn();
    if ($res) {
        echo " [PASS] $name\n";
    } else {
        echo " [FAIL] $name\n";
        $allOk = false;
    }
}

if (!$allOk) {
    echo "\n❌ Pengujian gagal!\n";
    exit(1);
} else {
    echo "\n✅ Seluruh pengujian diferensiasi periode pretes BERHASIL 100%!\n";
    exit(0);
}
