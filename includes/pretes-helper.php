<?php
/** Penyimpanan nilai rinci dan sesi absensi pretes. */
require_once __DIR__ . '/pretes-components.php';
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

/**
 * Komponen pretes wajib (kunci => label): Ujian tulis (CBT) + komponen praktik aktif program.
 * Dikelola admin di admin/pretes-tugas.php (includes/pretes-components.php).
 * $program: 'rpl' | 'reguler' | null (gabungan semua program, untuk kolom rekap/impor).
 */
function pretesComponents(?string $program = null) {
    return pretesComponentsFor($program);
}

/** Lulus hanya jika setiap komponen wajib bernilai minimal 80. */
function getPretesStatusLulus($scores, ?string $program = null) {
    $complete = true;
    foreach (pretesComponents($program) as $key => $label) {
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
    $program = null; // program mahasiswa menentukan daftar komponen wajib
    try {
        $stmtProg = $pdo->prepare('SELECT jenis_program FROM users WHERE id = ?');
        $stmtProg->execute([$userId]);
        $jp = $stmtProg->fetchColumn();
        if ($jp !== false) $program = pretesProgramKey($jp);
    } catch (Throwable $e) {}

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
                if (getPretesStatusLulus($scores, $program) === 'lulus') {
                    return true;
                }
            }
        }
    } catch (Exception $e) {}

    // 3. RPL mode "cukup CBT": CBT resmi (registration_id > 0) yang sudah dikumpulkan dengan nilai ≥ 80
    //    (cadangan bila nilai CBT belum tersalin ke komponen Ujian tulis).
    if ($program === 'rpl' && function_exists('pretesRplCbtOnly') && pretesRplCbtOnly()) {
        try {
            $stmt = $pdo->prepare("
                SELECT a.score
                FROM pretes_cbt_attempts a
                JOIN pretes_registrations pr ON pr.id = a.registration_id
                WHERE a.user_id = ? AND a.registration_id > 0 AND a.submitted_at IS NOT NULL AND a.score IS NOT NULL
                ORDER BY pr.id DESC, a.id DESC LIMIT 1
            ");
            $stmt->execute([$userId]);
            $cbtScore = $stmt->fetchColumn();
            if ($cbtScore !== false && $cbtScore !== null && (float)$cbtScore >= 80) {
                return true;
            }
        } catch (Throwable $e) {}
    }

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

function pretesValidateScores($input, ?string $program = null) {
    if (!is_array($input)) throw new InvalidArgumentException('Data nilai tidak valid.');
    $scores = [];
    foreach (pretesComponents($program) as $key => $label) {
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

function pretesCanAssess($user, $registration, ?PDO $pdo = null) {
    if (($user['role'] ?? '') === 'admin') return true;
    if (($user['role'] ?? '') !== 'dosen') return false;
    // Identitas penguji = NIM akun dosen (bukan nama), lihat includes/pretes-examiner.php
    require_once __DIR__ . '/pretes-examiner.php';
    $pdo = $pdo ?? (function_exists('getDBConnection') ? getDBConnection() : null);
    if (!$pdo) return false;
    return pretesExaminerOwns($pdo, $user, (array)$registration);
}

function pretesProgress($participants) {
    $progress = ['total' => count($participants), 'hadir' => 0, 'lengkap' => 0, 'sebagian' => 0, 'kosong' => 0];
    foreach ($participants as $row) {
        if (($row['status'] ?? '') === 'hadir') $progress['hadir']++;
        $scores = json_decode($row['scores_json'] ?? '{}', true);
        $filled = 0;
        $rowComponents = pretesComponents(array_key_exists('jenis_program', $row) ? pretesProgramKey($row['jenis_program']) : null);
        foreach ($rowComponents as $key => $label) {
            $value = is_array($scores) ? ($scores[$key] ?? null) : null;
            if ($value !== null && $value !== '' && is_numeric($value) && (float)$value >= 0 && (float)$value <= 100) $filled++;
        }
        if ($filled === count($rowComponents)) $progress['lengkap']++;
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

/* ------------------------------------------------------------------
 * Hari pelaksanaan pretes (PRETES-RPL-SABTU-AHAD)
 * Senin–Jumat untuk semua periode; Sabtu & Ahad HANYA untuk periode bertarget "Khusus RPL".
 * Kolom master_pretes: kuota_{hari} & penguji_{hari} (sabtu, ahad ditambahkan otomatis).
 * ------------------------------------------------------------------ */
if (!function_exists('pretesWeekdays')) {
    function pretesWeekdays(): array { return ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat']; }
    function pretesWeekendDaysRpl(): array { return ['Sabtu', 'Ahad']; }
    /** Seluruh hari yang dikenal sistem pretes (urutan tampilan). */
    function pretesAllDays(): array { return array_merge(pretesWeekdays(), pretesWeekendDaysRpl()); }
    /** Sabtu & Ahad dibuka hanya untuk periode Khusus RPL. */
    function pretesPeriodAllowsWeekend(?array $period): bool {
        return $period !== null && normalizePretesTargetProgram($period['target_program'] ?? 'Semua') === 'RPL';
    }
    /** Hari yang berlaku untuk sebuah periode. */
    function pretesPeriodDays(?array $period): array {
        return pretesPeriodAllowsWeekend($period) ? pretesAllDays() : pretesWeekdays();
    }
    /**
     * Hari untuk tampilan/filter: hari periode + Sabtu/Ahad yang sudah punya data ($counts[hari] > 0),
     * mis. saat menampilkan "Semua Periode".
     */
    function pretesDisplayDays(?array $period, array $counts = []): array {
        $days = pretesPeriodDays($period);
        foreach (pretesWeekendDaysRpl() as $d) {
            if (!in_array($d, $days, true) && (int)($counts[$d] ?? 0) > 0) $days[] = $d;
        }
        return array_values(array_filter(pretesAllDays(), static fn($d) => in_array($d, $days, true)));
    }
    /** Selisih hari dari tanggal mulai (Senin) — Sabtu +5, Ahad +6. */
    function pretesDayOffset(string $day): int {
        $i = array_search($day, pretesAllDays(), true);
        return $i === false ? 0 : (int)$i;
    }
    /** Normalisasi nama hari bebas ("sabtu", "Minggu", "ahad") → nama baku atau ''. */
    function pretesNormalizeDay($value): string {
        $v = strtolower(trim((string)$value));
        if ($v === 'minggu') $v = 'ahad';
        foreach (pretesAllDays() as $d) if (strtolower($d) === $v) return $d;
        return '';
    }
    /** Baris master_pretes berdasarkan nama periode (null bila tidak ada). */
    function pretesPeriodByName(PDO $pdo, string $periode): ?array {
        if (trim($periode) === '') return null;
        try {
            $st = $pdo->prepare('SELECT * FROM master_pretes WHERE periode = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$periode]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
    /** Hari yang boleh dipakai sebuah pendaftaran (mengikuti periode pendaftarannya). */
    function pretesDaysForRegistration(PDO $pdo, int $registrationId): array {
        try {
            $st = $pdo->prepare('SELECT periode FROM pretes_registrations WHERE id = ?');
            $st->execute([$registrationId]);
            $periode = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $periode = '';
        }
        return pretesPeriodDays(pretesPeriodByName($pdo, $periode));
    }
    /** Simpan kuota & penguji Sabtu/Ahad periode; dikosongkan bila periode bukan Khusus RPL. */
    function pretesSaveWeekendSlots(PDO $pdo, int $periodId, string $targetProgram, array $post): void {
        if ($periodId <= 0) return;
        $isRpl = normalizePretesTargetProgram($targetProgram) === 'RPL';
        // Form tanpa isian Sabtu/Ahad (mis. halaman lain) tidak boleh menghapus slot akhir pekan periode RPL
        if ($isRpl && !array_key_exists('kuota_sabtu', $post) && !array_key_exists('kuota_ahad', $post)) return;
        $vals = [];
        foreach (pretesWeekendDaysRpl() as $d) {
            $k = strtolower($d);
            $vals[] = $isRpl ? max(0, (int)($post['kuota_' . $k] ?? 0)) : 0;
            $list = (isset($post['penguji_' . $k]) && is_array($post['penguji_' . $k])) ? $post['penguji_' . $k] : [];
            $vals[] = $isRpl ? implode('|||', array_filter(array_map('trim', $list))) : '';
        }
        $vals[] = $periodId;
        $pdo->prepare('UPDATE master_pretes SET kuota_sabtu = ?, penguji_sabtu = ?, kuota_ahad = ?, penguji_ahad = ? WHERE id = ?')->execute($vals);
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

        if ($driver === 'mysql' && $verified && !empty($_SESSION['_schema_pretes_target_ok_v2'])) {
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
                foreach (['sabtu', 'ahad'] as $wd) { // Sabtu & Ahad: khusus periode RPL
                    if (!in_array('kuota_' . $wd, $colNames, true)) $pdo->exec("ALTER TABLE master_pretes ADD COLUMN kuota_$wd INTEGER DEFAULT 0");
                    if (!in_array('penguji_' . $wd, $colNames, true)) $pdo->exec("ALTER TABLE master_pretes ADD COLUMN penguji_$wd TEXT DEFAULT NULL");
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
                foreach (['sabtu', 'ahad'] as $wd) { // Sabtu & Ahad: khusus periode RPL
                    if (!$pdo->query("SHOW COLUMNS FROM master_pretes LIKE 'kuota_$wd'")->fetch()) {
                        $pdo->exec("ALTER TABLE master_pretes ADD COLUMN kuota_$wd INT DEFAULT 0");
                    }
                    if (!$pdo->query("SHOW COLUMNS FROM master_pretes LIKE 'penguji_$wd'")->fetch()) {
                        $pdo->exec("ALTER TABLE master_pretes ADD COLUMN penguji_$wd VARCHAR(1000) DEFAULT NULL");
                    }
                }
                $_SESSION['_schema_pretes_target_ok'] = true;
                $_SESSION['_schema_pretes_target_ok_v2'] = true;
                $verified = true;
            }
        } catch (Throwable $e) {
            error_log('ensurePretesPeriodTargetColumns warning: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensurePretesRegistrationPhotoColumn')) {
    /**
     * Memastikan kolom `foto` tersedia di tabel `pretes_registrations`.
     */
    function ensurePretesRegistrationPhotoColumn(PDO $pdo): void {
        static $verified = false;
        $driver = '';
        try {
            $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable $e) {
            return;
        }

        if ($driver === 'mysql' && $verified && !empty($_SESSION['_schema_pretes_photo_ok'])) {
            return;
        }

        try {
            if ($driver === 'sqlite') {
                $cols = $pdo->query("PRAGMA table_info(pretes_registrations)")->fetchAll(PDO::FETCH_ASSOC);
                if (empty($cols)) return;
                $colNames = array_map(fn($c) => strtolower((string)($c['name'] ?? '')), $cols);
                if (!in_array('foto', $colNames, true)) {
                    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN foto TEXT DEFAULT NULL");
                }
            } else {
                $tbl = $pdo->query("SHOW TABLES LIKE 'pretes_registrations'")->fetch();
                if (!$tbl) return;

                $colFoto = $pdo->query("SHOW COLUMNS FROM pretes_registrations LIKE 'foto'")->fetch();
                if (!$colFoto) {
                    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN foto VARCHAR(255) DEFAULT NULL AFTER jam");
                }
                $_SESSION['_schema_pretes_photo_ok'] = true;
                $verified = true;
            }
        } catch (Throwable $e) {
            error_log('ensurePretesRegistrationPhotoColumn warning: ' . $e->getMessage());
        }
    }
}

if (!function_exists('savePretesRegistrationPhoto')) {
    /**
     * Menyimpan foto resmi pendaftaran pretes (dari base64 data URL atau uploaded file)
     * ke folder `uploads/pretes_foto/`.
     * Mengembalikan relative path file tersimpan atau null jika gagal.
     */
    function savePretesRegistrationPhoto(string $photoPayload, string $nim): ?string {
        $cleanPayload = trim($photoPayload);
        if ($cleanPayload === '') {
            return null;
        }

        $baseDir = realpath(__DIR__ . '/..');
        if (!$baseDir) {
            $baseDir = dirname(__DIR__);
        }
        $targetDir = $baseDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pretes_foto';
        if (!is_dir($targetDir)) {
            if (!@mkdir($targetDir, 0755, true)) {
                return null;
            }
        }

        // Amankan folder dengan .htaccess
        $htaccessFile = $targetDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccessFile)) {
            @file_put_contents($htaccessFile, "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phps)$\">\nOrder Deny,Allow\nDeny from all\n</FilesMatch>\nOptions -ExecCGI\n");
        }

        $binaryData = null;
        $extension = 'jpg';

        if (str_starts_with($cleanPayload, 'data:image/')) {
            $commaPos = strpos($cleanPayload, ',');
            if ($commaPos === false) {
                return null;
            }
            $meta = substr($cleanPayload, 0, $commaPos);
            $rawBase64 = substr($cleanPayload, $commaPos + 1);
            $binaryData = base64_decode($rawBase64, true);
            if ($binaryData === false) {
                return null;
            }

            if (str_contains($meta, 'image/png')) {
                $extension = 'png';
            } elseif (str_contains($meta, 'image/webp')) {
                $extension = 'webp';
            } else {
                $extension = 'jpg';
            }
        } elseif (file_exists($cleanPayload)) {
            // Path file temporer
            $binaryData = @file_get_contents($cleanPayload);
            $imgInfo = @getimagesize($cleanPayload);
            if (!$imgInfo) {
                return null;
            }
            $mime = $imgInfo['mime'] ?? '';
            if ($mime === 'image/png') {
                $extension = 'png';
            } elseif ($mime === 'image/webp') {
                $extension = 'webp';
            } else {
                $extension = 'jpg';
            }
        }

        if (empty($binaryData) || strlen($binaryData) < 100) {
            return null;
        }

        // Simpan file
        $cleanNim = preg_replace('/[^a-zA-Z0-9_-]/', '', $nim) ?: 'peserta';
        $fileName = 'pretes_' . $cleanNim . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
        $filePath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($binaryData);
            if (!$img) {
                return null;
            }
            imagejpeg($img, $filePath, 90);
            imagedestroy($img);
            return 'uploads/pretes_foto/' . $fileName;
        } else {
            $fileName = 'pretes_' . $cleanNim . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            $filePath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
            if (@file_put_contents($filePath, $binaryData) === false) {
                return null;
            }
            return 'uploads/pretes_foto/' . $fileName;
        }
    }
}

if (!function_exists('getActivePretesPeriod')) {
    /**
     * Mengambil periode pretes yang berstatus aktif beserta kolom target_program dan tanggal_mulai.
     */
    function getActivePretesPeriod(PDO $pdo): ?array {
        ensurePretesPeriodTargetColumns($pdo);
        ensurePretesRegistrationPhotoColumn($pdo);
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
     * Menghasilkan label hari (Senin-Jumat; + Sabtu & Ahad untuk periode Khusus RPL) dan rentang tanggal pelaksanaan.
     *
     * @return array{days:array<string,string>, range_label:string, short_range:string}
     */
    function getPretesPeriodDayLabels(?array $period): array {
        $days = pretesPeriodDays($period);
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
        foreach ($days as $dayName) {
            $idx = pretesDayOffset($dayName); // Senin +0 … Jumat +4, Sabtu +5, Ahad +6
            $dt = $startDt->modify("+{$idx} days");
            $m = (int)$dt->format('n');
            $dayLabels[$dayName] = $dayName . ', ' . (int)$dt->format('j') . ' ' . ($months[$m] ?? $dt->format('F')) . ' ' . $dt->format('Y');
        }

        $endDt = $startDt->modify('+' . pretesDayOffset((string)end($days)) . ' days');
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
if (!function_exists('studentHasPretesRegistration')) {
    /**
     * Cek apakah mahasiswa terdaftar pada program pretes (baik periode/status aktif maupun non-aktif).
     * Mengembalikan true jika mahasiswa memiliki catatan pendaftaran pretes atau riwayat hasil pretes.
     *
     * @param PDO $pdo
     * @param array|null $user
     * @return bool
     */
    function studentHasPretesRegistration(PDO $pdo, ?array $user): bool {
        if (!$user || ($user['role'] ?? '') !== 'mahasiswa' || (int)($user['id'] ?? 0) < 1) {
            return false;
        }
        $userId = (int)$user['id'];
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM pretes_registrations WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            if ($stmt->fetchColumn()) {
                return true;
            }
            $stmtRes = $pdo->prepare("SELECT 1 FROM pretes_results WHERE user_id = ? LIMIT 1");
            $stmtRes->execute([$userId]);
            if ($stmtRes->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {
            error_log('studentHasPretesRegistration error: ' . $e->getMessage());
        }
        return false;
    }
}



if (!function_exists('isMahasiswaPretesAktif')) {
    /**
     * Menentukan apakah mahasiswa sedang dalam keadaan pretes aktif:
     * - Role pengguna adalah mahasiswa
     * - Belum dinyatakan lulus pretes
     * - Periode pretes aktif yang eligible untuk mahasiswa tersedia,
     *   ATAU mahasiswa memiliki pendaftaran pada periode pretes yang berstatus aktif.
     *
     * @param PDO $pdo
     * @param array|null $user
     * @return bool
     */
    function isMahasiswaPretesAktif(PDO $pdo, ?array $user): bool {
        if (!$user) {
            return false;
        }
        $role = strtolower(trim((string)($user['role'] ?? 'mahasiswa')));
        if ($role !== 'mahasiswa') {
            return false;
        }

        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        // 1. Jika sudah lulus pretes, maka tidak lagi dalam keadaan pretes aktif
        if (isUserLulusPretes($pdo, $userId)) {
            return false;
        }

        // 2. Ambil periode pretes aktif yang eligible untuk mahasiswa
        ensurePretesPeriodTargetColumns($pdo);
        $activeEligiblePeriod = null;
        try {
            $stmt = $pdo->query("SELECT * FROM master_pretes WHERE status = 'aktif' ORDER BY id DESC");
            $periods = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($periods as $period) {
                if (isUserEligibleForPretesPeriod($user, $period)) {
                    $activeEligiblePeriod = $period;
                    break;
                }
            }
        } catch (Throwable $e) {}

        // 3. Cek pendaftaran pretes terbaru mahasiswa
        $latestReg = null;
        try {
            $stmtReg = $pdo->prepare("
                SELECT pr.*, mp.status as periode_status 
                FROM pretes_registrations pr 
                LEFT JOIN master_pretes mp ON pr.periode = mp.periode 
                WHERE pr.user_id = ? 
                ORDER BY pr.id DESC LIMIT 1
            ");
            $stmtReg->execute([$userId]);
            $latestReg = $stmtReg->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}

        // Mahasiswa terdaftar pada periode pretes yang berstatus aktif
        if ($latestReg && strtolower(trim((string)($latestReg['periode_status'] ?? ''))) === 'aktif') {
            return true;
        }

        // Ada periode pretes aktif yang eligible bagi mahasiswa dan mahasiswa belum lulus
        if ($activeEligiblePeriod !== null) {
            return true;
        }

        return false;
    }
}

if (!function_exists('mapPretesScoresToCertificate')) {
    /**
     * Memetakan 12 nilai komponen pretes ke dalam 6 komponen nilai transkrip sertifikat (tutorial_registrations).
     *
     * 1. Thaharah     : Rata-rata dari (praktik_wudlu, doa_setelah_wudlu, praktik_tayammum, niat_mandi_besar)
     * 2. Shalat       : Rata-rata dari (niat_jamak_qashar, bacaan_shalat_subuh)
     * 3. Surat Pendek : Rata-rata dari (al_bayyinah, al_alaq, al_dluha)
     * 4. Amaliyah     : Nilai istighasah
     * 5. Jenazah      : Nilai shalat_jenazah
     * 6. Ujian Tulis  : Nilai ujian_tulis
     *
     * @param array $scores Associative array [key => float|int]
     * @return array [
     *   'thaharah'     => float,
     *   'shalat'       => float,
     *   'surat_pendek' => float,
     *   'amaliyah'     => float,
     *   'jenazah'      => float,
     *   'ujian_tulis'  => float,
     *   'rata_rata'    => float,
     * ]
     */
    function mapPretesScoresToCertificate(array $scores): array {
        $thaharah = round((
            (float)($scores['praktik_wudlu'] ?? 0) +
            (float)($scores['doa_setelah_wudlu'] ?? 0) +
            (float)($scores['praktik_tayammum'] ?? 0) +
            (float)($scores['niat_mandi_besar'] ?? 0)
        ) / 4, 2);

        $shalat = round((
            (float)($scores['niat_jamak_qashar'] ?? 0) +
            (float)($scores['bacaan_shalat_subuh'] ?? 0)
        ) / 2, 2);

        $suratPendek = round((
            (float)($scores['al_bayyinah'] ?? 0) +
            (float)($scores['al_alaq'] ?? 0) +
            (float)($scores['al_dluha'] ?? 0)
        ) / 3, 2);

        $amaliyah   = round((float)($scores['istighasah'] ?? 0), 2);
        $jenazah    = round((float)($scores['shalat_jenazah'] ?? 0), 2);
        $ujianTulis = round((float)($scores['ujian_tulis'] ?? 0), 2);

        $rataRata = round(($thaharah + $shalat + $suratPendek + $amaliyah + $jenazah + $ujianTulis) / 6, 2);

        return [
            'thaharah'     => $thaharah,
            'shalat'       => $shalat,
            'surat_pendek' => $suratPendek,
            'amaliyah'     => $amaliyah,
            'jenazah'      => $jenazah,
            'ujian_tulis'  => $ujianTulis,
            'rata_rata'    => $rataRata,
        ];
    }
}

if (!function_exists('syncPretesToKelolaNilai')) {
    /**
     * Mensinkronkan nilai peserta pretes yang LULUS ke tabel tutorial_registrations
     * agar tampil di halaman Kelola Nilai (admin/kelola-nilai.php) dan siap dicetak sertifikatnya.
     *
     * @param PDO $pdo
     * @param string $periodeName Nama periode pretes (misal "2026/2027 RPL")
     * @param string|null $tahunAjaran Tahun ajaran (opsional, jika null diambil dari master_pretes)
     * @return array Ringkasan hasil sinkronisasi
     */
    function syncPretesToKelolaNilai(PDO $pdo, string $periodeName, ?string $tahunAjaran = null): array {
        $periodeName = trim($periodeName);
        if ($periodeName === '') {
            throw new InvalidArgumentException("Nama periode pretes tidak boleh kosong.");
        }

        // Resolusi tahun ajaran jika belum diberikan
        if (empty($tahunAjaran)) {
            try {
                $stmtTa = $pdo->prepare("SELECT tahun_ajaran FROM master_pretes WHERE periode = ? ORDER BY id DESC LIMIT 1");
                $stmtTa->execute([$periodeName]);
                $tahunAjaran = trim((string)$stmtTa->fetchColumn());
            } catch (Throwable $e) {
                $tahunAjaran = '';
            }
        }
        if (empty($tahunAjaran)) {
            $tahunAjaran = $periodeName;
        }

        // Pastikan kolom tahun_ajaran & tipe_nilai tersedia di tutorial_registrations
        try {
            $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            if ($driver === 'sqlite') {
                $cols = $pdo->query("PRAGMA table_info(tutorial_registrations)")->fetchAll(PDO::FETCH_ASSOC);
                $colNames = array_map(fn($c) => strtolower((string)($c['name'] ?? '')), $cols);
                if (!in_array('tahun_ajaran', $colNames, true)) {
                    $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN tahun_ajaran VARCHAR(50) DEFAULT NULL");
                }
                if (!in_array('tipe_nilai', $colNames, true)) {
                    $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN tipe_nilai VARCHAR(50) DEFAULT NULL");
                }
                $hasNilaiAkhir = in_array('nilai_akhir', $colNames, true);
            } else {
                $cTa = $pdo->query("SHOW COLUMNS FROM tutorial_registrations LIKE 'tahun_ajaran'")->fetch();
                if (!$cTa) {
                    $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN tahun_ajaran VARCHAR(50) DEFAULT NULL AFTER status");
                }
                $cTipe = $pdo->query("SHOW COLUMNS FROM tutorial_registrations LIKE 'tipe_nilai'")->fetch();
                if (!$cTipe) {
                    $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN tipe_nilai VARCHAR(50) DEFAULT NULL AFTER tahun_ajaran");
                }
                $cNa = $pdo->query("SHOW COLUMNS FROM tutorial_registrations LIKE 'nilai_akhir'")->fetch();
                $hasNilaiAkhir = (bool)$cNa;
            }
        } catch (Throwable $e) {
            $hasNilaiAkhir = false;
        }

        // Ambil semua pendaftar pretes pada periode ini yang memiliki data asesmen
        $stmtCandidates = $pdo->prepare("
            SELECT pr.id as reg_id, pr.user_id, pr.periode, pa.scores_json,
                   u.nama_lengkap, u.nim
            FROM pretes_registrations pr
            JOIN users u ON pr.user_id = u.id
            JOIN pretes_assessments pa ON pa.registration_id = pr.id
            WHERE pr.periode = ?
        ");
        $stmtCandidates->execute([$periodeName]);
        $candidates = $stmtCandidates->fetchAll(PDO::FETCH_ASSOC);

        $syncedCount   = 0;
        $insertedCount = 0;
        $updatedCount  = 0;
        $skippedCount  = 0;

        $findRegStmt = $pdo->prepare("
            SELECT id, nomor_sertifikat FROM tutorial_registrations 
            WHERE user_id = ? 
            ORDER BY (LOWER(COALESCE(tipe_nilai, '')) = 'pretest') DESC, id DESC 
            LIMIT 1
        ");

        if ($hasNilaiAkhir) {
            $updateStmt = $pdo->prepare("
                UPDATE tutorial_registrations 
                SET status = 'lulus',
                    gelombang = COALESCE(NULLIF(gelombang, ''), 'pretest'),
                    tahun_ajaran = ?,
                    tipe_nilai = 'pretest',
                    nilai_thaharah = ?,
                    nilai_shalat = ?,
                    nilai_surat_pendek = ?,
                    nilai_amaliyah = ?,
                    nilai_jenazah = ?,
                    nilai_ujian_tulis = ?,
                    nilai_akhir = ?
                WHERE id = ?
            ");
            $insertStmt = $pdo->prepare("
                INSERT INTO tutorial_registrations 
                (user_id, status, gelombang, tahun_ajaran, tipe_nilai, nilai_thaharah, nilai_shalat, nilai_surat_pendek, nilai_amaliyah, nilai_jenazah, nilai_ujian_tulis, nilai_akhir)
                VALUES (?, 'lulus', 'pretest', ?, 'pretest', ?, ?, ?, ?, ?, ?, ?)
            ");
        } else {
            $updateStmt = $pdo->prepare("
                UPDATE tutorial_registrations 
                SET status = 'lulus',
                    gelombang = COALESCE(NULLIF(gelombang, ''), 'pretest'),
                    tahun_ajaran = ?,
                    tipe_nilai = 'pretest',
                    nilai_thaharah = ?,
                    nilai_shalat = ?,
                    nilai_surat_pendek = ?,
                    nilai_amaliyah = ?,
                    nilai_jenazah = ?,
                    nilai_ujian_tulis = ?
                WHERE id = ?
            ");
            $insertStmt = $pdo->prepare("
                INSERT INTO tutorial_registrations 
                (user_id, status, gelombang, tahun_ajaran, tipe_nilai, nilai_thaharah, nilai_shalat, nilai_surat_pendek, nilai_amaliyah, nilai_jenazah, nilai_ujian_tulis)
                VALUES (?, 'lulus', 'pretest', ?, 'pretest', ?, ?, ?, ?, ?, ?)
            ");
        }

        $pdo->beginTransaction();
        try {
            foreach ($candidates as $cand) {
                $scores = json_decode($cand['scores_json'] ?? '{}', true);
                $statusLulus = getPretesStatusLulus($scores);

                // Syarat mutlak masuk ke Kelola Nilai & Cetak Sertifikat: seluruh 12 komponen lengkap & >= 80 (status LULUS)
                if ($statusLulus !== 'lulus') {
                    $skippedCount++;
                    continue;
                }

                $mapped = mapPretesScoresToCertificate($scores);
                $userId = (int)$cand['user_id'];

                $findRegStmt->execute([$userId]);
                $existingReg = $findRegStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingReg && !empty($existingReg['id'])) {
                    $regId = (int)$existingReg['id'];
                    if ($hasNilaiAkhir) {
                        $updateStmt->execute([
                            $tahunAjaran,
                            $mapped['thaharah'],
                            $mapped['shalat'],
                            $mapped['surat_pendek'],
                            $mapped['amaliyah'],
                            $mapped['jenazah'],
                            $mapped['ujian_tulis'],
                            $mapped['rata_rata'],
                            $regId,
                        ]);
                    } else {
                        $updateStmt->execute([
                            $tahunAjaran,
                            $mapped['thaharah'],
                            $mapped['shalat'],
                            $mapped['surat_pendek'],
                            $mapped['amaliyah'],
                            $mapped['jenazah'],
                            $mapped['ujian_tulis'],
                            $regId,
                        ]);
                    }
                    $updatedCount++;
                } else {
                    if ($hasNilaiAkhir) {
                        $insertStmt->execute([
                            $userId,
                            $tahunAjaran,
                            $mapped['thaharah'],
                            $mapped['shalat'],
                            $mapped['surat_pendek'],
                            $mapped['amaliyah'],
                            $mapped['jenazah'],
                            $mapped['ujian_tulis'],
                            $mapped['rata_rata'],
                        ]);
                    } else {
                        $insertStmt->execute([
                            $userId,
                            $tahunAjaran,
                            $mapped['thaharah'],
                            $mapped['shalat'],
                            $mapped['surat_pendek'],
                            $mapped['amaliyah'],
                            $mapped['jenazah'],
                            $mapped['ujian_tulis'],
                        ]);
                    }
                    $insertedCount++;
                }
                $syncedCount++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'success'          => true,
            'synced'           => $syncedCount,
            'inserted'         => $insertedCount,
            'updated'          => $updatedCount,
            'skipped'          => $skippedCount,
            'total_candidates' => count($candidates),
            'tahun_ajaran'     => $tahunAjaran,
            'periode'          => $periodeName,
        ];
    }
}

if (!function_exists('ensurePretesProctoringTables')) {
    /**
     * Memastikan tabel log pengawasan AI (Proctoring) tersedia di database.
     * Mendukung SQLite (in-memory test) dan MySQL/MariaDB (cPanel production).
     */
    function ensurePretesProctoringTables(PDO $pdo): void {
        static $checked = false;
        if ($checked) return;
        // Jalur normal cukup membaca tabel; hindari DDL di setiap request kamera.
        try {
            $pdo->query("SELECT 1 FROM pretes_proctoring_logs LIMIT 0");
            $checked = true;
            return;
        } catch (PDOException $e) {
            // Instalasi pertama: buat tabel di bawah bila belum tersedia.
        }
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_proctoring_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    registration_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    event_type VARCHAR(30) NOT NULL,
                    similarity_score REAL DEFAULT 0.0,
                    status VARCHAR(30) NOT NULL,
                    snapshot_path VARCHAR(255) DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_proc_reg_date ON pretes_proctoring_logs (registration_id, created_at)");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_proctoring_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    registration_id INT NOT NULL,
                    user_id INT NOT NULL,
                    event_type VARCHAR(30) NOT NULL,
                    similarity_score DECIMAL(5,2) DEFAULT 0.00,
                    status VARCHAR(30) NOT NULL,
                    snapshot_path VARCHAR(255) DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_proc_reg_date (registration_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
            $checked = true;
        } catch (Throwable $e) {
            error_log("ensurePretesProctoringTables warning: " . $e->getMessage());
        }
    }
}

if (!function_exists('pretesPeriodicSnapshotNeeded')) {
function pretesPeriodicSnapshotNeeded(PDO $pdo, int $registrationId, bool $matched): bool {
    if (!$matched) return true;
    $cutoff = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? "datetime('now', '-5 minutes')" : 'DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 5 MINUTE)';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pretes_proctoring_logs WHERE registration_id = ? AND status = 'MATCH' AND snapshot_path IS NOT NULL AND created_at >= $cutoff");
    $stmt->execute([$registrationId]);
    return (int)$stmt->fetchColumn() === 0;
}
}

if (!function_exists('saveProctoringSnapshot')) {
    /**
     * Menyimpan snapshot foto pengawasan kamera live (Skenario A & Skenario B)
     * ke folder `uploads/pretes_proctoring/` yang dilindungi .htaccess.
     */
    function saveProctoringSnapshot(string $photoPayload, string $nim, string $type = 'checkin'): ?string {
        $cleanPayload = trim($photoPayload);
        if ($cleanPayload === '' || !str_starts_with($cleanPayload, 'data:image/')) {
            return null;
        }

        $baseDir = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
        $targetDir = $baseDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pretes_proctoring';
        if (!is_dir($targetDir)) {
            if (!@mkdir($targetDir, 0755, true)) {
                return null;
            }
        }

        $htaccessFile = $targetDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccessFile)) {
            @file_put_contents($htaccessFile, "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phps)$\">\nOrder Deny,Allow\nDeny from all\n</FilesMatch>\nOptions -ExecCGI\n");
        }

        $commaPos = strpos($cleanPayload, ',');
        if ($commaPos === false) return null;
        $rawBase64 = substr($cleanPayload, $commaPos + 1);
        $binaryData = base64_decode($rawBase64, true);
        if ($binaryData === false || strlen($binaryData) < 100) return null;

        $cleanNim = preg_replace('/[^a-zA-Z0-9_-]/', '', $nim) ?: 'peserta';
        $cleanType = preg_replace('/[^a-zA-Z0-9_-]/', '', $type) ?: 'snap';
        $fileName = 'proc_' . $cleanNim . '_' . $cleanType . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.jpg';
        $filePath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($binaryData);
            if ($img) {
                imagejpeg($img, $filePath, 85);
                imagedestroy($img);
                return 'uploads/pretes_proctoring/' . $fileName;
            }
        }
        if (@file_put_contents($filePath, $binaryData) !== false) {
            return 'uploads/pretes_proctoring/' . $fileName;
        }
        return null;
    }
}

// Legacy callers also use the server model. No GD/pixel fallback may grant access.
require_once __DIR__ . '/pretes-face-engine.php';
if (!function_exists('compareFacesGD')) {
    function compareFacesGD(string $refPhotoPath, string $livePayload): array {
        return comparePretesFaces($refPhotoPath, [$livePayload]);
    }
}

if (!function_exists('getStudentProctoringLogs')) {
    /**
     * Mengambil riwayat log pengawasan proctoring untuk registrasi pretes tertentu.
     */
    function getStudentProctoringLogs(PDO $pdo, int $registrationId): array {
        ensurePretesProctoringTables($pdo);
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM pretes_proctoring_logs 
                WHERE registration_id = ? 
                ORDER BY created_at DESC, id DESC
            ");
            $stmt->execute([$registrationId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('pretesOfficialScoresByRegistration')) {
    /**
     * Nilai hasil pretes resmi per pendaftaran: CBT resmi yang sudah dikumpulkan
     * (pretes_cbt_attempts.registration_id = id pendaftaran; dipakai yang terakhir dikumpulkan),
     * cadangan nilai ujian_tulis di pretes_assessments (mis. impor E-Ujian).
     * Belum pretes → tidak ada kunci (tampilkan "-").
     * @return array<int, array{score: float, source: string}>
     */
    function pretesOfficialScoresByRegistration(PDO $pdo, array $regIds): array {
        $out = [];
        $regIds = array_values(array_unique(array_filter(array_map('intval', $regIds), static fn($v) => $v > 0)));
        foreach (array_chunk($regIds, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            try {
                $st = $pdo->prepare("SELECT registration_id, score FROM pretes_cbt_attempts
                    WHERE registration_id IN ($ph) AND submitted_at IS NOT NULL AND score IS NOT NULL
                    ORDER BY submitted_at ASC, id ASC");
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[(int)$r['registration_id']] = ['score' => (float)$r['score'], 'source' => 'CBT'];
                }
            } catch (Throwable $e) {}
            try {
                $st = $pdo->prepare("SELECT registration_id, scores_json FROM pretes_assessments WHERE registration_id IN ($ph)");
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $rid = (int)$r['registration_id'];
                    if (isset($out[$rid])) continue;
                    $sj = json_decode((string)$r['scores_json'], true);
                    $ut = is_array($sj) ? ($sj['ujian_tulis'] ?? null) : null;
                    if ($ut !== null && $ut !== '' && is_numeric($ut)) {
                        $out[$rid] = ['score' => (float)$ut, 'source' => 'Ujian tulis'];
                    }
                }
            } catch (Throwable $e) {}
        }
        return $out;
    }
}

if (!function_exists('pretesFormatScore')) {
    /** 81.5 → "81,5"; 92.0 → "92". */
    function pretesFormatScore(float $score): string {
        return rtrim(rtrim(number_format($score, 2, ',', ''), '0'), ',');
    }
}

if (!function_exists('pretesParseHariFilter')) {
    /**
     * Filter hari multi-pilih dari parameter GET ("Senin,Rabu" atau hari[]=Senin&hari[]=Rabu).
     * Hanya Senin–Jumat, Sabtu, Ahad yang diterima ("Minggu" → Ahad); urutan mengikuti urutan hari.
     * @return string[]
     */
    function pretesParseHariFilter($raw): array {
        $valid = pretesAllDays();
        $parts = is_array($raw) ? $raw : explode(',', (string)$raw);
        $want = [];
        foreach ($parts as $p) {
            $d = pretesNormalizeDay($p);
            if ($d !== '' && in_array($d, $valid, true)) $want[$d] = true;
        }
        return array_values(array_filter($valid, static fn($d) => isset($want[$d])));
    }
}

if (!function_exists('pretesXlsxColLetter')) {
    function pretesXlsxColLetter($num) {
        $col = '';
        while ($num > 0) {
            $mod = ($num - 1) % 26;
            $col = chr(65 + $mod) . $col;
            $num = intdiv($num - $mod - 1, 26);
        }
        return $col;
    }
}

if (!function_exists('pretesGetCbtCategoryExportData')) {
    /**
     * Agregasi data hasil CBT pretes resmi per kategori/grup materi soal untuk daftar mahasiswa ($roster).
     *
     * @param PDO $pdo
     * @param array $roster Daftar peserta pretes
     * @return array{categories: string[], rows: array}
     */
    function pretesGetCbtCategoryExportData(PDO $pdo, array $roster): array {
        $regIds = array_values(array_filter(array_map('intval', array_column($roster, 'reg_id')), static fn($v) => $v > 0));
        $userToRegMap = [];
        foreach ($roster as $r) {
            $uId = (int)($r['user_id'] ?? 0);
            $rId = (int)($r['reg_id'] ?? 0);
            if ($uId > 0 && $rId > 0) {
                $userToRegMap[$uId] = $rId;
            }
        }

        $cbtAttempts = [];
        if (!empty($regIds)) {
            foreach (array_chunk($regIds, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                try {
                    $stmt = $pdo->prepare("
                        SELECT a.id as attempt_id, a.user_id, a.registration_id, a.session_id, a.score, a.correct_count, a.started_at, a.deadline_at, a.submitted_at,
                               s.title as session_title
                        FROM pretes_cbt_attempts a
                        LEFT JOIN pretes_cbt_sessions s ON s.id = a.session_id
                        WHERE a.registration_id IN ($ph)
                        ORDER BY a.submitted_at DESC, a.id DESC
                    ");
                    $stmt->execute($chunk);
                    while ($att = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $rid = (int)$att['registration_id'];
                        if (!isset($cbtAttempts[$rid])) {
                            $cbtAttempts[$rid] = $att;
                        }
                    }
                } catch (Throwable $e) {}
            }
        }

        // Fallback pencarian attempt berdasarkan user_id jika ada pendaftaran yang belum terpetakan langsung
        $missingUserIds = [];
        foreach ($roster as $r) {
            $rid = (int)($r['reg_id'] ?? 0);
            $uid = (int)($r['user_id'] ?? 0);
            if (!isset($cbtAttempts[$rid]) && $uid > 0) {
                $missingUserIds[] = $uid;
            }
        }
        if (!empty($missingUserIds)) {
            foreach (array_chunk(array_unique($missingUserIds), 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                try {
                    $stmt = $pdo->prepare("
                        SELECT a.id as attempt_id, a.user_id, a.registration_id, a.session_id, a.score, a.correct_count, a.started_at, a.deadline_at, a.submitted_at,
                               s.title as session_title
                        FROM pretes_cbt_attempts a
                        LEFT JOIN pretes_cbt_sessions s ON s.id = a.session_id
                        WHERE a.user_id IN ($ph) AND a.registration_id > 0 AND a.submitted_at IS NOT NULL
                        ORDER BY a.submitted_at DESC, a.id DESC
                    ");
                    $stmt->execute($chunk);
                    while ($att = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $uid = (int)$att['user_id'];
                        if (isset($userToRegMap[$uid])) {
                            $rid = $userToRegMap[$uid];
                            if (!isset($cbtAttempts[$rid])) {
                                $cbtAttempts[$rid] = $att;
                            }
                        }
                    }
                } catch (Throwable $e) {}
            }
        }

        $attemptIds = [];
        foreach ($cbtAttempts as $att) {
            if (!empty($att['attempt_id']) && !empty($att['submitted_at'])) {
                $attemptIds[] = (int)$att['attempt_id'];
            }
        }
        $attemptIds = array_values(array_unique($attemptIds));

        $statsByAttempt = [];
        $foundCategories = [];

        if (!empty($attemptIds)) {
            foreach (array_chunk($attemptIds, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                try {
                    $stmt = $pdo->prepare("
                        SELECT i.attempt_id,
                               COALESCE(NULLIF(TRIM(q.category), ''), 'Umum') AS cat_name,
                               SUM(CASE WHEN i.answer IS NOT NULL AND i.answer <> '' AND i.answer = i.correct_answer THEN 1 ELSE 0 END) AS benar_count,
                               COUNT(i.position) AS total_count
                        FROM pretes_cbt_items i
                        LEFT JOIN pretes_cbt_questions q ON q.id = i.question_id
                        WHERE i.attempt_id IN ($ph)
                        GROUP BY i.attempt_id, COALESCE(NULLIF(TRIM(q.category), ''), 'Umum')
                    ");
                    $stmt->execute($chunk);
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $aid = (int)$row['attempt_id'];
                        $cat = trim((string)$row['cat_name']);
                        if ($cat === '') $cat = 'Umum';
                        $benar = (int)$row['benar_count'];
                        $tot = (int)$row['total_count'];
                        $pct = ($tot > 0) ? round(($benar / $tot) * 100, 1) : 0.0;
                        $statsByAttempt[$aid][$cat] = [
                            'benar' => $benar,
                            'total' => $tot,
                            'score' => $pct,
                        ];
                        $foundCategories[$cat] = true;
                    }
                } catch (Throwable $e) {}
            }
        }

        // Tentukan daftar kategori
        $categories = array_keys($foundCategories);
        if (empty($categories)) {
            try {
                $catStmt = $pdo->query("SELECT DISTINCT category FROM pretes_cbt_questions WHERE is_active = 1 ORDER BY category ASC");
                $categories = $catStmt ? $catStmt->fetchAll(PDO::FETCH_COLUMN) : [];
                $categories = array_values(array_filter(array_map('trim', $categories)));
            } catch (Throwable $e) {}
        }
        if (empty($categories)) {
            $categories = ['Umum'];
        } else {
            sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
        }

        $passMin = function_exists('pretesCbtPassMinScore') ? pretesCbtPassMinScore() : 80.0;

        $rows = [];
        foreach ($roster as $idx => $r) {
            $rid = (int)($r['reg_id'] ?? 0);
            $att = $cbtAttempts[$rid] ?? null;
            $aid = !empty($att['attempt_id']) ? (int)$att['attempt_id'] : 0;
            $isSubmitted = ($att && !empty($att['submitted_at']));

            $catData = [];
            $totalSoal = 0;
            $totalBenar = 0;

            foreach ($categories as $cat) {
                if ($isSubmitted && isset($statsByAttempt[$aid][$cat])) {
                    $cs = $statsByAttempt[$aid][$cat];
                    $catData[$cat] = [
                        'score' => $cs['score'],
                        'ratio' => $cs['benar'] . '/' . $cs['total'],
                    ];
                    $totalSoal += $cs['total'];
                    $totalBenar += $cs['benar'];
                } else {
                    $catData[$cat] = [
                        'score' => null,
                        'ratio' => '-',
                    ];
                }
            }

            if ($isSubmitted) {
                $finalScore = isset($att['score']) && $att['score'] !== null ? round((float)$att['score'], 2) : ($totalSoal > 0 ? round(($totalBenar / $totalSoal) * 100, 2) : 0.0);
                $statusLulus = ($finalScore >= $passMin) ? 'LULUS' : 'TIDAK LULUS';
                $waktuSelesai = date('d/m/Y H:i', (int)$att['submitted_at']) . ' WIB';
                $sessionTitle = !empty($att['session_title']) ? $att['session_title'] : 'Sesi CBT Resmi';
            } else {
                $finalScore = null;
                $statusLulus = 'BELUM CBT';
                $waktuSelesai = '-';
                $sessionTitle = '-';
                $totalSoal = '-';
                $totalBenar = '-';
            }

            $rows[] = [
                'no'             => $idx + 1,
                'nim'            => (string)($r['nim'] ?? ''),
                'nama_lengkap'   => $r['nama_lengkap'] ?? '',
                'program_studi'  => $r['program_studi'] ?? '',
                'jenis_program'  => function_exists('normalizeJenisProgram') ? normalizeJenisProgram($r['jenis_program'] ?? 'Reguler') : ($r['jenis_program'] ?? 'Reguler'),
                'fakultas'       => $r['fakultas'] ?? '',
                'session_title'  => $sessionTitle,
                'waktu_selesai'  => $waktuSelesai,
                'categories'     => $catData,
                'total_soal'     => $totalSoal,
                'total_benar'    => $totalBenar,
                'final_score'    => $finalScore,
                'status_lulus'   => $statusLulus,
                'is_submitted'   => $isSubmitted,
            ];
        }

        return [
            'categories' => $categories,
            'rows'       => $rows,
        ];
    }
}

if (!function_exists('pretesExportCbtCategoriesToCsv')) {
    /**
     * Ekspor hasil CBT per kategori ke format CSV UTF-8.
     */
    function pretesExportCbtCategoriesToCsv($filename, $headers, $categories, $exportRows, bool $returnContent = false) {
        if ($returnContent) {
            $fp = fopen('php://temp', 'r+');
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, $headers);
            foreach ($exportRows as $r) {
                $line = [
                    $r['no'],
                    "'" . $r['nim'],
                    $r['nama_lengkap'],
                    $r['program_studi'],
                    $r['jenis_program'],
                    $r['fakultas'],
                    $r['session_title'],
                    $r['waktu_selesai'],
                ];
                foreach ($categories as $cat) {
                    $catItem = $r['categories'][$cat] ?? null;
                    $line[] = ($catItem && $catItem['score'] !== null) ? $catItem['score'] : '-';
                    $line[] = ($catItem && $catItem['ratio'] !== null) ? $catItem['ratio'] : '-';
                }
                $line[] = $r['total_soal'];
                $line[] = $r['total_benar'];
                $line[] = ($r['final_score'] !== null) ? $r['final_score'] : '-';
                $line[] = $r['status_lulus'];
                fputcsv($fp, $line);
            }
            rewind($fp);
            $csv = stream_get_contents($fp);
            fclose($fp);
            return $csv;
        }

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
        echo "\xEF\xBB\xBF"; // UTF-8 BOM

        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);

        foreach ($exportRows as $r) {
            $line = [
                $r['no'],
                "'" . $r['nim'],
                $r['nama_lengkap'],
                $r['program_studi'],
                $r['jenis_program'],
                $r['fakultas'],
                $r['session_title'],
                $r['waktu_selesai'],
            ];
            foreach ($categories as $cat) {
                $catItem = $r['categories'][$cat] ?? null;
                $line[] = ($catItem && $catItem['score'] !== null) ? $catItem['score'] : '-';
                $line[] = ($catItem && $catItem['ratio'] !== null) ? $catItem['ratio'] : '-';
            }
            $line[] = $r['total_soal'];
            $line[] = $r['total_benar'];
            $line[] = ($r['final_score'] !== null) ? $r['final_score'] : '-';
            $line[] = $r['status_lulus'];
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }
}

if (!function_exists('pretesExportCbtCategoriesToXlsxNative')) {
    /**
     * Ekspor hasil CBT per kategori ke format XLSX asli (OpenXML) menggunakan ZipArchive.
     */
    function pretesExportCbtCategoriesToXlsxNative($filename, $periodeName, $headers, $categories, $exportRows, bool $returnContent = false) {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'rekap_cbt_xlsx_') . '.xlsx';
        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        // [Content_Types].xml
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '</Types>');

        // _rels/.rels
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>');

        // xl/_rels/workbook.xml.rels
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>');

        // xl/workbook.xml
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="20480" windowHeight="10240"/></bookViews>' .
            '<sheets><sheet name="Rekap CBT Kategori" sheetId="1" r:id="rId1"/></sheets>' .
            '</workbook>');

        // xl/styles.xml
        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="5">' .
            '<font><sz val="11"/><color rgb="FF0F172A"/><name val="Calibri"/><family val="2"/></font>' .
            '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>' .
            '<font><b/><sz val="13"/><color rgb="FF0D5C3A"/><name val="Calibri"/><family val="2"/></font>' .
            '<font><sz val="11"/><color rgb="FF475569"/><name val="Calibri"/><family val="2"/></font>' .
            '<font><b/><sz val="11"/><color rgb="FF1E293B"/><name val="Calibri"/><family val="2"/></font>' .
            '</fonts>' .
            '<fills count="4">' .
            '<fill><patternFill patternType="none"/></fill>' .
            '<fill><patternFill patternType="gray125"/></fill>' .
            '<fill><patternFill patternType="solid"><fgColor rgb="FF0D5C3A"/></patternFill></fill>' .
            '<fill><patternFill patternType="solid"><fgColor rgb="FF0284C7"/></patternFill></fill>' .
            '</fills>' .
            '<borders count="2">' .
            '<border><left/><right/><top/><bottom/><diagonal/></border>' .
            '<border>' .
            '<left style="thin"><color rgb="FFCBD5E1"/></left>' .
            '<right style="thin"><color rgb="FFCBD5E1"/></right>' .
            '<top style="thin"><color rgb="FFCBD5E1"/></top>' .
            '<bottom style="thin"><color rgb="FFCBD5E1"/></bottom>' .
            '<diagonal/>' .
            '</border>' .
            '</borders>' .
            '<cellStyleXfs count="1">' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' .
            '</cellStyleXfs>' .
            '<cellXfs count="6">' .
            '<!-- 0: Default -->' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
            '<!-- 1: Header Green Bold Center -->' .
            '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' .
            '<!-- 2: Data Left -->' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>' .
            '<!-- 3: Data Center -->' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' .
            '<!-- 4: Title Banner -->' .
            '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' .
            '<!-- 5: Subtitle Banner -->' .
            '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' .
            '</cellXfs>' .
            '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $stylesXml);

        // xl/worksheets/sheet1.xml
        $rowsXml = [];

        // Row 1: Banner Title
        $rowsXml[] = '<row r="1" ht="28" customHeight="1">' .
            '<c r="A1" t="inlineStr" s="4"><is><t>LEMBAGA PENGEMBANGAN PENDIDIKAN AGAMA ISLAM (LPPAI) UNISDA</t></is></c>' .
            '</row>';

        // Row 2: Subtitle
        $rowsXml[] = '<row r="2" ht="22" customHeight="1">' .
            '<c r="A2" t="inlineStr" s="5"><is><t>REKAPITULASI NILAI CBT PRETES PER KATEGORI SOAL - PERIODE: ' . htmlspecialchars($periodeName, ENT_XML1, 'UTF-8') . '</t></is></c>' .
            '</row>';

        // Row 4: Column Headers
        $headerCells = [];
        foreach ($headers as $colIdx => $h) {
            $colLetter = pretesXlsxColLetter($colIdx + 1);
            $headerCells[] = '<c r="' . $colLetter . '4" t="inlineStr" s="1"><is><t>' . htmlspecialchars($h, ENT_XML1, 'UTF-8') . '</t></is></c>';
        }
        $rowsXml[] = '<row r="4" ht="26" customHeight="1">' . implode('', $headerCells) . '</row>';

        $totalCols = count($headers);
        $lastColLetter = pretesXlsxColLetter($totalCols);

        if (empty($exportRows)) {
            $rowsXml[] = '<row r="5" ht="24" customHeight="1">' .
                '<c r="A5" t="inlineStr" s="3"><is><t>Tidak ada data peserta yang sesuai dengan filter yang dipilih.</t></is></c>' .
                '</row>';
            $extraMerge = '<mergeCell ref="A5:' . $lastColLetter . '5"/>';
            $mergeCount = 3;
        } else {
            $extraMerge = '';
            $mergeCount = 2;
            $rowNum = 5;
            foreach ($exportRows as $r) {
                $cells = [];
                $colIdx = 1;

                // 1. No
                $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" s="3"><v>' . $r['no'] . '</v></c>';
                // 2. NIM
                $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" t="inlineStr" s="3"><is><t>' . htmlspecialchars($r['nim'], ENT_XML1, 'UTF-8') . '</t></is></c>';
                // 3. Nama
                $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" t="inlineStr" s="2"><is><t>' . htmlspecialchars($r['nama_lengkap'], ENT_XML1, 'UTF-8') . '</t></is></c>';
                // 4. Prodi
                $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" t="inlineStr" s="2"><is><t>' . htmlspecialchars($r['program_studi'], ENT_XML1, 'UTF-8') . '</t></is></c>';
                // 5. Jenis Program
                $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" t="inlineStr" s="3"><is><t>' . htmlspecialchars($r['jenis_program'], ENT_XML1, 'UTF-8') . '</t></is></c>';
                // 6. Fakultas
                $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" t="inlineStr" s="2"><is><t>' . htmlspecialchars($r['fakultas'], ENT_XML1, 'UTF-8') . '</t></is></c>';
                // 7. Sesi CBT
                $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" t="inlineStr" s="2"><is><t>' . htmlspecialchars($r['session_title'], ENT_XML1, 'UTF-8') . '</t></is></c>';
                // 8. Waktu Selesai (WIB)
                $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" t="inlineStr" s="3"><is><t>' . htmlspecialchars($r['waktu_selesai'], ENT_XML1, 'UTF-8') . '</t></is></c>';

                // Breakdown Kategori
                foreach ($categories as $cat) {
                    $catItem = $r['categories'][$cat] ?? null;
                    if ($catItem && $catItem['score'] !== null) {
                        $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" s="3"><v>' . round((float)$catItem['score'], 1) . '</v></c>';
                    } else {
                        $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" t="inlineStr" s="3"><is><t>-</t></is></c>';
                    }
                    $ratioText = ($catItem && $catItem['ratio'] !== null) ? $catItem['ratio'] : '-';
                    $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" t="inlineStr" s="3"><is><t>' . htmlspecialchars($ratioText, ENT_XML1, 'UTF-8') . '</t></is></c>';
                }

                // Total Soal
                if (is_numeric($r['total_soal'])) {
                    $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" s="3"><v>' . (int)$r['total_soal'] . '</v></c>';
                } else {
                    $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" t="inlineStr" s="3"><is><t>-</t></is></c>';
                }

                // Total Benar
                if (is_numeric($r['total_benar'])) {
                    $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" s="3"><v>' . (int)$r['total_benar'] . '</v></c>';
                } else {
                    $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" t="inlineStr" s="3"><is><t>-</t></is></c>';
                }

                // Nilai Akhir CBT
                if ($r['final_score'] !== null && is_numeric($r['final_score'])) {
                    $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" s="3"><v>' . round((float)$r['final_score'], 2) . '</v></c>';
                } else {
                    $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" t="inlineStr" s="3"><is><t>-</t></is></c>';
                }

                // Status Kelulusan
                $cells[] = '<c r="' . pretesXlsxColLetter($colIdx++) . $rowNum . '" t="inlineStr" s="3"><is><t>' . htmlspecialchars($r['status_lulus'], ENT_XML1, 'UTF-8') . '</t></is></c>';

                $rowsXml[] = '<row r="' . $rowNum . '">' . implode('', $cells) . '</row>';
                $rowNum++;
            }
        }

        // Lebar Kolom Dinamis
        $colsXml = [];
        for ($cNum = 1; $cNum <= $totalCols; $cNum++) {
            $w = 14;
            if ($cNum === 1) $w = 6;
            elseif ($cNum === 2) $w = 16;
            elseif ($cNum === 3) $w = 28;
            elseif ($cNum === 4) $w = 22;
            elseif ($cNum === 5) $w = 14;
            elseif ($cNum === 6) $w = 16;
            elseif ($cNum === 7) $w = 24;
            elseif ($cNum === 8) $w = 18;
            elseif ($cNum === $totalCols) $w = 16;
            $colsXml[] = '<col min="' . $cNum . '" max="' . $cNum . '" width="' . $w . '" customWidth="1"/>';
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<cols>' . implode('', $colsXml) . '</cols>' .
            '<sheetData>' . implode('', $rowsXml) . '</sheetData>' .
            '<mergeCells count="' . $mergeCount . '">' .
            '<mergeCell ref="A1:' . $lastColLetter . '1"/>' .
            '<mergeCell ref="A2:' . $lastColLetter . '2"/>' .
            $extraMerge .
            '</mergeCells>' .
            '</worksheet>';

        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        if ($returnContent) {
            $content = file_get_contents($tmpFile);
            @unlink($tmpFile);
            return $content;
        }

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"{$filename}.xlsx\"");
        header('Content-Length: ' . filesize($tmpFile));
        header('Cache-Control: max-age=0, no-cache, must-revalidate');
        header('Pragma: public');
        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    }
}

if (!function_exists('pretesExportCbtCategoriesToXlsXml')) {
    /**
     * Ekspor hasil CBT per kategori ke format XML Spreadsheet (Excel 2003 .xls).
     */
    function pretesExportCbtCategoriesToXlsXml($filename, $periodeName, $headers, $categories, $exportRows, bool $returnContent = false) {
        if ($returnContent) {
            ob_start();
        } else {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
            header("Content-Disposition: attachment; filename=\"{$filename}.xls\"");
            header('Cache-Control: max-age=0, no-cache, must-revalidate');
            header('Pragma: public');
        }

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
        echo ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
        echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
        echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
        echo '<Styles>' . "\n";
        echo ' <Style ss:ID="Default" ss:Name="Normal"><Font ss:FontName="Calibri" ss:Size="11" ss:Color="#0F172A"/></Style>' . "\n";
        echo ' <Style ss:ID="Header"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#0D5C3A" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/></Borders></Style>' . "\n";
        echo ' <Style ss:ID="DataLeft"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/></Borders></Style>' . "\n";
        echo ' <Style ss:ID="DataCenter"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/></Borders></Style>' . "\n";
        echo ' <Style ss:ID="Title"><Font ss:FontName="Calibri" ss:Size="14" ss:Bold="1" ss:Color="#0D5C3A"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style>' . "\n";
        echo ' <Style ss:ID="SubTitle"><Font ss:FontName="Calibri" ss:Size="11" ss:Color="#475569"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style>' . "\n";
        echo '</Styles>' . "\n";
        echo '<Worksheet ss:Name="Rekap CBT Kategori">' . "\n";
        echo '<Table>' . "\n";

        $totalCols = count($headers);
        for ($i = 0; $i < $totalCols; $i++) {
            $w = 90;
            if ($i === 0) $w = 40;
            elseif ($i === 1) $w = 100;
            elseif ($i === 2) $w = 170;
            elseif ($i === 3) $w = 130;
            elseif ($i === 4) $w = 85;
            elseif ($i === 5) $w = 100;
            elseif ($i === 6) $w = 150;
            elseif ($i === 7) $w = 110;
            echo '<Column ss:Width="' . $w . '"/>' . "\n";
        }

        echo '<Row ss:Height="28"><Cell ss:MergeAcross="' . ($totalCols - 1) . '" ss:StyleID="Title"><Data ss:Type="String">LEMBAGA PENGEMBANGAN PENDIDIKAN AGAMA ISLAM (LPPAI) UNISDA</Data></Cell></Row>' . "\n";
        echo '<Row ss:Height="22"><Cell ss:MergeAcross="' . ($totalCols - 1) . '" ss:StyleID="SubTitle"><Data ss:Type="String">REKAPITULASI NILAI CBT PRETES PER KATEGORI SOAL - PERIODE: ' . htmlspecialchars($periodeName, ENT_XML1, 'UTF-8') . '</Data></Cell></Row>' . "\n";
        echo '<Row ss:Height="12"></Row>' . "\n";

        echo '<Row ss:Height="26">' . "\n";
        foreach ($headers as $h) {
            echo '<Cell ss:StyleID="Header"><Data ss:Type="String">' . htmlspecialchars($h, ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
        }
        echo '</Row>' . "\n";

        if (empty($exportRows)) {
            echo '<Row ss:Height="24"><Cell ss:MergeAcross="' . ($totalCols - 1) . '" ss:StyleID="DataCenter"><Data ss:Type="String">Tidak ada data peserta yang sesuai dengan filter yang dipilih.</Data></Cell></Row>' . "\n";
        } else {
            foreach ($exportRows as $r) {
                echo '<Row ss:Height="20">' . "\n";
                echo '<Cell ss:StyleID="DataCenter"><Data ss:Type="Number">' . $r['no'] . '</Data></Cell>' . "\n";
                echo '<Cell ss:StyleID="DataCenter"><Data ss:Type="String">' . htmlspecialchars($r['nim'], ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
                echo '<Cell ss:StyleID="DataLeft"><Data ss:Type="String">' . htmlspecialchars($r['nama_lengkap'], ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
                echo '<Cell ss:StyleID="DataLeft"><Data ss:Type="String">' . htmlspecialchars($r['program_studi'], ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
                echo '<Cell ss:StyleID="DataCenter"><Data ss:Type="String">' . htmlspecialchars($r['jenis_program'], ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
                echo '<Cell ss:StyleID="DataLeft"><Data ss:Type="String">' . htmlspecialchars($r['fakultas'], ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
                echo '<Cell ss:StyleID="DataLeft"><Data ss:Type="String">' . htmlspecialchars($r['session_title'], ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
                echo '<Cell ss:StyleID="DataCenter"><Data ss:Type="String">' . htmlspecialchars($r['waktu_selesai'], ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";

                foreach ($categories as $cat) {
                    $catItem = $r['categories'][$cat] ?? null;
                    if ($catItem && $catItem['score'] !== null) {
                        echo '<Cell ss:StyleID="DataCenter"><Data ss:Type="Number">' . round((float)$catItem['score'], 1) . '</Data></Cell>' . "\n";
                    } else {
                        echo '<Cell ss:StyleID="DataCenter"><Data ss:Type="String">-</Data></Cell>' . "\n";
                    }
                    $ratioText = ($catItem && $catItem['ratio'] !== null) ? $catItem['ratio'] : '-';
                    echo '<Cell ss:StyleID="DataCenter"><Data ss:Type="String">' . htmlspecialchars($ratioText, ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
                }

                if (is_numeric($r['total_soal'])) {
                    echo '<Cell ss:StyleID="DataCenter"><Data ss:Type="Number">' . (int)$r['total_soal'] . '</Data></Cell>' . "\n";
                } else {
                    echo '<Cell ss:StyleID="DataCenter"><Data ss:Type="String">-</Data></Cell>' . "\n";
                }

                if (is_numeric($r['total_benar'])) {
                    echo '<Cell ss:StyleID="DataCenter"><Data ss:Type="Number">' . (int)$r['total_benar'] . '</Data></Cell>' . "\n";
                } else {
                    echo '<Cell ss:StyleID="DataCenter"><Data ss:Type="String">-</Data></Cell>' . "\n";
                }

                if ($r['final_score'] !== null && is_numeric($r['final_score'])) {
                    echo '<Cell ss:StyleID="DataCenter"><Data ss:Type="Number">' . round((float)$r['final_score'], 2) . '</Data></Cell>' . "\n";
                } else {
                    echo '<Cell ss:StyleID="DataCenter"><Data ss:Type="String">-</Data></Cell>' . "\n";
                }

                echo '<Cell ss:StyleID="DataCenter"><Data ss:Type="String">' . htmlspecialchars($r['status_lulus'], ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
                echo '</Row>' . "\n";
            }
        }

        echo '</Table>' . "\n";
        echo '</Worksheet>' . "\n";
        echo '</Workbook>' . "\n";

        if ($returnContent) {
            return ob_get_clean();
        }
        exit;
    }
}
