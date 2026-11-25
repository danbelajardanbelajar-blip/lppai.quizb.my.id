<?php
/** Read-only adapter: snapshots come exclusively from the Tutorial RPL attempt/item tables. */
function tutorialRplCbtReviewData(PDO $pdo, int $attemptId): array
{
    $stmt = $pdo->prepare('SELECT a.*,u.nama_lengkap,u.nim,u.program_studi,u.jenis_program,
        c.nama_kelompok,s.title AS session_title,s.passing_grade,p.name AS package_name
        FROM tutorial_rpl_cbt_attempts a JOIN users u ON u.id=a.user_id
        LEFT JOIN tutorial_rpl_classes c ON c.id=a.class_id
        LEFT JOIN tutorial_rpl_cbt_sessions s ON s.id=a.session_id
        LEFT JOIN tutorial_rpl_cbt_packages p ON p.id=a.package_id
        WHERE a.id=? LIMIT 1');
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $items = [];
    if ($attempt) {
        $stmt = $pdo->prepare('SELECT position,question_id,question_text,options_json,correct_answer,answer,flagged FROM tutorial_rpl_cbt_items WHERE attempt_id=? ORDER BY position');
        $stmt->execute([$attemptId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return ['attempt'=>$attempt,'items'=>$items];
}

function tutorialRplCbtReviewContext(array $query): array
{
    $context = ['tab'=>'results'];
    foreach (['result_filter','status_filter','pass_filter','q','group_filter','page','per_page'] as $key) {
        if (isset($query[$key]) && is_string($query[$key])) $context[$key] = mb_substr($query[$key],0,100);
    }
    return $context;
}
