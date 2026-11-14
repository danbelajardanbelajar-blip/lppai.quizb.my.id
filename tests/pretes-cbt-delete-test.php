<?php
/**
 * Test suite for CBT Results Deletion (per-attempt and delete all)
 * in pretes-ujian.php?cbt_bank=1&tab=results
 */

require_once __DIR__ . '/../includes/pretes-cbt.php';

$errors = [];
function delAssert(bool $cond, string $msg, array &$errors): void {
    if (!$cond) {
        $errors[] = $msg;
        echo " [FAIL] $msg\n";
    } else {
        echo " [PASS] $msg\n";
    }
}

echo "=== Menjalankan Validasi Menu Delete Hasil CBT (tab=results) ===\n";

// 1. In-memory SQLite Database Setup
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensurePretesCbtTables($pdo);

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nama_lengkap TEXT NOT NULL,
    nim TEXT
)");

// Insert test users
$pdo->exec("INSERT INTO users (id, nama_lengkap, nim) VALUES (1, 'Fulan bin Fulan', '2601101001')");
$pdo->exec("INSERT INTO users (id, nama_lengkap, nim) VALUES (2, 'Siti Aisyah', '2601101002')");

// Start 2 attempts
$res1 = pretesCbtStart($pdo, 1, 101, 1000);
$res2 = pretesCbtStart($pdo, 2, 102, 1050);

$att1 = $res1['attempt'];
$att2 = $res2['attempt'];

delAssert($att1 !== null && $att2 !== null, 'Dua sesi CBT berhasil diinisialisasi.', $errors);
delAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_attempts')->fetchColumn() === 2, 'Terdapat 2 attempt di database.', $errors);
delAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_items')->fetchColumn() === 50, 'Terdapat 50 item pertanyaan (25 x 2).', $errors);

// 2. Test pretesCbtDeleteAttempt
$deleted1 = pretesCbtDeleteAttempt($pdo, (int)$att1['id']);
delAssert($deleted1 === true, 'pretesCbtDeleteAttempt berhasil menghapus attempt 1.', $errors);
delAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_attempts WHERE id = ' . (int)$att1['id'])->fetchColumn() === 0, 'Attempt 1 terhapus dari pretes_cbt_attempts.', $errors);
delAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_items WHERE attempt_id = ' . (int)$att1['id'])->fetchColumn() === 0, 'Pertanyaan attempt 1 terhapus dari pretes_cbt_items.', $errors);
delAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_attempts WHERE id = ' . (int)$att2['id'])->fetchColumn() === 1, 'Attempt 2 masih utuh (terisolasi).', $errors);

// User 1 can now restart CBT
$stateUser1 = pretesCbtState($pdo, 1, 101, 1200);
delAssert($stateUser1['attempt'] === null, 'User 1 dalam status unstarted dan dapat mengulang ujian.', $errors);

// 3. Test pretesCbtDeleteAllAttempts
$deletedAll = pretesCbtDeleteAllAttempts($pdo);
delAssert($deletedAll === 1, 'pretesCbtDeleteAllAttempts berhasil menghapus sisa 1 attempt.', $errors);
delAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_attempts')->fetchColumn() === 0, 'Seluruh pretes_cbt_attempts kini 0.', $errors);
delAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_items')->fetchColumn() === 0, 'Seluruh pretes_cbt_items kini 0.', $errors);

