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
