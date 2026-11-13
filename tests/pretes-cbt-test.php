<?php
require __DIR__ . '/../includes/pretes-cbt.php';
function cbtAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function cbtReject(callable $fn): void {
    try { $fn(); } catch (InvalidArgumentException | RuntimeException $e) { return; }
    throw new RuntimeException('Invalid operation was accepted');
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensurePretesCbtTables($pdo);
ensurePretesCbtTables($pdo);
cbtAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_questions')->fetchColumn() === 40, 'Seed bank exactly once');
cbtAssert(pretesCbtState($pdo, 7, 42, 1000)['attempt'] === null, 'Unstarted state');
$initial = pretesCbtStart($pdo, 7, 42, 1000)['attempt'];
cbtAssert(count($initial['questions']) === 25 && $initial['remaining_seconds'] === 1800, '25 questions, 30 minutes');
cbtAssert(strpos(json_encode($initial), 'correct_answer') === false && $initial['score'] === null, 'No answer keys or scores leaked');
foreach ($initial['questions'] as $question) cbtAssert(array_keys($question['options']) === range('A', 'E'), 'Five options A-E');
$rows = $pdo->query('SELECT * FROM pretes_cbt_items WHERE attempt_id = ' . $initial['id'] . ' ORDER BY position')->fetchAll(PDO::FETCH_ASSOC);
cbtAssert(count(array_unique(array_column($rows, 'question_id'))) === 25, 'No duplicate questions');
foreach ($rows as $row) {
    $source = $pdo->query('SELECT * FROM pretes_cbt_questions WHERE id = ' . $row['question_id'])->fetch(PDO::FETCH_ASSOC);
    $sourceOptions = json_decode($source['options_json'], true);
    $shuffled = json_decode($row['options_json'], true);
    cbtAssert($shuffled[$row['correct_answer']] === $sourceOptions[$source['correct_answer']], 'Random options preserve correct answer');
}
$resumed = pretesCbtStart($pdo, 7, 42, 1100)['attempt'];
cbtAssert($resumed['questions'] === $initial['questions'] && $resumed['remaining_seconds'] === 1700, 'Refresh preserves package and deadline');
cbtAssert(pretesCbtState($pdo, 8, 42, 1100)['attempt'] === null, 'Owner isolation');
cbtReject(fn() => pretesCbtChange($pdo, 8, 42, 'save', ['position' => 1, 'answer' => 'A'], 1100));
cbtReject(fn() => pretesCbtChange($pdo, 7, 42, 'save', ['position' => 26, 'answer' => 'A'], 1100));
cbtReject(fn() => pretesCbtChange($pdo, 7, 42, 'save', ['position' => 1, 'answer' => 'Z'], 1100));
for ($i = 0; $i < 3; $i++) pretesCbtChange($pdo, 7, 42, 'save', ['position' => $i + 1, 'answer' => $rows[$i]['correct_answer'], 'flagged' => $i === 0], 1100);
$saved = pretesCbtState($pdo, 7, 42, 1200)['attempt'];
cbtAssert($saved['answered'] === 3 && $saved['questions'][0]['flagged'], 'Answers and review flags persist');
$pdo->exec("UPDATE pretes_cbt_questions SET correct_answer = 'A', question_text = 'Edited source'");
$final = pretesCbtChange($pdo, 7, 42, 'submit', ['score' => 100], 1300)['attempt'];
cbtAssert($final['submitted'] && $final['correct'] === 3 && $final['score'] === 12.0 && $final['questions'] === [], 'Server snapshot grading ignores client score');
$again = pretesCbtChange($pdo, 7, 42, 'submit', [], 1400)['attempt'];
cbtAssert($again['score'] === 12.0 && $again['id'] === $final['id'], 'Submission idempotent');
$after = pretesCbtChange($pdo, 7, 42, 'save', ['position' => 4, 'answer' => 'A'], 1500)['attempt'];
cbtAssert($after['score'] === 12.0 && $after['answered'] === 3, 'Submitted answers immutable');
$other = pretesCbtStart($pdo, 8, 77, 2000)['attempt'];
$expired = pretesCbtChange($pdo, 8, 77, 'save', ['position' => 1, 'answer' => 'A'], 3800)['attempt'];
cbtAssert($expired['submitted'] && $expired['answered'] === 0 && $expired['score'] === 0.0, 'Server deadline rejects late answers and finalizes');
$form = ['category' => 'Uji', 'question_text' => 'Pertanyaan admin', 'correct_answer' => 'E', 'is_active' => '1'];
foreach (range('A', 'E') as $letter) $form['option_' . $letter] = 'Pilihan ' . $letter;
pretesCbtSaveQuestion($pdo, $form);
$questionId = (int)$pdo->lastInsertId();
cbtAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_questions')->fetchColumn() === 41, 'Admin adds a question');
$form['id'] = $questionId;
$form['correct_answer'] = 'C';
unset($form['is_active']);
pretesCbtSaveQuestion($pdo, $form);
$edited = $pdo->query('SELECT * FROM pretes_cbt_questions WHERE id = ' . $questionId)->fetch(PDO::FETCH_ASSOC);
cbtAssert($edited['correct_answer'] === 'C' && (int)$edited['is_active'] === 0, 'Admin edits key and active state');
cbtReject(fn() => pretesCbtSaveQuestion($pdo, array_merge($form, ['option_A' => ''])));
$pdo->exec('UPDATE pretes_cbt_questions SET is_active = 0');
cbtReject(fn() => pretesCbtStart($pdo, 9, 88, 4000));
cbtAssert(pretesCbtState($pdo, 9, 88, 4000)['attempt'] === null, 'Insufficient bank rolls back attempt');
echo "PASS: seed bank, random snapshots and choices, resume, ownership, validation, autosave, server grading, immutable submit, and expiry.\n";
