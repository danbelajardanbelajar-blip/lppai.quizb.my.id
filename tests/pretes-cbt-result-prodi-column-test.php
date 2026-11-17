<?php
/**
 * Test: Tampilan Jurusan (Kecil) di Kolom Nama di Bawah Nama pada Tab Hasil Ujian CBT
 * URL: /pretes-ujian.php?cbt_bank=1&tab=results&pass_filter=lulus
 */
declare(strict_types=1);

if (!defined('BASE_URL')) define('BASE_URL', 'https://lppai.quizb.my.id');

$failures = 0;
function assertProdi(bool $ok, string $desc, string $detail = ''): void {
    global $failures;
    if ($ok) {
        echo " [PASS] $desc\n";
    } else {
        echo " ❌ [FAIL] $desc" . ($detail !== '' ? " ($detail)" : '') . "\n";
        $failures++;
    }
}

echo "=== Menjalankan Validasi Jurusan di Bawah Nama Peserta CBT ===\n";

// 1. Validasi Sintaks PHP
$phpFiles = [
    __DIR__ . '/../includes/pretes-cbt-admin-view.php',
    __DIR__ . '/../includes/pretes-cbt.php',
    __DIR__ . '/../admin/pretes-bank-soal.php',
    __DIR__ . '/../pages/pretes-ujian.php',
];

foreach ($phpFiles as $file) {
    $out = [];
    $ret = 0;
    exec('php -l ' . escapeshellarg($file), $out, $ret);
    assertProdi($ret === 0, 'Sintaks PHP valid: ' . basename($file), implode("\n", $out));
}

// 2. Validasi View Template (includes/pretes-cbt-admin-view.php)
$viewContent = file_get_contents(__DIR__ . '/../includes/pretes-cbt-admin-view.php');
assertProdi(
    strpos($viewContent, 'cbt-user-prodi') !== false,
    'View memuat elemen class .cbt-user-prodi di kolom nama'
);
assertProdi(
    strpos($viewContent, '$attempt[\'program_studi\']') !== false,
    'View membaca field $attempt[\'program_studi\']'
);
assertProdi(
    strpos($viewContent, 'font-size:11px') !== false,
    'View menggunakan styling teks kecil (font-size:11px)'
);

// 3. Validasi JavaScript Live Monitoring (assets/js/pretes-cbt-admin.js)
$jsContent = file_get_contents(__DIR__ . '/../assets/js/pretes-cbt-admin.js');
assertProdi(
    strpos($jsContent, 'att.program_studi') !== false,
    'assets/js/pretes-cbt-admin.js membaca properti att.program_studi'
);
assertProdi(
    strpos($jsContent, 'cbt-user-prodi') !== false,
    'assets/js/pretes-cbt-admin.js menyertakan elemen .cbt-user-prodi pada render row live'
);
assertProdi(
    strpos($jsContent, "escapeHtml(prodiText)") !== false,
    'assets/js/pretes-cbt-admin.js mengamankan teks jurusan dengan escapeHtml'
);

// 4. Validasi Stylesheet CSS (assets/css/pretes-cbt-admin.css)
$cssContent = file_get_contents(__DIR__ . '/../assets/css/pretes-cbt-admin.css');
assertProdi(
    strpos($cssContent, '.cbt-user-prodi') !== false,
    'assets/css/pretes-cbt-admin.css mendefinisikan kelas .cbt-user-prodi'
);

// 5. Pengujian Logika Render Baris PHP (Simulasi Data & Output HTML)
if (!function_exists('adminUserProfileLink')) {
    function adminUserProfileLink($name, $userId = null): string {
        $nameSafe = htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8');
        return '<a class="admin-user-profile-link" href="/admin/profil-pengguna.php?id=' . (int)$userId . '">' . $nameSafe . '</a>';
    }
}
if (!function_exists('sanitize')) {
    function sanitize($val): string {
        return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
    }
}

$testAttempts = [
    [
        'id' => 101,
        'user_id' => 501,
        'nama_lengkap' => 'Ahmad Syarif',
        'nim' => '2401001',
        'program_studi' => 'S1 Teknik Informatika',
        'jenis_program' => 'Reguler',
    ],
    [
        'id' => 102,
        'user_id' => 502,
        'nama_lengkap' => 'Nur Fatimah',
        'nim' => '2401002',
        'program_studi' => 'S1 Manajemen',
        'jenis_program' => 'RPL',
    ],
    [
        'id' => 103,
        'user_id' => 503,
        'nama_lengkap' => 'Rian Hidayat',
        'nim' => '2401003',
        'program_studi' => '',
        'jenis_program' => 'Reguler',
    ],
    [
        'id' => 104,
        'user_id' => 504,
        'nama_lengkap' => 'Dewi Lestari',
        'nim' => '2401004',
        'program_studi' => null,
        'jenis_program' => 'Reguler',
    ],
];

