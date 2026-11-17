<?php
/**
 * Test Suite: Pembuatan dan Verifikasi Akun Admin 'ali' (Password: 123)
 */

require_once __DIR__ . '/../includes/user-helper.php';

echo "=== STARTING ADMIN ALI ACCOUNT TEST ===\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        nama_lengkap TEXT NOT NULL,
        nim TEXT,
        role TEXT NOT NULL DEFAULT 'mahasiswa',
        is_active INTEGER DEFAULT 1,
        program_studi TEXT,
        jenis_program TEXT DEFAULT 'Reguler',
        fakultas TEXT,
        angkatan TEXT,
        foto TEXT,
        no_hp TEXT
    );
");

// 1. Test creation of admin 'ali'
echo "1. Testing ensureAdminUser() creation...\n";
$res1 = ensureAdminUser($pdo, 'ali', '123', 'Ali');
assert($res1 === true, 'ensureAdminUser must return true');

$stmt = $pdo->prepare("SELECT * FROM users WHERE username = 'ali'");
$stmt->execute();
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

assert(!empty($admin), "User 'ali' must exist in database");
assert($admin['username'] === 'ali', "Username must be 'ali'");
assert($admin['nama_lengkap'] === 'Ali', "Nama lengkap must be 'Ali'");
assert($admin['role'] === 'admin', "Role must be 'admin'");
assert((int)$admin['is_active'] === 1, "is_active must be 1");
assert(password_verify('123', $admin['password']), "Password hash must verify with '123'");
assert(!password_verify('wrongpass', $admin['password']), "Password hash must reject wrong password");
echo " -> Creation test PASS\n";

// 2. Test idempotency (calling again should not create duplicate or change correctly set account)
echo "2. Testing ensureAdminUser() idempotency...\n";
$res2 = ensureAdminUser($pdo, 'ali', '123', 'Ali');
assert($res2 === true, 'Subsequent ensureAdminUser must return true');

$stmtCount = $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'ali'");
assert((int)$stmtCount->fetchColumn() === 1, "There must be exactly 1 user with username 'ali'");
echo " -> Idempotency test PASS\n";

// 3. Test upgrade of existing non-admin user
echo "3. Testing ensureAdminUser() update of existing user...\n";
$pdo->exec("INSERT INTO users (username, password, nama_lengkap, role, is_active) VALUES ('other_admin', 'oldhash', 'Old Name', 'mahasiswa', 0)");
$res3 = ensureAdminUser($pdo, 'other_admin', 'secret123', 'New Admin Name');
assert($res3 === true, 'Upgrading user must return true');

$stmtUp = $pdo->prepare("SELECT * FROM users WHERE username = 'other_admin'");
$stmtUp->execute();
$upgraded = $stmtUp->fetch(PDO::FETCH_ASSOC);

assert($upgraded['role'] === 'admin', "Upgraded user must have role 'admin'");
assert((int)$upgraded['is_active'] === 1, "Upgraded user must be active (1)");
assert(password_verify('secret123', $upgraded['password']), "Upgraded user password must match 'secret123'");
echo " -> User update test PASS\n";

// 4. Test login simulation with admin 'ali'
echo "4. Testing login logic for admin 'ali'...\n";
if (!isset($_SESSION)) {
    $_SESSION = [];
}

$stmtLogin = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
$stmtLogin->execute(['username' => 'ali']);
$userLogin = $stmtLogin->fetch(PDO::FETCH_ASSOC);

assert($userLogin && password_verify('123', $userLogin['password']), "Credentials for 'ali' / '123' must be valid");

$_SESSION['user_id'] = $userLogin['id'];
$_SESSION['username'] = $userLogin['username'];
$_SESSION['nama_lengkap'] = $userLogin['nama_lengkap'];
$_SESSION['role'] = $userLogin['role'];
$_SESSION['is_active'] = (int)$userLogin['is_active'];

assert($_SESSION['role'] === 'admin', "Session role must be 'admin'");
assert($_SESSION['is_active'] === 1, "Session is_active must be 1");
echo " -> Login logic test PASS\n";

echo "=== ALL ADMIN ALI ACCOUNT TESTS PASSED 100% ===\n";
