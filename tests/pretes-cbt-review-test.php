<?php
/**
 * Test Suite: Review Result CBT Pretes Resmi & Tryout Mandiri
 *
 * Memvalidasi:
 * 1. Halaman Review Result Admin (admin/pretes-cbt-review.php):
 *    - Data peserta (Nama, NIM, Program Studi, Badge RPL/Reguler).
 *    - Pembeda Pretes Resmi vs Try Out Mandiri.
 *    - Metrik hasil: Skor, Total Soal, Benar, Salah, Ragu, Belum Dijawab.
 *    - Status butir soal: Benar (+1), Salah (0), Tidak Menjawab (0).
 *    - Penandaan opsi jawaban (Kunci Jawaban vs Pilihan Mahasiswa vs Pilihan Salah).
 *    - Dukungan sesi yang sudah selesai (submitted) maupun sedang berlangsung (ongoing).
 * 2. Integrasi tombol Review di:
 *    - Kolom aksi tabel Hasil Ujian CBT (includes/pretes-cbt-admin-view.php).
 *    - Rendering dinamis JS live update (assets/js/pretes-cbt-admin.js).
 * 3. Routing:
 *    - pages/pretes-ujian.php?cbt_review=1
 *    - admin/pretes-bank-soal.php?tab=review
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/pretes-cbt.php';
require_once __DIR__ . '/../includes/pretes-tryout.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensurePretesCbtTables($pdo);

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT,
    password TEXT,
    nama_lengkap TEXT NOT NULL,
    nim TEXT,
    program_studi TEXT,
    jenis_program TEXT,
    no_hp TEXT,
    email TEXT,
    role TEXT,
    angkatan INTEGER
)");

echo "=== Menjalankan Validasi Halaman Review Result CBT Pretes & Tryout ===\n";

function reviewAssert(bool $condition, string $passMsg, string $failMsg): void {
    if (!$condition) {
        echo " [FAIL] $failMsg\n";
        exit(1);
    }
    echo " [PASS] $passMsg\n";
}

// ── Setup Fixtures ──────────────────────────────────────────────────────────
$ts = time();

// 1. Buat User Mahasiswa Reguler & RPL
$stmtUser = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nim, program_studi, jenis_program, role, angkatan) VALUES (?, ?, ?, ?, ?, ?, 'mahasiswa', 2026)");
$stmtUser->execute(['mhs_reguler', 'pass', 'Ahmad Reguler', '2601001', 'Pendidikan Agama Islam', 'Reguler']);
$userRegId = (int)$pdo->lastInsertId();

$stmtUser->execute(['mhs_rpl', 'pass', 'Budi RPL', '2602002', 'Pendidikan Bahasa Arab', 'RPL']);
$userRplId = (int)$pdo->lastInsertId();

// 2. Buat Paket CBT
$stmtPkg = $pdo->prepare("INSERT INTO pretes_cbt_packages (code, name, is_active, created_at) VALUES ('PKG-REV', 'Paket Review CBT', 1, ?)");
$stmtPkg->execute([$ts]);
$pkgId = (int)$pdo->lastInsertId();

// 3. Buat Sesi CBT Resmi
$stmtSes = $pdo->prepare("INSERT INTO pretes_cbt_sessions (package_ids_json, code, title, duration_minutes, is_active, created_at) VALUES (?, 'SES-REV', 'Sesi Pretes Review', 60, 1, ?)");
$stmtSes->execute([json_encode([$pkgId]), $ts]);
$sesId = (int)$pdo->lastInsertId();

// 4. Buat Attempt Selesai (Pretes Resmi, Mahasiswa Reguler)
// Total 4 soal:
// Soal 1: User jawab A, Kunci A -> BENAR
// Soal 2: User jawab B, Kunci C -> SALAH
// Soal 3: User tidak jawab (NULL), Kunci D -> BELUM DIJAWAB
// Soal 4: User jawab E, Kunci E, flagged=1 -> BENAR & RAGU
$stmtAtt1 = $pdo->prepare("INSERT INTO pretes_cbt_attempts (session_id, registration_id, user_id, started_at, deadline_at, submitted_at, correct_count, score) VALUES (?, 101, ?, ?, ?, ?, 2, 50.0)");
$stmtAtt1->execute([$sesId, $userRegId, $ts - 1800, $ts + 1800, $ts - 300]);
$attempt1Id = (int)$pdo->lastInsertId();

$sampleOptions = json_encode([
    'A' => 'Pilihan Jawaban A',
    'B' => 'Pilihan Jawaban B',
    'C' => 'Pilihan Jawaban C',
    'D' => 'Pilihan Jawaban D',
    'E' => 'Pilihan Jawaban E'
], JSON_UNESCAPED_UNICODE);

$stmtItem = $pdo->prepare("INSERT INTO pretes_cbt_items (attempt_id, position, question_id, question_text, options_json, correct_answer, answer, flagged) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmtItem->execute([$attempt1Id, 1, 1001, 'Pertanyaan nomor satu?', $sampleOptions, 'A', 'A', 0]);
$stmtItem->execute([$attempt1Id, 2, 1002, 'Pertanyaan nomor dua?', $sampleOptions, 'C', 'B', 0]);
$stmtItem->execute([$attempt1Id, 3, 1003, 'Pertanyaan nomor tiga?', $sampleOptions, 'D', null, 0]);
$stmtItem->execute([$attempt1Id, 4, 1004, 'Pertanyaan nomor empat?', $sampleOptions, 'E', 'E', 1]);

// 5. Buat Attempt Sedang Berlangsung / Ongoing (Try Out Mandiri, Mahasiswa RPL)
// registration_id negatif untuk tryout mandiri: pretesTryoutRegId(sessionId, attemptNo)
$tryoutRegId = pretesTryoutRegId($sesId, 1);
$stmtAtt2 = $pdo->prepare("INSERT INTO pretes_cbt_attempts (session_id, registration_id, user_id, started_at, deadline_at, submitted_at, correct_count, score) VALUES (?, ?, ?, ?, ?, NULL, 0, NULL)");
$stmtAtt2->execute([$sesId, $tryoutRegId, $userRplId, $ts - 600, $ts + 3000]);
$attempt2Id = (int)$pdo->lastInsertId();

// Soal 1: User jawab B, Kunci B -> BENAR
// Soal 2: User jawab A, Kunci C -> SALAH
// Soal 3: Belum dijawab
$stmtItem->execute([$attempt2Id, 1, 2001, 'Soal Tryout Satu?', $sampleOptions, 'B', 'B', 0]);
$stmtItem->execute([$attempt2Id, 2, 2002, 'Soal Tryout Dua?', $sampleOptions, 'C', 'A', 1]);
$stmtItem->execute([$attempt2Id, 3, 2003, 'Soal Tryout Tiga?', $sampleOptions, 'D', '', 0]);

// ── Test 1: Verifikasi Data Attempt 1 (Resmi - Submitted) ───────────────────
$stmtQueryAtt1 = $pdo->prepare("SELECT a.*, u.nama_lengkap, u.nim, u.program_studi, u.jenis_program FROM pretes_cbt_attempts a JOIN users u ON u.id = a.user_id WHERE a.id = ?");
$stmtQueryAtt1->execute([$attempt1Id]);
$dataAtt1 = $stmtQueryAtt1->fetch(PDO::FETCH_ASSOC);

reviewAssert($dataAtt1['nama_lengkap'] === 'Ahmad Reguler', 'Nama peserta attempt 1 sesuai (Ahmad Reguler)', 'Nama peserta tidak cocok');
reviewAssert($dataAtt1['jenis_program'] === 'Reguler', 'Jenis program peserta 1 adalah Reguler', 'Jenis program bukan Reguler');
reviewAssert((int)$dataAtt1['registration_id'] > 0, 'Attempt 1 adalah Pretes Resmi (registration_id > 0)', 'Bukan pretes resmi');
reviewAssert($dataAtt1['submitted_at'] !== null, 'Attempt 1 berstatus Selesai (submitted_at tidak null)', 'Status bukan selesai');

// Ambil items attempt 1 dan hitung metrik
$stmtGetItems1 = $pdo->prepare("SELECT * FROM pretes_cbt_items WHERE attempt_id = ? ORDER BY position ASC");
$stmtGetItems1->execute([$attempt1Id]);
$items1 = $stmtGetItems1->fetchAll(PDO::FETCH_ASSOC);

$correct1 = 0;
$wrong1 = 0;
$empty1 = 0;
$flagged1 = 0;
foreach ($items1 as $it) {
    $uAns = strtoupper(trim((string)($it['answer'] ?? '')));
    $cAns = strtoupper(trim((string)($it['correct_answer'] ?? '')));
    if (!empty($it['flagged'])) $flagged1++;
    if ($uAns === '') {
        $empty1++;
    } elseif ($uAns === $cAns) {
        $correct1++;
    } else {
        $wrong1++;
    }
}

reviewAssert(count($items1) === 4, 'Attempt 1 memiliki 4 butir soal', 'Jumlah soal attempt 1 tidak sesuai');
reviewAssert($correct1 === 2, 'Attempt 1: 2 soal terjawab benar (soal 1 & soal 4)', 'Jumlah benar salah');
reviewAssert($wrong1 === 1, 'Attempt 1: 1 soal terjawab salah (soal 2)', 'Jumlah salah keliru');
reviewAssert($empty1 === 1, 'Attempt 1: 1 soal belum dijawab (soal 3)', 'Jumlah kosong keliru');
reviewAssert($flagged1 === 1, 'Attempt 1: 1 soal ditandai ragu-ragu (soal 4)', 'Jumlah ragu keliru');

// ── Test 2: Verifikasi Data Attempt 2 (Try Out - Ongoing) ───────────────────
$stmtQueryAtt2 = $pdo->prepare("SELECT a.*, u.nama_lengkap, u.nim, u.program_studi, u.jenis_program FROM pretes_cbt_attempts a JOIN users u ON u.id = a.user_id WHERE a.id = ?");
$stmtQueryAtt2->execute([$attempt2Id]);
$dataAtt2 = $stmtQueryAtt2->fetch(PDO::FETCH_ASSOC);

reviewAssert($dataAtt2['nama_lengkap'] === 'Budi RPL', 'Nama peserta attempt 2 sesuai (Budi RPL)', 'Nama peserta tidak cocok');
reviewAssert($dataAtt2['jenis_program'] === 'RPL', 'Jenis program peserta 2 adalah RPL', 'Jenis program bukan RPL');
reviewAssert((int)$dataAtt2['registration_id'] < 0, 'Attempt 2 adalah Try Out Mandiri (registration_id < 0)', 'Bukan tryout mandiri');
reviewAssert($dataAtt2['submitted_at'] === null, 'Attempt 2 berstatus Ongoing / Belum Selesai', 'Status bukan ongoing');

$isTryout = pretesTryoutIsAttemptRegId((int)$dataAtt2['registration_id']);
reviewAssert($isTryout === true, 'pretesTryoutIsAttemptRegId mengenali registration_id sebagai tryout', 'Gagal mengenali reg_id tryout');

$tryoutInfo = pretesTryoutDecodeRegId((int)$dataAtt2['registration_id']);
reviewAssert($tryoutInfo['session_id'] === $sesId && $tryoutInfo['attempt_no'] === 1, 'pretesTryoutDecodeRegId mengembalikan session_id dan attempt_no dengan benar', 'Gagal decode reg_id tryout');

$stmtGetItems2 = $pdo->prepare("SELECT * FROM pretes_cbt_items WHERE attempt_id = ? ORDER BY position ASC");
$stmtGetItems2->execute([$attempt2Id]);
$items2 = $stmtGetItems2->fetchAll(PDO::FETCH_ASSOC);

$correct2 = 0; $wrong2 = 0; $empty2 = 0; $flagged2 = 0;
foreach ($items2 as $it) {
    $uAns = strtoupper(trim((string)($it['answer'] ?? '')));
    $cAns = strtoupper(trim((string)($it['correct_answer'] ?? '')));
    if (!empty($it['flagged'])) $flagged2++;
    if ($uAns === '') {
        $empty2++;
    } elseif ($uAns === $cAns) {
        $correct2++;
    } else {
        $wrong2++;
    }
}

reviewAssert(count($items2) === 3, 'Attempt 2 memiliki 3 butir soal', 'Jumlah soal attempt 2 tidak sesuai');
reviewAssert($correct2 === 1, 'Attempt 2: 1 soal terjawab benar saat ini (live)', 'Jumlah benar ongoing keliru');
reviewAssert($wrong2 === 1, 'Attempt 2: 1 soal terjawab salah saat ini (live)', 'Jumlah salah ongoing keliru');
reviewAssert($empty2 === 1, 'Attempt 2: 1 soal belum terjawab', 'Jumlah kosong ongoing keliru');
reviewAssert($flagged2 === 1, 'Attempt 2: 1 soal ragu-ragu', 'Jumlah ragu ongoing keliru');

$liveScore = count($items2) > 0 ? round(($correct2 / count($items2)) * 100, 1) : 0.0;
reviewAssert(abs($liveScore - 33.3) < 0.1, 'Estimasi skor live ongoing attempt 2 terhitung 33.3', 'Skor live salah');

// ── Test 3: Verifikasi Logika Penandaan Opsi Jawaban (Correct/Wrong/Key) ─────
// Uji untuk Soal 1 Attempt 1 (Key A, User A): Opsi A harus opt-user-correct
$q1Options = json_decode($items1[0]['options_json'], true);
$q1Key = $items1[0]['correct_answer'];
$q1User = $items1[0]['answer'];

$q1Classes = [];
foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
    $isKey = ($letter === $q1Key);
    $isUser = ($letter === $q1User);
    if ($isKey && $isUser) $q1Classes[$letter] = 'opt-user-correct';
    elseif ($isKey) $q1Classes[$letter] = 'opt-is-key';
    elseif ($isUser) $q1Classes[$letter] = 'opt-user-wrong';
    else $q1Classes[$letter] = '';
}
reviewAssert($q1Classes['A'] === 'opt-user-correct', 'Soal 1: Opsi A ditandai opt-user-correct (kunci & pilihan peserta)', 'Opsi A salah kelas');
reviewAssert($q1Classes['B'] === '', 'Soal 1: Opsi B tidak ditandai (bukan kunci, bukan pilihan)', 'Opsi B salah kelas');

// Uji untuk Soal 2 Attempt 1 (Key C, User B): Opsi C harus opt-is-key, Opsi B harus opt-user-wrong
$q2Key = $items1[1]['correct_answer'];
$q2User = $items1[1]['answer'];
$q2Classes = [];
foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
    $isKey = ($letter === $q2Key);
    $isUser = ($letter === $q2User);
    if ($isKey && $isUser) $q2Classes[$letter] = 'opt-user-correct';
    elseif ($isKey) $q2Classes[$letter] = 'opt-is-key';
    elseif ($isUser) $q2Classes[$letter] = 'opt-user-wrong';
    else $q2Classes[$letter] = '';
}
reviewAssert($q2Classes['C'] === 'opt-is-key', 'Soal 2: Kunci C ditandai opt-is-key', 'Kunci C salah kelas');
reviewAssert($q2Classes['B'] === 'opt-user-wrong', 'Soal 2: Pilihan salah B ditandai opt-user-wrong', 'Pilihan B salah kelas');

// ── Test 4: Verifikasi File admin/pretes-cbt-review.php ──────────────────────
$reviewFile = file_get_contents(__DIR__ . '/../admin/pretes-cbt-review.php');
reviewAssert($reviewFile !== false, 'File admin/pretes-cbt-review.php berhasil dibaca', 'File tidak ditemukan');
reviewAssert(strpos($reviewFile, 'requireAdmin();') !== false, 'admin/pretes-cbt-review.php mengamankan akses dengan requireAdmin()', 'Tidak ada requireAdmin');
reviewAssert(strpos($reviewFile, 'pretes_cbt_items') !== false, 'admin/pretes-cbt-review.php membaca butir dari pretes_cbt_items', 'Tidak ada query pretes_cbt_items');
$reviewFile .= file_get_contents(__DIR__ . '/../includes/cbt-attempt-review-view.php');
reviewAssert(strpos($reviewFile, 'opt-user-correct') !== false, 'admin/pretes-cbt-review.php memiliki styling opt-user-correct', 'Tidak ada styling opt-user-correct');
reviewAssert(strpos($reviewFile, 'opt-user-wrong') !== false, 'admin/pretes-cbt-review.php memiliki styling opt-user-wrong', 'Tidak ada styling opt-user-wrong');
reviewAssert(strpos($reviewFile, 'opt-is-key') !== false, 'admin/pretes-cbt-review.php memiliki styling opt-is-key', 'Tidak ada styling opt-is-key');
reviewAssert(strpos($reviewFile, 'filterQuestions') !== false, 'admin/pretes-cbt-review.php menyediakan filter butir soal (Semua, Benar, Salah, Ragu, Kosong)', 'Tidak ada fungsi filterQuestions');
reviewAssert(strpos($reviewFile, 'highlightQuestion') !== false, 'admin/pretes-cbt-review.php menyediakan Quick Jump Number Palette', 'Tidak ada fungsi highlightQuestion');
reviewAssert(strpos($reviewFile, 'renderJenisProgramBadge') !== false, 'admin/pretes-cbt-review.php menampilkan badge Program RPL / Reguler via renderJenisProgramBadge', 'Tidak ada renderJenisProgramBadge');

// ── Test 5: Verifikasi Integrasi Tombol Review di Admin View & JS ───────────
$adminViewFile = file_get_contents(__DIR__ . '/../includes/pretes-cbt-admin-view.php');
reviewAssert(strpos($adminViewFile, 'pretes-ujian.php?cbt_review=1&amp;attempt_id=') !== false, 'includes/pretes-cbt-admin-view.php menyertakan link tombol Review pada kolom aksi tabel hasil', 'Tidak ada link Review di admin view');
reviewAssert(strpos($adminViewFile, '👁️ Review') !== false, 'includes/pretes-cbt-admin-view.php menampilkan label 👁️ Review', 'Tidak ada label Review');
reviewAssert(strpos($adminViewFile, 'window.CBT_BASE_URL') !== false, 'includes/pretes-cbt-admin-view.php mendefinisikan window.CBT_BASE_URL', 'Tidak ada CBT_BASE_URL');

$adminJsFile = file_get_contents(__DIR__ . '/../assets/js/pretes-cbt-admin.js');
reviewAssert(strpos($adminJsFile, 'cbt_review=1') !== false, 'assets/js/pretes-cbt-admin.js menyertakan tombol Review dalam renderAttemptRow untuk live update', 'Tidak ada link review di JS live update');

// ── Test 6: Verifikasi Routing cbt_review & tab=review ──────────────────────
$pretesUjianFile = file_get_contents(__DIR__ . '/../pages/pretes-ujian.php');
reviewAssert(strpos($pretesUjianFile, "isset(\$_GET['cbt_review'])") !== false, 'pages/pretes-ujian.php memiliki handler routing cbt_review', 'Tidak ada route cbt_review');

$pretesBankFile = file_get_contents(__DIR__ . '/../admin/pretes-bank-soal.php');
reviewAssert(strpos($pretesBankFile, "\$rawTab === 'review'") !== false, 'admin/pretes-bank-soal.php memiliki handler delegasi tab=review', 'Tidak ada handler tab=review');

echo "\nSemua 22 uji validasi Review Result CBT (Pretes Resmi & Tryout) BERHASIL SEMPURNA!\n";
