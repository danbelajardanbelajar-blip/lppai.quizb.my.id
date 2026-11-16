<?php
/** Eligibility shared with the visible admin roster. */
function adminTutorialUnpassedStudentSql(): string
{
    return "u.id NOT IN (
    SELECT tr_sub.user_id FROM tutorial_registrations tr_sub
    WHERE tr_sub.nilai_thaharah IS NOT NULL AND tr_sub.nilai_shalat IS NOT NULL AND tr_sub.nilai_surat_pendek IS NOT NULL AND tr_sub.nilai_amaliyah IS NOT NULL AND tr_sub.nilai_jenazah IS NOT NULL AND tr_sub.nilai_ujian_tulis IS NOT NULL
    AND tr_sub.nilai_thaharah >= (CASE WHEN LOWER(tr_sub.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr_sub.nilai_shalat >= (CASE WHEN LOWER(tr_sub.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr_sub.nilai_surat_pendek >= (CASE WHEN LOWER(tr_sub.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr_sub.nilai_amaliyah >= (CASE WHEN LOWER(tr_sub.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr_sub.nilai_jenazah >= (CASE WHEN LOWER(tr_sub.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr_sub.nilai_ujian_tulis >= (CASE WHEN LOWER(tr_sub.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
)";
}

/** Same period membership for the admin roster, duplicate checks, and quota. */
function adminTutorialPeriodRegistrationIdsSql(): string
{
    return "SELECT MAX(candidate.id) FROM tutorial_registrations candidate
        LEFT JOIN tutorial_classes period_class ON candidate.tutorial_class_id = period_class.id
        WHERE COALESCE(NULLIF(period_class.gelombang, ''), candidate.gelombang) = ?
          AND (REPLACE(TRIM(candidate.tahun_ajaran), '/', '-') = ?
            OR ((candidate.tahun_ajaran IS NULL OR TRIM(candidate.tahun_ajaran) = '')
                AND (REPLACE(TRIM(period_class.semester), '/', '-') = ?
                    OR (period_class.id IS NULL AND candidate.created_at >= ?))))
          AND (period_class.id IS NULL OR TRIM(period_class.semester) = ?
            OR REPLACE(TRIM(period_class.semester), '/', '-') = ?)
        GROUP BY candidate.user_id";
}

function adminTutorialPeriodRegistrationParams(array $period): array
{
    $year = str_replace('/', '-', trim($period['tahun_ajaran']));
    $semester = trim($period['semester']);
    return [$period['gelombang'], $year, $year . '-' . $semester,
        $period['created_at'], $semester, $year . '-' . $semester];
}

/** Serialize registrations against the selected period before checking quota. */
function adminTutorialRegisterStudent(PDO $pdo, int $userId, int $periodId, string $day): int
{
    if ($userId < 1 || $periodId < 1 || !in_array($day, ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'], true)) {
        throw new RuntimeException('Pilih mahasiswa, periode tutorial, dan hari yang valid.');
    }
    $pdo->beginTransaction();
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT * FROM master_gelombang WHERE id = ?' . $lock);
        $stmt->execute([$periodId]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$period || $period['status'] !== 'aktif' || trim((string)$period['tahun_ajaran']) === '') {
            throw new RuntimeException('Periode tutorial tujuan tidak aktif atau belum lengkap. Pilih periode aktif terlebih dahulu.');
        }
        $eligibleSql = adminTutorialUnpassedStudentSql();
        $stmt = $pdo->prepare("SELECT u.id FROM users u WHERE u.id = ? AND u.role = 'mahasiswa' AND ($eligibleSql)");
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('Mahasiswa tidak ditemukan atau sudah memenuhi syarat kelulusan tutorial.');

        $idsSql = adminTutorialPeriodRegistrationIdsSql();
        $params = adminTutorialPeriodRegistrationParams($period);
        $stmt = $pdo->prepare("SELECT user_id, hari_pilihan FROM tutorial_registrations WHERE id IN ($idsSql)");
        $stmt->execute($params);
        $occupied = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $registration) {
            if ((int)$registration['user_id'] === $userId) throw new RuntimeException('Mahasiswa ini sudah terdaftar pada periode tutorial tujuan.');
            if (strcasecmp(trim((string)$registration['hari_pilihan']), $day) === 0) $occupied++;
        }
        $quota = (int)($period['kuota_' . strtolower($day)] ?? 0);
        if ($quota <= 0 || $occupied >= $quota) throw new RuntimeException("Maaf, kuota untuk hari $day pada periode ini sudah penuh.");

        $stmt = $pdo->prepare("INSERT INTO tutorial_registrations (user_id, status, hari_pilihan, gelombang, tahun_ajaran)
            VALUES (?, 'terdaftar', ?, ?, ?)");
        $stmt->execute([$userId, $day, $period['gelombang'], $period['tahun_ajaran']]);
        $id = (int)$pdo->lastInsertId();
        $check = $pdo->prepare("SELECT id FROM tutorial_registrations WHERE id = ? AND user_id = ? AND id IN ($idsSql)");
        $check->execute(array_merge([$id, $userId], $params));
        if (!$check->fetchColumn()) throw new RuntimeException('Pendaftaran belum tersimpan pada periode tujuan. Silakan coba lagi.');
        $pdo->commit();
        return $id;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
