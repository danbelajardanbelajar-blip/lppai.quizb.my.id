<?php
/**
 * Test Suite: Validasi Pengawas AI Ujian Pretes (Skenario A & Skenario B)
 * Memverifikasi:
 * 1. Auto-migrasi tabel pretes_proctoring_logs di database
 * 2. Fungsi komparasi wajah compareFacesGD (wajah identik vs non-wajah/blank wall)
 * 3. Penyimpanan snapshot bukti proctoring (saveProctoringSnapshot)
 * 4. API api/pretes-proctoring-check.php & Portal pages/pretes-ujian.php
 */

require_once __DIR__ . '/../includes/pretes-helper.php';

$errors = [];
function procAssert(bool $cond, string $msg, array &$errors): void {
    if (!$cond) {
        $errors[] = $msg;
    }
}

echo "=== Menjalankan Test Suite: Skenario A & Skenario B Proctoring Pretes ===\n";

// ── 1. Uji Auto-Migration Tabel Log Proctoring ────────────────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

ensurePretesProctoringTables($pdo);
ensurePretesProctoringTables($pdo); // Uji idempoten

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='pretes_proctoring_logs'")->fetchAll();
procAssert(!empty($tables), "Tabel pretes_proctoring_logs harus berhasil dibuat.", $errors);

$cols = $pdo->query("PRAGMA table_info(pretes_proctoring_logs)")->fetchAll();
$colNames = array_map(fn($c) => strtolower((string)$c['name']), $cols);
foreach (['id', 'registration_id', 'user_id', 'event_type', 'similarity_score', 'status', 'snapshot_path', 'notes', 'created_at'] as $expCol) {
    procAssert(in_array($expCol, $colNames, true), "Kolom '$expCol' wajib ada pada pretes_proctoring_logs.", $errors);
}

// ── 2. Uji Penyimpanan Snapshot Bukti Proctoring ──────────────────────────────
$testNim = '25062108888';
$testIm = imagecreatetruecolor(120, 160);
$bg = imagecolorallocate($testIm, 220, 180, 150);
imagefill($testIm, 0, 0, $bg);
ob_start();
imagejpeg($testIm, null, 80);
$jpegBytes = ob_get_clean();
imagedestroy($testIm);

$testBase64 = 'data:image/jpeg;base64,' . base64_encode($jpegBytes);
$savedSnap = saveProctoringSnapshot($testBase64, $testNim, 'test_snap');

procAssert(!empty($savedSnap), "saveProctoringSnapshot harus mengembalikan relative path tersimpan.", $errors);
procAssert(str_starts_with($savedSnap, 'uploads/pretes_proctoring/'), "Path snapshot harus berada di uploads/pretes_proctoring/.", $errors);

$fullSnapPath = __DIR__ . '/../' . $savedSnap;
procAssert(file_exists($fullSnapPath), "File snapshot fisik harus benar-benar ada di disk.", $errors);
if (file_exists($fullSnapPath)) {
    @unlink($fullSnapPath); // Cleanup
}

// ── 3. Uji Algoritma Komparasi Wajah compareFacesGD ───────────────────────────
// Buat foto referensi dummy
$refIm = imagecreatetruecolor(240, 320);
$skin = imagecolorallocate($refIm, 235, 195, 165);
imagefill($refIm, 0, 0, $skin);
// Fitur wajah (mata, rambut, mulut)
$hair = imagecolorallocate($refIm, 25, 20, 15);
imagefilledrectangle($refIm, 40, 10, 200, 60, $hair);
$eye = imagecolorallocate($refIm, 20, 20, 20);
imagefilledellipse($refIm, 90, 120, 24, 14, $eye);
imagefilledellipse($refIm, 150, 120, 24, 14, $eye);
$mouth = imagecolorallocate($refIm, 160, 60, 60);
imagefilledrectangle($refIm, 95, 220, 145, 234, $mouth);

$tempRefPath = __DIR__ . '/../uploads/pretes_foto/test_ref_proctoring_' . time() . '.jpg';
imagejpeg($refIm, $tempRefPath, 90);
imagedestroy($refIm);

