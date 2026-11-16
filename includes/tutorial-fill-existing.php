<?php
/** Fill existing classes only; caller holds the period lock and transaction. */
function tutorialFillExistingClasses(PDO $pdo, array $period, string $day, array $students, array $tutors, string $mode, int $tutorQuota): array
{
    $year = str_replace('/', '-', trim($period['tahun_ajaran']));
    $semester = trim($period['semester']);
    $stmt = $pdo->prepare("SELECT tc.*,
            (SELECT COUNT(DISTINCT tr.user_id) FROM tutorial_registrations tr
             WHERE tr.tutorial_class_id = tc.id AND tr.status <> 'mengundurkan_diri') AS occupied
        FROM tutorial_classes tc WHERE tc.hari = ? AND tc.gelombang = ?
          AND (REPLACE(TRIM(tc.semester), '/', '-') = ? OR (TRIM(tc.semester) = ? AND EXISTS (
            SELECT 1 FROM tutorial_registrations pr WHERE pr.tutorial_class_id = tc.id
              AND (REPLACE(TRIM(pr.tahun_ajaran), '/', '-') IN (?, ?)
                OR (COALESCE(TRIM(pr.tahun_ajaran), '') = '' AND pr.created_at >= ?))
          ))) ORDER BY tc.id");
    $stmt->execute([$day, $period['gelombang'], $year . '-' . $semester, $semester,
        $year, $year . '-' . $semester, $period['created_at']]);
    $normalize = static fn($name) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string)$name)), 'UTF-8');
    $allowed = array_fill_keys(array_map($normalize, $tutors), true);
    $classes = [];
    $loads = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $class) {
        $teacher = $normalize($class['dosen_pengampu']);
        $class['_teacher'] = $teacher;
        $loads[$teacher] = ($loads[$teacher] ?? 0) + (int)$class['occupied'];
        if (isset($allowed[$teacher])) $classes[] = $class;
    }
    $update = $pdo->prepare('UPDATE tutorial_registrations SET tutorial_class_id = ?, tahun_ajaran = ?, tipe_nilai = ? WHERE id = ? AND tutorial_class_id IS NULL');
    $type = ['gel1' => 'gel 1', 'gel2' => 'gel 2'][$period['gelombang']] ?? 'mandiri';
    $assigned = 0;
    foreach ($students as $registrationId) {
        $available = [];
        foreach ($classes as $index => $class) {
            if ((int)$class['occupied'] < (int)$class['kuota'] && ($loads[$class['_teacher']] ?? 0) < $tutorQuota) $available[] = $index;
        }
        if (!$available) break;
        if ($mode === 'distribute_evenly') {
            usort($available, static function ($a, $b) use ($classes, $loads) {
                return [$loads[$classes[$a]['_teacher']], (int)$classes[$a]['occupied'], (int)$classes[$a]['id']]
                    <=> [$loads[$classes[$b]['_teacher']], (int)$classes[$b]['occupied'], (int)$classes[$b]['id']];
            });
        }
        $index = $available[0];
        $update->execute([$classes[$index]['id'], $year, $type, $registrationId]);
        if ($update->rowCount() !== 1) throw new RuntimeException('Penempatan peserta berubah. Muat ulang halaman sebelum generate kembali.');
        $classes[$index]['occupied']++;
        $loads[$classes[$index]['_teacher']]++;
        $assigned++;
    }
    return ['assigned' => $assigned, 'remaining' => array_slice($students, $assigned)];
}
