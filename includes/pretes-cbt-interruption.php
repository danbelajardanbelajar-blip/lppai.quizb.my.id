<?php
// Runs inside the attempt's locked transaction. Only server time extends deadlines.
function pretesCbtInterruptionTables(PDO $pdo, string $engine): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_cbt_interruptions (
        attempt_id INTEGER PRIMARY KEY, revision INTEGER NOT NULL DEFAULT 0,
        paused_at INTEGER DEFAULT NULL, resume_position INTEGER NOT NULL DEFAULT 1,
        reason VARCHAR(40) DEFAULT NULL, event_id VARCHAR(80) DEFAULT NULL
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_cbt_interruption_events (
        attempt_id INTEGER NOT NULL, event_id VARCHAR(80) NOT NULL,
        reason VARCHAR(40) NOT NULL, interrupted_position INTEGER NOT NULL,
        paused_at INTEGER NOT NULL, resumed_at INTEGER DEFAULT NULL,
        PRIMARY KEY(attempt_id, event_id)
    )$engine");
}

function pretesCbtTiming(PDO $pdo, array $attempt): array {
    $stmt = $pdo->prepare('SELECT * FROM pretes_cbt_interruptions WHERE attempt_id = ?');
    $stmt->execute([$attempt['id']]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return $attempt + ['paused_at' => $state['paused_at'] ?? null,
        'revision' => (int)($state['revision'] ?? 0),
        'resume_position' => (int)($state['resume_position'] ?? 1),
        'pause_reason' => $state['reason'] ?? null];
}

function pretesCbtClock(array $attempt, int $now): int {
    return $attempt['paused_at'] !== null ? (int)$attempt['paused_at'] : $now;
}

function pretesCbtInterrupt(PDO $pdo, array $attempt, array $input, int $now): array {
    $event = $input['event_id'] ?? '';
    $reason = $input['reason'] ?? '';
    if (!is_string($event) || !preg_match('/^[a-zA-Z0-9_-]{8,80}$/D', $event)
        || !in_array($reason, ['page_hidden', 'camera_unavailable', 'face_missing', 'face_mismatch'], true)) {
        throw new InvalidArgumentException('Peristiwa pengawasan tidak valid.');
    }
    $seen = $pdo->prepare('SELECT 1 FROM pretes_cbt_interruption_events WHERE attempt_id = ? AND event_id = ?');
    $seen->execute([$attempt['id'], $event]);
    if ($seen->fetchColumn() || $attempt['paused_at'] !== null) return $attempt;
    if ((int)($input['revision'] ?? 0) !== (int)$attempt['revision']) throw new RuntimeException('Urutan soal berubah. Muat ulang halaman.');
    $stmt = $pdo->prepare('SELECT * FROM pretes_cbt_items WHERE attempt_id = ? ORDER BY position');
    $stmt->execute([$attempt['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $position = filter_var($input['position'] ?? null, FILTER_VALIDATE_INT);
    if (!$position || $position < 1 || $position > count($items)) throw new InvalidArgumentException('Nomor soal tidak valid.');
    // Retain other pending answers atomically before positions change.
    $pending = $input['pending'] ?? [];
    if (!is_array($pending) || count($pending) > count($items)) throw new InvalidArgumentException('Jawaban tertunda tidak valid.');
    foreach ($pending as $change) {
        if (!is_array($change)) throw new InvalidArgumentException('Jawaban tertunda tidak valid.');
        $p = filter_var($change['position'] ?? null, FILTER_VALIDATE_INT);
        $answer = $change['answer'] ?? null;
        if (!$p || $p < 1 || $p > count($items) || ($answer !== null && !in_array($answer, range('A', 'E'), true))) throw new InvalidArgumentException('Jawaban tertunda tidak valid.');
        $items[$p - 1]['answer'] = $answer;
        $items[$p - 1]['flagged'] = !empty($change['flagged']) ? 1 : 0;
    }
    $current = $position - 1;
    $items[$current]['answer'] = null;
    $options = json_decode($items[$current]['options_json'], true);
    $letters = array_keys($options);
    $order = pretesCbtShuffle($letters);
    if ($order === $letters) $order = array_merge(array_slice($order, 1), [$order[0]]);
    $shuffled = [];
    foreach ($order as $i => $oldLetter) {
        $letter = chr(65 + $i);
        $shuffled[$letter] = $options[$oldLetter];
        if ($oldLetter === $items[$current]['correct_answer']) $correct = $letter;
    }
    $items[$current]['options_json'] = json_encode($shuffled, JSON_UNESCAPED_UNICODE);
    $items[$current]['correct_answer'] = $correct;
    $candidates = [];
    foreach ($items as $i => $item) if ($i !== $current && $item['answer'] === null) $candidates[] = $i;
    if ($candidates) {
        $other = $candidates[random_int(0, count($candidates) - 1)];
        [$items[$current], $items[$other]] = [$items[$other], $items[$current]];
    }
    $update = $pdo->prepare('UPDATE pretes_cbt_items SET question_id = ?, question_text = ?, options_json = ?, correct_answer = ?, answer = ?, flagged = ? WHERE attempt_id = ? AND position = ?');
    foreach ($items as $i => $item) $update->execute([$item['question_id'], $item['question_text'], $item['options_json'], $item['correct_answer'], $item['answer'], $item['flagged'], $attempt['id'], $i + 1]);
    $revision = (int)$attempt['revision'] + 1;
    $pdo->prepare('DELETE FROM pretes_cbt_interruptions WHERE attempt_id = ?')->execute([$attempt['id']]);
    $pdo->prepare('INSERT INTO pretes_cbt_interruptions (attempt_id, revision, paused_at, resume_position, reason, event_id) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$attempt['id'], $revision, $now, $position, $reason, $event]);
    $pdo->prepare('INSERT INTO pretes_cbt_interruption_events (attempt_id, event_id, reason, interrupted_position, paused_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$attempt['id'], $event, $reason, $position, $now]);
    return array_replace($attempt, ['paused_at' => $now, 'revision' => $revision, 'resume_position' => $position, 'pause_reason' => $reason]);
}

function pretesCbtResume(PDO $pdo, array $attempt, int $now): array {
    if ($attempt['paused_at'] === null) return $attempt;
    $deadline = (int)$attempt['deadline_at'] + max(0, $now - (int)$attempt['paused_at']);
    $pdo->prepare('UPDATE pretes_cbt_attempts SET deadline_at = ? WHERE id = ?')->execute([$deadline, $attempt['id']]);
    $pdo->prepare('UPDATE pretes_cbt_interruption_events SET resumed_at = ? WHERE attempt_id = ? AND resumed_at IS NULL')->execute([$now, $attempt['id']]);
    $pdo->prepare('UPDATE pretes_cbt_interruptions SET paused_at = NULL WHERE attempt_id = ?')->execute([$attempt['id']]);
    return array_replace($attempt, ['deadline_at' => $deadline, 'paused_at' => null]);
}
