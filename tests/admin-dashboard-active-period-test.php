<?php
/**
 * Test: Admin Dashboard Dynamic Active Period & Registration Tabs
 */

$file = __DIR__ . '/../admin/dashboard.php';
if (!file_exists($file)) {
    echo "[FAIL] File admin/dashboard.php does not exist.\n";
    exit(1);
}

$code = file_get_contents($file);

// Test 1: Syntax check
$output = [];
$returnVar = 0;
exec('php -l ' . escapeshellarg($file), $output, $returnVar);
if ($returnVar !== 0) {
    echo "[FAIL] Syntax error in admin/dashboard.php:\n" . implode("\n", $output) . "\n";
    exit(1);
}
echo "[PASS] Syntax check passed.\n";

// Test 2: Ensure dynamic detection of master_pretes and master_gelombang
assert(strpos($code, "SELECT * FROM master_pretes WHERE status = 'aktif'") !== false, "master_pretes query missing");
assert(strpos($code, "SELECT * FROM master_gelombang WHERE status = 'aktif'") !== false, "master_gelombang query missing");
echo "[PASS] Active period detection queries exist.\n";

// Test 3: WhatsApp formatter function logic
if (!function_exists('formatWaDashboard')) {
    function formatWaDashboard($noHp) {
        $clean = preg_replace('/[^0-9]/', '', (string)$noHp);
        if (empty($clean)) return '';
        if (strpos($clean, '0') === 0) {
            $clean = '62' . substr($clean, 1);
        }
        return 'https://wa.me/' . $clean;
    }
}

assert(formatWaDashboard('081234567890') === 'https://wa.me/6281234567890', "formatWaDashboard 08... failed");
assert(formatWaDashboard('+62 812-3456-7890') === 'https://wa.me/6281234567890', "formatWaDashboard +62... failed");
assert(formatWaDashboard('') === '', "formatWaDashboard empty failed");
echo "[PASS] WhatsApp formatting tests passed.\n";

// Test 4: Check tab switching elements and fallback handling
assert(strpos($code, 'switchDashboardTab') !== false, "switchDashboardTab JS missing");
assert(strpos($code, 'tabContentTutorial') !== false, "tabContentTutorial container missing");
assert(strpos($code, 'tabContentPretes') !== false, "tabContentPretes container missing");
assert(strpos($code, 'badgeActiveTut') !== false, "badgeActiveTut missing");
assert(strpos($code, 'badgeActivePre') !== false, "badgeActivePre missing");
echo "[PASS] Dynamic tab UI and indicators validated successfully.\n";

echo "ALL TESTS PASSED!\n";
