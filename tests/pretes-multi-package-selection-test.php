<?php
/**
 * Test Suite: CBT Multi-Package Activation & Student Package Choice
 * Memverifikasi kedua paket (Ubudiyah/Islam & Kristen Protestan) aktif bersamaan,
 * mahasiswa dapat memilih salah satu paket, dan butir soal terseleksi secara presisi
 * tanpa tercampur antar-agama.
 */

require_once __DIR__ . '/../includes/pretes-cbt.php';
require_once __DIR__ . '/../tools/import-paket-2-kristen-protestan.php';

function runMultiPackageSelectionTests(): void {
    echo "=== Menjalankan Test Suite: CBT Multi-Package Selection & Isolation ===\n";
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Setup dummy users & registrations
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, nim TEXT, nama_lengkap TEXT, program_studi TEXT, jenis_program TEXT)");
    $pdo->exec("INSERT INTO users VALUES
        (1, '2026001', 'Ahmad Muslim', 'Teknik Informatika', 'Reguler'),
        (2, '2026002', 'Yohanes Kristian', 'Sistem Informasi', 'Reguler')");
    $pdo->exec("CREATE TABLE pretes_registrations (id INTEGER PRIMARY KEY, user_id INTEGER)");
    $pdo->exec("INSERT INTO pretes_registrations VALUES (101, 1), (102, 2)");

    // 1. Setup tables & default seeded Paket 1 (40 soal)
    ensurePretesCbtTables($pdo);
    $q1Count = (int)$pdo->query("SELECT COUNT(*) FROM pretes_cbt_questions WHERE package_id = 1")->fetchColumn();
    assert($q1Count === 40, "Paket 1 harus memiliki 40 butir soal (didapat: $q1Count).");
    echo "✓ Test 1 Passed: Paket 1 (Ubudiyah) terpasang dengan 40 butir soal.\n";

    // 2. Impor Paket 2 (Kristen Protestan, 25 butir soal)
    $resPkg2 = lppaiImportPaket2KristenProtestan($pdo, null, true, true);
    $pkg2Id = $resPkg2['package_id'];
    assert($pkg2Id > 1, "Paket 2 harus memiliki ID > 1.");
    $q2Count = (int)$pdo->query("SELECT COUNT(*) FROM pretes_cbt_questions WHERE package_id = $pkg2Id")->fetchColumn();
    assert($q2Count === 25, "Paket 2 harus memiliki tepat 25 butir soal.");
    echo "✓ Test 2 Passed: Paket 2 (Kristen Protestan) terpasang dengan 25 butir soal.\n";

    // 3. Sinkronisasi Multi-Paket Aktif & Sesi Resmi
    pretesCbtEnsureMultiPackageActive($pdo);
    $activePkgs = pretesCbtGetActivePackages($pdo);
    assert(count($activePkgs) >= 2, "Harus ada minimal 2 paket yang aktif bersamaan.");
    $activeCodes = array_column($activePkgs, 'code');
    assert(in_array('PKT-UTAMA', $activeCodes, true) || in_array('PKT-PRETES-UTAMA', $activeCodes, true) || in_array('PKT-1', $activeCodes, true), "Paket 1 harus aktif.");
    assert(in_array('PKT-2-KRISTEN-PROTESTAN', $activeCodes, true), "Paket 2 harus aktif.");
    echo "✓ Test 3 Passed: Kedua paket berhasil aktif bersamaan (is_active = 1).\n";

    // 4. Periksa Sesi Resmi Aktif mencakup kedua paket
    $activeSes = pretesCbtGetActiveSession($pdo);
    assert($activeSes !== null, "Harus ada sesi resmi yang aktif.");
    assert(in_array(1, $activeSes['package_ids'], true), "Sesi resmi harus menghubungkan Paket 1.");
    assert(in_array($pkg2Id, $activeSes['package_ids'], true), "Sesi resmi harus menghubungkan Paket 2.");
    echo "✓ Test 4 Passed: Sesi pretes resmi mencakup kedua paket ([1, $pkg2Id]).\n";

    // 5. Periksa state prapengerjaan CBT mengembalikan daftar kedua paket
    $state1 = pretesCbtState($pdo, 1, 101);
    assert($state1['attempt'] === null, "Sebelum mulai, attempt harus null.");
    assert(!empty($state1['available_packages']), "Harus ada available_packages di state.");
    assert(count($state1['available_packages']) >= 2, "Tersedia minimal 2 paket untuk dipilih.");
    echo "✓ Test 5 Passed: API state mengembalikan available_packages untuk dipilih mahasiswa.\n";

    // 6. Validasi pilihan paket yang tidak valid
    $invalidPkgFailed = false;
    try {
        pretesCbtStart($pdo, 1, 101, time(), null, null, false, 9999);
    } catch (InvalidArgumentException $e) {
        $invalidPkgFailed = true;
    }
    assert($invalidPkgFailed, "Memilih ID paket fiktif harus melempar InvalidArgumentException.");
    echo "✓ Test 6 Passed: Validasi penolakan paket tidak valid berfungsi presisi.\n";

    // 7. Mahasiswa 1 (Muslim) memilih Paket 1 (Ubudiyah)
    $now = time();
    $exam1 = pretesCbtStart($pdo, 1, 101, $now, null, null, false, 1);
    assert(isset($exam1['attempt']), "Mahasiswa 1 harus berhasil memulai ujian.");
    assert((int)$exam1['attempt']['total'] === 25, "Mahasiswa 1 harus mendapat 25 soal.");
    assert((int)$exam1['attempt']['package_id'] === 1, "Attempt Mahasiswa 1 harus tersimpan dengan package_id = 1.");

    // Verifikasi bahwa seluruh 25 butir soal di attempt 1 HANYA berasal dari Paket 1
    $items1 = $pdo->query("SELECT q.package_id, q.category FROM pretes_cbt_items i JOIN pretes_cbt_questions q ON q.id = i.question_id WHERE i.attempt_id = {$exam1['attempt']['id']}")->fetchAll();
    assert(count($items1) === 25, "Harus ada 25 butir soal.");
    foreach ($items1 as $it) {
        assert((int)$it['package_id'] === 1, "Semua soal Mahasiswa 1 harus berasal dari Paket 1 (ditemukan package_id " . $it['package_id'] . ")");
    }
    echo "✓ Test 7 Passed: Mahasiswa 1 mendapat 25 soal murni Paket 1 (Ubudiyah/Islam), tanpa soal Kristen.\n";

    // 8. Mahasiswa 2 (Kristen Protestan) memilih Paket 2
    $exam2 = pretesCbtStart($pdo, 2, 102, $now, null, null, false, $pkg2Id);
    assert(isset($exam2['attempt']), "Mahasiswa 2 harus berhasil memulai ujian.");
    assert((int)$exam2['attempt']['total'] === 25, "Mahasiswa 2 harus mendapat 25 soal.");
    assert((int)$exam2['attempt']['package_id'] === $pkg2Id, "Attempt Mahasiswa 2 harus tersimpan dengan package_id = $pkg2Id.");

    // Verifikasi bahwa seluruh 25 butir soal di attempt 2 HANYA berasal dari Paket 2
    $items2 = $pdo->query("SELECT q.package_id, q.code, q.category FROM pretes_cbt_items i JOIN pretes_cbt_questions q ON q.id = i.question_id WHERE i.attempt_id = {$exam2['attempt']['id']}")->fetchAll();
    assert(count($items2) === 25, "Harus ada 25 butir soal.");
    foreach ($items2 as $it) {
        assert((int)$it['package_id'] === $pkg2Id, "Semua soal Mahasiswa 2 harus berasal dari Paket 2 (ditemukan package_id " . $it['package_id'] . ")");
        assert(strpos($it['code'], 'PKT2-KP-') === 0, "Kode soal harus berawalan PKT2-KP-");
    }
    echo "✓ Test 8 Passed: Mahasiswa 2 mendapat 25 soal murni Paket 2 (Kristen Protestan), tanpa soal Ubudiyah.\n";

    // 9. Simpan jawaban dan submit untuk kedua mahasiswa
    // Mahasiswa 1 menjawab semua soal
    $attempt1Items = $pdo->query("SELECT position, correct_answer FROM pretes_cbt_items WHERE attempt_id = {$exam1['attempt']['id']}")->fetchAll();
    foreach ($attempt1Items as $it) {
        pretesCbtChange($pdo, 1, 101, 'save', ['position' => $it['position'], 'answer' => $it['correct_answer']], $now + 60);
    }
    $submit1 = pretesCbtChange($pdo, 1, 101, 'submit', [], $now + 120);
    assert($submit1['attempt']['submitted'] === true, "Ujian Mahasiswa 1 harus tersubmit.");
    assert((float)$submit1['attempt']['score'] === 100.0, "Nilai Mahasiswa 1 harus 100.0.");
    assert($submit1['attempt']['package_id'] === 1, "Nilai hasil harus memuat package_id = 1.");

    // Mahasiswa 2 menjawab semua soal
    $attempt2Items = $pdo->query("SELECT position, correct_answer FROM pretes_cbt_items WHERE attempt_id = {$exam2['attempt']['id']}")->fetchAll();
    foreach ($attempt2Items as $it) {
        pretesCbtChange($pdo, 2, 102, 'save', ['position' => $it['position'], 'answer' => $it['correct_answer']], $now + 60);
    }
    $submit2 = pretesCbtChange($pdo, 2, 102, 'submit', [], $now + 120);
    assert($submit2['attempt']['submitted'] === true, "Ujian Mahasiswa 2 harus tersubmit.");
    assert((float)$submit2['attempt']['score'] === 100.0, "Nilai Mahasiswa 2 harus 100.0.");
    assert($submit2['attempt']['package_id'] === $pkg2Id, "Nilai hasil harus memuat package_id = $pkg2Id.");
    assert(strpos($submit2['attempt']['package_name'], 'Kristen Protestan') !== false, "Nama paket harus tertera.");
    echo "✓ Test 9 Passed: Penilaian dan lembar hasil kedua mahasiswa tersimpan independen dan sempurna.\n";

    // 10. Test Fitur Admin: Nonaktifkan & Aktifkan Paket
    // Menonaktifkan paket 2
    $deactOk = pretesCbtDeactivatePackage($pdo, $pkg2Id);
    assert($deactOk === true, "Nonaktifkan Paket 2 harus berhasil.");
    $pkg2Data = pretesCbtGetPackage($pdo, $pkg2Id);
    assert((int)$pkg2Data['is_active'] === 0, "Paket 2 sekarang nonaktif.");

    // Mencoba menonaktifkan Paket 1 saat hanya Paket 1 yang aktif harus dilempar exception
    $cantDisableLast = false;
    try {
        pretesCbtDeactivatePackage($pdo, 1);
    } catch (InvalidArgumentException $e) {
        $cantDisableLast = true;
    }
    assert($cantDisableLast, "Menonaktifkan paket aktif terakhir harus dicegah.");

    // Mengaktifkan kembali Paket 2 secara non-eksklusif (multi-aktif)
    $actOk = pretesCbtActivatePackage($pdo, $pkg2Id, false);
    assert($actOk === true, "Aktivasi Paket 2 harus berhasil.");
    assert((int)pretesCbtGetPackage($pdo, 1)['is_active'] === 1, "Paket 1 harus tetap aktif.");
    assert((int)pretesCbtGetPackage($pdo, $pkg2Id)['is_active'] === 1, "Paket 2 harus aktif.");
    echo "✓ Test 10 Passed: Admin dapat mengaktifkan & menonaktifkan paket dengan aman tanpa saling menghapus.\n";

    echo "\n=== SEMUA 10 PENGUJIAN MULTI-PAKET CBT LULUS 100% ===\n";
}

runMultiPackageSelectionTests();
