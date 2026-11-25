<?php
declare(strict_types=1);

namespace Lppai\ProgramSeparation;
require_once __DIR__ . '/scope.php';

/** Offline planner only. Does not load auth, config, legacy helpers or PDO. Never writes migration SQL. */
final class Audit
{
    // Deliberately exclude names, photos, tokens, questions, answers, scores and passwords.
    public const FIELDS = [
        'users' => ['id', 'jenis_program'],
        'master_pretes' => ['id', 'periode', 'target_program', 'status'],
        'pretes_registrations' => ['id', 'user_id', 'periode'],
        'pretes_cbt_sessions' => ['id', 'is_active', 'package_ids_json'],
        'pretes_cbt_packages' => ['id'],
        'pretes_cbt_attempts' => ['id', 'user_id', 'registration_id', 'session_id', 'submitted_at'],
        'tutorial_registrations' => ['id', 'user_id'],
        'tutorial_rpl_registrations' => ['id', 'user_id'],
        'tutorial_rpl_members' => ['id', 'user_id', 'class_id'],
        'tutorial_rpl_cbt_sessions' => ['id', 'is_active'],
        'tutorial_rpl_cbt_attempts' => ['id', 'user_id', 'class_id', 'session_id', 'submitted', 'submitted_at'],
    ];

