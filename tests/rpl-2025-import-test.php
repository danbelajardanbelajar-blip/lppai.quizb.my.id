<?php
/**
 * Verification tests for RPL Angkatan 2025 dataset, flexible SIAKAD header/date helpers,
 * and idempotent upsertRplUserRecords() logic.
 */

require_once __DIR__ . '/../includes/user-helper.php';

$root = realpath(__DIR__ . '/..');
$errors = [];

function rplAssert($cond, string $message, array &$errors): void {
    if (!$cond) {
        $errors[] = $message;
    }
}

// 1. Dataset validation (data/rpl-angkatan-2025.php)
$records = require $root . '/data/rpl-angkatan-2025.php';
rplAssert(is_array($records) && count($records) === 123, 'Dataset RPL 2025 must contain exactly 123 records.', $errors);

$seenNims = [];
foreach ($records as $idx => $rec) {
    $nim = trim((string)($rec['nim'] ?? ''));
    $nama = trim((string)($rec['nama_lengkap'] ?? ''));
    $prodi = trim((string)($rec['program_studi'] ?? ''));
    $tgl = trim((string)($rec['tanggal_lahir'] ?? ''));

    rplAssert($nim !== '', "Row $idx must have non-empty NIM.", $errors);
    rplAssert(!isset($seenNims[$nim]), "Duplicate NIM found in RPL 2025 dataset: $nim", $errors);
    $seenNims[$nim] = true;
    rplAssert($nama !== '', "Row $idx ($nim) must have non-empty nama_lengkap.", $errors);
    rplAssert($prodi !== '', "Row $idx ($nim) must have non-empty program_studi.", $errors);
    $parsedTgl = parseFlexibleDateToYmd($tgl);
    rplAssert($parsedTgl !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsedTgl) === 1, "Row $idx ($nim) tanggal_lahir must parse to YYYY-MM-DD (got '$tgl').", $errors);
}

// 2. Indonesian & mixed date parsing tests
$dateCases = [
    '4 April 1997'     => '1997-04-04',
    '7 Nopember 1983'  => '1983-11-07',
    '29 Desember 1994' => '1994-12-29',
    '15 Maret 1984'    => '1984-03-15',
    '1 Mei 1997'       => '1997-05-01',
    '01031990'         => '1990-03-01',
    '14/01/1986'       => '1986-01-14',
    '2001-09-12'       => '2001-09-12',
];
foreach ($dateCases as $input => $expected) {
    $actual = parseFlexibleDateToYmd($input);
    rplAssert($actual === $expected, "parseFlexibleDateToYmd('$input') expected '$expected', got '" . ($actual ?? 'null') . "'.", $errors);
}
rplAssert(parseFlexibleDateToYmd('bukan-tanggal') === null, 'Invalid date string must return null.', $errors);

// 3. SIAKAD multi-row HTML/Excel header detection & mapping
$siakadRows = [
    ['LAPORAN DAFTAR MAHASISWA UNISDA'],
    ['Angkatan Masuk : 2025 | Program : RPL'],
    [],
    ['NO', 'NIM', 'ANGKATAN', 'NAMA', 'JK', 'NO TEST', 'STATUS', 'KELAS', 'JURUSAN', 'TEMPAT LAHIR', 'TGL LAHIR', 'NAMA IBU', 'NAMA AYAH', 'NO HP', 'EMAIL'],
    [1, '2501101046', '2025', 'AHMAD FAUZI', 'L', '-', 'Aktif', 'RPL 2025/2026 Ganjil', 'Pendidikan Agama Islam', 'Lamongan', '4 April 1997', 'SITI AMINAH', 'KASAN', '081234567890', 'fauzi@example.com'],
];
$detected = detectUserImportHeaderAndMap($siakadRows);
rplAssert($detected['header_row_index'] === 3, 'Header row index should be detected at index 3.', $errors);
$map = $detected['col_map'];
rplAssert(($map['nim'] ?? null) === 1, 'NIM column index must be 1.', $errors);
rplAssert(($map['nama_lengkap'] ?? null) === 3, 'NAMA column index must be 3 and NOT overwritten by NAMA IBU (11).', $errors);
rplAssert(($map['kelas'] ?? null) === 7, 'KELAS column index must be 7.', $errors);
rplAssert(($map['program_studi'] ?? null) === 8, 'JURUSAN must map to program_studi at index 8.', $errors);
rplAssert(($map['tempat_lahir'] ?? null) === 9, 'TEMPAT LAHIR must map to index 9.', $errors);
rplAssert(($map['tanggal_lahir'] ?? null) === 10, 'TGL LAHIR must map to index 10.', $errors);
rplAssert(($map['no_hp'] ?? null) === 13, 'NO HP must map to index 13.', $errors);
rplAssert(($map['email'] ?? null) === 14, 'EMAIL must map to index 14.', $errors);

