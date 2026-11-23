<?php
require_once __DIR__ . '/tutorial-rpl-registration-policy.php';

function ensureTutorialRplPeriodTable(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    // Nullable unique slot enforces at most one active period, including concurrent saves.
    $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_registration_periods (
        id $id, nama VARCHAR(100) NOT NULL, gelombang VARCHAR(50) NOT NULL,
        opens_at DATETIME NOT NULL, closes_at DATETIME NOT NULL,
        quota INT NOT NULL DEFAULT 0, active_slot INT NULL UNIQUE,
        created_by INT NOT NULL, updated_by INT NOT NULL,
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        version INT NOT NULL DEFAULT 1
    )$engine");
}

function tutorialRplPeriodPolicy(?array $period): array
{
    return array_replace(tutorialRplRegistrationPolicy(), $period ?? ['id' => null, 'active_slot' => null]);
}

function tutorialRplActivePeriod(PDO $pdo): ?array
{
    $row = $pdo->query('SELECT * FROM tutorial_rpl_registration_periods WHERE active_slot = 1')->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function tutorialRplPeriodDate(string $input): string
{
    $input = str_replace('T', ' ', trim($input));
    if (strlen($input) === 16) $input .= ':00';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $input, new DateTimeZone('Asia/Jakarta'));
    if (!$date || $date->format('Y-m-d H:i:s') !== $input || (int)$date->format('Y') < 2000 || (int)$date->format('Y') > 9999) {
        throw new InvalidArgumentException('Tanggal dan waktu tidak valid. Gunakan waktu WIB.');
    }
    return $input;
}

/** Admin-only write. Caller must also validate CSRF before invoking this function. */
function tutorialRplSavePeriod(PDO $pdo, array $data, array $actor): int
{
    if (($actor['role'] ?? '') !== 'admin' || (int)($actor['id'] ?? 0) < 1) {
        throw new RuntimeException('Hanya admin yang dapat mengelola periode.');
    }
    foreach (['nama', 'gelombang', 'opens_at', 'closes_at', 'quota', 'id', 'version', 'active_slot'] as $key) {
        if (isset($data[$key]) && !is_scalar($data[$key])) throw new InvalidArgumentException('Format data periode tidak valid.');
    }
    $nama = trim((string)($data['nama'] ?? ''));
    $gelombang = trim((string)($data['gelombang'] ?? ''));
    if ($nama === '' || mb_strlen($nama) > 100 || $gelombang === '' || mb_strlen($gelombang) > 50) {
        throw new InvalidArgumentException('Nama periode wajib diisi maksimal 100 karakter dan gelombang maksimal 50 karakter.');
    }
    $opens = tutorialRplPeriodDate((string)($data['opens_at'] ?? ''));
    $closes = tutorialRplPeriodDate((string)($data['closes_at'] ?? ''));
    if ($closes <= $opens) throw new InvalidArgumentException('Waktu tutup harus setelah waktu buka.');
    $quota = filter_var($data['quota'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
    if ($quota === false || $quota === null) throw new InvalidArgumentException('Kuota harus bilangan bulat 0 sampai 1.000.000.');
    $id = filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($id === false) throw new InvalidArgumentException('ID periode tidak valid.');
    $active = in_array($data['active_slot'] ?? null, [1, '1'], true) ? 1 : null;
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        if ($active) {
            $check = $pdo->prepare('SELECT id FROM tutorial_rpl_registration_periods WHERE active_slot = 1 AND id <> ?');
            $check->execute([$id]);
            if ($check->fetchColumn()) throw new InvalidArgumentException('Masih ada periode aktif. Nonaktifkan periode tersebut terlebih dahulu.');
        }
        $values = [$nama, $gelombang, $opens, $closes, $quota, $active, (int)$actor['id'], $now];
        if ($id > 0) {
            $version = filter_var($data['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$version) throw new InvalidArgumentException('Versi periode tidak valid. Muat ulang halaman.');
            $stmt = $pdo->prepare('UPDATE tutorial_rpl_registration_periods SET nama=?, gelombang=?, opens_at=?, closes_at=?, quota=?, active_slot=?, updated_by=?, updated_at=?, version=version+1 WHERE id=? AND version=?');
            $stmt->execute(array_merge($values, [$id, $version]));
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Periode telah berubah atau tidak ditemukan. Muat ulang halaman sebelum menyimpan.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO tutorial_rpl_registration_periods (nama, gelombang, opens_at, closes_at, quota, active_slot, updated_by, updated_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute(array_merge($values, [(int)$actor['id'], $now]));
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tutorialRplPeriodSchedule(array $policy): string
{
    return (new DateTimeImmutable($policy['opens_at'], new DateTimeZone('Asia/Jakarta')))->format('d/m/Y H:i:s')
        . ' sampai ' . (new DateTimeImmutable($policy['closes_at'], new DateTimeZone('Asia/Jakarta')))->format('d/m/Y H:i:s') . ' WIB';
}
