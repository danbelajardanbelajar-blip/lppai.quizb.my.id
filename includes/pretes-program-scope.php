<?php
declare(strict_types=1);

/**
 * LPPAI Corner - Helper Scope Program Pretes (RPL / Reguler)
 * Fondasi pembatas program di sisi server untuk halaman admin, ekspor, dan CBT Pretes.
 */

require_once __DIR__ . '/program-separation/scope.php';

use Lppai\ProgramSeparation\Scope;

if (!function_exists('pretesAdminValidateAlias')) {
    function pretesAdminValidateAlias(string $alias): string
    {
        if ($alias === '') {
            return '';
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('Alias tabel tidak valid.');
        }
        return $alias . '.';
    }
}

if (!function_exists('pretesAdminResolveProgram')) {
    /**
     * Menentukan program aktif ('rpl' | 'reguler' | null) secara aman di server.
     * Menolak konflik parameter GET/POST terhadap halaman program tetap.
     */
    function pretesAdminResolveProgram(?string $fixedProgram = null, ?array $query = null, ?array $post = null): ?string
    {
        $fixed = null;
        if ($fixedProgram !== null && trim($fixedProgram) !== '') {
            $fixed = Scope::program($fixedProgram);
            if ($fixed === null) {
                throw new InvalidArgumentException('Program halaman tidak valid.');
            }
        }

        $q = $query ?? $_GET;
        $p = $post ?? $_POST;

        foreach ([$q['program'] ?? null, $p['program'] ?? null] as $raw) {
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                continue;
            }
            $parsed = Scope::program($raw);
            if ($parsed === null) {
                throw new InvalidArgumentException('Parameter program tidak valid.');
            }
            if ($fixed !== null && $parsed !== $fixed) {
                throw new DomainException('Program permintaan tidak sesuai halaman.');
            }
            if ($fixed === null) {
                $fixed = $parsed;
            }
        }

        return $fixed;
    }
}

if (!function_exists('pretesAdminProgramLabel')) {
    function pretesAdminProgramLabel(?string $program): string
    {
        return match ($program) {
            'rpl' => 'RPL',
            'reguler' => 'Reguler',
            default => '',
        };
    }
}

if (!function_exists('pretesAdminTargetProgramValue')) {
    function pretesAdminTargetProgramValue(?string $program): ?string
    {
        return match ($program) {
            'rpl' => 'RPL',
            'reguler' => 'Reguler',
            default => null,
        };
    }
}

if (!function_exists('pretesPeriodTargetWhere')) {
    /**
     * Filter SQL untuk master_pretes.target_program.
     * Secara default ketat per program; set $includeLegacyAll=true hanya untuk pembacaan arsip.
     */
    function pretesPeriodTargetWhere(?string $program, string $alias = '', bool $includeLegacyAll = false): array
    {
        if ($program === null) {
            return ['sql' => '1=1', 'params' => []];
        }
        $normalized = Scope::program($program);
        if ($normalized === null) {
            throw new InvalidArgumentException('Program filter periode tidak valid.');
        }
        $prefix = pretesAdminValidateAlias($alias);
        if ($includeLegacyAll) {
            return [
                'sql' => "(LOWER(TRIM(COALESCE({$prefix}target_program, ''))) = ? OR LOWER(TRIM(COALESCE({$prefix}target_program, ''))) = 'semua')",
                'params' => [$normalized],
            ];
        }
        return [
            'sql' => "LOWER(TRIM(COALESCE({$prefix}target_program, ''))) = ?",
            'params' => [$normalized],
        ];
    }
}

if (!function_exists('pretesUserProgramWhere')) {
    /**
     * Filter SQL untuk users.jenis_program.
     */
    function pretesUserProgramWhere(?string $program, string $userAlias = 'u'): array
    {
        if ($program === null) {
            return ['sql' => '1=1', 'params' => []];
        }
        $normalized = Scope::program($program);
        if ($normalized === null) {
            throw new InvalidArgumentException('Program filter mahasiswa tidak valid.');
        }
        $prefix = pretesAdminValidateAlias($userAlias);
        return [
            'sql' => "LOWER(TRIM(COALESCE({$prefix}jenis_program, ''))) = ?",
            'params' => [$normalized],
        ];
    }
}

if (!function_exists('pretesCbtProgramWhere')) {
    /**
     * Filter SQL untuk pretes_cbt_packages.program_scope / pretes_cbt_sessions.program_scope.
     */
    function pretesCbtProgramWhere(?string $program, string $alias = ''): array
    {
        if ($program === null) {
            return ['sql' => '1=1', 'params' => []];
        }
        $normalized = Scope::program($program);
        if ($normalized === null) {
            throw new InvalidArgumentException('Program filter CBT tidak valid.');
        }
        $prefix = pretesAdminValidateAlias($alias);
        return [
            'sql' => "LOWER(TRIM(COALESCE({$prefix}program_scope, ''))) = ?",
            'params' => [$normalized],
        ];
    }
}

