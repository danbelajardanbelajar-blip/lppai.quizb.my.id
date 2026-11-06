<?php
/**
 * Test Verifikasi Fitur Impor Hasil CBT Pretes
 * Jalankan: php tests/pretes-cbt-import-test.php
 */

require_once __DIR__ . '/../includes/pretes-helper.php';
require_once __DIR__ . '/../includes/pretes-cbt-importer.php';

function assertTest($cond, $msg) {
    if (!$cond) {
        throw new RuntimeException("Assertion failed: $msg");
    }
}

echo "Memulai pengujian Fitur Impor Nilai CBT Pretes...\n";

// 1. Pengujian normalizePretesDay
assertTest(normalizePretesDay('senin') === 'Senin', 'Normalisasi senin harus Senin');
assertTest(normalizePretesDay('kamis') === 'Kamis', 'Normalisasi kamis harus Kamis');
assertTest(normalizePretesDay('Jumat') === 'Jumat', 'Normalisasi Jumat harus Jumat');
assertTest(normalizePretesDay("jum'at") === 'Jumat', "Normalisasi jum'at harus Jumat");
assertTest(normalizePretesDay('selasa') === 'Selasa', 'Normalisasi selasa harus Selasa');
assertTest(normalizePretesDay('rabu') === 'Rabu', 'Normalisasi rabu harus Rabu');

// 2. Pengujian detectPretesCbtColumns dengan header persis dari user
$userHeader = ['No.', 'Nomor Induk', 'Nama', 'Kelas', 'Nilai', 'Keterangan'];
$map = detectPretesCbtColumns($userHeader);
assertTest($map['no'] === 0, 'Kolom no harus index 0');
assertTest($map['nim'] === 1, 'Kolom nim harus index 1');
assertTest($map['nama'] === 2, 'Kolom nama harus index 2');
assertTest($map['kelas'] === 3, 'Kolom kelas harus index 3');
assertTest($map['nilai'] === 4, 'Kolom nilai harus index 4');
assertTest($map['keterangan'] === 5, 'Kolom keterangan harus index 5');

// Variasi header lain
$altHeader = ['No', 'NIM', 'Nama Mahasiswa', 'Hari', 'Nilai CBT', 'Status'];
$mapAlt = detectPretesCbtColumns($altHeader);
assertTest($mapAlt['nim'] === 1, 'Alt header NIM harus index 1');
assertTest($mapAlt['kelas'] === 3, 'Alt header Hari harus index 3');
assertTest($mapAlt['nilai'] === 4, 'Alt header Nilai CBT harus index 4');

// 3. Pengujian parsePretesCbtText dengan cuplikan data user
$sampleText = <<<EOC
No.,Nomor Induk,Nama,Kelas,Nilai,Keterangan
1,24042061,AHMAD NASIK MAULAL HAQQI,Jumat,96,Lulus
1,24081002,ABDUL AZIZ ASY'ARI,kamis,0,Tidak Lulus
2,24032035,AFIYATUS SHOLICHAH,senin,54,Tidak Lulus
EOC;

$parsedRows = parsePretesCbtText($sampleText);
assertTest(count($parsedRows) === 4, 'Harus ada 4 baris yang terurai (1 header + 3 data)');
assertTest($parsedRows[1][1] === '24042061', 'NIM baris 1 harus 24042061');
assertTest($parsedRows[1][4] === '96', 'Nilai baris 1 harus 96');
assertTest($parsedRows[2][1] === '24081002', "NIM baris 2 harus 24081002");
assertTest($parsedRows[2][4] === '0', 'Nilai baris 2 harus 0');

// 4. Pengujian Database In-Memory (SQLite) untuk End-to-End Import
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Buat skema SQLite
$pdo->exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nim VARCHAR(50) NOT NULL UNIQUE,
    nama_lengkap VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'mahasiswa',
    program_studi VARCHAR(100),
    fakultas VARCHAR(100),
    no_hp VARCHAR(50)
)");

$pdo->exec("CREATE TABLE pretes_registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    hari_pilihan VARCHAR(50),
    jam VARCHAR(50),
    periode VARCHAR(100),
    status VARCHAR(50) DEFAULT 'terdaftar',
    dosen_penguji VARCHAR(255),
    ruangan VARCHAR(255),
    tanggal_daftar TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    username_tes VARCHAR(100),
    password_tes VARCHAR(100)
)");

