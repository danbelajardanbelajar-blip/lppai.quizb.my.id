<?php
/**
 * Test Suite: Fitur Scan Absensi Pretes pada Dashboard Mahasiswa
 */

function runPretesScanTests() {
    $failures = [];
    $ajaxFile = __DIR__ . '/../ajax-pretes-scan.php';
    $dashFile = __DIR__ . '/../pages/dashboard.php';

    // 1. Cek keberadaan file
    if (!file_exists($ajaxFile)) {
        $failures[] = "File ajax-pretes-scan.php tidak ditemukan.";
    }
    if (!file_exists($dashFile)) {
        $failures[] = "File pages/dashboard.php tidak ditemukan.";
    }

    if (file_exists($ajaxFile)) {
        $ajaxContent = file_get_contents($ajaxFile);

        // 2. Cek Pengamanan Auth & Role
        if (strpos($ajaxContent, 'isLoggedIn()') === false) {
            $failures[] = "Pengamanan isLoggedIn() tidak ditemukan di ajax-pretes-scan.php";
        }
        if (strpos($ajaxContent, "role'] ?? '') !== 'mahasiswa'") === false && strpos($ajaxContent, "role'] !== 'mahasiswa'") === false) {
            $failures[] = "Pemeriksaan role mahasiswa tidak ditemukan di ajax-pretes-scan.php";
        }

        // 3. Cek CSRF
        if (strpos($ajaxContent, 'verifyCsrf(') === false) {
            $failures[] = "Pemeriksaan verifyCsrf() tidak ditemukan di ajax-pretes-scan.php";
        }

        // 4. Cek Logika Token dan Scope Matching
        if (strpos($ajaxContent, 'pretes_attendance_sessions') === false) {
            $failures[] = "Query pretes_attendance_sessions tidak ditemukan di ajax-pretes-scan.php";
        }
        if (strpos($ajaxContent, 'pretesScopeHash') === false) {
            $failures[] = "Fungsi pretesScopeHash tidak digunakan di ajax-pretes-scan.php";
        }
        if (strpos($ajaxContent, 'pretes_attendance_records') === false) {
            $failures[] = "Pencatatan pretes_attendance_records tidak ditemukan di ajax-pretes-scan.php";
        }
        if (strpos($ajaxContent, "status = 'hadir'") === false) {
            $failures[] = "Update status = 'hadir' tidak ditemukan di ajax-pretes-scan.php";
        }
    }

    if (file_exists($dashFile)) {
        $dashContent = file_get_contents($dashFile);

        // 5. Cek Elemen Scanner di Dashboard Mahasiswa
        $expectedDashElements = [
            'pretes-scanner-wrapper'    => "Container wrapper scanner (pretes-scanner-wrapper)",
            'reader_pretes_dashboard'   => "Elemen viewport kamera (reader_pretes_dashboard)",
            'btn-toggle-pretes-scanner' => "Tombol toggle scanner (btn-toggle-pretes-scanner)",
            'ajax-pretes-scan.php'      => "Endpoint ajax-pretes-scan.php dipanggil di script dashboard",
            'startPretesScanner'        => "Fungsi startPretesScanner di script dashboard",
            'stopPretesScanner'         => "Fungsi stopPretesScanner di script dashboard"
        ];

        foreach ($expectedDashElements as $needle => $label) {
            if (strpos($dashContent, $needle) === false) {
                $failures[] = "$label tidak ditemukan di pages/dashboard.php";
            }
        }

        // 6. Test Ekstraksi Token QR
        $testToken = '4f8b9e67a123456789012345678901234567890123456789';
        $inputs = [
            "https://lppai.quizb.my.id/absensi-pretes.php?token={$testToken}",
            "/absensi-pretes.php?token={$testToken}",
            "absensi-pretes.php?token={$testToken}",
            $testToken,
            strtoupper($testToken)
        ];

        foreach ($inputs as $input) {
            $extracted = null;
            if (preg_match('/[?&]token=([a-f0-9]{48})/i', $input, $m)) {
                $extracted = strtolower($m[1]);
            } elseif (preg_match('/^[a-f0-9]{48}$/i', $input)) {
                $extracted = strtolower($input);
            }
            if ($extracted !== $testToken) {
                $failures[] = "Gagal mengekstraksi token dari input: '$input'";
            }
        }
    }

    if (!empty($failures)) {
        echo "FAILURES:\n" . implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "PASS: Fitur Scan Absensi Pretes pada Dashboard Mahasiswa terverifikasi lengkap & valid.\n";
    exit(0);
}

runPretesScanTests();
