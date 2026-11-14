<?php
/**
 * Helper terpusat untuk normalisasi, migrasi otomatis, dan tampilan kolom jenis_program (`RPL` / `Reguler`).
 */

if (!function_exists('getAllowedJenisProgramOptions')) {
    /**
     * Mengembalikan daftar nilai valid untuk kolom users.jenis_program.
     *
     * @return array<int, string>
     */
    function getAllowedJenisProgramOptions(): array {
        return ['Reguler', 'RPL'];
    }
}

if (!function_exists('normalizeJenisProgram')) {
    /**
     * Menormalkan nilai jenis program agar selalu bernilai persis "RPL" atau "Reguler".
     *
     * @param mixed $value
     */
    function normalizeJenisProgram($value): string {
        $normalized = strtoupper(trim((string)($value ?? '')));
        if ($normalized === 'RPL' || str_contains($normalized, 'RPL')) {
            return 'RPL';
        }
        return 'Reguler';
    }
}

if (!function_exists('ensureJenisProgramColumn')) {
    /**
     * Memastikan kolom `jenis_program` tersedia di tabel `users` (MySQL ENUM atau SQLite TEXT).
     * Menggunakan cache static + session agar pemeriksaan schema berjalan minimal per request.
     */
    function ensureJenisProgramColumn(PDO $pdo): void {
        static $verifiedConnections = [];
        $objectId = function_exists('spl_object_id') ? spl_object_id($pdo) : 0;
        if (isset($verifiedConnections[$objectId])) {
            return;
        }

        $driver = '';
        try {
            $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable $e) {
            $driver = 'mysql';
        }

        if ($driver === 'mysql' && !empty($_SESSION['_schema_jenis_program_ok'])) {
            $verifiedConnections[$objectId] = true;
            return;
        }

        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info(users)");
            $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $exists = false;
            foreach ($cols as $col) {
                if (strtolower((string)($col['name'] ?? '')) === 'jenis_program') {
                    $exists = true;
                    break;
                }
            }
            if (!$exists && !empty($cols)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN jenis_program TEXT NOT NULL DEFAULT 'Reguler'");
            }
            $verifiedConnections[$objectId] = true;
            return;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'jenis_program'");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if (!$row) {
                $pdo->exec("ALTER TABLE users ADD COLUMN jenis_program ENUM('RPL', 'Reguler') NOT NULL DEFAULT 'Reguler' AFTER program_studi");
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['_schema_jenis_program_ok'] = true;
            }
            $verifiedConnections[$objectId] = true;
        } catch (Throwable $e) {
            error_log('ensureJenisProgramColumn error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('renderJenisProgramBadge')) {
    /**
     * Merender badge HTML untuk membedakan status RPL dan Reguler secara visual.
     *
     * @param mixed $value
     */
    function renderJenisProgramBadge($value, bool $compact = false): string {
        $jenis = normalizeJenisProgram($value);
        $isRpl = ($jenis === 'RPL');
        $bg = $isRpl ? '#fef3c7' : '#e0f2fe';
        $color = $isRpl ? '#92400e' : '#0369a1';
        $border = $isRpl ? '#fde68a' : '#bae6fd';
        $pad = $compact ? '2px 7px' : '3px 9px';
        $fontSize = $compact ? '10.5px' : '11.5px';

        return '<span class="badge-jenis-program badge-jenis-' . strtolower($jenis) . '" style="display:inline-flex;align-items:center;padding:' . $pad . ';border-radius:999px;font-size:' . $fontSize . ';font-weight:700;letter-spacing:0.2px;line-height:1.2;background:' . $bg . ';color:' . $color . ';border:1px solid ' . $border . ';white-space:nowrap;">'
            . htmlspecialchars($jenis, ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
}

if (!function_exists('formatProdiDisplay')) {
    /**
     * Menampilkan nama Jurusan / Program Studi beserta badge jenis program (RPL / Reguler).
     *
     * @param mixed $prodi
     * @param mixed $jenisProgram
     */
    function formatProdiDisplay($prodi, $jenisProgram = 'Reguler', bool $compact = false, bool $inline = false): string {
        $prodiStr = trim((string)($prodi ?? ''));
        $prodiHtml = $prodiStr !== ''
            ? '<span>' . htmlspecialchars($prodiStr, ENT_QUOTES, 'UTF-8') . '</span>'
            : '<span style="color:#94a3b8;">-</span>';
        $badge = renderJenisProgramBadge($jenisProgram, $compact);

        if ($inline) {
            return '<span style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;">' . $prodiHtml . ' ' . $badge . '</span>';
        }

        return '<div style="display:flex;flex-direction:column;align-items:flex-start;gap:4px;">'
            . $prodiHtml
            . $badge
            . '</div>';
    }
}

if (!function_exists('parseFlexibleDateToYmd')) {
    /**
     * Mem-parse berbagai format tanggal (Excel serial, ddmmyyyy, d/m/Y, Y-m-d, atau tanggal berbahasa Indonesia)
     * menjadi string 'Y-m-d', atau null jika tidak dikenali.
     *
     * @param mixed $raw
     */
    function parseFlexibleDateToYmd($raw): ?string {
        $val = trim((string)($raw ?? ''));
        if ($val === '' || $val === '-') {
            return null;
        }

        if (is_numeric($val) && strlen($val) !== 8 && class_exists('\PhpOffice\PhpSpreadsheet\Shared\Date')) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$val)->format('Y-m-d');
            } catch (Throwable $e) {
                return null;
            }
        }

        foreach (['dmY', 'd/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d', 'd/m/y', 'd-m-y', 'dmy'] as $fmt) {
            $dt = DateTime::createFromFormat('!' . $fmt, $val);
            if ($dt instanceof DateTime && $dt->format($fmt) === $val) {
                return $dt->format('Y-m-d');
            }
        }

        // Normalisasi nama bulan Indonesia (termasuk variasi ejaan) -> Inggris
        $bulanMap = [
            'januari'   => 'January',   'jan' => 'January',
            'februari'  => 'February',  'pebruari' => 'February', 'feb' => 'February',
            'maret'     => 'March',     'mar' => 'March',
            'april'     => 'April',     'apr' => 'April',
            'mei'       => 'May',
            'juni'      => 'June',      'jun' => 'June',
            'juli'      => 'July',      'jul' => 'July',
            'agustus'   => 'August',    'agu' => 'August',    'agt' => 'August',
            'september' => 'September', 'sep' => 'September',
            'oktober'   => 'October',   'okt' => 'October',
            'november'  => 'November',  'nopember' => 'November', 'nov' => 'November', 'nop' => 'November',
            'desember'  => 'December',  'des' => 'December',
        ];
        $lower = strtolower(preg_replace('/\s+/', ' ', $val));
        $normalized = preg_replace_callback('/\b([a-z]+)\b/', static function (array $m) use ($bulanMap): string {
            return $bulanMap[$m[1]] ?? $m[1];
        }, $lower);

        foreach (['j F Y', 'd F Y', 'j M Y', 'd M Y', 'F j, Y'] as $fmt) {
            $dt = DateTime::createFromFormat('!' . $fmt, $normalized);
            if ($dt instanceof DateTime) {
                return $dt->format('Y-m-d');
            }
        }

        $ts = strtotime($normalized);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        return null;
    }
}

