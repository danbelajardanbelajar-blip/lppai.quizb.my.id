<?php
require_once __DIR__ . '/tutorial-rpl-periods.php';
require_once __DIR__ . '/pretes-components.php';

function ensureTutorialRplRegistrationTable(PDO $pdo): void
{
    ensureTutorialRplPeriodTable($pdo);
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_registrations (
        id $id, period_id INT NOT NULL, user_id INT NOT NULL,
        channel VARCHAR(10) NOT NULL, registered_by INT NOT NULL,
        registered_at DATETIME NOT NULL, evidence_json TEXT NOT NULL,
        hari_pilihan VARCHAR(20) NULL, schedule_id INT NULL, day_selected_by INT NULL, day_selected_at DATETIME NULL,
        UNIQUE(period_id, user_id)
    )$engine");
    $columns = $mysql ? array_column($pdo->query('SHOW COLUMNS FROM tutorial_rpl_registrations')->fetchAll(PDO::FETCH_ASSOC),'Field')
        : array_column($pdo->query('PRAGMA table_info(tutorial_rpl_registrations)')->fetchAll(PDO::FETCH_ASSOC),'name');
    foreach (['hari_pilihan'=>'VARCHAR(20) NULL','schedule_id'=>'INT NULL','day_selected_by'=>'INT NULL','day_selected_at'=>'DATETIME NULL'] as $name=>$type) {
        if (!in_array($name,$columns,true)) {
            try { $pdo->exec("ALTER TABLE tutorial_rpl_registrations ADD COLUMN $name $type"); }
            catch (PDOException $e) {
                // Only a concurrent successful migration may be ignored.
                $fresh = $mysql ? array_column($pdo->query('SHOW COLUMNS FROM tutorial_rpl_registrations')->fetchAll(PDO::FETCH_ASSOC),'Field')
                    : array_column($pdo->query('PRAGMA table_info(tutorial_rpl_registrations)')->fetchAll(PDO::FETCH_ASSOC),'name');
                if (!in_array($name,$fresh,true)) throw $e;
            }
        }
    }
}

/** Capacity is per lecturer per day; the student chooses a day, never a lecturer. */
function tutorialRplDayAvailability(PDO $pdo, int $periodId): array
{
    $rows = tutorialRplPeriodTutors($pdo,$periodId); $days = [];
    $stmt = $pdo->prepare('SELECT schedule_id,COUNT(*) AS used FROM tutorial_rpl_registrations WHERE period_id=? GROUP BY schedule_id'); $stmt->execute([$periodId]);
    $counts = []; foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[(int)$row['schedule_id']] = (int)$row['used'];
    foreach (TUTORIAL_RPL_DAYS as $day) foreach ($rows as $row) {
        if ($row['hari']!==$day || $row['dosen_role']!=='dosen') continue;
        if (!isset($days[$day])) $days[$day]=['quota'=>0,'used'=>0,'remaining'=>0];
        $used = $counts[(int)$row['id']] ?? 0;
        $days[$day]['quota']+=(int)$row['quota']; $days[$day]['used']+=$used; $days[$day]['remaining']+=max(0,(int)$row['quota']-$used);
    }
    return $days;
}

function tutorialRplValidPretesScore($score): bool
{
    return is_numeric($score) && is_finite((float)$score) && (float)$score >= 0 && (float)$score <= 100;
}

