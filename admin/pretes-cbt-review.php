<?php
/**
 * LPPAI Corner - Admin: Review Lembar Jawaban CBT Peserta
 * Menampilkan rincian butir soal, jawaban peserta, kunci jawaban, dan status benar/salah
 * baik untuk Pretes Resmi maupun Try Out Mandiri, baik sesi yang sudah selesai maupun sedang berlangsung.
 */

require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/pretes-cbt.php';
require_once __DIR__ . '/../includes/pretes-tryout.php';
require_once __DIR__ . '/../includes/user-helper.php';
require_once __DIR__ . '/../includes/pretes-program-scope.php';

try {
    $pretesProgram = pretesAdminResolveProgram(defined('PRETES_ADMIN_PROGRAM') ? PRETES_ADMIN_PROGRAM : null);
} catch (DomainException $e) {
    http_response_code(403);
    exit($e->getMessage());
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    exit($e->getMessage());
}
$pretesProgramLabel = pretesAdminProgramLabel($pretesProgram);
$userWhere = pretesUserProgramWhere($pretesProgram, 'u');

define('PAGE_TITLE', 'Review Lembar Jawaban CBT Peserta' . ($pretesProgramLabel !== '' ? ' ' . $pretesProgramLabel : ''));

$pdo = getDBConnection();
ensurePretesCbtTables($pdo);

$attemptId = (int)($_GET['attempt_id'] ?? 0);
$filterParam = isset($_GET['result_filter']) && in_array($_GET['result_filter'], ['resmi', 'tryout', 'all'], true)
    ? '&result_filter=' . urlencode($_GET['result_filter'])
    : '';
$backUrl = pretesAdminRoute('cbt_bank', $pretesProgram, ['tab' => 'results']) . $filterParam;

// Ambil data attempt dan data peserta
$stmtAttempt = $pdo->prepare("
    SELECT a.*, 
           u.nama_lengkap, u.nim, u.program_studi, u.jenis_program, u.no_hp, u.email
    FROM pretes_cbt_attempts a
    JOIN users u ON u.id = a.user_id
    WHERE a.id = ? AND {$userWhere['sql']}
    LIMIT 1
");
$stmtAttempt->execute(array_merge([$attemptId], $userWhere['params']));
$attempt = $stmtAttempt->fetch(PDO::FETCH_ASSOC);

$items = [];
$sessionInfo = null;
$packageInfo = null;

if ($attempt) {
    // Ambil info sesi CBT jika ada
    if (!empty($attempt['session_id'])) {
        $sessionInfo = pretesCbtGetSession($pdo, (int)$attempt['session_id']);
    }

    // Ambil butir soal dan jawaban peserta
    $stmtItems = $pdo->prepare("
        SELECT position, question_id, question_text, options_json, correct_answer, answer, flagged
        FROM pretes_cbt_items
        WHERE attempt_id = ?
        ORDER BY position ASC
    ");
    $stmtItems->execute([$attemptId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
}

$tryoutCache = [];
$jenisInfo = $attempt ? pretesTryoutAttemptLabel($pdo, $attempt, $tryoutCache) : [];
$reviewJenisKind = $jenisInfo['kind'] ?? 'resmi';
$reviewJenisLabel = $jenisInfo['label'] ?? 'Resmi';
$reviewPassingGrade = 80;
include __DIR__ . '/../includes/cbt-attempt-review-view.php';
