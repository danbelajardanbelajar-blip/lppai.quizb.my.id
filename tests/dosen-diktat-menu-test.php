<?php
/**
 * Test Suite: Validasi Menu & Halaman Diktat Tutorial untuk Role Dosen
 *
 * Menguji:
 * 1. Menu "Diktat Tutorial" di Sidebar Dosen (includes/sidebar.php)
 * 2. Banner & Kartu Diktat di Dashboard Dosen (dosen/dashboard.php)
 * 3. Halaman Reader Diktat Dosen (dosen/diktat.php)
 */

$errors = [];
function diktatAssert(bool $condition, string $message, array &$errors): void {
    if (!$condition) {
        $errors[] = $message;
        echo " [FAIL] $message\n";
    } else {
        echo " [PASS] $message\n";
    }
}

echo "=== Menjalankan Validasi Menu & Halaman Diktat Role Dosen ===\n";

// ── 1. Validasi Sidebar Dosen ────────────────────────────────────
echo "\n--- 1. Validasi Sidebar Dosen (includes/sidebar.php) ---\n";
$sidebarFile = __DIR__ . '/../includes/sidebar.php';
diktatAssert(file_exists($sidebarFile), 'File includes/sidebar.php harus ada.', $errors);

$syntaxSidebar = shell_exec('php -l ' . escapeshellarg($sidebarFile) . ' 2>&1');
diktatAssert(strpos($syntaxSidebar, 'No syntax errors detected') !== false, 'Syntax PHP includes/sidebar.php harus valid.', $errors);

$sidebarContent = file_get_contents($sidebarFile);
diktatAssert(strpos($sidebarContent, 'dosen/diktat.php') !== false, 'Link dosen/diktat.php harus terdaftar di sidebar.', $errors);
diktatAssert(strpos($sidebarContent, 'Diktat Tutorial') !== false, 'Label teks "Diktat Tutorial" harus ada di sidebar dosen.', $errors);
diktatAssert(strpos($sidebarContent, 'menuActive(\'diktat.php\')') !== false, 'Status menuActive(\'diktat.php\') harus terpasang.', $errors);

// ── 2. Validasi Dashboard Dosen ──────────────────────────────────
echo "\n--- 2. Validasi Dashboard Dosen (dosen/dashboard.php) ---\n";
$dashboardFile = __DIR__ . '/../dosen/dashboard.php';
diktatAssert(file_exists($dashboardFile), 'File dosen/dashboard.php harus ada.', $errors);

$syntaxDashboard = shell_exec('php -l ' . escapeshellarg($dashboardFile) . ' 2>&1');
diktatAssert(strpos($syntaxDashboard, 'No syntax errors detected') !== false, 'Syntax PHP dosen/dashboard.php harus valid.', $errors);

$dashboardContent = file_get_contents($dashboardFile);
diktatAssert(strpos($dashboardContent, 'id="dosen-diktat-card"') !== false, 'Elemen kartu banner #dosen-diktat-card harus ada di dashboard dosen.', $errors);
diktatAssert(strpos($dashboardContent, 'btn-buka-diktat-dosen') !== false, 'Tombol CTA #btn-buka-diktat-dosen harus ada.', $errors);
diktatAssert(strpos($dashboardContent, 'ti-diktat-book-card') !== false, 'Elemen visual 3D buku diktat harus ada.', $errors);
diktatAssert(strpos($dashboardContent, 'Diktat Resmi LPPAI 2026') !== false, 'Judul Diktat Resmi LPPAI 2026 harus ada di dashboard.', $errors);
diktatAssert(strpos($dashboardContent, 'BUKU PEGANGAN DOSEN &amp; TUTOR') !== false || strpos($dashboardContent, 'BUKU PEGANGAN DOSEN') !== false, 'Badge pegangan dosen & tutor harus ada.', $errors);
diktatAssert(strpos($dashboardContent, 'Thaharah &amp; Wudhu Sempurna') !== false, 'Topik thaharah harus tercantum.', $errors);
diktatAssert(strpos($dashboardContent, 'Praktik Shalat Fardhu &amp; Jenazah') !== false, 'Topik shalat harus tercantum.', $errors);
diktatAssert(strpos($dashboardContent, 'Dzikir &amp; Wirid Sesudah Shalat') !== false, 'Topik dzikir harus tercantum.', $errors);
diktatAssert(strpos($dashboardContent, 'Kaidah Tajwid &amp; Tartil Al-Qur\'an') !== false, 'Topik tajwid harus tercantum.', $errors);

// ── 3. Validasi Halaman Reader Dosen (dosen/diktat.php) ──────────
echo "\n--- 3. Validasi Halaman Reader Dosen (dosen/diktat.php) ---\n";
$diktatFile = __DIR__ . '/../dosen/diktat.php';
diktatAssert(file_exists($diktatFile), 'File dosen/diktat.php harus ada.', $errors);

$syntaxDiktat = shell_exec('php -l ' . escapeshellarg($diktatFile) . ' 2>&1');
diktatAssert(strpos($syntaxDiktat, 'No syntax errors detected') !== false, 'Syntax PHP dosen/diktat.php harus valid.', $errors);

$diktatContent = file_get_contents($diktatFile);
diktatAssert(strpos($diktatContent, 'requireDosen();') !== false, 'Otorisasi requireDosen() harus dipanggil.', $errors);
diktatAssert(strpos($diktatContent, '$_SESSION[\'diktat_key\']') !== false, 'Inisialisasi kunci sesi diktat harus ada.', $errors);
diktatAssert(strpos($diktatContent, 'diktat-stream.php') !== false, 'Endpoint stream diktat-stream.php harus dirujuk.', $errors);
diktatAssert(strpos($diktatContent, 'id="dr-wrap"') !== false, 'Kontainer reader #dr-wrap harus ada.', $errors);
diktatAssert(strpos($diktatContent, 'id="dr-view"') !== false, 'Area canvas view #dr-view harus ada.', $errors);
diktatAssert(strpos($diktatContent, 'WATERMARK') !== false, 'Proteksi watermark nama dosen harus dipasang.', $errors);
diktatAssert(strpos($diktatContent, 'pdfjsLib') !== false, 'Integrasi pustaka PDF.js harus ada.', $errors);

if (!empty($errors)) {
    echo "\n❌ GAGAL: " . count($errors) . " asersi gagal.\n";
    exit(1);
} else {
    echo "\n✅ Seluruh pengujian Menu & Halaman Diktat Dosen BERHASIL 100%!\n";
}