// 4. upsertRplUserRecords() on SQLite: existing Reguler update vs already-RPL skip vs new insert
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    password TEXT NOT NULL,
    nama_lengkap TEXT NOT NULL,
    nim TEXT,
    tempat_lahir TEXT,
    email TEXT,
    no_hp TEXT,
    program_studi TEXT,
    jenis_program TEXT NOT NULL DEFAULT 'Reguler',
    angkatan TEXT,
    tanggal_lahir TEXT,
    role TEXT NOT NULL DEFAULT 'mahasiswa',
    is_active INTEGER NOT NULL DEFAULT 0
)");

$firstRec = $records[0];
$secondRec = $records[1];

$pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nim, program_studi, jenis_program, angkatan, role, is_active) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([$firstRec['nim'], 'KEEP_EXISTING_HASH', 'NAMA EKSISTING', $firstRec['nim'], 'PRODI LAMA', 'Reguler', '2025', 'mahasiswa', 1]);
$pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nim, program_studi, jenis_program, angkatan, role, is_active) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([$secondRec['nim'], 'KEEP_RPL_HASH', 'SUDAH RPL', $secondRec['nim'], 'PRODI RPL', 'RPL', '2025', 'mahasiswa', 1]);

$summary1 = upsertRplUserRecords($pdo, $records);
rplAssert(empty($summary1['errors']), 'Full 123-record upsert must produce 0 errors.', $errors);
rplAssert($summary1['total'] === 123, 'Total processed must be 123.', $errors);
rplAssert($summary1['updated'] === 1, 'Expected 1 existing Reguler NIM to update to RPL.', $errors);
rplAssert($summary1['skipped'] === 1, 'Expected 1 already-RPL NIM to be skipped.', $errors);
rplAssert($summary1['inserted'] === 121, 'Expected 121 new NIMs to be inserted.', $errors);

// Verify existing Reguler row only updated jenis_program, preserving name/password/is_active
$updatedExisting = $pdo->prepare("SELECT * FROM users WHERE nim = ?");
$updatedExisting->execute([$firstRec['nim']]);
$rowExisting = $updatedExisting->fetch(PDO::FETCH_ASSOC);
rplAssert(($rowExisting['jenis_program'] ?? null) === 'RPL', 'Existing NIM jenis_program must be updated to RPL.', $errors);
rplAssert(($rowExisting['nama_lengkap'] ?? null) === 'NAMA EKSISTING', 'Existing NIM nama_lengkap must not be overwritten.', $errors);
rplAssert(($rowExisting['password'] ?? null) === 'KEEP_EXISTING_HASH', 'Existing NIM password must not be overwritten.', $errors);
rplAssert((int)($rowExisting['is_active'] ?? 0) === 1, 'Existing NIM is_active must not be overwritten.', $errors);

// Verify newly inserted row defaults & password format (ddmmyyyy)
$thirdRec = $records[2];
$insertedNew = $pdo->prepare("SELECT * FROM users WHERE nim = ?");
$insertedNew->execute([$thirdRec['nim']]);
$rowNew = $insertedNew->fetch(PDO::FETCH_ASSOC);
rplAssert(($rowNew['jenis_program'] ?? null) === 'RPL', 'New NIM must have jenis_program = RPL.', $errors);
rplAssert(($rowNew['angkatan'] ?? null) === '2025', 'New NIM must have angkatan = 2025.', $errors);
rplAssert(($rowNew['role'] ?? null) === 'mahasiswa', 'New NIM must have role = mahasiswa.', $errors);
rplAssert((int)($rowNew['is_active'] ?? 1) === 0, 'New 2025 NIM must have is_active = 0.', $errors);
$thirdYmd = parseFlexibleDateToYmd($thirdRec['tanggal_lahir']);
$expectedPass = $thirdYmd ? DateTimeImmutable::createFromFormat('Y-m-d', $thirdYmd)->format('dmY') : '';
rplAssert($expectedPass !== '' && password_verify($expectedPass, $rowNew['password']), 'New NIM password must match ddmmyyyy of tanggal_lahir.', $errors);

// Second run must be completely idempotent
$summary2 = upsertRplUserRecords($pdo, $records);
rplAssert($summary2['inserted'] === 0 && $summary2['updated'] === 0 && $summary2['skipped'] === 123, 'Second upsert run must be 100% idempotent (0 inserted, 0 updated, 123 skipped).', $errors);

if (!empty($errors)) {
    foreach ($errors as $err) {
        fwrite(STDERR, "[FAIL] $err\n");
    }
    exit(1);
}

echo "OK: rpl-2025-import tests passed (123 RPL 2025 records & SIAKAD/idempotency assertions).\n";