if (!function_exists('detectUserImportHeaderAndMap')) {
    /**
     * Mendeteksi baris header pada sheet import pengguna (termasuk ekspor SIAKAD multi-kolom yang memiliki judul atas)
     * dan memetakan kolom tanpa tertukar antara kolom sejenis (mis. NAMA vs NAMA IBU, TEMPAT LAHIR vs TGL LAHIR).
     *
     * @param array<int, array<int, mixed>> $rows
     * @return array{header_row_index:int, col_map:array<string, int>}
     */
    function detectUserImportHeaderAndMap(array $rows): array {
        $maxScan = min(25, count($rows));
        $bestIdx = 0;
        $bestScore = -1;
        $bestMap = [];

        for ($r = 0; $r < $maxScan; $r++) {
            $row = $rows[$r] ?? [];
            if (!is_array($row)) {
                continue;
            }
            $colMap = [];
            foreach ($row as $idx => $cell) {
                $h = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(
                    ['(ddmmyyyy)', '(mahasiswa/admin/dosen)', '(mahasiswa/admin)', '(rpl/reguler)', 'no. '],
                    ['', '', '', '', 'no '],
                    (string)$cell
                ))));
                if ($h === '') {
                    continue;
                }

                if ($h === 'nim' || preg_match('/\bnim\b/', $h)) {
                    if (!isset($colMap['nim'])) $colMap['nim'] = $idx;
                } elseif (
                    $h === 'nama' || $h === 'nama lengkap' || $h === 'nama mahasiswa'
                    || (str_contains($h, 'nama') && !str_contains($h, 'ibu') && !str_contains($h, 'ayah') && !str_contains($h, 'ortu') && !str_contains($h, 'wali'))
                ) {
                    if (!isset($colMap['nama_lengkap']) || str_contains($h, 'lengkap') || $h === 'nama') {
                        $colMap['nama_lengkap'] = $idx;
                    }
                } elseif (str_contains($h, 'tempat')) {
                    if (!isset($colMap['tempat_lahir'])) $colMap['tempat_lahir'] = $idx;
                } elseif (str_contains($h, 'tgl') || str_contains($h, 'tanggal') || str_contains($h, 'lahir')) {
                    if (!isset($colMap['tanggal_lahir'])) $colMap['tanggal_lahir'] = $idx;
                } elseif (str_contains($h, 'email') || str_contains($h, 'e-mail')) {
                    if (!isset($colMap['email'])) $colMap['email'] = $idx;
                } elseif (str_contains($h, 'hp') || str_contains($h, 'phone') || str_contains($h, 'telp') || str_contains($h, 'telepon') || str_contains($h, 'whatsapp') || $h === 'wa') {
                    if (!isset($colMap['no_hp'])) $colMap['no_hp'] = $idx;
                } elseif (str_contains($h, 'jenis') || str_contains($h, 'jalur') || str_contains($h, 'rpl') || str_contains($h, 'reguler')) {
                    if (!isset($colMap['jenis_program'])) $colMap['jenis_program'] = $idx;
                } elseif (str_contains($h, 'jurusan') || str_contains($h, 'prodi') || str_contains($h, 'program') || str_contains($h, 'studi')) {
                    if (!isset($colMap['program_studi'])) $colMap['program_studi'] = $idx;
                } elseif ($h === 'kelas') {
                    if (!isset($colMap['kelas'])) $colMap['kelas'] = $idx;
                } elseif (str_contains($h, 'role') || str_contains($h, 'peran')) {
                    if (!isset($colMap['role'])) $colMap['role'] = $idx;
                }
            }

            $score = count($colMap);
            if (isset($colMap['nim'])) $score += 10;
            if (isset($colMap['nama_lengkap'])) $score += 10;
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIdx = $r;
                $bestMap = $colMap;
            }
        }

        return [
            'header_row_index' => $bestIdx,
            'col_map'          => $bestMap,
        ];
    }
}

