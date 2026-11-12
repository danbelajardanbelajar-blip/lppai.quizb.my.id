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
