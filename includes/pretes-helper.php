<?php
/** Penyimpanan nilai rinci dan sesi absensi pretes. */
function ensurePretesFeatures($pdo) {
    static $ready = false;
    if ($ready) return;
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_assessments (
                registration_id INTEGER NOT NULL PRIMARY KEY,
                scores_json TEXT NOT NULL,
                assessed_by INTEGER NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_attendance_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope_hash CHAR(64) NOT NULL UNIQUE,
                periode VARCHAR(100) NOT NULL,
                hari VARCHAR(20) NOT NULL,
                penguji VARCHAR(255) NOT NULL,
                ruangan VARCHAR(255) NOT NULL,
                token CHAR(48) NOT NULL UNIQUE,
                open_until DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_attendance_records (
                registration_id INTEGER NOT NULL PRIMARY KEY,
                session_id INTEGER NOT NULL,
                attended_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_assessments (
                registration_id INT NOT NULL PRIMARY KEY,
                scores_json TEXT NOT NULL,
                assessed_by INT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_attendance_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                scope_hash CHAR(64) NOT NULL UNIQUE,
                periode VARCHAR(100) NOT NULL,
                hari VARCHAR(20) NOT NULL,
                penguji VARCHAR(255) NOT NULL,
                ruangan VARCHAR(255) NOT NULL,
                token CHAR(48) NOT NULL UNIQUE,
                open_until DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_attendance_records (
                registration_id INT NOT NULL PRIMARY KEY,
                session_id INT NOT NULL,
                attended_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $ready = true;
    } catch (Exception $e) {
        error_log("ensurePretesFeatures warning: " . $e->getMessage());
    }
}

function pretesComponents() {
    return [
        'ujian_tulis' => 'Ujian tulis',
        'praktik_wudlu' => 'Praktik wudlu',
        'doa_setelah_wudlu' => 'Doa setelah wudlu',
        'praktik_tayammum' => 'Praktik tayammum',
        'niat_mandi_besar' => 'Niat mandi besar',
        'niat_jamak_qashar' => 'Niat jamak qashar',
        'bacaan_shalat_subuh' => 'Bacaan shalat Subuh',
        'al_bayyinah' => 'Al Bayyinah',
        'al_alaq' => "Al 'Alaq",
        'al_dluha' => 'Al Dluha',
        'istighasah' => 'Istighasah',
        'shalat_jenazah' => 'Shalat jenazah',
    ];
}

/** Lulus hanya jika setiap komponen wajib bernilai minimal 80. */
function getPretesStatusLulus($scores) {
    $complete = true;
    foreach (pretesComponents() as $key => $label) {
        $value = is_array($scores) ? ($scores[$key] ?? null) : null;
        if ($value === null || $value === '' || !is_numeric($value)
            || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 100) {
            $complete = false;
            continue;
        }
        if ((float)$value < 80) return 'tidak_lulus';
    }
    return $complete ? 'lulus' : 'menunggu';
}

/** Cek apakah user telah dinyatakan lulus pretes (baik dari tabel pretes_results maupun asesmen 12 komponen). */
function isUserLulusPretes($pdo, $userId) {
    if (!$pdo || !$userId) return false;

    // 1. Cek dari tabel pretes_results
    try {
        $stmt = $pdo->prepare("SELECT status_lulus FROM pretes_results WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);
        $status = strtolower(trim((string)$stmt->fetchColumn()));
        if ($status === 'lulus') {
            return true;
        }
    } catch (Exception $e) {}

    // 2. Cek dari data asesmen di pretes_registrations & pretes_assessments
    try {
        $stmt = $pdo->prepare("
            SELECT pa.scores_json 
            FROM pretes_registrations pr
            JOIN pretes_assessments pa ON pa.registration_id = pr.id
            WHERE pr.user_id = ?
            ORDER BY pr.id DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        $json = $stmt->fetchColumn();
        if ($json) {
            $scores = json_decode($json, true);
            if (is_array($scores) && function_exists('getPretesStatusLulus')) {
                if (getPretesStatusLulus($scores) === 'lulus') {
                    return true;
                }
            }
        }
    } catch (Exception $e) {}

    return false;
}

if (!function_exists('getPretesPredikat')) {
    function getPretesPredikat($score) {
        if ($score === null || $score === '') return '-';
        $num = (float)$score;
        if ($num >= 85) return 'A';
        if ($num >= 75) return 'B';
        if ($num >= 65) return 'C';
        if ($num >= 55) return 'D';
        return 'E';
    }
}

function pretesValidateScores($input) {
    if (!is_array($input)) throw new InvalidArgumentException('Data nilai tidak valid.');
    $scores = [];
    foreach (pretesComponents() as $key => $label) {
        $raw = $input[$key] ?? '';
        if (!is_scalar($raw) && $raw !== null) throw new InvalidArgumentException('Nilai ' . $label . ' tidak valid.');
        $raw = trim((string)$raw);
        if ($raw === '') { $scores[$key] = null; continue; }
        if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $raw) || (float)$raw > 100) {
            throw new InvalidArgumentException('Nilai ' . $label . ' harus 0–100, maksimal dua angka desimal.');
        }
        $scores[$key] = (float)$raw;
    }
    return $scores;
}

function pretesScope($row) {
    return [
        'periode' => trim($row['periode'] ?? ''),
        'hari' => ucfirst(strtolower(trim($row['hari_pilihan'] ?? ''))) ?: 'Senin',
        'penguji' => trim($row['dosen_penguji'] ?? ''),
        'ruangan' => trim($row['ruangan'] ?? ''),
    ];
}

function pretesScopeHash($scope) {
    return hash('sha256', json_encode(array_values($scope), JSON_UNESCAPED_UNICODE));
}

function pretesSession($pdo, $scope) {
    $hash = pretesScopeHash($scope);
    // ON DUPLICATE mempertahankan token, sehingga QR cetak dan kartu dosen sama.
    $stmt = $pdo->prepare('INSERT INTO pretes_attendance_sessions
        (scope_hash, periode, hari, penguji, ruangan, token) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE scope_hash = VALUES(scope_hash)');
    $stmt->execute([$hash, $scope['periode'], $scope['hari'], $scope['penguji'], $scope['ruangan'], bin2hex(random_bytes(24))]);
    $stmt = $pdo->prepare('SELECT *, (open_until > NOW()) AS is_open FROM pretes_attendance_sessions WHERE scope_hash = ?');
    $stmt->execute([$hash]);
    return $stmt->fetch();
}

function pretesAttendanceUrl($session) {
    return BASE_URL . '/absensi-pretes.php?token=' . rawurlencode($session['token']);
}

function pretesCanAssess($user, $registration) {
    return $user['role'] === 'admin' || ($user['role'] === 'dosen'
        && trim($user['nama_lengkap']) !== ''
        && trim($registration['dosen_penguji'] ?? '') === trim($user['nama_lengkap']));
}

function pretesProgress($participants) {
    $progress = ['total' => count($participants), 'hadir' => 0, 'lengkap' => 0, 'sebagian' => 0, 'kosong' => 0];
    foreach ($participants as $row) {
        if (($row['status'] ?? '') === 'hadir') $progress['hadir']++;
        $scores = json_decode($row['scores_json'] ?? '{}', true);
        $filled = 0;
        foreach (pretesComponents() as $key => $label) {
            $value = is_array($scores) ? ($scores[$key] ?? null) : null;
            if ($value !== null && $value !== '' && is_numeric($value) && (float)$value >= 0 && (float)$value <= 100) $filled++;
        }
        if ($filled === count(pretesComponents())) $progress['lengkap']++;
        elseif ($filled > 0) $progress['sebagian']++;
        else $progress['kosong']++;
    }
    return $progress;
}

if (!function_exists('getAllowedPretesTargetPrograms')) {
    /**
     * Mengembalikan daftar target peserta periode pretes yang valid.
     *
     * @return array<int, string>
     */
    function getAllowedPretesTargetPrograms(): array {
        return ['Semua', 'RPL', 'Reguler'];
    }
}

if (!function_exists('normalizePretesTargetProgram')) {
    /**
     * Menormalkan target program periode pretes ke salah satu dari: "Semua", "RPL", atau "Reguler".
     */
    function normalizePretesTargetProgram($value): string {
        $normalized = strtoupper(trim((string)($value ?? '')));
        if ($normalized === 'RPL' || str_contains($normalized, 'RPL')) {
            return 'RPL';
        }
        if ($normalized === 'REGULER' || str_contains($normalized, 'REGULER')) {
            return 'Reguler';
        }
        return 'Semua';
    }
}

if (!function_exists('ensurePretesPeriodTargetColumns')) {
    /**
     * Memastikan kolom `target_program` dan `tanggal_mulai` tersedia di tabel `master_pretes`.
     */
    function ensurePretesPeriodTargetColumns(PDO $pdo): void {
        static $verified = false;
        $driver = '';
        try {
            $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable $e) {
            return;
        }

        if ($driver === 'mysql' && $verified && !empty($_SESSION['_schema_pretes_target_ok'])) {
            return;
        }

        try {
            if ($driver === 'sqlite') {
                $cols = $pdo->query("PRAGMA table_info(master_pretes)")->fetchAll(PDO::FETCH_ASSOC);
                if (empty($cols)) return;
                $colNames = array_map(fn($c) => strtolower((string)($c['name'] ?? '')), $cols);
                if (!in_array('target_program', $colNames, true)) {
                    $pdo->exec("ALTER TABLE master_pretes ADD COLUMN target_program TEXT NOT NULL DEFAULT 'Semua'");
                }
                if (!in_array('tanggal_mulai', $colNames, true)) {
                    $pdo->exec("ALTER TABLE master_pretes ADD COLUMN tanggal_mulai TEXT DEFAULT NULL");
                }
            } else {
                $tbl = $pdo->query("SHOW TABLES LIKE 'master_pretes'")->fetch();
                if (!$tbl) return;

                $colTarget = $pdo->query("SHOW COLUMNS FROM master_pretes LIKE 'target_program'")->fetch();
                if (!$colTarget) {
                    $pdo->exec("ALTER TABLE master_pretes ADD COLUMN target_program ENUM('Semua', 'RPL', 'Reguler') NOT NULL DEFAULT 'Semua' AFTER tahun_ajaran");
                }
                $colTanggal = $pdo->query("SHOW COLUMNS FROM master_pretes LIKE 'tanggal_mulai'")->fetch();
                if (!$colTanggal) {
                    $pdo->exec("ALTER TABLE master_pretes ADD COLUMN tanggal_mulai DATE DEFAULT NULL AFTER target_program");
                }
                $_SESSION['_schema_pretes_target_ok'] = true;
                $verified = true;
            }
        } catch (Throwable $e) {
            error_log('ensurePretesPeriodTargetColumns warning: ' . $e->getMessage());
        }
    }
}

if (!function_exists('getActivePretesPeriod')) {
    /**
     * Mengambil periode pretes yang berstatus aktif beserta kolom target_program dan tanggal_mulai.
     */
    function getActivePretesPeriod(PDO $pdo): ?array {
        ensurePretesPeriodTargetColumns($pdo);
        try {
            $row = $pdo->query("SELECT * FROM master_pretes WHERE status = 'aktif' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('isUserEligibleForPretesPeriod')) {
    /**
     * Menentukan apakah pengguna berhak mendaftar/mengakses pendaftaran pada periode pretes tertentu.
     */
    function isUserEligibleForPretesPeriod(?array $user, ?array $period): bool {
        if (!$period || ($period['status'] ?? '') !== 'aktif') {
            return false;
        }

        $target = normalizePretesTargetProgram($period['target_program'] ?? 'Semua');
        if ($target === 'Semua') {
            return true;
        }

        if (!$user) {
            return false;
        }

        $role = strtolower(trim((string)($user['role'] ?? 'mahasiswa')));
        if ($role !== 'mahasiswa') {
            return true;
        }

        require_once __DIR__ . '/user-helper.php';
        $userProgram = normalizeJenisProgram($user['jenis_program'] ?? 'Reguler');
        return $userProgram === $target;
    }
}

if (!function_exists('renderPretesTargetProgramBadge')) {
    /**
     * Merender badge HTML untuk target program periode pretes.
     */
    function renderPretesTargetProgramBadge($targetProgram): string {
        $target = normalizePretesTargetProgram($targetProgram);
        if ($target === 'RPL') {
            $style = 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;';
            $label = 'Khusus RPL';
        } elseif ($target === 'Reguler') {
            $style = 'background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;';
            $label = 'Khusus Reguler';
        } else {
            $style = 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0;';
            $label = 'Semua Program';
        }
        return '<span class="badge" style="' . $style . 'font-size:11px;padding:3px 9px;border-radius:999px;font-weight:700;">' . $label . '</span>';
    }
}

if (!function_exists('getPretesPeriodDayLabels')) {
    /**
     * Menghasilkan label hari (Senin-Jumat) dan rentang tanggal pelaksanaan berbahasa Indonesia.
     *
     * @return array{days:array<string,string>, range_label:string, short_range:string}
     */
    function getPretesPeriodDayLabels(?array $period): array {
        $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
        $defaultDays = array_combine($days, $days);
        $rawStart = trim((string)($period['tanggal_mulai'] ?? ''));
        if ($rawStart === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawStart) !== 1) {
            return [
                'days'        => $defaultDays,
                'range_label' => '',
                'short_range' => '',
            ];
        }

        $startDt = DateTimeImmutable::createFromFormat('!Y-m-d', $rawStart);
        if (!$startDt) {
            return [
                'days'        => $defaultDays,
                'range_label' => '',
                'short_range' => '',
            ];
        }

        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        $dayLabels = [];
        foreach ($days as $idx => $dayName) {
            $dt = $startDt->modify("+{$idx} days");
            $m = (int)$dt->format('n');
            $dayLabels[$dayName] = $dayName . ', ' . (int)$dt->format('j') . ' ' . ($months[$m] ?? $dt->format('F')) . ' ' . $dt->format('Y');
        }

        $endDt = $startDt->modify('+4 days');
        $d1 = (int)$startDt->format('j');
        $m1 = $months[(int)$startDt->format('n')] ?? $startDt->format('F');
        $y1 = $startDt->format('Y');

        $d2 = (int)$endDt->format('j');
        $m2 = $months[(int)$endDt->format('n')] ?? $endDt->format('F');
        $y2 = $endDt->format('Y');

        if ($y1 === $y2 && $m1 === $m2) {
            $rangeLabel = "{$d1} sampai {$d2} {$m1} {$y1}";
            $shortRange = "{$d1} – {$d2} {$m1} {$y1}";
        } elseif ($y1 === $y2) {
            $rangeLabel = "{$d1} {$m1} sampai {$d2} {$m2} {$y1}";
            $shortRange = "{$d1} {$m1} – {$d2} {$m2} {$y1}";
        } else {
            $rangeLabel = "{$d1} {$m1} {$y1} sampai {$d2} {$m2} {$y2}";
            $shortRange = "{$d1} {$m1} {$y1} – {$d2} {$m2} {$y2}";
        }

        return [
            'days'        => $dayLabels,
            'range_label' => $rangeLabel,
            'short_range' => $shortRange,
        ];
    }
}

if (!function_exists('shouldShowDaftarPretesToStudent')) {
    /**
     * Menentukan apakah tautan menu "Daftar Pretes" di sidebar perlu ditampilkan untuk mahasiswa.
     */
    function shouldShowDaftarPretesToStudent(PDO $pdo, ?array $user): bool {
        if (!$user) {
            return false;
        }
        $period = getActivePretesPeriod($pdo);
        if (!$period) {
            return false;
        }
        return isUserEligibleForPretesPeriod($user, $period);
    }
}