/** Read official evidence without helpers that swallow query errors or write/backfill scores. */
function tutorialRplPretesEvidence(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM pretes_results WHERE user_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $legacy = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($legacy && (!array_key_exists('status_lulus', $legacy)
        || (($legacy['nilai'] ?? null) !== null && $legacy['nilai'] !== '' && !tutorialRplValidPretesScore($legacy['nilai'])))) {
        throw new DomainException('Data hasil pretes resmi perlu diperiksa admin.');
    }
    if ($legacy && strtolower(trim((string)($legacy['status_lulus'] ?? ''))) === 'lulus') {
        $score = $legacy['nilai'] ?? null;
        if ($score !== null && $score !== '' && (!tutorialRplValidPretesScore($score) || (float)$score < 80)) {
            throw new DomainException('Status lulus dan nilai pretes resmi tidak sesuai. Hubungi admin untuk pemeriksaan hasil.');
        }
        return ['official'=>true, 'submitted'=>true, 'score'=>$score === '' ? null : $score,
            'legacy_pass'=>true, 'source'=>'pretes_results', 'result_id'=>(int)$legacy['id']];
    }
    $mode = PRETES_RPL_PASS_MODE_DEFAULT;
    if (tutorialRplTableExists($pdo, 'pretes_settings')) {
        $stmt = $pdo->prepare('SELECT setting_value FROM pretes_settings WHERE setting_key=?');
        $stmt->execute(['rpl_pass_mode']);
        $value = $stmt->fetchColumn();
        if ($value !== false) {
            if (!isset(PRETES_RPL_PASS_MODES[$value])) throw new DomainException('Pengaturan kelulusan pretes perlu diperiksa admin.');
            $mode = $value;
        }
    }
    $stmt = $pdo->prepare('SELECT id FROM pretes_registrations WHERE user_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $registrationId = $stmt->fetchColumn();
    if (!$registrationId) return null;
    if ($mode === 'cbt_only') {
        // A newer pending registration does not erase an earlier completed official CBT result.
        $stmt = $pdo->prepare('SELECT a.score,a.id,a.registration_id FROM pretes_cbt_attempts a JOIN pretes_registrations pr ON pr.id=a.registration_id AND pr.user_id=a.user_id WHERE a.user_id=? AND a.registration_id>0 AND a.submitted_at IS NOT NULL ORDER BY pr.id DESC,a.id DESC LIMIT 1');
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->prepare('SELECT score,id,registration_id FROM pretes_cbt_attempts WHERE user_id=? AND registration_id=? AND registration_id>0 AND submitted_at IS NOT NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId, $registrationId]);
    }
    $cbt = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cbt) $registrationId = $cbt['registration_id'];
    $stmt = $pdo->prepare('SELECT scores_json FROM pretes_assessments WHERE registration_id=?');
    $stmt->execute([$registrationId]);
    $json = $stmt->fetchColumn();
    $scores = $json !== false ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : [];
    if (!is_array($scores)) throw new DomainException('Data penilaian pretes tidak valid. Hubungi admin.');
    if ($cbt) {
        if (!tutorialRplValidPretesScore($cbt['score'])) throw new DomainException('Nilai CBT resmi tidak valid. Hubungi admin.');
        $scores['ujian_tulis'] = $cbt['score'];
    }
    $keys = ['ujian_tulis'];
    if ($mode !== 'cbt_only') {
        if (tutorialRplTableExists($pdo, 'pretes_components')) {
            $stmt = $pdo->prepare('SELECT comp_key FROM pretes_components WHERE program=? AND is_active=1');
            $stmt->execute(['rpl']);
            $keys = array_merge($keys, $stmt->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $keys = array_merge($keys, array_column(pretesDefaultComponentRows(), 'comp_key'));
        }
    }
    $complete = true;
    $minimum = 100.0;
    foreach ($keys as $key) {
        $score = $scores[$key] ?? null;
        if ($score === null || $score === '') { $complete = false; continue; }
        if (!tutorialRplValidPretesScore($score)) throw new DomainException('Nilai komponen pretes tidak valid. Hubungi admin.');
        $minimum = min($minimum, (float)$score);
    }
    return ['official'=>true, 'submitted'=>$complete, 'score'=>$complete ? $minimum : null,
        'source'=>$cbt ? 'CBT resmi dan komponen wajib' : 'Penilaian resmi', 'registration_id'=>(int)$registrationId, 'mode'=>$mode];
}

function tutorialRplRegistrationFor(PDO $pdo, int $periodId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM tutorial_rpl_registrations WHERE period_id=? AND user_id=?');
    $stmt->execute([$periodId, $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function tutorialRplRegistrationCount(PDO $pdo, int $periodId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tutorial_rpl_registrations WHERE period_id=?');
    $stmt->execute([$periodId]);
    return (int)$stmt->fetchColumn();
}

function tutorialRplRegistrationReason(string $reason): string
{
    return ['passed_pretes'=>'Anda dibebaskan dari tutorial karena sudah lulus pretes resmi.',
        'not_rpl_student'=>'Pendaftaran ini khusus akun mahasiswa RPL.', 'forbidden'=>'Anda tidak berhak mendaftarkan akun ini.',
        'invalid_channel'=>'Jalur pendaftaran tidak valid.', 'not_open_yet'=>'Pendaftaran belum dibuka.',
        'registration_closed'=>'Pendaftaran sudah ditutup.', 'no_active_period'=>'Belum ada periode pendaftaran aktif.',
        'invalid_pretes_result'=>'Hasil pretes perlu diperiksa admin.'][$reason] ?? 'Pendaftaran belum dapat diproses.';
}

/** Caller validates CSRF. Lock the selected period before counting to serialize both channels. */
function tutorialRplRegister(PDO $pdo, int $periodId, int $studentId, array $actor, string $channel, ?int $now = null, ?string $day = null): array
{
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if ($sqlite) $pdo->exec('BEGIN IMMEDIATE'); else $pdo->beginTransaction();
    try {
        $lock = $sqlite ? '' : ' FOR UPDATE';
        $stmt = $pdo->prepare('SELECT * FROM tutorial_rpl_registration_periods WHERE id=?' . $lock);
        $stmt->execute([$periodId]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$period) throw new DomainException('Periode tidak ditemukan. Muat ulang halaman.');
        $stmt = $pdo->prepare('SELECT id, role, jenis_program FROM users WHERE id=?' . $lock);
        $stmt->execute([(int)($actor['id'] ?? 0)]);
        $freshActor = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $policy = tutorialRplPeriodPolicy($period);
        $access = tutorialRplRegistrationDecision($student, $freshActor, $channel, null, $now, $policy);
        if (in_array($access['reason'], ['forbidden','invalid_channel','not_rpl_student'], true)) throw new DomainException(tutorialRplRegistrationReason($access['reason']));
        // Repeated POST is idempotent, including after the window closes or quota fills.
        $existing = tutorialRplRegistrationFor($pdo, $periodId, $studentId);
        if ($existing && $existing['schedule_id'] !== null) {
            if ($sqlite) $pdo->exec('COMMIT'); else $pdo->commit();
            return ['id'=>(int)$existing['id'], 'created'=>false];
        }
        $evidence = tutorialRplPretesEvidence($pdo, $studentId);
        $decision = tutorialRplRegistrationDecision($student, $freshActor, $channel, $evidence, $now, $policy);
        if (!$decision['allowed']) throw new DomainException(tutorialRplRegistrationReason($decision['reason']));
        if (!$existing && (int)$period['quota'] > 0 && tutorialRplRegistrationCount($pdo, $periodId) >= (int)$period['quota']) throw new DomainException('Kuota pendaftaran periode ini sudah penuh.');
        if (!in_array($day,TUTORIAL_RPL_DAYS,true)) throw new DomainException('Pilih hari tutorial yang tersedia terlebih dahulu.');
        $stmt = $pdo->prepare("SELECT s.id,s.quota,COALESCE(r.used,0) AS used FROM tutorial_rpl_period_tutors s JOIN users u ON u.id=s.dosen_id AND u.role='dosen' LEFT JOIN (SELECT schedule_id,COUNT(*) AS used FROM tutorial_rpl_registrations WHERE period_id=? GROUP BY schedule_id) r ON r.schedule_id=s.id WHERE s.period_id=? AND s.hari=? ORDER BY used,s.id");
        $stmt->execute([$periodId,$periodId,$day]); $seat = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) if ((int)$row['used']<(int)$row['quota']) { $seat=$row; break; }
        if (!$seat) throw new DomainException('Hari yang dipilih tidak tersedia atau kuota dosen pada hari tersebut sudah penuh. Pilih hari lain.');
        $stamp = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s');
        if ($existing) {
            $pdo->prepare('UPDATE tutorial_rpl_registrations SET hari_pilihan=?,schedule_id=?,day_selected_by=?,day_selected_at=? WHERE id=? AND schedule_id IS NULL')->execute([$day,$seat['id'],$freshActor['id'],$stamp,$existing['id']]);
            $id=(int)$existing['id'];
        } else {
            $stmt = $pdo->prepare('INSERT INTO tutorial_rpl_registrations (period_id,user_id,channel,registered_by,registered_at,evidence_json,hari_pilihan,schedule_id,day_selected_by,day_selected_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$periodId,$studentId,$channel,(int)$freshActor['id'],$stamp,json_encode(['pretes'=>$evidence,'policy_id'=>$policy['id']],JSON_THROW_ON_ERROR),$day,(int)$seat['id'],$freshActor['id'],$stamp]);
            $id = (int)$pdo->lastInsertId();
        }
        if ($sqlite) $pdo->exec('COMMIT'); else $pdo->commit();
        return ['id'=>$id, 'created'=>!$existing,'day_selected'=>true];
    } catch (Throwable $e) {
        if ($sqlite) $pdo->exec('ROLLBACK'); elseif ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
