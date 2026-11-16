<?php
/**
 * Test: Mahasiswa yang sudah menyelesaikan ujian CBT resmi pretes di pages/dashboard.php
 * Memastikan:
 * 1. Banner wajib tryout disembunyikan bagi mahasiswa yang sudah mengumpulkan CBT.
 * 2. Kartu "Jadwal & Status Pretes Anda" disembunyikan setelah CBT selesai dikerjakan.
 * 3. Stat card menampilkan status CBT: "✅ Lolos CBT (XX)" jika >= 80 atau "❌ Lanjut Tutorial (XX)" jika < 80.
 * 4. Kartu hasil CBT pretes resmi ditampilkan menggantikan jadwal/akses ujian.
 * 5. Untuk nilai < 80, menampilkan arahan mendaftar Tutorial RPL dengan link ke /tutorial-rpl.php.
 * 6. Untuk nilai >= 80, menampilkan instruksi tugas online RPL dan link Google Drive.
 */

$temporary = sys_get_temp_dir() . '/dashboard-cbt-' . bin2hex(random_bytes(6));
mkdir($temporary . '/pages', 0777, true);
mkdir($temporary . '/includes', 0777, true);
mkdir($temporary . '/data', 0777, true);

$copyFiles = [
    'pages/dashboard.php',
    'includes/pretes-helper.php',
    'includes/pretes-components.php',
    'includes/user-helper.php',
    'includes/tutorial-membership.php',
    'includes/announcement-helper.php',
    'includes/pretes-cbt.php',
    'includes/pretes-cbt-interruption.php',
    'includes/pretes-cbt-result-card.php',
    'includes/pretes-exam-access.php',
    'includes/pretes-tryout.php',
    'includes/pretes-examiner.php',
    'includes/pretes-face-engine.php',
    'data/pretes-cbt-bank.php'
];

foreach ($copyFiles as $file) {
    if (file_exists(__DIR__ . '/../' . $file)) {
        copy(__DIR__ . '/../' . $file, $temporary . '/' . $file);
    }
}

