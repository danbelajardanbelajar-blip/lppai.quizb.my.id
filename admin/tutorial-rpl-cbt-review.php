<?php
define('PAGE_TITLE', 'Review Lembar Jawaban CBT Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/user-helper.php';
require_once __DIR__ . '/../includes/tutorial-rpl-cbt-review.php';

$attemptId = (int)filter_var($_GET['attempt_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$backUrl = BASE_URL . '/admin/tutorial-rpl-cbt.php?' . http_build_query(tutorialRplCbtReviewContext($_GET));
$attempt = null; $items = []; $sessionInfo = null; $reviewError = '';
try {
    $data = tutorialRplCbtReviewData(getDBConnection(),$attemptId);
    $attempt = $data['attempt']; $items = $data['items'];
    if (!$attempt) http_response_code(404);
    if ($attempt && ($attempt['session_title'] || $attempt['nama_kelompok'])) {
        $sessionInfo = ['title'=>implode(' · ',array_filter([$attempt['session_title'],$attempt['nama_kelompok']]))];
    }
} catch (Throwable $e) {
    error_log('Tutorial RPL CBT review: ' . $e->getMessage());
    http_response_code(503);
    $reviewError = 'Data review belum dapat dimuat. Silakan coba kembali atau hubungi pengelola server.';
}
$reviewJenisKind = 'resmi';
$reviewJenisLabel = 'Tutorial RPL' . (!empty($attempt['package_name']) ? ' · ' . $attempt['package_name'] : '');
$reviewPassingGrade = (float)($attempt['passing_grade'] ?? 70);
include __DIR__ . '/../includes/cbt-attempt-review-view.php';