if (!function_exists('upsertRplUserRecords')) {
    /**
     * Memasukkan daftar mahasiswa RPL baru atau memperbarui `jenis_program = 'RPL'` jika NIM sudah terdaftar.
     *
     * @param array<int, array<string, mixed>> $records
     * @return array{total:int, inserted:int, updated:int, skipped:int, details:array<int, string>, errors:array<int, string>}
     */
    function upsertRplUserRecords(PDO $pdo, array $records): array {
        ensureJenisProgramColumn($pdo);

        $normalizePhone = static function ($raw): ?string {
            $rawStr = trim((string)($raw ?? ''));
            if ($rawStr === '' || $rawStr === '-') {
                return null;
            }
            if (function_exists('formatNoHp')) {
                $formatted = formatNoHp($rawStr);
                return $formatted !== '' ? $formatted : null;
            }
            $clean = preg_replace('/[^0-9]/', '', $rawStr);
            if ($clean === '') return null;
            if (str_starts_with($clean, '62')) return $clean;
            if (str_starts_with($clean, '0')) return '62' . substr($clean, 1);
            if (str_starts_with($clean, '8')) return '62' . $clean;
            return $clean;
        };

        $stmtFind = $pdo->prepare("SELECT id, nim, username, jenis_program FROM users WHERE nim = ? OR username = ? LIMIT 1");
        $stmtUpdate = $pdo->prepare("UPDATE users SET jenis_program = 'RPL' WHERE id = ?");
        $stmtInsert = $pdo->prepare(
            "INSERT INTO users (username, password, nama_lengkap, nim, tempat_lahir, tanggal_lahir, email, no_hp, program_studi, jenis_program, angkatan, role, is_active) "
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'RPL', ?, 'mahasiswa', ?)"
        );

        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        $stats = [
            'total'    => count($records),
            'inserted' => 0,
            'updated'  => 0,
            'skipped'  => 0,
            'details'  => [],
            'errors'   => [],
        ];

        try {
            foreach ($records as $idx => $rec) {
                $nim = trim((string)($rec['nim'] ?? ''));
                $nama = trim((string)($rec['nama_lengkap'] ?? ''));
                if ($nim === '' || $nama === '') {
                    $stats['skipped']++;
                    $stats['errors'][] = "Baris #" . ($idx + 1) . ": NIM atau Nama kosong.";
                    continue;
                }

                $stmtFind->execute([$nim, $nim]);
                $existing = $stmtFind->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    if (normalizeJenisProgram($existing['jenis_program'] ?? 'Reguler') === 'RPL') {
                        $stats['skipped']++;
                        continue;
                    }
                    $stmtUpdate->execute([(int)$existing['id']]);
                    $stats['updated']++;
                    $stats['details'][] = "UPDATE [{$nim}] {$nama} -> jenis_program='RPL'";
                    continue;
                }

                $prodi = trim((string)($rec['program_studi'] ?? ''));
                $tmptLahir = trim((string)($rec['tempat_lahir'] ?? ''));
                $tglYmd = parseFlexibleDateToYmd($rec['tanggal_lahir'] ?? null);
                $passwordRaw = '123456';
                if ($tglYmd !== null) {
                    $dt = DateTime::createFromFormat('!Y-m-d', $tglYmd);
                    if ($dt instanceof DateTime) {
                        $passwordRaw = $dt->format('dmY');
                    }
                }
                $hash = password_hash($passwordRaw, PASSWORD_BCRYPT, ['cost' => 8]);
                $noHp = $normalizePhone($rec['no_hp'] ?? '');
                $email = trim((string)($rec['email'] ?? ''));
                $angkatan = trim((string)($rec['angkatan'] ?? ''));
                if ($angkatan === '') {
                    $prefix = substr($nim, 0, 2);
                    $angkatan = (ctype_digit($prefix) && strlen($prefix) === 2) ? ('20' . $prefix) : '2025';
                }
                $isActive = ($angkatan === '2024') ? 1 : 0;

                $stmtInsert->execute([
                    $nim,
                    $hash,
                    $nama,
                    $nim,
                    $tmptLahir !== '' ? $tmptLahir : null,
                    $tglYmd,
                    $email !== '' ? $email : null,
                    $noHp,
                    $prodi !== '' ? $prodi : null,
                    $angkatan,
                    $isActive,
                ]);
                $stats['inserted']++;
                $stats['details'][] = "INSERT [{$nim}] {$nama} ({$prodi}) -> jenis_program='RPL'";
            }

            if ($startedTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $stats;
    }
}

