<?php
/**
 * Test Suite: CBT Session Package Isolation & Contaminated Attempt Cleanup
 *
 * Menguji:
 * 1. Pencegahan kontaminasi silang bank soal antar sesi ujian resmi (Islam & Kristen).
 * 2. Normalisasi kolom package_ids_json pada SESI-REGULER-2026 (Paket 1) dan SESI-RESMI-PROTESTAN-2026 (Paket 2).
 * 3. Pembersihan otomatis attempt yang tercemar butir soal di luar paket resmi sesi bersangkutan.
 * 4. Proteksi di level pretesCbtStart() sehingga sesi Kristen 100% eksklusif menarik soal Paket Kristen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/pretes-cbt.php';

echo "=== Menjalankan Test Suite: CBT Session Package Isolation ===\n";

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// 1. Setup skema tabel dasar
$pdo->exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nim VARCHAR(30) UNIQUE,
    nama_lengkap VARCHAR(150),
    email VARCHAR(100),
    role VARCHAR(20) DEFAULT 'mahasiswa'
)");

$pdo->exec("CREATE TABLE periods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nama_periode VARCHAR(100),
    is_active INTEGER DEFAULT 1
)");

$pdo->exec("CREATE TABLE pretes_registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    period_id INTEGER,
    status VARCHAR(30) DEFAULT 'terverifikasi',
    created_at INTEGER
)");

ensurePretesCbtTables($pdo);

// 2. Buat data user & pendaftaran
$pdo->exec("INSERT INTO users (id, nim, nama_lengkap, email, role) VALUES 
    (1, '2504102001', 'Mahasiswa Muslim', 'muslim@example.com', 'mahasiswa'),
    (2, '2504102096', 'ERWIN MANDHASARI SIMANJUNTAK', 'erwin@example.com', 'mahasiswa')");

$pdo->exec("INSERT INTO periods (id, nama_periode, is_active) VALUES (1, 'Periode Pretes 2026', 1)");
$pdo->exec("INSERT INTO pretes_registrations (id, user_id, period_id, status, created_at) VALUES 
    (101, 1, 1, 'terverifikasi', strftime('%s', 'now')),
    (102, 2, 1, 'terverifikasi', strftime('%s', 'now'))");

// 3. Setup Paket 1 (Ubudiyah Islam) & Paket 2 (Kristen Protestan)
$pkg1Id = (int)$pdo->query("SELECT id FROM pretes_cbt_packages WHERE code = 'PKT-UTAMA'")->fetchColumn();
if (!$pkg1Id) {
    $pkg1Id = pretesCbtSavePackage($pdo, [
        'name' => 'Paket 1 - Ubudiyah Islam',
        'code' => 'PKT-UTAMA',
        'description' => 'Paket Soal Fiqih/Ubudiyah Islam',
        'duration_minutes' => 30,
        'question_count' => 25,
        'is_active' => 1
    ]);
}

$pkg2Id = (int)$pdo->query("SELECT id FROM pretes_cbt_packages WHERE code = 'PKT-2-KRISTEN-PROTESTAN'")->fetchColumn();
if (!$pkg2Id) {
    $pkg2Id = pretesCbtSavePackage($pdo, [
        'name' => 'Paket 2 - Kristen Protestan',
        'code' => 'PKT-2-KRISTEN-PROTESTAN',
        'description' => 'Paket Soal Pendidikan Agama Kristen',
        'duration_minutes' => 30,
        'question_count' => 25,
        'is_active' => 1
    ]);
}

// Bersihkan soal default agar bersih untuk pengujian
$pdo->exec("DELETE FROM pretes_cbt_questions");

// Tambahkan 25 butir soal ke masing-masing paket
$stmtQ = $pdo->prepare("INSERT INTO pretes_cbt_questions (code, package_id, category, question_text, options_json, correct_answer, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");

for ($i = 1; $i <= 25; $i++) {
    $code1 = sprintf('PKT1-ISLAM-%03d', $i);
    $qText1 = "Soal Islam #{$i}: Di manakah batas aurat perempuan merdeka dalam shalat?";
    $opts1 = json_encode(['A' => 'Jawaban A', 'B' => 'Jawaban B', 'C' => 'Jawaban C', 'D' => 'Jawaban D', 'E' => 'Jawaban E']);
    $stmtQ->execute([$code1, $pkg1Id, 'Ubudiyah', $qText1, $opts1, 'A']);

    $code2 = sprintf('PKT2-KRISTEN-%03d', $i);
    $qText2 = "Soal Kristen #{$i}: Pertanyaan iman kristiani dan etika teologi?";
    $opts2 = json_encode(['A' => 'Pilihan A', 'B' => 'Pilihan B', 'C' => 'Pilihan C', 'D' => 'Pilihan D', 'E' => 'Pilihan E']);
    $stmtQ->execute([$code2, $pkg2Id, 'Pendidikan Agama Kristen', $qText2, $opts2, 'A']);
}

echo "✓ Test 1 Passed: Paket 1 (Islam) & Paket 2 (Kristen) terisi masing-masing 25 butir soal.\n";

// 4. Setup kedua Sesi Ujian dengan simulasi database tercemar (package_ids_json = [1, 2])
$ses1Id = pretesCbtSaveSession($pdo, [
    'id' => 1,
    'title' => 'Sesi Pretes Reguler 2026',
    'code' => 'SESI-REGULER-2026',
    'package_ids' => [$pkg1Id, $pkg2Id], // sengaja tercemar gabungan
    'duration_minutes' => 30,
    'camera_mode' => 'wajib',
    'is_active' => 1
]);

$ses2Id = pretesCbtSaveSession($pdo, [
    'title' => 'Sesi Pretes Resmi – Kristen Protestan',
    'code' => 'SESI-RESMI-PROTESTAN-2026',
    'package_ids' => [$pkg1Id, $pkg2Id], // sengaja tercemar gabungan
    'duration_minutes' => 30,
    'camera_mode' => 'wajib',
    'is_active' => 1
]);

// Buat attempt tercemar pada Erwin (user 2) di Sesi 2 yang berisi soal Paket 1
$now = time();
$pdo->prepare("INSERT INTO pretes_cbt_attempts (user_id, registration_id, session_id, started_at, deadline_at) VALUES (2, 102, ?, ?, ?)")
    ->execute([$ses2Id, $now, $now + 1800]);
$corruptedAttemptId = (int)$pdo->lastInsertId();

// Masukkan 1 butir soal Paket 1 (Islam) ke attempt Sesi Kristen Erwin
$islamQId = (int)$pdo->query("SELECT id FROM pretes_cbt_questions WHERE package_id = {$pkg1Id} LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO pretes_cbt_items (attempt_id, position, question_id, question_text, options_json, correct_answer) VALUES (?, 1, ?, 'Soal Islam tercemar', '{}', 'A')")
    ->execute([$corruptedAttemptId, $islamQId]);

assert((int)$pdo->query("SELECT COUNT(*) FROM pretes_cbt_attempts WHERE id = {$corruptedAttemptId}")->fetchColumn() === 1, "Attempt tercemar harus tercatat sebelum pembersihan.");
echo "✓ Test 2 Passed: Simulasi database tercemar [1, 2] dan attempt Kristen Erwin yang tercemar soal Islam berhasil disiapkan.\n";

// 5. Jalankan normalisasi pretesCbtEnsureDefaultSessionsActive dengan force = true
pretesCbtEnsureDefaultSessionsActive($pdo, true);

// Verifikasi package_ids_json pada kedua sesi
$ses1Clean = pretesCbtGetSession($pdo, $ses1Id);
$ses2Clean = pretesCbtGetSession($pdo, $ses2Id);

assert($ses1Clean['package_ids'] === [$pkg1Id], "SESI-REGULER-2026 harus terisolasi HANYA ke Paket 1.");
assert($ses2Clean['package_ids'] === [$pkg2Id], "SESI-RESMI-PROTESTAN-2026 harus terisolasi HANYA ke Paket 2.");
echo "✓ Test 3 Passed: pretesCbtEnsureDefaultSessionsActive() menormalkan package_ids_json SESI-REGULER ke [1] dan SESI-RESMI-PROTESTAN ke [2].\n";

// Verifikasi attempt tercemar Erwin otomatis terhapus
$attemptCount = (int)$pdo->query("SELECT COUNT(*) FROM pretes_cbt_attempts WHERE id = {$corruptedAttemptId}")->fetchColumn();
assert($attemptCount === 0, "Attempt tercemar pada Sesi Kristen harus otomatis dihapus.");
echo "✓ Test 4 Passed: Attempt tercemar pada Sesi Kristen otomatis dibersihkan tanpa meninggalkan jejak kotor.\n";

// 6. Test Erwin memulai ujian baru di Sesi 2 (Kristen)
$startKristen = pretesCbtStart($pdo, 2, 102, time(), null, null, true, $ses2Id);
assert(!empty($startKristen['attempt']['id']), "Attempt baru Erwin harus berhasil dibuat.");
assert(count($startKristen['attempt']['questions']) === 25, "Erwin harus mendapatkan tepat 25 butir soal.");

// Periksa setiap butir soal: SEMUANYA harus berasal dari Paket 2 (Kristen)
$itemsStmt = $pdo->prepare("SELECT q.package_id, q.category, q.code FROM pretes_cbt_items i JOIN pretes_cbt_questions q ON q.id = i.question_id WHERE i.attempt_id = ?");
$itemsStmt->execute([$startKristen['attempt']['id']]);
$kristenItems = $itemsStmt->fetchAll();

foreach ($kristenItems as $item) {
    assert((int)$item['package_id'] === $pkg2Id, "Setiap butir soal di Sesi Kristen WAJIB berasal dari Paket 2 Kristen (ditemukan package_id: {$item['package_id']}).");
    assert($item['category'] === 'Pendidikan Agama Kristen', "Kategori soal harus Pendidikan Agama Kristen.");
}
echo "✓ Test 5 Passed: Erwin di Sesi Kristen 100% menerima soal Paket Kristen (0% kontaminasi Islam).\n";

// 7. Test Mahasiswa Muslim memulai ujian di Sesi 1 (Reguler)
$startMuslim = pretesCbtStart($pdo, 1, 101, time(), null, null, true, $ses1Id);
assert(!empty($startMuslim['attempt']['id']), "Attempt baru Mahasiswa Muslim harus berhasil dibuat.");

$itemsStmt->execute([$startMuslim['attempt']['id']]);
$muslimItems = $itemsStmt->fetchAll();

foreach ($muslimItems as $item) {
    assert((int)$item['package_id'] === $pkg1Id, "Setiap butir soal di Sesi Reguler WAJIB berasal dari Paket 1 Islam (ditemukan package_id: {$item['package_id']}).");
    assert($item['category'] === 'Ubudiyah', "Kategori soal harus Ubudiyah.");
}
echo "✓ Test 6 Passed: Mahasiswa Muslim di Sesi Reguler 100% menerima soal Paket Islam (0% kontaminasi Kristen).\n";

// 8. Test Hard Safety Guard: Bahkan jika package_ids_json sengaja diisi [1, 2] lagi di database,
// pretesCbtStart() tetap mengisolasi SESI-RESMI-PROTESTAN-2026 secara eksklusif ke Paket 2
$pdo->prepare("UPDATE pretes_cbt_sessions SET package_ids_json = ? WHERE id = ?")
    ->execute([json_encode([$pkg1Id, $pkg2Id]), $ses2Id]);

// Hapus attempt Erwin untuk menguji start ulang dengan database terkontaminasi
pretesCbtDeleteAttempt($pdo, (int)$startKristen['attempt']['id']);

$startProtected = pretesCbtStart($pdo, 2, 102, time(), null, null, true, $ses2Id);
$itemsStmt->execute([$startProtected['attempt']['id']]);
$protectedItems = $itemsStmt->fetchAll();

foreach ($protectedItems as $item) {
    assert((int)$item['package_id'] === $pkg2Id, "Safety guard pretesCbtStart() WAJIB menjaga isolasi 100% Paket 2 meskipun package_ids_json di database dicemari.");
}
echo "✓ Test 7 Passed: Hard safety guard di pretesCbtStart() berhasil melindungi Sesi Kristen dari kontaminasi database.\n";

echo "\n=== SEMUA 7 PENGUJIAN ISOLASI PAKET SOAL SESI LULUS 100%! ===\n";
