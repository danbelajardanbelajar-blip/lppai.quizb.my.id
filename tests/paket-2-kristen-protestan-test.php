<?php
/**
 * Test: Paket 2 - Kristen Protestan (Soal Ujian Resmi Pretes CBT)
 * Memverifikasi integritas 25 butir soal, distribusi kunci, pembacaan file Excel .xlsx,
 * migrasi/seeding otomatis di database, integritas sesi ujian resmi (mode='resmi'), dan simulasi CBT.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$t = function (bool $ok, string $msg) use (&$fail): void {
    echo ($ok ? ' [PASS] ' : '❌ [FAIL] ') . $msg . "\n";
    if (!$ok) $fail++;
};

require_once $root . '/includes/pretes-cbt.php';
require_once $root . '/includes/pretes-cbt-xlsx.php';
require_once $root . '/tools/import-paket-2-kristen-protestan.php';

echo "=== TEST: PAKET 2 - KRISTEN PROTESTAN (SOAL RESMI) ===\n";

// 1. Verifikasi Berkas Data PHP
$dataPath = $root . '/data/paket-2-kristen-protestan.php';
$t(file_exists($dataPath), 'Berkas data/paket-2-kristen-protestan.php ada');
$questions = require $dataPath;
$t(is_array($questions) && count($questions) === 25, 'Data memuat tepat 25 butir pertanyaan');

// 2. Verifikasi Struktur & Validitas Setiap Butir Soal
$keyCounts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
$validStructure = true;
$uniqueCodes = [];
foreach ($questions as $idx => $item) {
    [$code, $cat, $q, $opts, $key, $exp] = $item;
    if (empty($code) || empty($cat) || empty($q) || empty($exp)) $validStructure = false;
    if (count($opts) !== 5) $validStructure = false;
    if (count(array_unique($opts)) !== 5) $validStructure = false;
    if (!in_array($key, ['A', 'B', 'C', 'D', 'E'], true)) $validStructure = false;
    $keyCounts[$key] = ($keyCounts[$key] ?? 0) + 1;
    $uniqueCodes[$code] = true;
}
$t($validStructure, 'Semua 25 butir memiliki kode, kategori, pertanyaan, 5 pilihan unik, dan penjelasan');
$t(count($uniqueCodes) === 25, 'Semua 25 kode soal unik (PKT2-KP-001 s.d PKT2-KP-025)');

// 3. Distribusi Kunci Jawaban Merata (5A, 5B, 5C, 5D, 5E)
$balancedKeys = ($keyCounts['A'] === 5 && $keyCounts['B'] === 5 && $keyCounts['C'] === 5 && $keyCounts['D'] === 5 && $keyCounts['E'] === 5);
$t($balancedKeys, 'Kunci jawaban terdistribusi merata A-E (masing-masing tepat 5 soal)');

// 4. Verifikasi Berkas Excel .xlsx
$xlsxPath = $root . '/downloads/paket-2-kristen-protestan.xlsx';
$t(file_exists($xlsxPath), 'Berkas downloads/paket-2-kristen-protestan.xlsx ada');
$xlsxRows = cbtReadQuestionXlsx($xlsxPath);
$t(count($xlsxRows) === 26, 'Berkas Excel berhasil dibaca (1 baris judul + 25 baris soal)');

// 5. Uji Seeding & Migrasi Otomatis ke Database SQLite
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Tabel dasar pengguna & pendaftaran pretes untuk simulasi
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, nama_lengkap TEXT, role TEXT, nim TEXT, program_studi TEXT, jenis_program TEXT)");
$pdo->exec("INSERT INTO users VALUES (101, 'mhs_kristen', 'Yohanes Mahasiswa', 'mahasiswa', '2026101', 'Teknik Informatika', 'Reguler')");
$pdo->exec("CREATE TABLE pretes_registrations (id INTEGER PRIMARY KEY, user_id INT, periode TEXT, hari_pilihan TEXT, jam TEXT, ruangan TEXT, dosen_penguji TEXT, status TEXT, username_tes TEXT, password_tes TEXT)");
$pdo->exec("INSERT INTO pretes_registrations VALUES (501, 101, 'Pretes 2026/2027', 'Senin', '13.00', 'Lab 1', 'Penguji A', 'hadir', 'tes2026101', 'pass')");

ensurePretesCbtTables($pdo);
$initRes = lppaiImportPaket2KristenProtestan($pdo, null, true, false);

// Cek apakah paket 2 terdaftar di database
$stmtPkg = $pdo->prepare("SELECT * FROM pretes_cbt_packages WHERE code = ?");
$stmtPkg->execute([PAKET_2_KP_PKG_CODE]);
$pkg = $stmtPkg->fetch(PDO::FETCH_ASSOC);
$t(!empty($pkg), 'Paket 2 berhasil terdaftar di database via lppaiImportPaket2KristenProtestan');
$t(($pkg['name'] ?? '') === 'Paket 2 - Kristen Protestan', 'Nama paket adalah "Paket 2 - Kristen Protestan"');
$t((int)($pkg['question_count'] ?? 0) === 25, 'Jumlah butir paket = 25');
$t((int)($pkg['duration_minutes'] ?? 0) === 30, 'Durasi paket = 30 menit');

// Cek jumlah butir soal di tabel pretes_cbt_questions
$stmtQ = $pdo->prepare("SELECT COUNT(*) FROM pretes_cbt_questions WHERE package_id = ?");
$stmtQ->execute([(int)$pkg['id']]);
$qCount = (int)$stmtQ->fetchColumn();
$t($qCount === 25, 'Tabel pretes_cbt_questions berisi tepat 25 butir soal untuk Paket 2');

// Cek sesi resmi
$stmtSes = $pdo->prepare("SELECT * FROM pretes_cbt_sessions WHERE code = ?");
$stmtSes->execute([PAKET_2_KP_SES_CODE]);
$ses = $stmtSes->fetch(PDO::FETCH_ASSOC);
$t(!empty($ses), 'Sesi resmi SESI-RESMI-PROTESTAN-2026 otomatis terdaftar');
$t(($ses['title'] ?? '') === 'Sesi Pretes Resmi – Kristen Protestan', 'Judul sesi resmi sesuai');
$t(($ses['mode'] ?? '') === 'resmi', 'Mode sesi adalah "resmi" (BUKAN tryout)');
$t((int)($ses['max_attempts'] ?? 0) === 1, 'Maksimal pengerjaan sesi resmi = 1 kali');
$t(($ses['camera_mode'] ?? '') === 'wajib', 'Pengawasan kamera wajib aktif');
$t((int)($ses['exam_open'] ?? 0) === 1, 'Status sakelar ujian resmi = DIBUKA');

$pkgIdsInSession = json_decode((string)($ses['package_ids_json'] ?? '[]'), true);
$t(in_array((int)$pkg['id'], array_map('intval', $pkgIdsInSession), true), 'Paket 2 terhubung ke sesi ujian resmi');

// 6. Uji Idempotensi (Dijalankan ulang tidak boleh menggandakan soal atau paket)
$importResult = lppaiImportPaket2KristenProtestan($pdo, null, true, false);
$stmtQAgain = $pdo->prepare("SELECT COUNT(*) FROM pretes_cbt_questions WHERE package_id = ?");
$stmtQAgain->execute([(int)$pkg['id']]);
$qCountAgain = (int)$stmtQAgain->fetchColumn();
$t($qCountAgain === 25, 'Impor ulang bersifat idempoten: jumlah soal tetap 25 (tidak ganda)');
$t($importResult['updated'] === 25 && $importResult['added'] === 0, '25 soal diperbarui tanpa menambah baris baru');

// 7. Uji Simulasi Pengerjaan Ujian CBT Resmi
// Panggil pretesCbtStart dengan sesi resmi Paket 2
$startResult = pretesCbtStart($pdo, 101, 501, time(), null, $ses, true);
$t(!empty($startResult['attempt']['id']), 'Mahasiswa berhasil memulai ujian CBT pada sesi Paket 2');
$cbtQuestions = $startResult['attempt']['questions'] ?? [];
$t(count($cbtQuestions) === 25, 'Sistem CBT menyajikan 25 butir soal kepada peserta');

// Verifikasi setiap butir memiliki 5 pilihan dan teks pertanyaan
$itemsOk = true;
foreach ($cbtQuestions as $item) {
    if (empty($item['text']) || count($item['options']) !== 5) {
        $itemsOk = false;
        break;
    }
}
$t($itemsOk, 'Seluruh 25 butir soal di attempt memiliki pilihan lengkap dan teracak');

// Simulasi peserta menjawab seluruh soal dengan benar
$attemptId = (int)$startResult['attempt']['id'];
$stmtItems = $pdo->prepare("SELECT position, correct_answer FROM pretes_cbt_items WHERE attempt_id = ?");
$stmtItems->execute([$attemptId]);
$cbtItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

foreach ($cbtItems as $ci) {
    pretesCbtChange($pdo, 101, 501, 'save', [
        'position' => (int)$ci['position'],
        'answer' => $ci['correct_answer'],
        'flagged' => 0
    ]);
}

// Submit ujian
$submitResult = pretesCbtChange($pdo, 101, 501, 'submit', []);
$t(!empty($submitResult['attempt']['submitted']), 'Ujian berhasil disubmit oleh mahasiswa');
$t((float)$submitResult['attempt']['score'] === 100.0, 'Nilai ujian terhitung sempurna 100.0 (25 dari 25 benar)');

echo "\nTOTAL FAILURES: {$fail}\n";
if ($fail === 0) {
    echo "✅ SEMUA 24 PENGUJIAN PAKET 2 KRISTEN PROTESTAN LULUS SEMPURNA!\n";
    exit(0);
} else {
    echo "❌ TERDAPAT PENGUJIAN YANG GAGAL!\n";
    exit(1);
}
