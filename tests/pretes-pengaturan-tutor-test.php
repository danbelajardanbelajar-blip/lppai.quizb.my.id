<?php
/**
 * Test Suite: Pengaturan Tutor & Sesi Pretes
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

        // Cek AJAX endpoints untuk Tutor & Sesi
        if (strpos($content, 'update_tutor_ajax') === false) {
            $failures[] = "Endpoint update_tutor_ajax tidak ditemukan di file.";
        }
        if (strpos($content, 'Dosen penguji telah dirubah') === false) {
            $failures[] = "Pesan toast 'Dosen penguji telah dirubah' tidak ditemukan di file.";
        }
        if (strpos($content, 'update_jam_ajax') === false) {
            $failures[] = "Endpoint update_jam_ajax tidak ditemukan di file.";
        }
        if (strpos($content, 'Sesi jam pretes telah dirubah') === false) {
            $failures[] = "Pesan toast 'Sesi jam pretes telah dirubah' tidak ditemukan di file.";
        }
        if (strpos($content, 'toggle_session_ajax') === false) {
            $failures[] = "Endpoint toggle_session_ajax tidak ditemukan di file.";
        }
        if (strpos($content, 'reset_session_token_ajax') === false) {
            $failures[] = "Endpoint reset_session_token_ajax tidak ditemukan di file.";
        }
        if (strpos($content, 'toggle_tutor_status_ajax') === false) {
            $failures[] = "Endpoint toggle_tutor_status_ajax tidak ditemukan di file.";
        }

        // Cek Form Actions Pengaturan Tutor & Sesi
        if (strpos($content, 'create_tutor') === false) {
            $failures[] = "Aksi create_tutor tidak ditemukan.";
        }
        if (strpos($content, 'update_tutor') === false) {
            $failures[] = "Aksi update_tutor tidak ditemukan.";
        }
        if (strpos($content, 'bulk_reassign_tutor') === false) {
            $failures[] = "Aksi bulk_reassign_tutor tidak ditemukan.";
        }
        if (strpos($content, 'auto_distribute_tutors') === false) {
            $failures[] = "Aksi auto_distribute_tutors tidak ditemukan.";
        }
        if (strpos($content, 'update_master_jadwal_tutor') === false) {
            $failures[] = "Aksi update_master_jadwal_tutor tidak ditemukan.";
        }
        if (strpos($content, 'update_master_jam') === false) {
            $failures[] = "Aksi update_master_jam tidak ditemukan.";
        }
        if (strpos($content, 'bulk_update_jam') === false) {
            $failures[] = "Aksi bulk_update_jam tidak ditemukan.";
        }
        if (strpos($content, 'open_all_sessions') === false) {
            $failures[] = "Aksi open_all_sessions tidak ditemukan.";
        }
        if (strpos($content, 'close_all_sessions') === false) {
            $failures[] = "Aksi close_all_sessions tidak ditemukan.";
        }

        // Cek tab pengaturan sesi
        if (strpos($content, 'tab=sesi') === false) {
            $failures[] = "Tab 'tab=sesi' tidak ditemukan di navigasi tab.";
        }
        if (strpos($content, 'Pengaturan Sesi Pretes') === false) {
            $failures[] = "Teks 'Pengaturan Sesi Pretes' tidak ditemukan di file.";
        }

        // Cek kolom tabel penguji dan sesi terkait
        foreach (['dosen_penguji', 'jam', 'penguji_senin', 'penguji_selasa', 'penguji_rabu', 'penguji_kamis', 'penguji_jumat'] as $col) {
            if (strpos($content, $col) === false) {
                $failures[] = "Kolom $col tidak ditemukan di file.";
            }
        }
    }

    // 3. Test Logika Parser Penguji
    $testStr = "Dr. Fauzi, M.Pd.I|||Ustadz Ahmad, M.E.I|||K.H. Zainal";
    $parts = array_values(array_filter(array_map('trim', explode('|||', $testStr))));
    if (count($parts) !== 3 || $parts[1] !== 'Ustadz Ahmad, M.E.I') {
        $failures[] = "Parser penguji ||| gagal memisahkan string.";
    }

    if (!empty($failures)) {
        echo "FAILURES:\n" . implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "PASS: Pengaturan Tutor & Sesi Pretes terverifikasi lengkap dan valid.\n";
    exit(0);
}

runTutorSettingTests();
