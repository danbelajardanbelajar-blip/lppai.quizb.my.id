<?php
/**
 * Verification tests for pretes period `target_program` (`Semua` / `RPL` / `Reguler`),
 * dynamic period schedule dates (e.g., 16-20 November 2026), period quota isolation,
 * and visibility gating across registration, sidebar, dashboard, and results pages.
 */

require_once __DIR__ . '/../includes/user-helper.php';
require_once __DIR__ . '/../includes/pretes-helper.php';

$root = realpath(__DIR__ . '/..');
$errors = [];

function tpAssert($cond, string $message, array &$errors): void {
    if (!$cond) {
        $errors[] = $message;
    }
}

// 1. Allowed & normalized target programs
$allowed = getAllowedPretesTargetPrograms();
tpAssert($allowed === ['Semua', 'RPL', 'Reguler'], 'Allowed target programs must be Semua, RPL, Reguler.', $errors);
tpAssert(normalizePretesTargetProgram('RPL') === 'RPL', 'RPL normalizes to RPL.', $errors);
tpAssert(normalizePretesTargetProgram('khusus rpl') === 'RPL', 'Label with RPL normalizes to RPL.', $errors);
tpAssert(normalizePretesTargetProgram('Reguler') === 'Reguler', 'Reguler normalizes to Reguler.', $errors);
tpAssert(normalizePretesTargetProgram('reguler') === 'Reguler', 'Lowercase reguler normalizes to Reguler.', $errors);
tpAssert(normalizePretesTargetProgram('') === 'Semua', 'Empty target normalizes to Semua.', $errors);
tpAssert(normalizePretesTargetProgram('lainnya') === 'Semua', 'Unknown target falls back to Semua.', $errors);

// 2. Auto-migration on SQLite + eligibility & sidebar gating
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE master_pretes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    periode TEXT NOT NULL,
    tahun_ajaran TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'aktif'
)");
ensurePretesPeriodTargetColumns($pdo);
ensurePretesPeriodTargetColumns($pdo); // idempotent

$cols = $pdo->query("PRAGMA table_info(master_pretes)")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($cols, 'name');
tpAssert(in_array('target_program', $colNames, true), 'ensurePretesPeriodTargetColumns must add target_program.', $errors);
tpAssert(in_array('tanggal_mulai', $colNames, true), 'ensurePretesPeriodTargetColumns must add tanggal_mulai.', $errors);

$pdo->prepare("INSERT INTO master_pretes (periode, tahun_ajaran, status, target_program, tanggal_mulai) VALUES (?, ?, ?, ?, ?)")
    ->execute(['Pretes RPL 2026/2027', '2026 - 2027', 'aktif', 'RPL', '2026-11-16']);

$activePeriod = getActivePretesPeriod($pdo);
tpAssert($activePeriod !== null && ($activePeriod['periode'] ?? '') === 'Pretes RPL 2026/2027', 'getActivePretesPeriod must fetch active RPL period.', $errors);

$rplStudent = ['id' => 10, 'role' => 'mahasiswa', 'jenis_program' => 'RPL'];
$regStudent = ['id' => 11, 'role' => 'mahasiswa', 'jenis_program' => 'Reguler'];
$adminUser  = ['id' => 1, 'role' => 'admin', 'jenis_program' => 'Reguler'];

tpAssert(isUserEligibleForPretesPeriod($rplStudent, $activePeriod) === true, 'RPL student must be eligible for RPL period.', $errors);
tpAssert(isUserEligibleForPretesPeriod($regStudent, $activePeriod) === false, 'Reguler student must NOT be eligible for RPL period.', $errors);
tpAssert(isUserEligibleForPretesPeriod($adminUser, $activePeriod) === true, 'Admin role must not be blocked.', $errors);
tpAssert(isUserEligibleForPretesPeriod($rplStudent, ['status' => 'nonaktif', 'target_program' => 'RPL']) === false, 'Inactive period must deny registration.', $errors);
tpAssert(isUserEligibleForPretesPeriod($regStudent, ['status' => 'aktif', 'target_program' => 'Semua']) === true, 'Semua period must allow Reguler student.', $errors);
tpAssert(isUserEligibleForPretesPeriod($rplStudent, ['status' => 'aktif', 'target_program' => 'Semua']) === true, 'Semua period must allow RPL student.', $errors);

tpAssert(shouldShowDaftarPretesToStudent($pdo, $rplStudent) === true, 'Sidebar must show Daftar Pretes to RPL student during RPL period.', $errors);
tpAssert(shouldShowDaftarPretesToStudent($pdo, $regStudent) === false, 'Sidebar must hide Daftar Pretes from Reguler student during RPL period.', $errors);

$pdo->exec("UPDATE master_pretes SET status = 'selesai'");
tpAssert(shouldShowDaftarPretesToStudent($pdo, $rplStudent) === false, 'Sidebar must hide Daftar Pretes when no period is active.', $errors);

