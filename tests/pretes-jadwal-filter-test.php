<?php
/**
 * Test Suite: Filter Tabel Pretes (Hari, Dosen, Ruangan, Terjadwal & Tidak Terjadwal)
 */

function runPretesJadwalFilterTests() {
    $failures = [];
    $targetFile = __DIR__ . '/../admin/pretes-jadwal.php';

    if (!file_exists($targetFile)) {
        $failures[] = "File admin/pretes-jadwal.php tidak ditemukan.";
    } else {
        $content = file_get_contents($targetFile);

        // 1. Cek Keamanan Auth
        if (strpos($content, 'requireAdmin();') === false) {
            $failures[] = "Pengamanan requireAdmin() tidak ditemukan di admin/pretes-jadwal.php";
        }

        // 2. Cek Elemen Filter Utama di atas tabel
        $expectedFilters = [
            'filter-table-hari'           => "Filter Hari (filter-table-hari)",
            'filter-table-dosen'          => "Filter Dosen (filter-table-dosen)",
            'filter-table-ruangan'        => "Filter Ruangan (filter-table-ruangan)",
            'filter-table-status-jadwal'  => "Filter Status Terjadwal / Tidak Terjadwal (filter-table-status-jadwal)",
            'btn-reset-pretes-filter'     => "Tombol Reset Filter (btn-reset-pretes-filter)"
        ];

        foreach ($expectedFilters as $id => $label) {
            if (strpos($content, $id) === false) {
                $failures[] = "$label tidak ditemukan di admin/pretes-jadwal.php";
            }
        }

        // 3. Cek Opsi Status Penjadwalan (Terjadwal & Tidak Terjadwal)
        if (strpos($content, 'value="terjadwal"') === false) {
            $failures[] = "Opsi 'terjadwal' tidak ditemukan pada filter status.";
        }
        if (strpos($content, 'value="tidak_terjadwal"') === false) {
            $failures[] = "Opsi 'tidak_terjadwal' tidak ditemukan pada filter status.";
        }

        // 4. Cek Atribut Data pada Baris Tabel
        $expectedAttrs = ['data-hari', 'data-dosen', 'data-ruangan', 'data-status-jadwal'];
        foreach ($expectedAttrs as $attr) {
            if (strpos($content, $attr) === false) {
                $failures[] = "Atribut baris $attr tidak ditemukan di tabel peserta.";
            }
        }

        // 5. Cek ID Tabel
        if (strpos($content, 'id="table-pretes-peserta"') === false) {
            $failures[] = "ID tabel 'table-pretes-peserta' tidak ditemukan.";
        }

        // 6. Cek Script DataTables Search Extension & Vanilla JS Resilience
        if (strpos($content, '$.fn.dataTable.ext.search.push') === false) {
            $failures[] = "DataTables ext.search.push tidak ditemukan di script.";
        }
        if (strpos($content, 'applyPretesFilters') === false) {
            $failures[] = "Fungsi applyPretesFilters tidak ditemukan di script.";
        }
        if (strpos($content, 'rowMatchesPretesFilters') === false) {
            $failures[] = "Fungsi rowMatchesPretesFilters tidak ditemukan di script.";
        }
        if (strpos($content, 'syncDayPills') === false) {
            $failures[] = "Fungsi syncDayPills tidak ditemukan di script.";
        }
        if (strpos($content, 'watchForPretesDataTables') === false) {
            $failures[] = "Fungsi watchForPretesDataTables tidak ditemukan di script.";
        }

        // 7. Cek Pencegahan Error ReferenceError: $ is not defined
        // Pastikan tidak ada $(document).ready() di file pretes-jadwal.php karena jQuery di-load di footer.php
        if (strpos($content, '$(document).ready') !== false) {
            $failures[] = "Terdeteksi $(document).ready() yang dapat memicu ReferenceError: $ is not defined sebelum footer.php di-load.";
        }
        if (strpos($content, "document.addEventListener('change'") === false) {
            $failures[] = "Event listener change vanilla JS tidak ditemukan di script.";
        }
        if (strpos($content, "document.addEventListener('click'") === false) {
            $failures[] = "Event listener click vanilla JS tidak ditemukan di script.";
        }
    }

    if (!empty($failures)) {
        echo "FAILURES:\n" . implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "PASS: Filter di atas tabel pretes-jadwal.php (Hari, Dosen, Ruangan, Terjadwal & Tidak Terjadwal) terverifikasi valid.\n";
    exit(0);
}

runPretesJadwalFilterTests();