    public static function inspect(array $snapshot): array
    {
        if (($snapshot['schema_version'] ?? null) !== 1 || !is_array($snapshot['tables'] ?? null)) {
            throw new \InvalidArgumentException('Snapshot harus memakai schema_version 1 dan objek tables.');
        }
        foreach (array_keys($snapshot['tables']) as $table) {
            if (!isset(self::FIELDS[$table])) throw new \InvalidArgumentException('Tabel snapshot tidak didukung.');
        }
        $tables = []; $issues = []; $map = []; $counts = [];
        $issue = static function(string $code, string $table, ?int $id = null) use (&$issues): void {
            $issues[] = ['code' => $code, 'table' => $table, 'id' => $id];
        };
        foreach (self::FIELDS as $table => $fields) {
            $tables[$table] = [];
            if (!array_key_exists($table, $snapshot['tables'])) {
                $issue('missing_table_snapshot', $table); continue;
            }
            if (!is_array($snapshot['tables'][$table]) || !array_is_list($snapshot['tables'][$table])) {
                throw new \InvalidArgumentException('Isi tabel harus berupa list baris.');
            }
            foreach ($snapshot['tables'][$table] as $row) {
                if (!is_array($row) || array_diff(array_keys($row), $fields)) {
                    throw new \InvalidArgumentException('Snapshot memuat kolom di luar daftar minimum yang diizinkan.');
                }
                $id = Scope::positiveId($row['id'] ?? null);
                if (!$id || isset($tables[$table][$id])) throw new \InvalidArgumentException('ID baris tidak valid atau duplikat.');
                // Require every projected column. Unknown values must be explicit null, never silently omitted.
                if (array_diff($fields, array_keys($row))) throw new \InvalidArgumentException('Kolom snapshot minimum belum lengkap.');
                foreach ($row as $value) if (!is_scalar($value) && $value !== null) throw new \InvalidArgumentException('Nilai kolom harus skalar atau null.');
                $tables[$table][$id] = $row;
            }
            $counts[$table] = count($tables[$table]);
        }
        $userProgram = static function(mixed $id) use ($tables): ?string {
            return Scope::program($tables['users'][Scope::positiveId($id) ?? 0]['jenis_program'] ?? null);
        };
        foreach ($tables['users'] as $id => $row) {
            if (!Scope::program($row['jenis_program'])) $issue('unknown_user_program', 'users', $id);
        }
        $periodNames = [];
        foreach ($tables['master_pretes'] as $id => $row) {
            $name = is_string($row['periode']) ? trim($row['periode']) : '';
            if ($name === '') $issue('missing_period_name', 'master_pretes', $id);
            else $periodNames[$name][] = $id;
            $target = Scope::program($row['target_program']);
            $isMixed = is_string($row['target_program']) && strtolower(trim($row['target_program'])) === 'semua';
            if ($isMixed) $issue('mixed_period_requires_split', 'master_pretes', $id);
            elseif (!$target) $issue('unknown_period_program', 'master_pretes', $id);
            if (strtolower(trim((string)$row['status'])) === 'aktif') $issue('active_pretes_period', 'master_pretes', $id);
            $map['master_pretes'][$id] = ['candidate_program' => $target, 'source_id' => $id];
        }
        foreach ($tables['pretes_registrations'] as $id => $row) {
            $program = $userProgram($row['user_id']);
            $ids = is_string($row['periode']) ? ($periodNames[trim($row['periode'])] ?? []) : [];
            $periodId = count($ids) === 1 ? $ids[0] : null;
            if (!$program) $issue('registration_user_unresolved', 'pretes_registrations', $id);
            if (!$periodId) $issue('period_name_missing_or_ambiguous', 'pretes_registrations', $id);
            $target = $periodId ? ($map['master_pretes'][$periodId]['candidate_program'] ?? null) : null;
            if ($target && $program && $target !== $program) {
                $issue('user_period_program_conflict', 'pretes_registrations', $id); $program = null;
            }
            // Current account program is evidence only; cannot prove historical enrollment program.
            $map['pretes_registrations'][$id] = ['candidate_program' => $program, 'period_source_id' => $periodId,
                'evidence' => 'current_account_and_period', 'requires_history_review' => true];
        }
        $sessionPrograms = [];
        foreach ($tables['pretes_cbt_attempts'] as $id => $row) {
            if (!Scope::positiveId($row['submitted_at'])) $issue('unfinished_pretes_attempt', 'pretes_cbt_attempts', $id);
            $regId = Scope::positiveId($row['registration_id']);
            $registration = $tables['pretes_registrations'][$regId ?? 0] ?? null;
            $program = $map['pretes_registrations'][$regId ?? 0]['candidate_program'] ?? null;
            if (!$registration || Scope::positiveId($registration['user_id']) !== Scope::positiveId($row['user_id'])) {
                $issue('attempt_registration_owner_conflict', 'pretes_cbt_attempts', $id); $program = null;
            }
            $sessionId = Scope::positiveId($row['session_id']);
            if (!$sessionId || !isset($tables['pretes_cbt_sessions'][$sessionId])) {
                $issue('attempt_session_unresolved', 'pretes_cbt_attempts', $id);
            } elseif ($program) $sessionPrograms[$sessionId][$program] = true;
            $map['pretes_cbt_attempts'][$id] = ['candidate_program' => $program, 'registration_source_id' => $regId,
                'session_source_id' => $sessionId, 'preserve_original' => true];
        }
        $packagePrograms = [];
        foreach ($tables['pretes_cbt_sessions'] as $id => $row) {
            // Active sessions block cutover even when no attempt has started yet.
            if (!in_array($row['is_active'], [0, '0'], true)) $issue('active_or_unknown_pretes_session', 'pretes_cbt_sessions', $id);
            $programs = array_keys($sessionPrograms[$id] ?? []); sort($programs);
            if (count($programs) !== 1) $issue('session_ownership_requires_review', 'pretes_cbt_sessions', $id);
            $map['pretes_cbt_sessions'][$id] = ['observed_programs' => $programs, 'requires_explicit_owner' => true];
            $raw = $row['package_ids_json'];
            try { $packages = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null; }
            catch (\JsonException $e) { $packages = null; }
            if (!is_array($packages) || !array_is_list($packages) || !$packages) {
                $issue('session_packages_invalid', 'pretes_cbt_sessions', $id); continue;
            }
            foreach ($packages as $package) {
                $packageId = Scope::positiveId($package);
                if (!$packageId || !isset($tables['pretes_cbt_packages'][$packageId])) {
                    $issue('session_package_unresolved', 'pretes_cbt_sessions', $id); continue;
                }
                foreach ($programs as $program) $packagePrograms[$packageId][$program] = true;
            }
        }
        foreach ($tables['pretes_cbt_packages'] as $id => $row) {
            $programs = array_keys($packagePrograms[$id] ?? []); sort($programs);
            if (count($programs) !== 1) $issue('package_ownership_requires_review', 'pretes_cbt_packages', $id);
            $map['pretes_cbt_packages'][$id] = ['observed_programs' => $programs, 'requires_explicit_owner' => true];
        }
        foreach (['tutorial_registrations' => 'reguler', 'tutorial_rpl_registrations' => 'rpl', 'tutorial_rpl_members' => 'rpl'] as $table => $expected) {
            foreach ($tables[$table] as $id => $row) {
                $program = $userProgram($row['user_id']);
                if ($program !== $expected) $issue('tutorial_account_program_requires_review', $table, $id);
                $map[$table][$id] = ['candidate_program' => $program === $expected ? $expected : null, 'preserve_original' => true];
            }
        }
        foreach ($tables['tutorial_rpl_cbt_sessions'] as $id => $row) {
            if (!in_array($row['is_active'], [0, '0'], true)) $issue('active_or_unknown_tutorial_session', 'tutorial_rpl_cbt_sessions', $id);
        }
        foreach ($tables['tutorial_rpl_cbt_attempts'] as $id => $row) {
            if (!in_array($row['submitted'], [1, '1'], true) || !Scope::positiveId($row['submitted_at'])) {
                $issue('unfinished_tutorial_attempt', 'tutorial_rpl_cbt_attempts', $id);
            }
            if ($userProgram($row['user_id']) !== 'rpl') $issue('tutorial_attempt_user_unresolved', 'tutorial_rpl_cbt_attempts', $id);
        }
        return [
            'schema_version' => 1, 'mode' => 'offline_plan_only', 'cutover_allowed' => false,
            'reason' => 'Offline evidence never authorizes live cutover. Full history, current activity and rollback must be verified separately.',
            'counts' => $counts, 'issues' => $issues, 'candidate_mapping' => $map,
            'mandatory_reviews' => ['historical_program_snapshots', 'explicit_session_and_package_ownership',
                'dependent_scores_items_logs_certificates_and_exports', 'live_exam_quiescence', 'verified_backup_and_restore'],
        ];
    }
}
