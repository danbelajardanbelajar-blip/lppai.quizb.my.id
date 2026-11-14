<?php
/** Active-period teaching assignments; each row represents one scheduled class. */
function tutorialActiveSchedulePeriod(PDO $pdo): ?array
{
    $period = $pdo->query("SELECT * FROM master_gelombang WHERE status = 'aktif' ORDER BY created_at DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    return $period ?: null;
}

function tutorialScheduledLecturerClasses(PDO $pdo, array $period, ?int $classId = null): array
{
    $year = str_replace('/', '-', trim($period['tahun_ajaran']));
    $semester = trim($period['semester']);
    $sql = "SELECT tc.* FROM tutorial_classes tc
        WHERE tc.gelombang = ? AND TRIM(COALESCE(tc.dosen_pengampu, '')) <> ''
          AND (REPLACE(TRIM(tc.semester), '/', '-') = ?
            OR (TRIM(tc.semester) = ? AND EXISTS (
                SELECT 1 FROM tutorial_registrations tr WHERE tr.tutorial_class_id = tc.id
                  AND (REPLACE(TRIM(tr.tahun_ajaran), '/', '-') IN (?, ?)
                    OR ((tr.tahun_ajaran IS NULL OR TRIM(tr.tahun_ajaran) = '') AND tr.created_at >= ?))
            )))";
    $params = [$period['gelombang'], $year . '-' . $semester, $semester, $year, $year . '-' . $semester, $period['created_at']];
    if ($classId !== null) {
        $sql .= ' AND tc.id = ?';
        $params[] = $classId;
    }
    $sql .= " ORDER BY CASE tc.hari WHEN 'Senin' THEN 1 WHEN 'Selasa' THEN 2 WHEN 'Rabu' THEN 3 WHEN 'Kamis' THEN 4 WHEN 'Jumat' THEN 5 ELSE 6 END,
        tc.dosen_pengampu, tc.nama_kelas, tc.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function tutorialLecturerScheduleRooms(PDO $pdo, array $classes): array
{
    $rooms = $pdo->query('SELECT ruang FROM rooms')->fetchAll(PDO::FETCH_COLUMN);
    $rooms = array_merge($rooms, array_column($classes, 'ruangan'));
    $rooms = array_values(array_unique(array_filter(array_map(static fn($room) => trim((string)$room), $rooms), static fn($room) => $room !== '')));
    sort($rooms, SORT_NATURAL | SORT_FLAG_CASE);
    return $rooms;
}

function updateTutorialLecturerSchedule(PDO $pdo, array $input): void
{
    foreach (['schedule_class_id', 'schedule_period_id', 'nama_kelas', 'hari', 'ruangan'] as $key) {
        if (!isset($input[$key]) || !is_string($input[$key])) {
            throw new InvalidArgumentException('Data jadwal tidak valid. Muat ulang halaman dan coba lagi.');
        }
    }
    $classId = filter_var($input['schedule_class_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $periodId = filter_var($input['schedule_period_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $name = trim($input['nama_kelas']);
    $day = trim($input['hari']);
    $room = trim($input['ruangan']);
    if (!$classId || !$periodId) {
        throw new InvalidArgumentException('Jadwal tidak valid. Muat ulang halaman dan coba lagi.');
    }
    if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
        throw new InvalidArgumentException('Nama kelas wajib diisi, maksimal 100 karakter.');
    }
    if (!in_array($day, ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'], true)) {
        throw new InvalidArgumentException('Pilih hari tutorial Senin sampai Jumat.');
    }
    $pdo->beginTransaction();
    try {
        $period = tutorialActiveSchedulePeriod($pdo);
        if (!$period || (int)$period['id'] !== $periodId) {
            throw new DomainException('Periode tutorial aktif telah berubah. Muat ulang halaman sebelum mengedit jadwal.');
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $lock = $pdo->prepare("SELECT id FROM master_gelombang WHERE id = ? AND status = 'aktif' FOR UPDATE");
            $lock->execute([$periodId]);
            if (!$lock->fetchColumn()) throw new DomainException('Periode tutorial sudah tidak aktif. Muat ulang halaman.');
        }
        $classes = tutorialScheduledLecturerClasses($pdo, $period);
        if (!in_array($classId, array_map('intval', array_column($classes, 'id')), true)) {
            throw new DomainException('Jadwal dosen tidak ditemukan pada periode tutorial aktif.');
        }
        if ($room !== '' && !in_array($room, tutorialLecturerScheduleRooms($pdo, $classes), true)) {
            throw new InvalidArgumentException('Pilih ruangan yang tersedia.');
        }
        $stmt = $pdo->prepare('UPDATE tutorial_classes SET nama_kelas = ?, hari = ?, ruangan = ? WHERE id = ?');
        $stmt->execute([$name, $day, $room === '' ? null : $room, $classId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
