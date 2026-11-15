<?php
/** Read-only recent registrations, independently scoped to every active period. */
function adminDashboardRegistrationGroups(PDO $pdo, array $tutorialPeriods, array $pretesPeriods): array
{
    $groups = [];
    foreach (['tutorial' => $tutorialPeriods, 'pretes' => $pretesPeriods] as $type => $periods) {
        foreach ($periods as $period) {
            $label = $type === 'pretes' ? $period['periode']
                : $period['tahun_ajaran'] . ' / ' . $period['semester'] . ' / ' . $period['gelombang'];
            $group = ['type' => $type, 'period' => $period, 'label' => $label, 'rows' => [], 'error' => false];
            try {
                if ($type === 'pretes') {
                    $stmt = $pdo->prepare('SELECT pr.*, u.nama_lengkap, u.nim, u.program_studi, u.jenis_program, u.no_hp
                        FROM pretes_registrations pr JOIN users u ON pr.user_id = u.id
                        WHERE pr.periode = ? ORDER BY pr.tanggal_daftar DESC, pr.id DESC LIMIT 5');
                    $stmt->execute([$period['periode']]);
                } else {
                    $normalize = static fn($value) => strtolower(str_replace('/', '-', trim((string)$value)));
                    $year = $normalize($period['tahun_ajaran']);
                    $semester = $normalize($period['semester']);
                    $fullSemester = $year . '-' . $semester;
                    $stmt = $pdo->prepare("SELECT tr.*, u.nama_lengkap, u.nim, u.program_studi, u.jenis_program, u.no_hp,
                            tc.nama_kelas, tc.hari AS kelas_hari, tc.ruangan, tc.dosen_pengampu
                        FROM tutorial_registrations tr JOIN users u ON tr.user_id = u.id
                        LEFT JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
                        WHERE LOWER(TRIM(COALESCE(NULLIF(tc.gelombang, ''), tr.gelombang, ''))) = ?
                          AND LOWER(REPLACE(TRIM(COALESCE(tc.semester, '')), '/', '-')) IN ('', ?, ?, ?)
                          AND (
                            LOWER(REPLACE(TRIM(COALESCE(tr.tahun_ajaran, '')), '/', '-')) IN (?, ?)
                            OR (COALESCE(TRIM(tr.tahun_ajaran), '') = '' AND (
                                LOWER(REPLACE(TRIM(COALESCE(tc.semester, '')), '/', '-')) = ?
                                OR (? <> '' AND tr.created_at >= ?)
                            ))
                          )
                        ORDER BY tr.created_at DESC, tr.id DESC LIMIT 5");
                    $opened = trim((string)($period['created_at'] ?? ''));
                    $stmt->execute([$normalize($period['gelombang']), $semester, $year, $fullSemester,
                        $year, $fullSemester, $fullSemester, $opened, $opened]);
                }
                $group['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $error) {
                error_log('Admin dashboard registrations (' . $type . ', period ' . (int)$period['id'] . '): ' . $error->getMessage());
                $group['error'] = true;
            }
            $groups[] = $group;
        }
    }
    return $groups;
}
