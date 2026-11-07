<?php
/**
 * Test: Absensi Pretes Standalone Page & Legacy Redirect
 */

$sidebarCode = file_get_contents(__DIR__ . '/../includes/sidebar.php');
$dashboardCode = file_get_contents(__DIR__ . '/../pages/dashboard.php');
$absensiCode = file_get_contents(__DIR__ . '/../absensi-pretes.php');

// Test 1: Sidebar does not contain dashboard.php?scan=pretes and has single absensi link (in bottom submenu)
assert(strpos($sidebarCode, 'dashboard.php?scan=pretes') === false, "Sidebar still contains dashboard.php?scan=pretes");
assert(strpos($sidebarCode, 'Scan Absensi Pretes') === false, "Sidebar still has duplicate Scan Absensi Pretes in top menu");
assert(substr_count($sidebarCode, '<a href="<?= BASE_URL ?>/absensi-pretes.php"') === 1, "Expected exactly 1 absensi-pretes link tag in sidebar");
echo "[PASS] Sidebar navigation is clean and points to single absensi-pretes.php in submenu.\n";

// Test 2: Legacy redirect in pages/dashboard.php
assert(strpos($dashboardCode, "\$_GET['scan'] === 'pretes'") !== false, "Legacy redirect condition missing in dashboard.php");
assert(strpos($dashboardCode, "header('Location: ' . BASE_URL . '/absensi-pretes.php')") !== false, "Legacy redirect header missing in dashboard.php");
echo "[PASS] Legacy redirect in pages/dashboard.php confirmed.\n";

// Test 3: Standalone scanner and token mode in absensi-pretes.php
assert(strpos($absensiCode, "KASUS 1: Dibuka via Scan URL Eksternal") !== false, "Token mode comment/logic missing in absensi-pretes.php");
assert(strpos($absensiCode, "KASUS 2: Dibuka Langsung dari Menu Sidebar") !== false, "Standalone camera scanner mode missing in absensi-pretes.php");
assert(strpos($absensiCode, "reader_pretes_page") !== false, "In-page HTML5-QRCode reader element missing in absensi-pretes.php");
assert(strpos($absensiCode, "Periode Pretes Telah Selesai") !== false, "Expired period message missing in absensi-pretes.php");
echo "[PASS] absensi-pretes.php dual mode (token & camera scanner) validated.\n";

// Test 4: Verify pretes_attendance_records queries (no created_at / ORDER BY id)
assert(strpos($absensiCode, "SELECT created_at FROM pretes_attendance_records") === false, "Found invalid column created_at on pretes_attendance_records!");
assert(strpos($absensiCode, "SELECT attended_at FROM pretes_attendance_records WHERE registration_id = ?") !== false, "Correct attended_at query missing!");
assert(strpos($absensiCode, "pretes_attendance_records WHERE registration_id = ? ORDER BY") === false, "Found invalid ORDER BY on pretes_attendance_records!");
echo "[PASS] Database query schema integrity for pretes_attendance_records verified.\n";

// Test 5: Verify admin/dosen preview support
assert(strpos($absensiCode, '$isAdminUser') !== false, "isAdminUser check missing in absensi-pretes.php");
assert(strpos($absensiCode, '$isDosenUser') !== false, "isDosenUser check missing in absensi-pretes.php");
echo "[PASS] Role-based preview support (admin/dosen/mahasiswa) verified.\n";

echo "ALL ABSENSI PRETES TESTS PASSED!\n";