if (!function_exists('upsertAngkatan2026Records')) {
    /**
     * Upsert idempotent data mahasiswa Angkatan 2026 ke tabel users.
     * Secara default menggunakan jenis_program = 'RPL', angkatan = '2026', is_active = 1.
     * Password default menggunakan format dmY (misal 03111981) dari tanggal_lahir, atau '123456' jika tanggal lahir kosong.
     *
     * @param PDO $pdo
     * @param array $records
     * @param string $defaultJenisProgram
     * @return array
     */
    function upsertAngkatan2026Records(PDO $pdo, array $records, string $defaultJenisProgram = 'RPL'): array {
        ensureJenisProgramColumn($pdo);

        $normalizePhone = static function ($raw): ?string {
            $rawStr = trim((string)($raw ?? ''));
            if ($rawStr === '' || $rawStr === '-') {
                return null;
            }
            if (function_exists('formatNoHp')) {
                $formatted = formatNoHp($rawStr);
                return $formatted !== '' ? $formatted : null;
            }
            $clean = preg_replace('/[^0-9]/', '', $rawStr);
            if ($clean === '') return null;
            if (str_starts_with($clean, '62')) return $clean;
            if (str_starts_with($clean, '0')) return '62' . substr($clean, 1);
            if (str_starts_with($clean, '8')) return '62' . $clean;
            return $clean;
        };

        $stmtFind = $pdo->prepare("SELECT id, nim, username, nama_lengkap, jenis_program, angkatan, is_active FROM users WHERE nim = ? OR username = ? LIMIT 1");
        $stmtUpdate = $pdo->prepare("UPDATE users SET jenis_program = ?, angkatan = '2026', is_active = 1 WHERE id = ?");
        $stmtInsert = $pdo->prepare(
            "INSERT INTO users (username, password, nama_lengkap, nim, tempat_lahir, tanggal_lahir, email, no_hp, program_studi, jenis_program, angkatan, role, is_active) "
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '2026', 'mahasiswa', 1)"
        );

        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        $stats = [
            'total'    => count($records),
            'inserted' => 0,
            'updated'  => 0,
            'skipped'  => 0,
            'details'  => [],
            'errors'   => [],
        ];

        try {
            foreach ($records as $idx => $rec) {
                $nim = trim((string)($rec['nim'] ?? ''));
                $nama = trim((string)($rec['nama_lengkap'] ?? ''));
                if ($nim === '' || $nama === '') {
                    $stats['skipped']++;
                    $stats['errors'][] = "Baris #" . ($idx + 1) . ": NIM atau Nama kosong.";
                    continue;
                }

                $targetJenis = normalizeJenisProgram($rec['jenis_program'] ?? $defaultJenisProgram);

                $stmtFind->execute([$nim, $nim]);
                $existing = $stmtFind->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $currJenis = normalizeJenisProgram($existing['jenis_program'] ?? 'Reguler');
                    $currAngkatan = trim((string)($existing['angkatan'] ?? ''));
                    $currActive = (int)($existing['is_active'] ?? 0);

                    // Idempotent: jika sudah sesuai target jenis, angkatan 2026, dan is_active 1, skip
                    if ($currJenis === $targetJenis && $currAngkatan === '2026' && $currActive === 1) {
                        $stats['skipped']++;
                        $stats['details'][] = "SKIP [{$nim}] {$nama} (sudah {$targetJenis} 2026 aktif)";
                        continue;
                    }

                    $stmtUpdate->execute([$targetJenis, (int)$existing['id']]);
                    $stats['updated']++;
                    $stats['details'][] = "UPDATE [{$nim}] {$nama} -> jenis_program='{$targetJenis}', angkatan='2026', is_active=1";
                    continue;
                }

                $prodi = trim((string)($rec['program_studi'] ?? ''));
                $tmptLahir = trim((string)($rec['tempat_lahir'] ?? ''));
                $tglYmd = parseFlexibleDateToYmd($rec['tanggal_lahir'] ?? null);
                $passwordRaw = '123456';
                if ($tglYmd !== null) {
                    $dt = DateTime::createFromFormat('!Y-m-d', $tglYmd);
                    if ($dt instanceof DateTime) {
                        $passwordRaw = $dt->format('dmY');
                    }
                }
                $hash = password_hash($passwordRaw, PASSWORD_BCRYPT, ['cost' => 8]);
                $noHp = $normalizePhone($rec['no_hp'] ?? '');
                $email = trim((string)($rec['email'] ?? ''));

                $stmtInsert->execute([
                    $nim,
                    $hash,
                    $nama,
                    $nim,
                    $tmptLahir !== '' ? $tmptLahir : null,
                    $tglYmd,
                    $email !== '' ? $email : null,
                    $noHp,
                    $prodi !== '' ? $prodi : null,
                    $targetJenis,
                ]);
                $stats['inserted']++;
                $stats['details'][] = "INSERT [{$nim}] {$nama} ({$prodi}) -> {$targetJenis} 2026 (aktif)";
            }

            if ($startedTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $stats;
    }
}

