<?php
/**
 * Test: CSRF Protection & Access Control on dosen/tutorial-rpl.php
 */
$php = "d:\\all_project\\lppai.quizb.my.id\\scratch\\php-runtime\\php.exe";
$dir = sys_get_temp_dir() . '/test-dosen-csrf-' . bin2hex(random_bytes(6));
mkdir($dir . '/dosen', 0777, true);
mkdir($dir . '/includes', 0777, true);

// Buat basis data SQLite tiruan
$pdo = new PDO('sqlite:' . $dir . '/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        username TEXT,
        password TEXT,
        role TEXT,
        nama_lengkap TEXT,
        nim TEXT,
        program_studi TEXT,
        fakultas TEXT,
        no_hp TEXT,
        whatsapp TEXT,
        jenis_program TEXT
    );
    CREATE TABLE tutorial_rpl_classes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nama_kelompok TEXT,
        periode TEXT,
        gelombang TEXT,
        dosen_id INTEGER,
        dosen_nim TEXT,
        dosen_nama TEXT,
        dosen_wa TEXT,
        drive_url TEXT,
        kuota INTEGER DEFAULT 40,
        catatan TEXT,
        is_active INTEGER DEFAULT 1
    );
    CREATE TABLE tutorial_rpl_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        class_id INTEGER,
        user_id INTEGER,
        drive_submitted INTEGER DEFAULT 0,
        drive_verified INTEGER DEFAULT 0,
        exam_submitted INTEGER DEFAULT 0,
        nilai_ujian_tulis REAL,
        nilai_praktik REAL,
        nilai_akhir REAL,
        status_lulus TEXT DEFAULT 'belum',
        drive_notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    INSERT INTO users (id, username, role, nama_lengkap, nim) VALUES (10, 'dosen1', 'dosen', 'Dr. Zaid', '0010');
    INSERT INTO users (id, username, role, nama_lengkap, nim) VALUES (11, 'dosen2', 'dosen', 'Dr. Umar', '0011');
    INSERT INTO users (id, username, role, nama_lengkap, nim) VALUES (101, 'mhs1', 'mahasiswa', 'Ahmad', '202601');
    INSERT INTO users (id, username, role, nama_lengkap, nim) VALUES (102, 'mhs2', 'mahasiswa', 'Budi', '202602');
    INSERT INTO users (id, username, role, nama_lengkap, nim) VALUES (103, 'mhs3', 'mahasiswa', 'Citra', '202603');

    INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, gelombang, dosen_id, dosen_nim, dosen_nama, is_active)
    VALUES (1, 'Kelompok RPL 01', '2026/2027', 'Gelombang 1', 10, '0010', 'Dr. Zaid', 1);

    INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, gelombang, dosen_id, dosen_nim, dosen_nama, is_active)
    VALUES (2, 'Kelompok RPL 02', '2026/2027', 'Gelombang 1', 11, '0011', 'Dr. Umar', 1);

    INSERT INTO tutorial_rpl_members (id, class_id, user_id, drive_submitted, drive_verified, exam_submitted, nilai_praktik)
    VALUES (1, 1, 101, 1, 0, 1, NULL);
    INSERT INTO tutorial_rpl_members (id, class_id, user_id, drive_submitted, drive_verified, exam_submitted, nilai_praktik)
    VALUES (2, 1, 102, 1, 0, 0, NULL);
    INSERT INTO tutorial_rpl_members (id, class_id, user_id, drive_submitted, drive_verified, exam_submitted, nilai_praktik)
    VALUES (3, 2, 103, 1, 0, 1, NULL);
");

// Salin berkas asli dan stub auth
copy(__DIR__ . '/../dosen/tutorial-rpl.php', $dir . '/dosen/tutorial-rpl.php');
copy(__DIR__ . '/../includes/tutorial-rpl-helper.php', $dir . '/includes/tutorial-rpl-helper.php');