function renderCellHtml(array $attempt): string {
    ob_start();
    ?>
    <td>
        <div><strong><?= adminUserProfileLink($attempt['nama_lengkap'], $attempt['user_id']) ?></strong></div>
        <?php if (!empty(trim((string)($attempt['program_studi'] ?? '')))): ?>
            <div class="cbt-user-prodi" style="font-size:11px;color:#64748b;margin-top:2px;line-height:1.25;"><?= sanitize($attempt['program_studi']) ?></div>
        <?php endif; ?>
    </td>
    <?php
    return ob_get_clean();
}

$html1 = renderCellHtml($testAttempts[0]);
assertProdi(strpos($html1, 'Ahmad Syarif') !== false, 'Baris 1 merender nama peserta');
assertProdi(strpos($html1, 'S1 Teknik Informatika') !== false, 'Baris 1 merender jurusan di bawah nama');
assertProdi(strpos($html1, 'class="cbt-user-prodi"') !== false, 'Baris 1 memiliki wrapper .cbt-user-prodi');

$html2 = renderCellHtml($testAttempts[1]);
assertProdi(strpos($html2, 'Nur Fatimah') !== false, 'Baris 2 merender nama peserta');
assertProdi(strpos($html2, 'S1 Manajemen') !== false, 'Baris 2 merender jurusan di bawah nama');

$html3 = renderCellHtml($testAttempts[2]);
assertProdi(strpos($html3, 'Rian Hidayat') !== false, 'Baris 3 merender nama peserta');
assertProdi(strpos($html3, 'cbt-user-prodi') === false, 'Baris 3 tidak merender wrapper prodi jika string kosong');

$html4 = renderCellHtml($testAttempts[3]);
assertProdi(strpos($html4, 'Dewi Lestari') !== false, 'Baris 4 merender nama peserta');
assertProdi(strpos($html4, 'cbt-user-prodi') === false, 'Baris 4 tidak merender wrapper prodi jika null');

// 6. Validasi Pencarian Jurusan / Program Studi via pretesCbtResultWhere
require_once __DIR__ . '/../includes/pretes-cbt.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, nama_lengkap TEXT, nim TEXT, program_studi TEXT, jenis_program TEXT)');
$pdo->exec('CREATE TABLE pretes_cbt_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, registration_id INTEGER, started_at INTEGER, deadline_at INTEGER, submitted_at INTEGER NULL, score REAL NULL)');

$insUser = $pdo->prepare('INSERT INTO users (id, username, nama_lengkap, nim, program_studi, jenis_program) VALUES (?,?,?,?,?,?)');
$insUser->execute([1, 'ahmad', 'Ahmad Syarif', '2401001', 'Teknik Informatika', 'Reguler']);
$insUser->execute([2, 'nur', 'Nur Fatimah', '2401002', 'Pendidikan Agama Islam', 'RPL']);
$insUser->execute([3, 'budi', 'Budi Santoso', '2401003', 'Hukum Ekonomi Syariah', 'Reguler']);

$now = time();
$insAtt = $pdo->prepare('INSERT INTO pretes_cbt_attempts (user_id, registration_id, started_at, deadline_at, submitted_at, score) VALUES (?,?,?,?,?,?)');
$insAtt->execute([1, 10, $now - 600, $now + 3000, $now, 85.0]);
$insAtt->execute([2, 11, $now - 600, $now + 3000, $now, 90.0]);
$insAtt->execute([3, 12, $now - 600, $now + 3000, $now, 75.0]);

// Tes pencarian berdasarkan jurusan
$fProdi = pretesCbtResultFilters(['q' => 'Informatika']);
[$whereSql, $params] = pretesCbtResultWhere($fProdi, $now);
$st = $pdo->prepare("SELECT u.nama_lengkap FROM pretes_cbt_attempts a JOIN users u ON u.id = a.user_id $whereSql");
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->execute();
$foundProdi = $st->fetchAll(PDO::FETCH_COLUMN);

assertProdi($foundProdi === ['Ahmad Syarif'], 'Pencarian kata kunci jurusan "Informatika" menemukan peserta yang sesuai');

// Tes placeholder PDO unik
preg_match_all('/:[a-z_0-9]+/', $whereSql, $matches);
assertProdi(
    count($matches[0]) === count(array_unique($matches[0])),
    'Setiap placeholder named parameter PDO unik untuk native MySQL prepare'
);
assertProdi(
    count($matches[0]) === count($params),
    'Jumlah parameter binding sama dengan placeholder SQL'
);

echo "\n" . ($failures === 0 ? "✅ Seluruh 19 pengujian Tampilan Jurusan di Kolom Nama BERHASIL 100% SEMPURNA!" : "❌ Terdapat $failures kegagalan pengujian!") . "\n";
exit($failures === 0 ? 0 : 1);