if (!function_exists('updateAngkatan2026ToRpl')) {
    /**
     * Memperbarui data mahasiswa Angkatan 2026 menjadi jenis_program = 'RPL'.
     * - Jika sudah ada di DB: UPDATE jenis_program = 'RPL', angkatan = '2026', is_active = 1.
     * - Jika belum ada di DB: INSERT langsung sebagai mahasiswa RPL 2026 aktif.
     * - Idempotent: aman dijalankan berulang kali.
     *
     * @param PDO $pdo
     * @param array|null $records
     * @return array
     */
    function updateAngkatan2026ToRpl(PDO $pdo, ?array $records = null): array {
        if ($records === null) {
            $datasetPath = __DIR__ . '/../data/mahasiswa-angkatan-2026.php';
            if (!file_exists($datasetPath)) {
                throw new RuntimeException("File dataset tidak ditemukan: {$datasetPath}");
            }
            $records = require $datasetPath;
        }

        return upsertAngkatan2026Records($pdo, $records, 'RPL');
    }
}

if (!function_exists('normalizePhoneWa')) {
    /**
     * Menormalkan nomor HP menjadi format standar internasional WhatsApp (diawali 62 tanpa +).
     */
    function normalizePhoneWa($noHp): string {
        if (function_exists('formatNoHp')) {
            $formatted = formatNoHp($noHp);
            if (!empty($formatted)) {
                return (string)$formatted;
            }
        }
        $clean = preg_replace('/[^0-9]/', '', (string)$noHp);
        if (empty($clean)) {
            return '';
        }
        if (str_starts_with($clean, '62')) {
            return $clean;
        }
        if (str_starts_with($clean, '0')) {
            return '62' . substr($clean, 1);
        }
        if (str_starts_with($clean, '8')) {
            return '62' . $clean;
        }
        return $clean;
    }
}

