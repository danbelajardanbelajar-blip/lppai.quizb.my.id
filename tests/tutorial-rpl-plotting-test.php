<?php
/**
 * Test: Manajemen & Plotting Kolektif Tutorial RPL Online (Tahap 2)
 * Memverifikasi:
 * 1. Batch Plotting (Multi-select) & Pengendalian Kuota
 * 2. Batch Unplot (Pelepasan massal)
 * 3. Auto-Distribusi Merata (Round-robin load balancing)
 * 4. Kueri Siswa dengan Status CBT Pretes & Filter (Kelas, Plotting, Search)
 */

require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';

$pdoFixture = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Skema tabel dasar
$pdoFixture->exec("
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nama_lengkap TEXT,
    nim TEXT,
    username TEXT,
    role TEXT,
    jenis_program TEXT,
    program_studi TEXT,
    fakultas TEXT,
    no_hp TEXT
);

CREATE TABLE pretes_registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    periode TEXT
);

CREATE TABLE pretes_cbt_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    registration_id INTEGER,
    score REAL,
    submitted INTEGER DEFAULT 0
);
");

ensureTutorialRplTables($pdoFixture);

$failures = [];
$check = function(bool $condition, string $label) use (&$failures) {
    if (!$condition) {
        $failures[] = "FAIL: $label";
        echo "❌ FAIL: $label\n";
    } else {
        echo "✅ PASS: $label\n";
    }
};

echo "=== TEST 1: Batch Plotting & Pengendalian Kuota ===\n";
// Buat 2 kelompok: Kelompok A (kuota 2), Kelompok B (kuota 5)
$pdoFixture->exec("
INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, kuota, is_active)
VALUES (1, 'Kelompok RPL A', 'Tutorial RPL 2026/2027', 2, 1);

INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, kuota, is_active)
VALUES (2, 'Kelompok RPL B', 'Tutorial RPL 2026/2027', 5, 1);
");

