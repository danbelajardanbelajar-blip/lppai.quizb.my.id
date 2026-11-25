<?php
declare(strict_types=1);

namespace Lppai\ProgramSeparation;

/** Staging-only boundary. Not loaded by any live route; no database/session side effects. */
final class Scope
{
    private const MODULES = [
        'pretes-rpl' => ['pretes', 'rpl'],
        'pretes-reguler' => ['pretes', 'reguler'],
        'tutorial-rpl' => ['tutorial', 'rpl'],
        'tutorial-reguler' => ['tutorial', 'reguler'],
    ];

    public function __construct(public readonly string $module)
    {
        if (!isset(self::MODULES[$module])) throw new \InvalidArgumentException('Modul harus ditentukan secara eksplisit.');
    }

    public static function program(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = strtolower(trim($value));
        return in_array($value, ['rpl', 'reguler'], true) ? $value : null;
    }

    public function programKey(): string { return self::MODULES[$this->module][1]; }
    public function activity(): string { return self::MODULES[$this->module][0]; }

    /** Owner must come from a validated server record, never directly from URL/POST. */
    public function assertOwner(array $owner): void
    {
        if (($owner['activity'] ?? null) !== $this->activity()
            || self::program($owner['program'] ?? null) !== $this->programKey()) {
            throw new \DomainException('Data berada di luar lingkup modul.');
        }
    }

    public function assertStudent(array $actor, array $owner): void
    {
        $this->assertOwner($owner);
        if (($actor['role'] ?? null) !== 'mahasiswa'
            || !self::positiveId($actor['id'] ?? null)
            || self::program($actor['jenis_program'] ?? null) !== $this->programKey()
            || self::positiveId($owner['user_id'] ?? null) !== self::positiveId($actor['id'] ?? null)) {
            throw new \DomainException('Data bukan milik mahasiswa pada program ini.');
        }
    }

    public function assertAdmin(array $actor, array $owner): void
    {
        $this->assertOwner($owner);
        if (($actor['role'] ?? null) !== 'admin' || !self::positiveId($actor['id'] ?? null)) {
            throw new \DomainException('Tindakan ini memerlukan admin pada lingkup yang sesuai.');
        }
    }

    /** Teacher access is bound to this assignment, not merely to their account role. */
    public function assertLecturer(array $actor, array $assignment): void
    {
        $this->assertOwner($assignment);
        if (($actor['role'] ?? null) !== 'dosen' || !self::positiveId($actor['id'] ?? null)
            || self::positiveId($assignment['dosen_id'] ?? null) !== self::positiveId($actor['id'] ?? null)) {
            throw new \DomainException('Dosen tidak ditugaskan pada lingkup ini.');
        }
    }

    public static function positiveId(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}
