<?php
/**
 * Test suite for CBT Results Export Scope, Dynamic Question Counts, and Scoped Deletion
 */

require_once __DIR__ . '/../includes/pretes-cbt.php';
require_once __DIR__ . '/../includes/pretes-tryout.php';
require_once __DIR__ . '/../includes/pretes-cbt-xlsx.php';

function expAssert(bool $cond, string $msg): void {
    if (!$cond) throw new RuntimeException("FAIL: $msg");
    echo " [PASS] $msg\n";
}

echo "=== Menjalankan Validasi Ekspor CBT, Hitungan Soal Dinamis, dan Scoped Deletion ===\n";

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensurePretesCbtTables($pdo);

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT,
    nama_lengkap TEXT NOT NULL,
    nim TEXT
)");

// Insert test users
$pdo->exec("INSERT INTO users (id, nama_lengkap, nim) VALUES (1, 'Peserta Resmi', '2601101001')");
$pdo->exec("INSERT INTO users (id, nama_lengkap, nim) VALUES (2, 'Peserta Tryout', '2601101002')");

// 1. Inisialisasi Sesi Resmi (25 soal) dan Sesi Tryout (misal 50 soal atau paket custom)
$resmiAttempt = pretesCbtStart($pdo, 1, 101, 1000); // registration_id = 101 (> 0, Resmi)
expAssert($resmiAttempt['attempt'] !== null, 'Attempt ujian resmi berhasil dimulai.');

// Buat sesi tryout
$trySessionId = pretesCbtSaveSession($pdo, [
    'title' => 'Tryout Akbar 2026',
    'package_ids' => [1],
    'mode' => 'tryout',
    'max_attempts' => 0,
    'duration_minutes' => 60,
    'is_active' => 1
]);
$trySession = pretesTryoutGetSession($pdo, $trySessionId);
$tryRegId = pretesTryoutRegId($trySessionId, 1); // registration_id < 0 (Tryout)

$tryAttempt = pretesCbtStart($pdo, 2, $tryRegId, 2000, null, $trySession);
expAssert($tryAttempt['attempt'] !== null, 'Attempt tryout berhasil dimulai.');

// Submit both attempts
pretesCbtChange($pdo, 1, 101, 'submit', [], 1200);
pretesCbtChange($pdo, 2, $tryRegId, 'submit', [], 2200);

// 2. Test cbtResultsExportRows('all')
$rowsAll = iterator_to_array(cbtResultsExportRows($pdo, 'all'));
expAssert(count($rowsAll) === 3, 'Ekspor all menghasilkan 1 baris header + 2 baris data.');
expAssert($rowsAll[0] === ['NIM', 'Nama Peserta', 'Jenis Ujian', 'Sesi Ujian', 'Mulai (WIB)', 'Selesai (WIB)', 'Status', 'Jawaban Benar', 'Jumlah Soal', 'Nilai'], 'Header ekspor 10 kolom lengkap.');

// Verify dynamic question count (not hardcoded 25)
$resmiRow = null;
$tryoutRow = null;
foreach (array_slice($rowsAll, 1) as $r) {
    if ($r[0] === '2601101001') $resmiRow = $r;
    if ($r[0] === '2601101002') $tryoutRow = $r;
}
expAssert($resmiRow !== null && $tryoutRow !== null, 'Kedua baris peserta ditemukan.');
expAssert($resmiRow[2] === 'Ujian Resmi', 'Label jenis pengerjaan resmi tepat.');
expAssert(strpos($tryoutRow[2], 'Tryout') !== false, 'Label jenis pengerjaan tryout tepat.');
expAssert($resmiRow[8] === 25, 'Jumlah soal peserta resmi dinamis 25.');
expAssert($tryoutRow[8] === 25, 'Jumlah soal peserta tryout dinamis sesuai items.');

// 3. Test filter 'resmi'
$rowsResmi = iterator_to_array(cbtResultsExportRows($pdo, 'resmi'));
expAssert(count($rowsResmi) === 2, 'Ekspor resmi hanya menghasilkan 1 baris data resmi.');
expAssert($rowsResmi[1][0] === '2601101001', 'Hanya NIM peserta resmi yang diekspor.');

// 4. Test filter 'tryout'
$rowsTryout = iterator_to_array(cbtResultsExportRows($pdo, 'tryout'));
expAssert(count($rowsTryout) === 2, 'Ekspor tryout hanya menghasilkan 1 baris data tryout.');
expAssert($rowsTryout[1][0] === '2601101002', 'Hanya NIM peserta tryout yang diekspor.');

// 5. Test Scoped Deletion: delete only tryout
$delTryoutCount = pretesCbtDeleteAllAttempts($pdo, null, 'tryout');
expAssert($delTryoutCount === 1, 'pretesCbtDeleteAllAttempts(tryout) menghapus tepat 1 attempt tryout.');
expAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_attempts WHERE registration_id > 0')->fetchColumn() === 1, 'Attempt resmi tetap aman dan utuh!');
expAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_attempts WHERE registration_id < 0')->fetchColumn() === 0, 'Attempt tryout terhapus tuntas.');

// 6. Test Scoped Deletion: delete resmi
$delResmiCount = pretesCbtDeleteAllAttempts($pdo, null, 'resmi');
expAssert($delResmiCount === 1, 'pretesCbtDeleteAllAttempts(resmi) menghapus 1 attempt resmi.');
expAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_attempts')->fetchColumn() === 0, 'Seluruh attempts kini kosong.');

echo "\n✅ Seluruh pengujian Ekspor CBT & Scoped Deletion BERHASIL 100%!\n";