if (!function_exists('getDosenWaMessageTemplate')) {
    /**
     * Menghasilkan teks template pesan WhatsApp untuk menghubungi dosen dari role mahasiswa.
     *
     * Format:
     * Assalamu'alaikum wr wb
     *
     * Perkenalkan nama saya : [Nama Mahasiswa]
     * nim : [NIM Mahasiswa]
     * jurursan/ prodi : [Jurusan/Prodi Mahasiswa]
     * Keperluan : [Keperluan / Kosong untuk diisi mahasiswa]
     *
     *
     * terima kasih
     *
     * @param array|null $student
     * @param string|null $keperluan
     * @param bool $useDots
     * @return string
     */
    function getDosenWaMessageTemplate(?array $student = null, ?string $keperluan = null, bool $useDots = false): string {
        if ($student === null && function_exists('getCurrentUser')) {
            $student = getCurrentUser();
        }

        $nama = (!$useDots && !empty($student['nama_lengkap']))
            ? trim((string)$student['nama_lengkap'])
            : '........................';

        $nimRaw = !empty($student['nim']) ? $student['nim'] : (!empty($student['username']) ? $student['username'] : '');
        $nim = (!$useDots && !empty($nimRaw))
            ? trim((string)$nimRaw)
            : '.................................';

        $prodiRaw = !empty($student['program_studi']) ? $student['program_studi'] : (!empty($student['jurusan']) ? $student['jurusan'] : '');
        $prodi = (!$useDots && !empty($prodiRaw))
            ? trim((string)$prodiRaw)
            : '................................';

        // Keperluan dibiarkan titik-titik sesuai template resmi agar diisi sendiri oleh mahasiswa
        $keperluanVal = ($keperluan !== null && trim($keperluan) !== '')
            ? trim($keperluan)
            : '..........................................';

        return "Assalamu'alaikum wr wb\n\n"
            . "Perkenalkan nama saya : " . $nama . "\n"
            . "nim : " . $nim . "\n"
            . "jurursan/ prodi : " . $prodi . "\n"
            . "Keperluan : " . $keperluanVal . "\n\n\n"
            . "terima kasih";
    }
}

