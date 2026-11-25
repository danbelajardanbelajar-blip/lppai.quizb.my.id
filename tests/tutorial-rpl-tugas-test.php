<?php
/**
 * Test: Halaman & Helper Kelola Tugas Tutorial RPL (admin/tutorial-rpl-tugas.php)
 */
require_once __DIR__ . '/../includes/tutorial-rpl-tasks.php';

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

ensureTutorialRplTasksTables($pdo);

// 1. Daftar awal sama dengan komponen bawaan Pretes RPL
$rows = tutorialRplTaskRows($pdo, true);
$defaultCount=count(pretesDefaultComponentRows());
assert(count($rows) === $defaultCount, 'Daftar awal sama dengan Pretes RPL');
assert(array_column($rows,'task_key')===array_column(pretesDefaultComponentRows(),'comp_key'),'Kunci tugas awal mengikuti sumber RPL');
assert(tutorialRplPassMode($pdo) === 'flexible_3_options', 'Default mode kelulusan adalah flexible_3_options');

// 2. Simpan mode kelulusan
tutorialRplSettingSet($pdo, 'rpl_tutorial_pass_mode', 'cbt_only');
assert(tutorialRplPassMode($pdo) === 'cbt_only', 'Mode kelulusan tersimpan cbt_only');

// 3. Tambah tugas baru
$created = tutorialRplTaskSave($pdo, [
    'label' => 'Praktik Adzan & Iqamah',
    'bentuk' => 'Video Rekaman',
    'kategori' => 'Ibadah Praktik',
    'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'video_title' => 'Panduan Adzan',
    'reading_title' => 'Lafadz Adzan',
    'reading_text' => 'Allahu Akbar...',
]);
assert((int)$created['id'] > 0 && $created['label'] === 'Praktik Adzan & Iqamah', 'Tambah tugas baru berhasil');

// 4. Edit tugas
$updated = tutorialRplTaskSave($pdo, [
    'label' => 'Praktik Adzan & Iqamah (Revisi)',
    'bentuk' => 'Lisan / Praktik',
    'kategori' => 'Ubudiyah',
], (int)$created['id']);
assert($updated['label'] === 'Praktik Adzan & Iqamah (Revisi)', 'Edit tugas berhasil');

// 5. Pindah urutan naik
$beforeOrder = array_column(tutorialRplTaskRows($pdo, true), 'id');
$lastId = (int)end($beforeOrder);
tutorialRplTaskMove($pdo, $lastId, -1);
$afterOrder = array_column(tutorialRplTaskRows($pdo, true), 'id');
assert((int)$afterOrder[count($afterOrder) - 2] === $lastId, 'Urutan tugas naik 1 posisi');

// 6. Nonaktifkan & aktifkan kembali
tutorialRplTaskSetActive($pdo, $lastId, false);
$activeOnly = tutorialRplTaskRows($pdo, false);
assert(count($activeOnly) === $defaultCount, 'Tugas nonaktif tidak muncul di daftar aktif');
tutorialRplTaskSetActive($pdo, $lastId, true);
assert(count(tutorialRplTaskRows($pdo, false)) === $defaultCount + 1, 'Tugas aktif kembali');

// 7. Hapus tugas
tutorialRplTaskDelete($pdo, $lastId);
assert(count(tutorialRplTaskRows($pdo, true)) === $defaultCount, 'Hapus tugas berhasil');

// 8. Verifikasi sidebar & halaman admin tersedia
$sidebar = file_get_contents(__DIR__ . '/../includes/sidebar.php');
assert(str_contains($sidebar, 'tutorial-rpl-tugas.php'), 'Sidebar memuat link tutorial-rpl-tugas.php');
$adminPage = file_get_contents(__DIR__ . '/../admin/tutorial-rpl-tugas.php');
assert(str_contains($adminPage, 'Kelola Tugas Tutorial RPL'), 'Halaman admin/tutorial-rpl-tugas.php tersedia');

echo "=== ALL TUTORIAL RPL TUGAS TESTS PASSED (100%) ===\n";
exit(0);
