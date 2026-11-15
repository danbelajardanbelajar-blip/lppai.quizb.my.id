<?php
/**
 * Test kolom password Kelola Pengguna (admin/ajax-users.php + admin/users.php):
 * password tertutup •••, tombol 👁️ tampil/sembunyi, tombol 📋 Salin, klik teks = salin,
 * dan status password bawaan (masih berlaku / sudah diganti / belum dicek).
 */
$root = dirname(__DIR__);
$ajax = file_get_contents("$root/admin/ajax-users.php");
$page = file_get_contents("$root/admin/users.php");
$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };

// Jalankan blok pembentuk kotak password dengan data contoh
$check(preg_match("/\/\/ Password Box: tertutup.*?\n    \}\n/s", $ajax, $m) === 1, 'Blok kotak password ditemukan');
$render = function (string $passwordLogin, string $passStatus) use ($m): string {
    $safePass = htmlspecialchars($passwordLogin, ENT_QUOTES, 'UTF-8');
    $maskPass = str_repeat('•', strlen($passwordLogin));
    $aksi = '';
    eval($m[0]);
    return $aksi;
};
$def = $render('01031990', 'default');
$check(strpos($def, 'data-pass="01031990"') !== false && strpos($def, '>••••••••<') !== false, 'Password tertutup •••, nilai asli di data-pass');
$check(strpos($def, 'toggleUserPass(this)') !== false && strpos($def, '👁️') !== false, 'Ada tombol mata');
$check(strpos($def, 'class="btn-copy-pass"') !== false && strpos($def, '📋 Salin') !== false, 'Ada tombol Salin');
$check(substr_count($def, 'copyUserPass(this)') >= 2, 'Klik teks password dan tombol Salin sama-sama menyalin');
$check(strpos($def, '#86efac') !== false && strpos($def, 'Password bawaan masih berlaku') !== false, 'Status bawaan terverifikasi ditandai');
$unk = $render('123456', 'unknown');
$check(strpos($unk, 'belum dicek') !== false && strpos($unk, 'btn-copy-pass') !== false, 'Belum dicek tetap bisa disalin dengan keterangan');
$chg = $render('01011990', 'changed');
$check(strpos($chg, 'Sudah diganti') !== false && strpos($chg, 'data-pass') === false && strpos($chg, 'btn-copy-pass') === false, 'Password yang sudah diganti tidak ditampilkan/disalin');

// Logika verifikasi hash
$check(strpos($ajax, 'password_verify($passwordLogin, (string)$row[\'password\'])') !== false, 'Password bawaan dicocokkan dengan hash');
$check(strpos($ajax, '$length <= 25') !== false, 'Verifikasi dibatasi ≤ 25 baris per halaman');
$hash = password_hash('01031990', PASSWORD_DEFAULT);
$check(password_verify('01031990', $hash) && !password_verify('01011990', $hash), 'password_verify membedakan bawaan vs diganti');

// JS halaman
$check(strpos($page, 'window.copyUserPass = function') !== false, 'Fungsi copyUserPass ada');
$check(strpos($page, 'navigator.clipboard.writeText') !== false && strpos($page, "document.execCommand('copy')") !== false, 'Clipboard API + fallback execCommand');
$check(strpos($page, 'Password disalin ke clipboard.') !== false, 'Notifikasi berhasil disalin');

if ($failures) { echo "FAIL:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
echo "PASS: kolom password Kelola Pengguna (tertutup, mata, salin, klik-salin, status bawaan/diganti).\n";