// 4. Bulk deletion must affect only selected packages/sessions and remain atomic.
$now = time();
$insertPackage = $pdo->prepare('INSERT INTO pretes_cbt_packages (id, code, name, duration_minutes, question_count, is_active, created_at) VALUES (?, ?, ?, 30, 1, ?, ?)');
foreach ([[2, 'BULK-PKG-2', 'Paket pilihan 2', 1], [3, 'BULK-PKG-3', 'Paket pilihan 3', 0], [4, 'BULK-PKG-4', 'Paket tetap', 0]] as [$id, $code, $name, $active]) {
    $insertPackage->execute([$id, $code, $name, $active, $now]);
}
$insertQuestion = $pdo->prepare('INSERT INTO pretes_cbt_questions (code, package_id, category, question_text, options_json, correct_answer) VALUES (?, ?, ?, ?, ?, ?)');
$insertQuestion->execute(['BULK-Q-2', 2, 'Umum', 'Soal paket 2', '{}', 'A']);
$insertQuestion->execute(['BULK-Q-3', 3, 'Umum', 'Soal paket 3', '{}', 'A']);
$pdo->exec('UPDATE pretes_cbt_packages SET is_active = 0 WHERE id = 1');
$deletedPackages = pretesCbtDeletePackages($pdo, [2, 3, 2, 0]);
delAssert($deletedPackages === ['packages' => 2, 'questions' => 2], 'Hapus paket terpilih mengembalikan jumlah paket dan soal yang benar.', $errors);
delAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_packages WHERE id IN (2, 3)')->fetchColumn() === 0, 'Kedua paket terpilih terhapus.', $errors);
delAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_packages WHERE id = 4')->fetchColumn() === 1, 'Paket yang tidak dipilih tetap utuh.', $errors);
delAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_questions WHERE package_id IN (2, 3)')->fetchColumn() === 0, 'Seluruh soal milik paket terpilih ikut terhapus.', $errors);
delAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_questions WHERE package_id = 4')->fetchColumn() === 0, 'Soal paket yang tidak dipilih tidak tersentuh.', $errors);
delAssert((int)$pdo->query('SELECT is_active FROM pretes_cbt_packages WHERE id = 1')->fetchColumn() === 1, 'Paket aktif dipilih kembali jika semua paket aktif terhapus.', $errors);

$insertSession = $pdo->prepare('INSERT INTO pretes_cbt_sessions (id, code, title, is_active, created_at) VALUES (?, ?, ?, ?, ?)');
foreach ([[2, 'BULK-SES-2', 'Sesi pilihan 2', 1], [3, 'BULK-SES-3', 'Sesi pilihan 3', 0], [4, 'BULK-SES-4', 'Sesi tetap', 0]] as [$id, $code, $title, $active]) {
    $insertSession->execute([$id, $code, $title, $active, $now]);
}
$pdo->exec('UPDATE pretes_cbt_sessions SET is_active = 0 WHERE id = 1');
$deletedSessions = pretesCbtDeleteSessions($pdo, [2, 3, 2, -1]);
delAssert($deletedSessions === 2, 'Hapus sesi terpilih mengembalikan jumlah sesi yang benar.', $errors);
delAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_sessions WHERE id IN (2, 3)')->fetchColumn() === 0, 'Kedua sesi terpilih terhapus.', $errors);
delAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_sessions WHERE id = 4')->fetchColumn() === 1, 'Sesi yang tidak dipilih tetap utuh.', $errors);
delAssert((int)$pdo->query('SELECT is_active FROM pretes_cbt_sessions WHERE id = 1')->fetchColumn() === 1, 'Sesi aktif dipilih kembali jika sesi aktif terhapus.', $errors);
delAssert(pretesCbtDeleteSessions($pdo, []) === 0, 'Permintaan tanpa pilihan tidak menghapus sesi apa pun.', $errors);
delAssert(pretesCbtDeletePackage($pdo, 4) === true, 'Penghapusan satu paket lama tetap kompatibel dengan helper massal.', $errors);
delAssert(pretesCbtDeleteSession($pdo, 4) === true, 'Penghapusan satu sesi lama tetap kompatibel dengan helper massal.', $errors);

// 5. Test view/controller wiring for bulk selection and existing results deletion.
$viewFile = __DIR__ . '/../includes/pretes-cbt-admin-view.php';
$viewContent = file_get_contents($viewFile);

