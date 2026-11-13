<?php
/** Test tools/tambah-admin.php (fungsi lppaiTambahAdmin) dengan SQLite in-memory. */
define('LPPAI_TAMBAH_ADMIN_LIB', true);
require __DIR__ . '/../tools/tambah-admin.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password TEXT, nama_lengkap TEXT, nim TEXT, role TEXT, is_active INT DEFAULT 0)");
$pdo->exec("INSERT INTO users (username, password, nama_lengkap, role, is_active) VALUES ('mhs1', 'x', 'Mhs', 'mahasiswa', 0)");

$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };

$r = lppaiTambahAdmin($pdo, 'adminbaru', 'rahasia', 'Admin Baru', false);
$row = $pdo->query("SELECT * FROM users WHERE username='adminbaru'")->fetch(PDO::FETCH_ASSOC);
$check($r['status'] === 'created', 'Admin baru harus dibuat');
$check($row && $row['role'] === 'admin' && (int)$row['is_active'] === 1, 'Role admin & aktif');
$check($row && password_verify('rahasia', $row['password']) && $row['password'] !== 'rahasia', 'Password harus di-hash dan cocok');

$r = lppaiTambahAdmin($pdo, 'adminbaru', 'lain', '', false);
$check($r['status'] === 'exists', 'Username yang sudah ada tanpa --reset tidak boleh diubah');
$row = $pdo->query("SELECT password FROM users WHERE username='adminbaru'")->fetch(PDO::FETCH_ASSOC);
$check(password_verify('rahasia', $row['password']), 'Password lama tetap');

$r = lppaiTambahAdmin($pdo, 'mhs1', 'baru123', '', true);
$row = $pdo->query("SELECT * FROM users WHERE username='mhs1'")->fetch(PDO::FETCH_ASSOC);
$check($r['status'] === 'updated' && $row['role'] === 'admin' && (int)$row['is_active'] === 1 && password_verify('baru123', $row['password']), '--reset menjadikan admin aktif + ganti password');

foreach (['', 'a b', 'x'] as $bad) {
    try { lppaiTambahAdmin($pdo, $bad, 'p', '', false); $check(false, "Username '$bad' harus ditolak"); } catch (InvalidArgumentException $e) {}
}
try { lppaiTambahAdmin($pdo, 'oke123', '', '', false); $check(false, 'Password kosong harus ditolak'); } catch (InvalidArgumentException $e) {}

$src = file_get_contents(__DIR__ . '/../tools/tambah-admin.php');
$check(strpos($src, "PHP_SAPI !== 'cli'") !== false, 'Skrip harus menolak akses non-CLI');
$check(is_file(__DIR__ . '/../tools/.htaccess'), 'tools/.htaccess harus memblokir akses web');

if ($failures) { echo "FAIL:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
echo "PASS: tambah-admin (buat, tolak duplikat, --reset, validasi, CLI-only) berhasil.\n";