if (!function_exists('getDosenPhoneByName')) {
    /**
     * Mencari nomor WhatsApp dosen dari tabel users berdasarkan nama dosen / tutor / penguji.
     * Menggunakan in-memory cache dan pencocokan cerdas (exact, substring, dan title-stripped).
     *
     * @param PDO $pdo
     * @param string|null $dosenName
     * @param bool $forceRefresh
     * @return string|null Nomor telepon format 62... atau null jika tidak ditemukan
     */
    function getDosenPhoneByName(PDO $pdo, ?string $dosenName, bool $forceRefresh = false): ?string {
        static $lecturersCache = null;
        static $resolvedCache = [];

        if ($forceRefresh) {
            $lecturersCache = null;
            $resolvedCache = [];
        }

        $name = trim((string)($dosenName ?? ''));
        if ($name === '') {
            return null;
        }

        $lower = mb_strtolower($name);
        $ignoreList = [
            '-', 'menunggu pembagian', 'menunggu plotting', 'belum ditentukan',
            'dosen penguji', 'dosen pengampu', 'tutor', 'no_tutor'
        ];
        if (in_array($lower, $ignoreList, true) || str_contains($lower, 'menunggu') || str_contains($lower, 'belum ditentukan')) {
            return null;
        }

        if (isset($resolvedCache[$lower])) {
            return $resolvedCache[$lower];
        }

        if ($lecturersCache === null) {
            try {
                $stmt = $pdo->query("SELECT id, nama_lengkap, no_hp, nim, username FROM users WHERE role = 'dosen' AND no_hp IS NOT NULL AND TRIM(no_hp) != ''");
                $lecturersCache = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable $e) {
                $lecturersCache = [];
            }
        }

        if (empty($lecturersCache)) {
            $resolvedCache[$lower] = null;
            return null;
        }

        // 1. Exact match (case-insensitive)
        foreach ($lecturersCache as $lec) {
            $lecName = mb_strtolower(trim((string)($lec['nama_lengkap'] ?? '')));
            if ($lecName !== '' && $lecName === $lower) {
                $phone = normalizePhoneWa($lec['no_hp'] ?? '');
                if (!empty($phone) && strlen($phone) >= 9) {
                    $resolvedCache[$lower] = $phone;
                    return $phone;
                }
            }
        }

        // 2. Substring match
        foreach ($lecturersCache as $lec) {
            $lecName = mb_strtolower(trim((string)($lec['nama_lengkap'] ?? '')));
            if ($lecName === '') continue;
            if (mb_strpos($lecName, $lower) !== false || mb_strpos($lower, $lecName) !== false) {
                $phone = normalizePhoneWa($lec['no_hp'] ?? '');
                if (!empty($phone) && strlen($phone) >= 9) {
                    $resolvedCache[$lower] = $phone;
                    return $phone;
                }
            }
        }

        // 3. Normalized / Title-stripped match
        $stripTitles = static function(string $str): string {
            $clean = preg_replace('/[,.]/', ' ', mb_strtolower($str));
            $titles = [
                'dr', 'dra', 'drs', 'prof', 'h', 'hj', 'lc', 'm pd', 's pd',
                'm ag', 's ag', 'm si', 's si', 'm hum', 's hum', 'm kom',
                's kom', 'm thi', 's thi', 'm pd i', 's pd i', 'ph d', 'mag'
            ];
            foreach ($titles as $t) {
                $clean = preg_replace('/\b' . preg_quote($t, '/') . '\b/u', ' ', $clean);
            }
            return trim(preg_replace('/\s+/', ' ', $clean));
        };

        $targetClean = $stripTitles($name);
        if ($targetClean !== '') {
            foreach ($lecturersCache as $lec) {
                $lecClean = $stripTitles((string)($lec['nama_lengkap'] ?? ''));
                if ($lecClean !== '' && ($lecClean === $targetClean || mb_strpos($lecClean, $targetClean) !== false || mb_strpos($targetClean, $lecClean) !== false)) {
                    $phone = normalizePhoneWa($lec['no_hp'] ?? '');
                    if (!empty($phone) && strlen($phone) >= 9) {
                        $resolvedCache[$lower] = $phone;
                        return $phone;
                    }
                }
            }
        }

        $resolvedCache[$lower] = null;
        return null;
    }
}

