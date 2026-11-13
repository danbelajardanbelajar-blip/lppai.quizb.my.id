<?php
/**
 * Verification test suite for Mahasiswa Angkatan 2026 dataset,
 * SIAKAD 2026 column mapping, and upsertAngkatan2026Records() logic.
 */

require_once __DIR__ . '/../includes/user-helper.php';

$root = realpath(__DIR__ . '/..');
$errors = [];

function mhs2026Assert($cond, string $message, array &$errors): void {
    if (!$cond) {
        $errors[] = $message;
    }
}

// 1. Dataset validation (data/mahasiswa-angkatan-2026.php)
$records = require $root . '/data/mahasiswa-angkatan-2026.php';
mhs2026Assert(is_array($records), 'Dataset must return an array.', $errors);
mhs2026Assert(count($records) === 112, 'Dataset Angkatan 2026 must contain exactly 112 records, got ' . count($records), $errors);

$seenNims = [];
foreach ($records as $idx => $rec) {
    $nim = trim((string)($rec['nim'] ?? ''));
    $nama = trim((string)($rec['nama_lengkap'] ?? ''));
    $prodi = trim((string)($rec['program_studi'] ?? ''));
    $tgl = trim((string)($rec['tanggal_lahir'] ?? ''));
    $angkatan = trim((string)($rec['angkatan'] ?? ''));
    $jenis = trim((string)($rec['jenis_program'] ?? ''));

    mhs2026Assert($nim !== '', "Row #$idx has empty NIM.", $errors);
    mhs2026Assert(str_starts_with($nim, '26'), "Row #$idx ($nim) must start with '26'.", $errors);
    mhs2026Assert(!isset($seenNims[$nim]), "Duplicate NIM found in dataset: $nim", $errors);
    $seenNims[$nim] = true;

    mhs2026Assert($nama !== '', "Row #$idx ($nim) has empty nama_lengkap.", $errors);
    mhs2026Assert($prodi !== '', "Row #$idx ($nim) has empty program_studi.", $errors);
    mhs2026Assert($angkatan === '2026', "Row #$idx ($nim) angkatan must be '2026', got '$angkatan'.", $errors);
    mhs2026Assert($jenis === 'Reguler', "Row #$idx ($nim) jenis_program must be 'Reguler', got '$jenis'.", $errors);

    $parsedTgl = parseFlexibleDateToYmd($tgl);
    mhs2026Assert($parsedTgl !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsedTgl) === 1, "Row #$idx ($nim) tanggal_lahir must parse to YYYY-MM-DD (got '$tgl').", $errors);
}

// 2. SIAKAD 2026 column header detection
$siakad2026Header = [
    'No', 'NIM', 'NAMA', 'L/P', 'Jurusan', 'Sistem', 'Kelas', 'IPK',
    'TEMPAT LAHIR', 'TGL LAHIR', 'NAMA IBU', 'NO. KTP', 'ALAMAT',
    'DESA', 'KECAMATAN', 'KABUPATEN', 'PROPINSI', 'No HP', 'Email',
    'Asal SMA', 'NPSN SMA'
];
$dummyRows = [
    ['LAPORAN DAFTAR MAHASISWA'],
    $siakad2026Header,
    [1, '2601101019', 'BAMBANG ALIYANTO', 'L', 'Pendidikan Agama Islam', 'Sore', 'PAI B/Sore', '0', 'Lamongan', '3 November 1981', 'IBU', '123', 'ALM', 'DS', 'KEC', 'KAB', 'PROP', '08123456789', 'bambang@example.com', 'SMA', '12345']
];
$detected = detectUserImportHeaderAndMap($dummyRows);
mhs2026Assert($detected['header_row_index'] === 1, 'Header row index for SIAKAD 2026 should be 1, got ' . $detected['header_row_index'], $errors);
$map = $detected['col_map'];
mhs2026Assert(($map['nim'] ?? null) === 1, 'NIM column must map to index 1.', $errors);
mhs2026Assert(($map['nama_lengkap'] ?? null) === 2, 'NAMA column must map to index 2.', $errors);
mhs2026Assert(($map['program_studi'] ?? null) === 4, 'Jurusan column must map to index 4.', $errors);
mhs2026Assert(($map['kelas'] ?? null) === 6, 'Kelas column must map to index 6.', $errors);
mhs2026Assert(($map['tempat_lahir'] ?? null) === 8, 'TEMPAT LAHIR column must map to index 8.', $errors);
mhs2026Assert(($map['tanggal_lahir'] ?? null) === 9, 'TGL LAHIR column must map to index 9.', $errors);
mhs2026Assert(($map['no_hp'] ?? null) === 17, 'No HP column must map to index 17.', $errors);
mhs2026Assert(($map['email'] ?? null) === 18, 'Email column must map to index 18.', $errors);

