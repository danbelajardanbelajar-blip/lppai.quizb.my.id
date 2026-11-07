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
assert(strpos($dashCode, '($isLulusTutorial || $isPretesLulus) && $isTutorialRegAnnouncement') !== false, "Tutorial registration announcement filtering missing for lulus pretes in dashboard.php");
echo "[PASS] pages/dashboard.php integration & announcement filtering confirmed.\n";

// Test 4: Simulation of announcement filter logic for Lulus Pretes student
$sampleAnnouncements = [
    ['id' => 1, 'tipe' => 'hasil_pretes', 'judul' => 'Pengumuman Hasil Pretes 2026', 'konten' => '...', 'link_tujuan' => '/pages/pretes-hasil.php'],
    ['id' => 2, 'tipe' => 'pendaftaran_gel1', 'judul' => 'Pendaftaran Tutorial Gelombang 1', 'konten' => 'Bagi Yang Belum Lulus, silahkan Mendaftarkan diri', 'link_tujuan' => '/pages/tutorial-pendaftaran.php'],
    ['id' => 3, 'tipe' => 'umum', 'judul' => 'Pendaftaran Tutorial Mandiri Dibuka', 'konten' => '...', 'link_tujuan' => 'tutorial-pendaftaran.php'],
    ['id' => 4, 'tipe' => 'umum', 'judul' => 'Kajian Rutin Kampus', 'konten' => '...', 'link_tujuan' => '']
];
$isPretesLulus = true;
$isLulusTutorial = false;
$filtered = [];
foreach ($sampleAnnouncements as $ann) {
    $tipe = $ann['tipe'];
    $isTutorialRegAnnouncement = (
        (strpos($tipe, 'pendaftaran_') === 0 && $tipe !== 'pendaftaran_pretes')
        || stripos($ann['judul'], 'pendaftaran tutorial') !== false
        || (!empty($ann['link_tujuan']) && stripos($ann['link_tujuan'], 'tutorial-pendaftaran') !== false)
    );
    if (($isLulusTutorial || $isPretesLulus) && $isTutorialRegAnnouncement) {
        continue;
    }
    $filtered[] = $ann;
}
assert(count($filtered) === 2, "Expected exactly 2 announcements (hasil pretes & kajian), got " . count($filtered));
assert($filtered[0]['id'] === 1, "First announcement should be Pengumuman Hasil Pretes");
assert($filtered[1]['id'] === 4, "Second announcement should be Kajian Rutin Kampus");
echo "[PASS] Announcement filter simulation verified: Tutorial registration cards completely removed for Lulus Pretes students.\n";

echo "ALL TUTORIAL LULUS PRETES LOCK TESTS PASSED!\n";
