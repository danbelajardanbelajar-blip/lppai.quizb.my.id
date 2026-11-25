<?php
/**
 * Test: Student Hub & CBT Integration for Tutorial RPL Online
 */
$php = "d:\\all_project\\lppai.quizb.my.id\\scratch\\php-runtime\\php.exe";
$dir = sys_get_temp_dir() . '/test-rpl-student-hub-' . bin2hex(random_bytes(6));
mkdir($dir . '/pages', 0777, true);
mkdir($dir . '/includes', 0777, true);
mkdir($dir . '/assets/css', 0777, true);
mkdir($dir . '/assets/js', 0777, true);

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
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE tutorial_rpl_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        class_id INTEGER,
        user_id INTEGER,
        drive_submitted INTEGER DEFAULT 0,
        drive_verified INTEGER DEFAULT 0,
        drive_notes TEXT,
        exam_submitted INTEGER DEFAULT 0,
        nilai_ujian_tulis REAL,
        nilai_praktik REAL,
        nilai_akhir REAL,
        status_lulus TEXT DEFAULT 'proses',
        catatan TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(class_id, user_id)
    );
    CREATE TABLE tutorial_rpl_cbt_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT,
        title TEXT,
        duration_minutes INTEGER DEFAULT 30,
        passing_grade REAL DEFAULT 80.0,
        token TEXT DEFAULT 'TUTOR26',
        exam_open INTEGER DEFAULT 1,
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE tutorial_rpl_cbt_questions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE,
        category TEXT,
        question_text TEXT,
        options_json TEXT,
        correct_answer TEXT,
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE tutorial_rpl_cbt_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        class_id INTEGER,
        started_at INTEGER,
        submitted_at INTEGER,
        correct_count INTEGER,
        total_questions INTEGER,
        score REAL,
        submitted INTEGER DEFAULT 0,
        UNIQUE(user_id, class_id)
    );
    CREATE TABLE tutorial_rpl_registration_periods (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        version INTEGER DEFAULT 1,
        nama TEXT,
        gelombang TEXT,
        opens_at TEXT,
        closes_at TEXT,
        quota INTEGER DEFAULT 0,
        active_slot INTEGER,
        created_by INTEGER,
        updated_by INTEGER,
        created_at TEXT,
        updated_at TEXT
    );

    INSERT INTO users (id, username, role, nama_lengkap, nim, jenis_program) VALUES (10, 'dosen1', 'dosen', 'Dr. Zaid', '0010', 'Reguler');
    INSERT INTO users (id, username, role, nama_lengkap, nim, no_hp, jenis_program) VALUES (101, 'mhs_rpl1', 'mahasiswa', 'Rizky Pratama', '202699', '08123456789', 'RPL');
    INSERT INTO users (id, username, role, nama_lengkap, nim, no_hp, jenis_program) VALUES (102, 'mhs_rpl2', 'mahasiswa', 'Dewi Lestari', '202698', '08123456788', 'RPL');

    INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, gelombang, dosen_id, dosen_nim, dosen_nama, dosen_wa, drive_url, is_active)
    VALUES (1, 'Kelompok RPL 01', 'Tutorial RPL 2026/2027', 'Gelombang 1', 10, '0010', 'Dr. Zaid', '08129999000', 'https://drive.google.com/drive/folders/test1234', 1);

    INSERT INTO tutorial_rpl_members (id, class_id, user_id, drive_submitted, drive_verified, exam_submitted, nilai_praktik)
    VALUES (1, 1, 101, 0, 0, 0, 85.0);

    INSERT INTO tutorial_rpl_cbt_sessions (id, code, title, duration_minutes, passing_grade, token, exam_open, is_active)
    VALUES (1, 'SESI-TUTORIAL-RPL-2026', 'Ujian Tulis Tutorial RPL 2026/2027', 30, 80.0, 'TUTOR26', 1, 1);

    INSERT INTO tutorial_rpl_cbt_questions (code, category, question_text, options_json, correct_answer, is_active)
    VALUES ('Q1', 'Thaharah', 'Rukun wudhu yang pertama adalah...', '{\"A\":\"Niat\",\"B\":\"Membasuh muka\",\"C\":\"Membasuh tangan\",\"D\":\"Tertib\",\"E\":\"Mengusap kepala\"}', 'A', 1);
    INSERT INTO tutorial_rpl_cbt_questions (code, category, question_text, options_json, correct_answer, is_active)
    VALUES ('Q2', 'Sholat', 'Membaca Al-Fatihah dalam sholat adalah...', '{\"A\":\"Sunnah\",\"B\":\"Rukun sholat\",\"C\":\"Mubah\",\"D\":\"Syarat wajib\",\"E\":\"Makruh\"}', 'B', 1);

    INSERT INTO tutorial_rpl_registration_periods (id, nama, gelombang, opens_at, closes_at, active_slot)
    VALUES (1, 'Tutorial RPL 2026/2027', 'Gelombang 1', '2026-01-01 00:00:00', '2026-12-31 23:59:59', 1);
