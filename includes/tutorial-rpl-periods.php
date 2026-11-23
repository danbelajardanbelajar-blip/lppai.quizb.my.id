<?php
require_once __DIR__ . '/tutorial-rpl-registration-policy.php';
const TUTORIAL_RPL_DAYS = ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'];

/** Optional configuration tables may be absent on older installations; query errors propagate. */
function tutorialRplTableExists(PDO $pdo, string $name): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    } else {
        $stmt = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    }
    $stmt->execute([$name]);
    return $stmt->fetchColumn() !== false;
}

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
    $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_period_tutors (
        id $id, period_id INT NOT NULL, dosen_id INT NOT NULL,
        hari VARCHAR(20) NOT NULL, quota INT NOT NULL,
        UNIQUE(period_id,dosen_id,hari)
    )$engine");
}

function tutorialRplPeriodTutors(PDO $pdo, int $periodId): array
{
    $stmt = $pdo->prepare('SELECT s.*,u.nama_lengkap AS dosen_nama,u.role AS dosen_role FROM tutorial_rpl_period_tutors s LEFT JOIN users u ON u.id=s.dosen_id WHERE s.period_id=? ORDER BY s.id');
    $stmt->execute([$periodId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Called inside the period transaction/lock; never remove capacity already reserved. */
function tutorialRplSavePeriodTutors(PDO $pdo, int $periodId, $rows, bool $active): void
{
    $old = $pdo->prepare('SELECT * FROM tutorial_rpl_period_tutors WHERE period_id=?');
    $old->execute([$periodId]); $existing = $old->fetchAll(PDO::FETCH_ASSOC);
    $oldKeys = []; foreach ($existing as $row) $oldKeys[$row['dosen_id'].'|'.$row['hari']]=true;
    if ($rows === null) $rows = array_map(static fn($r) => ['dosen_id'=>$r['dosen_id'],'hari'=>$r['hari'],'quota'=>$r['quota']],$existing);
    if (!is_array($rows) || count($rows)>500) throw new InvalidArgumentException('Daftar dosen dan hari tidak valid (maksimal 500 baris).');
    $wanted = [];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('Format jadwal dosen tidak valid.');
        $dosen = filter_var($row['dosen_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $quota = filter_var($row['quota'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        $day = $row['hari'] ?? null;
        if (!$dosen || !$quota || !is_string($day) || !in_array($day,TUTORIAL_RPL_DAYS,true)) throw new InvalidArgumentException('Setiap baris wajib berisi dosen, hari dan kuota minimal 1.');
        $key = $dosen.'|'.$day;
        if (isset($wanted[$key])) throw new InvalidArgumentException('Dosen yang sama tidak boleh ditambahkan dua kali pada hari yang sama.');
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='dosen'"); $stmt->execute([$dosen]);
        if (!$stmt->fetchColumn() && ($active || !isset($oldKeys[$key]))) throw new InvalidArgumentException('Akun dosen tidak ditemukan atau sudah bukan dosen.');
        $wanted[$key] = ['dosen_id'=>$dosen,'hari'=>$day,'quota'=>$quota];
    }
    if ($active && !$wanted) throw new InvalidArgumentException('Tambahkan minimal satu dosen beserta hari dan kuotanya sebelum mengaktifkan periode.');
    $hasRegistrations = tutorialRplTableExists($pdo,'tutorial_rpl_registrations');
    $reserved = [];
    if ($hasRegistrations) {
        $columns = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            ? array_column($pdo->query('PRAGMA table_info(tutorial_rpl_registrations)')->fetchAll(PDO::FETCH_ASSOC),'name')
            : array_column($pdo->query('SHOW COLUMNS FROM tutorial_rpl_registrations')->fetchAll(PDO::FETCH_ASSOC),'Field');
        if (in_array('schedule_id',$columns,true)) {
            $stmt=$pdo->prepare('SELECT schedule_id,COUNT(*) AS used FROM tutorial_rpl_registrations WHERE period_id=? GROUP BY schedule_id'); $stmt->execute([$periodId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $reserved[(int)$row['schedule_id']]=(int)$row['used'];
        }
    }
    foreach ($existing as $row) {
        $key = $row['dosen_id'].'|'.$row['hari']; $used = $reserved[(int)$row['id']] ?? 0;
        if (!isset($wanted[$key])) {
            if ($used>0) throw new InvalidArgumentException('Dosen/hari yang sudah dipilih mahasiswa tidak dapat dihapus atau diganti.');
            $pdo->prepare('DELETE FROM tutorial_rpl_period_tutors WHERE id=? AND period_id=?')->execute([$row['id'],$periodId]);
        } else {
            if ($wanted[$key]['quota']<$used) throw new InvalidArgumentException('Kuota dosen tidak boleh kurang dari jumlah tempat yang sudah terisi.');
            $pdo->prepare('UPDATE tutorial_rpl_period_tutors SET quota=? WHERE id=?')->execute([$wanted[$key]['quota'],$row['id']]); unset($wanted[$key]);
        }
    }
    foreach ($wanted as $row) $pdo->prepare('INSERT INTO tutorial_rpl_period_tutors(period_id,dosen_id,hari,quota) VALUES(?,?,?,?)')->execute([$periodId,$row['dosen_id'],$row['hari'],$row['quota']]);
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
            $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $check = $pdo->prepare('SELECT id FROM tutorial_rpl_registration_periods WHERE active_slot = 1 AND id <> ?' . $suffix);
            $check->execute([$id]);
            if ($check->fetchColumn()) throw new InvalidArgumentException('Masih ada periode aktif. Nonaktifkan periode tersebut terlebih dahulu.');
        }
        $values = [$nama, $gelombang, $opens, $closes, $quota, $active, (int)$actor['id'], $now];
        if ($id > 0) {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $current = $pdo->prepare('SELECT id FROM tutorial_rpl_registration_periods WHERE id=?' . $lock);
            $current->execute([$id]);
            if (!$current->fetchColumn()) throw new InvalidArgumentException('Periode tidak ditemukan.');
            if ($quota > 0 && tutorialRplTableExists($pdo, 'tutorial_rpl_registrations')) {
                $used = $pdo->prepare('SELECT COUNT(*) FROM tutorial_rpl_registrations WHERE period_id=?');
                $used->execute([$id]);
                if ($quota < (int)$used->fetchColumn()) throw new InvalidArgumentException('Kuota tidak boleh lebih kecil dari jumlah mahasiswa yang sudah terdaftar.');
            }
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
        tutorialRplSavePeriodTutors($pdo,$id,$data['schedules'] ?? null,$active === 1);
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