// Mock auth.php, header.php, footer.php
file_put_contents($temporary . '/includes/auth.php', '<?php
if (!defined("BASE_URL")) define("BASE_URL", "https://example.test");
function requireLogin() {}
function getCurrentUser() { global $currentUserMock; return $currentUserMock; }
function getDBConnection() { global $pdoFixture; return $pdoFixture; }
function sanitize($v) { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }
function formatDate($d) { return $d ?: "-"; }
function isAdmin() { global $currentUserMock; return ($currentUserMock["role"] ?? "") === "admin"; }
function isDosen() { global $currentUserMock; return ($currentUserMock["role"] ?? "") === "dosen"; }
function isMahasiswa() { global $currentUserMock; return ($currentUserMock["role"] ?? "") === "mahasiswa"; }
function getActivePretesPeriod($pdo) {
    return $pdo->query("SELECT * FROM master_pretes WHERE status = \'aktif\' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
}
function isUserEligibleForPretesPeriod($user, $period) {
    return true;
}
function countPretesRegistrationsForPeriod($pdo, $userId, $periodId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pretes_registrations WHERE user_id = ? AND periode = ?");
    $stmt->execute([$userId, $periodId]);
    return (int)$stmt->fetchColumn();
}
function renderDosenWithWa(...$args) { return "Menunggu pembagian"; }
');

file_put_contents($temporary . '/includes/header.php', '<!-- MOCK_HEADER -->');
file_put_contents($temporary . '/includes/footer.php', '<!-- MOCK_FOOTER -->');

$pdoFixture = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Buat skema tabel yang dibutuhkan
$pdoFixture->exec("
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    nama_lengkap TEXT,
    nim TEXT,
    username TEXT,
    role TEXT,
    jenis_program TEXT,
    program_studi TEXT,
    fakultas TEXT
);

CREATE TABLE master_pretes (
    id INTEGER PRIMARY KEY,
    periode TEXT,
    status TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    penguji_senin TEXT,
    penguji_selasa TEXT,
    penguji_rabu TEXT,
    penguji_kamis TEXT,
    penguji_jumat TEXT
);

CREATE TABLE pretes_registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    periode TEXT,
    hari_pilihan TEXT,
    jam TEXT,
    ruangan TEXT,
    dosen_penguji TEXT,
    pretes_schedule_id INTEGER,
    status TEXT,
    tanggal_daftar TEXT DEFAULT CURRENT_TIMESTAMP,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE pretes_results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    registration_id INTEGER,
    pretes_schedule_id INTEGER,
    status_lulus TEXT,
    nilai REAL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE pretes_schedules (
    id INTEGER PRIMARY KEY,
    periode TEXT,
    tanggal TEXT,
    waktu_mulai TEXT,
    waktu_selesai TEXT,
    ruangan TEXT
);

CREATE TABLE master_gelombang (
    id INTEGER PRIMARY KEY,
    nama_gelombang TEXT,
    status TEXT DEFAULT 'nonaktif',
    is_active INTEGER DEFAULT 1
);

CREATE TABLE pretes_attendance_records (
    id INTEGER PRIMARY KEY,
    registration_id INTEGER,
    attended_at TEXT
);

CREATE TABLE registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    kelas_id INTEGER,
    hari_pilihan TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE master_kelas (
    id INTEGER PRIMARY KEY,
    nama_kelas TEXT,
    hari TEXT,
    jam TEXT,
    ruangan TEXT,
    dosen_id INTEGER
);

CREATE TABLE tutorial_registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    tutorial_class_id INTEGER,
    tahun_ajaran TEXT,
    hari_pilihan TEXT,
    status TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tutorial_classes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nama_kelas TEXT,
    gelombang TEXT,
    hari TEXT,
    jam TEXT,
    ruangan TEXT,
    dosen_pengampu TEXT
);

CREATE TABLE announcements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    judul TEXT,
    konten TEXT,
    tipe TEXT,
    target_role TEXT,
    link_tujuan TEXT,
    is_active INTEGER DEFAULT 1,
    tanggal_mulai TEXT,
    tanggal_selesai TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
");

require_once $temporary . '/includes/pretes-cbt.php';
ensurePretesCbtTables($pdoFixture);

// Data master pretes aktif
$pdoFixture->exec("
INSERT INTO master_pretes (id, periode, status) VALUES (1, 'Pretes RPL 2026/2027', 'aktif');
");

// Sesi CBT pretes aktif
$now = time();
$pdoFixture->prepare("
INSERT INTO pretes_cbt_sessions (code, title, duration_minutes, is_active, mode, exam_open, created_at)
VALUES ('SESI-PRETES-RPL', 'Sesi Pretes RPL', 60, 1, 'resmi', 1, ?)
")->execute([$now - 3600]);

// Sesi Tryout aktif
$pdoFixture->prepare("
INSERT INTO pretes_cbt_sessions (code, title, duration_minutes, is_active, mode, exam_open, created_at)
VALUES ('SESI-TRYOUT-RPL', 'Tryout Pretes RPL', 30, 1, 'tryout', 1, ?)
")->execute([$now - 3600]);

$failures = [];
$check = function(bool $condition, string $label) use (&$failures) {
    if (!$condition) {
        $failures[] = "FAIL: $label";
        echo "❌ FAIL: $label\n";
    } else {
        echo "✅ PASS: $label\n";
    }
};

// Helper untuk render dashboard
$renderDashboard = function(array $user) use ($temporary): string {
    global $currentUserMock;
    $currentUserMock = $user;
    ob_start();
    include $temporary . '/pages/dashboard.php';
    return ob_get_clean();
};

echo "=== TEST 1: Mahasiswa belum mengerjakan CBT pretes ===\n";
$user1 = [
    'id' => 101,
    'nama_lengkap' => 'Budi Santoso',
    'nim' => '20260101',
    'username' => '20260101',
    'role' => 'mahasiswa',
    'jenis_program' => 'RPL',
    'program_studi' => 'Pendidikan Agama Islam',
    'fakultas' => 'Tarbiyah'
];
$pdoFixture->prepare("INSERT INTO users VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute(array_values($user1));
$pdoFixture->prepare("
INSERT INTO pretes_registrations (id, user_id, periode, hari_pilihan, jam, status)
VALUES (1, 101, 'Pretes RPL 2026/2027', 'Senin', '13.00 WIB (Jam 1)', 'terdaftar')
")->execute();

$html1 = $renderDashboard($user1);
$check(str_contains($html1, 'Jadwal & Status Pretes Anda'), 'Test 1: Menampilkan kartu jadwal saat CBT belum selesai');
$check(str_contains($html1, 'Wajib Mengikuti Tryout Pretes'), 'Test 1: Menampilkan banner wajib tryout saat sesi tryout dibuka');
$check(str_contains($html1, '⏳ Menunggu'), 'Test 1: Stat status menunjukkan ⏳ Menunggu');
$check(!str_contains($html1, 'Hasil CBT Pretes Saya'), 'Test 1: Tidak menampilkan kartu hasil CBT');

echo "\n=== TEST 2: Mahasiswa sudah submit CBT dengan nilai < 80 (e.g. 76) ===\n";
$user2 = [
    'id' => 102,
    'nama_lengkap' => 'Siti Aminah',
    'nim' => '20260102',
    'username' => '20260102',
    'role' => 'mahasiswa',
    'jenis_program' => 'RPL',
    'program_studi' => 'Pendidikan Agama Islam',
    'fakultas' => 'Tarbiyah'
];
$pdoFixture->prepare("INSERT INTO users VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute(array_values($user2));
$pdoFixture->prepare("
INSERT INTO pretes_registrations (id, user_id, periode, hari_pilihan, jam, status)
VALUES (2, 102, 'Pretes RPL 2026/2027', 'Senin', '13.00 WIB (Jam 1)', 'terdaftar')
")->execute();

// Mulai CBT dan submit dengan 19 benar (19/25 = 76)
pretesCbtStart($pdoFixture, 102, 2, $now);
$att2 = (int)$pdoFixture->query("SELECT id FROM pretes_cbt_attempts WHERE user_id = 102 AND registration_id = 2")->fetchColumn();
$items2 = $pdoFixture->query("SELECT position, correct_answer FROM pretes_cbt_items WHERE attempt_id = $att2 ORDER BY position")->fetchAll(PDO::FETCH_ASSOC);
foreach ($items2 as $idx => $it) {
    $ans = $idx < 19 ? $it['correct_answer'] : ($it['correct_answer'] === 'A' ? 'B' : 'A');
    $pdoFixture->prepare("UPDATE pretes_cbt_items SET answer = ? WHERE attempt_id = ? AND position = ?")->execute([$ans, $att2, $it['position']]);
}
pretesCbtChange($pdoFixture, 102, 2, 'submit', ['revision' => 0], $now + 120);

$html2 = $renderDashboard($user2);
$check(!str_contains($html2, 'Jadwal & Status Pretes Anda'), 'Test 2: Kartu jadwal pretes disembunyikan');
$check(!str_contains($html2, 'Wajib Mengikuti Tryout Pretes'), 'Test 2: Banner tryout disembunyikan');
$check(str_contains($html2, '❌ Lanjut Tutorial (76)'), 'Test 2: Stat card menunjukkan ❌ Lanjut Tutorial (76)');
$check(str_contains($html2, 'Hasil CBT Pretes Saya'), 'Test 2: Menampilkan kartu Hasil CBT Pretes Saya');
$check(str_contains($html2, 'Mohon maaf, nilai CBT Pretes Anda tidak mencapai nilai minimal (80)'), 'Test 2: Menampilkan pesan maaf nilai tidak mencapai 80');
$check(str_contains($html2, 'href="https://example.test/tutorial-rpl.php"'), 'Test 2: Menampilkan tombol pendaftaran Tutorial RPL ke /tutorial-rpl.php');
$check(!str_contains($html2, PRETES_RPL_DRIVE_URL), 'Test 2: Tidak menampilkan folder Google Drive tugas RPL');

echo "\n=== TEST 3: Mahasiswa sudah submit CBT dengan nilai >= 80 (e.g. 84) ===\n";
$user3 = [
    'id' => 103,
    'nama_lengkap' => 'Rahmat Hidayat',
    'nim' => '20260103',
    'username' => '20260103',
    'role' => 'mahasiswa',
    'jenis_program' => 'RPL',
    'program_studi' => 'Pendidikan Agama Islam',
    'fakultas' => 'Tarbiyah'
];
$pdoFixture->prepare("INSERT INTO users VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute(array_values($user3));
$pdoFixture->prepare("
INSERT INTO pretes_registrations (id, user_id, periode, hari_pilihan, jam, status)
VALUES (3, 103, 'Pretes RPL 2026/2027', 'Senin', '13.00 WIB (Jam 1)', 'terdaftar')
")->execute();

// Mulai CBT dan submit dengan 21 benar (21/25 = 84)
pretesCbtStart($pdoFixture, 103, 3, $now);
$att3 = (int)$pdoFixture->query("SELECT id FROM pretes_cbt_attempts WHERE user_id = 103 AND registration_id = 3")->fetchColumn();
$items3 = $pdoFixture->query("SELECT position, correct_answer FROM pretes_cbt_items WHERE attempt_id = $att3 ORDER BY position")->fetchAll(PDO::FETCH_ASSOC);
foreach ($items3 as $idx => $it) {
    $ans = $idx < 21 ? $it['correct_answer'] : ($it['correct_answer'] === 'A' ? 'B' : 'A');
    $pdoFixture->prepare("UPDATE pretes_cbt_items SET answer = ? WHERE attempt_id = ? AND position = ?")->execute([$ans, $att3, $it['position']]);
}
pretesCbtChange($pdoFixture, 103, 3, 'submit', ['revision' => 0], $now + 120);

$html3 = $renderDashboard($user3);
$check(!str_contains($html3, 'Jadwal & Status Pretes Anda'), 'Test 3: Kartu jadwal pretes disembunyikan');
$check(!str_contains($html3, 'Wajib Mengikuti Tryout Pretes'), 'Test 3: Banner tryout disembunyikan');
$check(str_contains($html3, '✅ Lolos CBT (84)'), 'Test 3: Stat card menunjukkan ✅ Lolos CBT (84)');
$check(str_contains($html3, 'Hasil CBT Pretes Saya'), 'Test 3: Menampilkan kartu Hasil CBT Pretes Saya');
$check(str_contains($html3, 'Selamat, Saudara diperkenankan melanjutkan ke tahap pretes secara online'), 'Test 3: Menampilkan instruksi tugas pretes online RPL');
$check(str_contains($html3, PRETES_RPL_DRIVE_URL), 'Test 3: Menampilkan folder Google Drive tugas RPL');
$check(!str_contains($html3, 'Mohon maaf, nilai CBT Pretes Anda tidak mencapai nilai minimal'), 'Test 3: Tidak menampilkan pesan maaf');

// Bersihkan direktori sementara
try {
    $it = new RecursiveDirectoryIterator($temporary, RecursiveIteratorIterator::CHILD_FIRST);
    foreach (new RecursiveIteratorIterator($it) as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($temporary);
} catch (Throwable $e) {}

if (!empty($failures)) {
    echo "\nTotal failures: " . count($failures) . "\n";
    exit(1);
}

echo "\nSemua pengujian dashboard CBT submitted BERHASIL! (100% PASS)\n";
exit(0);