delAssert(strpos($viewContent, 'name="action" value="delete_attempt"') !== false, 'Form action delete_attempt tersedia di view.', $errors);
delAssert(strpos($viewContent, 'name="action" value="delete_all_attempts"') !== false, 'Form action delete_all_attempts tersedia di view.', $errors);
delAssert(strpos($viewContent, '<th>Aksi</th>') !== false, 'Kolom th Aksi tersedia di tabel hasil CBT.', $errors);
delAssert(strpos($viewContent, 'bank-button danger-sm') !== false, 'Tombol bank-button danger-sm terpasang pada tiap baris.', $errors);
delAssert(strpos($viewContent, 'Hapus Semua Hasil') !== false, 'Tombol Hapus Semua Hasil terpasang pada header tabel.', $errors);
delAssert(strpos($viewContent, 'confirm(') !== false, 'Konfirmasi alert JavaScript terpasang sebelum submit penghapusan.', $errors);
delAssert(strpos($viewContent, 'name="package_ids[]"') !== false && strpos($viewContent, 'data-bulk-select-item="packages"') !== false, 'Checkbox paket terhubung ke form hapus massal.', $errors);
delAssert(strpos($viewContent, 'name="session_ids[]"') !== false && strpos($viewContent, 'data-bulk-select-item="sessions"') !== false, 'Checkbox sesi terhubung ke form hapus massal.', $errors);
delAssert(strpos($viewContent, 'data-bulk-select-all="packages"') !== false && strpos($viewContent, 'data-bulk-select-all="sessions"') !== false, 'Kontrol pilih semua tersedia pada kedua tab.', $errors);

// 5. Test Controller Handling in admin/pretes-bank-soal.php
$controllerFile = __DIR__ . '/../admin/pretes-bank-soal.php';
$controllerContent = file_get_contents($controllerFile);
delAssert(strpos($controllerContent, "\$action === 'delete_attempt'") !== false, 'Controller menangani action delete_attempt.', $errors);
delAssert(strpos($controllerContent, "\$action === 'delete_all_attempts'") !== false, 'Controller menangani action delete_all_attempts.', $errors);
delAssert(strpos($controllerContent, "pretesCbtDeleteAttempt(") !== false, 'Controller memanggil pretesCbtDeleteAttempt.', $errors);
delAssert(strpos($controllerContent, "pretesCbtDeleteAllAttempts(") !== false, 'Controller memanggil pretesCbtDeleteAllAttempts.', $errors);
delAssert(strpos($controllerContent, "\$action === 'delete_packages'") !== false && strpos($controllerContent, 'pretesCbtDeletePackages(') !== false, 'Controller menangani penghapusan paket terpilih.', $errors);
delAssert(strpos($controllerContent, "\$action === 'delete_sessions'") !== false && strpos($controllerContent, 'pretesCbtDeleteSessions(') !== false, 'Controller menangani penghapusan sesi terpilih.', $errors);

$adminJs = file_get_contents(__DIR__ . '/../assets/js/pretes-cbt-admin.js');
delAssert(strpos($adminJs, 'refreshBulkSelection') !== false && strpos($adminJs, 'data-bulk-select-all') !== false, 'JavaScript memperbarui hitungan dan pilih semua pada dua daftar.', $errors);
delAssert(strpos($adminJs, 'window.confirm(message)') !== false, 'Penghapusan massal meminta konfirmasi sebelum dikirim.', $errors);

// 6. Test CSS classes in assets/css/pretes-cbt-admin.css
$cssFile = __DIR__ . '/../assets/css/pretes-cbt-admin.css';
$cssContent = file_get_contents($cssFile);
delAssert(strpos($cssContent, '.bank-button.danger') !== false, 'CSS .bank-button.danger terdefinisi.', $errors);
delAssert(strpos($cssContent, '.bank-button.danger-sm') !== false, 'CSS .bank-button.danger-sm terdefinisi.', $errors);
delAssert(strpos($cssContent, '[data-bulk-toolbar][hidden]{display:none!important}') !== false, 'Toolbar terpilih benar-benar tersembunyi sebelum ada checkbox yang dicentang.', $errors);

if (!empty($errors)) {
    echo "\n❌ GAGAL: " . count($errors) . " asersi gagal.\n";
    exit(1);
}

echo "\n✅ Seluruh pengujian Menu Delete Hasil CBT BERHASIL 100%!\n";
exit(0);
