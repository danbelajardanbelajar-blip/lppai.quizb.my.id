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

    // Ambil konfigurasi sesi ujian untuk proteksi anti-contek
    $session = null;
    if (!empty($attempt['session_id'])) {
        $session = pretesCbtGetSession($pdo, (int)$attempt['session_id']);
    }
    if (!$session) {
        $session = pretesCbtGetActiveSession($pdo);
    }
    $shuffleOnViolation = $session ? (int)($session['shuffle_on_violation'] ?? 1) : 1;

    // Helper untuk mengacak abjad pilihan A-E dan memetakan kunci jawaban/pilihan peserta secara presisi
    $shuffleItemOptions = function(array $options, ?string $selectedKey, string $correctKey): array {
        $selectedText = $selectedKey !== null && isset($options[$selectedKey]) ? $options[$selectedKey] : null;
        $correctText = $options[$correctKey] ?? null;

        $keys = array_keys($options);
        $order = pretesCbtShuffle($keys);
        if ($order === $keys && count($keys) > 1) {
            $order = array_merge(array_slice($order, 1), [$order[0]]);
        }

        $newOptions = [];
        $newAnswer = null;
        $newCorrect = '';
        foreach ($order as $idx => $oldKey) {
            $letter = chr(65 + $idx);
            $val = $options[$oldKey];
            $newOptions[$letter] = $val;
            if ($selectedText !== null && $val === $selectedText) {
                $newAnswer = $letter;
            }
            if ($correctText !== null && $val === $correctText) {
                $newCorrect = $letter;
            }
        }
        if ($newCorrect === '' && !empty($order)) {
            $newCorrect = 'A';
        }
        return [
            'options_json' => json_encode($newOptions, JSON_UNESCAPED_UNICODE),
            'answer' => $newAnswer,
            'correct_answer' => $newCorrect
        ];
    };

    if ($shuffleOnViolation === 1) {
        $packageIds = [];
        if ($session && !empty($session['package_ids'])) {
            $packageIds = array_map('intval', $session['package_ids']);
        }
        if (empty($packageIds) && !empty($items)) {
            $qIds = array_column($items, 'question_id');
            $placeholders = implode(',', array_fill(0, count($qIds), '?'));
            $pkgStmt = $pdo->prepare("SELECT DISTINCT package_id FROM pretes_cbt_questions WHERE id IN ($placeholders)");
            $pkgStmt->execute($qIds);
            $packageIds = array_map('intval', $pkgStmt->fetchAll(PDO::FETCH_COLUMN));
        }
        if (empty($packageIds)) {
            $packageIds = [1];
        }

        $usedQuestionIds = array_map('intval', array_column($items, 'question_id'));

        $reserveQuestions = [];
        $categoriesByQid = [];
        if (!empty($usedQuestionIds)) {
            $usedPlaceholders = implode(',', array_fill(0, count($usedQuestionIds), '?'));
            $cStmt = $pdo->prepare("SELECT id, category FROM pretes_cbt_questions WHERE id IN ($usedPlaceholders)");
            $cStmt->execute($usedQuestionIds);
            foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $categoriesByQid[(int)$row['id']] = (string)$row['category'];
            }

            $pkgPlaceholders = implode(',', array_fill(0, count($packageIds), '?'));
            $reserveStmt = $pdo->prepare("SELECT * FROM pretes_cbt_questions WHERE package_id IN ($pkgPlaceholders) AND id NOT IN ($usedPlaceholders) AND is_active = 1");
            $reserveStmt->execute(array_merge($packageIds, $usedQuestionIds));
            $reserveQuestions = $reserveStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!empty($reserveQuestions)) {
            // Terdapat bank soal cadangan:
            // 1. Soal yang sudah dijawab: pertanyaan tetap, abjad opsi diacak (jawaban peserta & skor tetap 100% aman)
            // 2. Soal yang belum dijawab: diganti soal baru dari bank cadangan (proporsional per kategori bila tersedia)
            $reserveByCategory = [];
            $shuffledReserve = pretesCbtShuffle($reserveQuestions);
            foreach ($shuffledReserve as $rq) {
                $cat = (string)($rq['category'] ?? '');
                $reserveByCategory[$cat][] = $rq;
            }

            $popReserve = function(?string $prefCategory) use (&$reserveByCategory, &$shuffledReserve): ?array {
                if ($prefCategory !== null && !empty($reserveByCategory[$prefCategory])) {
                    $q = array_pop($reserveByCategory[$prefCategory]);
                    foreach ($shuffledReserve as $idx => $r) {
                        if ((int)$r['id'] === (int)$q['id']) {
                            unset($shuffledReserve[$idx]);
                            break;
                        }
                    }
                    return $q;
                }
                while (!empty($shuffledReserve)) {
                    $q = array_pop($shuffledReserve);
                    $cat = (string)($q['category'] ?? '');
                    if (!empty($reserveByCategory[$cat])) {
                        foreach ($reserveByCategory[$cat] as $idx => $r) {
                            if ((int)$r['id'] === (int)$q['id']) {
                                unset($reserveByCategory[$cat][$idx]);
                                break;
                            }
                        }
                    }
                    return $q;
                }
                return null;
            };

            foreach ($items as $i => &$item) {
                $isAnswered = ($item['answer'] !== null && $item['answer'] !== '');
                if ($isAnswered) {
                    $oldOpts = json_decode($item['options_json'], true) ?: [];
                    $sh = $shuffleItemOptions($oldOpts, $item['answer'], $item['correct_answer']);
                    $item['options_json'] = $sh['options_json'];
                    $item['answer'] = $sh['answer'];
                    $item['correct_answer'] = $sh['correct_answer'];
                } else {
                    $prefCat = $categoriesByQid[(int)$item['question_id']] ?? null;
                    $spare = $popReserve($prefCat);
                    if ($spare) {
                        $item['question_id'] = (int)$spare['id'];
                        $item['question_text'] = $spare['question_text'];
                        $spareOpts = json_decode($spare['options_json'], true) ?: [];
                        $sh = $shuffleItemOptions($spareOpts, null, $spare['correct_answer']);
                        $item['options_json'] = $sh['options_json'];
                        $item['correct_answer'] = $sh['correct_answer'];
                        $item['answer'] = null;
                        $item['flagged'] = 0;
                    } else {
                        $curOpts = json_decode($item['options_json'], true) ?: [];
                        $sh = $shuffleItemOptions($curOpts, null, $item['correct_answer']);
                        $item['options_json'] = $sh['options_json'];
                        $item['correct_answer'] = $sh['correct_answer'];
                        $item['answer'] = null;
                    }
                }
            }
            unset($item);

            // Relokasi posisi soal aktif yang terinterupsi ke posisi soal belum dijawab lainnya
            $candidates = [];
            foreach ($items as $i => $it) {
                if ($i !== $current && $it['answer'] === null) {
                    $candidates[] = $i;
                }
            }
            if ($candidates) {
                $other = $candidates[random_int(0, count($candidates) - 1)];
                [$items[$current], $items[$other]] = [$items[$other], $items[$current]];
            }
        } else {
            // Bank soal cadangan habis (atau bank = jumlah soal ujian):
            // Acak opsi soal aktif yang terinterupsi dan tukar posisinya dengan kandidat soal kosong lainnya
            $options = json_decode($items[$current]['options_json'], true) ?: [];
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
        // shuffle_on_violation === 0: Perilaku standar (hanya relokasi soal aktif)
        $options = json_decode($items[$current]['options_json'], true) ?: [];
        $letters = array_keys($options);
        $order = pretesCbtShuffle($letters);
        if ($order === $letters && count($letters) > 1) $order = array_merge(array_slice($order, 1), [$order[0]]);
        $shuffled = [];
        $correct = '';
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