");

// Salin berkas asli dan stub auth
copy(__DIR__ . '/../pages/tutorial-rpl.php', $dir . '/pages/tutorial-rpl.php');
copy(__DIR__ . '/../pages/tutorial-rpl-cbt.php', $dir . '/pages/tutorial-rpl-cbt.php');
copy(__DIR__ . '/../includes/tutorial-rpl-helper.php', $dir . '/includes/tutorial-rpl-helper.php');
copy(__DIR__ . '/../includes/tutorial-rpl-cbt-options.php', $dir . '/includes/tutorial-rpl-cbt-options.php');
copy(__DIR__ . '/../includes/tutorial-rpl-cbt-engine.php', $dir . '/includes/tutorial-rpl-cbt-engine.php');
copy(__DIR__ . '/../includes/tutorial-rpl-periods.php', $dir . '/includes/tutorial-rpl-periods.php');
copy(__DIR__ . '/../includes/tutorial-rpl-registration-policy.php', $dir . '/includes/tutorial-rpl-registration-policy.php');
copy(__DIR__ . '/../includes/tutorial-rpl-registration-rules-view.php', $dir . '/includes/tutorial-rpl-registration-rules-view.php');
copy(__DIR__ . '/../includes/pretes-cbt-view.php', $dir . '/includes/pretes-cbt-view.php');
file_put_contents($dir . '/includes/pretes-helper.php', "<?php\nif (!function_exists('ensurePretesProctoringTables')) { function ensurePretesProctoringTables(PDO \$pdo): void {} }\n");
copy(__DIR__ . '/../includes/user-exam-photo.php', $dir . '/includes/user-exam-photo.php');
copy(__DIR__ . '/../assets/css/pretes-cbt-exam.css', $dir . '/assets/css/pretes-cbt-exam.css');
copy(__DIR__ . '/../assets/js/pretes-cbt.js', $dir . '/assets/js/pretes-cbt.js');

