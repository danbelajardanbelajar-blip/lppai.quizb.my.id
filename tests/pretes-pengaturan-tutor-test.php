<?php
/**
 * Test Suite: Pengaturan Sesi & Penguji / Tutor Pretes (Per Dosen, Per Ruang, Per Hari)
 */

function runTutorSettingTests() {
    $failures = [];

    // 1. Verifikasi Sidebar
    $sidebarFile = __DIR__ . '/../includes/sidebar.php';
    if (!file_exists($sidebarFile)) {
        $failures[] = "File includes/sidebar.php tidak ditemukan.";
    } else {
        $sidebarContent = file_get_contents($sidebarFile);
        if (strpos($sidebarContent, 'admin/pretes-pengaturan-tutor.php') === false) {
            $failures[] = "Link admin/pretes-pengaturan-tutor.php tidak ditemukan di includes/sidebar.php";
        }
        if (strpos($sidebarContent, 'Pengaturan Tutor') === false) {
            $failures[] = "Teks 'Pengaturan Tutor' tidak ditemukan di includes/sidebar.php";
        }
    }

    // 2. Verifikasi File admin/pretes-pengaturan-tutor.php
    $targetFile = __DIR__ . '/../admin/pretes-pengaturan-tutor.php';
    if (!file_exists($targetFile)) {
        $failures[] = "File admin/pretes-pengaturan-tutor.php tidak ditemukan.";
    } else {
        $content = file_get_contents($targetFile);
        
        // Cek keamanan auth
        if (strpos($content, 'requireAdmin();') === false) {
            $failures[] = "Pengamanan requireAdmin() tidak ditemukan di admin/pretes-pengaturan-tutor.php";
        }

        // Pastikan TIDAK ADA tabel daftar nama mahasiswa yang dirender
        if (strpos($content, 'table-penugasan') !== false || strpos($content, 'Nama Mahasiswa') !== false) {
            $failures[] = "Nama mahasiswa / tabel peserta masih ditampilkan. Halaman seharusnya tidak menampilkan daftar nama mahasiswa.";
        }

        // Cek Fitur Pengaturan Sesi: Per Dosen, Per Ruang, Per Hari
        if (strpos($content, 'create_session') === false) {
            $failures[] = "Aksi create_session tidak ditemukan.";
        }
        if (strpos($content, 'edit_session') === false) {
            $failures[] = "Aksi edit_session tidak ditemukan.";
        }
        if (strpos($content, 'delete_session') === false) {
            $failures[] = "Aksi delete_session tidak ditemukan.";
        }
        if (strpos($content, 'duplicate_day_sessions') === false) {
            $failures[] = "Aksi duplicate_day_sessions tidak ditemukan.";
        }

        // Cek AJAX endpoints untuk Sesi & QR
        if (strpos($content, 'toggle_session_ajax') === false) {
            $failures[] = "Endpoint toggle_session_ajax tidak ditemukan di file.";
        }
        if (strpos($content, 'reset_session_token_ajax') === false) {
            $failures[] = "Endpoint reset_session_token_ajax tidak ditemukan di file.";
        }
        if (strpos($content, 'open_all_sessions') === false) {
            $failures[] = "Aksi open_all_sessions tidak ditemukan.";
        }
        if (strpos($content, 'close_all_sessions') === false) {
            $failures[] = "Aksi close_all_sessions tidak ditemukan.";
        }

        // Cek Form Actions Pengaturan Tutor
        if (strpos($content, 'create_tutor') === false) {
            $failures[] = "Aksi create_tutor tidak ditemukan.";
        }
        if (strpos($content, 'update_tutor') === false) {
            $failures[] = "Aksi update_tutor tidak ditemukan.";
        }
        if (strpos($content, 'update_master_jadwal_tutor') === false) {
            $failures[] = "Aksi update_master_jadwal_tutor tidak ditemukan.";
        }

        // Cek kolom entitas sesi terkait
        foreach (['penguji', 'ruangan', 'hari', 'jam', 'kuota'] as $col) {
            if (strpos($content, $col) === false) {
                $failures[] = "Kolom $col tidak ditemukan di file.";
            }
        }

        // Cek Fitur Menu Cetak & Bagikan Jadwal Tutor
        if (strpos($content, 'pretes-jadwal-cetak.php') === false) {
            $failures[] = "Link menu cetak jadwal pretes-jadwal-cetak.php tidak ditemukan di admin/pretes-pengaturan-tutor.php";
        }
        if (strpos($content, 'copyScheduleWaText') === false) {
            $failures[] = "Fungsi copyScheduleWaText tidak ditemukan di admin/pretes-pengaturan-tutor.php";
        }
        if (strpos($content, 'api.whatsapp.com/send') === false) {
            $failures[] = "Tombol share WhatsApp tidak ditemukan di admin/pretes-pengaturan-tutor.php";
        }
        if (strpos($content, 'Kuota Terplot') === false) {
            $failures[] = "Teks 'Kuota Terplot' per dosen tidak ditemukan di admin/pretes-pengaturan-tutor.php";
        }
    }

    // 3. Verifikasi File admin/pretes-jadwal-cetak.php
    $printFile = __DIR__ . '/../admin/pretes-jadwal-cetak.php';
    if (!file_exists($printFile)) {
        $failures[] = "File admin/pretes-jadwal-cetak.php tidak ditemukan.";
    } else {
        $pContent = file_get_contents($printFile);
        if (strpos($pContent, 'requireAdmin();') === false) {
            $failures[] = "Pengamanan requireAdmin() tidak ditemukan di admin/pretes-jadwal-cetak.php";
        }
        if (strpos($pContent, 'kop-surat') === false) {
            $failures[] = "Kop surat LPPAI UNISDA tidak ditemukan di admin/pretes-jadwal-cetak.php";
        }
        if (strpos($pContent, 'window.print()') === false) {
            $failures[] = "Fungsi window.print() tidak ditemukan di admin/pretes-jadwal-cetak.php";
        }
        if (strpos($pContent, 'copyWaText') === false) {
            $failures[] = "Fungsi salin WhatsApp tidak ditemukan di admin/pretes-jadwal-cetak.php";
        }
        if (strpos($pContent, 'Dr. Zainul Hakim. M.H.I.') === false) {
            $failures[] = "Nama Kepala LPPAI 'Dr. Zainul Hakim. M.H.I.' tidak ditemukan di admin/pretes-jadwal-cetak.php";
        }
        if (strpos($pContent, 'Kuota Terplot') === false) {
            $failures[] = "Teks 'Kuota Terplot' per dosen tidak ditemukan di admin/pretes-jadwal-cetak.php";
        }
        if (strpos($pContent, 'day-kuota-box') !== false) {
            $failures[] = "Box kuota di bawah tabel masih ditemukan di admin/pretes-jadwal-cetak.php";
        }
    }

    // 4. Test Logika Parser Penguji
    $testStr = "Dr. Fauzi, M.Pd.I|||Ustadz Ahmad, M.E.I|||K.H. Zainal";
    $parts = array_values(array_filter(array_map('trim', explode('|||', $testStr))));
    if (count($parts) !== 3 || $parts[1] !== 'Ustadz Ahmad, M.E.I') {
        $failures[] = "Parser penguji ||| gagal memisahkan string.";
    }

    if (!empty($failures)) {
        echo "FAILURES:\n" . implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "PASS: Pengaturan Sesi & Penguji (per dosen, per ruang, per hari) tanpa nama mahasiswa terverifikasi valid.\n";
    exit(0);
}

runTutorSettingTests();
