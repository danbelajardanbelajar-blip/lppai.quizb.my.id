<?php
/**
 * Test Suite: CBT Multi-Active Sessions (Menjalankan 2 sesi atau lebih dalam satu waktu)
 */
require_once __DIR__ . '/../includes/pretes-cbt.php';

function runMultiSessionTests(): void {
    echo "=== Menjalankan Test Suite: CBT Multi-Active Sessions ===\n";
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Setup mock users & registrations
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, nim TEXT, nama_lengkap TEXT, program_studi TEXT, jenis_program TEXT)");
    $pdo->exec("INSERT INTO users VALUES 
        (1, '2026001', 'Ahmad Mahasiswa (Muslim)', 'Pendidikan Agama Islam', 'Reguler'),
        (2, '2026002', 'Yohanes Mahasiswa (Kristen)', 'Teknik Informatika', 'Reguler')");
    $pdo->exec("CREATE TABLE pretes_registrations (id INTEGER PRIMARY KEY, user_id INTEGER)");
    $pdo->exec("INSERT INTO pretes_registrations VALUES (101, 1), (102, 2)");

    ensurePretesCbtTables($pdo);

    // 1. Setup 2 Paket Soal
    $pkg1Id = pretesCbtSavePackage($pdo, [
        'name' => 'Paket 1 - Ubudiyah Islam',
        'code' => 'PKT-UBUD-ISLAM',
        'duration_minutes' => 30,
        'question_count' => 25,
        'is_active' => 1
    ]);
    $pkg2Id = pretesCbtSavePackage($pdo, [
        'name' => 'Paket 2 - Kristen Protestan',
        'code' => 'PKT-KRISTEN-PROT',
        'duration_minutes' => 30,
        'question_count' => 25,
        'is_active' => 1
    ]);

    // Seed 25 soal di Paket 1 dan 25 soal di Paket 2
    for ($i = 1; $i <= 25; $i++) {
        pretesCbtSaveQuestion($pdo, [
            'package_id' => $pkg1Id,
            'code' => "ISLAM-$i",
            'category' => 'Ubudiyah',
            'question_text' => "Soal Ubudiyah Islam nomor $i",
            'option_A' => 'A', 'option_B' => 'B', 'option_C' => 'C', 'option_D' => 'D', 'option_E' => 'E',
            'correct_answer' => 'A', 'is_active' => 1
        ]);
        pretesCbtSaveQuestion($pdo, [
            'package_id' => $pkg2Id,
            'code' => "KRISTEN-$i",
            'category' => 'Kristen',
            'question_text' => "Soal Kristen Protestan nomor $i",
            'option_A' => 'A', 'option_B' => 'B', 'option_C' => 'C', 'option_D' => 'D', 'option_E' => 'E',
            'correct_answer' => 'B', 'is_active' => 1
        ]);
    }
    echo "✓ Test 1 Passed: Inisialisasi Paket 1 (Islam) dan Paket 2 (Kristen) dengan masing-masing 25 butir soal.\n";

    // 2. Setup Sesi 1 (Reguler Islam)
    $ses1Id = pretesCbtSaveSession($pdo, [
        'id' => 1,
        'title' => 'Sesi Pretes Reguler 2026',
        'code' => 'SESI-REGULER-2026',
        'package_ids' => [$pkg1Id],
        'duration_minutes' => 30,
        'camera_mode' => 'wajib',
        'token' => 'ISLAM26',
        'token_required' => 0,
        'is_active' => 1
    ]);
    assert($ses1Id > 0, "Sesi 1 harus berhasil dibuat.");

    // 3. Setup Sesi 2 (Kristen Protestan) dengan is_active = 1
    // Verifikasi bahwa menyimpan Sesi 2 sebagai aktif TIDAK mematikan Sesi 1
    $ses2Id = pretesCbtSaveSession($pdo, [
        'title' => 'Sesi Pretes Resmi – Kristen Protestan',
        'code' => 'SESI-RESMI-PROTESTAN-2026',
        'package_ids' => [$pkg2Id],
        'duration_minutes' => 30,
        'camera_mode' => 'wajib',
        'token' => 'KRISTEN26',
        'token_required' => 0,
        'is_active' => 1
    ]);
    assert($ses2Id > 0, "Sesi 2 harus berhasil dibuat.");

    $ses1 = pretesCbtGetSession($pdo, $ses1Id);
    $ses2 = pretesCbtGetSession($pdo, $ses2Id);
    assert((int)$ses1['is_active'] === 1, "Sesi 1 harus TETAP aktif.");
    assert((int)$ses2['is_active'] === 1, "Sesi 2 harus aktif.");

    $activeSessions = pretesCbtGetActiveSessions($pdo);
    assert(count($activeSessions) >= 2, "Harus ada minimal 2 sesi resmi yang aktif bersamaan.");
    echo "✓ Test 2 Passed: Kedua sesi resmi (Islam & Kristen) aktif bersamaan dalam satu waktu.\n";

    // 4. Test pretesCbtState() mengembalikan available_sessions jika > 1 sesi aktif
    $stateMhs = pretesCbtState($pdo, 1, 101);
    assert(!empty($stateMhs['available_sessions']), "State harus menyediakan available_sessions saat peserta belum memulai ujian.");
    assert(count($stateMhs['available_sessions']) >= 2, "available_sessions harus memuat minimal 2 sesi.");
    echo "✓ Test 3 Passed: pretesCbtState() mengembalikan available_sessions untuk dipilih mahasiswa.\n";

    // 5. Test Mahasiswa 1 memulai ujian di Sesi 1 (Islam)
    $start1 = pretesCbtStart($pdo, 1, 101, time(), null, null, true, $ses1Id);
    assert(!empty($start1['attempt']['id']), "Attempt Mahasiswa 1 harus berhasil dibuat.");
    assert((int)$start1['attempt']['session_id'] === $ses1Id, "Attempt Mahasiswa 1 harus terhubung ke Sesi 1.");
    assert(count($start1['attempt']['questions']) === 25, "Mahasiswa 1 harus mendapat 25 butir soal.");

    // Cek bahwa semua butir soal berasal dari Paket 1 (Islam)
    $stmtItems1 = $pdo->prepare("
        SELECT q.code, q.category, q.package_id 
        FROM pretes_cbt_items i 
        JOIN pretes_cbt_questions q ON q.id = i.question_id 
        WHERE i.attempt_id = ?
    ");
    $stmtItems1->execute([$start1['attempt']['id']]);
    $items1 = $stmtItems1->fetchAll();
    foreach ($items1 as $it) {
        assert((int)$it['package_id'] === $pkg1Id, "Semua butir soal Mahasiswa 1 harus dari Paket 1 (Islam).");
        assert($it['category'] === 'Ubudiyah', "Kategori soal Mahasiswa 1 harus Ubudiyah.");
    }
    echo "✓ Test 4 Passed: Mahasiswa 1 di Sesi 1 hanya menerima soal Paket Islam (terisolasi 100%).\n";

    // 6. Test Mahasiswa 2 memulai ujian di Sesi 2 (Kristen)
    $start2 = pretesCbtStart($pdo, 2, 102, time(), null, null, true, $ses2Id);
    assert(!empty($start2['attempt']['id']), "Attempt Mahasiswa 2 harus berhasil dibuat.");
    assert((int)$start2['attempt']['session_id'] === $ses2Id, "Attempt Mahasiswa 2 harus terhubung ke Sesi 2.");
    assert(count($start2['attempt']['questions']) === 25, "Mahasiswa 2 harus mendapat 25 butir soal.");

    // Cek bahwa semua butir soal berasal dari Paket 2 (Kristen)
    $stmtItems2 = $pdo->prepare("
        SELECT q.code, q.category, q.package_id 
        FROM pretes_cbt_items i 
        JOIN pretes_cbt_questions q ON q.id = i.question_id 
        WHERE i.attempt_id = ?
    ");
    $stmtItems2->execute([$start2['attempt']['id']]);
    $items2 = $stmtItems2->fetchAll();
    foreach ($items2 as $it) {
        assert((int)$it['package_id'] === $pkg2Id, "Semua butir soal Mahasiswa 2 harus dari Paket 2 (Kristen).");
        assert($it['category'] === 'Kristen', "Kategori soal Mahasiswa 2 harus Kristen.");
    }
    echo "✓ Test 5 Passed: Mahasiswa 2 di Sesi 2 hanya menerima soal Paket Kristen (terisolasi 100%).\n";

    // 7. Test Penilaian & Finalisasi kedua sesi
    // Mahasiswa 1 jawab benar semua sesuai correct_answer pada attempt
    $attemptItems1 = $pdo->query("SELECT position, correct_answer FROM pretes_cbt_items WHERE attempt_id = {$start1['attempt']['id']}")->fetchAll();
    foreach ($attemptItems1 as $it) {
        pretesCbtChange($pdo, 1, 101, 'save', ['position' => (int)$it['position'], 'answer' => $it['correct_answer']]);
    }
    $final1 = pretesCbtChange($pdo, 1, 101, 'submit', []);
    assert($final1['attempt']['submitted'] === true);
    assert((float)$final1['attempt']['score'] === 100.0);

    // Mahasiswa 2 jawab benar semua sesuai correct_answer pada attempt
    $attemptItems2 = $pdo->query("SELECT position, correct_answer FROM pretes_cbt_items WHERE attempt_id = {$start2['attempt']['id']}")->fetchAll();
    foreach ($attemptItems2 as $it) {
        pretesCbtChange($pdo, 2, 102, 'save', ['position' => (int)$it['position'], 'answer' => $it['correct_answer']]);
    }
    $final2 = pretesCbtChange($pdo, 2, 102, 'submit', []);
    assert($final2['attempt']['submitted'] === true);
    assert((float)$final2['attempt']['score'] === 100.0);
    echo "✓ Test 6 Passed: Kedua mahasiswa berhasil menyelesaikan ujian dengan penilaian akurat di sesinya masing-masing.\n";

    // 8. Test Toggle Deaktivasi & Aktivasi Sesi
    // Nonaktifkan Sesi 2 -> Berhasil karena masih ada Sesi 1 yang aktif
    $deact2Ok = pretesCbtDeactivateSession($pdo, $ses2Id);
    assert($deact2Ok === true, "Deaktivasi Sesi 2 harus berhasil.");
    assert((int)pretesCbtGetSession($pdo, $ses2Id)['is_active'] === 0, "Sesi 2 harus menjadi tidak aktif.");
    assert((int)pretesCbtGetSession($pdo, $ses1Id)['is_active'] === 1, "Sesi 1 harus tetap aktif.");

    // Coba nonaktifkan Sesi 1 (satu-satunya sesi resmi aktif) -> Harus ditolak proteksi minimal 1 sesi
    $deact1Fail = pretesCbtDeactivateSession($pdo, $ses1Id);
    assert($deact1Fail === false, "Deaktivasi sesi aktif terakhir harus dicegah (proteksi minimal 1 sesi aktif).");
    assert((int)pretesCbtGetSession($pdo, $ses1Id)['is_active'] === 1, "Sesi 1 harus tetap aktif.");

    // Aktifkan kembali Sesi 2 tanpa exclusive -> Kedua sesi aktif lagi
    $act2Ok = pretesCbtActivateSession($pdo, $ses2Id, false);
    assert($act2Ok === true, "Aktivasi Sesi 2 harus berhasil.");
    assert((int)pretesCbtGetSession($pdo, $ses1Id)['is_active'] === 1, "Sesi 1 harus tetap aktif.");
    assert((int)pretesCbtGetSession($pdo, $ses2Id)['is_active'] === 1, "Sesi 2 harus aktif kembali.");
    echo "✓ Test 7 Passed: Toggle aktivasi & nonaktivasi sesi ujian berfungsi presisi dengan proteksi keamanan.\n";

    echo "\n=== SEMUA TEST SUITE CBT MULTI-ACTIVE SESSIONS LULUS SEMPURNA! ===\n";
}

runMultiSessionTests();
