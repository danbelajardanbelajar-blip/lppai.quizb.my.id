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
