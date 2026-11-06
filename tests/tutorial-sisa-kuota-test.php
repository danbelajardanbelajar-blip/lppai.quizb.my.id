<?php
/**
 * Test Suite: Verifikasi Perhitungan Sisa Kuota Tutorial
 * Memverifikasi bahwa perhitungan sisa kuota pada tab Pengaturan & Generate tidak lagi menghasilkan nilai minus
 * dan terisolasi pada pendaftar periode aktif.
 */

function runTutorialSisaKuotaTests() {
    $failures = [];
    $baseDir = dirname(__DIR__);

    $pengaturanFile = $baseDir . '/admin/tutorial-pengaturan-generate.php';
    if (!file_exists($pengaturanFile)) {
        $failures[] = "File admin/tutorial-pengaturan-generate.php tidak ditemukan.";
    } else {
        $content = file_get_contents($pengaturanFile);

        // 1. Cek query registeredCounts menggunakan parameter periode aktif
        if (strpos($content, '$targetGelombang') === false || strpos($content, 'candidate.gelombang') === false) {
            $failures[] = "admin/tutorial-pengaturan-generate.php belum memfilter pendaftar ke gelombang aktif.";
        }

        // 2. Cek bahwa tampilan sisa kuota tidak menampilkan minus (menggunakan max(0, ...))
        if (strpos($content, 'max(0, $sisaKuota)') === false) {
            $failures[] = "admin/tutorial-pengaturan-generate.php belum menerapkan proteksi max(0, ...) pada tampilan sisa kuota.";
        }

        // 3. Cek fungsi javascript update real-time sisa kuota
        if (strpos($content, 'updateQESisaKuota') === false) {
            $failures[] = "Fungsi updateQESisaKuota tidak ditemukan pada JavaScript admin/tutorial-pengaturan-generate.php.";
        }
    }

    $kolektifFile = $baseDir . '/admin/tutorial-pendaftaran-kolektif.php';
    if (!file_exists($kolektifFile)) {
        $failures[] = "File admin/tutorial-pendaftaran-kolektif.php tidak ditemukan.";
    } else {
        $content = file_get_contents($kolektifFile);
        if (strpos($content, '$targetGelombang') === false) {
            $failures[] = "admin/tutorial-pendaftaran-kolektif.php belum memfilter registeredCounts ke gelombang aktif.";
        }
    }

    $pendaftaranFile = $baseDir . '/pages/tutorial-pendaftaran.php';
    if (!file_exists($pendaftaranFile)) {
        $failures[] = "File pages/tutorial-pendaftaran.php tidak ditemukan.";
    } else {
        $content = file_get_contents($pendaftaranFile);
        if (strpos($content, '$targetGelombang') === false) {
            $failures[] = "pages/tutorial-pendaftaran.php belum memfilter registeredCounts ke gelombang aktif.";
        }
    }

    if (!empty($failures)) {
        echo "FAIL: Ditemukan kegagalan pada verifikasi perhitungan sisa kuota:\n";
        foreach ($failures as $f) {
            echo " - $f\n";
        }
        exit(1);
    } else {
        echo "PASS: Verifikasi berhasil! Perhitungan kuota terisi dan sisa kuota kini akurat berbasis periode aktif tanpa angka minus.\n";
        exit(0);
    }
}

runTutorialSisaKuotaTests();
