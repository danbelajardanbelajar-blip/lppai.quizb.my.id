<?php
/**
 * Test suite for CBT Paket Soal Management and Prerequisite Enforcement
 */
require_once __DIR__ . '/../includes/pretes-cbt.php';
require_once __DIR__ . '/../includes/pretes-cbt-xlsx.php';

function runPackageTests(): void {
    echo "=== Running CBT Paket Soal Tests ===\n";
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Setup base tables for user & registrations
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, nim TEXT, nama_lengkap TEXT)");
    $pdo->exec("INSERT INTO users VALUES (1, '2026001', 'Peserta Uji Paket')");
    $pdo->exec("CREATE TABLE pretes_registrations (id INTEGER PRIMARY KEY, user_id INTEGER)");
    $pdo->exec("INSERT INTO pretes_registrations VALUES (10, 1)");

    // 1. Initial table setup and default package seeding
    ensurePretesCbtTables($pdo);
    $packages = pretesCbtGetPackages($pdo);
    assert(count($packages) === 1, "Expected 1 seeded default package, got " . count($packages));
    assert($packages[0]['name'] === 'Paket Pretes Utama', "Expected default package name 'Paket Pretes Utama'");
    assert((int)$packages[0]['is_active'] === 1, "Default package should be active");
    assert((int)$packages[0]['duration_minutes'] === 30, "Default duration should be 30");
    assert((int)$packages[0]['question_count'] === 25, "Default question count should be 25");
    echo "✓ Test 1 Passed: Default package seeded and active.\n";

    // 2. Create a new package
    $pkgId = pretesCbtSavePackage($pdo, [
        'name' => 'Paket Pretes Gelombang 2',
        'code' => 'PKT-GEL-2',
        'description' => 'Khusus gelombang 2 fakultas teknik',
        'duration_minutes' => 45,
        'question_count' => 10,
        'is_active' => 0
    ]);
    assert($pkgId > 1, "Expected new package ID > 1");
    $pkg = pretesCbtGetPackage($pdo, $pkgId);
    assert($pkg['name'] === 'Paket Pretes Gelombang 2');
    assert($pkg['code'] === 'PKT-GEL-2');
    assert((int)$pkg['duration_minutes'] === 45);
    assert((int)$pkg['question_count'] === 10);
    assert((int)$pkg['is_active'] === 0);
    echo "✓ Test 2 Passed: Create new package.\n";

    // 3. Switch active package
    $ok = pretesCbtActivatePackage($pdo, $pkgId);
    assert($ok === true, "Expected package activation to return true");
    $active = pretesCbtGetActivePackage($pdo);
    assert($active['id'] == $pkgId, "Expected active package ID to be $pkgId");
    assert($active['code'] === 'PKT-GEL-2');
    
    // Verify other package is now inactive
    $oldPkg = pretesCbtGetPackage($pdo, 1);
    assert((int)$oldPkg['is_active'] === 0, "Old package should now be inactive");
    echo "✓ Test 3 Passed: Package activation toggle.\n";

    // 4. Save question with package assignment
    for ($i = 1; $i <= 12; $i++) {
        pretesCbtSaveQuestion($pdo, [
            'package_id' => $pkgId,
            'code' => "G2-SOAL-$i",
            'category' => 'Fiqih',
            'question_text' => "Pertanyaan Gelombang 2 nomor $i",
            'option_A' => 'Pilihan A',
            'option_B' => 'Pilihan B',
            'option_C' => 'Pilihan C',
            'option_D' => 'Pilihan D',
            'option_E' => 'Pilihan E',
            'correct_answer' => 'A',
            'is_active' => 1
        ]);
    }
    $pkgAfterQ = pretesCbtGetPackage($pdo, $pkgId);
    assert((int)$pkgAfterQ['total_questions'] === 12, "Expected 12 total questions in package, got " . $pkgAfterQ['total_questions']);
    assert((int)$pkgAfterQ['question_count_actual'] === 12, "Expected 12 active questions in package");
    echo "✓ Test 4 Passed: Question assignment and count aggregation.\n";

    // 5. Test Exam Start (pretesCbtStart) uses the active package (PKT-GEL-2: 45 min, 10 questions)
    $examState = pretesCbtStart($pdo, 1, 10);
    assert(isset($examState['attempt']), "Expected attempt key in exam state");
    $attemptData = $examState['attempt'];
    assert((int)$attemptData['total'] === 10, "Expected 10 items in exam from active package, got " . $attemptData['total']);
    // Check deadline is roughly 45 minutes from now
    $remainingMinutes = ceil($attemptData['remaining_seconds'] / 60);
    assert($remainingMinutes >= 44 && $remainingMinutes <= 46, "Expected ~45 minutes remaining, got $remainingMinutes");
    assert(strpos($attemptData['questions'][0]['text'], 'Pertanyaan Gelombang 2') !== false, "Questions should come from active package");
    echo "✓ Test 5 Passed: pretesCbtStart dynamically adopts active package duration and questions.\n";

    // 6. Test Package Deletion
    $delOk = pretesCbtDeletePackage($pdo, $pkgId);
    assert($delOk === true, "Delete package should return true");
    assert(pretesCbtGetPackage($pdo, $pkgId) === null, "Deleted package should not exist");
    // Verify questions in deleted package are removed
    $leftoverQ = (int)$pdo->query("SELECT COUNT(*) FROM pretes_cbt_questions WHERE package_id = $pkgId")->fetchColumn();
    assert($leftoverQ === 0, "Questions in deleted package should be cascade removed");
    // Verify fallback active package
    $newActive = pretesCbtGetActivePackage($pdo);
    assert($newActive !== null && (int)$newActive['is_active'] === 1, "Fallback active package should be set");
    echo "✓ Test 6 Passed: Package deletion and cascade cleanup.\n";

    // 7. Test validation of package inputs
    $nameError = false;
    try {
        pretesCbtSavePackage($pdo, ['name' => '   ']);
    } catch (InvalidArgumentException $e) {
        $nameError = true;
    }
    assert($nameError, "Empty package name must throw InvalidArgumentException");
    echo "✓ Test 7 Passed: Validation for package input.\n";

    echo "\n=== ALL CBT PACKAGE TESTS PASSED SUCCESSFULLY ===\n";
}

runPackageTests();
