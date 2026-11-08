<?php
/**
 * Test: Verifikasi Tema "Emerald Royale & Champagne Prestige" (Fase 1 s.d. Fase 4)
 */

$cssPath = __DIR__ . '/../assets/css/style.css';
$headerPath = __DIR__ . '/../includes/header.php';
$sidebarPath = __DIR__ . '/../includes/sidebar.php';
$footerPath = __DIR__ . '/../includes/footer.php';
$dashMhsPath = __DIR__ . '/../pages/dashboard.php';
$dashAdminPath = __DIR__ . '/../admin/dashboard.php';
$dashDosenPath = __DIR__ . '/../dosen/dashboard.php';

// 1. Verifikasi keterbacaan file
assert(file_exists($cssPath), "File assets/css/style.css harus ada");
assert(file_exists($headerPath), "File includes/header.php harus ada");
assert(file_exists($sidebarPath), "File includes/sidebar.php harus ada");
assert(file_exists($footerPath), "File includes/footer.php harus ada");
assert(file_exists($dashMhsPath), "File pages/dashboard.php harus ada");
assert(file_exists($dashAdminPath), "File admin/dashboard.php harus ada");
assert(file_exists($dashDosenPath), "File dosen/dashboard.php harus ada");

$css = file_get_contents($cssPath);
$header = file_get_contents($headerPath);
$sidebar = file_get_contents($sidebarPath);
$footer = file_get_contents($footerPath);
$dashMhs = file_get_contents($dashMhsPath);
$dashAdmin = file_get_contents($dashAdminPath);
$dashDosen = file_get_contents($dashDosenPath);

// 2. Verifikasi Font & Token Warna di style.css (Fase 1)
assert(strpos($css, 'Plus+Jakarta+Sans') !== false, "Font Plus Jakarta Sans harus diimpor di style.css");
assert(strpos($css, '--primary: #0b3b22;') !== false, "Token --primary harus menggunakan Obsidian Emerald (#0b3b22)");
assert(strpos($css, '--secondary: #c59b27;') !== false, "Token --secondary harus menggunakan Champagne Gold (#c59b27)");
assert(strpos($css, '--bg-light: #f8fafc;') !== false, "Token --bg-light harus menggunakan canvas bersih (#f8fafc)");

// 3. Verifikasi Topbar Frosted Glass & Sidebar Obsidian Royale (Fase 2)
assert(strpos($css, 'backdrop-filter: blur(16px)') !== false, "Topbar harus memiliki efek frosted glass backdrop-filter");
assert(strpos($css, '.topbar .user-info .avatar') !== false, "Avatar topbar harus memiliki styling luxury ring");
assert(strpos($css, 'linear-gradient(180deg, #051b10 0%, #082617 40%, #0d3822 100%)') !== false, "Sidebar harus menggunakan gradient Obsidian Forest");
assert(strpos($sidebar, 'LPPAI Core &bull; Online') !== false, "Sidebar harus memiliki status pill indikator");
assert(strpos($header, 'Plus+Jakarta+Sans') !== false, "Header harus memuat link Google Fonts Plus Jakarta Sans");
assert(strpos($footer, 'v2.0 Premium') !== false, "Footer harus memuat branding terpadu");

// 4. Verifikasi Komponen Executive Cards, Stat Metrics & Quick Actions (Fase 3 & Fase 4)
assert(strpos($css, '.welcome-card') !== false, "CSS style.css harus mendefinisikan .welcome-card");
assert(strpos($css, '.welcome-chip') !== false, "CSS style.css harus mendefinisikan .welcome-chip");
assert(strpos($css, '.segmented-control') !== false, "CSS style.css harus mendefinisikan .segmented-control");
assert(strpos($css, '.quick-action-btn') !== false, "CSS style.css harus mendefinisikan .quick-action-btn");
assert(strpos($css, '.badge-gold') !== false, "CSS style.css harus mendefinisikan .badge-gold");
assert(strpos($css, '.badge-emerald') !== false, "CSS style.css harus mendefinisikan .badge-emerald");
assert(strpos($css, '.modal-backdrop') !== false, "CSS style.css harus mendefinisikan .modal-backdrop");
assert(strpos($css, '.modal-content') !== false, "CSS style.css harus mendefinisikan .modal-content");
assert(strpos($css, '.modal-backdrop.show') !== false, "CSS style.css harus mendefinisikan .modal-backdrop.show");

// 5. Verifikasi Integrasi di Dashboard Mahasiswa, Admin, & Dosen
assert(strpos($dashMhs, 'welcome-card') !== false, "Dashboard Mahasiswa harus menggunakan .welcome-card");
assert(strpos($dashMhs, 'welcome-chip') !== false, "Dashboard Mahasiswa harus menggunakan .welcome-chip");
assert(strpos($dashAdmin, 'welcome-card') !== false, "Dashboard Admin harus menggunakan .welcome-card");
assert(strpos($dashAdmin, 'quick-action-btn') !== false, "Dashboard Admin harus menggunakan .quick-action-btn");
assert(strpos($dashDosen, 'welcome-card') !== false, "Dashboard Dosen harus menggunakan .welcome-card");
assert(strpos($dashDosen, 'welcome-chip') !== false, "Dashboard Dosen harus menggunakan .welcome-chip");

echo "✅ PASS: Verifikasi Tema 'Emerald Royale & Champagne Prestige' (Fase 1 s.d. Fase 4) BERHASIL 100%!\n";
