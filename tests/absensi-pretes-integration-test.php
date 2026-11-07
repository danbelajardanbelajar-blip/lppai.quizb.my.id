<?php
/**
 * Test: Absensi Pretes Standalone Page & Legacy Redirect
 */

$sidebarCode = file_get_contents(__DIR__ . '/../includes/sidebar.php');
$dashboardCode = file_get_contents(__DIR__ . '/../pages/dashboard.php');
$absensiCode = file_get_contents(__DIR__ . '/../absensi-pretes.php');

// Test 1: Sidebar does not contain dashboard.php?scan=pretes
assert(strpos($sidebarCode, 'dashboard.php?scan=pretes') === false, "Sidebar still contains dashboard.php?scan=pretes");
assert(strpos($sidebarCode, 'absensi-pretes.php') !== false, "Sidebar does not link to absensi-pretes.php");
echo "[PASS] Sidebar navigation is clean and points to absensi-pretes.php.\n";

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

echo "ALL ABSENSI PRETES TESTS PASSED!\n";
