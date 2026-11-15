<?php
/**
 * Test: Verifikasi Tata Letak Sidebar Modern, Cantik, & Compact (Accordion Groups)
 */

$root = dirname(__DIR__);
$sidebarFile = $root . '/includes/sidebar.php';
$cssFile = $root . '/assets/css/style.css';
$jsFile = $root . '/assets/js/app.js';

assert(file_exists($sidebarFile), "includes/sidebar.php harus ada");
assert(file_exists($cssFile), "assets/css/style.css harus ada");
assert(file_exists($jsFile), "assets/js/app.js harus ada");

$sidebar = file_get_contents($sidebarFile);
$css = file_get_contents($cssFile);
$js = file_get_contents($jsFile);

echo "=== 1. Verifikasi Elemen & Struktur Sidebar Accordion ===\n";
assert(strpos($sidebar, 'class="nav-group') !== false, "[FAIL] Sidebar harus mendefinisikan .nav-group");
assert(strpos($sidebar, 'class="nav-group-toggle') !== false, "[FAIL] Sidebar harus memiliki tombol .nav-group-toggle");
assert(strpos($sidebar, 'class="nav-badge"') !== false, "[FAIL] Sidebar harus memiliki badge counter pada group");
assert(strpos($sidebar, 'class="chevron"') !== false, "[FAIL] Sidebar harus memiliki chevron indikator");
assert(strpos($sidebar, 'class="nav-group-content"') !== false, "[FAIL] Sidebar harus memiliki .nav-group-content");
assert(strpos($sidebar, 'data-group="admin-pretes"') !== false, "[FAIL] Group admin-pretes harus ada");
assert(strpos($sidebar, 'data-group="admin-tutorial"') !== false, "[FAIL] Group admin-tutorial harus ada");
assert(strpos($sidebar, 'data-group="admin-keuangan"') !== false, "[FAIL] Group admin-keuangan harus ada");
assert(strpos($sidebar, 'data-group="admin-pengguna"') !== false, "[FAIL] Group admin-pengguna harus ada");
assert(strpos($sidebar, 'data-group="admin-administrasi"') !== false, "[FAIL] Group admin-administrasi harus ada");
assert(strpos($sidebar, 'data-group="admin-sistem"') !== false, "[FAIL] Group admin-sistem harus ada");
assert(strpos($sidebar, 'data-group="menu-informasi"') !== false, "[FAIL] Group menu-informasi harus ada");
assert(strpos($sidebar, 'data-group="menu-akun"') !== false, "[FAIL] Group menu-akun harus ada");
echo " [PASS] Seluruh 8 group accordion terdefinisi secara rapi dan terstruktur.\n";

echo "\n=== 2. Verifikasi CSS Styling (Emerald Royale Compact) ===\n";
assert(strpos($css, '.nav-group-toggle') !== false, "[FAIL] style.css harus mendefinisikan .nav-group-toggle");
assert(strpos($css, '.nav-badge') !== false, "[FAIL] style.css harus mendefinisikan .nav-badge");
assert(strpos($css, '.chevron') !== false, "[FAIL] style.css harus mendefinisikan .chevron");
assert(strpos($css, '.nav-group.open > .nav-group-toggle .chevron') !== false, "[FAIL] style.css harus memiliki animasi chevron rotasi 180deg");
assert(strpos($css, '.nav-group-content') !== false, "[FAIL] style.css harus mendefinisikan .nav-group-content");
assert(strpos($css, 'border-left: 1.5px solid rgba(255, 255, 255, 0.12)') !== false, "[FAIL] style.css harus memiliki tree guide line");
assert(strpos($css, 'sidebarSubmenuFadeIn') !== false, "[FAIL] style.css harus memiliki animasi fade-in transisi");
echo " [PASS] Styling luxury Emerald Royale & compact layout CSS terverifikasi 100% sempurna.\n";

echo "\n=== 3. Verifikasi SPA updateNav & Auto-Expand Group ===\n";
assert(strpos($js, "link.closest('.nav-group')") !== false, "[FAIL] app.js updateNav harus mendeteksi parent .nav-group");
assert(strpos($js, "navGroup.classList.add('open')") !== false, "[FAIL] app.js updateNav harus otomatis membuka parent .nav-group");
assert(strpos($js, "other.classList.remove('open')") !== false, "[FAIL] app.js updateNav harus menutup sibling groups agar tetap compact");
echo " [PASS] Integrasi SPA client routing & auto-expand group terverifikasi akurat.\n";

echo "\n=== 4. Verifikasi Status Online Pill & Menu Esensial ===\n";
assert(strpos($sidebar, 'LPPAI Core &bull; Online') !== false, "[FAIL] Status pill harus tetap ada");
assert(strpos($sidebar, 'profil.php') !== false, "[FAIL] Tautan profil.php harus ada");
assert(strpos($sidebar, 'pengaturan.php') !== false, "[FAIL] Tautan pengaturan.php harus ada");
assert(strpos($sidebar, 'logout.php') !== false, "[FAIL] Tautan logout.php harus ada");
echo " [PASS] Status pill dan tautan profil/pengaturan/logout lengkap.\n";

echo "\n✅ Seluruh pengujian sidebar cantik dan compact BERHASIL 100%!\n";
