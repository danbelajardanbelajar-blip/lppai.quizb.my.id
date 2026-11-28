<?php
declare(strict_types=1);

/**
 * Unit test isolasi periode & sesi CBT pada portal mahasiswa/dosen
 * ketika periode/sesi RPL dan Reguler aktif bersamaan.
 */

require_once __DIR__ . '/../includes/user-helper.php';
require_once __DIR__ . '/../includes/pretes-helper.php';
require_once __DIR__ . '/../includes/pretes-cbt.php';

$errors = [];
$check = static function (bool $cond, string $msg) use (&$errors): void {
    if (!$cond) {
        $errors[] = $msg;
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE master_pretes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    periode TEXT NOT NULL,
    tahun_ajaran TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'aktif'
)");
$pdo->exec("CREATE TABLE pretes_registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    periode TEXT NOT NULL,
    hari_pilihan TEXT DEFAULT NULL
)");
$pdo->exec("CREATE TABLE pretes_cbt_packages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT,
    name TEXT,
    description TEXT,
    duration_minutes INTEGER DEFAULT 30,
    question_count INTEGER DEFAULT 25,
    is_active INTEGER DEFAULT 1,
    created_at INTEGER DEFAULT 0
)");
$pdo->exec("CREATE TABLE pretes_cbt_questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    package_id INTEGER DEFAULT 1,
    category TEXT DEFAULT 'Umum',
    is_active INTEGER DEFAULT 1
)");
$pdo->exec("CREATE TABLE pretes_cbt_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT,
    title TEXT,
    description TEXT,
    package_ids_json TEXT,
    category_quotas_json TEXT,
    camera_mode TEXT DEFAULT 'wajib',
    duration_minutes INTEGER DEFAULT 30,
    scoring_method TEXT DEFAULT 'standar',
    passing_grade REAL DEFAULT 65.0,
    start_time TEXT DEFAULT NULL,
    end_time TEXT DEFAULT NULL,
    token TEXT DEFAULT '',
    token_required INTEGER DEFAULT 0,
    shuffle_on_violation INTEGER DEFAULT 1,
    is_active INTEGER DEFAULT 1,
    created_at INTEGER DEFAULT 0,
    mode TEXT DEFAULT 'resmi',
    max_attempts INTEGER DEFAULT 1,
    exam_open INTEGER DEFAULT 1
)");

ensurePretesPeriodTargetColumns($pdo);
ensurePretesCbtProgramScopeColumns($pdo);

// 1. Periode Reguler & RPL aktif bersamaan
$pdo->exec("INSERT INTO master_pretes (periode, tahun_ajaran, status, target_program) VALUES
    ('Pretes Reguler 2026', '2026 - 2027', 'aktif', 'Reguler'),
    ('Pretes RPL 2026', '2026 - 2027', 'aktif', 'RPL')");

$pRpl = getActivePretesPeriod($pdo, 'rpl');
$pReg = getActivePretesPeriod($pdo, 'reguler');
$check(($pRpl['periode'] ?? '') === 'Pretes RPL 2026', 'Mahasiswa RPL harus mendapat periode aktif RPL.');
$check(($pReg['periode'] ?? '') === 'Pretes Reguler 2026', 'Mahasiswa Reguler harus mendapat periode aktif Reguler.');

$mhsRpl = ['id' => 10, 'role' => 'mahasiswa', 'jenis_program' => 'RPL'];
$mhsReg = ['id' => 11, 'role' => 'mahasiswa', 'jenis_program' => 'Reguler'];
$check(shouldShowDaftarPretesToStudent($pdo, $mhsRpl) === true, 'Menu Daftar Pretes harus tampil untuk mahasiswa RPL.');
$check(shouldShowDaftarPretesToStudent($pdo, $mhsReg) === true, 'Menu Daftar Pretes harus tampil untuk mahasiswa Reguler.');

// 2. Sesi CBT Reguler & RPL aktif bersamaan
$pdo->exec("INSERT INTO pretes_cbt_sessions (code, title, package_ids_json, is_active, mode, program_scope) VALUES
    ('SESI-REG', 'Sesi CBT Reguler', '[1]', 1, 'resmi', 'reguler'),
    ('SESI-RPL', 'Sesi CBT RPL', '[2]', 1, 'resmi', 'rpl')");

$sRpl = pretesCbtGetActiveSession($pdo, null, 'rpl');
$sReg = pretesCbtGetActiveSession($pdo, null, 'reguler');
$check(($sRpl['code'] ?? '') === 'SESI-RPL', 'Sesi CBT aktif untuk RPL harus SESI-RPL.');
$check(($sReg['code'] ?? '') === 'SESI-REG', 'Sesi CBT aktif untuk Reguler harus SESI-REG.');

$listRpl = pretesCbtGetActiveSessions($pdo, 'rpl');
$check(count($listRpl) === 1 && ($listRpl[0]['code'] ?? '') === 'SESI-RPL', 'Daftar sesi CBT RPL tidak boleh menyertakan sesi Reguler.');

if ($errors) {
    fwrite(STDERR, "FAILED:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}

echo "OK: pretes-portal-program-isolation-test\n";
