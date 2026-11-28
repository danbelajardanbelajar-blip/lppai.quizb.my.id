<?php
declare(strict_types=1);

/**
 * Verifikasi duplikasi penuh Pretes Reguler -> Pretes RPL pada sidebar admin,
 * ketersediaan seluruh route wrapper RPL, serta isolasi active-state CBT.
 */

require_once __DIR__ . '/../includes/pretes-program-scope.php';

$errors = [];
$check = static function (bool $cond, string $msg) use (&$errors): void {
    if (!$cond) {
        $errors[] = $msg;
    }
};

// 1. Pastikan seluruh route wrapper RPL tersedia
$rplWrappers = [
    __DIR__ . '/../admin/pretes-rpl-pengaturan-tutor.php' => "PRETES_ADMIN_PROGRAM",
    __DIR__ . '/../admin/pretes-rpl-dosen.php' => "PRETES_ADMIN_PROGRAM",
    __DIR__ . '/../admin/pretes-rpl-jadwal.php' => "PRETES_ADMIN_PROGRAM",
    __DIR__ . '/../admin/pretes-rpl-jadwal-cetak.php' => "PRETES_ADMIN_PROGRAM",
    __DIR__ . '/../admin/pretes-rpl-peserta.php' => "PRETES_ADMIN_PROGRAM",
    __DIR__ . '/../admin/pretes-rpl-peserta-export.php' => "PRETES_ADMIN_PROGRAM",
    __DIR__ . '/../admin/pretes-rpl-peserta-pdf.php' => "PRETES_ADMIN_PROGRAM",
    __DIR__ . '/../admin/pretes-rpl-mahasiswa-export.php' => "PRETES_ADMIN_PROGRAM",
    __DIR__ . '/../admin/pretes-rpl-nilai.php' => "PRETES_ADMIN_PROGRAM",
    __DIR__ . '/../admin/pretes-rpl-absensi-export.php' => "PRETES_ADMIN_PROGRAM",
    __DIR__ . '/../admin/pretes-rpl-tugas.php' => "PRETES_TASK_PROGRAM",
    __DIR__ . '/../admin/pretes-rpl-bank-soal.php' => "PRETES_ADMIN_PROGRAM",
    __DIR__ . '/../admin/pretes-rpl-cbt-review.php' => "PRETES_ADMIN_PROGRAM",
    __DIR__ . '/../admin/pretes-rpl-rekap-nilai.php' => "PRETES_ADMIN_PROGRAM",
];

foreach ($rplWrappers as $file => $constName) {
    $check(is_file($file), 'File wrapper RPL tidak ditemukan: ' . basename($file));
    if (is_file($file)) {
        $src = (string)file_get_contents($file);
        $check(str_contains($src, $constName) && str_contains($src, "'rpl'"), 'Wrapper ' . basename($file) . ' harus mendefinisikan ' . $constName . " = 'rpl'.");
    }
}

// 2. Render sidebar admin & verifikasi 8 submenu Pretes RPL
if (!defined('BASE_URL')) define('BASE_URL', '');
if (!defined('APP_NAME')) define('APP_NAME', 'LPPAI Corner');
if (!function_exists('getDBConnection')) {
    function getDBConnection() { return new PDO('sqlite::memory:'); }
}

$renderAdminSidebar = static function (string $self, array $get = []): string {
    global $currentUser;
    $currentUser = ['id' => 1, 'role' => 'admin', 'nama_lengkap' => 'Admin'];
    $_SERVER['PHP_SELF'] = $self;
    $_GET = $get;
    ob_start();
    include __DIR__ . '/../includes/sidebar.php';
    return (string)ob_get_clean();
};

$html = $renderAdminSidebar('/admin/dashboard.php');
$check(str_contains($html, 'data-group="admin-pretes"'), 'Sidebar harus memiliki grup admin-pretes.');
$check(str_contains($html, 'data-group="admin-pretes-rpl"'), 'Sidebar harus memiliki grup admin-pretes-rpl.');
$check(str_contains($html, '<span class="nav-text">Pretes RPL</span>'), 'Sidebar harus menampilkan label Pretes RPL.');

$expectedRplLinks = [
    '/admin/pretes-rpl-pengaturan-tutor.php',
    '/admin/pretes-rpl-dosen.php',
    '/admin/pretes-rpl-jadwal.php',
    '/admin/pretes-rpl-peserta.php',
    '/admin/pretes-rpl-nilai.php',
    '/admin/pretes-rpl-tugas.php',
    '/pretes-ujian.php?cbt_bank=1&program=rpl',
    '/admin/pretes-rpl-rekap-nilai.php',
];
foreach ($expectedRplLinks as $link) {
    $check(str_contains($html, $link), 'Sidebar Pretes RPL harus memuat tautan: ' . $link);
}

// 3. Isolasi status active saat membuka CBT RPL vs CBT Reguler
$htmlCbtRpl = $renderAdminSidebar('/pretes-ujian.php', ['cbt_bank' => '1', 'program' => 'rpl']);
$check(str_contains($htmlCbtRpl, 'nav-group open" data-group="admin-pretes-rpl"'), 'CBT RPL harus membuka grup admin-pretes-rpl.');
$check(!str_contains($htmlCbtRpl, 'nav-group open" data-group="admin-pretes"'), 'CBT RPL tidak boleh membuka grup admin-pretes.');

$htmlCbtReg = $renderAdminSidebar('/pretes-ujian.php', ['cbt_bank' => '1']);
$check(str_contains($htmlCbtReg, 'nav-group open" data-group="admin-pretes"'), 'CBT Reguler harus membuka grup admin-pretes.');
$check(!str_contains($htmlCbtReg, 'nav-group open" data-group="admin-pretes-rpl"'), 'CBT Reguler tidak boleh membuka grup admin-pretes-rpl.');

if ($errors) {
    fwrite(STDERR, "FAILED:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}

echo "OK: pretes-rpl-duplication-test\n";
