<?php
/**
 * Test: Pembatasan Pendaftaran Tutorial untuk Mahasiswa yang Lulus Pretes
 */

require_once __DIR__ . '/../includes/pretes-helper.php';

// Test 1: SQLite in-memory test for isUserLulusPretes()
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE pretes_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        status_lulus TEXT NOT NULL
    );
    CREATE TABLE pretes_registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL
    );
    CREATE TABLE pretes_assessments (
        registration_id INTEGER PRIMARY KEY,
        scores_json TEXT NOT NULL
    );
");

// User 1: Lulus di pretes_results
$pdo->exec("INSERT INTO pretes_results (user_id, status_lulus) VALUES (1, 'lulus')");
assert(isUserLulusPretes($pdo, 1) === true, "User 1 should be LULUS from pretes_results");

// User 2: Tidak Lulus di pretes_results
$pdo->exec("INSERT INTO pretes_results (user_id, status_lulus) VALUES (2, 'tidak_lulus')");
assert(isUserLulusPretes($pdo, 2) === false, "User 2 should be TIDAK LULUS");

// User 3: Belum di pretes_results, tapi asesmen 12 komponen semua >= 80
$pdo->exec("INSERT INTO pretes_registrations (id, user_id) VALUES (10, 3)");
$scoresLulus = [];
foreach (pretesComponents() as $k => $l) {
    $scoresLulus[$k] = 85;
}
$stmt = $pdo->prepare("INSERT INTO pretes_assessments (registration_id, scores_json) VALUES (10, ?)");
$stmt->execute([json_encode($scoresLulus)]);
assert(isUserLulusPretes($pdo, 3) === true, "User 3 should be LULUS from 12 components assessment");

// User 4: Asesmen ada yang < 80
$pdo->exec("INSERT INTO pretes_registrations (id, user_id) VALUES (20, 4)");
$scoresGagal = $scoresLulus;
$scoresGagal['ujian_tulis'] = 75;
$stmt4 = $pdo->prepare("INSERT INTO pretes_assessments (registration_id, scores_json) VALUES (20, ?)");
$stmt4->execute([json_encode($scoresGagal)]);
assert(isUserLulusPretes($pdo, 4) === false, "User 4 should be TIDAK LULUS because ujian_tulis < 80");

// User 5: Belum pernah ikut pretes sama sekali
assert(isUserLulusPretes($pdo, 5) === false, "User 5 with no pretes should be false");

echo "[PASS] isUserLulusPretes() unit tests passed in all scenarios.\n";

// Test 2: Code inspection of pages/tutorial-pendaftaran.php
$tutCode = file_get_contents(__DIR__ . '/../pages/tutorial-pendaftaran.php');
assert(strpos($tutCode, 'isUserLulusPretes($pdo, $user[\'id\'])') !== false, "isUserLulusPretes lookup missing in tutorial-pendaftaran.php");
assert(strpos($tutCode, 'Selamat, Anda Dinyatakan LULUS Pretes!') !== false, "Celebration card missing in tutorial-pendaftaran.php");
assert(strpos($tutCode, 'dibebaskan dari kewajiban mengikuti program Tutorial LPPAI') !== false, "Exemption notice missing in tutorial-pendaftaran.php");
assert(strpos($tutCode, '!$isLulusPretes') !== false, "Protection logic against registration missing in tutorial-pendaftaran.php");
echo "[PASS] pages/tutorial-pendaftaran.php lock & UI confirmed.\n";

// Test 3: Code inspection of pages/dashboard.php
$dashCode = file_get_contents(__DIR__ . '/../pages/dashboard.php');
assert(strpos($dashCode, 'isUserLulusPretes($pdo, $user[\'id\'])') !== false, "isUserLulusPretes fallback missing in dashboard.php");
assert(strpos($dashCode, 'if (!$isPretesLulus):') !== false, "Daftar Tutorial button hiding condition missing in dashboard.php");
echo "[PASS] pages/dashboard.php integration confirmed.\n";

echo "ALL TUTORIAL LULUS PRETES LOCK TESTS PASSED!\n";
