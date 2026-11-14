<?php
/** Registration closes for the whole period once any participant has been plotted. */
function tutorialRegistrationHasPlotting(PDO $pdo, array $period): bool
{
    $year = str_replace('/', '-', trim($period['tahun_ajaran']));
    $semester = trim($period['semester']);
    $stmt = $pdo->prepare("SELECT 1 FROM tutorial_registrations tr
        JOIN tutorial_classes tc ON tc.id = tr.tutorial_class_id
        WHERE tc.gelombang = ?
          AND (REPLACE(TRIM(tc.semester), '/', '-') = ?
            OR (TRIM(tc.semester) = ? AND (
                REPLACE(TRIM(tr.tahun_ajaran), '/', '-') IN (?, ?)
                OR ((tr.tahun_ajaran IS NULL OR TRIM(tr.tahun_ajaran) = '') AND tr.created_at >= ?)
            )))
        LIMIT 1");
    $stmt->execute([$period['gelombang'], $year . '-' . $semester, $semester,
        $year, $year . '-' . $semester, $period['created_at']]);
    return (bool)$stmt->fetchColumn();
}