if (!function_exists('buildDosenWaUrl')) {
    /**
     * Membangun URL tautan wa.me dengan pesan template resmi.
     */
    function buildDosenWaUrl(string $phone, ?array $student = null, ?string $keperluan = null): string {
        $cleanPhone = normalizePhoneWa($phone);
        $msg = getDosenWaMessageTemplate($student, $keperluan);
        return 'https://wa.me/' . $cleanPhone . '?text=' . rawurlencode($msg);
    }
}

if (!function_exists('renderDosenWaButton')) {
    /**
     * Merender tombol ikon WhatsApp dengan SVG dan label WA.
     */
    function renderDosenWaButton(PDO $pdo, ?string $dosenName, ?array $student = null, ?string $keperluan = null, string $extraClass = ''): string {
        $phone = getDosenPhoneByName($pdo, $dosenName);
        if (empty($phone)) {
            return '';
        }

        $url = buildDosenWaUrl($phone, $student, $keperluan);
        $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $classes = 'btn-wa-dosen' . ($extraClass !== '' ? ' ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8') : '');
        $style = 'display:inline-flex;align-items:center;gap:4px;margin-left:6px;padding:2px 8px;background:#25D366;color:#ffffff!important;border-radius:9999px;font-size:11px;font-weight:700;line-height:1.4;text-decoration:none!important;vertical-align:middle;box-shadow:0 1px 3px rgba(37,211,102,0.35);white-space:nowrap;';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="12" height="12" style="fill:currentColor;flex-shrink:0;vertical-align:-1px;" aria-hidden="true"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>';

        return '<a href="' . $escapedUrl . '" target="_blank" rel="noopener noreferrer" class="' . $classes . '" style="' . $style . '" title="Chat WhatsApp dengan Dosen" aria-label="Chat WhatsApp Dosen">'
            . $svg . '<span>WA</span></a>';
    }
}

if (!function_exists('renderDosenWithWa')) {
    /**
     * Merender nama dosen yang disanitasi disertai tombol ikon pesan WhatsApp di sebelahnya jika nomor ditemukan.
     */
    function renderDosenWithWa(PDO $pdo, ?string $dosenName, ?array $student = null, ?string $keperluan = null, string $fallback = '-'): string {
        $raw = trim((string)($dosenName ?? ''));
        if ($raw === '' || in_array(strtolower($raw), ['-', 'menunggu pembagian', 'menunggu plotting', 'belum ditentukan'], true)) {
            $displayFallback = $fallback !== '' ? $fallback : ($raw !== '' ? $raw : '-');
            return htmlspecialchars($displayFallback, ENT_QUOTES, 'UTF-8');
        }

        $escaped = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
        $btn = renderDosenWaButton($pdo, $raw, $student, $keperluan);
        return $btn !== '' ? ($escaped . ' ' . $btn) : $escaped;
    }
}

