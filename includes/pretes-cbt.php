<?php
const PRETES_CBT_COUNT = 25;
const PRETES_CBT_SECONDS = 1800;

function ensurePretesCbtTables(PDO $pdo): void {
    try {
        foreach (['questions', 'attempts', 'items'] as $table) $pdo->query("SELECT 1 FROM pretes_cbt_$table LIMIT 0");
    } catch (PDOException $e) {
        $id = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $engine = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_cbt_questions (
            id $id, code VARCHAR(64) NOT NULL UNIQUE, category VARCHAR(80) NOT NULL,
            question_text TEXT NOT NULL, options_json TEXT NOT NULL, correct_answer CHAR(1) NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1
        )$engine");
        $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_cbt_attempts (
            id $id, user_id INTEGER NOT NULL, registration_id INTEGER NOT NULL,
            started_at INTEGER NOT NULL, deadline_at INTEGER NOT NULL, submitted_at INTEGER DEFAULT NULL,
            correct_count INTEGER DEFAULT NULL, score DECIMAL(5,2) DEFAULT NULL,
            UNIQUE(user_id, registration_id)
        )$engine");
        $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_cbt_items (
            attempt_id INTEGER NOT NULL, position INTEGER NOT NULL, question_id INTEGER NOT NULL,
            question_text TEXT NOT NULL, options_json TEXT NOT NULL, correct_answer CHAR(1) NOT NULL,
            answer CHAR(1) DEFAULT NULL, flagged INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY(attempt_id, position)
        )$engine");
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_questions')->fetchColumn() === 0) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $insert = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $stmt = $pdo->prepare("$insert INTO pretes_cbt_questions (code, category, question_text, options_json, correct_answer) VALUES (?, ?, ?, ?, ?)");
        $pdo->beginTransaction();
        try {
            foreach (require __DIR__ . '/../data/pretes-cbt-bank.php' as $i => $row) {
                [$question, $options, $correct, $category] = $row;
                $stmt->execute(['initial-' . ($i + 1), $category, $question, json_encode(array_combine(range('A', 'E'), $options), JSON_UNESCAPED_UNICODE), $correct]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}

function pretesCbtShuffle(array $values): array {
    $values = array_values($values);
    for ($i = count($values) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
    }
    return $values;
}

function pretesCbtLock(PDO $pdo, int $userId, int $registrationId): ?array {
    $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare('SELECT * FROM pretes_cbt_attempts WHERE user_id = ? AND registration_id = ?' . $suffix);
    $stmt->execute([$userId, $registrationId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pretesCbtFinalize(PDO $pdo, array $attempt, int $now): array {
    if ($attempt['submitted_at'] !== null) return $attempt;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pretes_cbt_items WHERE attempt_id = ? AND answer = correct_answer');
    $stmt->execute([$attempt['id']]);
    $correct = (int)$stmt->fetchColumn();
    $score = round($correct / PRETES_CBT_COUNT * 100, 2);
    $pdo->prepare('UPDATE pretes_cbt_attempts SET submitted_at = ?, correct_count = ?, score = ? WHERE id = ? AND submitted_at IS NULL')
        ->execute([min($now, (int)$attempt['deadline_at']), $correct, $score, $attempt['id']]);
    $attempt['submitted_at'] = min($now, (int)$attempt['deadline_at']);
    $attempt['correct_count'] = $correct;
    $attempt['score'] = $score;
    return $attempt;
}

function pretesCbtPublic(PDO $pdo, ?array $attempt, int $now): array {
    if (!$attempt) return ['attempt' => null, 'question_count' => PRETES_CBT_COUNT, 'duration_seconds' => PRETES_CBT_SECONDS];
    $submitted = $attempt['submitted_at'] !== null;
    $items = [];
    $stmt = $pdo->prepare('SELECT position, question_text, options_json, answer, flagged FROM pretes_cbt_items WHERE attempt_id = ? ORDER BY position');
    $stmt->execute([$attempt['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = ['position' => (int)$row['position'], 'text' => $row['question_text'],
            'options' => json_decode($row['options_json'], true), 'answer' => $row['answer'], 'flagged' => (bool)$row['flagged']];
    }
    $answered = count(array_filter($items, static fn($item) => $item['answer'] !== null));
    return ['attempt' => [
        'id' => (int)$attempt['id'], 'submitted' => $submitted,
        'remaining_seconds' => max(0, (int)$attempt['deadline_at'] - $now),
        'total' => PRETES_CBT_COUNT, 'answered' => $answered,
        'correct' => $submitted ? (int)$attempt['correct_count'] : null,
        'score' => $submitted ? (float)$attempt['score'] : null,
        'questions' => $submitted ? [] : $items,
    ]];
}

function pretesCbtState(PDO $pdo, int $userId, int $registrationId, ?int $now = null): array {
    $now ??= time();
    $pdo->beginTransaction();
    try {
        $attempt = pretesCbtLock($pdo, $userId, $registrationId);
        if ($attempt && $attempt['submitted_at'] === null && $now >= $attempt['deadline_at']) $attempt = pretesCbtFinalize($pdo, $attempt, $now);
        $result = pretesCbtPublic($pdo, $attempt, $now);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function pretesCbtStart(PDO $pdo, int $userId, int $registrationId, ?int $now = null): array {
    $now ??= time();
    $pdo->beginTransaction();
    try {
        $attempt = pretesCbtLock($pdo, $userId, $registrationId);
        if (!$attempt) {
            $bank = $pdo->query('SELECT * FROM pretes_cbt_questions WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
            if (count($bank) < PRETES_CBT_COUNT) throw new RuntimeException('Bank soal aktif belum mencapai 25 soal. Hubungi admin.');
            $selected = array_slice(pretesCbtShuffle($bank), 0, PRETES_CBT_COUNT);
            $pdo->prepare('INSERT INTO pretes_cbt_attempts (user_id, registration_id, started_at, deadline_at) VALUES (?, ?, ?, ?)')
                ->execute([$userId, $registrationId, $now, $now + PRETES_CBT_SECONDS]);
            $attemptId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO pretes_cbt_items (attempt_id, position, question_id, question_text, options_json, correct_answer) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($selected as $i => $question) {
                $old = json_decode($question['options_json'], true);
                $keys = pretesCbtShuffle(range('A', 'E'));
                $options = [];
                $correct = '';
                foreach (range('A', 'E') as $j => $letter) {
                    $options[$letter] = $old[$keys[$j]];
                    if ($keys[$j] === $question['correct_answer']) $correct = $letter;
                }
                $stmt->execute([$attemptId, $i + 1, $question['id'], $question['question_text'], json_encode($options, JSON_UNESCAPED_UNICODE), $correct]);
            }
            $attempt = pretesCbtLock($pdo, $userId, $registrationId);
        }
        if ($attempt['submitted_at'] === null && $now >= $attempt['deadline_at']) $attempt = pretesCbtFinalize($pdo, $attempt, $now);
        $result = pretesCbtPublic($pdo, $attempt, $now);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Concurrent starts are bound by UNIQUE(user_id, registration_id).
        if ($e instanceof PDOException && in_array((string)$e->getCode(), ['23000', '23505'], true)) return pretesCbtState($pdo, $userId, $registrationId, $now);
        throw $e;
    }
}

function pretesCbtChange(PDO $pdo, int $userId, int $registrationId, string $action, array $input, ?int $now = null): array {
    $now ??= time();
    $pdo->beginTransaction();
    try {
        $attempt = pretesCbtLock($pdo, $userId, $registrationId);
        if (!$attempt) throw new RuntimeException('Mulai ujian terlebih dahulu.');
        if ($attempt['submitted_at'] === null && $now >= $attempt['deadline_at']) $attempt = pretesCbtFinalize($pdo, $attempt, $now);
        if ($attempt['submitted_at'] === null && $action === 'save') {
            $position = filter_var($input['position'] ?? null, FILTER_VALIDATE_INT);
            $answer = $input['answer'] ?? null;
            if (!$position || $position < 1 || $position > PRETES_CBT_COUNT || ($answer !== null && !in_array($answer, range('A', 'E'), true))) {
                throw new InvalidArgumentException('Jawaban atau nomor soal tidak valid.');
            }
            $pdo->prepare('UPDATE pretes_cbt_items SET answer = ?, flagged = ? WHERE attempt_id = ? AND position = ?')
                ->execute([$answer, !empty($input['flagged']) ? 1 : 0, $attempt['id'], $position]);
        } elseif ($action === 'submit') {
            $attempt = pretesCbtFinalize($pdo, $attempt, $now);
        }
        $result = pretesCbtPublic($pdo, $attempt, $now);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function pretesCbtSaveQuestion(PDO $pdo, array $input): void {
    $text = trim((string)($input['question_text'] ?? ''));
    $category = trim((string)($input['category'] ?? 'Umum'));
    $options = [];
    foreach (range('A', 'E') as $letter) {
        $value = trim((string)($input['option_' . $letter] ?? ''));
        if ($value === '' || strlen($value) > 2000) throw new InvalidArgumentException('Isi kelima pilihan A–E, maksimal 2.000 karakter per pilihan.');
        $options[$letter] = $value;
    }
    if (count(array_unique($options)) !== 5) throw new InvalidArgumentException('Kelima pilihan jawaban harus berbeda.');
    $correct = $input['correct_answer'] ?? '';
    if ($text === '' || strlen($text) > 10000 || $category === '' || strlen($category) > 80 || !in_array($correct, range('A', 'E'), true)) throw new InvalidArgumentException('Periksa pertanyaan, kategori, dan kunci jawaban.');
    $values = [$category, $text, json_encode($options, JSON_UNESCAPED_UNICODE), $correct, isset($input['is_active']) ? 1 : 0];
    if (!empty($input['id'])) {
        $values[] = (int)$input['id'];
        $pdo->prepare('UPDATE pretes_cbt_questions SET category = ?, question_text = ?, options_json = ?, correct_answer = ?, is_active = ? WHERE id = ?')->execute($values);
    } else {
        array_unshift($values, 'custom-' . bin2hex(random_bytes(12)));
        $pdo->prepare('INSERT INTO pretes_cbt_questions (code, category, question_text, options_json, correct_answer, is_active) VALUES (?, ?, ?, ?, ?, ?)')->execute($values);
    }
}
