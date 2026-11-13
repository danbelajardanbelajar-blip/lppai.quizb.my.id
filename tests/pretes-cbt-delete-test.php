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

// 4. Test View Rendering for results tab
$viewFile = __DIR__ . '/../includes/pretes-cbt-admin-view.php';
$viewContent = file_get_contents($viewFile);

delAssert(strpos($viewContent, 'name="action" value="delete_attempt"') !== false, 'Form action delete_attempt tersedia di view.', $errors);
delAssert(strpos($viewContent, 'name="action" value="delete_all_attempts"') !== false, 'Form action delete_all_attempts tersedia di view.', $errors);
delAssert(strpos($viewContent, '<th>Aksi</th>') !== false, 'Kolom th Aksi tersedia di tabel hasil CBT.', $errors);
delAssert(strpos($viewContent, 'bank-button danger-sm') !== false, 'Tombol bank-button danger-sm terpasang pada tiap baris.', $errors);
delAssert(strpos($viewContent, 'Hapus Semua Hasil') !== false, 'Tombol Hapus Semua Hasil terpasang pada header tabel.', $errors);
delAssert(strpos($viewContent, 'confirm(') !== false, 'Konfirmasi alert JavaScript terpasang sebelum submit penghapusan.', $errors);

// 5. Test Controller Handling in admin/pretes-bank-soal.php
$controllerFile = __DIR__ . '/../admin/pretes-bank-soal.php';
$controllerContent = file_get_contents($controllerFile);
delAssert(strpos($controllerContent, "\$action === 'delete_attempt'") !== false, 'Controller menangani action delete_attempt.', $errors);
delAssert(strpos($controllerContent, "\$action === 'delete_all_attempts'") !== false, 'Controller menangani action delete_all_attempts.', $errors);
delAssert(strpos($controllerContent, "pretesCbtDeleteAttempt(") !== false, 'Controller memanggil pretesCbtDeleteAttempt.', $errors);
delAssert(strpos($controllerContent, "pretesCbtDeleteAllAttempts(") !== false, 'Controller memanggil pretesCbtDeleteAllAttempts.', $errors);

// 6. Test CSS classes in assets/css/pretes-cbt-admin.css
$cssFile = __DIR__ . '/../assets/css/pretes-cbt-admin.css';
$cssContent = file_get_contents($cssFile);
delAssert(strpos($cssContent, '.bank-button.danger') !== false, 'CSS .bank-button.danger terdefinisi.', $errors);
delAssert(strpos($cssContent, '.bank-button.danger-sm') !== false, 'CSS .bank-button.danger-sm terdefinisi.', $errors);

if (!empty($errors)) {
    echo "\n❌ GAGAL: " . count($errors) . " asersi gagal.\n";
    exit(1);
}

echo "\n✅ Seluruh pengujian Menu Delete Hasil CBT BERHASIL 100%!\n";
exit(0);
