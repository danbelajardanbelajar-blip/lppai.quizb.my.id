<?php
/**
 * Test Suite: CBT Sesi Ujian (Exam Session Management), Category Quotas, Camera, Scoring & Token
 */
require_once __DIR__ . '/../includes/pretes-cbt.php';
require_once __DIR__ . '/../includes/pretes-cbt-xlsx.php';

function runSessionTests(): void {
    echo "=== Menjalankan Test Suite: CBT Sesi Ujian (Exam Sessions) ===\n";
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Setup base tables for user & registrations
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, nim TEXT, nama_lengkap TEXT)");
    $pdo->exec("INSERT INTO users VALUES (1, '2026001', 'Peserta Sesi A'), (2, '2026002', 'Peserta Sesi B')");
    $pdo->exec("CREATE TABLE pretes_registrations (id INTEGER PRIMARY KEY, user_id INTEGER)");
    $pdo->exec("INSERT INTO pretes_registrations VALUES (101, 1), (102, 2)");

    // 1. Initial table setup and default session seeding
    ensurePretesCbtTables($pdo);
    $sessions = pretesCbtGetSessions($pdo);
    assert(!empty($sessions), "Tabel pretes_cbt_sessions harus memiliki default seeded session.");
    $defaultSession = $sessions[0];
    assert($defaultSession['code'] === 'SESI-REGULER-2026', "Default session code harus SESI-REGULER-2026");
    assert((int)$defaultSession['is_active'] === 1, "Default session harus aktif");
    echo "✓ Test 1 Passed: Inisialisasi tabel pretes_cbt_sessions & default seeded session.\n";

    // 2. Setup 2 Packages with categorized questions
    $pkg1Id = pretesCbtSavePackage($pdo, [
        'name' => 'Paket Ubudiyah Dasar',
        'code' => 'PKT-UBUD-1',
        'duration_minutes' => 30,
        'question_count' => 15,
        'is_active' => 1
    ]);
    $pkg2Id = pretesCbtSavePackage($pdo, [
        'name' => 'Paket Al-Qur\'an Hadits',
        'code' => 'PKT-QURAN-2',
        'duration_minutes' => 45,
        'question_count' => 10,
        'is_active' => 0
    ]);

    // Seed questions for categories: Fiqih (5), Al-Qur'an (5), Ubudiyah (5)
    for ($i = 1; $i <= 5; $i++) {
        pretesCbtSaveQuestion($pdo, [
            'package_id' => $pkg1Id,
            'code' => "FIQIH-$i",
            'category' => 'Fiqih',
            'question_text' => "Pertanyaan Fiqih nomor $i",
            'option_A' => 'A', 'option_B' => 'B', 'option_C' => 'C', 'option_D' => 'D', 'option_E' => 'E',
            'correct_answer' => 'A', 'is_active' => 1
        ]);
        pretesCbtSaveQuestion($pdo, [
            'package_id' => $pkg1Id,
            'code' => "UBUD-$i",
            'category' => 'Ubudiyah',
            'question_text' => "Pertanyaan Ubudiyah nomor $i",
            'option_A' => 'A', 'option_B' => 'B', 'option_C' => 'C', 'option_D' => 'D', 'option_E' => 'E',
            'correct_answer' => 'B', 'is_active' => 1
        ]);
        pretesCbtSaveQuestion($pdo, [
            'package_id' => $pkg2Id,
            'code' => "QURAN-$i",
            'category' => 'Al-Quran',
            'question_text' => "Pertanyaan Al-Quran nomor $i",
            'option_A' => 'A', 'option_B' => 'B', 'option_C' => 'C', 'option_D' => 'D', 'option_E' => 'E',
            'correct_answer' => 'C', 'is_active' => 1
        ]);
    }
    echo "✓ Test 2 Passed: Setup 2 paket soal dengan 3 kategori (Fiqih, Ubudiyah, Al-Quran).\n";

    // 3. Create Custom Session with Category Quotas, Camera Mode, Token, Duration & Scoring
    $sesId = pretesCbtSaveSession($pdo, [
        'title' => 'Sesi Ujian Khusus Gelombang 1',
        'code' => 'SESI-GEL1-2026',
        'description' => 'Sesi ujian dengan proporsi Fiqih 3, Ubudiyah 2, Al-Quran 2',
        'package_ids' => [$pkg1Id, $pkg2Id],
        'category_quotas' => [
            'Fiqih' => 3,
            'Ubudiyah' => 2,
            'Al-Quran' => 2
        ],
        'camera_mode' => 'tidak_aktif',
        'duration_minutes' => 40,
        'scoring_method' => 'penalti',
        'passing_grade' => 70.00,
        'start_time' => '2026-11-01 08:00',
        'end_time' => '2026-11-30 23:59',
        'token' => 'GEL1OK',
        'token_required' => 1,
        'is_active' => 1
    ]);
    assert($sesId > 0, "Sesi baru harus berhasil disimpan.");
    $ses = pretesCbtGetSession($pdo, $sesId);
    assert($ses['title'] === 'Sesi Ujian Khusus Gelombang 1');
    assert($ses['camera_mode'] === 'tidak_aktif');
    assert((int)$ses['duration_minutes'] === 40);
    assert($ses['scoring_method'] === 'penalti');
    assert((int)$ses['token_required'] === 1);
    assert($ses['token'] === 'GEL1OK');
    assert($ses['category_quotas']['Fiqih'] == 3);
    assert($ses['category_quotas']['Ubudiyah'] == 2);
    assert($ses['category_quotas']['Al-Quran'] == 2);
    echo "✓ Test 3 Passed: Pembuatan Sesi Ujian dengan konfigurasi lengkap.\n";

    // 4. Token Enforcement in Exam Start
    $tokenFailed = false;
    try {
        pretesCbtStart($pdo, 1, 101, time(), 'WRONG_TOKEN');
    } catch (InvalidArgumentException $e) {
        $tokenFailed = true;
    }
    assert($tokenFailed, "Mulai ujian dengan token salah harus melempar InvalidArgumentException.");

    // Start with correct token
    $examState = pretesCbtStart($pdo, 1, 101, time(), 'GEL1OK');
    assert(isset($examState['attempt']), "Sesi ujian harus berhasil dimulai dengan token yang benar.");
    assert((int)$examState['attempt']['total'] === 7, "Total soal harus sesuai kadar kategori (3+2+2 = 7), didapat: " . $examState['attempt']['total']);
    
    // Check remaining time matches session duration (40 menit = 2400 detik)
    $remainingMin = ceil($examState['attempt']['remaining_seconds'] / 60);
    assert($remainingMin >= 39 && $remainingMin <= 41, "Durasi ujian harus sesuai sesi (40 menit).");

    // Verify exact category distribution in items
    $items = $pdo->query("SELECT q.category, COUNT(*) as cnt FROM pretes_cbt_items i JOIN pretes_cbt_questions q ON q.id = i.question_id WHERE i.attempt_id = {$examState['attempt']['id']} GROUP BY q.category")->fetchAll(PDO::FETCH_KEY_PAIR);
    assert((int)($items['Fiqih'] ?? 0) === 3, "Harus terdapat tepat 3 soal Fiqih.");
    assert((int)($items['Ubudiyah'] ?? 0) === 2, "Harus terdapat tepat 2 soal Ubudiyah.");
    assert((int)($items['Al-Quran'] ?? 0) === 2, "Harus terdapat tepat 2 soal Al-Quran.");
    echo "✓ Test 4 Passed: Validasi token dan penarikan soal sesuai kadar kuota per kategori (3 Fiqih, 2 Ubudiyah, 2 Al-Quran).\n";

    // 5. Test Penalti Scoring Method in Finalize
    // User answers: 4 correct, 2 wrong, 1 empty
    $attemptItems = $pdo->query("SELECT position, correct_answer FROM pretes_cbt_items WHERE attempt_id = {$examState['attempt']['id']} ORDER BY position ASC")->fetchAll();
    foreach ($attemptItems as $idx => $it) {
        if ($idx < 4) {
            // Correct answer
            pretesCbtChange($pdo, 1, 101, 'save', ['position' => $it['position'], 'answer' => $it['correct_answer']]);
        } elseif ($idx < 6) {
            // Wrong answer
            $wrongAnswer = $it['correct_answer'] === 'A' ? 'B' : 'A';
            pretesCbtChange($pdo, 1, 101, 'save', ['position' => $it['position'], 'answer' => $wrongAnswer]);
        }
        // idx == 6 remains empty
    }
    $finalized = pretesCbtChange($pdo, 1, 101, 'submit', []);
    assert($finalized['attempt']['submitted'] === true, "Ujian harus berstatus diserahkan (submitted).");
    assert((int)$finalized['attempt']['correct'] === 4, "Jumlah benar harus 4.");
    // Penalti score calculation:
    // Raw score = (4 * 4) - (2 * 1) = 16 - 2 = 14
    // Max raw = 7 * 4 = 28
    // Scaled score = (14 / 28) * 100 = 50.00
    assert(abs((float)$finalized['attempt']['score'] - 50.00) < 0.1, "Nilai metode penalti harus 50.00, didapat: " . $finalized['attempt']['score']);
    echo "✓ Test 5 Passed: Perhitungan nilai dengan metode penalti UTBK (+4 benar, -1 salah, 0 kosong).\n";

    // 6. Test Start Time & End Time Window Restrictions
    // Future session
    $futureSesId = pretesCbtSaveSession($pdo, [
        'title' => 'Sesi Masa Depan',
        'code' => 'SESI-FUT-2026',
        'package_ids' => [$pkg1Id],
        'start_time' => date('Y-m-d H:i', time() + 3600), // 1 hour later
        'end_time' => date('Y-m-d H:i', time() + 7200),
        'is_active' => 1
    ]);
    $futureBlocked = false;
    try {
        pretesCbtStart($pdo, 2, 102, time());
    } catch (RuntimeException $e) {
        $futureBlocked = (strpos($e->getMessage(), 'belum dibuka') !== false);
    }
    assert($futureBlocked, "Mulai ujian sebelum start_time harus diblokir dengan pesan 'belum dibuka'.");

    // Expired session
    $pastSesId = pretesCbtSaveSession($pdo, [
        'title' => 'Sesi Masa Lalu',
        'code' => 'SESI-PAST-2026',
        'package_ids' => [$pkg1Id],
        'start_time' => date('Y-m-d H:i', time() - 7200),
        'end_time' => date('Y-m-d H:i', time() - 3600), // ended 1 hour ago
        'is_active' => 1
    ]);
    $pastBlocked = false;
    try {
        pretesCbtStart($pdo, 2, 102, time());
    } catch (RuntimeException $e) {
        $pastBlocked = (strpos($e->getMessage(), 'telah ditutup') !== false);
    }
    assert($pastBlocked, "Mulai ujian setelah end_time harus diblokir dengan pesan 'telah ditutup'.");
    echo "✓ Test 6 Passed: Pembatasan waktu mulai dan batas akhir (window jadwal ujian).\n";

    // 7. Verify UI View Template & Modals Markup
    $viewContent = file_get_contents(__DIR__ . '/../includes/pretes-cbt-admin-view.php');
    assert(strpos($viewContent, 'id="modalPackageForm"') !== false, "Markup modalPackageForm harus ada.");
    assert(strpos($viewContent, 'id="modalSessionForm"') !== false, "Markup modalSessionForm harus ada.");
    assert(strpos($viewContent, 'id="modalQuickQuestion"') !== false, "Markup modalQuickQuestion harus ada.");
    assert(strpos($viewContent, 'openSessionModal') !== false, "Handler JS openSessionModal harus ada.");
    assert(strpos($viewContent, 'openPackageModal') !== false, "Handler JS openPackageModal harus ada.");
    assert(strpos($viewContent, 'generateRandomToken') !== false, "Handler JS generateRandomToken harus ada.");
    echo "✓ Test 7 Passed: Verifikasi kelengkapan komponen modal in-place dan controller JS pada view.\n";

    echo "\n=== SEMUA TEST SUITE CBT SESI UJIAN BERHASIL 100% ===\n";
}

runSessionTests();
