<?php
/** Printable teaching schedule for sharing with tutorial lecturers. */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/tutorial-scheduled-lecturers.php';
header('Cache-Control: private, no-store');
header('Content-Type: text/html; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Gunakan tombol Export Jadwal Dosen / PDF.');
}
$pdo = getDBConnection();
if (isset($_GET['gelombang_id'])) {
    $id = is_string($_GET['gelombang_id'])
        ? filter_var($_GET['gelombang_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if (!$id) {
        http_response_code(400);
        exit('Periode tutorial tidak valid.');
    }
    $stmt = $pdo->prepare('SELECT * FROM master_gelombang WHERE id = ?');
    $stmt->execute([$id]);
    $schedulePeriod = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $schedulePeriod = tutorialActiveSchedulePeriod($pdo);
}
if (!$schedulePeriod) {
    http_response_code(404);
    exit('Periode tutorial tidak ditemukan. Pilih periode melalui halaman hasil plotting.');
}
if (isset($_GET['dosen']) && !is_string($_GET['dosen'])) {
    http_response_code(400);
    exit('Filter dosen tidak valid.');
}
$selectedLecturer = trim($_GET['dosen'] ?? '');
$scheduleRows = tutorialScheduledLecturerClasses($pdo, $schedulePeriod);
$lecturerOptions = array_values(array_unique(array_map(static fn($row) => trim($row['dosen_pengampu']), $scheduleRows)));
sort($lecturerOptions, SORT_NATURAL | SORT_FLAG_CASE);
if ($selectedLecturer !== '') {
    $scheduleRows = array_values(array_filter($scheduleRows, static fn($row) => trim($row['dosen_pengampu']) === $selectedLecturer));
}
$lecturerCount = count(array_unique(array_map(static fn($row) => trim($row['dosen_pengampu']), $scheduleRows)));
require __DIR__ . '/../includes/tutorial-jadwal-dosen-export-view.php';
