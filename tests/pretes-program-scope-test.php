<?php
declare(strict_types=1);

/**
 * Unit test untuk fondasi scope program Pretes (RPL / Reguler).
 */

require_once __DIR__ . '/../includes/pretes-program-scope.php';

$errors = [];

function psAssert(bool $cond, string $message, array &$errors): void
{
    if (!$cond) {
        $errors[] = $message;
    }
}

// 1. Resolusi program & proteksi konflik GET/POST
psAssert(pretesAdminResolveProgram('rpl', [], []) === 'rpl', 'Fixed RPL harus menghasilkan rpl.', $errors);
psAssert(pretesAdminResolveProgram('reguler', ['program' => 'reguler'], []) === 'reguler', 'Program sama dengan fixed harus diterima.', $errors);
psAssert(pretesAdminResolveProgram(null, ['program' => 'RPL'], []) === 'rpl', 'Query RPL harus ternormalisasi ke rpl.', $errors);
psAssert(pretesAdminResolveProgram(null, [], []) === null, 'Tanpa fixed dan query harus mengembalikan null.', $errors);

$conflictThrown = false;
try {
    pretesAdminResolveProgram('rpl', ['program' => 'reguler'], []);
} catch (DomainException $e) {
    $conflictThrown = true;
}
psAssert($conflictThrown, 'Konflik program GET terhadap fixed RPL harus melempar DomainException.', $errors);

$invalidThrown = false;
try {
    pretesAdminResolveProgram(null, ['program' => 'lainnya'], []);
} catch (InvalidArgumentException $e) {
    $invalidThrown = true;
}
psAssert($invalidThrown, 'Program tidak valid harus melempar InvalidArgumentException.', $errors);

// 2. Label dan target_program
psAssert(pretesAdminProgramLabel('rpl') === 'RPL', 'Label rpl harus RPL.', $errors);
psAssert(pretesAdminProgramLabel('reguler') === 'Reguler', 'Label reguler harus Reguler.', $errors);
psAssert(pretesAdminProgramLabel(null) === '', 'Label null harus string kosong.', $errors);
psAssert(pretesAdminTargetProgramValue('rpl') === 'RPL', 'Target rpl harus RPL.', $errors);
psAssert(pretesAdminTargetProgramValue('reguler') === 'Reguler', 'Target reguler harus Reguler.', $errors);

// 3. Route mapping RPL vs Reguler
psAssert(pretesAdminRoute('tutor', 'rpl') === '/admin/pretes-rpl-pengaturan-tutor.php', 'Route tutor RPL sesuai.', $errors);
psAssert(pretesAdminRoute('tutor', 'reguler') === '/admin/pretes-pengaturan-tutor.php', 'Route tutor Reguler sesuai.', $errors);
psAssert(pretesAdminRoute('cbt_bank', 'rpl') === '/pretes-ujian.php?cbt_bank=1&program=rpl', 'Route CBT bank RPL menyertakan program=rpl.', $errors);
psAssert(pretesAdminRoute('rekap', 'rpl', ['pretes_id' => 5]) === '/admin/pretes-rpl-rekap-nilai.php?pretes_id=5', 'Route rekap RPL meneruskan query.', $errors);

// 4. Filter SQL & auto-migration program_scope di SQLite in-memory
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE master_pretes (id INTEGER PRIMARY KEY, periode TEXT, target_program TEXT)");
$pdo->exec("INSERT INTO master_pretes VALUES (1, 'Periode Reg', 'Reguler'), (2, 'Periode RPL', 'RPL'), (3, 'Periode Lama', 'Semua')");

$whereRpl = pretesPeriodTargetWhere('rpl');
$stmt = $pdo->prepare("SELECT periode FROM master_pretes WHERE {$whereRpl['sql']}");
$stmt->execute($whereRpl['params']);
$rplPeriods = $stmt->fetchAll(PDO::FETCH_COLUMN);
psAssert($rplPeriods === ['Periode RPL'], 'Filter periode RPL ketat hanya mengambil RPL.', $errors);

$whereReg = pretesPeriodTargetWhere('reguler');
$stmt = $pdo->prepare("SELECT periode FROM master_pretes WHERE {$whereReg['sql']}");
$stmt->execute($whereReg['params']);
$regPeriods = $stmt->fetchAll(PDO::FETCH_COLUMN);
psAssert($regPeriods === ['Periode Reg'], 'Filter periode Reguler ketat hanya mengambil Reguler.', $errors);

$pdo->exec("CREATE TABLE pretes_cbt_packages (id INTEGER PRIMARY KEY, code TEXT)");
$pdo->exec("CREATE TABLE pretes_cbt_sessions (id INTEGER PRIMARY KEY, code TEXT)");
$pdo->exec("INSERT INTO pretes_cbt_packages (id, code) VALUES (1, 'PKT-LAMA')");

ensurePretesCbtProgramScopeColumns($pdo);
ensurePretesCbtProgramScopeColumns($pdo); // idempoten

$pkgCols = array_column($pdo->query("PRAGMA table_info(pretes_cbt_packages)")->fetchAll(PDO::FETCH_ASSOC), 'name');
$sesCols = array_column($pdo->query("PRAGMA table_info(pretes_cbt_sessions)")->fetchAll(PDO::FETCH_ASSOC), 'name');
psAssert(in_array('program_scope', $pkgCols, true), 'Kolom program_scope harus ada di pretes_cbt_packages.', $errors);
psAssert(in_array('program_scope', $sesCols, true), 'Kolom program_scope harus ada di pretes_cbt_sessions.', $errors);

$legacyScope = $pdo->query("SELECT program_scope FROM pretes_cbt_packages WHERE id = 1")->fetchColumn();
psAssert($legacyScope === null, 'Baris paket lama harus tetap NULL (tidak diubah).', $errors);

if ($errors) {
    fwrite(STDERR, "FAILED:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}

echo "OK: pretes-program-scope-test\n";
