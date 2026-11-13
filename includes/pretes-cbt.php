<?php
const PRETES_CBT_COUNT = 25;
const PRETES_CBT_SECONDS = 1800;

function ensurePretesCbtTables(PDO $pdo): void {
    $id = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
    $engine = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_cbt_packages (
        id $id,
        code VARCHAR(64) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        description TEXT DEFAULT NULL,
        duration_minutes INTEGER NOT NULL DEFAULT 30,
        question_count INTEGER NOT NULL DEFAULT 25,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at INTEGER NOT NULL
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_cbt_questions (
        id $id, code VARCHAR(64) NOT NULL UNIQUE, package_id INTEGER NOT NULL DEFAULT 1,
        category VARCHAR(80) NOT NULL,
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

    // Pastikan kolom package_id ada di pretes_cbt_questions
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasCol = false;
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info(pretes_cbt_questions)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                if (strtolower((string)($col['name'] ?? '')) === 'package_id') { $hasCol = true; break; }
            }
        } else {
            $cols = $pdo->query("SHOW COLUMNS FROM pretes_cbt_questions LIKE 'package_id'")->fetchAll(PDO::FETCH_ASSOC);
            $hasCol = !empty($cols);
        }
        if (!$hasCol) {
            $pdo->exec("ALTER TABLE pretes_cbt_questions ADD COLUMN package_id INTEGER NOT NULL DEFAULT 1");
        }
    } catch (Throwable $e) {}

    // Inisialisasi default package jika tabel packages kosong
    if ((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_packages')->fetchColumn() === 0) {
        $now = time();
        $pdo->prepare("INSERT INTO pretes_cbt_packages (id, code, name, description, duration_minutes, question_count, is_active, created_at) VALUES (1, 'PKT-UTAMA', 'Paket Pretes Utama', 'Paket soal standar ujian pretes ubudiyah LPPAI', 30, 25, 1, ?)")
            ->execute([$now]);
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_questions')->fetchColumn() === 0) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $insert = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $stmt = $pdo->prepare("$insert INTO pretes_cbt_questions (code, package_id, category, question_text, options_json, correct_answer) VALUES (?, 1, ?, ?, ?, ?)");
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
    $itemCount = (int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_items WHERE attempt_id = ' . (int)$attempt['id'])->fetchColumn();
    $totalQ = $itemCount > 0 ? $itemCount : PRETES_CBT_COUNT;
    $score = round($correct / $totalQ * 100, 2);
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
        'total' => count($items) ?: PRETES_CBT_COUNT, 'answered' => $answered,
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
            $package = null;
            try {
                $pkgStmt = $pdo->query('SELECT * FROM pretes_cbt_packages WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
                $package = $pkgStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}

            $targetCount = $package && !empty($package['question_count']) ? (int)$package['question_count'] : PRETES_CBT_COUNT;
            $durationSeconds = $package && !empty($package['duration_minutes']) ? ((int)$package['duration_minutes'] * 60) : PRETES_CBT_SECONDS;

            if ($package) {
                $bankStmt = $pdo->prepare('SELECT * FROM pretes_cbt_questions WHERE package_id = ? AND is_active = 1');
                $bankStmt->execute([(int)$package['id']]);
                $bank = $bankStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $bank = $pdo->query('SELECT * FROM pretes_cbt_questions WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
            }

            if (count($bank) < $targetCount) {
                $pkgName = $package ? (" '" . $package['name'] . "'") : '';
                throw new RuntimeException("Bank soal aktif{$pkgName} belum mencapai {$targetCount} soal aktif. Hubungi admin.");
            }
            $selected = array_slice(pretesCbtShuffle($bank), 0, $targetCount);
            $pdo->prepare('INSERT INTO pretes_cbt_attempts (user_id, registration_id, started_at, deadline_at) VALUES (?, ?, ?, ?)')
                ->execute([$userId, $registrationId, $now, $now + $durationSeconds]);
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
    ensurePretesCbtTables($pdo);
    $length = static fn(string $text): int => function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    $text = trim((string)($input['question_text'] ?? ''));
    $category = trim((string)($input['category'] ?? 'Umum'));
    $packageId = (int)($input['package_id'] ?? 0);
    if ($packageId <= 0) {
        $activePkg = pretesCbtGetActivePackage($pdo);
        $packageId = $activePkg ? (int)$activePkg['id'] : 1;
    }
    $checkPkg = $pdo->prepare('SELECT id FROM pretes_cbt_packages WHERE id = ?');
    $checkPkg->execute([$packageId]);
    if (!$checkPkg->fetchColumn()) {
        throw new InvalidArgumentException('Paket soal tidak valid. Buat paket soal terlebih dahulu.');
    }

    $options = [];
    foreach (range('A', 'E') as $letter) {
        $value = trim((string)($input['option_' . $letter] ?? ''));
        if ($value === '' || $length($value) > 2000) throw new InvalidArgumentException('Isi kelima pilihan A–E, maksimal 2.000 karakter per pilihan.');
        $options[$letter] = $value;
    }
    if (count(array_unique($options)) !== 5) throw new InvalidArgumentException('Kelima pilihan jawaban harus berbeda.');
    $correct = $input['correct_answer'] ?? '';
    if ($text === '' || $length($text) > 10000 || $category === '' || $length($category) > 80 || !in_array($correct, range('A', 'E'), true)) throw new InvalidArgumentException('Periksa pertanyaan, kategori, dan kunci jawaban.');
    $values = [$packageId, $category, $text, json_encode($options, JSON_UNESCAPED_UNICODE), $correct, isset($input['is_active']) ? 1 : 0];
    if (!empty($input['id'])) {
        $values[] = (int)$input['id'];
        $pdo->prepare('UPDATE pretes_cbt_questions SET package_id = ?, category = ?, question_text = ?, options_json = ?, correct_answer = ?, is_active = ? WHERE id = ?')->execute($values);
    } else {
        array_unshift($values, 'custom-' . bin2hex(random_bytes(12)));
        $pdo->prepare('INSERT INTO pretes_cbt_questions (code, package_id, category, question_text, options_json, correct_answer, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute($values);
    }
}

function pretesCbtGetPackages(PDO $pdo): array {
    ensurePretesCbtTables($pdo);
    return $pdo->query('SELECT p.*, (SELECT COUNT(*) FROM pretes_cbt_questions q WHERE q.package_id = p.id) AS total_questions, (SELECT COUNT(*) FROM pretes_cbt_questions q WHERE q.package_id = p.id AND q.is_active = 1) AS question_count_actual FROM pretes_cbt_packages p ORDER BY p.is_active DESC, p.id DESC')->fetchAll(PDO::FETCH_ASSOC);
}

function pretesCbtGetPackage(PDO $pdo, int $packageId): ?array {
    if ($packageId <= 0) return null;
    ensurePretesCbtTables($pdo);
    $stmt = $pdo->prepare('SELECT p.*, (SELECT COUNT(*) FROM pretes_cbt_questions q WHERE q.package_id = p.id) AS total_questions, (SELECT COUNT(*) FROM pretes_cbt_questions q WHERE q.package_id = p.id AND q.is_active = 1) AS question_count_actual FROM pretes_cbt_packages p WHERE p.id = ?');
    $stmt->execute([$packageId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pretesCbtGetActivePackage(PDO $pdo): ?array {
    ensurePretesCbtTables($pdo);
    $pkg = $pdo->query('SELECT * FROM pretes_cbt_packages WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return $pkg ?: null;
}

function pretesCbtSavePackage(PDO $pdo, array $input): int {
    ensurePretesCbtTables($pdo);
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Nama paket soal wajib diisi.');
    }
    if (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') > 150 : strlen($name) > 150) {
        throw new InvalidArgumentException('Nama paket soal maksimal 150 karakter.');
    }
    $description = trim((string)($input['description'] ?? ''));
    $duration = max(5, min(300, (int)($input['duration_minutes'] ?? 30)));
    $questionCount = max(5, min(200, (int)($input['question_count'] ?? 25)));
    $isActive = isset($input['is_active']) && (int)$input['is_active'] === 1 ? 1 : 0;
    $pkgId = (int)($input['id'] ?? 0);

    $pdo->beginTransaction();
    try {
        if ($isActive === 1) {
            $pdo->exec('UPDATE pretes_cbt_packages SET is_active = 0');
        } else {
            $otherCount = (int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_packages WHERE id != ' . $pkgId)->fetchColumn();
            if ($otherCount === 0) {
                $isActive = 1;
            }
        }

        if ($pkgId > 0) {
            $stmt = $pdo->prepare('UPDATE pretes_cbt_packages SET name = ?, description = ?, duration_minutes = ?, question_count = ?, is_active = ? WHERE id = ?');
            $stmt->execute([$name, $description !== '' ? $description : null, $duration, $questionCount, $isActive, $pkgId]);
        } else {
            $code = trim((string)($input['code'] ?? ''));
            if ($code === '') {
                $code = 'PKT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            }
            $stmt = $pdo->prepare('INSERT INTO pretes_cbt_packages (code, name, description, duration_minutes, question_count, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$code, $name, $description !== '' ? $description : null, $duration, $questionCount, $isActive, time()]);
            $pkgId = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        return $pkgId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function pretesCbtActivatePackage(PDO $pdo, int $packageId): bool {
    if ($packageId <= 0) return false;
    ensurePretesCbtTables($pdo);
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE pretes_cbt_packages SET is_active = 0');
        $stmt = $pdo->prepare('UPDATE pretes_cbt_packages SET is_active = 1 WHERE id = ?');
        $stmt->execute([$packageId]);
        $ok = $stmt->rowCount() > 0;
        $pdo->commit();
        return $ok;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function pretesCbtDeletePackage(PDO $pdo, int $packageId): bool {
    if ($packageId <= 0) return false;
    ensurePretesCbtTables($pdo);
    $pdo->beginTransaction();
    try {
        $stmtDelQ = $pdo->prepare('DELETE FROM pretes_cbt_questions WHERE package_id = ?');
        $stmtDelQ->execute([$packageId]);

        $stmt = $pdo->prepare('DELETE FROM pretes_cbt_packages WHERE id = ?');
        $stmt->execute([$packageId]);
        $ok = $stmt->rowCount() > 0;

        $activeCount = (int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_packages WHERE is_active = 1')->fetchColumn();
        if ($activeCount === 0) {
            $firstPkg = $pdo->query('SELECT id FROM pretes_cbt_packages ORDER BY id ASC LIMIT 1')->fetchColumn();
            if ($firstPkg) {
                $pdo->prepare('UPDATE pretes_cbt_packages SET is_active = 1 WHERE id = ?')->execute([(int)$firstPkg]);
            }
        }

        $pdo->commit();
        return $ok;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function pretesCbtDeleteAttempt(PDO $pdo, int $attemptId): bool {
    if ($attemptId <= 0) return false;
    $tx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $tx = true;
    }
    try {
        $stmtItems = $pdo->prepare('DELETE FROM pretes_cbt_items WHERE attempt_id = ?');
        $stmtItems->execute([$attemptId]);

        $stmtAttempt = $pdo->prepare('DELETE FROM pretes_cbt_attempts WHERE id = ?');
        $stmtAttempt->execute([$attemptId]);
        $deleted = $stmtAttempt->rowCount() > 0;

        if ($tx) $pdo->commit();
        return $deleted;
    } catch (Throwable $e) {
        if ($tx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function pretesCbtDeleteAllAttempts(PDO $pdo, ?array $attemptIds = null): int {
    $tx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $tx = true;
    }
    try {
        if ($attemptIds === null) {
            $pdo->exec('DELETE FROM pretes_cbt_items');
            $count = (int)$pdo->exec('DELETE FROM pretes_cbt_attempts');
        } else {
            $cleanIds = array_values(array_filter(array_map('intval', $attemptIds), fn($id) => $id > 0));
            if (empty($cleanIds)) {
                if ($tx) $pdo->commit();
                return 0;
            }
            $inClause = implode(',', array_fill(0, count($cleanIds), '?'));
            $stmtItems = $pdo->prepare("DELETE FROM pretes_cbt_items WHERE attempt_id IN ($inClause)");
            $stmtItems->execute($cleanIds);

            $stmtAttempts = $pdo->prepare("DELETE FROM pretes_cbt_attempts WHERE id IN ($inClause)");
            $stmtAttempts->execute($cleanIds);
            $count = (int)$stmtAttempts->rowCount();
        }
        if ($tx) $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        if ($tx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

