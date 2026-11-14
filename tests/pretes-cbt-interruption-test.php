<?php
require __DIR__ . '/../includes/pretes-cbt.php';
function checkPause(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejectPause(callable $operation): void {
    try { $operation(); } catch (RuntimeException | InvalidArgumentException $e) { return; }
    throw new RuntimeException('Invalid operation accepted');
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensurePretesCbtTables($pdo);
ensurePretesCbtTables($pdo);
$pdo->exec('UPDATE pretes_cbt_sessions SET shuffle_on_violation = 0 WHERE id = 1');
$initial = pretesCbtStart($pdo, 7, 42, 1000)['attempt'];
pretesCbtChange($pdo, 7, 42, 'save', ['position' => 1, 'answer' => 'B'], 1010);
$before = $pdo->query('SELECT * FROM pretes_cbt_items ORDER BY position')->fetchAll(PDO::FETCH_ASSOC);
$pause = ['event_id' => 'event_first', 'reason' => 'page_hidden', 'position' => 2, 'revision' => 0,
    'pending' => [['position' => 3, 'answer' => 'E', 'flagged' => true], ['position' => 2, 'answer' => 'A']]];
$paused = pretesCbtChange($pdo, 7, 42, 'interrupt', $pause, 1020)['attempt'];
checkPause($paused['paused'] && $paused['revision'] === 1 && $paused['remaining_seconds'] === 1780, 'Server pauses clock');
checkPause($paused['questions'][0]['answer'] === 'B' && $paused['questions'][2]['answer'] === 'E', 'Other saved and pending answers survive');
checkPause($paused['questions'][2]['flagged'], 'Pending flag survives');
$after = $pdo->query('SELECT * FROM pretes_cbt_items ORDER BY position')->fetchAll(PDO::FETCH_ASSOC);
$moved = array_values(array_filter($after, fn($row) => $row['question_id'] === $before[1]['question_id']))[0];
checkPause((int)$moved['position'] !== 2 && $moved['answer'] === null, 'Only open question cleared and moved');
checkPause($moved['options_json'] !== $before[1]['options_json'], 'Options actually shuffled');
checkPause(json_decode($moved['options_json'], true)[$moved['correct_answer']] === json_decode($before[1]['options_json'], true)[$before[1]['correct_answer']], 'Correct answer remapped');
checkPause($paused['questions'][1]['text'] !== $before[1]['question_text'], 'Next visible question changes');
checkPause(count(array_unique(array_column($after, 'question_id'))) === 25, 'No duplicates or lost questions');
$again = pretesCbtChange($pdo, 7, 42, 'interrupt', $pause, 2000)['attempt'];
checkPause($again['questions'] === $paused['questions'] && $again['revision'] === 1, 'Retry is idempotent');
$reload = pretesCbtStart($pdo, 7, 42, 5000)['attempt'];
checkPause($reload['paused'] && !$reload['submitted'] && $reload['remaining_seconds'] === 1780, 'Reload beyond old deadline stays paused');
rejectPause(fn() => pretesCbtChange($pdo, 7, 42, 'save', ['position' => 1, 'answer' => 'C', 'revision' => 1], 5000));
rejectPause(fn() => pretesCbtChange($pdo, 7, 42, 'submit', ['revision' => 1], 5000));
rejectPause(fn() => pretesCbtChange($pdo, 8, 42, 'resume', [], 5000));
$resumed = pretesCbtChange($pdo, 7, 42, 'resume', [], 5010)['attempt'];
checkPause(!$resumed['paused'] && $resumed['remaining_seconds'] === 1780, 'Server credits exactly popup duration');
$retryResume = pretesCbtChange($pdo, 7, 42, 'resume', [], 5020)['attempt'];
checkPause($retryResume['remaining_seconds'] === 1770, 'Resume retries cannot extend time twice');
$oldPause = pretesCbtChange($pdo, 7, 42, 'interrupt', $pause, 5020)['attempt'];
checkPause(!$oldPause['paused'] && $oldPause['revision'] === 1, 'Delayed duplicate pause cannot penalize twice');
rejectPause(fn() => pretesCbtChange($pdo, 7, 42, 'save', ['position' => 2, 'answer' => 'A', 'revision' => 0], 5020));
pretesCbtChange($pdo, 7, 42, 'save', ['position' => 2, 'answer' => 'D', 'revision' => 1], 5020);
checkPause(pretesCbtState($pdo, 7, 42, 5020)['attempt']['questions'][1]['answer'] === 'D', 'New revision saves normally');
checkPause(strpos(json_encode($paused), 'correct_answer') === false, 'No answer keys exposed');
$second = array_replace($pause, ['event_id' => 'event_second', 'revision' => 1]);
pretesCbtChange($pdo, 7, 42, 'interrupt', $second, 5030);
checkPause((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_interruption_events')->fetchColumn() === 2, 'Separate violations audited');
pretesCbtDeleteAttempt($pdo, $initial['id']);
checkPause((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_interruptions')->fetchColumn() === 0, 'Deleting attempt cleans pause state');
checkPause((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_interruption_events')->fetchColumn() === 0, 'Deleting attempt cleans audit');
// Expired exams cannot be resurrected through a late pause.
pretesCbtStart($pdo, 8, 43, 1000);
checkPause(pretesCbtChange($pdo, 8, 43, 'interrupt', $pause, 2801)['attempt']['submitted'], 'Late pause cannot revive expired exam');
$last = pretesCbtStart($pdo, 9, 44, 6000)['attempt'];
for ($i = 1; $i < 25; $i++) pretesCbtChange($pdo, 9, 44, 'save', ['position' => $i, 'answer' => 'A'], 6010);
$lastPause = array_replace($pause, ['position' => 25, 'event_id' => 'last_question', 'pending' => []]);
$lastPaused = pretesCbtChange($pdo, 9, 44, 'interrupt', $lastPause, 6020)['attempt'];
checkPause($lastPaused['questions'][24]['text'] === $last['questions'][24]['text'], 'Single remaining question stays available');
checkPause($lastPaused['questions'][24]['options'] !== $last['questions'][24]['options'], 'Single remaining question still reshuffles choices');
checkPause($lastPaused['answered'] === 24, 'Last question pause preserves all other answers');
echo "PASS: server pause/resume, refresh, random relocation, answer integrity, revisions, replay safety, ownership, audit, and expiry.\n";
