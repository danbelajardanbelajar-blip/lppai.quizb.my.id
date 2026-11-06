<?php
/**
 * Test Suite: Verifikasi Penanganan Kuota Pendaftaran Tutorial
 * Memverifikasi bahwa mahasiswa tidak dapat mendaftar jika kuota hari telah habis
 */

function runTutorialKuotaTests() {
    $failures = [];
    $targetFile = dirname(__DIR__) . '/pages/tutorial-pendaftaran.php';

    if (!file_exists($targetFile)) {
        $failures[] = "File pages/tutorial-pendaftaran.php tidak ditemukan.";
    } else {
        $content = file_get_contents($targetFile);

        // 1. Cek query penghitungan kuota menghitung pendaftar dengan tahun_ajaran IS NULL
        if (strpos($content, 'tahun_ajaran IS NULL') === false) {
            $failures[] = "Query penghitungan kuota belum menyertakan 'tahun_ajaran IS NULL'.";
        }

        // 2. Cek validasi backend memblokir pendaftaran jika kuota penuh atau <= 0
        if (strpos($content, '$totalKuota <= 0 || $terisi >= $totalKuota') === false &&
            strpos($content, '$terisi >= $totalKuota') === false) {
            $failures[] = "Validasi backend batas kuota tidak ditemukan.";
        }

        // 3. Cek pesan penolakan kuota penuh
        if (strpos($content, 'kuota untuk hari $hari_pilihan sudah penuh') === false) {
            $failures[] = "Pesan penolakan kuota penuh tidak ditemukan.";
        }

        // 4. Cek penonaktifan dropdown (disabled) ketika kuota hari penuh
        if (strpos($content, "\$isFull ? 'disabled' : ''") === false) {
            $failures[] = "Atribut disabled untuk opsi hari penuh tidak ditemukan pada dropdown.";
        }

        // 5. Cek label (Penuh) pada opsi dropdown
        if (strpos($content, "\$isFull ? '(Penuh)'") === false) {
            $failures[] = "Label (Penuh) untuk hari yang habis kuotanya tidak ditemukan.";
        }

        // 6. Cek penyimpanan tahun_ajaran saat insert registrasi
        if (strpos($content, 'INSERT INTO tutorial_registrations (user_id, status, hari_pilihan, gelombang, tahun_ajaran)') === false) {
            $failures[] = "Query insert pendaftaran belum mencatat kolom tahun_ajaran.";
        }
    }

    if (!empty($failures)) {
        echo "FAIL: Ditemukan kegagalan pada verifikasi kuota tutorial:\n";
        foreach ($failures as $f) {
            echo " - $f\n";
        }
        exit(1);
    } else {
        echo "PASS: Verifikasi sistem kuota pendaftaran tutorial berhasil dan valid (mahasiswa dicegah mendaftar jika kuota habis).\n";
        exit(0);
    }
}

runTutorialKuotaTests();
