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
            ['catatan', 'TEXT DEFAULT NULL'],
            ['is_active', 'INT NOT NULL DEFAULT 1']
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
 * Periksa apakah mahasiswa telah dinyatakan LULUS pada Tutorial RPL.
 */
if (!function_exists('tutorialRplIsUserLulus')) {
    function tutorialRplIsUserLulus(PDO $pdo, int $userId): bool {
        if ($userId <= 0) return false;
        ensureTutorialRplTables($pdo);
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM tutorial_rpl_members WHERE user_id = ? AND status_lulus = 'lulus' LIMIT 1");
            $stmt->execute([$userId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

/**
 * Periksa apakah mahasiswa sudah mendaftar Tutorial RPL (baik sudah diplot ke kelas maupun terdaftar pada periode).
 */
if (!function_exists('tutorialRplIsUserRegistered')) {
    function tutorialRplIsUserRegistered(PDO $pdo, int $userId): bool {
        if ($userId <= 0) return false;
        ensureTutorialRplTables($pdo);
        try {
            $stmtMember = $pdo->prepare("SELECT 1 FROM tutorial_rpl_members WHERE user_id = ? LIMIT 1");
            $stmtMember->execute([$userId]);
            if ((bool)$stmtMember->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {}

        try {
            $stmtReg = $pdo->prepare("SELECT 1 FROM tutorial_rpl_registrations WHERE user_id = ? LIMIT 1");
            $stmtReg->execute([$userId]);
            if ((bool)$stmtReg->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {}

        return false;
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

        $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_cbt_packages (
            id $idType,
            code VARCHAR(64) NOT NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            description TEXT DEFAULT NULL,
            duration_minutes INT DEFAULT 90,
            question_count INT NOT NULL DEFAULT 25,
            is_active INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )$engine");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_cbt_sessions (
            id $idType,
            code VARCHAR(64) NOT NULL UNIQUE,
            title VARCHAR(150) NOT NULL,
            package_ids_json TEXT DEFAULT NULL,
            duration_minutes INT NOT NULL DEFAULT 90,
            passing_grade DECIMAL(5,2) NOT NULL DEFAULT 70.00,
            token VARCHAR(32) DEFAULT 'TUTOR26',
            exam_open INT NOT NULL DEFAULT 1,
            is_active INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )$engine");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_cbt_questions (
            id $idType,
            code VARCHAR(64) NOT NULL UNIQUE,
            package_id INT NOT NULL DEFAULT 1,
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
            session_id INT DEFAULT NULL,
            package_id INT DEFAULT NULL,
            started_at INT NOT NULL,
            deadline_at INT DEFAULT NULL,
            submitted_at INT DEFAULT NULL,
            correct_count INT DEFAULT NULL,
            total_questions INT NOT NULL DEFAULT 25,
            score DECIMAL(5,2) DEFAULT NULL,
            submitted INT NOT NULL DEFAULT 0,
            UNIQUE(user_id, class_id)
        )$engine");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_cbt_items (
            id $idType,
            attempt_id INT NOT NULL,
            question_id INT NOT NULL,
            position INT NOT NULL,
            category VARCHAR(80) DEFAULT NULL,
            question_text TEXT DEFAULT NULL,
            options_json TEXT DEFAULT NULL,
            correct_answer CHAR(1) DEFAULT NULL,
            answer CHAR(1) DEFAULT NULL,
            flagged INT NOT NULL DEFAULT 0,
            updated_at INT DEFAULT NULL,
            UNIQUE(attempt_id, position)
        )$engine");

        try {
            $pdo->exec("ALTER TABLE tutorial_rpl_cbt_questions ADD COLUMN package_id INT NOT NULL DEFAULT 1");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE tutorial_rpl_cbt_sessions ADD COLUMN package_ids_json TEXT DEFAULT NULL");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE tutorial_rpl_cbt_attempts ADD COLUMN deadline_at INT DEFAULT NULL");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE tutorial_rpl_cbt_attempts ADD COLUMN session_id INT DEFAULT NULL");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE tutorial_rpl_cbt_attempts ADD COLUMN package_id INT DEFAULT NULL");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE tutorial_rpl_cbt_attempts ADD COLUMN total_questions INT NOT NULL DEFAULT 25");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE tutorial_rpl_cbt_attempts ADD COLUMN correct_count INT DEFAULT NULL");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE tutorial_rpl_cbt_attempts ADD COLUMN score DECIMAL(5,2) DEFAULT NULL");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE tutorial_rpl_cbt_attempts ADD COLUMN submitted INT NOT NULL DEFAULT 0");
        } catch (Throwable $e) {}

        // Inisialisasi dan sinkronisasi Paket Soal, Sesi Ujian, & Data Soal CBT Pretes
        tutorialRplCbtSeedPackagesAndQuestions($pdo, false);
    }
}

/**
 * Inisialisasi Paket Soal & Sesi Ujian Tutorial RPL disamakan dengan CBT Pretes RPL,
 * serta menindih data soal lama dengan butir soal resmi dari CBT Pretes (Muslim & Kristen Protestan).
 */
if (!function_exists('tutorialRplCbtSeedPackagesAndQuestions')) {
    function tutorialRplCbtSeedPackagesAndQuestions(PDO $pdo, bool $force = false): array {
        // 1. Paket Soal (Paket 1: Utama / Muslim & Paket 2: Kristen Protestan)
        $stmtPkg = $pdo->prepare("SELECT id FROM tutorial_rpl_cbt_packages WHERE code = ?");
        $stmtPkg->execute(['PKT-TUTOR-UTAMA']);
        $pkg1Id = (int)$stmtPkg->fetchColumn();

        if ($pkg1Id <= 0) {
            $stmt = $pdo->prepare("
                INSERT INTO tutorial_rpl_cbt_packages (id, code, name, description, duration_minutes, question_count, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                1,
                'PKT-TUTOR-UTAMA',
                'Paket 1 - Utama (Ubudiyah / Keislaman)',
                "Paket soal standar ujian tutorial ubudiyah keislaman LPPAI (Rukun Islam, Shalat, Thaharah, Puasa, Zakat, Jenazah, Al-Qur'an, Akhlak, Amaliyah).",
                90,
                25,
                1
            ]);
            $pkg1Id = 1;
        }

        $stmtPkg->execute(['PKT-2-KRISTEN-PROTESTAN']);
        $pkg2Id = (int)$stmtPkg->fetchColumn();

        if ($pkg2Id <= 0) {
            $stmt = $pdo->prepare("
                INSERT INTO tutorial_rpl_cbt_packages (id, code, name, description, duration_minutes, question_count, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                2,
                'PKT-2-KRISTEN-PROTESTAN',
                'Paket 2 - Kristen Protestan',
                'Paket 2 butir soal resmi pendidikan agama Kristen Protestan (25 butir soal).',
                90,
                25,
                1
            ]);
            $pkg2Id = 2;
        }

        // 2. Sesi Ujian (Sesi 1: Utama / Muslim & Sesi 2: Kristen Protestan)
        $stmtSes = $pdo->prepare("SELECT id FROM tutorial_rpl_cbt_sessions WHERE code = ?");
        $stmtSes->execute(['SESI-TUTORIAL-RPL-2026']);
        $ses1Id = (int)$stmtSes->fetchColumn();

        if ($ses1Id <= 0) {
            $stmt = $pdo->prepare("
                INSERT INTO tutorial_rpl_cbt_sessions (id, code, title, package_ids_json, duration_minutes, passing_grade, token, exam_open, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([1, 'SESI-TUTORIAL-RPL-2026', 'Sesi Ujian Tulis Tutorial RPL 2026/2027 (Utama / Muslim)', '[1]', 90, 70.00, 'TUTOR26', 1, 1]);
        } elseif ($force) {
            $pdo->prepare("UPDATE tutorial_rpl_cbt_sessions SET package_ids_json = ?, duration_minutes = 90, passing_grade = 70.00 WHERE id = ?")->execute(['[1]', $ses1Id]);
        }

        $stmtSes->execute(['SESI-TUTORIAL-PROTESTAN-2026']);
        $ses2Id = (int)$stmtSes->fetchColumn();

        if ($ses2Id <= 0) {
            $stmt = $pdo->prepare("
                INSERT INTO tutorial_rpl_cbt_sessions (id, code, title, package_ids_json, duration_minutes, passing_grade, token, exam_open, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([2, 'SESI-TUTORIAL-PROTESTAN-2026', 'Sesi Ujian Tulis Tutorial RPL – Kristen Protestan', '[2]', 90, 70.00, 'TUTOR26', 1, 1]);
        } elseif ($force) {
            $pdo->prepare("UPDATE tutorial_rpl_cbt_sessions SET package_ids_json = ?, duration_minutes = 90, passing_grade = 70.00 WHERE id = ?")->execute(['[2]', $ses2Id]);
        }

        // 3. Menindih Data Soal Lama dengan Soal Resmi CBT Pretes (177 Muslim + 25 Kristen = 202 Soal)
        $totalQuestions = (int)$pdo->query("SELECT COUNT(*) FROM tutorial_rpl_cbt_questions")->fetchColumn();
        $hasDummy = (int)$pdo->query("SELECT COUNT(*) FROM tutorial_rpl_cbt_questions WHERE code LIKE 'TUTOR-RPL-Q%'")->fetchColumn() > 0;
        $hasOld40Seed = ((int)$pdo->query("SELECT COUNT(*) FROM tutorial_rpl_cbt_questions WHERE code LIKE 'TUTOR-ISL-%'")->fetchColumn() === 40);

        $addedIslam = 0;
        $addedKristen = 0;

        if ($force || $totalQuestions === 0 || $hasDummy || $hasOld40Seed) {
            $pdo->exec("DELETE FROM tutorial_rpl_cbt_questions");

            // 3a. Soal Muslim / Ubudiyah (Paket 1) — Salin 177 butir soal resmi CBT Pretes
            $seededIslam = false;

            // Prioritas 1: Jika tabel pretes_cbt_questions di database sudah terisi >= 100 butir soal
            try {
                $hasPretesTable = (int)$pdo->query("SELECT COUNT(*) FROM pretes_cbt_questions WHERE package_id = 1")->fetchColumn();
                if ($hasPretesTable >= 100) {
                    $pretesQuestions = $pdo->query("SELECT * FROM pretes_cbt_questions WHERE package_id = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($pretesQuestions)) {
                        $stmtIns = $pdo->prepare("
                            INSERT INTO tutorial_rpl_cbt_questions (code, package_id, category, question_text, options_json, correct_answer, is_active)
                            VALUES (?, 1, ?, ?, ?, ?, ?)
                        ");
                        foreach ($pretesQuestions as $i => $pq) {
                            $code = 'TUTOR-ISL-' . sprintf('%03d', $i + 1);
                            $stmtIns->execute([
                                $code,
                                $pq['category'] ?? 'Umum',
                                $pq['question_text'],
                                $pq['options_json'],
                                $pq['correct_answer'],
                                (int)($pq['is_active'] ?? 1)
                            ]);
                            $addedIslam++;
                        }
                        if ($addedIslam > 0) $seededIslam = true;
                    }
                }
            } catch (Throwable $e) {}

            // Prioritas 2: Membaca berkas master 177 soal CBT Pretes (downloads/bank-soal-pretes-177-soal.xlsx)
            if (!$seededIslam) {
                $xlsx177 = __DIR__ . '/../downloads/bank-soal-pretes-177-soal.xlsx';
                if (file_exists($xlsx177)) {
                    require_once __DIR__ . '/pretes-cbt-xlsx.php';
                    if (function_exists('cbtReadQuestionXlsx')) {
                        try {
                            $rows = cbtReadQuestionXlsx($xlsx177);
                            $stmtIns = $pdo->prepare("
                                INSERT INTO tutorial_rpl_cbt_questions (code, package_id, category, question_text, options_json, correct_answer, is_active)
                                VALUES (?, 1, ?, ?, ?, ?, ?)
                            ");
                            $qIdx = 0;
                            foreach ($rows as $r) {
                                if (($r['row'] ?? 0) === 1) continue; // lewati baris judul header
                                $cells = $r['cells'] ?? [];
                                $cat = trim($cells[1] ?? 'Umum');
                                $qText = trim($cells[2] ?? '');
                                $optA = trim($cells[3] ?? '');
                                $optB = trim($cells[4] ?? '');
                                $optC = trim($cells[5] ?? '');
                                $optD = trim($cells[6] ?? '');
                                $optE = trim($cells[7] ?? '');
                                $ans = strtoupper(trim($cells[8] ?? 'A'));
                                $status = trim($cells[9] ?? 'Aktif');
                                if (!$qText || !$optA || !$optB) continue;
                                $qIdx++;
                                $code = 'TUTOR-ISL-' . sprintf('%03d', $qIdx);
                                $optsJson = json_encode([$optA, $optB, $optC, $optD, $optE], JSON_UNESCAPED_UNICODE);
                                $isActive = (strtolower($status) !== 'nonaktif') ? 1 : 0;
                                $stmtIns->execute([$code, $cat, $qText, $optsJson, $ans, $isActive]);
                                $addedIslam++;
                            }
                            if ($addedIslam > 0) $seededIslam = true;
                        } catch (Throwable $e) {}
                    }
                }
            }

            // Prioritas 3 (Fallback cadangan jika berkas xlsx tidak terbaca): data/pretes-cbt-bank.php
            if (!$seededIslam) {
                $bankFile = __DIR__ . '/../data/pretes-cbt-bank.php';
                if (file_exists($bankFile)) {
                    $bank = require $bankFile;
                    $stmtIns = $pdo->prepare("
                        INSERT INTO tutorial_rpl_cbt_questions (code, package_id, category, question_text, options_json, correct_answer, is_active)
                        VALUES (?, 1, ?, ?, ?, ?, 1)
                    ");
                    foreach ($bank as $i => [$qText, $opts, $ans, $cat]) {
                        $code = 'TUTOR-ISL-' . sprintf('%03d', $i + 1);
                        $stmtIns->execute([
                            $code,
                            $cat,
                            $qText,
                            json_encode(array_values($opts), JSON_UNESCAPED_UNICODE),
                            $ans
                        ]);
                        $addedIslam++;
                    }
                }
            }

            // 3b. Soal Kristen Protestan (Paket 2) dari data/paket-2-kristen-protestan.php
            $kpFile = __DIR__ . '/../data/paket-2-kristen-protestan.php';
            if (file_exists($kpFile)) {
                $kpBank = require $kpFile;
                $stmtInsKp = $pdo->prepare("
                    INSERT INTO tutorial_rpl_cbt_questions (code, package_id, category, question_text, options_json, correct_answer, is_active)
                    VALUES (?, 2, ?, ?, ?, ?, 1)
                ");
                foreach ($kpBank as [$code, $cat, $qText, $opts, $ans]) {
                    $stmtInsKp->execute([
                        $code,
                        $cat,
                        $qText,
                        json_encode(array_values($opts), JSON_UNESCAPED_UNICODE),
                        $ans
                    ]);
                    $addedKristen++;
                }
            }
        }

        return [
            'packages' => 2,
            'sessions' => 2,
            'questions_islam' => $addedIslam,
            'questions_kristen' => $addedKristen,
            'total_questions' => (int)$pdo->query("SELECT COUNT(*) FROM tutorial_rpl_cbt_questions")->fetchColumn()
        ];
    }
}

/**
 * Mengambil daftar paket soal CBT Tutorial RPL.
 */
if (!function_exists('tutorialRplCbtGetPackages')) {
    function tutorialRplCbtGetPackages(PDO $pdo): array {
        ensureTutorialRplCbtTables($pdo);
        return $pdo->query("
            SELECT p.*,
                   (SELECT COUNT(*) FROM tutorial_rpl_cbt_questions q WHERE q.package_id = p.id) AS total_questions,
                   (SELECT COUNT(*) FROM tutorial_rpl_cbt_questions q WHERE q.package_id = p.id AND q.is_active = 1) AS question_count_actual
            FROM tutorial_rpl_cbt_packages p
            ORDER BY p.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Mengambil satu paket soal berdasarkan ID.
 */
if (!function_exists('tutorialRplCbtGetPackage')) {
    function tutorialRplCbtGetPackage(PDO $pdo, int $id): ?array {
        ensureTutorialRplCbtTables($pdo);
        $stmt = $pdo->prepare("SELECT * FROM tutorial_rpl_cbt_packages WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

/**
 * Menyimpan / memperbarui paket soal CBT Tutorial RPL.
 */
if (!function_exists('tutorialRplCbtSavePackage')) {
    function tutorialRplCbtSavePackage(PDO $pdo, array $data): int {
        ensureTutorialRplCbtTables($pdo);
        $id = (int)($data['id'] ?? ($data['package_id'] ?? 0));
        $code = trim((string)($data['code'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        $desc = trim((string)($data['description'] ?? ''));
        $duration = max(5, min(300, (int)($data['duration_minutes'] ?? 90)));
        $questionCount = max(1, (int)($data['question_count'] ?? 25));
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($code === '') {
            $code = 'PKT-TUTOR-' . date('YmdHis');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE tutorial_rpl_cbt_packages SET
                    code = ?,
                    name = ?,
                    description = ?,
                    duration_minutes = ?,
                    question_count = ?,
                    is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$code, $name, $desc, $duration, $questionCount, $isActive, $id]);
            return $id;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO tutorial_rpl_cbt_packages (code, name, description, duration_minutes, question_count, is_active)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$code, $name, $desc, $duration, $questionCount, $isActive]);
            return (int)$pdo->lastInsertId();
        }
    }
}

/**
 * Mengaktifkan paket soal CBT Tutorial RPL.
 */
if (!function_exists('tutorialRplCbtActivatePackage')) {
    function tutorialRplCbtActivatePackage(PDO $pdo, int $id): bool {
        ensureTutorialRplCbtTables($pdo);
        $stmt = $pdo->prepare("UPDATE tutorial_rpl_cbt_packages SET is_active = CASE WHEN id = ? THEN 1 ELSE is_active END WHERE id = ?");
        return $stmt->execute([$id, $id]);
    }
}

/**
 * Menghapus paket soal CBT Tutorial RPL beserta butir pertanyaannya.
 */
if (!function_exists('tutorialRplCbtDeletePackage')) {
    function tutorialRplCbtDeletePackage(PDO $pdo, int $id): bool {
        ensureTutorialRplCbtTables($pdo);
        if ($id <= 2) {
            // Lindungi Paket Inti 1 (Muslim) dan 2 (Kristen) dari penghapusan total
            return false;
        }
        $pdo->prepare("DELETE FROM tutorial_rpl_cbt_questions WHERE package_id = ?")->execute([$id]);
        $stmt = $pdo->prepare("DELETE FROM tutorial_rpl_cbt_packages WHERE id = ?");
        return $stmt->execute([$id]);
    }
}

/**
 * Mengambil daftar sesi ujian CBT Tutorial RPL.
 */
if (!function_exists('tutorialRplCbtGetSessions')) {
    function tutorialRplCbtGetSessions(PDO $pdo): array {
        ensureTutorialRplCbtTables($pdo);
        return $pdo->query("SELECT * FROM tutorial_rpl_cbt_sessions ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Mengambil data sesi CBT Tutorial RPL yang aktif atau berdasarkan ID.
 */
if (!function_exists('tutorialRplCbtGetSession')) {
    function tutorialRplCbtGetSession(PDO $pdo, ?int $id = null): array {
        ensureTutorialRplCbtTables($pdo);
        if ($id !== null && $id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM tutorial_rpl_cbt_sessions WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }
        $ses = $pdo->query("SELECT * FROM tutorial_rpl_cbt_sessions WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$ses) {
            return [
                'id' => 1,
                'code' => 'SESI-TUTORIAL-RPL-2026',
                'title' => 'Sesi Ujian Tulis Tutorial RPL 2026/2027 (Utama / Muslim)',
                'package_ids_json' => '[1]',
                'duration_minutes' => 90,
                'passing_grade' => 70.00,
                'token' => 'TUTOR26',
                'exam_open' => 1,
                'is_active' => 1
            ];
        }
        return $ses;
    }
}

/**
 * Menyimpan atau memperbarui data sesi ujian CBT Tutorial RPL.
 */
if (!function_exists('tutorialRplCbtSaveSession')) {
    function tutorialRplCbtSaveSession(PDO $pdo, array $data): int {
        ensureTutorialRplCbtTables($pdo);
        $sesId = (int)($data['id'] ?? ($data['session_id'] ?? 0));
        $code = trim((string)($data['code'] ?? ''));
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            $title = 'Sesi Ujian Tulis Tutorial RPL';
        }

        $packageIds = $data['package_ids'] ?? ($data['package_ids_json'] ?? null);
        if (is_array($packageIds)) {
            $packageIds = json_encode(array_values(array_map('intval', $packageIds)));
        } elseif (empty($packageIds)) {
            $pkgId = (int)($data['package_id'] ?? ($sesId === 2 ? 2 : 1));
            $packageIds = json_encode([$pkgId]);
        } elseif (!is_string($packageIds)) {
            $packageIds = json_encode([1]);
        }

        $duration = max(5, min(300, (int)($data['duration_minutes'] ?? 90)));
        $passingGrade = max(0, min(100, (float)($data['passing_grade'] ?? 70.0)));
        $token = strtoupper(trim((string)($data['token'] ?? 'TUTOR26')));
        if ($token === '') $token = 'TUTOR26';
        $examOpen = !empty($data['exam_open']) ? 1 : 0;
        $isActive = isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : 1;

        if ($sesId > 0) {
            if ($code !== '') {
                $stmt = $pdo->prepare("
                    UPDATE tutorial_rpl_cbt_sessions SET
                        code = ?,
                        title = ?,
                        package_ids_json = ?,
                        duration_minutes = ?,
                        passing_grade = ?,
                        token = ?,
                        exam_open = ?,
                        is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$code, $title, $packageIds, $duration, $passingGrade, $token, $examOpen, $isActive, $sesId]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE tutorial_rpl_cbt_sessions SET
                        title = ?,
                        package_ids_json = ?,
                        duration_minutes = ?,
                        passing_grade = ?,
                        token = ?,
                        exam_open = ?,
                        is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$title, $packageIds, $duration, $passingGrade, $token, $examOpen, $isActive, $sesId]);
            }
            return $sesId;
        } else {
            if ($code === '') {
                $code = 'SESI-TUTOR-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            }
            $stmt = $pdo->prepare("
                INSERT INTO tutorial_rpl_cbt_sessions
                    (code, title, package_ids_json, duration_minutes, passing_grade, token, exam_open, is_active)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$code, $title, $packageIds, $duration, $passingGrade, $token, $examOpen, $isActive]);
            return (int)$pdo->lastInsertId();
        }
    }
}

/**
 * Menghapus sesi ujian CBT Tutorial RPL.
 */
if (!function_exists('tutorialRplCbtDeleteSession')) {
    function tutorialRplCbtDeleteSession(PDO $pdo, int $sessionId): bool {
        if ($sessionId <= 0) return false;
        ensureTutorialRplCbtTables($pdo);

        $total = (int)$pdo->query("SELECT COUNT(*) FROM tutorial_rpl_cbt_sessions")->fetchColumn();
        if ($total <= 1) {
            return false;
        }

        $stmt = $pdo->prepare("DELETE FROM tutorial_rpl_cbt_sessions WHERE id = ?");
        $ok = $stmt->execute([$sessionId]);

        if ($ok && $stmt->rowCount() > 0) {
            $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM tutorial_rpl_cbt_sessions WHERE is_active = 1")->fetchColumn();
            if ($activeCount === 0) {
                $firstId = $pdo->query("SELECT id FROM tutorial_rpl_cbt_sessions ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($firstId) {
                    $pdo->prepare("UPDATE tutorial_rpl_cbt_sessions SET is_active = 1 WHERE id = ?")->execute([(int)$firstId]);
                }
            }
            return true;
        }
        return false;
    }
}

/**
 * Update pengaturan sesi CBT Tutorial RPL (backward compatibility).
 */
if (!function_exists('tutorialRplCbtUpdateSession')) {
    function tutorialRplCbtUpdateSession(PDO $pdo, array $data): bool {
        if (empty($data['id']) && empty($data['session_id'])) {
            $ses = tutorialRplCbtGetSession($pdo);
            $data['id'] = (int)($ses['id'] ?? 1);
        }
        return tutorialRplCbtSaveSession($pdo, $data) > 0;
    }
}

/**
 * Mengambil daftar butir soal CBT Tutorial RPL dengan filter kategori dan paket soal.
 */
if (!function_exists('tutorialRplCbtGetQuestions')) {
    function tutorialRplCbtGetQuestions(PDO $pdo, ?string $category = null, ?int $packageId = null): array {
        ensureTutorialRplCbtTables($pdo);

        $sql = "SELECT q.*, p.name AS package_name, p.code AS package_code
                FROM tutorial_rpl_cbt_questions q
                LEFT JOIN tutorial_rpl_cbt_packages p ON p.id = q.package_id
                WHERE q.is_active = 1";
        $params = [];
        if (!empty($category)) {
            $sql .= " AND q.category = ?";
            $params[] = $category;
        }
        if (!empty($packageId) && $packageId > 0) {
            $sql .= " AND q.package_id = ?";
            $params[] = $packageId;
        }
        $sql .= " ORDER BY q.package_id ASC, q.id ASC";

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
        $packageId = max(1, (int)($data['package_id'] ?? 1));
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
                    package_id = ?, category = ?, question_text = ?, options_json = ?, correct_answer = ?
                WHERE id = ?
            ");
            $stmt->execute([$packageId, $category, $text, $options, $correct, $id]);
            return $id;
        } else {
            $code = 'Q-TUTOR-' . bin2hex(random_bytes(4));
            $stmt = $pdo->prepare("
                INSERT INTO tutorial_rpl_cbt_questions (code, package_id, category, question_text, options_json, correct_answer, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$code, $packageId, $category, $text, $options, $correct]);
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
 * Inisialisasi butir soal bawaan Tutorial RPL jika tabel masih kosong (alias wrapper).
 */
if (!function_exists('tutorialRplCbtSeedQuestionsIfEmpty')) {
    function tutorialRplCbtSeedQuestionsIfEmpty(PDO $pdo, bool $forceReset = false): int {
        $res = tutorialRplCbtSeedPackagesAndQuestions($pdo, $forceReset);
        return (int)($res['total_questions'] ?? 0);
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
                   c.nama_kelompok, c.dosen_nama,
                   s.title AS session_title, s.code AS session_code,
                   p.name AS package_name, p.code AS package_code
            FROM tutorial_rpl_cbt_attempts a
            JOIN users u ON a.user_id = u.id
            LEFT JOIN tutorial_rpl_classes c ON a.class_id = c.id
            LEFT JOIN tutorial_rpl_cbt_sessions s ON a.session_id = s.id
            LEFT JOIN tutorial_rpl_cbt_packages p ON a.package_id = p.id
        ";
        $params = [];
        if (!empty($classId)) {
            $sql .= " WHERE a.class_id = ?";
            $params[] = $classId;
        }
        $sql .= " ORDER BY a.submitted_at DESC, a.id DESC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
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

        // Hapus item-item pada attempt mahasiswa
        $stmtFind = $pdo->prepare("SELECT id FROM tutorial_rpl_cbt_attempts WHERE user_id = ? AND class_id = ?");
        $stmtFind->execute([$userId, $classId]);
        $attemptId = (int)$stmtFind->fetchColumn();

        if ($attemptId > 0) {
            $pdo->prepare("DELETE FROM tutorial_rpl_cbt_items WHERE attempt_id = ?")->execute([$attemptId]);
        }
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

/**
 * Mengambil attempt aktif mahasiswa atau membuat attempt baru beserta lembar butir soalnya.
 * Mendukung adaptasi paket soal berdasarkan sesi yang dipilih atau deteksi agama mahasiswa (Muslim vs Kristen).
 */
/**
 * Menghapus / me-reset satu attempt ujian CBT Tutorial RPL berdasarkan ID attempt.
 */
if (!function_exists('tutorialRplCbtDeleteAttempt')) {
    function tutorialRplCbtDeleteAttempt(PDO $pdo, int $attemptId): bool {
        if ($attemptId <= 0) return false;
        ensureTutorialRplCbtTables($pdo);

        $stmt = $pdo->prepare("SELECT user_id, class_id FROM tutorial_rpl_cbt_attempts WHERE id = ?");
        $stmt->execute([$attemptId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        return tutorialRplCbtResetAttempt($pdo, (int)$row['user_id'], (int)$row['class_id']);
    }
}

/**
 * Menghapus seluruh / kelompok attempt ujian CBT Tutorial RPL sesuai filter scope.
 */
if (!function_exists('tutorialRplCbtDeleteAllAttempts')) {
    function tutorialRplCbtDeleteAllAttempts(PDO $pdo, ?string $scope = 'all'): int {
        ensureTutorialRplCbtTables($pdo);
        $where = '';
        if ($scope === '1' || $scope === 'muslim') {
            $where = 'WHERE package_id = 1';
        } elseif ($scope === '2' || $scope === 'kristen') {
            $where = 'WHERE package_id = 2';
        } elseif ($scope === 'finished') {
            $where = 'WHERE submitted_at IS NOT NULL';
        } elseif ($scope === 'live') {
            $where = 'WHERE submitted_at IS NULL AND deadline_at > ' . time();
        }

        $attempts = $pdo->query("SELECT id, user_id, class_id FROM tutorial_rpl_cbt_attempts $where")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($attempts)) return 0;

        $count = 0;
        foreach ($attempts as $a) {
            if (tutorialRplCbtResetAttempt($pdo, (int)$a['user_id'], (int)$a['class_id'])) {
                $count++;
            }
        }
        return $count;
    }
}

/**
 * Filter tab "Hasil Ujian" CBT Tutorial RPL (dipakai halaman dan AJAX live_results).
 */
if (!function_exists('tutorialRplCbtResultFilters')) {
    function tutorialRplCbtResultFilters(array $src): array {
        $status = trim((string)($src['status_filter'] ?? 'all'));
        if (!in_array($status, ['all', 'live', 'finished'], true)) $status = 'all';

        $result = trim((string)($src['result_filter'] ?? 'all'));
        if (!in_array($result, ['all', '1', '2'], true)) $result = 'all';

        $q = preg_replace('/\s+/u', ' ', trim((string)($src['q'] ?? ''))) ?? '';
        if (function_exists('mb_substr')) $q = mb_substr($q, 0, 100); else $q = substr($q, 0, 100);

        $group = trim((string)($src['group'] ?? ''));

        $pass = trim((string)($src['pass_filter'] ?? 'all'));
        if (!in_array($pass, ['all', 'lulus', 'tidak_lulus'], true)) $pass = 'all';

        if ($pass !== 'all' && $status === 'live') $status = 'finished';

        $perPage = (int)($src['per_page'] ?? 25);
        if (!in_array($perPage, [10, 25, 50, 100], true)) $perPage = 25;

        return [
            'status_filter' => $status,
            'result_filter' => $result,
            'q' => $q,
            'group' => $group,
            'pass_filter' => $pass,
            'page' => max(1, (int)($src['page'] ?? 1)),
            'per_page' => $perPage,
        ];
    }
}

/**
 * Bangun WHERE untuk daftar hasil CBT Tutorial RPL.
 */
if (!function_exists('tutorialRplCbtResultWhere')) {
    function tutorialRplCbtResultWhere(array $f, int $now, float $passingGrade, bool $withPass = true): array {
        $conds = [];
        $params = [];

        if ($f['status_filter'] === 'live') {
            $conds[] = '(a.submitted_at IS NULL AND a.deadline_at > :now_ts)';
            $params[':now_ts'] = $now;
        } elseif ($f['status_filter'] === 'finished') {
            $conds[] = '(a.submitted_at IS NOT NULL OR a.deadline_at <= :now_ts)';
            $params[':now_ts'] = $now;
        }

        if ($f['result_filter'] === '1') {
            $conds[] = 'a.package_id = 1';
        } elseif ($f['result_filter'] === '2') {
            $conds[] = 'a.package_id = 2';
        }

        if (!empty($f['group'])) {
            $conds[] = 'c.nama_kelompok = :group_name';
            $params[':group_name'] = $f['group'];
        }

        if ($withPass && $f['pass_filter'] === 'lulus') {
            $conds[] = '(a.submitted_at IS NOT NULL AND a.score >= :pass_min)';
            $params[':pass_min'] = $passingGrade;
        } elseif ($withPass && $f['pass_filter'] === 'tidak_lulus') {
            $conds[] = '(a.submitted_at IS NOT NULL AND (a.score IS NULL OR a.score < :pass_min))';
            $params[':pass_min'] = $passingGrade;
        }

        if (!empty($f['q'])) {
            $kw = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $f['q']) . '%';
            $conds[] = "(u.nama_lengkap LIKE :kw_nama ESCAPE '!' OR u.nim LIKE :kw_nim ESCAPE '!' OR u.program_studi LIKE :kw_prodi ESCAPE '!' OR c.nama_kelompok LIKE :kw_grp ESCAPE '!')";
            $params[':kw_nama'] = $kw;
            $params[':kw_nim'] = $kw;
            $params[':kw_prodi'] = $kw;
            $params[':kw_grp'] = $kw;
        }

        $sql = !empty($conds) ? 'WHERE ' . implode(' AND ', $conds) : '';
        return [$sql, $params];
    }
}

/**
 * Hitung jumlah lulus dan tidak lulus untuk filter chips.
 */
if (!function_exists('tutorialRplCbtResultPassCounts')) {
    function tutorialRplCbtResultPassCounts(PDO $pdo, array $filters, int $now, float $passingGrade): array {
        ensureTutorialRplCbtTables($pdo);

        [$whereBase, $paramsBase] = tutorialRplCbtResultWhere($filters, $now, $passingGrade, false);

        $paramsBase[':pass_min1'] = $passingGrade;
        $paramsBase[':pass_min2'] = $passingGrade;

        $sqlBase = "
            SELECT
                SUM(CASE WHEN a.submitted_at IS NOT NULL AND a.score >= :pass_min1 THEN 1 ELSE 0 END) AS lulus_count,
                SUM(CASE WHEN a.submitted_at IS NOT NULL AND (a.score IS NULL OR a.score < :pass_min2) THEN 1 ELSE 0 END) AS tidak_lulus_count
            FROM tutorial_rpl_cbt_attempts a
            JOIN users u ON a.user_id = u.id
            LEFT JOIN tutorial_rpl_classes c ON a.class_id = c.id
            $whereBase
        ";

        try {
            $stmt = $pdo->prepare($sqlBase);
            foreach ($paramsBase as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $row = [];
        }

        return [
            'lulus' => (int)($row['lulus_count'] ?? 0),
            'tidak_lulus' => (int)($row['tidak_lulus_count'] ?? 0),
        ];
    }
}

/**
 * Bangun URL navigasi tab Hasil Ujian CBT Tutorial RPL.
 */
if (!function_exists('buildTutorialRplResultUrl')) {
    function buildTutorialRplResultUrl(array $overrides = []): string {
        global $cbtFilters;
        $status = $overrides['status_filter'] ?? ($cbtFilters['status_filter'] ?? 'all');
        $result = $overrides['result_filter'] ?? ($cbtFilters['result_filter'] ?? 'all');
        $pass = $overrides['pass_filter'] ?? ($cbtFilters['pass_filter'] ?? 'all');
        $group = array_key_exists('group', $overrides) ? (string)$overrides['group'] : (string)($cbtFilters['group'] ?? '');
        $q = array_key_exists('q', $overrides) ? (string)$overrides['q'] : (string)($cbtFilters['q'] ?? '');
        $page = $overrides['page'] ?? ($cbtFilters['page'] ?? 1);
        $perPage = $overrides['per_page'] ?? ($cbtFilters['per_page'] ?? 25);

        $params = ['tab' => 'results'];
        if ($status !== 'all') $params['status_filter'] = $status;
        if ($result !== 'all') $params['result_filter'] = $result;
        if ($pass !== 'all') $params['pass_filter'] = $pass;
        if ($group !== '') $params['group'] = $group;
        if ($q !== '') $params['q'] = $q;
        if ((int)$page > 1) $params['page'] = (int)$page;
        if ((int)$perPage !== 25) $params['per_page'] = (int)$perPage;

        return '?' . http_build_query($params);
    }
}

if (!function_exists('tutorialRplCbtGetOrCreateAttempt')) {
    function tutorialRplCbtGetOrCreateAttempt(PDO $pdo, int $userId, int $classId, ?array $session = null, ?int $sessionId = null, ?int $packageId = null): array {
        ensureTutorialRplCbtTables($pdo);

        // Cari atau validasi sesi
        if ($session === null) {
            if ($sessionId !== null && $sessionId > 0) {
                $session = tutorialRplCbtGetSession($pdo, $sessionId);
            } else {
                // Deteksi apakah mahasiswa Kristen Protestan
                $isKristen = function_exists('isPretesUserKristen') ? isPretesUserKristen($pdo, $userId) : false;
                if ($isKristen) {
                    $session = tutorialRplCbtGetSession($pdo, 2);
                } else {
                    $session = tutorialRplCbtGetSession($pdo, 1);
                }
            }
        }

        $sessionId = (int)($session['id'] ?? 1);

        // Tentukan package_id
        if ($packageId === null || $packageId <= 0) {
            $pkgIds = !empty($session['package_ids_json']) ? json_decode((string)$session['package_ids_json'], true) : [];
            if (is_array($pkgIds) && !empty($pkgIds)) {
                $packageId = (int)$pkgIds[0];
            } else {
                $packageId = ($sessionId === 2) ? 2 : 1;
            }
        }

        $stmt = $pdo->prepare("SELECT * FROM tutorial_rpl_cbt_attempts WHERE user_id = ? AND class_id = ?");
        $stmt->execute([$userId, $classId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

        $now = time();
        $durationMinutes = max(5, (int)($session['duration_minutes'] ?? 90));
        $durationSeconds = $durationMinutes * 60;

        if (!$attempt) {
            $questions = tutorialRplCbtGetQuestions($pdo, null, $packageId);
            if (empty($questions)) {
                tutorialRplCbtSeedPackagesAndQuestions($pdo, true);
                $questions = tutorialRplCbtGetQuestions($pdo, null, $packageId);
            }

            // Ambil jumlah soal yang ditentukan paket atau seluruh soal yang tersedia
            $pkg = tutorialRplCbtGetPackage($pdo, $packageId);
            $targetCount = ($pkg && !empty($pkg['question_count'])) ? (int)$pkg['question_count'] : 25;
            if (count($questions) > $targetCount) {
                $questions = array_slice($questions, 0, $targetCount);
            }

            $totalQuestions = count($questions);
            $deadlineAt = $now + $durationSeconds;

            $ins = $pdo->prepare("
                INSERT INTO tutorial_rpl_cbt_attempts (user_id, class_id, session_id, package_id, started_at, deadline_at, total_questions, submitted)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $ins->execute([$userId, $classId, $sessionId, $packageId, $now, $deadlineAt, $totalQuestions]);
            $attemptId = (int)$pdo->lastInsertId();

            $stmtItem = $pdo->prepare("
                INSERT INTO tutorial_rpl_cbt_items (attempt_id, question_id, position, category, question_text, options_json, correct_answer, answer, flagged)
                VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 0)
            ");
            foreach ($questions as $idx => $q) {
                $stmtItem->execute([
                    $attemptId,
                    (int)$q['id'],
                    $idx + 1,
                    $q['category'] ?? 'Umum',
                    $q['question_text'],
                    $q['options_json'],
                    $q['correct_answer']
                ]);
            }

            $stmt = $pdo->prepare("SELECT * FROM tutorial_rpl_cbt_attempts WHERE id = ?");
            $stmt->execute([$attemptId]);
            $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $attemptId = (int)$attempt['id'];
            // Jika kolom deadline_at kosong, isi dari started_at + duration
            if (empty($attempt['deadline_at'])) {
                $deadlineAt = (int)$attempt['started_at'] + $durationSeconds;
                $pdo->prepare("UPDATE tutorial_rpl_cbt_attempts SET deadline_at = ? WHERE id = ?")->execute([$deadlineAt, $attemptId]);
                $attempt['deadline_at'] = $deadlineAt;
            }
            if (empty($attempt['session_id']) || empty($attempt['package_id'])) {
                $pdo->prepare("UPDATE tutorial_rpl_cbt_attempts SET session_id = ?, package_id = ? WHERE id = ?")->execute([$sessionId, $packageId, $attemptId]);
                $attempt['session_id'] = $sessionId;
                $attempt['package_id'] = $packageId;
            }

            // Pastikan item-item soal tersedia jika attempt dibuat sebelum migrasi
            $chkItems = $pdo->prepare("SELECT COUNT(*) FROM tutorial_rpl_cbt_items WHERE attempt_id = ?");
            $chkItems->execute([$attemptId]);
            if ((int)$chkItems->fetchColumn() === 0) {
                $questions = tutorialRplCbtGetQuestions($pdo, null, $packageId);
                $pkg = tutorialRplCbtGetPackage($pdo, $packageId);
                $targetCount = ($pkg && !empty($pkg['question_count'])) ? (int)$pkg['question_count'] : 25;
                if (count($questions) > $targetCount) {
                    $questions = array_slice($questions, 0, $targetCount);
                }

                $stmtItem = $pdo->prepare("
                    INSERT INTO tutorial_rpl_cbt_items (attempt_id, question_id, position, category, question_text, options_json, correct_answer, answer, flagged)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 0)
                ");
                foreach ($questions as $idx => $q) {
                    $stmtItem->execute([
                        $attemptId,
                        (int)$q['id'],
                        $idx + 1,
                        $q['category'] ?? 'Umum',
                        $q['question_text'],
                        $q['options_json'],
                        $q['correct_answer']
                    ]);
                }
            }
        }

        return $attempt;
    }
}

/**
 * Mengambil attempt berdasarkan ID.
 */
if (!function_exists('tutorialRplCbtGetAttempt')) {
    function tutorialRplCbtGetAttempt(PDO $pdo, int $attemptId): ?array {
        ensureTutorialRplCbtTables($pdo);
        $stmt = $pdo->prepare("
            SELECT a.*, u.nama_lengkap, u.nim, u.program_studi, c.nama_kelompok, c.dosen_nama
            FROM tutorial_rpl_cbt_attempts a
            JOIN users u ON a.user_id = u.id
            JOIN tutorial_rpl_classes c ON a.class_id = c.id
            WHERE a.id = ?
        ");
        $stmt->execute([$attemptId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

/**
 * Mengambil attempt mahasiswa berdasarkan user_id dan class_id.
 */
if (!function_exists('tutorialRplCbtGetStudentAttempt')) {
    function tutorialRplCbtGetStudentAttempt(PDO $pdo, int $userId, int $classId): ?array {
        ensureTutorialRplCbtTables($pdo);
        $stmt = $pdo->prepare("SELECT * FROM tutorial_rpl_cbt_attempts WHERE user_id = ? AND class_id = ?");
        $stmt->execute([$userId, $classId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

/**
 * Mengambil lembar butir soal pada suatu attempt.
 */
if (!function_exists('tutorialRplCbtGetAttemptItems')) {
    function tutorialRplCbtGetAttemptItems(PDO $pdo, int $attemptId): array {
        ensureTutorialRplCbtTables($pdo);
        $stmt = $pdo->prepare("SELECT * FROM tutorial_rpl_cbt_items WHERE attempt_id = ? ORDER BY position ASC");
        $stmt->execute([$attemptId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Simpan jawaban pilihan mahasiswa per butir soal (auto-save).
 */
if (!function_exists('tutorialRplCbtSaveAnswer')) {
    function tutorialRplCbtSaveAnswer(PDO $pdo, int $attemptId, int $position, string $answer): bool {
        ensureTutorialRplCbtTables($pdo);
        $answer = strtoupper(trim($answer));
        if (!in_array($answer, ['A', 'B', 'C', 'D', 'E', ''], true)) {
            return false;
        }
        $val = ($answer === '') ? null : $answer;
        $now = time();
        $stmt = $pdo->prepare("UPDATE tutorial_rpl_cbt_items SET answer = ?, updated_at = ? WHERE attempt_id = ? AND position = ?");
        return $stmt->execute([$val, $now, $attemptId, $position]);
    }
}

/**
 * Simpan status flag ragu-ragu per butir soal.
 */
if (!function_exists('tutorialRplCbtSaveFlag')) {
    function tutorialRplCbtSaveFlag(PDO $pdo, int $attemptId, int $position, int $flagged): bool {
        ensureTutorialRplCbtTables($pdo);
        $flag = ($flagged === 1) ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE tutorial_rpl_cbt_items SET flagged = ? WHERE attempt_id = ? AND position = ?");
        return $stmt->execute([$flag, $attemptId, $position]);
    }
}

/**
 * Selesaikan attempt ujian, kalkulasi skor, dan sinkronisasi ke nilai Tutorial RPL.
 */
if (!function_exists('tutorialRplCbtFinishAttempt')) {
    function tutorialRplCbtFinishAttempt(PDO $pdo, int $attemptId): array {
        ensureTutorialRplCbtTables($pdo);
        $stmt = $pdo->prepare("SELECT * FROM tutorial_rpl_cbt_attempts WHERE id = ?");
        $stmt->execute([$attemptId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            throw new RuntimeException("Attempt tidak ditemukan.");
        }

        $items = tutorialRplCbtGetAttemptItems($pdo, $attemptId);
        $totalQuestions = count($items);
        $correctCount = 0;
        foreach ($items as $item) {
            $chosen = strtoupper(trim((string)($item['answer'] ?? '')));
            $correct = strtoupper(trim((string)($item['correct_answer'] ?? '')));
            if ($chosen !== '' && $chosen === $correct) {
                $correctCount++;
            }
        }

        $score = ($totalQuestions > 0) ? round(($correctCount / $totalQuestions) * 100, 2) : 0.00;
        $now = time();

        $upd = $pdo->prepare("
            UPDATE tutorial_rpl_cbt_attempts SET
                submitted_at = ?,
                correct_count = ?,
                total_questions = ?,
                score = ?,
                submitted = 1
            WHERE id = ?
        ");
        $upd->execute([$now, $correctCount, $totalQuestions, $score, $attemptId]);

        // Sinkronisasi ke tutorial_rpl_members
        $userId = (int)$attempt['user_id'];
        $classId = (int)$attempt['class_id'];
        $session = tutorialRplCbtGetSession($pdo);
        $passingGrade = (float)($session['passing_grade'] ?? 70.00);

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
                $statusLulus = ($nilaiAkhir >= $passingGrade) ? 'lulus' : 'tidak_lulus';
            } else {
                $nilaiAkhir = $score;
                $statusLulus = ($score >= $passingGrade) ? 'lulus' : 'tidak_lulus';
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

        return [
            'attempt_id' => $attemptId,
            'score' => $score,
            'correct_count' => $correctCount,
            'total_questions' => $totalQuestions,
            'submitted_at' => $now
        ];
    }
}

/**
 * Mengambil satu butir soal berdasarkan ID.
 */
if (!function_exists('tutorialRplCbtGetQuestion')) {
    function tutorialRplCbtGetQuestion(PDO $pdo, int $id): ?array {
        ensureTutorialRplCbtTables($pdo);
        $stmt = $pdo->prepare("SELECT * FROM tutorial_rpl_cbt_questions WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

/**
 * Impor butir soal dari data array Excel XLSX ke paket soal yang ditentukan.
 */
if (!function_exists('tutorialRplCbtImportQuestions')) {
    function tutorialRplCbtImportQuestions(PDO $pdo, array $rows, bool $updateExisting = true, int $packageId = 1): array {
        ensureTutorialRplCbtTables($pdo);
        $packageId = max(1, $packageId);
        $added = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        // Lewati baris header jika ada
        if (!empty($rows)) {
            $first = $rows[0];
            if (isset($first[0]) && stripos((string)$first[0], 'Kode') !== false) {
                array_shift($rows);
            }
        }

        $stmtCheck = $pdo->prepare("SELECT id FROM tutorial_rpl_cbt_questions WHERE code = ?");
        $stmtInsert = $pdo->prepare("
            INSERT INTO tutorial_rpl_cbt_questions (code, package_id, category, question_text, options_json, correct_answer, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $stmtUpdate = $pdo->prepare("
            UPDATE tutorial_rpl_cbt_questions SET
                package_id = ?, category = ?, question_text = ?, options_json = ?, correct_answer = ?, is_active = 1
            WHERE id = ?
        ");

        foreach ($rows as $lineIdx => $row) {
            $lineNum = $lineIdx + 2; // perkiraan nomor baris Excel
            $code = trim((string)($row[0] ?? ''));
            $category = trim((string)($row[1] ?? 'Umum'));
            if ($category === '') $category = 'Umum';
            $questionText = trim((string)($row[2] ?? ''));
            $optA = trim((string)($row[3] ?? ''));
            $optB = trim((string)($row[4] ?? ''));
            $optC = trim((string)($row[5] ?? ''));
            $optD = trim((string)($row[6] ?? ''));
            $optE = trim((string)($row[7] ?? ''));
            $correct = strtoupper(trim((string)($row[8] ?? 'A')));

            if ($questionText === '' && $optA === '' && $optB === '') {
                continue; // baris kosong
            }

            if ($questionText === '') {
                $errors[] = "Baris $lineNum: Teks pertanyaan tidak boleh kosong.";
                continue;
            }
            if ($optA === '' || $optB === '') {
                $errors[] = "Baris $lineNum: Minimal Pilihan A dan B wajib diisi.";
                continue;
            }
            if (!in_array($correct, ['A', 'B', 'C', 'D', 'E'], true)) {
                $errors[] = "Baris $lineNum: Kunci jawaban '$correct' tidak valid (harus A, B, C, D, atau E).";
                continue;
            }

            $options = [$optA, $optB, $optC, $optD, $optE];
            $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE);

            if ($code === '') {
                $code = 'TUTOR-RPL-' . strtoupper(bin2hex(random_bytes(3)));
            }

            $stmtCheck->execute([$code]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                if ($updateExisting) {
                    $stmtUpdate->execute([$packageId, $category, $questionText, $optionsJson, $correct, (int)$existingId]);
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                $stmtInsert->execute([$code, $packageId, $category, $questionText, $optionsJson, $correct]);
                $added++;
            }
        }

        return [
            'added' => $added,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }
}

/**
 * Format data butir soal untuk ekspor Excel XLSX.
 */
if (!function_exists('tutorialRplCbtExportQuestions')) {
    function tutorialRplCbtExportQuestions(PDO $pdo, ?int $packageId = null): array {
        ensureTutorialRplCbtTables($pdo);
        $questions = tutorialRplCbtGetQuestions($pdo, null, $packageId);
        $rows = [];
        $rows[] = ['Kode Soal', 'Paket', 'Kategori', 'Pertanyaan', 'Pilihan A', 'Pilihan B', 'Pilihan C', 'Pilihan D', 'Pilihan E', 'Kunci', 'Status'];

        foreach ($questions as $q) {
            $opts = json_decode((string)$q['options_json'], true);
            if (!is_array($opts)) $opts = [];
            $rows[] = [
                $q['code'],
                $q['package_name'] ?? ($q['package_id'] == 2 ? 'Paket 2 - Kristen Protestan' : 'Paket 1 - Utama (Muslim)'),
                $q['category'],
                $q['question_text'],
                $opts[0] ?? ($opts['A'] ?? ''),
                $opts[1] ?? ($opts['B'] ?? ''),
                $opts[2] ?? ($opts['C'] ?? ''),
                $opts[3] ?? ($opts['D'] ?? ''),
                $opts[4] ?? ($opts['E'] ?? ''),
                $q['correct_answer'],
                !empty($q['is_active']) ? 'Aktif' : 'Nonaktif'
            ];
        }
        return $rows;
    }
}

/**
 * Format data hasil pengerjaan CBT Tutorial RPL untuk ekspor Excel.
 */
if (!function_exists('tutorialRplCbtExportResults')) {
    function tutorialRplCbtExportResults(PDO $pdo, ?int $classId = null): array {
        ensureTutorialRplCbtTables($pdo);
        $attempts = tutorialRplCbtGetAttempts($pdo, $classId);
        $rows = [];
        $rows[] = ['NIM', 'Nama Mahasiswa', 'Program Studi', 'Kelompok RPL', 'Tutor', 'Sesi Ujian', 'Paket Soal', 'Mulai (WIB)', 'Selesai (WIB)', 'Status', 'Jawaban Benar', 'Jumlah Soal', 'Nilai CBT', 'Status Kelulusan'];

        $session = tutorialRplCbtGetSession($pdo);
        $passingGrade = (float)($session['passing_grade'] ?? 70.00);

        foreach ($attempts as $a) {
            $startedWib = (new DateTimeImmutable('@' . $a['started_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i');
            $submittedWib = !empty($a['submitted_at']) ? (new DateTimeImmutable('@' . $a['submitted_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i') : '-';
            $isSubmitted = !empty($a['submitted']);
            $score = ($a['score'] !== null) ? (float)$a['score'] : 0.0;
            $statusLulus = ($score >= $passingGrade) ? 'LULUS' : 'BELUM MEMENUHI STANDAR';

            $rows[] = [
                $a['nim'] ?? '-',
                $a['nama_lengkap'] ?? '-',
                $a['program_studi'] ?? '-',
                $a['nama_kelompok'] ?? '-',
                $a['dosen_nama'] ?? '-',
                $a['session_title'] ?? ($a['session_id'] == 2 ? 'Sesi Kristen Protestan' : 'Sesi Utama (Muslim)'),
                $a['package_name'] ?? ($a['package_id'] == 2 ? 'Paket 2 (Kristen)' : 'Paket 1 (Muslim)'),
                $startedWib,
                $submittedWib,
                $isSubmitted ? 'Selesai' : 'Sedang Mengerjakan',
                $a['correct_count'] !== null ? (int)$a['correct_count'] : '-',
                (int)($a['total_questions'] ?? 25),
                $score,
                $isSubmitted ? $statusLulus : 'Proses'
            ];
        }
        return $rows;
    }
}
