<?php
/**
 * Test Suite: Halaman Rekapitulasi Nilai Pretes (Admin & Dosen)
 */

function runPretesRekapNilaiTests() {
    $failures = [];
    require_once __DIR__ . '/../includes/pretes-helper.php';
    $all80 = array_fill_keys(array_keys(pretesComponents()), 80);
    $cases = [
        'semua tepat 80' => [$all80, 'lulus'],
        'semua 100' => [array_fill_keys(array_keys($all80), 100), 'lulus'],
        'nilai string dari database' => [array_fill_keys(array_keys($all80), '80.00'), 'lulus'],
        'tanpa nilai' => [[], 'menunggu'],
        'JSON tidak valid' => [null, 'menunggu'],
        'belum lengkap dan sudah di bawah 80' => [['ujian_tulis' => 79], 'tidak_lulus'],
        'komponen tambahan tidak mengganti komponen wajib' => [['tambahan' => 100], 'menunggu'],
    ];
    foreach (array_keys($all80) as $key) {
        foreach ([0, 65, 79, 79.99] as $value) {
            $scores = array_fill_keys(array_keys($all80), 100);
            $scores[$key] = $value;
            $cases["$key = $value walaupun rata-rata tinggi"] = [$scores, 'tidak_lulus'];
        }
        foreach ([null, '', 'abc', 101, -1, INF, ['80']] as $value) {
            $scores = $all80;
            $scores[$key] = $value;
            $cases[$key . ' tidak valid ' . count($cases)] = [$scores, 'menunggu'];
        }
        $scores = $all80;
        unset($scores[$key]);
        $cases["$key belum diisi"] = [$scores, 'menunggu'];
    }
    foreach ($cases as $name => [$scores, $expected]) {
        $actual = getPretesStatusLulus($scores);
        if ($actual !== $expected) $failures[] = "$name: harapan $expected, hasil $actual.";
    }
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

        // 8. Cek Tabel Data & DataTables ID & Pencegahan DataTables Incorrect Column Count Warning
        if (strpos($adminContent, 'table-rekap-pretes') === false) {
            $failures[] = "Elemen tabel id #table-rekap-pretes tidak ditemukan";
        }
        if (strpos($adminContent, 'colspan="22"') === false) {
            $failures[] = "Baris data kosong harus memiliki colspan=\"22\" sesuai jumlah kolom header 22 komponen";
        }
        if (strpos($adminContent, 'colspan="23"') !== false) {
            $failures[] = "Ditemukan colspan=\"23\" yang melebihi jumlah kolom header (22), menyebabkan Tech Note 18 DataTables warning";
        }
        if (strpos($adminContent, "no-datatable") === false) {
            $failures[] = "Kelas no-datatable saat roster kosong tidak ditemukan";
        }
        if (strpos($adminContent, "dataTable.ext.errMode = 'none'") === false) {
            $failures[] = "Pengamanan dataTable.ext.errMode = 'none' tidak ditemukan";
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
        echo 'PASS: ' . count($cases) . " skenario kelulusan per komponen dan pemeriksaan halaman rekapitulasi nilai pretes.\n";
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
