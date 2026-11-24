<?php
/**
 * LPPAI Corner - Helper & Skema Basis Data Tutorial RPL Online
 *
 * Mengelola struktur data khusus Tutorial RPL (kelas/kelompok online, plotting anggota,
 * verifikasi tugas Google Drive, pengerjaan ujian tulis CBT, serta perizinan tutor).
 */

if (file_exists(__DIR__ . '/user-helper.php')) {
    require_once __DIR__ . '/user-helper.php';
}

if (!defined('TUTORIAL_RPL_DEFAULT_PERIODE')) {
    define('TUTORIAL_RPL_DEFAULT_PERIODE', 'Tutorial RPL 2026/2027');
}
if (!defined('TUTORIAL_RPL_DEFAULT_GELOMBANG')) {
    define('TUTORIAL_RPL_DEFAULT_GELOMBANG', 'Gelombang 1');
}

/**
 * Memastikan tabel tutorial_rpl_classes dan tutorial_rpl_members tersedia.
 */
if (!function_exists('ensureTutorialRplTables')) {
    function ensureTutorialRplTables(PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idType = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        $engine = ($driver === 'mysql') ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_classes (
            id $idType,
            nama_kelompok VARCHAR(100) NOT NULL,
            periode VARCHAR(100) NOT NULL DEFAULT 'Tutorial RPL 2026/2027',
            gelombang VARCHAR(50) NOT NULL DEFAULT 'Gelombang 1',
            dosen_id INT DEFAULT NULL,
            dosen_nim VARCHAR(50) DEFAULT NULL,
            dosen_nama VARCHAR(150) DEFAULT NULL,
            dosen_wa VARCHAR(30) DEFAULT NULL,
            drive_url TEXT DEFAULT NULL,
            kuota INT NOT NULL DEFAULT 50,
            catatan TEXT DEFAULT NULL,
            is_active INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )$engine");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_members (
            id $idType,
            class_id INT NOT NULL,
            user_id INT NOT NULL,
            drive_submitted INT NOT NULL DEFAULT 0,
            drive_verified INT NOT NULL DEFAULT 0,
            drive_notes TEXT DEFAULT NULL,
            exam_submitted INT NOT NULL DEFAULT 0,
            nilai_ujian_tulis DECIMAL(5,2) DEFAULT NULL,
            nilai_praktik DECIMAL(5,2) DEFAULT NULL,
            nilai_akhir DECIMAL(5,2) DEFAULT NULL,
            status_lulus VARCHAR(20) NOT NULL DEFAULT 'proses',
            catatan TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(class_id, user_id)
        )$engine");

        // Pastikan kolom-kolom penting tersedia jika tabel dibuat versi terdahulu
        $neededClassCols = [
            ['dosen_nim', 'VARCHAR(50) DEFAULT NULL'],
            ['dosen_wa', 'VARCHAR(30) DEFAULT NULL'],
            ['drive_url', 'TEXT DEFAULT NULL'],
            ['kuota', 'INT NOT NULL DEFAULT 50'],
            ['catatan', 'TEXT DEFAULT NULL']
        ];
        foreach ($neededClassCols as [$col, $def]) {
            try {
                $pdo->exec("ALTER TABLE tutorial_rpl_classes ADD COLUMN $col $def");
            } catch (Throwable $e) {}
        }

        $neededMemberCols = [
            ['drive_submitted', 'INT NOT NULL DEFAULT 0'],
            ['drive_verified', 'INT NOT NULL DEFAULT 0'],
            ['drive_notes', 'TEXT DEFAULT NULL'],
            ['exam_submitted', 'INT NOT NULL DEFAULT 0'],
            ['nilai_ujian_tulis', 'DECIMAL(5,2) DEFAULT NULL'],
            ['nilai_praktik', 'DECIMAL(5,2) DEFAULT NULL'],
            ['nilai_akhir', 'DECIMAL(5,2) DEFAULT NULL'],
            ['status_lulus', "VARCHAR(20) NOT NULL DEFAULT 'proses'"],
            ['catatan', 'TEXT DEFAULT NULL']
        ];
        foreach ($neededMemberCols as [$col, $def]) {
            try {
                $pdo->exec("ALTER TABLE tutorial_rpl_members ADD COLUMN $col $def");
            } catch (Throwable $e) {}
        }

        if (function_exists('ensureJenisProgramColumn')) {
            try {
                ensureJenisProgramColumn($pdo);
            } catch (Throwable $e) {}
        }
    }
}

/**
 * Cek apakah dosen login merupakan Tutor yang ditugaskan di kelas Tutorial RPL Online.
 */
