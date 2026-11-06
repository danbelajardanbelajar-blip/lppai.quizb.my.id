<?php
/**
 * Test Suite: Verifikasi Filter Periode Aktif pada Halaman Hasil Plotting Tutorial
 * Memverifikasi bahwa admin/tutorial-hasil-plotting.php hanya menampilkan peserta dan kelas dari periode aktif
 */

function runTutorialHasilPlottingFilterTests() {
    $failures = [];
    $targetFile = dirname(__DIR__) . '/admin/tutorial-hasil-plotting.php';

    if (!file_exists($targetFile)) {
        $failures[] = "File admin/tutorial-hasil-plotting.php tidak ditemukan.";
    } else {
        $content = file_get_contents($targetFile);

        // 1. Cek query master_gelombang mencari status aktif
        if (strpos($content, "\$g['status'] === 'aktif'") === false) {
            $failures[] = "Pengecekan status 'aktif' pada master_gelombang tidak ditemukan.";
        }

        // 2. Cek query tutorial_classes terfilter berdasarkan gelombang dan semester aktif
        if (strpos($content, 'WHERE gelombang = ?') === false) {
            $failures[] = "Query tutorial_classes belum memfilter berdasarkan gelombang aktif (gelombang = ?).";
        }

        // 3. Cek query registrations peserta memfilter gelombang dan tahun ajaran aktif
        if (strpos($content, "tc.gelombang = ?") === false) {
            $failures[] = "Query registrations belum membatasi kelas peserta ke gelombang aktif (tc.gelombang = ?).";
        }

        // 4. Cek subquery memfilter candidate registration ke gelombang dan tahun ajaran
        if (strpos($content, "candidate.gelombang) = ?") === false) {
            $failures[] = "Subquery registrations belum memfilter candidate pendaftar ke gelombang aktif.";
        }

        // 5. Cek tampilan badge/indikator Periode Aktif
        if (strpos($content, 'Periode Aktif:') === false) {
            $failures[] = "Badge atau indikator 'Periode Aktif:' tidak ditemukan pada halaman.";
        }
    }

    if (!empty($failures)) {
        echo "FAIL: Ditemukan kegagalan pada verifikasi filter periode aktif tutorial hasil plotting:\n";
        foreach ($failures as $f) {
            echo " - $f\n";
        }
        exit(1);
    } else {
        echo "PASS: Verifikasi berhasil! Halaman tutorial-hasil-plotting.php kini memfilter kelas dan peserta secara ketat hanya dari periode aktif.\n";
        exit(0);
    }
}

runTutorialHasilPlottingFilterTests();