file_put_contents($dir . '/includes/auth.php', <<<'PHP'
<?php
define('BASE_URL', '');
define('APP_NAME', 'LPPAI Corner');
function getCurrentUser() {
    return $GLOBALS['request']['actor'] ?? ['id' => 101, 'role' => 'mahasiswa', 'nim' => '202699', 'nama_lengkap' => 'Rizky Pratama', 'jenis_program' => 'RPL'];
}
function requireLogin() {
    if (!getCurrentUser()['id']) { http_response_code(403); exit; }
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
session_start();
if (!empty($request['session'])) {
    $_SESSION = array_replace($_SESSION, $request['session']);
}
http_response_code(200);
ob_start();
register_shutdown_function(function() {
    echo json_encode([
        'status' => http_response_code(),
        'html' => ob_get_clean(),
        'session' => $_SESSION
    ]);
});

$page = $request['page'] ?? 'hub';
if ($page === 'cbt') {
    include __DIR__ . '/pages/tutorial-rpl-cbt.php';
} else {
    include __DIR__ . '/pages/tutorial-rpl.php';
}
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
    echo "=== TEST 1: Helper Functions Membership & Drive Submission ===\n";
    require_once $dir . '/includes/tutorial-rpl-helper.php';
    $mem = tutorialRplGetStudentMembership($pdo, 101);
    $check($mem !== null && $mem['nama_kelompok'] === 'Kelompok RPL 01', 'tutorialRplGetStudentMembership mengembalikan data kelompok 101');
    $check($mem['dosen_nama'] === 'Dr. Zaid' && $mem['drive_url'] !== '', 'Detail dosen dan link Drive terambil');

    $memNone = tutorialRplGetStudentMembership($pdo, 102);
    $check($memNone === null, 'Mahasiswa 102 belum terplot mengembalikan null');

    echo "\n=== TEST 2: Student Hub (pages/tutorial-rpl.php) ===\n";
    // 2a. GET Mahasiswa 101 yang sudah terplot
    $res = executeRequest($php, $dir, ['method' => 'GET', 'page' => 'hub', 'actor' => ['id' => 101, 'role' => 'mahasiswa', 'jenis_program' => 'RPL']]);
    $check($res['status'] === 200, 'GET tutorial-rpl.php status 200');
    $check(str_contains($res['html'], 'Kelompok RPL 01'), 'Hub menampilkan nama kelompok bimbingan');
    $check(str_contains($res['html'], 'Dr. Zaid'), 'Hub menampilkan nama dosen tutor');
    $check(str_contains($res['html'], 'https://drive.google.com/drive/folders/test1234'), 'Hub memuat link Google Drive kelas');
    $check(str_contains($res['html'], 'Konfirmasi Saya Sudah Mengunggah Tugas'), 'Tombol konfirmasi unggah muncul saat belum dikonfirmasi');

    // 2b. POST Konfirmasi Unggah Tugas dengan CSRF valid
    $res = executeRequest($php, $dir, [
        'method' => 'POST',
        'page' => 'hub',
        'actor' => ['id' => 101, 'role' => 'mahasiswa', 'jenis_program' => 'RPL'],
        'post' => ['action' => 'confirm_drive', 'csrf_token' => 'valid-csrf-token']
    ]);
    $check(str_contains($res['html'], 'berhasil disimpan'), 'Konfirmasi unggah tugas berhasil');
    $submitted = (int)$pdo->query("SELECT drive_submitted FROM tutorial_rpl_members WHERE id = 1")->fetchColumn();
    $check($submitted === 1, 'Status drive_submitted berubah menjadi 1 di basis data');

    // 2c. GET Mahasiswa 102 yang belum terplot (menampilkan panduan & pendaftaran)
    $res = executeRequest($php, $dir, ['method' => 'GET', 'page' => 'hub', 'actor' => ['id' => 102, 'role' => 'mahasiswa', 'jenis_program' => 'RPL']]);
    $check(!str_contains($res['html'], 'Kelompok RPL 01'), 'Mahasiswa 102 tidak melihat kelas orang lain');
    $check(str_contains($res['html'], 'Daftar Tutorial RPL'), 'Mahasiswa 102 diarahkan untuk daftar');

    echo "\n=== TEST 3: Student CBT (pages/tutorial-rpl-cbt.php) ===\n";
    // 3a. GET CBT -> Tampil shell CBT Pretes-parity (Skenario A & B + Token)
    $res = executeRequest($php, $dir, ['method' => 'GET', 'page' => 'cbt', 'actor' => ['id' => 101, 'role' => 'mahasiswa', 'jenis_program' => 'RPL']]);
    $check($res['status'] === 200, 'GET tutorial-rpl-cbt.php status 200');
    $check(str_contains($res['html'], 'id="pretesCbt"'), 'Menampilkan shell CBT terpadu (#pretesCbt)');
    $check(str_contains($res['html'], 'id="cbtInputToken"'), 'Menampilkan input token ujian');
    $check(str_contains($res['html'], 'id="sectionCheckin"') && str_contains($res['html'], 'id="sectionExamRoom"'), 'Memuat section Skenario A dan Skenario B');
    $check(str_contains($res['html'], 'tutorial-rpl-cbt.php?face_api=1') && str_contains($res['html'], 'tutorial-rpl-cbt.php?cbt_api=1'), 'Memuat endpoint face_api & cbt_api Tutorial RPL');

    // Sesuaikan target soal paket 1 ke 2 butir dan wajibkan token untuk fixture uji
    require_once $dir . '/includes/tutorial-rpl-cbt-engine.php';
    ensureTutorialRplCbtProctoringTables($pdo);
    $pdo->exec("UPDATE tutorial_rpl_cbt_packages SET question_count = 2 WHERE id = 1");
    $pdo->exec("UPDATE tutorial_rpl_cbt_sessions SET token_required = 1 WHERE id = 1");
    $pdo->exec("DELETE FROM tutorial_rpl_cbt_questions WHERE code NOT IN ('Q1', 'Q2')");

    // 3b. Token salah ditolak
    $tokenRejected = false;
    try {
        tutorialRplCbtStart($pdo, 101, 1, time(), 'SALAH', false, 1);
    } catch (InvalidArgumentException $e) {
        $tokenRejected = str_contains($e->getMessage(), 'Token ujian tidak valid');
    }
    $check($tokenRejected, 'Token salah ditolak oleh engine CBT');

    // 3c. Token benar -> Mulai ujian & dapatkan soal acak dengan opsi A-E
    $started = tutorialRplCbtStart($pdo, 101, 1, time(), 'TUTOR26', false, 1);
    $check(!empty($started['attempt']['id']) && count($started['attempt']['questions']) === 2, 'Verifikasi token berhasil & 2 soal terbit');

    // 3d. Simpan jawaban benar (berdasarkan kunci di tutorial_rpl_cbt_items) dan submit
    $stmtKeys = $pdo->prepare("SELECT position, correct_answer FROM tutorial_rpl_cbt_items WHERE attempt_id = ? ORDER BY position");
    $stmtKeys->execute([$started['attempt']['id']]);
    foreach ($stmtKeys->fetchAll(PDO::FETCH_ASSOC) as $rowKey) {
        tutorialRplCbtChange($pdo, 101, 1, 'save', [
            'position' => (int)$rowKey['position'],
            'answer' => $rowKey['correct_answer'],
            'revision' => 0
        ]);
    }
    $submittedRes = tutorialRplCbtChange($pdo, 101, 1, 'submit', ['revision' => 0]);
    $check(!empty($submittedRes['attempt']['submitted']) && (float)$submittedRes['attempt']['score'] === 100.0, 'Submit jawaban ujian berhasil dengan skor 100.0');

    // Verifikasi nilai tersimpan di tutorial_rpl_members
    $mRow = $pdo->query("SELECT exam_submitted, nilai_ujian_tulis, nilai_praktik, nilai_akhir, status_lulus FROM tutorial_rpl_members WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $check((int)$mRow['exam_submitted'] === 1, 'exam_submitted bernilai 1');
    $check((float)$mRow['nilai_ujian_tulis'] === 100.0, 'Nilai ujian tulis 100.0 (2/2 benar)');
    $check((float)$mRow['nilai_akhir'] === 92.5, 'Nilai akhir dihitung benar (50% x 85 + 50% x 100 = 92.5)');

    // 3e. State setelah submit mengembalikan submitted=true & score=100
    $stateAfter = tutorialRplCbtState($pdo, 101, 1);
    $check(!empty($stateAfter['attempt']['submitted']) && (float)$stateAfter['attempt']['score'] === 100.0, 'State CBT setelah submit menampilkan skor 100');

    // 3f. Verifikasi Presensi Hadir Sah di Hub saat drive_verified diaktifkan dosen
    $pdo->exec("UPDATE tutorial_rpl_members SET drive_verified = 1 WHERE id = 1");
    $res = executeRequest($php, $dir, ['method' => 'GET', 'page' => 'hub', 'actor' => ['id' => 101, 'role' => 'mahasiswa', 'jenis_program' => 'RPL']]);
    $check(str_contains($res['html'], 'Hadir Sah &amp; Lengkap'), 'Hub menampilkan badge Hadir Sah & Lengkap saat drive_verified=1 dan exam_submitted=1');

    echo "\n=== TEST 4: Sidebar Navigation Mahasiswa RPL ===\n";
    $sidebarFile = __DIR__ . '/../includes/sidebar.php';
    $sidebarContent = file_get_contents($sidebarFile);
    $check(str_contains($sidebarContent, 'href="<?= BASE_URL ?>/tutorial-rpl.php"'), 'Sidebar memuat link tutorial-rpl.php');
    $check(str_contains($sidebarContent, 'Info &amp; Kelas RPL'), 'Sidebar memuat label teks "Info & Kelas RPL"');

} finally {
    $pdo = null;
    @unlink($dir . '/test.sqlite');
    @unlink($dir . '/pages/tutorial-rpl.php');
    @unlink($dir . '/pages/tutorial-rpl-cbt.php');
    @unlink($dir . '/includes/tutorial-rpl-helper.php');
    @unlink($dir . '/includes/tutorial-rpl-cbt-engine.php');
    @unlink($dir . '/includes/tutorial-rpl-periods.php');
    @unlink($dir . '/includes/tutorial-rpl-registration-policy.php');
    @unlink($dir . '/includes/tutorial-rpl-registration-rules-view.php');
    @unlink($dir . '/includes/pretes-cbt-view.php');
    @unlink($dir . '/includes/pretes-helper.php');
    @unlink($dir . '/includes/user-exam-photo.php');
    @unlink($dir . '/includes/auth.php');
    @unlink($dir . '/includes/header.php');
    @unlink($dir . '/includes/footer.php');
    @unlink($dir . '/assets/css/pretes-cbt-exam.css');
    @unlink($dir . '/assets/js/pretes-cbt.js');
    @unlink($dir . '/runner.php');
    @rmdir($dir . '/assets/css');
    @rmdir($dir . '/assets/js');
    @rmdir($dir . '/assets');
    @rmdir($dir . '/pages');
    @rmdir($dir . '/includes');
    @rmdir($dir);
}

if (!empty($failures)) {
    echo "\nTotal failures: " . count($failures) . "\n";
    exit(1);
}
echo "\nSemua pengujian Student Hub & CBT Tutorial RPL Online BERHASIL! (100% PASS)\n";
exit(0);