// 3. Dynamic schedule dates for 16-20 November 2026 & cross-month boundary
$schedRpl = getPretesPeriodDayLabels(['tanggal_mulai' => '2026-11-16']);
tpAssert(($schedRpl['days']['Senin'] ?? '') === 'Senin, 16 November 2026', 'Senin label must be Senin, 16 November 2026.', $errors);
tpAssert(($schedRpl['days']['Jumat'] ?? '') === 'Jumat, 20 November 2026', 'Jumat label must be Jumat, 20 November 2026.', $errors);
tpAssert($schedRpl['range_label'] === '16 sampai 20 November 2026', 'range_label must be 16 sampai 20 November 2026.', $errors);
tpAssert($schedRpl['short_range'] === '16 – 20 November 2026', 'short_range must be 16 – 20 November 2026.', $errors);

$schedCross = getPretesPeriodDayLabels(['tanggal_mulai' => '2026-11-30']);
tpAssert($schedCross['range_label'] === '30 November sampai 4 Desember 2026', 'Cross-month range must format correctly.', $errors);

$schedEmpty = getPretesPeriodDayLabels(['tanggal_mulai' => '']);
tpAssert(($schedEmpty['days']['Senin'] ?? '') === 'Senin' && $schedEmpty['range_label'] === '', 'Empty tanggal_mulai must fall back to day names cleanly.', $errors);

// 4. Integration & isolation checks across PHP files
$daftarSrc = file_get_contents($root . '/pages/pretes-daftar.php');
tpAssert(str_contains($daftarSrc, 'isUserEligibleForPretesPeriod('), 'pages/pretes-daftar.php must enforce isUserEligibleForPretesPeriod().', $errors);
tpAssert(!str_contains($daftarSrc, "OR periode LIKE '%2026%'"), 'pages/pretes-daftar.php must not mix 2026/2027 periods when counting quota.', $errors);
tpAssert(!str_contains($daftarSrc, '2 sampai 6 November 2026'), 'pages/pretes-daftar.php must not hardcode 2-6 November 2026.', $errors);

$dosenSrc = file_get_contents($root . '/admin/pretes-dosen.php');
tpAssert(str_contains($dosenSrc, 'name="target_program"') && str_contains($dosenSrc, 'name="tanggal_mulai"'), 'admin/pretes-dosen.php must expose target_program and tanggal_mulai inputs.', $errors);
tpAssert(!str_contains($dosenSrc, "OR periode LIKE '%2026%'"), 'admin/pretes-dosen.php must not mix 2026/2027 periods in quota summary.', $errors);

$jadwalSrc = file_get_contents($root . '/admin/pretes-jadwal.php');
tpAssert(!str_contains($jadwalSrc, "OR periode LIKE '%2026%'") && !str_contains($jadwalSrc, "OR pr.periode LIKE '%2026%'"), 'admin/pretes-jadwal.php must isolate plotting, reset, quota, and participant table to active periode.', $errors);

$sidebarSrc = file_get_contents($root . '/includes/sidebar.php');
tpAssert(str_contains($sidebarSrc, 'shouldShowDaftarPretesToStudent('), 'includes/sidebar.php must gate Daftar Pretes link via shouldShowDaftarPretesToStudent().', $errors);

$dashboardSrc = file_get_contents($root . '/pages/dashboard.php');
tpAssert(str_contains($dashboardSrc, '$isEligibleActivePretes'), 'pages/dashboard.php must filter pendaftaran_pretes announcements by target eligibility.', $errors);

$hasilSrc = file_get_contents($root . '/pages/pretes-hasil.php');
tpAssert(str_contains($hasilSrc, '$isEligibleActivePretes'), 'pages/pretes-hasil.php must hide Daftar Pretes button for ineligible programs.', $errors);

$pesertaSrc = file_get_contents($root . '/admin/pretes-peserta.php');
tpAssert(
    str_contains($pesertaSrc, '$selectedPeriode') && str_contains($pesertaSrc, 'name="target_periode"') && str_contains($pesertaSrc, 'pr.periode = ?'),
    'admin/pretes-peserta.php must scope participants and bulk credential generation by selected periode.',
    $errors
);
tpAssert(
    str_contains($pesertaSrc, 'id="filter-jenis-program"') && str_contains($pesertaSrc, 'data-prodi=') && str_contains($pesertaSrc, 'data-jenis=') && str_contains($pesertaSrc, 'jenis_program'),
    'admin/pretes-peserta.php must support Jenis Program filter, exact prodi/jenis attributes, and export forwarding.',
    $errors
);

$pesertaExportSrc = file_get_contents($root . '/admin/pretes-peserta-export.php');
tpAssert(
    str_contains($pesertaExportSrc, "['periode']") && str_contains($pesertaExportSrc, 'pr.periode = ?') && str_contains($pesertaExportSrc, '<select name="periode"'),
    'admin/pretes-peserta-export.php must support periode parameter filtering and UI selector.',
    $errors
);

if (!empty($errors)) {
    foreach ($errors as $err) {
        fwrite(STDERR, "[FAIL] $err\n");
    }
    exit(1);
}

echo "OK: pretes-target-program tests passed (target filtering, 16-20 Nov 2026 dynamic dates, & period quota isolation verified).\n";