$pdo->exec("CREATE TABLE pretes_assessments (
    registration_id INTEGER NOT NULL PRIMARY KEY,
    scores_json TEXT NOT NULL,
    assessed_by INTEGER NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE pretes_attendance_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scope_hash CHAR(64) NOT NULL UNIQUE,
    periode VARCHAR(100) NOT NULL,
    hari VARCHAR(20) NOT NULL,
    penguji VARCHAR(255) NOT NULL,
    ruangan VARCHAR(255) NOT NULL,
    token CHAR(48) NOT NULL UNIQUE,
    open_until DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE pretes_attendance_records (
    registration_id INTEGER NOT NULL PRIMARY KEY,
    session_id INTEGER NOT NULL,
    attended_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Seed Mahasiswa
$stmtSeedUser = $pdo->prepare("INSERT INTO users (id, nim, nama_lengkap) VALUES (?, ?, ?)");
$stmtSeedUser->execute([1, '24042061', 'AHMAD NASIK MAULAL HAQQI']);
$stmtSeedUser->execute([2, '24081002', "ABDUL AZIZ ASY'ARI"]);
$stmtSeedUser->execute([3, '24032035', 'AFIYATUS SHOLICHAH']);

// Mahasiswa 1 sudah terdaftar sebelumnya dan sudah memiliki nilai praktik wudlu 90
$periodeTest = 'Pretes Gelombang 1 2026/2027';
$pdo->exec("INSERT INTO pretes_registrations (id, user_id, hari_pilihan, jam, periode) VALUES (10, 1, 'Jumat', '13.00 WIB (Jam 1)', '$periodeTest')");
$existingScores = ['praktik_wudlu' => 90.0, 'ujian_tulis' => null];
$pdo->prepare("INSERT INTO pretes_assessments (registration_id, scores_json, assessed_by) VALUES (10, ?, 1)")->execute([json_encode($existingScores)]);

// Jalankan import
$res = importPretesCbtData($pdo, $parsedRows, $periodeTest, 99, ['auto_register' => true, 'overwrite' => true]);

assertTest($res['success'] === true, 'Hasil import harus success');
assertTest($res['imported'] === 3, 'Total 3 nilai harus berhasil diimport');
assertTest($res['updated'] === 1, '1 data asesmen lama harus diperbarui');
assertTest($res['inserted'] === 2, '2 data asesmen baru harus di-insert');
assertTest($res['created_registrations'] === 2, '2 registrasi baru otomatis dibuat untuk user yang belum terdaftar di periode');

// Verifikasi Mahasiswa 1 (24042061): Nilai ujian_tulis 96, praktik_wudlu tetap 90
$stmt = $pdo->query("SELECT scores_json FROM pretes_assessments WHERE registration_id = 10");
$row10 = json_decode($stmt->fetchColumn(), true);
assertTest((float)$row10['ujian_tulis'] === 96.0, 'Nilai ujian_tulis mahasiswa 1 harus 96');
assertTest((float)$row10['praktik_wudlu'] === 90.0, 'Nilai praktik_wudlu mahasiswa 1 harus tetap 90 (tidak boleh hilang)');

// Verifikasi Mahasiswa 2 (24081002): Nilai 0 harus tersimpan sebagai 0.0 (tidak boleh dianggap kosong/null)
$stmt = $pdo->query("SELECT pr.id, pa.scores_json, pr.hari_pilihan FROM pretes_registrations pr JOIN pretes_assessments pa ON pa.registration_id = pr.id WHERE pr.user_id = 2");
$rowUser2 = $stmt->fetch(PDO::FETCH_ASSOC);
assertTest($rowUser2 !== false, 'Mahasiswa 2 harus terdaftar dan memiliki asesmen');
assertTest($rowUser2['hari_pilihan'] === 'Kamis', 'Hari pilihan mahasiswa 2 harus Kamis');
$scores2 = json_decode($rowUser2['scores_json'], true);
assertTest($scores2['ujian_tulis'] !== null && (float)$scores2['ujian_tulis'] === 0.0, 'Nilai CBT 0 harus tersimpan sebagai 0.0');

// Verifikasi Mahasiswa 3 (24032035): Nilai 54
$stmt = $pdo->query("SELECT pa.scores_json, pr.hari_pilihan FROM pretes_registrations pr JOIN pretes_assessments pa ON pa.registration_id = pr.id WHERE pr.user_id = 3");
$rowUser3 = $stmt->fetch(PDO::FETCH_ASSOC);
assertTest($rowUser3['hari_pilihan'] === 'Senin', 'Hari pilihan mahasiswa 3 harus Senin');
$scores3 = json_decode($rowUser3['scores_json'], true);
assertTest((float)$scores3['ujian_tulis'] === 54.0, 'Nilai CBT mahasiswa 3 harus 54.0');

// 5. Verifikasi Keberadaan Berkas dan Integrasi UI di pretes-panel.php
$panelPath = __DIR__ . '/../includes/pretes-panel.php';
$panelCode = file_get_contents($panelPath);
assertTest(strpos($panelCode, 'openImportCbtModal') !== false, 'Fungsi openImportCbtModal harus ada di pretes-panel.php');
assertTest(strpos($panelCode, 'modal-import-cbt') !== false, 'Modal modal-import-cbt harus ada di pretes-panel.php');
assertTest(strpos($panelCode, 'ajax-import-pretes-cbt.php') !== false, 'Endpoint ajax-import-pretes-cbt.php harus dipanggil di pretes-panel.php');
assertTest(strpos($panelCode, 'download-template-pretes-cbt.php') !== false, 'Link download-template-pretes-cbt.php harus ada di pretes-panel.php');
assertTest(strpos($panelCode, 'Import Nilai CBT') !== false, 'Tombol Import Nilai CBT harus ada di pretes-panel.php');

$ajaxPath = __DIR__ . '/../admin/ajax-import-pretes-cbt.php';
assertTest(file_exists($ajaxPath), 'admin/ajax-import-pretes-cbt.php harus ada');

$templatePath = __DIR__ . '/../admin/download-template-pretes-cbt.php';
assertTest(file_exists($templatePath), 'admin/download-template-pretes-cbt.php harus ada');
$templateCode = file_get_contents($templatePath);
assertTest(strpos($templateCode, 'Nomor Induk') !== false, 'Template harus memiliki kolom Nomor Induk');
assertTest(strpos($templateCode, 'Keterangan') !== false, 'Template harus memiliki kolom Keterangan');

echo "PASS: Seluruh pengujian Impor Hasil CBT Pretes (Parser, Normalisasi, SQLite End-to-End, dan Integrasi UI) BERHASIL 100%!\n";
