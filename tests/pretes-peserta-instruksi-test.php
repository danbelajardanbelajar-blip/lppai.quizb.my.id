<?php
/**
 * Test Suite: Validasi Instruksi & Gating Akses Halaman Pretes Mahasiswa
 * File: pages/pretes-peserta.php
 */

$fileContent = file_get_contents(__DIR__ . '/../pages/pretes-peserta.php');

$asserts = [
    'Syntax check file' => function() {
        exec('php -l ' . escapeshellarg(__DIR__ . '/../pages/pretes-peserta.php'), $out, $ret);
        return $ret === 0;
    },
    'Instruksi 1: Berdoa dan Belajar' => function() use ($fileContent) {
        return stripos($fileContent, 'Persiapkan diri untuk mengikuti ujian dengan berdoa dan belajar') !== false;
    },
    'Instruksi 2: Dikerjakan Sendiri' => function() use ($fileContent) {
        return stripos($fileContent, 'Pastikan dikerjakan sendiri') !== false;
    },
    'Instruksi 3: Tidak Membuka Web Lain' => function() use ($fileContent) {
        return stripos($fileContent, 'Pastikan tidak membuka web lain') !== false;
    },
    'Instruksi 4: Tidak Menggunakan HP Lain' => function() use ($fileContent) {
        return stripos($fileContent, 'Pastikan tidak menggunakan HP lain') !== false;
    },
    'Gating Tanggal 16 Nov: Variabel tglBukaUjian terdefinisi 2026-11-16' => function() use ($fileContent) {
        return strpos($fileContent, "'2026-11-16'") !== false && strpos($fileContent, '$isUjianDibuka') !== false;
    },
    'Teks Info Belum Tanggal 16 Muncul' => function() use ($fileContent) {
        return strpos($fileContent, 'Link &quot;Kerjakan Soal Pretes&quot; akan dapat diakses pada') !== false
            || strpos($fileContent, 'akan dapat diakses pada') !== false;
    },
    'Tombol Kerjakan Soal Pretes Tersedia Saat Buka' => function() use ($fileContent) {
        return strpos($fileContent, 'Kerjakan Soal Pretes') !== false;
    },
    'Link dikosongkan sementara' => function() use ($fileContent) {
        return strpos($fileContent, '$linkSoalPretes = \'\'') !== false;
    }
];

echo "=== Menjalankan Validasi pages/pretes-peserta.php ===\n";
$allPassed = true;
foreach ($asserts as $name => $fn) {
    $ok = $fn();
    if ($ok) {
        echo " [PASS] $name\n";
    } else {
        echo " [FAIL] $name\n";
        $allPassed = false;
    }
}

if (!$allPassed) {
    echo "\n❌ Ada pengujian yang gagal!\n";
    exit(1);
} else {
    echo "\n✅ Semua pengujian pretes-peserta.php BERHASIL!\n";
    exit(0);
}