file_put_contents($dir . '/includes/auth.php', <<<'PHP'
<?php
define('BASE_URL', '');
define('APP_NAME', 'LPPAI Corner');
function getCurrentUser() {
    return $GLOBALS['request']['actor'] ?? ['id' => 10, 'role' => 'dosen', 'nim' => '0010', 'nama_lengkap' => 'Dr. Zaid'];
}
function requireDosen() {
    if ((getCurrentUser()['role'] ?? '') !== 'dosen') {
        http_response_code(403);
        echo 'Akses ditolak';
        exit;
    }
}
function getDBConnection() {
    return new PDO('sqlite:' . __DIR__ . '/../test.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}
function csrfToken() { return 'valid-csrf-token'; }
function verifyCsrf($token) { return is_string($token) && $token === 'valid-csrf-token'; }
function sanitize($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
PHP
);

file_put_contents($dir . '/includes/header.php', '<!doctype html><html><body>');
file_put_contents($dir . '/includes/footer.php', '</body></html>');

file_put_contents($dir . '/runner.php', <<<'PHP'
<?php
$request = json_decode($argv[1], true);
$_POST = $request['post'] ?? [];
$_GET = $request['get'] ?? [];
$_SERVER['REQUEST_METHOD'] = $request['method'] ?? 'GET';
http_response_code(200);
ob_start();
register_shutdown_function(function() {
    echo json_encode([
        'status' => http_response_code(),
        'html' => ob_get_clean()
    ]);
});
include __DIR__ . '/dosen/tutorial-rpl.php';
PHP
);

function executeRequest(string $phpPath, string $dir, array $payload): array {
    $pipes = [];
    $p = proc_open([$phpPath, $dir . '/runner.php', json_encode($payload)], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    if ($code !== 0 || $err !== '') {
        throw new RuntimeException("Runner error: $err, output: $out");
    }
    return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

$failures = [];
$check = function(bool $condition, string $desc) use (&$failures) {
    if ($condition) {
        echo "[PASS] $desc\n";
    } else {
        echo "[FAIL] $desc\n";
        $failures[] = $desc;
    }
};

try {
    echo "=== TEST: CSRF & Access Control dosen/tutorial-rpl.php ===\n";

    // 1. GET page normal
    $res = executeRequest($php, $dir, ['method' => 'GET']);
    $check($res['status'] === 200, 'GET dosen/tutorial-rpl.php status 200');
    $check(str_contains($res['html'], 'name="csrf_token"'), 'Halaman merender input csrf_token');
    $check(str_contains($res['html'], 'Kelompok RPL 01'), 'Halaman menampilkan kelas bimbingan dosen login');
    $check(!str_contains($res['html'], 'Kelompok RPL 02'), 'Halaman tidak menampilkan kelas dosen lain');

    // 2. POST save_evaluation TANPA CSRF -> Ditolak
    $res = executeRequest($php, $dir, [
        'method' => 'POST',
        'post' => [
            'action' => 'save_evaluation',
            'member_id' => 1,
            'drive_verified' => 1,
            'nilai_praktik' => 88.5
        ]
    ]);
    $check(str_contains($res['html'], 'tidak valid atau telah kedaluwarsa'), 'POST evaluasi tanpa CSRF ditolak');
    $val = $pdo->query("SELECT nilai_praktik FROM tutorial_rpl_members WHERE id = 1")->fetchColumn();
    $check($val === null, 'Nilai tidak tersimpan saat CSRF gagal');

    // 3. POST save_evaluation DENGAN CSRF SALAH -> Ditolak
    $res = executeRequest($php, $dir, [
        'method' => 'POST',
        'post' => [
            'action' => 'save_evaluation',
            'csrf_token' => 'invalid-token',
            'member_id' => 1,
            'drive_verified' => 1,
            'nilai_praktik' => 88.5
        ]
    ]);
    $check(str_contains($res['html'], 'tidak valid atau telah kedaluwarsa'), 'POST evaluasi dengan CSRF salah ditolak');

    // 4. POST save_evaluation DENGAN CSRF VALID -> Berhasil
    $res = executeRequest($php, $dir, [
        'method' => 'POST',
        'post' => [
            'action' => 'save_evaluation',
            'csrf_token' => 'valid-csrf-token',
            'member_id' => 1,
            'drive_verified' => 1,
            'nilai_praktik' => 88.5,
            'drive_notes' => 'Makhraj huruf fasih dan lancar'
        ]
    ]);
    $check(str_contains($res['html'], 'berhasil disimpan'), 'POST evaluasi dengan CSRF valid berhasil');
    $row = $pdo->query("SELECT nilai_praktik, drive_verified, drive_notes FROM tutorial_rpl_members WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $check((float)$row['nilai_praktik'] === 88.5, 'Nilai praktik 88.5 tersimpan persisten');
    $check((int)$row['drive_verified'] === 1, 'Status drive_verified tersimpan 1');
    $check($row['drive_notes'] === 'Makhraj huruf fasih dan lancar', 'Catatan evaluasi tersimpan persisten');

    // 5. POST save_evaluation anggota kelas lain -> Ditolak (Cross-Class Protection)
    $res = executeRequest($php, $dir, [
        'method' => 'POST',
        'post' => [
            'action' => 'save_evaluation',
            'csrf_token' => 'valid-csrf-token',
            'member_id' => 3, // Anggota di kelas 2 (milik dosen2)
            'drive_verified' => 1,
            'nilai_praktik' => 95.0
        ]
    ]);
    $check(str_contains($res['html'], 'Akses ditolak'), 'POST evaluasi anggota kelas dosen lain ditolak');
    $valOther = $pdo->query("SELECT nilai_praktik FROM tutorial_rpl_members WHERE id = 3")->fetchColumn();
    $check($valOther === null, 'Nilai anggota kelas lain tidak berubah');

    // 6. POST batch_verify TANPA CSRF -> Ditolak
    $res = executeRequest($php, $dir, [
        'method' => 'POST',
        'post' => [
            'action' => 'batch_verify',
            'member_ids' => [1, 2],
            'batch_status' => 1
        ]
    ]);
    $check(str_contains($res['html'], 'tidak valid atau telah kedaluwarsa'), 'POST batch_verify tanpa CSRF ditolak');

    // 7. POST batch_verify DENGAN CSRF VALID -> Berhasil
    $res = executeRequest($php, $dir, [
        'method' => 'POST',
        'post' => [
            'action' => 'batch_verify',
            'csrf_token' => 'valid-csrf-token',
            'member_ids' => [1, 2],
            'batch_status' => 1
        ]
    ]);
    $check(str_contains($res['html'], 'Berhasil memperbarui'), 'POST batch_verify dengan CSRF valid berhasil');
    $v2 = (int)$pdo->query("SELECT drive_verified FROM tutorial_rpl_members WHERE id = 2")->fetchColumn();
    $check($v2 === 1, 'Anggota 2 terverifikasi lewat batch_verify');

    // 8. Cek tester bypass tidak ada lagi (akun dengan nim 12345 tidak otomatis melihat semua kelas)
    $resTester = executeRequest($php, $dir, [
        'method' => 'GET',
        'actor' => ['id' => 99, 'role' => 'dosen', 'nim' => '12345', 'nama_lengkap' => 'Tester']
    ]);
    $check(!str_contains($resTester['html'], 'Kelompok RPL 01') && !str_contains($resTester['html'], 'Kelompok RPL 02'), 'Akun 12345 tidak lagi membypass daftar kelas');

} finally {
    // Bersihkan file temp
    $pdo = null;
    @unlink($dir . '/test.sqlite');
    @unlink($dir . '/dosen/tutorial-rpl.php');
    @unlink($dir . '/includes/tutorial-rpl-helper.php');
    @unlink($dir . '/includes/auth.php');
    @unlink($dir . '/includes/header.php');
    @unlink($dir . '/includes/footer.php');
    @unlink($dir . '/runner.php');
    @rmdir($dir . '/dosen');
    @rmdir($dir . '/includes');
    @rmdir($dir);
}

if (!empty($failures)) {
    echo "\nTotal failures: " . count($failures) . "\n";
    exit(1);
}
echo "\nSemua pengujian CSRF & Hak Akses dosen/tutorial-rpl.php BERHASIL!\n";
exit(0);
