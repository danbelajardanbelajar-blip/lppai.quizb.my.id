<?php
/**
 * Test Suite: Halaman Rekapitulasi Nilai Pretes (Admin & Dosen)
 */

function runPretesRekapNilaiTests() {
    $failures = [];
    $adminFile = __DIR__ . '/../admin/pretes-rekap-nilai.php';
    $dosenFile = __DIR__ . '/../dosen/pretes-rekap-nilai.php';
    $sidebarFile = __DIR__ . '/../includes/sidebar.php';

    // 1. Cek keberadaan file
    if (!file_exists($adminFile)) {
        $failures[] = "File admin/pretes-rekap-nilai.php tidak ditemukan.";
    }
    if (!file_exists($dosenFile)) {
        $failures[] = "File dosen/pretes-rekap-nilai.php tidak ditemukan.";
    }
    if (!file_exists($sidebarFile)) {
        $failures[] = "File includes/sidebar.php tidak ditemukan.";
    }

    if (file_exists($adminFile)) {
        $adminContent = file_get_contents($adminFile);

        // 2. Cek Pengamanan Auth & Role
        if (strpos($adminContent, 'requireLogin()') === false) {
            $failures[] = "Pengamanan requireLogin() tidak ditemukan di admin/pretes-rekap-nilai.php";
        }
        if (strpos($adminContent, "in_array(\$role, ['admin', 'dosen']") === false) {
            $failures[] = "Pemeriksaan role admin dan dosen tidak ditemukan di admin/pretes-rekap-nilai.php";
        }

        // 3. Cek Helper Pretes & 12 Komponen
        if (strpos($adminContent, 'pretesComponents()') === false) {
            $failures[] = "Pemanggilan pretesComponents() tidak ditemukan di admin/pretes-rekap-nilai.php";
        }
        if (strpos($adminContent, 'getPretesPredikat') === false) {
            $failures[] = "Fungsi kalkulasi predikat getPretesPredikat() tidak ditemukan";
        }
        if (strpos($adminContent, 'getPretesStatusLulus') === false) {
            $failures[] = "Fungsi kalkulasi status lulus getPretesStatusLulus() tidak ditemukan";
        }

        // 4. Cek Sinkronisasi Nilai ke Hasil Pretes (Admin)
        if (strpos($adminContent, 'sync_to_results') === false) {
            $failures[] = "Aksi sinkronisasi sync_to_results tidak ditemukan";
        }
        if (strpos($adminContent, 'pretes_results') === false) {
            $failures[] = "Tabel pretes_results tidak diakses untuk sinkronisasi";
        }

        // 5. Cek Filter Multi-Kriteria
        $expectedFilters = [
            'Filter Hari'       => 'filterHari',
            'Filter Dosen'      => 'filterDosen',
            'Filter Ruang'      => 'filterRuang',
            'Filter Kelengkapan'=> 'filterKelengkapan',
            'Filter Kelulusan'  => 'filterStatus',
            'Pencarian Peserta' => 'filterSearch',
        ];
        foreach ($expectedFilters as $name => $var) {
            if (strpos($adminContent, $var) === false) {
                $failures[] = "Variabel filter {$name} ({$var}) tidak ditemukan";
            }
        }

        // 6. Cek Mode Export (Excel & CSV)
        if (strpos($adminContent, 'exportFormat') === false) {
            $failures[] = "Penanganan export format tidak ditemukan";
        }
        if (strpos($adminContent, '\xEF\xBB\xBF') === false && strpos($adminContent, "\xEF\xBB\xBF") === false) {
            $failures[] = "Fallback CSV dengan UTF-8 BOM tidak ditemukan";
        }

        // 7. Cek Mode Cetak & Kop LPPAI
        if (strpos($adminContent, 'isPrint') === false) {
            $failures[] = "Logika isPrint tidak ditemukan";
        }
        if (strpos($adminContent, 'Dr. Zainul Hakim. M.H.I.') === false) {
            $failures[] = "Nama Kepala LPPAI 'Dr. Zainul Hakim. M.H.I.' tidak ditemukan pada lembar cetak";
        }
        if (strpos($adminContent, 'ttd_zainul_hakim.png') === false) {
            $failures[] = "File tanda tangan ttd_zainul_hakim.png tidak ditemukan pada lembar cetak";
        }

        // 8. Cek Tabel Data & DataTables ID
        if (strpos($adminContent, 'table-rekap-pretes') === false) {
            $failures[] = "Elemen tabel id #table-rekap-pretes tidak ditemukan";
        }
    }

    if (file_exists($dosenFile)) {
        $dosenContent = file_get_contents($dosenFile);
        if (strpos($dosenContent, 'requireDosen()') === false) {
            $failures[] = "Pengamanan requireDosen() tidak ditemukan di dosen/pretes-rekap-nilai.php";
        }
        if (strpos($dosenContent, 'admin/pretes-rekap-nilai.php') === false) {
            $failures[] = "Delegasi ke admin/pretes-rekap-nilai.php tidak ditemukan di dosen/pretes-rekap-nilai.php";
        }
    }

    if (file_exists($sidebarFile)) {
        $sidebarContent = file_get_contents($sidebarFile);
        if (strpos($sidebarContent, 'admin/pretes-rekap-nilai.php') === false) {
            $failures[] = "Menu Rekapitulasi Nilai Pretes untuk Admin tidak ditemukan di includes/sidebar.php";
        }
        if (strpos($sidebarContent, 'dosen/pretes-rekap-nilai.php') === false) {
            $failures[] = "Menu Rekapitulasi Nilai Pretes untuk Dosen tidak ditemukan di includes/sidebar.php";
        }
    }

    if (empty($failures)) {
        echo "PASS: Halaman Rekapitulasi Nilai Pretes terverifikasi lengkap & valid.\n";
        return 0;
    } else {
        echo "FAIL: Ditemukan kegagalan pada pengujian rekapitulasi nilai pretes:\n";
        foreach ($failures as $f) {
            echo " - $f\n";
        }
        return 1;
    }
}

exit(runPretesRekapNilaiTests());