if (!function_exists('tutorialRplIsTutor')) {
    function tutorialRplIsTutor(PDO $pdo, ?array $user): bool {
        if (!$user) return false;
        $role = (string)($user['role'] ?? '');
        $nim = trim((string)($user['nim'] ?? ''));
        $nama = trim((string)($user['nama_lengkap'] ?? ''));
        $userId = (int)($user['id'] ?? 0);

        // Tester akun 12345 diizinkan sebagai preview
        if ($nim === '12345' || (string)($user['username'] ?? '') === '12345' || $userId === 12345) {
            return true;
        }
        if ($role !== 'dosen') {
            return false;
        }

        try {
            ensureTutorialRplTables($pdo);
            $stmt = $pdo->prepare("
                SELECT 1 FROM tutorial_rpl_classes
                WHERE is_active = 1
                  AND (
                      dosen_id = ?
                      OR (dosen_nim IS NOT NULL AND dosen_nim = ?)
                      OR (dosen_nama IS NOT NULL AND dosen_nama = ?)
                  )
                LIMIT 1
            ");
            $stmt->execute([$userId, $nim, $nama]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('tutorialRplIsTutor: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Ambil daftar kelompok kelas Tutorial RPL Online beserta jumlah kuota terisi.
 */
if (!function_exists('tutorialRplGetClasses')) {
    function tutorialRplGetClasses(PDO $pdo, ?string $periode = null): array {
        ensureTutorialRplTables($pdo);
        $sql = "
            SELECT c.*,
                   (SELECT COUNT(*) FROM tutorial_rpl_members m WHERE m.class_id = c.id) AS terisi,
                   (SELECT COUNT(*) FROM tutorial_rpl_members m WHERE m.class_id = c.id AND m.drive_verified = 1) AS tugas_selesai,
                   (SELECT COUNT(*) FROM tutorial_rpl_members m WHERE m.class_id = c.id AND m.exam_submitted = 1) AS cbt_selesai
            FROM tutorial_rpl_classes c
        ";
        $params = [];
        if (!empty($periode)) {
            $sql .= " WHERE c.periode = ? ";
            $params[] = $periode;
        }
        $sql .= " ORDER BY c.is_active DESC, c.id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Ambil detail kelas Tutorial RPL berdasarkan ID.
 */
if (!function_exists('tutorialRplGetClassById')) {
    function tutorialRplGetClassById(PDO $pdo, int $classId): ?array {
        ensureTutorialRplTables($pdo);
        $stmt = $pdo->prepare("
            SELECT c.*,
                   (SELECT COUNT(*) FROM tutorial_rpl_members m WHERE m.class_id = c.id) AS terisi
            FROM tutorial_rpl_classes c
            WHERE c.id = ?
            LIMIT 1
        ");
        $stmt->execute([$classId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

/**
 * Ambil daftar anggota/peserta dalam suatu kelompok kelas Tutorial RPL.
 */
if (!function_exists('tutorialRplGetMembers')) {
    function tutorialRplGetMembers(PDO $pdo, int $classId): array {
        ensureTutorialRplTables($pdo);
        $stmt = $pdo->prepare("
            SELECT m.*,
                   u.nama_lengkap, u.nim, u.no_hp, u.no_hp AS whatsapp, u.program_studi, u.fakultas, u.jenis_program
            FROM tutorial_rpl_members m
            JOIN users u ON m.user_id = u.id
            WHERE m.class_id = ?
            ORDER BY u.nama_lengkap ASC
        ");
        $stmt->execute([$classId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Ambil daftar dosen untuk pilihan tutor RPL.
 */
if (!function_exists('tutorialRplGetTutors')) {
    /**
 * Ambil data keanggotaan kelas Tutorial RPL aktif untuk seorang mahasiswa.
 */
if (!function_exists('tutorialRplGetStudentMembership')) {
    function tutorialRplGetStudentMembership(PDO $pdo, int $userId): ?array {
        ensureTutorialRplTables($pdo);
        $stmt = $pdo->prepare("
            SELECT m.*,
                   c.nama_kelompok, c.periode, c.gelombang,
                   c.dosen_id, c.dosen_nim, c.dosen_nama, c.dosen_wa,
                   c.drive_url, c.catatan AS class_catatan
            FROM tutorial_rpl_members m
            JOIN tutorial_rpl_classes c ON m.class_id = c.id
            WHERE m.user_id = ? AND c.is_active = 1
            ORDER BY m.id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

/**
 * Konfirmasi pengunggahan tugas video ke Google Drive oleh mahasiswa.
 */
if (!function_exists('tutorialRplConfirmDriveSubmission')) {
    function tutorialRplConfirmDriveSubmission(PDO $pdo, int $memberId, int $userId, int $submitted = 1): bool {
        ensureTutorialRplTables($pdo);
        $stmt = $pdo->prepare("
            UPDATE tutorial_rpl_members
            SET drive_submitted = ?
            WHERE id = ? AND user_id = ?
        ");
        return $stmt->execute([$submitted, $memberId, $userId]);
    }
}

function tutorialRplGetTutors(PDO $pdo): array {
        try {
            $stmt = $pdo->query("SELECT id, nim, nama_lengkap, no_hp, no_hp AS whatsapp FROM users WHERE role = 'dosen' ORDER BY nama_lengkap ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

/**
 * Plotting massal/kolektif mahasiswa ke suatu kelompok kelas Tutorial RPL.
 */
if (!function_exists('tutorialRplBatchPlot')) {
    function tutorialRplBatchPlot(PDO $pdo, array $userIds, int $classId): array {
        ensureTutorialRplTables($pdo);
        $cls = tutorialRplGetClassById($pdo, $classId);
        if (!$cls || empty($cls['is_active'])) {
            throw new InvalidArgumentException('Kelompok kelas tidak valid atau tidak aktif.');
        }

        $kuota = (int)($cls['kuota'] ?? 50);
        $terisi = (int)($cls['terisi'] ?? 0);
        $sisaKuota = max(0, $kuota - $terisi);

        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($id) => $id > 0)));
        if (empty($userIds)) {
            return ['success' => 0, 'skipped_quota' => 0, 'total' => 0];
        }

        $success = 0;
        $skipped = 0;

        $pdo->beginTransaction();
        try {
            $stmtCheck = $pdo->prepare("SELECT class_id FROM tutorial_rpl_members WHERE user_id = ?");
            $stmtDel = $pdo->prepare("DELETE FROM tutorial_rpl_members WHERE user_id = ?");
            $stmtIns = $pdo->prepare("INSERT INTO tutorial_rpl_members (class_id, user_id) VALUES (?, ?)");

            foreach ($userIds as $uid) {
                $stmtCheck->execute([$uid]);
                $existingClassId = $stmtCheck->fetchColumn();

                if ($existingClassId !== false && (int)$existingClassId === $classId) {
                    $success++;
                    continue;
                }

                if ($sisaKuota <= 0) {
                    $skipped++;
                    continue;
                }

                $stmtDel->execute([$uid]);
                $stmtIns->execute([$classId, $uid]);
                $sisaKuota--;
                $success++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return ['success' => $success, 'skipped_quota' => $skipped, 'total' => count($userIds)];
    }
}

/**
 * Lepas plotting massal berdasarkan ID anggota (member_id).
 */
if (!function_exists('tutorialRplBatchUnplot')) {
    function tutorialRplBatchUnplot(PDO $pdo, array $memberIds): int {
        ensureTutorialRplTables($pdo);
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds), fn($id) => $id > 0)));
        if (empty($memberIds)) return 0;

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $pdo->prepare("DELETE FROM tutorial_rpl_members WHERE id IN ($placeholders)");
        $stmt->execute($memberIds);
        return $stmt->rowCount();
    }
}

/**
 * Pembagian mahasiswa RPL yang belum memiliki kelompok secara otomatis dan merata ke kelompok aktif.
 */
if (!function_exists('tutorialRplAutoDistribute')) {
    function tutorialRplAutoDistribute(PDO $pdo, ?string $periode = null): array {
        ensureTutorialRplTables($pdo);
        $classes = tutorialRplGetClasses($pdo, $periode);
        $activeClasses = array_values(array_filter($classes, fn($c) => !empty($c['is_active']) && ($c['terisi'] < $c['kuota'])));

        if (empty($activeClasses)) {
            return ['plotted' => 0, 'classes_used' => 0, 'remaining_unassigned' => 0, 'message' => 'Tidak ada kelompok aktif yang memiliki sisa kuota.'];
        }

        $unassigned = $pdo->query("
            SELECT u.id
            FROM users u
            LEFT JOIN tutorial_rpl_members m ON u.id = m.user_id
            WHERE u.role = 'mahasiswa'
              AND (
                  UPPER(u.jenis_program) = 'RPL'
                  OR u.id IN (SELECT user_id FROM pretes_registrations WHERE periode LIKE '%RPL%')
              )
              AND m.id IS NULL
            ORDER BY u.nama_lengkap ASC
        ")->fetchAll(PDO::FETCH_COLUMN);

        if (empty($unassigned)) {
            return ['plotted' => 0, 'classes_used' => 0, 'remaining_unassigned' => 0, 'message' => 'Semua mahasiswa RPL sudah memiliki kelompok kelas.'];
        }

        usort($activeClasses, fn($a, $b) => $a['terisi'] <=> $b['terisi']);

        $plotted = 0;
        $classesUsed = [];

        $pdo->beginTransaction();
        try {
            $stmtIns = $pdo->prepare("INSERT INTO tutorial_rpl_members (class_id, user_id) VALUES (?, ?)");
            $classIndex = 0;
            $totalClasses = count($activeClasses);

            foreach ($unassigned as $uid) {
                $found = false;
                for ($attempt = 0; $attempt < $totalClasses; $attempt++) {
                    $c = &$activeClasses[$classIndex];
                    if ($c['terisi'] < $c['kuota']) {
                        $stmtIns->execute([$c['id'], (int)$uid]);
                        $c['terisi']++;
                        $classesUsed[$c['id']] = true;
                        $plotted++;
                        $found = true;
                        $classIndex = ($classIndex + 1) % $totalClasses;
                        break;
                    }
                    $classIndex = ($classIndex + 1) % $totalClasses;
                }
                if (!$found) break;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $remaining = count($unassigned) - $plotted;
        return [
            'plotted' => $plotted,
            'classes_used' => count($classesUsed),
            'remaining_unassigned' => $remaining,
            'message' => "Berhasil membagikan $plotted mahasiswa ke " . count($classesUsed) . " kelompok kelas."
        ];
    }
}

/**
 * Generate dan plotting otomatis kelompok kelas tutorial RPL.
 * Mendukung mode:
 * - 'fill_first': Utamakan memenuhi kuota per kelompok (minimal min_per_class)
 * - 'distribute_evenly': Utamakan meratakan jumlah mahasiswa ke seluruh kelompok/dosen
 * Mendukung opsi:
 * - fill_existing: Hanya mengisi kelompok yang sudah ada tanpa membuat kelompok baru
 * - existing_tutor_quota: Batas mahasiswa per kelompok/dosen
 * - overwrite_plotting: Bersihkan anggota kelompok periode ini lalu plot ulang
 * - match_hari_pilihan: Cocokkan dengan pilihan hari pendaftaran mahasiswa
 * - auto_create_classes: Otomatis buat kelompok kelas dari konfigurasi dosen aktif
 */
if (!function_exists('tutorialRplGenerateSchedule')) {
    function tutorialRplGenerateSchedule(PDO $pdo, array $options = []): array {
        ensureTutorialRplTables($pdo);
        if (function_exists('ensureTutorialRplPeriodTable')) {
            ensureTutorialRplPeriodTable($pdo);
        }
        if (function_exists('ensureTutorialRplRegistrationTable')) {
            ensureTutorialRplRegistrationTable($pdo);
        }

        $generateMode = $options['generate_mode'] ?? 'distribute_evenly';
        $overwritePlotting = !empty($options['overwrite_plotting']);
        $fillExisting = !empty($options['fill_existing']);
        $existingTutorQuota = max(1, (int)($options['existing_tutor_quota'] ?? 30));
        $minPerClass = max(1, (int)($options['min_per_class'] ?? 30));
        $matchHariPilihan = !isset($options['match_hari_pilihan']) || !empty($options['match_hari_pilihan']);
        $autoCreateClasses = !isset($options['auto_create_classes']) || !empty($options['auto_create_classes']);

        // Tentukan periode & gelombang
        $activePeriod = null;
        if (function_exists('tutorialRplActivePeriod')) {
            $activePeriod = tutorialRplActivePeriod($pdo);
        }
        $periode = $options['periode'] ?? ($activePeriod['nama'] ?? TUTORIAL_RPL_DEFAULT_PERIODE);
        $gelombang = $options['gelombang'] ?? ($activePeriod['gelombang'] ?? TUTORIAL_RPL_DEFAULT_GELOMBANG);
        $periodId = $options['period_id'] ?? ($activePeriod['id'] ?? null);

        $pdo->beginTransaction();
        try {
            // 1. Opsi Overwrite Plotting: bersihkan plotting anggota periode ini
            if ($overwritePlotting) {
                $stmtClasses = $pdo->prepare("SELECT id FROM tutorial_rpl_classes WHERE periode = ?");
                $stmtClasses->execute([$periode]);
                $periodClassIds = $stmtClasses->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($periodClassIds)) {
                    $inPlaceholders = implode(',', array_fill(0, count($periodClassIds), '?'));
                    $stmtDel = $pdo->prepare("DELETE FROM tutorial_rpl_members WHERE class_id IN ($inPlaceholders)");
                    $stmtDel->execute($periodClassIds);
                }
            }

            // 2. Opsi Auto Create Classes dari Jadwal Dosen Periode Aktif
            if ($autoCreateClasses && $periodId && function_exists('tutorialRplPeriodTutors')) {
                $tutors = tutorialRplPeriodTutors($pdo, (int)$periodId);
                foreach ($tutors as $t) {
                    $dosenId = (int)($t['dosen_id'] ?? 0);
                    $hari = trim((string)($t['hari'] ?? ''));
                    $quota = max(1, (int)($t['quota'] ?? 30));
                    $dosenNama = trim((string)($t['dosen_nama'] ?? ''));

                    if ($dosenId > 0) {
                        // Cek apakah kelas untuk dosen & hari ini sudah ada pada periode ini
                        $stmtCheck = $pdo->prepare("
                            SELECT id FROM tutorial_rpl_classes
                            WHERE periode = ? AND dosen_id = ? AND (catatan LIKE ? OR nama_kelompok LIKE ?)
                            LIMIT 1
                        ");
                        $stmtCheck->execute([$periode, $dosenId, '%' . $hari . '%', '%' . $hari . '%']);
                        $existingClassId = $stmtCheck->fetchColumn();

                        if (!$existingClassId) {
                            $stmtUser = $pdo->prepare("SELECT nim, no_hp, no_hp AS whatsapp FROM users WHERE id = ?");
                            $stmtUser->execute([$dosenId]);
                            $uInfo = $stmtUser->fetch(PDO::FETCH_ASSOC);
                            $dosenNim = $uInfo['nim'] ?? null;
                            $dosenWa = $uInfo['no_hp'] ?? ($uInfo['whatsapp'] ?? null);

                            $namaKelompok = 'Kelompok ' . ($hari ? $hari . ' - ' : '') . ($dosenNama ?: "Dosen $dosenId");

                            $stmtInsClass = $pdo->prepare("
                                INSERT INTO tutorial_rpl_classes
                                (nama_kelompok, periode, gelombang, dosen_id, dosen_nim, dosen_nama, dosen_wa, kuota, catatan, is_active)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                            ");
                            $stmtInsClass->execute([
                                $namaKelompok,
                                $periode,
                                $gelombang,
                                $dosenId,
                                $dosenNim,
                                $dosenNama,
                                $dosenWa,
                                $quota,
                                'Hari: ' . $hari
                            ]);
                        }
                    }
                }
            }

            // 3. Ambil seluruh kelompok kelas aktif pada periode ini
            $classes = tutorialRplGetClasses($pdo, $periode);
            if (empty($classes)) {
                $classes = tutorialRplGetClasses($pdo, null);
            }
            $activeClasses = array_values(array_filter($classes, fn($c) => !empty($c['is_active'])));

            if (empty($activeClasses)) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'plotted' => 0,
                    'classes_used' => 0,
                    'class_details' => [],
                    'unassigned' => [],
                    'message' => 'Tidak ditemukan kelompok kelas aktif pada periode ini. Buat kelompok kelas terlebih dahulu atau aktifkan opsi Buat Kelompok Otomatis.'
                ];
            }

            // Sesuaikan kuota jika mode fill_existing aktif
            foreach ($activeClasses as &$c) {
                if ($fillExisting) {
                    $c['kuota'] = min((int)$c['kuota'], $existingTutorQuota);
                }
                $c['terisi'] = (int)($c['terisi'] ?? 0);
                $c['sisa'] = max(0, (int)$c['kuota'] - $c['terisi']);
            }
            unset($c);

            // 4. Cari kandidat mahasiswa RPL yang belum memiliki kelompok
            $hasRegTable = function_exists('tutorialRplTableExists') ? tutorialRplTableExists($pdo, 'tutorial_rpl_registrations') : false;
            $candidates = [];

            if ($hasRegTable && $periodId) {
                $stmtCand = $pdo->prepare("
                    SELECT u.id as user_id, u.nama_lengkap, u.nim, u.program_studi, r.hari_pilihan
                    FROM tutorial_rpl_registrations r
                    JOIN users u ON u.id = r.user_id
                    LEFT JOIN tutorial_rpl_members m ON u.id = m.user_id
                    WHERE r.period_id = ? AND m.id IS NULL
                    ORDER BY u.nama_lengkap ASC
                ");
                $stmtCand->execute([$periodId]);
                $candidates = $stmtCand->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($candidates)) {
                $sqlCand = "
                    SELECT u.id as user_id, u.nama_lengkap, u.nim, u.program_studi,
                           " . ($hasRegTable ? "(SELECT r.hari_pilihan FROM tutorial_rpl_registrations r WHERE r.user_id = u.id ORDER BY r.id DESC LIMIT 1) AS hari_pilihan" : "NULL AS hari_pilihan") . "
                    FROM users u
                    LEFT JOIN tutorial_rpl_members m ON u.id = m.user_id
                    WHERE u.role = 'mahasiswa'
                      AND (
                          UPPER(u.jenis_program) = 'RPL'
                          OR u.id IN (SELECT user_id FROM pretes_registrations WHERE periode LIKE '%RPL%')
                          " . ($hasRegTable ? "OR u.id IN (SELECT user_id FROM tutorial_rpl_registrations)" : "") . "
                      )
                      AND m.id IS NULL
                    ORDER BY u.nama_lengkap ASC
                ";
                $candidates = $pdo->query($sqlCand)->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($candidates)) {
                $pdo->commit();
                return [
                    'success' => true,
                    'plotted' => 0,
                    'classes_used' => 0,
                    'unassigned' => [],
                    'class_details' => [],
                    'message' => 'Semua mahasiswa program RPL pada periode ini sudah memiliki kelompok kelas.'
                ];
            }

            // 5. Pembagian Mahasiswa
            $plotted = 0;
            $classesUsed = [];
            $unassigned = [];
            $stmtInsertMember = $pdo->prepare("INSERT INTO tutorial_rpl_members (class_id, user_id, status_lulus) VALUES (?, ?, 'proses')");

            $matchedCandidates = [];
            $generalCandidates = [];

            if ($matchHariPilihan) {
                foreach ($candidates as $cand) {
                    $hp = trim((string)($cand['hari_pilihan'] ?? ''));
                    if ($hp !== '') {
                        $matchedCandidates[] = $cand;
                    } else {
                        $generalCandidates[] = $cand;
                    }
                }
            } else {
                $generalCandidates = $candidates;
            }

            $assignCandidate = function(&$targetClass, $cand) use ($stmtInsertMember, &$plotted, &$classesUsed) {
                $stmtInsertMember->execute([$targetClass['id'], (int)$cand['user_id']]);
                $targetClass['terisi']++;
                $targetClass['sisa']--;
                if (!isset($classesUsed[$targetClass['id']])) {
                    $classesUsed[$targetClass['id']] = [
                        'id' => $targetClass['id'],
                        'nama_kelompok' => $targetClass['nama_kelompok'],
                        'dosen_nama' => $targetClass['dosen_nama'] ?? '-',
                        'added' => 0,
                        'total' => $targetClass['terisi'],
                        'kuota' => $targetClass['kuota']
                    ];
                }
                $classesUsed[$targetClass['id']]['added']++;
                $classesUsed[$targetClass['id']]['total'] = $targetClass['terisi'];
                $plotted++;
            };

            // Tahap A: Plot kandidat yang punya hari pilihan
            if (!empty($matchedCandidates)) {
                foreach ($matchedCandidates as $cand) {
                    $hp = strtolower(trim((string)($cand['hari_pilihan'] ?? '')));
                    $matchedClassIndices = [];
                    foreach ($activeClasses as $idx => $ac) {
                        $cHari = strtolower((string)($ac['catatan'] ?? '') . ' ' . (string)($ac['nama_kelompok'] ?? ''));
                        if (str_contains($cHari, $hp) && $ac['sisa'] > 0) {
                            $matchedClassIndices[] = $idx;
                        }
                    }

                    if (!empty($matchedClassIndices)) {
                        if ($generateMode === 'fill_first') {
                            $targetIdx = $matchedClassIndices[0];
                        } else {
                            usort($matchedClassIndices, fn($a, $b) => $activeClasses[$a]['terisi'] <=> $activeClasses[$b]['terisi']);
                            $targetIdx = $matchedClassIndices[0];
                        }
                        $assignCandidate($activeClasses[$targetIdx], $cand);
                    } else {
                        $generalCandidates[] = $cand;
                    }
                }
            }

            // Tahap B: Plot sisa kandidat ke kelas dengan sisa kuota
            if (!empty($generalCandidates)) {
                if ($generateMode === 'fill_first') {
                    $classIdx = 0;
                    foreach ($generalCandidates as $cand) {
                        while ($classIdx < count($activeClasses) && $activeClasses[$classIdx]['sisa'] <= 0) {
                            $classIdx++;
                        }
                        if ($classIdx < count($activeClasses)) {
                            $assignCandidate($activeClasses[$classIdx], $cand);
                        } else {
                            $unassigned[] = $cand;
                        }
                    }
                } else {
                    foreach ($generalCandidates as $cand) {
                        $availableIndices = [];
                        foreach ($activeClasses as $idx => $ac) {
                            if ($ac['sisa'] > 0) {
                                $availableIndices[] = $idx;
                            }
                        }
                        if (empty($availableIndices)) {
                            $unassigned[] = $cand;
                        } else {
                            usort($availableIndices, fn($a, $b) => $activeClasses[$a]['terisi'] <=> $activeClasses[$b]['terisi']);
                            $targetIdx = $availableIndices[0];
                            $assignCandidate($activeClasses[$targetIdx], $cand);
                        }
                    }
                }
            }

            $pdo->commit();
            return [
                'success' => true,
                'mode' => $generateMode,
                'total_candidates' => count($candidates),
                'plotted' => $plotted,
                'classes_used' => count($classesUsed),
                'class_details' => array_values($classesUsed),
                'unassigned' => $unassigned,
                'message' => "Berhasil memplot $plotted mahasiswa RPL ke " . count($classesUsed) . " kelompok kelas." . (count($unassigned) > 0 ? " (Sebanyak " . count($unassigned) . " mahasiswa belum kebagian kuota)." : "")
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

/**
 * Mengambil data mahasiswa program RPL lengkap dengan status plotting dan hasil CBT Pretes.
 */
if (!function_exists('tutorialRplGetStudentsWithPretes')) {
    function tutorialRplGetStudentsWithPretes(PDO $pdo, array $filters = []): array {
        ensureTutorialRplTables($pdo);

        $sql = "
            SELECT u.id, u.nama_lengkap, u.nim, u.program_studi, u.fakultas, u.no_hp, u.no_hp AS whatsapp, u.jenis_program,
                   m.id AS member_id, m.class_id, m.drive_submitted, m.drive_verified, m.exam_submitted,
                   m.nilai_ujian_tulis, m.nilai_praktik, m.nilai_akhir, m.status_lulus,
                   c.nama_kelompok, c.dosen_nama, c.dosen_wa, c.drive_url
            FROM users u
            LEFT JOIN tutorial_rpl_members m ON u.id = m.user_id
            LEFT JOIN tutorial_rpl_classes c ON m.class_id = c.id
            WHERE u.role = 'mahasiswa'
              AND (
                  UPPER(u.jenis_program) = 'RPL'
                  OR u.id IN (SELECT user_id FROM pretes_registrations WHERE periode LIKE '%RPL%')
              )
        ";

        $params = [];

        $statusPlot = $filters['status_plot'] ?? 'all';
        if ($statusPlot === 'plotted') {
            $sql .= " AND m.class_id IS NOT NULL ";
        } elseif ($statusPlot === 'unplotted') {
            $sql .= " AND m.class_id IS NULL ";
        }

        if (!empty($filters['class_id'])) {
            $sql .= " AND m.class_id = ? ";
            $params[] = (int)$filters['class_id'];
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim((string)$filters['search']) . '%';
            $sql .= " AND (u.nama_lengkap LIKE ? OR u.nim LIKE ? OR u.program_studi LIKE ?) ";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $sql .= " ORDER BY (m.class_id IS NULL) DESC, u.nama_lengkap ASC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('tutorialRplGetStudentsWithPretes error: ' . $e->getMessage());
            // Fallback query jika terdapat perbedaan kolom pada skema tertentu
            try {
                $sqlFallback = "
                    SELECT u.id, u.nama_lengkap, u.nim, u.program_studi, u.fakultas, u.no_hp, u.no_hp AS whatsapp, u.jenis_program,
                           m.id AS member_id, m.class_id, m.drive_submitted, m.drive_verified, m.exam_submitted,
                           m.nilai_ujian_tulis, m.nilai_praktik, m.nilai_akhir, m.status_lulus,
                           c.nama_kelompok, c.dosen_nama, c.dosen_wa, c.drive_url
                    FROM users u
                    LEFT JOIN tutorial_rpl_members m ON u.id = m.user_id
                    LEFT JOIN tutorial_rpl_classes c ON m.class_id = c.id
                    WHERE u.role = 'mahasiswa'
                    ORDER BY (m.class_id IS NULL) DESC, u.nama_lengkap ASC
                ";
                $stmt = $pdo->query($sqlFallback);
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable $e2) {
                error_log('tutorialRplGetStudentsWithPretes fallback error: ' . $e2->getMessage());
                $rows = [];
            }
        }

        $hasCbtTable = false;
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $hasCbtTable = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='pretes_cbt_attempts'")->fetchColumn();
            } else {
                $hasCbtTable = (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='pretes_cbt_attempts'")->fetchColumn();
            }
        } catch (Throwable $e) {}

        $stmtCbt = null;
        if ($hasCbtTable) {
            try {
                $stmtCbt = $pdo->prepare("
                    SELECT score, submitted
                    FROM pretes_cbt_attempts
                    WHERE user_id = ? AND registration_id > 0
                    ORDER BY id DESC LIMIT 1
                ");
            } catch (Throwable $e) {}
        }

        foreach ($rows as &$r) {
            $r['cbt_score'] = null;
            $r['cbt_status'] = 'Belum CBT';

            if ($stmtCbt) {
                try {
                    $stmtCbt->execute([(int)$r['id']]);
                    $cbt = $stmtCbt->fetch(PDO::FETCH_ASSOC);
                    if ($cbt && !empty($cbt['submitted'])) {
                        $score = (float)($cbt['score'] ?? 0);
                        $r['cbt_score'] = $score;
                        $r['cbt_status'] = $score >= 80.0 ? "Lolos CBT ($score)" : "Nilai $score (< 80)";
                    } elseif ($cbt) {
                        $r['cbt_status'] = 'Sedang CBT';
                    }
                } catch (Throwable $e) {}
            }
        }
        unset($r);

        return $rows;
    }
}

/**
 * Simpan evaluasi / penilaian hafalan & verifikasi tugas oleh Tutor Dosen.
 * Otomatis menghitung nilai_akhir jika ujian tulis tutorial sudah ada.
 */
if (!function_exists('tutorialRplSaveEvaluation')) {
    function tutorialRplSaveEvaluation(PDO $pdo, int $memberId, ?float $nilaiPraktik, int $driveVerified = 1, ?string $notes = null): bool {
        ensureTutorialRplTables($pdo);

        $stmtM = $pdo->prepare("SELECT * FROM tutorial_rpl_members WHERE id = ?");
        $stmtM->execute([$memberId]);
        $member = $stmtM->fetch(PDO::FETCH_ASSOC);
        if (!$member) return false;

        $nilaiUjianTulis = ($member['nilai_ujian_tulis'] !== null) ? (float)$member['nilai_ujian_tulis'] : null;

        $nilaiAkhir = null;
        $statusLulus = $member['status_lulus'] ?? 'proses';

        if ($nilaiPraktik !== null && $nilaiUjianTulis !== null) {
            $nilaiAkhir = round(($nilaiUjianTulis + $nilaiPraktik) / 2.0, 2);
            $statusLulus = ($nilaiAkhir >= 80.0) ? 'lulus' : 'tidak_lulus';
        } elseif ($nilaiPraktik !== null) {
            $nilaiAkhir = $nilaiPraktik;
        }

        $stmt = $pdo->prepare("
            UPDATE tutorial_rpl_members SET
                drive_verified = ?,
                drive_submitted = (CASE WHEN ? = 1 THEN 1 ELSE drive_submitted END),
                drive_notes = ?,
                nilai_praktik = ?,
                nilai_akhir = ?,
                status_lulus = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            $driveVerified,
            $driveVerified,
            $notes ?: null,
            $nilaiPraktik,
            $nilaiAkhir,
            $statusLulus,
            $memberId
        ]);
    }
}

/**
 * Verifikasi massal status tugas Google Drive oleh Tutor atau Admin.
 */
if (!function_exists('tutorialRplBatchVerify')) {
    function tutorialRplBatchVerify(PDO $pdo, array $memberIds, int $verified = 1): int {
        ensureTutorialRplTables($pdo);
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds), fn($id) => $id > 0)));
        if (empty($memberIds)) return 0;

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $pdo->prepare("
            UPDATE tutorial_rpl_members SET
                drive_verified = ?,
                drive_submitted = (CASE WHEN ? = 1 THEN 1 ELSE drive_submitted END)
            WHERE id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$verified, $verified], $memberIds));
        return $stmt->rowCount();
    }
}

/**
 * Memastikan tabel CBT Tutorial RPL (sesi, bank soal, attempt) tersedia.
 */
if (!function_exists('ensureTutorialRplCbtTables')) {
    function ensureTutorialRplCbtTables(PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idType = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        $engine = ($driver === 'mysql') ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_cbt_sessions (
            id $idType,
            code VARCHAR(64) NOT NULL UNIQUE,
            title VARCHAR(150) NOT NULL,
            duration_minutes INT NOT NULL DEFAULT 30,
            passing_grade DECIMAL(5,2) NOT NULL DEFAULT 80.00,
            token VARCHAR(32) DEFAULT 'TUTOR26',
            exam_open INT NOT NULL DEFAULT 1,
            is_active INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )$engine");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_cbt_questions (
            id $idType,
            code VARCHAR(64) NOT NULL UNIQUE,
            category VARCHAR(80) NOT NULL,
            question_text TEXT NOT NULL,
            options_json TEXT NOT NULL,
            correct_answer CHAR(1) NOT NULL,
            is_active INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )$engine");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_cbt_attempts (
            id $idType,
            user_id INT NOT NULL,
            class_id INT NOT NULL,
            started_at INT NOT NULL,
            submitted_at INT DEFAULT NULL,
            correct_count INT DEFAULT NULL,
            total_questions INT NOT NULL DEFAULT 25,
            score DECIMAL(5,2) DEFAULT NULL,
            submitted INT NOT NULL DEFAULT 0,
            UNIQUE(user_id, class_id)
        )$engine");

        // Pastikan sesi default tersedia
        $stmt = $pdo->query("SELECT COUNT(*) FROM tutorial_rpl_cbt_sessions");
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->prepare("
                INSERT INTO tutorial_rpl_cbt_sessions (code, title, duration_minutes, passing_grade, token, exam_open, is_active)
                VALUES ('SESI-TUTORIAL-RPL-2026', 'Ujian Tulis Tutorial RPL 2026/2027', 30, 80.00, 'TUTOR26', 1, 1)
            ")->execute();
        }
    }
}

/**
 * Mengambil data sesi CBT Tutorial RPL yang aktif.
 */
if (!function_exists('tutorialRplCbtGetSession')) {
    function tutorialRplCbtGetSession(PDO $pdo): array {
        ensureTutorialRplCbtTables($pdo);
        $ses = $pdo->query("SELECT * FROM tutorial_rpl_cbt_sessions WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$ses) {
            return [
                'id' => 1,
                'code' => 'SESI-TUTORIAL-RPL-2026',
                'title' => 'Ujian Tulis Tutorial RPL 2026/2027',
                'duration_minutes' => 30,
                'passing_grade' => 80.00,
                'token' => 'TUTOR26',
                'exam_open' => 1,
                'is_active' => 1
            ];
        }
        return $ses;
    }
}

/**
 * Update pengaturan sesi CBT Tutorial RPL.
 */
if (!function_exists('tutorialRplCbtUpdateSession')) {
    function tutorialRplCbtUpdateSession(PDO $pdo, array $data): bool {
        ensureTutorialRplCbtTables($pdo);
        $ses = tutorialRplCbtGetSession($pdo);
        $sesId = (int)($ses['id'] ?? 1);

        $stmt = $pdo->prepare("
            UPDATE tutorial_rpl_cbt_sessions SET
                title = ?,
                duration_minutes = ?,
                passing_grade = ?,
                token = ?,
                exam_open = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            trim((string)($data['title'] ?? 'Ujian Tulis Tutorial RPL')),
            max(5, min(180, (int)($data['duration_minutes'] ?? 30))),
            max(0, min(100, (float)($data['passing_grade'] ?? 80.0))),
            trim((string)($data['token'] ?? 'TUTOR26')),
            !empty($data['exam_open']) ? 1 : 0,
            $sesId
        ]);
    }
}

/**
 * Mengambil daftar butir soal CBT Tutorial RPL.
 */
if (!function_exists('tutorialRplCbtGetQuestions')) {
    function tutorialRplCbtGetQuestions(PDO $pdo, ?string $category = null): array {
        ensureTutorialRplCbtTables($pdo);
        tutorialRplCbtSeedQuestionsIfEmpty($pdo);

        $sql = "SELECT * FROM tutorial_rpl_cbt_questions WHERE is_active = 1";
        $params = [];
        if (!empty($category)) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        $sql .= " ORDER BY id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Menyimpan / memperbarui butir soal CBT Tutorial RPL.
 */
if (!function_exists('tutorialRplCbtSaveQuestion')) {
    function tutorialRplCbtSaveQuestion(PDO $pdo, array $data): int {
        ensureTutorialRplCbtTables($pdo);
        $id = (int)($data['id'] ?? 0);
        $category = trim((string)($data['category'] ?? 'Ubudiyah'));
        $text = trim((string)($data['question_text'] ?? ''));
        $correct = strtoupper(trim((string)($data['correct_answer'] ?? 'A')));
        $options = $data['options_json'] ?? '[]';
        if (is_array($options)) $options = json_encode($options, JSON_UNESCAPED_UNICODE);

        if ($text === '') {
            throw new InvalidArgumentException('Teks pertanyaan soal tidak boleh kosong.');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE tutorial_rpl_cbt_questions SET
                    category = ?, question_text = ?, options_json = ?, correct_answer = ?
                WHERE id = ?
            ");
            $stmt->execute([$category, $text, $options, $correct, $id]);
            return $id;
        } else {
            $code = 'Q-TUTOR-' . bin2hex(random_bytes(4));
            $stmt = $pdo->prepare("
                INSERT INTO tutorial_rpl_cbt_questions (code, category, question_text, options_json, correct_answer, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$code, $category, $text, $options, $correct]);
            return (int)$pdo->lastInsertId();
        }
    }
}

/**
 * Menghapus butir soal CBT Tutorial RPL.
 */
if (!function_exists('tutorialRplCbtDeleteQuestion')) {
    function tutorialRplCbtDeleteQuestion(PDO $pdo, int $questionId): bool {
        ensureTutorialRplCbtTables($pdo);
        $stmt = $pdo->prepare("DELETE FROM tutorial_rpl_cbt_questions WHERE id = ?");
        return $stmt->execute([$questionId]);
    }
}

/**
 * Inisialisasi butir soal bawaan Tutorial RPL jika tabel masih kosong.
 */
if (!function_exists('tutorialRplCbtSeedQuestionsIfEmpty')) {
    function tutorialRplCbtSeedQuestionsIfEmpty(PDO $pdo, bool $forceReset = false): int {
        ensureTutorialRplCbtTables($pdo);
        if (!$forceReset) {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM tutorial_rpl_cbt_questions")->fetchColumn();
            if ($count > 0) return $count;
        } else {
            $pdo->exec("DELETE FROM tutorial_rpl_cbt_questions");
        }

        $seed = [
            ['Thaharah', 'Rukun wudhu yang pertama sesuai syariat Islam adalah...', ['Niat', 'Membasuh muka', 'Membasuh kedua tangan', 'Mengusap kepala', 'Tertib'], 'A'],
            ['Thaharah', 'Air yang suci sifat zatnya dan dapat menyucikan benda lain tanpa makruh disebut...', ['Air musta\'mal', 'Air mutanajis', 'Air mutlak', 'Air makruh', 'Air musyammas'], 'C'],
            ['Sholat Wajib', 'Membaca Surat Al-Fatihah dalam setiap rakaat sholat termasuk kategori...', ['Syarat sah sholat', 'Rukun sholat', 'Sunnah ab\'adh', 'Sunnah hai\'ah', 'Syarat wajib'], 'B'],
            ['Sholat Wajib', 'Duduk tasyahud akhir dalam sholat fardhu hukumnya adalah...', ['Sunnah muakkad', 'Rukun sholat', 'Syarat sholat', 'Sunnah ghairu muakkad', 'Mubah'], 'B'],
            ['Sholat Jenazah', 'Jumlah takbir dalam pelaksanaan sholat jenazah menurut kesepakatan jumhur ulama adalah sebanyak...', ['3 takbir', '4 takbir', '5 takbir', '6 takbir', '7 takbir'], 'B'],
            ['Sholat Jenazah', 'Setelah takbir kedua dalam sholat jenazah, bacaan yang disyariatkan adalah...', ['Surat Al-Fatihah', 'Shalawat atas Nabi Muhammad SAW', 'Doa khusus untuk jenazah', 'Doa iftitah', 'Salam'], 'B'],
            ['Zikir & Doa', 'Hukum membaca doa Qunut pada sholat Subuh menurut mazhab Syafi\'i adalah...', ['Wajib', 'Rukun', 'Sunnah Ab\'adh', 'Sunnah Hai\'ah', 'Makruh'], 'C'],
            ['Sholat Sunnah', 'Sholat sunnah dua rakaat yang dikerjakan saat memasuki masjid sebelum duduk dinamakan...', ['Sholat Dhuha', 'Sholat Tahiyatul Masjid', 'Sholat Hajat', 'Sholat Istikharah', 'Sholat Tasbih'], 'B'],
            ['Ubudiyah Praktis', 'Apabila seseorang lupa tidak melakukan tasyahud awal, maka ia disunnahkan untuk melakukan...', ['Mengulang sholat dari awal', 'Sujud sahwi sebelum salam', 'Sujud tilawah', 'Sujud syukur setelah salam', 'Membatalkan sholat'], 'B'],
            ['Ubudiyah Praktis', 'Batasan aurat laki-laki ketika melaksanakan sholat menurut mayoritas ulama adalah...', ['Seluruh tubuh kecuali wajah', 'Antara pusar hingga lutut', 'Dada hingga ujung kaki', 'Pundak hingga lutut', 'Antara leher hingga mata kaki'], 'B']
        ];

        $stmt = $pdo->prepare("
            INSERT INTO tutorial_rpl_cbt_questions (code, category, question_text, options_json, correct_answer, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");

        $inserted = 0;
        foreach ($seed as $i => [$cat, $q, $opts, $ans]) {
            $code = 'TUTOR-RPL-Q' . ($i + 1);
            $stmt->execute([$code, $cat, $q, json_encode($opts, JSON_UNESCAPED_UNICODE), $ans]);
            $inserted++;
        }
        return $inserted;
    }
}

/**
 * Mengambil riwayat attempt CBT Tutorial RPL seluruh mahasiswa atau per kelompok.
 */
if (!function_exists('tutorialRplCbtGetAttempts')) {
    function tutorialRplCbtGetAttempts(PDO $pdo, ?int $classId = null): array {
        ensureTutorialRplCbtTables($pdo);
        $sql = "
            SELECT a.*,
                   u.nama_lengkap, u.nim, u.program_studi,
                   c.nama_kelompok, c.dosen_nama
            FROM tutorial_rpl_cbt_attempts a
            JOIN users u ON a.user_id = u.id
            JOIN tutorial_rpl_classes c ON a.class_id = c.id
        ";
        $params = [];
        if (!empty($classId)) {
            $sql .= " WHERE a.class_id = ?";
            $params[] = $classId;
        }
        $sql .= " ORDER BY a.submitted_at DESC, a.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Simpan / submit attempt pengerjaan CBT Tutorial RPL dan sinkronisasi ke tutorial_rpl_members.
 */
if (!function_exists('tutorialRplCbtSubmitAttempt')) {
    function tutorialRplCbtSubmitAttempt(PDO $pdo, int $userId, int $classId, float $score, int $correctCount, int $totalQuestions = 25): bool {
        ensureTutorialRplCbtTables($pdo);
        $now = time();

        // Cek apakah sudah pernah ada attempt (portable across SQLite & MySQL)
        $chk = $pdo->prepare("SELECT id FROM tutorial_rpl_cbt_attempts WHERE user_id = ? AND class_id = ?");
        $chk->execute([$userId, $classId]);
        $existingId = $chk->fetchColumn();

        if ($existingId) {
            $stmt = $pdo->prepare("
                UPDATE tutorial_rpl_cbt_attempts SET
                    submitted_at = ?,
                    correct_count = ?,
                    total_questions = ?,
                    score = ?,
                    submitted = 1
                WHERE id = ?
            ");
            $stmt->execute([$now, $correctCount, $totalQuestions, $score, $existingId]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO tutorial_rpl_cbt_attempts (user_id, class_id, started_at, submitted_at, correct_count, total_questions, score, submitted)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$userId, $classId, $now - 1200, $now, $correctCount, $totalQuestions, $score]);
        }

        // Sinkronisasi ke tutorial_rpl_members
        $stmtM = $pdo->prepare("SELECT id, nilai_praktik FROM tutorial_rpl_members WHERE user_id = ? AND class_id = ?");
        $stmtM->execute([$userId, $classId]);
        $mem = $stmtM->fetch(PDO::FETCH_ASSOC);

        if ($mem) {
            $memberId = (int)$mem['id'];
            $nilaiPraktik = ($mem['nilai_praktik'] !== null) ? (float)$mem['nilai_praktik'] : null;
            $nilaiAkhir = null;
            $statusLulus = 'proses';

            if ($nilaiPraktik !== null) {
                $nilaiAkhir = round(($score + $nilaiPraktik) / 2.0, 2);
                $statusLulus = ($nilaiAkhir >= 80.0) ? 'lulus' : 'tidak_lulus';
            } else {
                $nilaiAkhir = $score;
            }

            $stmtUpd = $pdo->prepare("
                UPDATE tutorial_rpl_members SET
                    exam_submitted = 1,
                    nilai_ujian_tulis = ?,
                    nilai_akhir = ?,
                    status_lulus = ?
                WHERE id = ?
            ");
            $stmtUpd->execute([$score, $nilaiAkhir, $statusLulus, $memberId]);
        }

        return true;
    }
}

if (!function_exists('tutorialRplCbtResetAttempt')) {
    function tutorialRplCbtResetAttempt(PDO $pdo, int $userId, int $classId): bool {
        ensureTutorialRplCbtTables($pdo);
        $pdo->prepare("DELETE FROM tutorial_rpl_cbt_attempts WHERE user_id = ? AND class_id = ?")->execute([$userId, $classId]);

        // Kembalikan status di tutorial_rpl_members
        $stmtM = $pdo->prepare("SELECT id, nilai_praktik FROM tutorial_rpl_members WHERE user_id = ? AND class_id = ?");
        $stmtM->execute([$userId, $classId]);
        $mem = $stmtM->fetch(PDO::FETCH_ASSOC);

        if ($mem) {
            $memberId = (int)$mem['id'];
            $nilaiPraktik = ($mem['nilai_praktik'] !== null) ? (float)$mem['nilai_praktik'] : null;
            $pdo->prepare("
                UPDATE tutorial_rpl_members SET
                    exam_submitted = 0,
                    nilai_ujian_tulis = NULL,
                    nilai_akhir = ?,
                    status_lulus = 'proses'
                WHERE id = ?
            ")->execute([$nilaiPraktik, $memberId]);
        }
        return true;
    }
}
