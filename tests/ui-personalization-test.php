<?php
/**
 * Test: Sistem Personalisasi Tampilan & Antarmuka (Themes, Typography, Density, Multi-device)
 * Menguji helper functions, migrasi SQLite otomatis, validasi nilai legal/fallback, persistensi DB, dan sinkronisasi session.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$t = function (bool $ok, string $msg) use (&$fail): void {
    echo ($ok ? ' [PASS] ' : '❌ [FAIL] ') . $msg . "\n";
    if (!$ok) $fail++;
};

require_once $root . '/includes/user-helper.php';

echo "=== TEST: UI PERSONALIZATION SETTINGS ===\n";

// 1. Uji fungsi getDefaultUiPreferences()
$defaults = getDefaultUiPreferences();
$t(is_array($defaults), 'getDefaultUiPreferences() mengembalikan array');
$t(($defaults['theme'] ?? '') === 'emerald', 'Bawaan theme adalah emerald');
$t(($defaults['font_family'] ?? '') === 'plus-jakarta-sans', 'Bawaan font_family adalah plus-jakarta-sans');
$t(($defaults['font_size'] ?? '') === 'normal', 'Bawaan font_size adalah normal');
$t(($defaults['table_density'] ?? '') === 'comfortable', 'Bawaan table_density adalah comfortable');
$t(($defaults['sidebar_mode'] ?? '') === 'default', 'Bawaan sidebar_mode adalah default');

// 2. Setup SQLite database di memori
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Buat tabel users tanpa kolom ui_preferences terlebih dahulu
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, nama_lengkap TEXT, role TEXT)");
$pdo->exec("INSERT INTO users VALUES (1, 'admin', 'Administrator Utama', 'admin')");
$pdo->exec("INSERT INTO users VALUES (2, 'mhs1', 'Ahmad Dahlan', 'mahasiswa')");

// 3. Pastikan ensureUiPreferencesColumn menambahkan kolom secara dinamis tanpa error
ensureUiPreferencesColumn($pdo);

$stmt = $pdo->query("PRAGMA table_info(users)");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_map(function($c) { return strtolower($c['name']); }, $cols);
$t(in_array('ui_preferences', $colNames, true), 'ensureUiPreferencesColumn berhasil menambahkan kolom ui_preferences di tabel users');

// 4. Uji getUserUiPreferences saat data masih kosong / NULL
$mhsPrefs = getUserUiPreferences(2, $pdo);
$t($mhsPrefs === $defaults, 'User dengan data NULL mengembalikan default preferences');

// 5. Uji saveUserUiPreferences dengan nilai valid
$customPrefs = [
    'theme' => 'dark',
    'font_family' => 'inter',
    'font_size' => 'medium',
    'table_density' => 'compact',
    'sidebar_mode' => 'compact'
];
$saveOk = saveUserUiPreferences(2, $customPrefs, $pdo);
$t($saveOk === true, 'saveUserUiPreferences berhasil menyimpan data valid ke SQLite');

// 6. Uji getUserUiPreferences setelah disimpan
// Reset session cache if any
if (session_status() === PHP_SESSION_ACTIVE) {
    unset($_SESSION['ui_preferences_data']);
}
$fetchedPrefs = getUserUiPreferences(2, $pdo);
$t($fetchedPrefs['theme'] === 'dark', 'Theme berhasil disimpan dan dimuat sebagai dark');
$t($fetchedPrefs['font_family'] === 'inter', 'Font family berhasil disimpan dan dimuat sebagai inter');
$t($fetchedPrefs['font_size'] === 'medium', 'Font size berhasil disimpan dan dimuat sebagai medium');
$t($fetchedPrefs['table_density'] === 'compact', 'Table density berhasil disimpan dan dimuat sebagai compact');
$t($fetchedPrefs['sidebar_mode'] === 'compact', 'Sidebar mode berhasil disimpan dan dimuat sebagai compact');

// 7. Uji validasi input ilegal / injection (harus jatuh ke fallback aman)
$illegalPrefs = [
    'theme' => '<script>alert(1)</script>',
    'font_family' => 'comic-sans',
    'font_size' => 'huge',
    'table_density' => 'loose',
    'sidebar_mode' => 'hidden'
];
$saveIllegalOk = saveUserUiPreferences(2, $illegalPrefs, $pdo);
$t($saveIllegalOk === true, 'saveUserUiPreferences menangani input ilegal dengan fallback aman');

if (session_status() === PHP_SESSION_ACTIVE) {
    unset($_SESSION['ui_preferences_data']);
}
$sanitizedPrefs = getUserUiPreferences(2, $pdo);
$t($sanitizedPrefs['theme'] === 'emerald', 'Tema ilegal otomatis dibersihkan menjadi fallback emerald');
$t($sanitizedPrefs['font_family'] === 'plus-jakarta-sans', 'Font ilegal otomatis dibersihkan menjadi fallback plus-jakarta-sans');
$t($sanitizedPrefs['font_size'] === 'normal', 'Font size ilegal otomatis dibersihkan menjadi fallback normal');
$t($sanitizedPrefs['table_density'] === 'comfortable', 'Density ilegal otomatis dibersihkan menjadi fallback comfortable');
$t($sanitizedPrefs['sidebar_mode'] === 'default', 'Sidebar ilegal otomatis dibersihkan menjadi fallback default');

// 8. Uji tema sapphire dan sepia
saveUserUiPreferences(1, ['theme' => 'sapphire'], $pdo);
if (session_status() === PHP_SESSION_ACTIVE) unset($_SESSION['ui_preferences_data']);
$adminPrefs = getUserUiPreferences(1, $pdo);
$t($adminPrefs['theme'] === 'sapphire', 'Tema sapphire tersimpan dengan benar');

saveUserUiPreferences(1, ['theme' => 'sepia', 'font_family' => 'lexend', 'font_size' => 'large'], $pdo);
if (session_status() === PHP_SESSION_ACTIVE) unset($_SESSION['ui_preferences_data']);
$adminPrefs2 = getUserUiPreferences(1, $pdo);
$t($adminPrefs2['theme'] === 'sepia', 'Tema sepia tersimpan dengan benar');
$t($adminPrefs2['font_family'] === 'lexend', 'Font lexend tersimpan dengan benar');
$t($adminPrefs2['font_size'] === 'large', 'Font size large tersimpan dengan benar');

// 9. Cek file style.css dan header.php memuat token penting
$cssContent = file_get_contents($root . '/assets/css/style.css');
$t(strpos($cssContent, 'html[data-theme="dark"]') !== false, 'style.css memuat selektor tema dark');
$t(strpos($cssContent, 'html[data-theme="sapphire"]') !== false, 'style.css memuat selektor tema sapphire');
$t(strpos($cssContent, 'html[data-theme="sepia"]') !== false, 'style.css memuat selektor tema sepia');
$t(strpos($cssContent, 'html[data-font="lexend"]') !== false, 'style.css memuat selektor font lexend');
$t(strpos($cssContent, 'html[data-density="compact"]') !== false, 'style.css memuat selektor kepadatan compact');

$headerContent = file_get_contents($root . '/includes/header.php');
$t(strpos($headerContent, 'lppai_ui_prefs') !== false, 'header.php memuat script anti-flicker lppai_ui_prefs');
$t(strpos($headerContent, 'getUserUiPreferences') !== false, 'header.php memanggil getUserUiPreferences');

$pengaturanContent = file_get_contents($root . '/pages/pengaturan.php');
$t(strpos($pengaturanContent, 'save_ui_preferences') !== false, 'pengaturan.php menangani action save_ui_preferences');
$t(strpos($pengaturanContent, 'id="theme-settings"') !== false, 'pengaturan.php memuat kartu #theme-settings');
$t(strpos($pengaturanContent, 'applyLivePreview') !== false, 'pengaturan.php memuat JavaScript interaktif live preview');

echo "\nTOTAL FAILURES: {$fail}\n";
if ($fail === 0) {
    echo "✅ SEMUA 24 PENGUJIAN UI PERSONALIZATION BERHASIL!\n";
    exit(0);
} else {
    echo "❌ TERDAPAT PENGUJIAN YANG GAGAL!\n";
    exit(1);
}