if (!function_exists('pretesAdminRoute')) {
    /**
     * Mengembalikan URL route admin Pretes sesuai program ('rpl' | 'reguler' | null).
     */
    function pretesAdminRoute(string $key, ?string $program = null, array $query = []): string
    {
        $base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
        $isRpl = ($program === 'rpl');

        $routes = [
            'tutor' => $isRpl ? 'admin/pretes-rpl-pengaturan-tutor.php' : 'admin/pretes-pengaturan-tutor.php',
            'dosen' => $isRpl ? 'admin/pretes-rpl-dosen.php' : 'admin/pretes-dosen.php',
            'jadwal' => $isRpl ? 'admin/pretes-rpl-jadwal.php' : 'admin/pretes-jadwal.php',
            'jadwal_cetak' => $isRpl ? 'admin/pretes-rpl-jadwal-cetak.php' : 'admin/pretes-jadwal-cetak.php',
            'peserta' => $isRpl ? 'admin/pretes-rpl-peserta.php' : 'admin/pretes-peserta.php',
            'peserta_export' => $isRpl ? 'admin/pretes-rpl-peserta-export.php' : 'admin/pretes-peserta-export.php',
            'peserta_pdf' => $isRpl ? 'admin/pretes-rpl-peserta-pdf.php' : 'admin/pretes-peserta-pdf.php',
            'nilai' => $isRpl ? 'admin/pretes-rpl-nilai.php' : 'admin/pretes-nilai.php',
            'absensi_export' => $isRpl ? 'admin/pretes-rpl-absensi-export.php' : 'admin/pretes-absensi-export.php',
            'tugas' => $isRpl ? 'admin/pretes-rpl-tugas.php' : 'admin/pretes-tugas.php',
            'cbt_bank' => 'pretes-ujian.php',
            'cbt_review' => 'pretes-ujian.php',
            'rekap' => $isRpl ? 'admin/pretes-rpl-rekap-nilai.php' : 'admin/pretes-rekap-nilai.php',
        ];

        if (!isset($routes[$key])) {
            throw new InvalidArgumentException('Route pretes tidak dikenal: ' . $key);
        }

        if ($key === 'cbt_bank') {
            $query = array_merge(['cbt_bank' => 1], $isRpl ? ['program' => 'rpl'] : [], $query);
        } elseif ($key === 'cbt_review') {
            $query = array_merge(['cbt_review' => 1], $isRpl ? ['program' => 'rpl'] : [], $query);
        }

        $qs = $query ? '?' . http_build_query($query) : '';
        return $base . '/' . $routes[$key] . $qs;
    }
}

if (!function_exists('ensurePretesCbtProgramScopeColumns')) {
    /**
     * Memastikan kolom nullable `program_scope` tersedia pada tabel paket & sesi CBT
     * tanpa mengubah nilai baris lama (selaras dengan AdditiveRehearsal::ADDITIONS).
     */
    function ensurePretesCbtProgramScopeColumns(PDO $pdo): void
    {
        static $ready = [];
        $oid = spl_object_id($pdo);
        if (isset($ready[$oid])) {
            return;
        }

        try {
            $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable $e) {
            return;
        }

        foreach (['pretes_cbt_packages', 'pretes_cbt_sessions'] as $table) {
            try {
                if ($driver === 'sqlite') {
                    $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($cols)) {
                        continue;
                    }
                    $colNames = array_map(static fn($c) => strtolower((string)($c['name'] ?? '')), $cols);
                    if (!in_array('program_scope', $colNames, true)) {
                        $pdo->exec("ALTER TABLE {$table} ADD COLUMN program_scope TEXT DEFAULT NULL");
                    }
                } else {
                    if (!$pdo->query("SHOW TABLES LIKE '{$table}'")->fetch()) {
                        continue;
                    }
                    if (!$pdo->query("SHOW COLUMNS FROM {$table} LIKE 'program_scope'")->fetch()) {
                        $pdo->exec("ALTER TABLE {$table} ADD COLUMN program_scope VARCHAR(16) NULL");
                    }
                }
            } catch (Throwable $e) {
                error_log("ensurePretesCbtProgramScopeColumns ({$table}) warning: " . $e->getMessage());
            }
        }

        $ready[$oid] = true;
    }
}
