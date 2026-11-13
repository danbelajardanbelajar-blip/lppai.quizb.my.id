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
        duration_minutes INTEGER DEFAULT 30,
        question_count INTEGER NOT NULL DEFAULT 25,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at INTEGER NOT NULL
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_cbt_sessions (
        id $id,
        code VARCHAR(64) NOT NULL UNIQUE,
        title VARCHAR(150) NOT NULL,
        description TEXT DEFAULT NULL,
        package_ids_json TEXT DEFAULT NULL,
        category_quotas_json TEXT DEFAULT NULL,
        camera_mode VARCHAR(20) NOT NULL DEFAULT 'wajib',
        duration_minutes INTEGER DEFAULT 30,
        scoring_method VARCHAR(30) NOT NULL DEFAULT 'standar',
        passing_grade DECIMAL(5,2) NOT NULL DEFAULT 65.00,
        start_time VARCHAR(30) DEFAULT NULL,
        end_time VARCHAR(30) DEFAULT NULL,
        token VARCHAR(32) DEFAULT NULL,
        token_required INTEGER NOT NULL DEFAULT 0,
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
        session_id INTEGER DEFAULT NULL,
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

    // Pastikan kolom session_id ada di pretes_cbt_attempts
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasSessionCol = false;
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info(pretes_cbt_attempts)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                if (strtolower((string)($col['name'] ?? '')) === 'session_id') { $hasSessionCol = true; break; }
            }
        } else {
            $cols = $pdo->query("SHOW COLUMNS FROM pretes_cbt_attempts LIKE 'session_id'")->fetchAll(PDO::FETCH_ASSOC);
            $hasSessionCol = !empty($cols);
        }
        if (!$hasSessionCol) {
            $pdo->exec("ALTER TABLE pretes_cbt_attempts ADD COLUMN session_id INTEGER DEFAULT NULL");
        }
    } catch (Throwable $e) {}

    // Inisialisasi default package jika tabel packages kosong
    if ((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_packages')->fetchColumn() === 0) {
        $now = time();
        $pdo->prepare("INSERT INTO pretes_cbt_packages (id, code, name, description, duration_minutes, question_count, is_active, created_at) VALUES (1, 'PKT-UTAMA', 'Paket Pretes Utama', 'Paket soal standar ujian pretes ubudiyah LPPAI', 30, 25, 1, ?)")
            ->execute([$now]);
    }

    // Inisialisasi default session jika tabel sessions kosong
    if ((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_sessions')->fetchColumn() === 0) {
        $now = time();
        $pdo->prepare("INSERT INTO pretes_cbt_sessions (id, code, title, description, package_ids_json, category_quotas_json, camera_mode, duration_minutes, scoring_method, passing_grade, start_time, end_time, token, token_required, is_active, created_at) VALUES (1, 'SESI-REGULER-2026', 'Sesi Pretes Reguler 2026', 'Sesi ujian pretes resmi tutorial ubudiyah mahasiswa', '[]', NULL, 'wajib', NULL, 'standar', 65.00, NULL, NULL, 'PRETES26', 0, 1, ?)")
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

    $stmtItems = $pdo->prepare('SELECT answer, correct_answer FROM pretes_cbt_items WHERE attempt_id = ?');
    $stmtItems->execute([$attempt['id']]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    $totalQ = count($items) > 0 ? count($items) : PRETES_CBT_COUNT;

    $correct = 0;
    $wrong = 0;
    $empty = 0;
    foreach ($items as $item) {
        if ($item['answer'] === null || $item['answer'] === '') {
            $empty++;
        } elseif ($item['answer'] === $item['correct_answer']) {
            $correct++;
        } else {
            $wrong++;
        }
    }

    $session = null;
    if (!empty($attempt['session_id'])) {
        $session = pretesCbtGetSession($pdo, (int)$attempt['session_id']);
    }
    $scoringMethod = $session['scoring_method'] ?? 'standar';

    if ($scoringMethod === 'penalti') {
        // UTBK style: Benar +4, Salah -1, Kosong 0
        $rawScore = ($correct * 4) - ($wrong * 1);
        $maxRaw = $totalQ * 4;
        $score = $maxRaw > 0 ? round(max(0, $rawScore) / $maxRaw * 100, 2) : 0.00;
    } else {
        $score = round($correct / $totalQ * 100, 2);
    }

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

function pretesCbtStart(PDO $pdo, int $userId, int $registrationId, ?int $now = null, ?string $token = null): array {
    $now ??= time();
    $pdo->beginTransaction();
    try {
        $attempt = pretesCbtLock($pdo, $userId, $registrationId);
        if (!$attempt) {
            $session = pretesCbtGetActiveSession($pdo);
            $sessionId = $session ? (int)$session['id'] : null;

            // Validasi Jadwal Waktu Mulai & Selesai Sesi
            if ($session) {
                if (!empty($session['start_time'])) {
                    $startTs = strtotime($session['start_time']);
                    if ($startTs !== false && $now < $startTs) {
                        $startFmt = (new DateTimeImmutable('@' . $startTs))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i');
                        throw new RuntimeException("Sesi ujian '{$session['title']}' belum dibuka. Ujian dapat dimulai pada {$startFmt} WIB.");
                    }
                }
                if (!empty($session['end_time'])) {
                    $endTs = strtotime($session['end_time']);
                    if ($endTs !== false && $now > $endTs) {
                        $endFmt = (new DateTimeImmutable('@' . $endTs))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i');
                        throw new RuntimeException("Sesi ujian '{$session['title']}' telah ditutup sejak {$endFmt} WIB. Anda tidak dapat memulai ujian lagi.");
                    }
                }
                // Validasi Token Ujian
                if (!empty($session['token_required']) && !empty($session['token'])) {
                    $tokenClean = strtoupper(trim((string)$token));
                    $expectedToken = strtoupper(trim((string)$session['token']));
                    $sessionVerified = !empty($_SESSION['pretes_cbt_token_verified_' . $session['id'] . '_' . $registrationId]);
                    if (!$sessionVerified) {
                        if ($tokenClean === '' || $tokenClean !== $expectedToken) {
                            throw new InvalidArgumentException("Token ujian tidak valid. Masukkan token yang diberikan oleh pengawas/proktor ujian.");
                        }
                        $_SESSION['pretes_cbt_token_verified_' . $session['id'] . '_' . $registrationId] = true;
                    }
                }
            }

            // Tentukan Paket Soal Sesi
            $packageIds = [];
            if ($session && !empty($session['package_ids_json'])) {
                $decoded = json_decode($session['package_ids_json'], true);
                if (is_array($decoded) && !empty($decoded)) {
                    $packageIds = array_map('intval', $decoded);
                }
            }
            if (empty($packageIds)) {
                $activePkg = pretesCbtGetActivePackage($pdo);
                $packageIds = $activePkg ? [(int)$activePkg['id']] : [1];
            }

            $firstPkg = !empty($packageIds) ? pretesCbtGetPackage($pdo, (int)$packageIds[0]) : null;

            // Tentukan Durasi Ujian
            $durationMinutes = 30;
            if ($session && !empty($session['duration_minutes'])) {
                $durationMinutes = (int)$session['duration_minutes'];
            } elseif ($firstPkg && !empty($firstPkg['duration_minutes'])) {
                $durationMinutes = (int)$firstPkg['duration_minutes'];
            }
            $durationSeconds = $durationMinutes * 60;

            // Tentukan Kuota Soal per Kategori (Kadar Kategori)
            $categoryQuotas = ($session && !empty($session['category_quotas_json']))
                ? json_decode($session['category_quotas_json'], true) : [];

            $selected = [];
            if (!empty($categoryQuotas) && is_array($categoryQuotas)) {
                foreach ($categoryQuotas as $cat => $quota) {
                    $quota = (int)$quota;
                    if ($quota <= 0) continue;
                    $placeholders = implode(',', array_fill(0, count($packageIds), '?'));
                    $catStmt = $pdo->prepare("SELECT * FROM pretes_cbt_questions WHERE package_id IN ($placeholders) AND category = ? AND is_active = 1");
                    $catParams = array_merge($packageIds, [$cat]);
                    $catStmt->execute($catParams);
                    $catQuestions = $catStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (count($catQuestions) < $quota) {
                        throw new RuntimeException("Kategori '{$cat}' hanya memiliki " . count($catQuestions) . " soal aktif dari kuota sesi {$quota} soal. Hubungi admin.");
                    }
                    $shuffled = pretesCbtShuffle($catQuestions);
                    $selected = array_merge($selected, array_slice($shuffled, 0, $quota));
                }
                $selected = pretesCbtShuffle($selected);
                $targetCount = count($selected);
            } else {
                $placeholders = implode(',', array_fill(0, count($packageIds), '?'));
                $bankStmt = $pdo->prepare("SELECT * FROM pretes_cbt_questions WHERE package_id IN ($placeholders) AND is_active = 1");
                $bankStmt->execute($packageIds);
                $bank = $bankStmt->fetchAll(PDO::FETCH_ASSOC);

                $firstPkg = pretesCbtGetPackage($pdo, (int)$packageIds[0]);
                $targetCount = $firstPkg && !empty($firstPkg['question_count']) ? (int)$firstPkg['question_count'] : PRETES_CBT_COUNT;
                if (count($bank) < $targetCount) {
                    $pkgDesc = count($packageIds) === 1 && $firstPkg ? (" '" . $firstPkg['name'] . "'") : '';
                    throw new RuntimeException("Bank soal aktif{$pkgDesc} belum mencapai {$targetCount} soal aktif (tersedia " . count($bank) . "). Hubungi admin.");
                }
                $selected = array_slice(pretesCbtShuffle($bank), 0, $targetCount);
            }

            $pdo->prepare('INSERT INTO pretes_cbt_attempts (user_id, registration_id, session_id, started_at, deadline_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$userId, $registrationId, $sessionId, $now, $now + $durationSeconds]);
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
            $itemCount = (int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_items WHERE attempt_id = ' . (int)$attempt['id'])->fetchColumn() ?: PRETES_CBT_COUNT;
            $position = filter_var($input['position'] ?? null, FILTER_VALIDATE_INT);
            $answer = $input['answer'] ?? null;
            if (!$position || $position < 1 || $position > $itemCount || ($answer !== null && !in_array($answer, range('A', 'E'), true))) {
                throw new InvalidArgumentException('Jawaban atau nomor soal tidak valid.');
            }
            $pdo->prepare('UPDATE pretes_cbt_items SET answer = ?, flagged = ? WHERE attempt_id = ? AND position = ?')
                ->execute([$answer, !empty($input['flagged']) ? 1 : 0, $attempt['id'], $position]);
        } elseif ($attempt['submitted_at'] === null && $action === 'flag') {
            $itemCount = (int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_items WHERE attempt_id = ' . (int)$attempt['id'])->fetchColumn() ?: PRETES_CBT_COUNT;
            $position = filter_var($input['position'] ?? null, FILTER_VALIDATE_INT);
            if (!$position || $position < 1 || $position > $itemCount) throw new InvalidArgumentException('Nomor soal tidak valid.');
            $flagged = !empty($input['flagged']) ? 1 : 0;
            $pdo->prepare('UPDATE pretes_cbt_items SET flagged = ? WHERE attempt_id = ? AND position = ?')
                ->execute([$flagged, $attempt['id'], $position]);
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

function pretesCbtSaveQuestion(PDO $pdo, array $input): int {
    ensurePretesCbtTables($pdo);
    $packageId = max(1, (int)($input['package_id'] ?? 1));
    $category = trim((string)($input['category'] ?? ''));
    if ($category === '' || (function_exists('mb_strlen') ? mb_strlen($category, 'UTF-8') : strlen($category)) > 80) {
        throw new InvalidArgumentException('Kategori materi wajib diisi, maksimal 80 karakter.');
    }
    $questionText = trim((string)($input['question_text'] ?? ''));
    if ($questionText === '' || (function_exists('mb_strlen') ? mb_strlen($questionText, 'UTF-8') : strlen($questionText)) > 10000) {
        throw new InvalidArgumentException('Pertanyaan wajib diisi, maksimal 10.000 karakter.');
    }
    $options = [];
    foreach (range('A', 'E') as $letter) {
        $val = trim((string)($input['option_' . $letter] ?? ''));
        if ($val === '' || (function_exists('mb_strlen') ? mb_strlen($val, 'UTF-8') : strlen($val)) > 2000) {
            throw new InvalidArgumentException("Pilihan $letter wajib diisi, maksimal 2.000 karakter.");
        }
        $options[$letter] = $val;
    }
    $correct = strtoupper(trim((string)($input['correct_answer'] ?? '')));
    if (!in_array($correct, range('A', 'E'), true)) {
        throw new InvalidArgumentException('Kunci jawaban harus salah satu dari A, B, C, D, atau E.');
    }
    $isActive = isset($input['is_active']) && (int)$input['is_active'] === 1 ? 1 : 0;
    $id = (int)($input['id'] ?? 0);

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE pretes_cbt_questions SET package_id = ?, category = ?, question_text = ?, options_json = ?, correct_answer = ?, is_active = ? WHERE id = ?');
        $stmt->execute([$packageId, $category, $questionText, json_encode($options, JSON_UNESCAPED_UNICODE), $correct, $isActive, $id]);
        return $id;
    }

    $code = 'Q-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $stmt = $pdo->prepare('INSERT INTO pretes_cbt_questions (code, package_id, category, question_text, options_json, correct_answer, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$code, $packageId, $category, $questionText, json_encode($options, JSON_UNESCAPED_UNICODE), $correct, $isActive]);
    return (int)$pdo->lastInsertId();
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
    $pkg = $pdo->query('SELECT p.*, (SELECT COUNT(*) FROM pretes_cbt_questions q WHERE q.package_id = p.id) AS total_questions, (SELECT COUNT(*) FROM pretes_cbt_questions q WHERE q.package_id = p.id AND q.is_active = 1) AS question_count_actual FROM pretes_cbt_packages p WHERE p.is_active = 1 ORDER BY p.id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
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

// ══════════════════════════════════════════════════════════════════════════
// MODUL MANAJEMEN SESI UJIAN (EXAM SESSIONS)
// ══════════════════════════════════════════════════════════════════════════

function pretesCbtGenerateToken(int $length = 6): string {
    $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $len = strlen($chars);
    $token = '';
    for ($i = 0; $i < $length; $i++) {
        $token .= $chars[random_int(0, $len - 1)];
    }
    return $token;
}

function pretesCbtGetSessions(PDO $pdo): array {
    ensurePretesCbtTables($pdo);
    $sessions = $pdo->query('SELECT * FROM pretes_cbt_sessions ORDER BY is_active DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    $packages = pretesCbtGetPackages($pdo);
    $pkgMap = [];
    foreach ($packages as $pkg) {
        $pkgMap[(int)$pkg['id']] = $pkg;
    }

    foreach ($sessions as &$ses) {
        $pkgIds = json_decode((string)($ses['package_ids_json'] ?? '[]'), true) ?: [];
        $ses['package_ids'] = array_map('intval', $pkgIds);
        $ses['category_quotas'] = json_decode((string)($ses['category_quotas_json'] ?? '[]'), true) ?: [];

        $packageNames = [];
        $totalQuestions = 0;
        $activeQuestions = 0;
        foreach ($ses['package_ids'] as $pid) {
            if (isset($pkgMap[$pid])) {
                $packageNames[] = $pkgMap[$pid]['name'];
                $totalQuestions += (int)$pkgMap[$pid]['total_questions'];
                $activeQuestions += (int)$pkgMap[$pid]['question_count_actual'];
            }
        }
        $ses['package_names'] = $packageNames;
        $ses['total_available_questions'] = $totalQuestions;
        $ses['active_available_questions'] = $activeQuestions;

        // Target question count: sum of quotas or default
        if (!empty($ses['category_quotas'])) {
            $ses['target_questions'] = array_sum($ses['category_quotas']);
        } else {
            $firstPid = $ses['package_ids'][0] ?? 0;
            $ses['target_questions'] = isset($pkgMap[$firstPid]) ? (int)$pkgMap[$firstPid]['question_count'] : PRETES_CBT_COUNT;
        }
    }
    unset($ses);
    return $sessions;
}

function pretesCbtGetSession(PDO $pdo, int $sessionId): ?array {
    if ($sessionId <= 0) return null;
    ensurePretesCbtTables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM pretes_cbt_sessions WHERE id = ?');
    $stmt->execute([$sessionId]);
    $ses = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ses) return null;

    $pkgIds = json_decode((string)($ses['package_ids_json'] ?? '[]'), true) ?: [];
    $ses['package_ids'] = array_map('intval', $pkgIds);
    $ses['category_quotas'] = json_decode((string)($ses['category_quotas_json'] ?? '[]'), true) ?: [];
    return $ses;
}

function pretesCbtGetActiveSession(PDO $pdo): ?array {
    ensurePretesCbtTables($pdo);
    $ses = $pdo->query('SELECT * FROM pretes_cbt_sessions WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$ses) return null;
    $pkgIds = json_decode((string)($ses['package_ids_json'] ?? '[]'), true) ?: [];
    $ses['package_ids'] = array_map('intval', $pkgIds);
    $ses['category_quotas'] = json_decode((string)($ses['category_quotas_json'] ?? '[]'), true) ?: [];
    return $ses;
}

function pretesCbtSaveSession(PDO $pdo, array $input): int {
    ensurePretesCbtTables($pdo);
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('Nama sesi ujian wajib diisi.');
    }
    if (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') > 150 : strlen($title) > 150) {
        throw new InvalidArgumentException('Nama sesi ujian maksimal 150 karakter.');
    }

    $description = trim((string)($input['description'] ?? ''));

    // Paket Soal yang Digunakan (bisa array atau single)
    $pkgIds = [];
    if (!empty($input['package_ids'])) {
        $raw = is_array($input['package_ids']) ? $input['package_ids'] : [$input['package_ids']];
        foreach ($raw as $val) {
            $val = (int)$val;
            if ($val > 0) $pkgIds[] = $val;
        }
    }
    if (empty($pkgIds)) {
        throw new InvalidArgumentException('Pilih minimal satu paket soal untuk sesi ini.');
    }
    $packageIdsJson = json_encode(array_values(array_unique($pkgIds)));

    // Kadar / Kuota per Kategori
    $categoryQuotasJson = null;
    if (!empty($input['category_quotas']) && is_array($input['category_quotas'])) {
        $cleanQuotas = [];
        foreach ($input['category_quotas'] as $cat => $quota) {
            $cat = trim((string)$cat);
            $quota = (int)$quota;
            if ($cat !== '' && $quota > 0) {
                $cleanQuotas[$cat] = $quota;
            }
        }
        if (!empty($cleanQuotas)) {
            $categoryQuotasJson = json_encode($cleanQuotas, JSON_UNESCAPED_UNICODE);
        }
    }

    // Kewajiban Kamera
    $cameraMode = trim((string)($input['camera_mode'] ?? 'wajib'));
    if (!in_array($cameraMode, ['wajib', 'opsional', 'tidak_aktif'], true)) {
        $cameraMode = 'wajib';
    }

    // Waktu (Durasi Menit)
    $duration = max(5, min(360, (int)($input['duration_minutes'] ?? 30)));

    // Cara Menilai
    $scoringMethod = trim((string)($input['scoring_method'] ?? 'standar'));
    if (!in_array($scoringMethod, ['standar', 'penalti'], true)) {
        $scoringMethod = 'standar';
    }
    $passingGrade = max(0.0, min(100.0, (float)($input['passing_grade'] ?? 65.0)));

    // Waktu Mulai & Waktu Selesai (Window)
    $startTime = trim((string)($input['start_time'] ?? ''));
    if ($startTime !== '') {
        $ts = strtotime($startTime);
        if ($ts === false) throw new InvalidArgumentException('Format waktu mulai ujian tidak valid.');
        $startTime = date('Y-m-d H:i', $ts);
    } else {
        $startTime = null;
    }

    $endTime = trim((string)($input['end_time'] ?? ''));
    if ($endTime !== '') {
        $ts = strtotime($endTime);
        if ($ts === false) throw new InvalidArgumentException('Format waktu akhir ujian tidak valid.');
        $endTime = date('Y-m-d H:i', $ts);
    } else {
        $endTime = null;
    }

    if ($startTime && $endTime && strtotime($startTime) >= strtotime($endTime)) {
        throw new InvalidArgumentException('Waktu mulai harus lebih awal daripada waktu batas akhir ujian.');
    }

    // Token Ujian
    $token = strtoupper(trim((string)($input['token'] ?? '')));
    if ($token === '') {
        $token = pretesCbtGenerateToken(6);
    }
    $tokenRequired = isset($input['token_required']) && (int)$input['token_required'] === 1 ? 1 : 0;
    $isActive = isset($input['is_active']) && (int)$input['is_active'] === 1 ? 1 : 0;
    $sessionId = (int)($input['id'] ?? 0);

    $pdo->beginTransaction();
    try {
        if ($isActive === 1) {
            $pdo->exec('UPDATE pretes_cbt_sessions SET is_active = 0');
        } else {
            $otherCount = (int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_sessions WHERE id != ' . $sessionId)->fetchColumn();
            if ($otherCount === 0) {
                $isActive = 1;
            }
        }

        if ($sessionId > 0) {
            $stmt = $pdo->prepare('UPDATE pretes_cbt_sessions SET title = ?, description = ?, package_ids_json = ?, category_quotas_json = ?, camera_mode = ?, duration_minutes = ?, scoring_method = ?, passing_grade = ?, start_time = ?, end_time = ?, token = ?, token_required = ?, is_active = ? WHERE id = ?');
            $stmt->execute([
                $title, $description !== '' ? $description : null, $packageIdsJson, $categoryQuotasJson,
                $cameraMode, $duration, $scoringMethod, $passingGrade, $startTime, $endTime,
                $token, $tokenRequired, $isActive, $sessionId
            ]);
        } else {
            $code = trim((string)($input['code'] ?? ''));
            if ($code === '') {
                $code = 'SESI-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            }
            $stmt = $pdo->prepare('INSERT INTO pretes_cbt_sessions (code, title, description, package_ids_json, category_quotas_json, camera_mode, duration_minutes, scoring_method, passing_grade, start_time, end_time, token, token_required, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $code, $title, $description !== '' ? $description : null, $packageIdsJson, $categoryQuotasJson,
                $cameraMode, $duration, $scoringMethod, $passingGrade, $startTime, $endTime,
                $token, $tokenRequired, $isActive, time()
            ]);
            $sessionId = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        return $sessionId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function pretesCbtActivateSession(PDO $pdo, int $sessionId): bool {
    if ($sessionId <= 0) return false;
    ensurePretesCbtTables($pdo);
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE pretes_cbt_sessions SET is_active = 0');
        $stmt = $pdo->prepare('UPDATE pretes_cbt_sessions SET is_active = 1 WHERE id = ?');
        $stmt->execute([$sessionId]);
        $ok = $stmt->rowCount() > 0;
        $pdo->commit();
        return $ok;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function pretesCbtDeleteSession(PDO $pdo, int $sessionId): bool {
    if ($sessionId <= 0) return false;
    ensurePretesCbtTables($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM pretes_cbt_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $ok = $stmt->rowCount() > 0;

        $activeCount = (int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_sessions WHERE is_active = 1')->fetchColumn();
        if ($activeCount === 0) {
            $firstSes = $pdo->query('SELECT id FROM pretes_cbt_sessions ORDER BY id ASC LIMIT 1')->fetchColumn();
            if ($firstSes) {
                $pdo->prepare('UPDATE pretes_cbt_sessions SET is_active = 1 WHERE id = ?')->execute([(int)$firstSes]);
            }
        }

        $pdo->commit();
        return $ok;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// ══════════════════════════════════════════════════════════════════════════
// ATTEMPTS DELETE
// ══════════════════════════════════════════════════════════════════════════

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
