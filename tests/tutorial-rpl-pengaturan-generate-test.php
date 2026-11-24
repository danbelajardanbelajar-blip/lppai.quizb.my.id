<?php
/**
 * Test: Pengaturan Dosen & Kuota serta Generate Plotting Tutorial RPL Online
 *
 * Menguji:
 * 1. Generate Jadwal mode distribute_evenly (distribusi merata)
 * 2. Generate Jadwal mode fill_first (isi sampai kuota per kelas)
 * 3. Opsi overwrite_plotting (tindih plotting lama)
 * 4. Opsi match_hari_pilihan (prioritas hari bimbingan pendaftaran)
 * 5. Opsi auto_create_classes (pembuatan kelas otomatis dari jadwal tutor)
 * 6. Penanganan kuota penuh dan pengembalian daftar mahasiswa unassigned
 * 7. Penyimpanan pengaturan dosen dan kuota per hari (tutorialRplSavePeriodTutors)
 * 8. Integritas sintaks & struktur halaman admin/tutorial-rpl-pengaturan-generate.php
 */

require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
require_once __DIR__ . '/../includes/tutorial-rpl-periods.php';
require_once __DIR__ . '/../includes/tutorial-rpl-registration.php';

function createRplTestPdo(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $pdo->exec("
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
    ");

    ensureTutorialRplTables($pdo);
    ensureTutorialRplPeriodTable($pdo);
    ensureTutorialRplRegistrationTable($pdo);

    return $pdo;
}

echo "=== TEST 1: Generate Jadwal mode distribute_evenly ===\n";
$pdo1 = createRplTestPdo();

// Buat 2 Dosen
$pdo1->exec("INSERT INTO users (id, nama_lengkap, nim, role) VALUES (1, 'Dr. Ahmad Fauzi, M.Pd.', '19800101', 'dosen')");
$pdo1->exec("INSERT INTO users (id, nama_lengkap, nim, role) VALUES (2, 'Hj. Siti Aminah, M.Ag.', '19820202', 'dosen')");

// Buat 2 Kelompok Kelas RPL Aktif (Kapasitas masing-masing 5)
$pdo1->exec("INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, gelombang, dosen_id, dosen_nama, kuota, is_active)
             VALUES (101, 'Kelompok RPL 1', 'Tutorial RPL 2026/2027', 'Gelombang 1', 1, 'Dr. Ahmad Fauzi, M.Pd.', 5, 1)");
$pdo1->exec("INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, gelombang, dosen_id, dosen_nama, kuota, is_active)
             VALUES (102, 'Kelompok RPL 2', 'Tutorial RPL 2026/2027', 'Gelombang 1', 2, 'Hj. Siti Aminah, M.Ag.', 5, 1)");

// Buat 6 Mahasiswa RPL
for ($i = 10; $i <= 15; $i++) {
    $pdo1->exec("INSERT INTO users (id, nama_lengkap, nim, role, jenis_program, program_studi)
                 VALUES ($i, 'Mahasiswa RPL $i', 'NIM$i', 'mahasiswa', 'RPL', 'Pendidikan Agama Islam')");
}

$res1 = tutorialRplGenerateSchedule($pdo1, [
    'periode' => 'Tutorial RPL 2026/2027',
    'gelombang' => 'Gelombang 1',
    'generate_mode' => 'distribute_evenly',
    'match_hari_pilihan' => false,
    'auto_create_classes' => false
]);

assert($res1['success'] === true, 'Hasil generate harus sukses');
assert($res1['plotted'] === 6, 'Semua 6 mahasiswa harus terplot');
assert($res1['classes_used'] === 2, 'Kedua kelompok harus digunakan');
assert(empty($res1['unassigned']), 'Tidak ada mahasiswa unassigned');

$count101 = (int)$pdo1->query("SELECT COUNT(*) FROM tutorial_rpl_members WHERE class_id = 101")->fetchColumn();
$count102 = (int)$pdo1->query("SELECT COUNT(*) FROM tutorial_rpl_members WHERE class_id = 102")->fetchColumn();
assert($count101 === 3 && $count102 === 3, "Distribusi harus merata: 3 di 101 dan 3 di 102 (Dapat: $count101 dan $count102)");
echo "✅ PASS: Distribute evenly membagi 6 mahasiswa secara merata 3:3.\n\n";

echo "=== TEST 2: Generate Jadwal mode fill_first ===\n";
$pdo2 = createRplTestPdo();
$pdo2->exec("INSERT INTO users (id, nama_lengkap, nim, role) VALUES (1, 'Dosen A', '111', 'dosen')");
$pdo2->exec("INSERT INTO users (id, nama_lengkap, nim, role) VALUES (2, 'Dosen B', '222', 'dosen')");

$pdo2->exec("INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, kuota, is_active) VALUES (201, 'Kelompok A', 'Tutorial RPL 2026/2027', 4, 1)");
$pdo2->exec("INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, kuota, is_active) VALUES (202, 'Kelompok B', 'Tutorial RPL 2026/2027', 4, 1)");

for ($i = 20; $i <= 24; $i++) {
    $pdo2->exec("INSERT INTO users (id, nama_lengkap, nim, role, jenis_program) VALUES ($i, 'Mhs $i', 'N$i', 'mahasiswa', 'RPL')");
}

$res2 = tutorialRplGenerateSchedule($pdo2, [
    'periode' => 'Tutorial RPL 2026/2027',
    'generate_mode' => 'fill_first',
    'match_hari_pilihan' => false,
    'auto_create_classes' => false
]);

assert($res2['plotted'] === 5, '5 mahasiswa harus terplot');
$count201 = (int)$pdo2->query("SELECT COUNT(*) FROM tutorial_rpl_members WHERE class_id = 201")->fetchColumn();
$count202 = (int)$pdo2->query("SELECT COUNT(*) FROM tutorial_rpl_members WHERE class_id = 202")->fetchColumn();
assert($count201 === 4 && $count202 === 1, "Mode fill_first harus mengisi kelas pertama sampai penuh (4), lalu sisa (1) ke kelas kedua. Dapat: $count201 dan $count202");
echo "✅ PASS: Mode fill_first mengisi penuh kelompok pertama sebelum ke kelompok kedua.\n\n";

echo "=== TEST 3: Opsi overwrite_plotting ===\n";
// Pada pdo2, plot lagi dengan overwrite_plotting = true
$res3 = tutorialRplGenerateSchedule($pdo2, [
    'periode' => 'Tutorial RPL 2026/2027',
    'generate_mode' => 'distribute_evenly',
    'overwrite_plotting' => true,
    'match_hari_pilihan' => false,
    'auto_create_classes' => false
]);

assert($res3['plotted'] === 5, '5 mahasiswa harus diplot ulang');
$totalMembers = (int)$pdo2->query("SELECT COUNT(*) FROM tutorial_rpl_members WHERE class_id IN (201, 202)")->fetchColumn();
assert($totalMembers === 5, "Total anggota tidak boleh dobel setelah overwrite. Total: $totalMembers");
echo "✅ PASS: Overwrite plotting membersihkan plotting lama dan menyusun ulang tanpa duplikasi.\n\n";

echo "=== TEST 4: Opsi match_hari_pilihan ===\n";
$pdo4 = createRplTestPdo();
$pdo4->exec("INSERT INTO users (id, nama_lengkap, role) VALUES (1, 'Dosen Senin', 'dosen')");
$pdo4->exec("INSERT INTO users (id, nama_lengkap, role) VALUES (2, 'Dosen Jumat', 'dosen')");

// Periode aktif
$pdo4->exec("INSERT INTO tutorial_rpl_registration_periods (id, nama, gelombang, opens_at, closes_at, quota, active_slot, created_by, updated_by, created_at, updated_at)
             VALUES (1, 'Tutorial RPL 2026/2027', 'Gelombang 1', '2026-11-01 00:00:00', '2026-12-31 23:59:59', 100, 1, 1, 1, '2026-11-01 00:00:00', '2026-11-01 00:00:00')");

// Kelas Senin & Kelas Jumat
$pdo4->exec("INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, dosen_id, dosen_nama, kuota, catatan, is_active)
             VALUES (401, 'Kelompok Senin', 'Tutorial RPL 2026/2027', 1, 'Dosen Senin', 10, 'Hari: Senin', 1)");
$pdo4->exec("INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, dosen_id, dosen_nama, kuota, catatan, is_active)
             VALUES (402, 'Kelompok Jumat', 'Tutorial RPL 2026/2027', 2, 'Dosen Jumat', 10, 'Hari: Jumat', 1)");

// Mahasiswa 1 pilih Senin, Mahasiswa 2 pilih Jumat
$pdo4->exec("INSERT INTO users (id, nama_lengkap, role, jenis_program) VALUES (41, 'Ali Pilihan Senin', 'mahasiswa', 'RPL')");
$pdo4->exec("INSERT INTO users (id, nama_lengkap, role, jenis_program) VALUES (42, 'Budi Pilihan Jumat', 'mahasiswa', 'RPL')");

$pdo4->exec("INSERT INTO tutorial_rpl_registrations (period_id, user_id, channel, registered_by, registered_at, evidence_json, hari_pilihan)
             VALUES (1, 41, 'self', 41, '2026-11-02 10:00:00', '{}', 'Senin')");
$pdo4->exec("INSERT INTO tutorial_rpl_registrations (period_id, user_id, channel, registered_by, registered_at, evidence_json, hari_pilihan)
             VALUES (1, 42, 'self', 42, '2026-11-02 10:00:00', '{}', 'Jumat')");

$res4 = tutorialRplGenerateSchedule($pdo4, [
    'periode' => 'Tutorial RPL 2026/2027',
    'match_hari_pilihan' => true,
    'auto_create_classes' => false
]);

assert($res4['plotted'] === 2, '2 mahasiswa harus terplot');
$aliClass = (int)$pdo4->query("SELECT class_id FROM tutorial_rpl_members WHERE user_id = 41")->fetchColumn();
$budiClass = (int)$pdo4->query("SELECT class_id FROM tutorial_rpl_members WHERE user_id = 42")->fetchColumn();

assert($aliClass === 401, "Ali yang memilih Senin harus masuk kelas 401 (Dapat: $aliClass)");
assert($budiClass === 402, "Budi yang memilih Jumat harus masuk kelas 402 (Dapat: $budiClass)");
echo "✅ PASS: Opsi match_hari_pilihan menempatkan mahasiswa sesuai hari bimbingan pilihannya.\n\n";

echo "=== TEST 5: Opsi auto_create_classes dari tutor periode ===\n";
$pdo5 = createRplTestPdo();
$pdo5->exec("INSERT INTO users (id, nama_lengkap, nim, role, no_hp) VALUES (1, 'Ustadz Zaid', '112233', 'dosen', '0812345678')");
$pdo5->exec("INSERT INTO tutorial_rpl_registration_periods (id, nama, gelombang, opens_at, closes_at, quota, active_slot, created_by, updated_by, created_at, updated_at)
             VALUES (1, 'Tutorial RPL 2026/2027', 'Gelombang 1', '2026-11-01 00:00:00', '2026-12-31 23:59:59', 50, 1, 1, 1, '2026-11-01 00:00:00', '2026-11-01 00:00:00')");

$pdo5->exec("INSERT INTO tutorial_rpl_period_tutors (period_id, dosen_id, hari, quota) VALUES (1, 1, 'Rabu', 35)");

$pdo5->exec("INSERT INTO users (id, nama_lengkap, role, jenis_program) VALUES (51, 'Santri 51', 'mahasiswa', 'RPL')");
$pdo5->exec("INSERT INTO tutorial_rpl_registrations (period_id, user_id, channel, registered_by, registered_at, evidence_json, hari_pilihan)
             VALUES (1, 51, 'self', 51, '2026-11-02 10:00:00', '{}', 'Rabu')");

// Awalnya tabel tutorial_rpl_classes masih kosong
$initialClassCount = (int)$pdo5->query("SELECT COUNT(*) FROM tutorial_rpl_classes")->fetchColumn();
assert($initialClassCount === 0, 'Awalnya kelas harus 0');

$res5 = tutorialRplGenerateSchedule($pdo5, [
    'periode' => 'Tutorial RPL 2026/2027',
    'auto_create_classes' => true
]);

assert($res5['success'] === true, 'Generate harus sukses');
$createdClasses = $pdo5->query("SELECT * FROM tutorial_rpl_classes")->fetchAll();
assert(count($createdClasses) === 1, 'Satu kelas baru harus otomatis terbuat');
assert($createdClasses[0]['dosen_nama'] === 'Ustadz Zaid', 'Nama tutor harus sesuai');
assert((int)$createdClasses[0]['kuota'] === 35, 'Kuota kelas harus sesuai tutor');
assert($res5['plotted'] === 1, 'Mahasiswa harus terplot ke kelas yang baru dibuat');
echo "✅ PASS: Opsi auto_create_classes otomatis membuat kelompok kelas dari jadwal dosen periode aktif.\n\n";

echo "=== TEST 6: Penanganan kuota penuh & daftar unassigned ===\n";
$pdo6 = createRplTestPdo();
$pdo6->exec("INSERT INTO users (id, nama_lengkap, role) VALUES (1, 'Dosen X', 'dosen')");
$pdo6->exec("INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, kuota, is_active) VALUES (601, 'Kelas Kecil', 'Tutorial RPL 2026/2027', 2, 1)");

for ($i = 61; $i <= 65; $i++) {
    $pdo6->exec("INSERT INTO users (id, nama_lengkap, nim, role, jenis_program, program_studi) VALUES ($i, 'Mhs $i', 'N$i', 'mahasiswa', 'RPL', 'Prodi TI')");
}

$res6 = tutorialRplGenerateSchedule($pdo6, [
    'periode' => 'Tutorial RPL 2026/2027',
    'auto_create_classes' => false
]);

assert($res6['plotted'] === 2, 'Hanya 2 mahasiswa yang terplot karena kuota 2');
assert(count($res6['unassigned']) === 3, 'Sebanyak 3 mahasiswa harus masuk daftar unassigned');
assert(isset($res6['unassigned'][0]['nama_lengkap']), 'Unassigned harus menyertakan nama_lengkap');
echo "✅ PASS: Kuota penuh ditangani dengan benar dan mengembalikan daftar mahasiswa unassigned.\n\n";

echo "=== TEST 7: Penyimpanan Pengaturan Dosen & Kuota (tutorialRplSavePeriodTutors) ===\n";
$pdo7 = createRplTestPdo();
$pdo7->exec("INSERT INTO users (id, nama_lengkap, role) VALUES (1, 'Dosen A', 'dosen')");
$pdo7->exec("INSERT INTO users (id, nama_lengkap, role) VALUES (2, 'Dosen B', 'dosen')");
$pdo7->exec("INSERT INTO tutorial_rpl_registration_periods (id, nama, gelombang, opens_at, closes_at, quota, active_slot, created_by, updated_by, created_at, updated_at)
             VALUES (1, 'Tutorial RPL 2026/2027', 'Gelombang 1', '2026-11-01 00:00:00', '2026-12-31 23:59:59', 0, 1, 1, 1, '2026-11-01 00:00:00', '2026-11-01 00:00:00')");

$schedules = [
    ['dosen_id' => 1, 'hari' => 'Senin', 'quota' => 30],
    ['dosen_id' => 2, 'hari' => 'Kamis', 'quota' => 25]
];
tutorialRplSavePeriodTutors($pdo7, 1, $schedules, true);

$savedTutors = tutorialRplPeriodTutors($pdo7, 1);
assert(count($savedTutors) === 2, 'Dua dosen harus tersimpan di tutorial_rpl_period_tutors');
assert((int)$savedTutors[0]['quota'] === 30, 'Kuota Dosen 1 harus 30');
assert((int)$savedTutors[1]['quota'] === 25, 'Kuota Dosen 2 harus 25');
echo "✅ PASS: Penyimpanan dosen dan kuota per hari berhasil diverifikasi.\n\n";

echo "=== TEST 8: Integritas Struktur Halaman admin/tutorial-rpl-pengaturan-generate.php ===\n";
$pageFile = __DIR__ . '/../admin/tutorial-rpl-pengaturan-generate.php';
assert(file_exists($pageFile), 'File admin/tutorial-rpl-pengaturan-generate.php harus ada');
$pageContent = file_get_contents($pageFile);

assert(str_contains($pageContent, "define('PAGE_TITLE', 'Pengaturan & Generate RPL');"), 'PAGE_TITLE harus Pengaturan & Generate RPL');
assert(str_contains($pageContent, 'requireAdmin();'), 'Halaman wajib requireAdmin');
assert(str_contains($pageContent, 'update_tutors_kuota'), 'Form update_tutors_kuota harus tersedia');
assert(str_contains($pageContent, 'generate_plotting'), 'Form generate_plotting harus tersedia');
assert(str_contains($pageContent, 'modeFillFirst'), 'Radio modeFillFirst harus tersedia');
assert(str_contains($pageContent, 'modeDistribute'), 'Radio modeDistribute harus tersedia');
assert(str_contains($pageContent, 'fillExistingClasses'), 'Checkbox fillExistingClasses harus tersedia');
assert(str_contains($pageContent, 'overwritePlotting'), 'Checkbox overwritePlotting harus tersedia');
assert(str_contains($pageContent, 'matchHariPilihan'), 'Checkbox matchHariPilihan harus tersedia');
assert(str_contains($pageContent, 'autoCreateClasses'), 'Checkbox autoCreateClasses harus tersedia');
assert(str_contains($pageContent, 'tutorial-rpl-kelas.php'), 'Harus menautkan ke halaman kelola kelompok RPL');

echo "✅ PASS: Seluruh elemen esensial pada admin/tutorial-rpl-pengaturan-generate.php lengkap dan valid.\n\n";

echo "🎉 ALL TESTS PASSED SUCCESSFULLY! (100%)\n";