// 3. SQLite In-Memory Database Simulation for upsertAngkatan2026Records()
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

// Pre-seed 1 record: NIM 2601101019 already in DB with older data
$firstRec = $records[0];
$pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nim, program_studi, jenis_program, angkatan, role, is_active) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([$firstRec['nim'], 'EXISTING_HASH', 'BAMBANG LAMA', $firstRec['nim'], 'PAI LAMA', 'RPL', '2025', 'mahasiswa', 0]);

// First migration run
$stats1 = upsertAngkatan2026Records($pdo, $records);
mhs2026Assert(empty($stats1['errors']), 'First run must have 0 errors.', $errors);
mhs2026Assert($stats1['total'] === 112, 'Total processed must be 112.', $errors);
mhs2026Assert($stats1['updated'] === 1, 'Expected 1 existing user to be updated to Reguler 2026 is_active=1.', $errors);
mhs2026Assert($stats1['inserted'] === 111, 'Expected 111 new users inserted, got ' . $stats1['inserted'], $errors);

// Verify pre-seeded user was updated to Reguler, 2026, is_active=1, preserving password
$stmt = $pdo->prepare("SELECT * FROM users WHERE nim = ?");
$stmt->execute([$firstRec['nim']]);
$u1 = $stmt->fetch(PDO::FETCH_ASSOC);
mhs2026Assert($u1['jenis_program'] === 'Reguler', 'User 1 jenis_program must be Reguler.', $errors);
mhs2026Assert($u1['angkatan'] === '2026', 'User 1 angkatan must be 2026.', $errors);
mhs2026Assert((int)$u1['is_active'] === 1, 'User 1 is_active must be 1.', $errors);
mhs2026Assert($u1['password'] === 'EXISTING_HASH', 'User 1 password must be preserved.', $errors);

// Verify a newly inserted user
$secondRec = $records[1]; // e.g. 2601101020
$stmt->execute([$secondRec['nim']]);
$u2 = $stmt->fetch(PDO::FETCH_ASSOC);
mhs2026Assert($u2 !== false, "User 2 ({$secondRec['nim']}) must exist.", $errors);
mhs2026Assert($u2['nama_lengkap'] === $secondRec['nama_lengkap'], 'User 2 name must match.', $errors);
mhs2026Assert($u2['jenis_program'] === 'Reguler', 'User 2 jenis_program must be Reguler.', $errors);
mhs2026Assert($u2['angkatan'] === '2026', 'User 2 angkatan must be 2026.', $errors);
mhs2026Assert((int)$u2['is_active'] === 1, 'User 2 is_active must be 1.', $errors);
mhs2026Assert($u2['role'] === 'mahasiswa', 'User 2 role must be mahasiswa.', $errors);

// Verify default password format (dmY)
$parsedTgl2 = parseFlexibleDateToYmd($secondRec['tanggal_lahir']);
$dt2 = DateTime::createFromFormat('!Y-m-d', $parsedTgl2);
$expectedPlainPass = $dt2 ? $dt2->format('dmY') : '123456';
mhs2026Assert(password_verify($expectedPlainPass, $u2['password']), "User 2 password hash must verify with '$expectedPlainPass'.", $errors);

// Second migration run (Idempotency test)
$stats2 = upsertAngkatan2026Records($pdo, $records);
mhs2026Assert(empty($stats2['errors']), 'Second run must have 0 errors.', $errors);
mhs2026Assert($stats2['inserted'] === 0, 'Second run must have 0 inserted.', $errors);
mhs2026Assert($stats2['updated'] === 0, 'Second run must have 0 updated.', $errors);
mhs2026Assert($stats2['skipped'] === 112, 'Second run must skip all 112 records.', $errors);

if (!empty($errors)) {
    echo "FAILED: " . count($errors) . " test assertions failed:\n";
    foreach ($errors as $e) {
        echo " - $e\n";
    }
    exit(1);
}

echo "PASSED: All 112 Mahasiswa Angkatan 2026 dataset, SIAKAD 2026 column mapping, and upsert logic verified successfully!\n";
exit(0);
