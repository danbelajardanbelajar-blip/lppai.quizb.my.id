<?php
/**
 * Test: Verifikasi Tema "Emerald Royale & Champagne Prestige" (Fase 1 & Fase 2)
 */

$cssPath = __DIR__ . '/../assets/css/style.css';
$headerPath = __DIR__ . '/../includes/header.php';
$sidebarPath = __DIR__ . '/../includes/sidebar.php';
$footerPath = __DIR__ . '/../includes/footer.php';

// 1. Verifikasi keterbacaan file
assert(file_exists($cssPath), "File assets/css/style.css harus ada");
assert(file_exists($headerPath), "File includes/header.php harus ada");
assert(file_exists($sidebarPath), "File includes/sidebar.php harus ada");
assert(file_exists($footerPath), "File includes/footer.php harus ada");

$css = file_get_contents($cssPath);
$header = file_get_contents($headerPath);
$sidebar = file_get_contents($sidebarPath);
$footer = file_get_contents($footerPath);

// 2. Verifikasi Font & Token Warna di style.css
assert(strpos($css, 'Plus+Jakarta+Sans') !== false, "Font Plus Jakarta Sans harus diimpor di style.css");
assert(strpos($css, '--primary: #0b3b22;') !== false, "Token --primary harus menggunakan Obsidian Emerald (#0b3b22)");
assert(strpos($css, '--secondary: #c59b27;') !== false, "Token --secondary harus menggunakan Champagne Gold (#c59b27)");
assert(strpos($css, '--bg-light: #f8fafc;') !== false, "Token --bg-light harus menggunakan canvas bersih (#f8fafc)");

// 3. Verifikasi Topbar Frosted Glass
assert(strpos($css, 'backdrop-filter: blur(16px)') !== false, "Topbar harus memiliki efek frosted glass backdrop-filter");
assert(strpos($css, '.topbar .user-info .avatar') !== false, "Avatar topbar harus memiliki styling luxury ring");

// 4. Verifikasi Sidebar Obsidian Royale
assert(strpos($css, 'linear-gradient(180deg, #051b10 0%, #082617 40%, #0d3822 100%)') !== false, "Sidebar harus menggunakan gradient Obsidian Forest");
assert(strpos($sidebar, 'LPPAI Core &bull; Online') !== false, "Sidebar harus memiliki status pill indikator");

// 5. Verifikasi Header & Footer Modernization
assert(strpos($header, 'Plus+Jakarta+Sans') !== false, "Header harus memuat link Google Fonts Plus Jakarta Sans");
assert(strpos($footer, 'v2.0 Premium') !== false, "Footer harus memuat branding terpadu");

echo "✅ PASS: Verifikasi Tema 'Emerald Royale & Champagne Prestige' (Fase 1 & Fase 2) BERHASIL 100%!\n";
