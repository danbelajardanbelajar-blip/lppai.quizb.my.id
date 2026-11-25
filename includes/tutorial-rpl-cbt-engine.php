<?php
/**
 * LPPAI Corner - Engine CBT & Proctoring Tutorial RPL Online
 * Berparitas penuh dengan CBT Pretes (includes/pretes-cbt.php & includes/pretes-cbt-interruption.php).
 */

require_once __DIR__ . '/tutorial-rpl-helper.php';

if (!defined('TUTORIAL_RPL_CBT_COUNT')) {
    define('TUTORIAL_RPL_CBT_COUNT', 25);
}
if (!defined('TUTORIAL_RPL_CBT_SECONDS')) {
    define('TUTORIAL_RPL_CBT_SECONDS', 90 * 60);
}

function ensureTutorialRplCbtProctoringTables(PDO $pdo): void {
    ensureTutorialRplCbtTables($pdo);
    static $done = [];
    $key = spl_object_id($pdo);
    if (!empty($done[$key])) return;
    $done[$key] = true;

    $isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $idType = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $engine = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_cbt_interruptions (
        attempt_id INTEGER PRIMARY KEY,
        revision INTEGER NOT NULL DEFAULT 0,
        paused_at INTEGER DEFAULT NULL,
        resume_position INTEGER NOT NULL DEFAULT 1,
        reason VARCHAR(40) DEFAULT NULL,
        event_id VARCHAR(80) DEFAULT NULL
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_cbt_interruption_events (
        attempt_id INTEGER NOT NULL,
        event_id VARCHAR(80) NOT NULL,
        reason VARCHAR(40) NOT NULL,
        interrupted_position INTEGER NOT NULL,
        paused_at INTEGER NOT NULL,
        resumed_at INTEGER DEFAULT NULL,
        PRIMARY KEY(attempt_id, event_id)
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_proctoring_logs (
        id $idType,
        member_id INT NOT NULL,
        class_id INT NOT NULL DEFAULT 0,
        user_id INT NOT NULL,
        event_type VARCHAR(40) NOT NULL,
        similarity_score DECIMAL(6,2) DEFAULT NULL,
        status VARCHAR(40) NOT NULL,
        snapshot_path VARCHAR(255) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )$engine");
}

function tutorialRplCbtShuffle(array $values): array {
    $values = array_values($values);
    for ($i = count($values) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
    }
    return $values;
}

function tutorialRplCbtTiming(PDO $pdo, array $attempt): array {
    $stmt = $pdo->prepare('SELECT * FROM tutorial_rpl_cbt_interruptions WHERE attempt_id = ?');
    $stmt->execute([$attempt['id']]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return $attempt + [
        'paused_at' => $state['paused_at'] ?? null,
        'revision' => (int)($state['revision'] ?? 0),
        'resume_position' => (int)($state['resume_position'] ?? 1),
        'pause_reason' => $state['reason'] ?? null,
    ];
}

function tutorialRplCbtClock(array $attempt, int $now): int {
    return $attempt['paused_at'] !== null ? (int)$attempt['paused_at'] : $now;
}

function tutorialRplCbtLock(PDO $pdo, int $userId, int $classId): ?array {
    $sql = 'SELECT * FROM tutorial_rpl_cbt_attempts WHERE user_id = ? AND class_id = ?';
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $classId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
    return $attempt ? tutorialRplCbtTiming($pdo, $attempt) : null;
}

function tutorialRplCbtGetActiveSessions(PDO $pdo): array {
    $all = tutorialRplCbtGetSessions($pdo);
    $active = [];
    foreach ($all as $s) {
        $mode = $s['mode'] ?? 'resmi';
        if (!empty($s['is_active']) && ($mode === '' || $mode === 'resmi')) {
            $active[] = $s;
        }
    }
    if (empty($active) && !empty($all)) {
        $active[] = $all[0];
    }
    return $active;
}

function tutorialRplCbtGetActiveSession(PDO $pdo, ?int $preferredSessionId = null): array {
    if ($preferredSessionId !== null && $preferredSessionId > 0) {
        $s = tutorialRplCbtGetSession($pdo, $preferredSessionId);
        if (!empty($s['id'])) return $s;
    }
    $active = tutorialRplCbtGetActiveSessions($pdo);
    return $active[0] ?? tutorialRplCbtGetSession($pdo);
}

function tutorialRplCbtFinalize(PDO $pdo, array $attempt, int $now): array {
    if (!empty($attempt['submitted']) && $attempt['submitted_at'] !== null) {
        return $attempt;
    }

    $stmtItems = $pdo->prepare('SELECT answer, correct_answer FROM tutorial_rpl_cbt_items WHERE attempt_id = ?');
    $stmtItems->execute([$attempt['id']]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    $totalQ = count($items) > 0 ? count($items) : TUTORIAL_RPL_CBT_COUNT;

    $correct = 0;
    $wrong = 0;
    foreach ($items as $item) {
        $chosen = strtoupper(trim((string)($item['answer'] ?? '')));
        $key = strtoupper(trim((string)($item['correct_answer'] ?? '')));
        if ($chosen === '') {
            continue;
        } elseif ($chosen === $key) {
            $correct++;
        } else {
            $wrong++;
        }
    }

    $session = !empty($attempt['session_id']) ? tutorialRplCbtGetSession($pdo, (int)$attempt['session_id']) : tutorialRplCbtGetSession($pdo);
    $scoringMethod = $session['scoring_method'] ?? 'standar';

    if ($scoringMethod === 'penalti') {
        $rawScore = ($correct * 4) - ($wrong * 1);
        $maxRaw = $totalQ * 4;
        $score = $maxRaw > 0 ? round(max(0, $rawScore) / $maxRaw * 100, 2) : 0.00;
    } else {
        $score = round($correct / $totalQ * 100, 2);
    }

    $submittedAt = min($now, (int)($attempt['deadline_at'] ?: $now));
    $pdo->prepare('UPDATE tutorial_rpl_cbt_attempts SET submitted_at = ?, submitted = 1, correct_count = ?, total_questions = ?, score = ? WHERE id = ? AND (submitted = 0 OR submitted_at IS NULL)')
        ->execute([$submittedAt, $correct, $totalQ, $score, $attempt['id']]);

    $attempt['submitted_at'] = $submittedAt;
    $attempt['submitted'] = 1;
    $attempt['correct_count'] = $correct;
    $attempt['total_questions'] = $totalQ;
    $attempt['score'] = $score;

    // Sinkronisasi nilai ke tutorial_rpl_members
    $userId = (int)$attempt['user_id'];
    $classId = (int)$attempt['class_id'];
    if ($classId > 0) {
        $passingGrade = (float)($session['passing_grade'] ?? 70.00);
        $stmtM = $pdo->prepare('SELECT id, nilai_praktik FROM tutorial_rpl_members WHERE user_id = ? AND class_id = ?');
        $stmtM->execute([$userId, $classId]);
        $mem = $stmtM->fetch(PDO::FETCH_ASSOC);
        if ($mem) {
            $nilaiPraktik = ($mem['nilai_praktik'] !== null) ? (float)$mem['nilai_praktik'] : null;
            $nilaiAkhir = ($nilaiPraktik !== null) ? round(($score + $nilaiPraktik) / 2.0, 2) : $score;
            $statusLulus = ($nilaiAkhir >= $passingGrade) ? 'lulus' : 'tidak_lulus';
            $pdo->prepare('UPDATE tutorial_rpl_members SET exam_submitted = 1, nilai_ujian_tulis = ?, nilai_akhir = ?, status_lulus = ? WHERE id = ?')
                ->execute([$score, $nilaiAkhir, $statusLulus, (int)$mem['id']]);
        }
    }

    return $attempt;
}

function tutorialRplCbtPublic(PDO $pdo, ?array $attempt, int $now): array {
    if (!$attempt) {
        return ['attempt' => null, 'question_count' => TUTORIAL_RPL_CBT_COUNT, 'duration_seconds' => TUTORIAL_RPL_CBT_SECONDS];
    }
    $submitted = !empty($attempt['submitted']) || $attempt['submitted_at'] !== null;
    $items = [];
    $stmt = $pdo->prepare('SELECT position, question_text, options_json, answer, flagged FROM tutorial_rpl_cbt_items WHERE attempt_id = ? ORDER BY position');
    $stmt->execute([$attempt['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'position' => (int)$row['position'],
            'text' => $row['question_text'],
            'options' => json_decode((string)$row['options_json'], true),
            'answer' => $row['answer'],
            'flagged' => (bool)$row['flagged'],
        ];
    }
    $answered = count(array_filter($items, static fn($item) => $item['answer'] !== null && $item['answer'] !== ''));

    $sessionTitle = null;
    if (!empty($attempt['session_id'])) {
        $stmtSes = $pdo->prepare('SELECT title FROM tutorial_rpl_cbt_sessions WHERE id = ?');
        $stmtSes->execute([(int)$attempt['session_id']]);
        $sessionTitle = $stmtSes->fetchColumn() ?: null;
    }

    return ['attempt' => [
        'id' => (int)$attempt['id'],
        'submitted' => $submitted,
        'session_id' => (int)($attempt['session_id'] ?? 0),
        'session_title' => $sessionTitle,
        'remaining_seconds' => max(0, (int)$attempt['deadline_at'] - tutorialRplCbtClock($attempt, $now)),
        'paused' => $attempt['paused_at'] !== null,
        'revision' => (int)($attempt['revision'] ?? 0),
        'resume_position' => (int)($attempt['resume_position'] ?? 1),
        'pause_reason' => $attempt['pause_reason'] ?? null,
        'total' => count($items) ?: TUTORIAL_RPL_CBT_COUNT,
        'answered' => $answered,
        'correct' => $submitted ? (int)$attempt['correct_count'] : null,
        'score' => $submitted ? (float)$attempt['score'] : null,
        'questions' => $submitted ? [] : $items,
    ]];
}

function tutorialRplCbtState(PDO $pdo, int $userId, int $classId, ?int $now = null): array {
    $now ??= time();
    ensureTutorialRplCbtProctoringTables($pdo);
    $pdo->beginTransaction();
    try {
        $attempt = tutorialRplCbtLock($pdo, $userId, $classId);
        if ($attempt && empty($attempt['submitted']) && $attempt['submitted_at'] === null && tutorialRplCbtClock($attempt, $now) >= (int)$attempt['deadline_at']) {
            $attempt = tutorialRplCbtFinalize($pdo, $attempt, $now);
        }
        $result = tutorialRplCbtPublic($pdo, $attempt, $now);
        if (!$attempt) {
            $activeSessions = tutorialRplCbtGetActiveSessions($pdo);
            if (count($activeSessions) > 1) {
                $result['available_sessions'] = array_map(static function ($s) {
                    return [
                        'id' => (int)$s['id'],
                        'code' => $s['code'],
                        'title' => $s['title'],
                        'description' => $s['description'] ?? '',
                        'duration_minutes' => (int)($s['duration_minutes'] ?: 90),
                        'token_required' => !empty($s['token_required']),
                        'package_names' => $s['package_names'] ?? [],
                    ];
                }, $activeSessions);
            }
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tutorialRplCbtStart(PDO $pdo, int $userId, int $classId, ?int $now = null, ?string $token = null, bool $ignoreExamClosed = false, ?int $requestedSessionId = null): array {
    $now ??= time();
    ensureTutorialRplCbtProctoringTables($pdo);
    $pdo->beginTransaction();
    try {
        $attempt = tutorialRplCbtLock($pdo, $userId, $classId);
        if (!$attempt) {
            $session = null;
            if ($requestedSessionId !== null && $requestedSessionId > 0) {
                $session = tutorialRplCbtGetSession($pdo, $requestedSessionId);
                if (empty($session['id']) || empty($session['is_active'])) {
                    throw new InvalidArgumentException('Sesi ujian yang dipilih tidak aktif.');
                }
            } elseif (!empty($token)) {
                $activeSessions = tutorialRplCbtGetActiveSessions($pdo);
                $tokenClean = strtoupper(trim((string)$token));
                foreach ($activeSessions as $as) {
                    if (!empty($as['token']) && strtoupper(trim((string)$as['token'])) === $tokenClean) {
                        $session = $as;
                        break;
                    }
                }
                if (!$session) {
                    $session = tutorialRplCbtGetActiveSession($pdo);
                }
            } else {
                $session = tutorialRplCbtGetActiveSession($pdo);
            }
            $sessionId = (int)($session['id'] ?? 1);

            if ($session && !$ignoreExamClosed && (int)($session['exam_open'] ?? 1) !== 1) {
                throw new RuntimeException('Pengerjaan ujian Tutorial RPL sedang ditutup oleh admin. Silakan tunggu informasi pembukaan ujian.');
            }

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
                if (!empty($session['token_required']) && !empty($session['token'])) {
                    $tokenClean = strtoupper(trim((string)$token));
                    $expectedToken = strtoupper(trim((string)$session['token']));
                    $sessionVerified = !empty($_SESSION['tutorial_rpl_cbt_token_verified_' . $sessionId . '_' . $classId]);
                    if (!$sessionVerified) {
                        if ($tokenClean === '' || $tokenClean !== $expectedToken) {
                            throw new InvalidArgumentException('Token ujian tidak valid. Masukkan token yang diberikan oleh pengawas/proktor ujian.');
                        }
                        $_SESSION['tutorial_rpl_cbt_token_verified_' . $sessionId . '_' . $classId] = true;
                    }
                }
            }

            $packageIds = [];
            if ($session && !empty($session['package_ids_json'])) {
                $decoded = json_decode((string)$session['package_ids_json'], true);
                if (is_array($decoded) && !empty($decoded)) {
                    $packageIds = array_map('intval', $decoded);
                }
            }
            if (empty($packageIds)) {
                $packageIds = [$sessionId === 2 ? 2 : 1];
            }

            $firstPkg = tutorialRplCbtGetPackage($pdo, (int)$packageIds[0]);
            $durationMinutes = 90;
            if ($session && !empty($session['duration_minutes'])) {
                $durationMinutes = (int)$session['duration_minutes'];
            } elseif ($firstPkg && !empty($firstPkg['duration_minutes'])) {
                $durationMinutes = (int)$firstPkg['duration_minutes'];
            }
            $durationSeconds = max(5, $durationMinutes) * 60;

            $categoryQuotas = ($session && !empty($session['category_quotas_json']))
                ? json_decode((string)$session['category_quotas_json'], true) : [];

            $selected = [];
            if (!empty($categoryQuotas) && is_array($categoryQuotas)) {
                foreach ($categoryQuotas as $cat => $quota) {
                    $quota = (int)$quota;
                    if ($quota <= 0) continue;
                    $placeholders = implode(',', array_fill(0, count($packageIds), '?'));
                    $catStmt = $pdo->prepare("SELECT * FROM tutorial_rpl_cbt_questions WHERE package_id IN ($placeholders) AND category = ? AND is_active = 1");
                    $catStmt->execute(array_merge($packageIds, [$cat]));
                    $catQuestions = $catStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (count($catQuestions) < $quota) {
                        throw new RuntimeException("Kategori '{$cat}' hanya memiliki " . count($catQuestions) . " soal aktif dari kuota sesi {$quota} soal. Hubungi admin.");
                    }
                    $shuffled = tutorialRplCbtShuffle($catQuestions);
                    $selected = array_merge($selected, array_slice($shuffled, 0, $quota));
                }
                $selected = tutorialRplCbtShuffle($selected);
                $targetCount = count($selected);
            } else {
                $placeholders = implode(',', array_fill(0, count($packageIds), '?'));
                $bankStmt = $pdo->prepare("SELECT * FROM tutorial_rpl_cbt_questions WHERE package_id IN ($placeholders) AND is_active = 1");
                $bankStmt->execute($packageIds);
                $bank = $bankStmt->fetchAll(PDO::FETCH_ASSOC);

                $targetCount = ($firstPkg && !empty($firstPkg['question_count'])) ? (int)$firstPkg['question_count'] : TUTORIAL_RPL_CBT_COUNT;
                if (count($bank) < $targetCount) {
                    $pkgDesc = count($packageIds) === 1 && $firstPkg ? (" '" . $firstPkg['name'] . "'") : '';
                    throw new RuntimeException("Bank soal aktif{$pkgDesc} belum mencapai {$targetCount} soal aktif (tersedia " . count($bank) . "). Hubungi admin.");
                }
                $selected = array_slice(tutorialRplCbtShuffle($bank), 0, $targetCount);
            }

            $pdo->prepare('INSERT INTO tutorial_rpl_cbt_attempts (user_id, class_id, session_id, package_id, started_at, deadline_at, total_questions, submitted) VALUES (?, ?, ?, ?, ?, ?, ?, 0)')
                ->execute([$userId, $classId, $sessionId, (int)$packageIds[0], $now, $now + $durationSeconds, $targetCount]);
            $attemptId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare('INSERT INTO tutorial_rpl_cbt_items (attempt_id, question_id, position, category, question_text, options_json, correct_answer, answer, flagged) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 0)');
            foreach ($selected as $i => $question) {
                $shuffledOptions=tutorialRplCbtShuffleOptions($question['options_json'],null,(string)$question['correct_answer']);
                $stmt->execute([
                    $attemptId,
                    (int)$question['id'],
                    $i + 1,
                    $question['category'] ?? 'Umum',
                    $question['question_text'],
                    $shuffledOptions['options_json'],
                    $shuffledOptions['correct_answer'],
                ]);
            }
            $attempt = tutorialRplCbtLock($pdo, $userId, $classId);
        }
        if (empty($attempt['submitted']) && $attempt['submitted_at'] === null && tutorialRplCbtClock($attempt, $now) >= (int)$attempt['deadline_at']) {
            $attempt = tutorialRplCbtFinalize($pdo, $attempt, $now);
        }
        $result = tutorialRplCbtPublic($pdo, $attempt, $now);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && in_array((string)$e->getCode(), ['23000', '23505'], true)) {
            return tutorialRplCbtState($pdo, $userId, $classId, $now);
        }
        throw $e;
    }
}

function tutorialRplCbtInterrupt(PDO $pdo, array $attempt, array $input, int $now): array {
    $event = $input['event_id'] ?? '';
    $reason = $input['reason'] ?? '';
    if (!is_string($event) || !preg_match('/^[a-zA-Z0-9_-]{8,80}$/D', $event)
        || !in_array($reason, ['page_hidden', 'camera_unavailable', 'face_missing', 'face_mismatch'], true)) {
        throw new InvalidArgumentException('Peristiwa pengawasan tidak valid.');
    }
    $seen = $pdo->prepare('SELECT 1 FROM tutorial_rpl_cbt_interruption_events WHERE attempt_id = ? AND event_id = ?');
    $seen->execute([$attempt['id'], $event]);
    if ($seen->fetchColumn() || $attempt['paused_at'] !== null) return $attempt;
    if ((int)($input['revision'] ?? 0) !== (int)$attempt['revision']) {
        throw new RuntimeException('Urutan soal berubah. Muat ulang halaman.');
    }
    $stmt = $pdo->prepare('SELECT * FROM tutorial_rpl_cbt_items WHERE attempt_id = ? ORDER BY position');
    $stmt->execute([$attempt['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $position = filter_var($input['position'] ?? null, FILTER_VALIDATE_INT);
    if (!$position || $position < 1 || $position > count($items)) {
        throw new InvalidArgumentException('Nomor soal tidak valid.');
    }
    $pending = $input['pending'] ?? [];
    if (!is_array($pending) || count($pending) > count($items)) {
        throw new InvalidArgumentException('Jawaban tertunda tidak valid.');
    }
    foreach ($pending as $change) {
        if (!is_array($change)) throw new InvalidArgumentException('Jawaban tertunda tidak valid.');
        $p = filter_var($change['position'] ?? null, FILTER_VALIDATE_INT);
        $answer = $change['answer'] ?? null;
        if (!$p || $p < 1 || $p > count($items) || ($answer !== null && !in_array($answer, range('A', 'E'), true))) {
            throw new InvalidArgumentException('Jawaban tertunda tidak valid.');
        }
        $items[$p - 1]['answer'] = $answer;
        $items[$p - 1]['flagged'] = !empty($change['flagged']) ? 1 : 0;
    }
    $current = $position - 1;
    $items[$current]['answer'] = null;

    $session = !empty($attempt['session_id']) ? tutorialRplCbtGetSession($pdo, (int)$attempt['session_id']) : tutorialRplCbtGetActiveSession($pdo);
    $shuffleOnViolation = $session ? (int)($session['shuffle_on_violation'] ?? 1) : 1;

    $shuffleItemOptions = static fn(array $options, ?string $selectedKey, string $correctKey): array
        => tutorialRplCbtShuffleOptions($options,$selectedKey,$correctKey,true);

    if ($shuffleOnViolation === 1) {
        $packageIds = !empty($session['package_ids']) ? array_map('intval', $session['package_ids']) : [];
        if (empty($packageIds) && !empty($items)) {
            $qIds = array_column($items, 'question_id');
            $placeholders = implode(',', array_fill(0, count($qIds), '?'));
            $pkgStmt = $pdo->prepare("SELECT DISTINCT package_id FROM tutorial_rpl_cbt_questions WHERE id IN ($placeholders)");
            $pkgStmt->execute($qIds);
            $packageIds = array_map('intval', $pkgStmt->fetchAll(PDO::FETCH_COLUMN));
        }
        if (empty($packageIds)) $packageIds = [1];

        $usedQuestionIds = array_map('intval', array_column($items, 'question_id'));
        $reserveQuestions = [];
        $categoriesByQid = [];
        if (!empty($usedQuestionIds)) {
            $usedPlaceholders = implode(',', array_fill(0, count($usedQuestionIds), '?'));
            $cStmt = $pdo->prepare("SELECT id, category FROM tutorial_rpl_cbt_questions WHERE id IN ($usedPlaceholders)");
            $cStmt->execute($usedQuestionIds);
            foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $categoriesByQid[(int)$row['id']] = (string)$row['category'];
            }
            $pkgPlaceholders = implode(',', array_fill(0, count($packageIds), '?'));
            $reserveStmt = $pdo->prepare("SELECT * FROM tutorial_rpl_cbt_questions WHERE package_id IN ($pkgPlaceholders) AND id NOT IN ($usedPlaceholders) AND is_active = 1");
            $reserveStmt->execute(array_merge($packageIds, $usedQuestionIds));
            $reserveQuestions = $reserveStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!empty($reserveQuestions)) {
            $reserveByCategory = [];
            $shuffledReserve = tutorialRplCbtShuffle($reserveQuestions);
            foreach ($shuffledReserve as $rq) {
                $reserveByCategory[(string)($rq['category'] ?? '')][] = $rq;
            }
            $popReserve = static function (?string $prefCategory) use (&$reserveByCategory, &$shuffledReserve): ?array {
                if ($prefCategory !== null && !empty($reserveByCategory[$prefCategory])) {
                    $q = array_pop($reserveByCategory[$prefCategory]);
                    foreach ($shuffledReserve as $idx => $r) {
                        if ((int)$r['id'] === (int)$q['id']) { unset($shuffledReserve[$idx]); break; }
                    }
                    return $q;
                }
                while (!empty($shuffledReserve)) {
                    $q = array_pop($shuffledReserve);
                    $cat = (string)($q['category'] ?? '');
                    if (!empty($reserveByCategory[$cat])) {
                        foreach ($reserveByCategory[$cat] as $idx => $r) {
                            if ((int)$r['id'] === (int)$q['id']) { unset($reserveByCategory[$cat][$idx]); break; }
                        }
                    }
                    return $q;
                }
                return null;
            };

            foreach ($items as &$item) {
                $isAnswered = ($item['answer'] !== null && $item['answer'] !== '');
                if ($isAnswered) {
                    $oldOpts = json_decode((string)$item['options_json'], true) ?: [];
                    $sh = $shuffleItemOptions($oldOpts, $item['answer'], $item['correct_answer']);
                    $item['options_json'] = $sh['options_json'];
                    $item['answer'] = $sh['answer'];
                    $item['correct_answer'] = $sh['correct_answer'];
                } else {
                    $prefCat = $categoriesByQid[(int)$item['question_id']] ?? null;
                    $spare = $popReserve($prefCat);
                    if ($spare) {
                        $item['question_id'] = (int)$spare['id'];
                        $item['category'] = $spare['category'] ?? 'Umum';
                        $item['question_text'] = $spare['question_text'];
                        $spareOpts = json_decode((string)$spare['options_json'], true) ?: [];
                        $sh = $shuffleItemOptions($spareOpts, null, $spare['correct_answer']);
                        $item['options_json'] = $sh['options_json'];
                        $item['correct_answer'] = $sh['correct_answer'];
                        $item['answer'] = null;
                        $item['flagged'] = 0;
                    } else {
                        $curOpts = json_decode((string)$item['options_json'], true) ?: [];
                        $sh = $shuffleItemOptions($curOpts, null, $item['correct_answer']);
                        $item['options_json'] = $sh['options_json'];
                        $item['correct_answer'] = $sh['correct_answer'];
                        $item['answer'] = null;
                    }
                }
            }
            unset($item);

            $candidates = [];
            foreach ($items as $i => $it) if ($i !== $current && $it['answer'] === null) $candidates[] = $i;
            if ($candidates) {
                $other = $candidates[random_int(0, count($candidates) - 1)];
                [$items[$current], $items[$other]] = [$items[$other], $items[$current]];
            }
        } else {
            $options = json_decode((string)$items[$current]['options_json'], true) ?: [];
            $sh = $shuffleItemOptions($options, null, $items[$current]['correct_answer']);
            $items[$current]['options_json'] = $sh['options_json'];
            $items[$current]['correct_answer'] = $sh['correct_answer'];

            $candidates = [];
            foreach ($items as $i => $item) if ($i !== $current && $item['answer'] === null) $candidates[] = $i;
            if ($candidates) {
                $other = $candidates[random_int(0, count($candidates) - 1)];
                [$items[$current], $items[$other]] = [$items[$other], $items[$current]];
            }
        }
    } else {
        $options = json_decode((string)$items[$current]['options_json'], true) ?: [];
        $sh = $shuffleItemOptions($options, null, $items[$current]['correct_answer']);
        $items[$current]['options_json'] = $sh['options_json'];
        $items[$current]['correct_answer'] = $sh['correct_answer'];

        $candidates = [];
        foreach ($items as $i => $item) if ($i !== $current && $item['answer'] === null) $candidates[] = $i;
        if ($candidates) {
            $other = $candidates[random_int(0, count($candidates) - 1)];
            [$items[$current], $items[$other]] = [$items[$other], $items[$current]];
        }
    }

    $update = $pdo->prepare('UPDATE tutorial_rpl_cbt_items SET question_id = ?, category = ?, question_text = ?, options_json = ?, correct_answer = ?, answer = ?, flagged = ? WHERE attempt_id = ? AND position = ?');
    foreach ($items as $i => $item) {
        $update->execute([
            $item['question_id'],
            $item['category'] ?? 'Umum',
            $item['question_text'],
            $item['options_json'],
            $item['correct_answer'],
            $item['answer'],
            $item['flagged'],
            $attempt['id'],
            $i + 1,
        ]);
    }

    $revision = (int)$attempt['revision'] + 1;
    $pdo->prepare('DELETE FROM tutorial_rpl_cbt_interruptions WHERE attempt_id = ?')->execute([$attempt['id']]);
    $pdo->prepare('INSERT INTO tutorial_rpl_cbt_interruptions (attempt_id, revision, paused_at, resume_position, reason, event_id) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$attempt['id'], $revision, $now, $position, $reason, $event]);
    $pdo->prepare('INSERT INTO tutorial_rpl_cbt_interruption_events (attempt_id, event_id, reason, interrupted_position, paused_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$attempt['id'], $event, $reason, $position, $now]);
    return array_replace($attempt, ['paused_at' => $now, 'revision' => $revision, 'resume_position' => $position, 'pause_reason' => $reason]);
}

function tutorialRplCbtResume(PDO $pdo, array $attempt, int $now): array {
    if ($attempt['paused_at'] === null) return $attempt;
    $deadline = (int)$attempt['deadline_at'] + max(0, $now - (int)$attempt['paused_at']);
    $pdo->prepare('UPDATE tutorial_rpl_cbt_attempts SET deadline_at = ? WHERE id = ?')->execute([$deadline, $attempt['id']]);
    $pdo->prepare('UPDATE tutorial_rpl_cbt_interruption_events SET resumed_at = ? WHERE attempt_id = ? AND resumed_at IS NULL')->execute([$now, $attempt['id']]);
    $pdo->prepare('UPDATE tutorial_rpl_cbt_interruptions SET paused_at = NULL WHERE attempt_id = ?')->execute([$attempt['id']]);
    return array_replace($attempt, ['deadline_at' => $deadline, 'paused_at' => null]);
}

function tutorialRplCbtChange(PDO $pdo, int $userId, int $classId, string $action, array $input, ?int $now = null): array {
    $now ??= time();
    ensureTutorialRplCbtProctoringTables($pdo);
    $pdo->beginTransaction();
    try {
        $attempt = tutorialRplCbtLock($pdo, $userId, $classId);
        if (!$attempt) throw new RuntimeException('Mulai ujian terlebih dahulu.');
        if (empty($attempt['submitted']) && $attempt['submitted_at'] === null && tutorialRplCbtClock($attempt, $now) >= (int)$attempt['deadline_at']) {
            $attempt = tutorialRplCbtFinalize($pdo, $attempt, $now);
        }
        $isDone = !empty($attempt['submitted']) || $attempt['submitted_at'] !== null;
        if (!$isDone && $action === 'interrupt') {
            $attempt = tutorialRplCbtInterrupt($pdo, $attempt, $input, $now);
        } elseif (!$isDone && $action === 'resume') {
            $attempt = tutorialRplCbtResume($pdo, $attempt, $now);
        } elseif (!$isDone) {
            if ($attempt['paused_at'] !== null) throw new RuntimeException('Ujian dijeda. Tutup popup melalui tombol Lanjutkan Ujian.');
            if ((int)($input['revision'] ?? 0) !== (int)$attempt['revision']) throw new RuntimeException('Urutan soal berubah. Muat ulang halaman.');
        }

        if (!$isDone && $action === 'save') {
            $itemCount = (int)$pdo->query('SELECT COUNT(*) FROM tutorial_rpl_cbt_items WHERE attempt_id = ' . (int)$attempt['id'])->fetchColumn() ?: TUTORIAL_RPL_CBT_COUNT;
            $position = filter_var($input['position'] ?? null, FILTER_VALIDATE_INT);
            $answer = $input['answer'] ?? null;
            if (!$position || $position < 1 || $position > $itemCount || ($answer !== null && !in_array($answer, range('A', 'E'), true))) {
                throw new InvalidArgumentException('Jawaban atau nomor soal tidak valid.');
            }
            $pdo->prepare('UPDATE tutorial_rpl_cbt_items SET answer = ?, flagged = ?, updated_at = ? WHERE attempt_id = ? AND position = ?')
                ->execute([$answer, !empty($input['flagged']) ? 1 : 0, $now, $attempt['id'], $position]);
        } elseif (!$isDone && $action === 'flag') {
            $itemCount = (int)$pdo->query('SELECT COUNT(*) FROM tutorial_rpl_cbt_items WHERE attempt_id = ' . (int)$attempt['id'])->fetchColumn() ?: TUTORIAL_RPL_CBT_COUNT;
            $position = filter_var($input['position'] ?? null, FILTER_VALIDATE_INT);
            if (!$position || $position < 1 || $position > $itemCount) throw new InvalidArgumentException('Nomor soal tidak valid.');
            $flagged = !empty($input['flagged']) ? 1 : 0;
            $pdo->prepare('UPDATE tutorial_rpl_cbt_items SET flagged = ? WHERE attempt_id = ? AND position = ?')
                ->execute([$flagged, $attempt['id'], $position]);
        } elseif ($action === 'submit') {
            $attempt = tutorialRplCbtFinalize($pdo, $attempt, $now);
        }

        $result = tutorialRplCbtPublic($pdo, $attempt, $now);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tutorialRplPeriodicSnapshotNeeded(PDO $pdo, int $memberId, bool $matched): bool {
    if (!$matched) return true;
    $cutoff = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? "datetime('now', '-5 minutes')" : 'DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 5 MINUTE)';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tutorial_rpl_proctoring_logs WHERE member_id = ? AND status = 'MATCH' AND snapshot_path IS NOT NULL AND created_at >= $cutoff");
    $stmt->execute([$memberId]);
    return (int)$stmt->fetchColumn() === 0;
}
