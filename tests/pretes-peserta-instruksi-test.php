<?php
/**
 * Test Suite: Validasi Instruksi & Gating Akses Halaman Pretes Mahasiswa
 * File: pages/pretes-peserta.php
 */

$fileContent = file_get_contents(__DIR__ . '/../pages/pretes-peserta.php');
$fileContent .= file_get_contents(__DIR__ . '/../includes/pretes-participant-view.php');
$fileContent .= file_get_contents(__DIR__ . '/../includes/pretes-active-page.php');

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
    'Gating oleh admin: link dibuka via pretesCbtExamStatus (tanpa tanggal tertanam)' => function() use ($fileContent) {
        return strpos($fileContent, 'pretesCbtExamStatus(') !== false && strpos($fileContent, '$isUjianDibuka') !== false
            && strpos($fileContent, "'2026-11-16'") === false;
    },
    'Teks info saat pengerjaan belum dibuka admin muncul' => function() use ($fileContent) {
        return strpos($fileContent, 'akan muncul di halaman ini begitu admin membuka pengerjaan ujian') !== false
            && strpos($fileContent, '$examClosedNotice') !== false;
    },
    'Tombol Kerjakan Soal Pretes Tersedia Saat Buka' => function() use ($fileContent) {
        return strpos($fileContent, 'Kerjakan Soal Pretes') !== false;
    },
    'Link pengerjaan soal mengarah ke portal ujian pretes-ujian.php' => function() use ($fileContent) {
        return strpos($fileContent, 'pretes-ujian.php') !== false;
    },
    'Privilege Tester Akun 12345 Terdefinisi' => function() use ($fileContent) {
        return strpos($fileContent, "'12345'") !== false && strpos($fileContent, '$isTesterPrivileged') !== false;
    },
    'Tombol Cetak Kartu Pretes Disembunyikan Sesuai Kebijakan Scan Wajah' => function() use ($fileContent) {
        return strpos($fileContent, 'cetak-kartu-pretes.php') === false && strpos($fileContent, 'Cetak Kartu Pretes') === false;
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