$relRefPath = 'uploads/pretes_foto/' . basename($tempRefPath);

// A. Test Wajah Identik / Sangat Mirip
$liveIdentical = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tempRefPath));
$resIdentical = compareFacesGD($relRefPath, $liveIdentical);

procAssert(!empty($resIdentical['match']), "Wajah identik harus dinyatakan MATCH.", $errors);
procAssert($resIdentical['status'] === 'MATCH', "Status wajah identik harus 'MATCH'.", $errors);
procAssert($resIdentical['similarity'] >= 70.0, "Skor kemiripan wajah identik harus >= 70%, didapat: " . $resIdentical['similarity'], $errors);

// B. Test Blank Wall (Dinding Polos Tanpa Wajah)
$blankIm = imagecreatetruecolor(240, 320);
imagefill($blankIm, 0, 0, imagecolorallocate($blankIm, 200, 200, 200));
ob_start();
imagejpeg($blankIm, null, 80);
$blankBytes = ob_get_clean();
imagedestroy($blankIm);

$liveBlank = 'data:image/jpeg;base64,' . base64_encode($blankBytes);
$resBlank = compareFacesGD($relRefPath, $liveBlank);

procAssert(empty($resBlank['match']), "Dinding polos/tanpa wajah TIDAK boleh MATCH.", $errors);
procAssert($resBlank['status'] === 'NO_FACE', "Status dinding polos harus 'NO_FACE', didapat: " . $resBlank['status'], $errors);

// Cleanup file temp ref
if (file_exists($tempRefPath)) {
    @unlink($tempRefPath);
}

// ── 4. Uji Query Log Pengawasan (getStudentProctoringLogs) ─────────────────────
$testRegId = 999;
$testUserId = 888;
$stmtInsert = $pdo->prepare("
    INSERT INTO pretes_proctoring_logs 
    (registration_id, user_id, event_type, similarity_score, status, snapshot_path, notes)
    VALUES (?, ?, 'initial_checkin', 88.5, 'MATCH', 'uploads/pretes_proctoring/snap1.jpg', 'Checkin OK')
");
$stmtInsert->execute([$testRegId, $testUserId]);

$logs = getStudentProctoringLogs($pdo, $testRegId);
procAssert(count($logs) === 1, "getStudentProctoringLogs harus mengembalikan 1 log tersimpan.", $errors);
procAssert($logs[0]['event_type'] === 'initial_checkin', "event_type log harus 'initial_checkin'.", $errors);
procAssert((float)$logs[0]['similarity_score'] === 88.5, "similarity_score harus 88.5.", $errors);

// ── 5. Uji Sintaks & Integrasi File Halaman & API ──────────────────────────────
$filesToCheck = [
    'api/pretes-proctoring-check.php',
    'pages/pretes-ujian.php',
    'pages/pretes-peserta.php',
    'includes/pretes-helper.php'
];

foreach ($filesToCheck as $f) {
    $full = __DIR__ . '/../' . $f;
    exec('php -l ' . escapeshellarg($full), $out, $ret);
    procAssert($ret === 0, "Sintaks file $f harus valid (no syntax errors).", $errors);
}

// Uji konten pages/pretes-ujian.php (memiliki Skenario A & Skenario B)
$ujianContent = file_get_contents(__DIR__ . '/../pages/pretes-ujian.php');
procAssert(strpos($ujianContent, 'initial_checkin') !== false, "pretes-ujian.php harus mendukung action 'initial_checkin' (Skenario A).", $errors);
procAssert(strpos($ujianContent, 'periodic_5min') !== false, "pretes-ujian.php harus mendukung action 'periodic_5min' (Skenario B).", $errors);
procAssert(strpos($ujianContent, '300') !== false || strpos($ujianContent, '05:00') !== false, "pretes-ujian.php harus memiliki konfigurasi timer 5 menit.", $errors);

if (!empty($errors)) {
    echo "❌ PENGUJIAN GAGAL:\n";
    foreach ($errors as $e) {
        echo " - $e\n";
    }
    exit(1);
}

echo "✅ SEMUA PENGUJIAN PROCTORING (SKENARIO A & B) BERHASIL 100%!\n";
exit(0);
