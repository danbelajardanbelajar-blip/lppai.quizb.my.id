<?php
/** Select registrations in the configured period, including legacy period formats. */
function tutorialPlottingCandidates(PDO $pdo, array $period, bool $overwrite): array
{
    $normalize = static fn($value) => strtolower(str_replace('/', '-', trim((string)$value)));
    $year = $normalize($period['tahun_ajaran']);
    $semester = $normalize($period['semester']);
    $wave = $normalize($period['gelombang']);
    $fullSemester = $year . '-' . $semester;
    $stmt = $pdo->prepare("SELECT tr.*, tc.semester AS class_semester,
            tc.gelombang AS class_gelombang
        FROM tutorial_registrations tr
        LEFT JOIN tutorial_classes tc ON tc.id = tr.tutorial_class_id
        WHERE tr.status IN ('terdaftar', 'aktif')
          AND (tr.gelombang = ? OR tc.gelombang = ?)
        ORDER BY tr.id ASC");
    $stmt->execute([$period['gelombang'], $period['gelombang']]);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $reg) {
        if (!$overwrite && $reg['tutorial_class_id'] !== null) continue;
        if ($normalize($reg['class_gelombang'] ?: $reg['gelombang']) !== $wave) continue;
        $regYear = $normalize($reg['tahun_ajaran']);
        $classSemester = $normalize($reg['class_semester']);
        if ($classSemester !== '' && !in_array($classSemester, [$semester, $fullSemester], true)) continue;
        if ($regYear !== '' && !in_array($regYear, [$year, $fullSemester], true)) continue;
        if ($regYear === '' && $classSemester !== $fullSemester) {
            $created = strtotime((string)($reg['created_at'] ?? ''));
            $opened = strtotime((string)($period['created_at'] ?? ''));
            if (!$created || !$opened || $created < $opened) continue;
        }
        $result[] = $reg;
    }
    return $result;
}
