<?php
/**
 * LPPAI Corner - Tambah Admin Baru (KHUSUS CLI / terminal server)
 *
 * Pemakaian (jalankan dari folder root aplikasi):
 *   php tools/tambah-admin.php <username> <password> ["Nama Lengkap"] [--reset]
 *
 *   --reset  Jika username sudah ada: ubah role menjadi admin, aktifkan, dan ganti password.
 *
 * Kredensial TIDAK disimpan di file ini (repo bersifat publik); selalu dikirim sebagai argumen.
 * Skrip menolak dijalankan lewat browser.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Membuat admin baru atau (dengan $reset) menjadikan user yang ada sebagai admin.
 * @return array{status:string, id:int, message:string}
 */
function lppaiTambahAdmin(PDO $pdo, string $username, string $password, string $nama, bool $reset): array
{
    $username = trim($username);
    if ($username === '' || !preg_match('/^[A-Za-z0-9._@-]{3,100}$/', $username)) {
        throw new InvalidArgumentException('Username harus 3–100 karakter (huruf, angka, titik, garis bawah, @, atau tanda hubung).');
    }
    if ($password === '') {
        throw new InvalidArgumentException('Password tidak boleh kosong.');
    }
    $nama = trim($nama) !== '' ? trim($nama) : $username;
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if (!$reset) {
            return ['status' => 'exists', 'id' => (int)$existing['id'],
                'message' => "Username '$username' sudah ada (role: {$existing['role']}). Tambahkan --reset untuk menjadikannya admin dan mengganti password."];
        }
        $pdo->prepare("UPDATE users SET password = ?, role = 'admin', is_active = 1 WHERE id = ?")
            ->execute([$hash, (int)$existing['id']]);
        return ['status' => 'updated', 'id' => (int)$existing['id'],
            'message' => "User '$username' sekarang berstatus admin aktif dan password-nya sudah diganti."];
    }

    try {
        $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nim, role, is_active) VALUES (?, ?, ?, NULL, 'admin', 1)")
            ->execute([$username, $hash, $nama]);
    } catch (PDOException $e) {
        // Fallback bila kolom nim NOT NULL
        $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nim, role, is_active) VALUES (?, ?, ?, '', 'admin', 1)")
            ->execute([$username, $hash, $nama]);
    }
    $id = (int)$pdo->lastInsertId();
    return ['status' => 'created', 'id' => $id, 'message' => "Admin '$username' berhasil dibuat (id $id)."];
}

// Mode library (dipakai test) → jangan jalankan CLI
if (defined('LPPAI_TAMBAH_ADMIN_LIB')) return;

$args  = array_slice($argv, 1);
$reset = in_array('--reset', $args, true);
$args  = array_values(array_filter($args, fn($a) => $a !== '--reset'));

if (count($args) < 2) {
    fwrite(STDERR, "Pemakaian: php tools/tambah-admin.php <username> <password> [\"Nama Lengkap\"] [--reset]\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
    $result = lppaiTambahAdmin($pdo, $args[0], $args[1], $args[2] ?? '', $reset);
    echo ($result['status'] === 'exists' ? '[!] ' : '[OK] ') . $result['message'] . PHP_EOL;
    if ($result['status'] !== 'exists') {
        echo "Login di: " . (defined('BASE_URL') ? BASE_URL : '') . "/index.php" . PHP_EOL;
    }
    exit($result['status'] === 'exists' ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, '[GAGAL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
