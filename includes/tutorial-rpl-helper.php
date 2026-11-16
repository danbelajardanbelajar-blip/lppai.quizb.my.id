<?php
/**
 * LPPAI Corner - Helper & Skema Basis Data Tutorial RPL Online
 *
 * Mengelola struktur data khusus Tutorial RPL (kelas/kelompok online, plotting anggota,
 * verifikasi tugas Google Drive, pengerjaan ujian tulis CBT, serta perizinan tutor).
 */

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
                   u.nama_lengkap, u.nim, u.whatsapp, u.program_studi, u.fakultas, u.jenis_program
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
    function tutorialRplGetTutors(PDO $pdo): array {
        try {
            $stmt = $pdo->query("SELECT id, nim, nama_lengkap, whatsapp FROM users WHERE role = 'dosen' ORDER BY nama_lengkap ASC");
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
 * Mengambil data mahasiswa program RPL lengkap dengan status plotting dan hasil CBT Pretes.
 */
if (!function_exists('tutorialRplGetStudentsWithPretes')) {
    function tutorialRplGetStudentsWithPretes(PDO $pdo, array $filters = []): array {
        ensureTutorialRplTables($pdo);

        $sql = "
            SELECT u.id, u.nama_lengkap, u.nim, u.program_studi, u.fakultas, u.whatsapp, u.jenis_program,
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

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
