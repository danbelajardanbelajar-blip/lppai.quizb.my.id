<?php
/** Read-only membership rule for student navigation; no session cache or enrollment mutation. */
function studentHasActiveTutorialRegistration(PDO $pdo, ?array $user): bool {
    if (!$user || ($user['role'] ?? '') !== 'mahasiswa' || (int)($user['id'] ?? 0) < 1) return false;
    try {
        $periods = $pdo->query("SELECT * FROM master_gelombang WHERE status = 'aktif' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        if (!$periods) return false;
        $stmt = $pdo->prepare('SELECT tr.*, tc.gelombang AS class_gelombang, tc.semester AS class_semester
            FROM tutorial_registrations tr
            LEFT JOIN tutorial_classes tc ON tc.id = tr.tutorial_class_id
            WHERE tr.user_id = ? ORDER BY tr.id DESC');
        $stmt->execute([(int)$user['id']]);
        $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $normalize = static fn($value) => strtolower(str_replace('/', '-', trim((string)$value)));
        foreach ($periods as $period) {
            $year = $normalize($period['tahun_ajaran'] ?? '');
            $semester = $normalize($period['semester'] ?? '');
            $wave = $normalize($period['gelombang'] ?? '');
            if ($year === '' || $wave === '') continue;
            foreach ($registrations as $reg) {
                $regWave = $normalize($reg['class_gelombang'] ?: ($reg['gelombang'] ?? ''));
                if ($regWave === '') $regWave = str_replace(' ', '', $normalize($reg['tipe_nilai'] ?? ''));
                if ($regWave !== $wave) continue;
                $regYear = $normalize($reg['tahun_ajaran'] ?? '');
                $classSemester = $normalize($reg['class_semester'] ?? '');
                $fullSemester = $year . '-' . $semester;
                if ($classSemester !== '' && !in_array($classSemester, [$semester, $fullSemester], true)) continue;
                if ($regYear !== '' && !in_array($regYear, [$year, $fullSemester], true)) continue;
                // Legacy unplotted records have no year: require a full class period
                // or a registration created since this active period was opened.
                if ($regYear === '' && $classSemester !== $fullSemester) {
                    $created = strtotime((string)($reg['created_at'] ?? ''));
                    $opened = strtotime((string)($period['created_at'] ?? ''));
                    if (!$created || !$opened || $created < $opened) continue;
                }
                // The latest matching record is authoritative, including withdrawal/completion.
                if (in_array($reg['status'] ?? '', ['terdaftar', 'aktif'], true)) return true;
                break;
            }
        }
    } catch (PDOException $error) {
        error_log('Tutorial membership lookup unavailable: ' . $error->getMessage());
    }
    return false;
}

if (!function_exists('studentHasTutorialRegistration')) {
    /**
     * Cek apakah mahasiswa terdaftar pada program tutorial (baik periode/status aktif maupun non-aktif).
     * Mengembalikan true jika mahasiswa memiliki catatan pendaftaran tutorial yang tidak berstatus 'mengundurkan_diri'.
     *
     * @param PDO $pdo
     * @param array|null $user
     * @return bool
     */
    function studentHasTutorialRegistration(PDO $pdo, ?array $user): bool {
        if (!$user || ($user['role'] ?? '') !== 'mahasiswa' || (int)($user['id'] ?? 0) < 1) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT status FROM tutorial_registrations 
                WHERE user_id = ? 
                ORDER BY id DESC 
                LIMIT 1
            ");
            $stmt->execute([(int)$user['id']]);
            $status = $stmt->fetchColumn();
            if ($status !== false) {
                return strtolower(trim((string)$status)) !== 'mengundurkan_diri';
            }
        } catch (Throwable $error) {
            error_log('studentHasTutorialRegistration error: ' . $error->getMessage());
        }
        return false;
    }
}