// Tambah 5 mahasiswa RPL
$pdoFixture->exec("
INSERT INTO users (id, nama_lengkap, nim, role, jenis_program) VALUES (1, 'Ahmad Dahlan', '2026001', 'mahasiswa', 'RPL');
INSERT INTO users (id, nama_lengkap, nim, role, jenis_program) VALUES (2, 'Budi Utomo', '2026002', 'mahasiswa', 'RPL');
INSERT INTO users (id, nama_lengkap, nim, role, jenis_program) VALUES (3, 'Cak Nur', '2026003', 'mahasiswa', 'RPL');
INSERT INTO users (id, nama_lengkap, nim, role, jenis_program) VALUES (4, 'Dewi Sartika', '2026004', 'mahasiswa', 'RPL');
INSERT INTO users (id, nama_lengkap, nim, role, jenis_program) VALUES (5, 'Embun Pagi', '2026005', 'mahasiswa', 'RPL');
");

// Plot 3 mahasiswa ke Kelompok A (kuota hanya 2) -> 2 sukses, 1 dilewati karena kuota
$res = tutorialRplBatchPlot($pdoFixture, [1, 2, 3], 1);
$check($res['success'] === 2 && $res['skipped_quota'] === 1 && $res['total'] === 3, 'Batch plot: Kuota 2 membatasi 3 pendaftar (2 sukses, 1 dilewati)');

$membersA = tutorialRplGetMembers($pdoFixture, 1);
$check(count($membersA) === 2, 'Kelompok A terisi tepat 2 anggota');

// Pindahkan mahasiswa 1 dari Kelompok A ke Kelompok B
$resMove = tutorialRplBatchPlot($pdoFixture, [1], 2);
$check($resMove['success'] === 1, 'Pindah kelompok: Mahasiswa 1 sukses dipindahkan ke Kelompok B');
$membersAAfter = tutorialRplGetMembers($pdoFixture, 1);
$membersB = tutorialRplGetMembers($pdoFixture, 2);
$check(count($membersAAfter) === 1 && count($membersB) === 1, 'Mahasiswa 1 berpindah tanpa duplikasi');

echo "\n=== TEST 2: Batch Unplot (Pelepasan Massal) ===\n";
// Ambil member ID di Kelompok A dan Kelompok B lalu lepas
$memberIds = $pdoFixture->query("SELECT id FROM tutorial_rpl_members")->fetchAll(PDO::FETCH_COLUMN);
$check(count($memberIds) === 2, 'Terdapat 2 anggota terdaftar sebelum unplot');

$unplotCount = tutorialRplBatchUnplot($pdoFixture, $memberIds);
$check($unplotCount === 2, 'tutorialRplBatchUnplot berhasil melepas 2 anggota');
$check(count($pdoFixture->query("SELECT id FROM tutorial_rpl_members")->fetchAll()) === 0, 'Semua anggota berhasil dilepas');

echo "\n=== TEST 3: Auto-Distribusi Merata (Round-Robin) ===\n";
// Sekarang semua 5 mahasiswa unassigned.
// Kelompok A sisa kuota 2, Kelompok B sisa kuota 5.
$autoRes = tutorialRplAutoDistribute($pdoFixture);
$check($autoRes['plotted'] === 5 && $autoRes['remaining_unassigned'] === 0, 'Auto-distribute memplot semua 5 mahasiswa');

$membersAEnd = tutorialRplGetMembers($pdoFixture, 1);
$membersBEnd = tutorialRplGetMembers($pdoFixture, 2);
// Kelompok A (kuota 2) harus terisi 2, Kelompok B harus terisi 3
$check(count($membersAEnd) === 2 && count($membersBEnd) === 3, 'Auto-distribute membagi merata sesuai sisa kuota (Kelompok A: 2, Kelompok B: 3)');

echo "\n=== TEST 4: Kueri Siswa dengan Nilai CBT Pretes & Filter ===\n";
// Tambah CBT attempt untuk mahasiswa 1 (score 76 < 80) dan mahasiswa 2 (score 84 >= 80)
$pdoFixture->exec("
INSERT INTO pretes_cbt_attempts (user_id, registration_id, score, submitted) VALUES (1, 10, 76.0, 1);
INSERT INTO pretes_cbt_attempts (user_id, registration_id, score, submitted) VALUES (2, 20, 84.0, 1);
");

$allWithPretes = tutorialRplGetStudentsWithPretes($pdoFixture, []);
$check(count($allWithPretes) === 5, 'Kueri mengembalikan 5 mahasiswa RPL');

$s1 = array_values(array_filter($allWithPretes, fn($s) => $s['id'] == 1))[0];
$s2 = array_values(array_filter($allWithPretes, fn($s) => $s['id'] == 2))[0];
$s3 = array_values(array_filter($allWithPretes, fn($s) => $s['id'] == 3))[0];

$check(str_contains($s1['cbt_status'], 'Nilai 76 (< 80)'), 'Mahasiswa 1 terdeteksi nilai 76 (< 80, lanjut tutorial)');
$check(str_contains($s2['cbt_status'], 'Lolos CBT (84)'), 'Mahasiswa 2 terdeteksi Lolos CBT (84)');
$check($s3['cbt_status'] === 'Belum CBT', 'Mahasiswa 3 terdeteksi Belum CBT');

// Filter class_id = 1
$filterClassA = tutorialRplGetStudentsWithPretes($pdoFixture, ['class_id' => 1]);
$check(count($filterClassA) === 2, 'Filter class_id=1 mengembalikan tepat 2 mahasiswa');

// Filter pencarian "Dahlan"
$searchDahlan = tutorialRplGetStudentsWithPretes($pdoFixture, ['search' => 'Dahlan']);
$check(count($searchDahlan) === 1 && $searchDahlan[0]['id'] == 1, 'Pencarian "Dahlan" menemukan Ahmad Dahlan');

// Filter pencarian NIM "2026002"
$searchNim = tutorialRplGetStudentsWithPretes($pdoFixture, ['search' => '2026002']);
$check(count($searchNim) === 1 && $searchNim[0]['id'] == 2, 'Pencarian NIM "2026002" menemukan Budi Utomo');

if (!empty($failures)) {
    echo "\nTotal failures: " . count($failures) . "\n";
    exit(1);
}

echo "\nSemua pengujian Manajemen & Plotting Kolektif Tutorial RPL BERHASIL! (100% PASS)\n";
exit(0);
