<?php
/**
 * LPPAI Corner - Identitas Dosen Penguji Pretes berbasis NIM (bukan nama)
 *
 * Penugasan penguji di pretes_registrations kini menyimpan kolom `dosen_penguji_nim`
 * (NIM/NIDN akun dosen di users.nim) di samping `dosen_penguji` (nama, untuk tampilan).
 * Semua pengecekan akses dosen memakai NIM, sehingga perbedaan penulisan nama/gelar
 * tidak lagi membuat penguji kehilangan pesertanya.
 *
 *  - pretesExaminerEnsure($pdo)            : kolom + indeks + isi NIM untuk baris yang belum terpetakan
 *  - pretesExaminerNimByName($pdo, $nama)  : nama penguji → NIM akun dosen (persis, lalu ternormalisasi)
 *  - pretesExaminerResync($pdo)            : hitung ulang NIM untuk semua penugasan (setelah plotting/edit)
 *  - pretesExaminerUserNim($user)          : NIM akun dosen yang login
 *  - pretesExaminerOwns($pdo, $user, $row) : apakah penugasan ini milik dosen (via NIM)
 *  - pretesExaminerIsScheduled($pdo, $user): dosen terjadwal menguji pada periode pretes aktif
 */

/** Normalisasi nama untuk pencocokan toleran: huruf kecil, tanpa tanda baca, spasi tunggal. */
function pretesExaminerNormName(?string $name): string {
    $s = mb_strtolower(trim((string)$name), 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/** Peta dosen: ['exact' => [nama_trim => nim], 'norm' => [nama_norm => nim|false(ganda)]]. */
function pretesExaminerDirectory(PDO $pdo, bool $refresh = false): array {
    static $cache = [];
    $key = spl_object_id($pdo);
    if (!$refresh && isset($cache[$key])) return $cache[$key];
    $dir = ['exact' => [], 'norm' => []];
    try {
        $rows = $pdo->query("SELECT nama_lengkap, nim FROM users WHERE role = 'dosen' AND nim IS NOT NULL AND TRIM(nim) <> ''")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }
    foreach ($rows as $r) {
        $nim = trim((string)$r['nim']);
        $exact = trim((string)$r['nama_lengkap']);
        $norm = pretesExaminerNormName($exact);
        if ($exact !== '') $dir['exact'][$exact] = isset($dir['exact'][$exact]) && $dir['exact'][$exact] !== $nim ? false : $nim;
        if ($norm !== '') $dir['norm'][$norm] = isset($dir['norm'][$norm]) && $dir['norm'][$norm] !== $nim ? false : $nim;
    }
    return $cache[$key] = $dir;
}

/** Nama penguji → NIM dosen; null bila tidak ditemukan atau ambigu (dua dosen bernama sama). */
function pretesExaminerNimByName(PDO $pdo, ?string $name): ?string {
    $name = trim((string)$name);
    if ($name === '') return null;
    $dir = pretesExaminerDirectory($pdo);
    if (array_key_exists($name, $dir['exact'])) return $dir['exact'][$name] ?: null;
    $norm = pretesExaminerNormName($name);
    if ($norm !== '' && array_key_exists($norm, $dir['norm'])) return $dir['norm'][$norm] ?: null;
    return null;
}

/** NIM akun dosen yang login ('' bila kosong). */
function pretesExaminerUserNim(?array $user): string {
    return trim((string)($user['nim'] ?? ''));
}

/** Pastikan kolom dosen_penguji_nim ada, lalu petakan baris yang belum memiliki NIM. */
function pretesExaminerEnsure(PDO $pdo): void {
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) return;
    $done[$key] = true;
    try {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $has = false;
        if ($sqlite) {
            foreach ($pdo->query('PRAGMA table_info(pretes_registrations)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (strtolower((string)$c['name']) === 'dosen_penguji_nim') { $has = true; break; }
            }
        } else {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM pretes_registrations LIKE 'dosen_penguji_nim'")->fetch();
        }
        if (!$has) {
            $pdo->exec('ALTER TABLE pretes_registrations ADD COLUMN dosen_penguji_nim VARCHAR(50) DEFAULT NULL');
            try { $pdo->exec('CREATE INDEX idx_pretes_reg_penguji_nim ON pretes_registrations (dosen_penguji_nim)'); } catch (Throwable $e) {}
        }
        // Backfill bertahap: hanya nama yang belum punya NIM
        $names = $pdo->query("SELECT DISTINCT TRIM(dosen_penguji) FROM pretes_registrations
            WHERE dosen_penguji IS NOT NULL AND TRIM(dosen_penguji) <> '' AND (dosen_penguji_nim IS NULL OR dosen_penguji_nim = '')")->fetchAll(PDO::FETCH_COLUMN);
        if ($names) {
            $upd = $pdo->prepare("UPDATE pretes_registrations SET dosen_penguji_nim = ? WHERE TRIM(dosen_penguji) = ? AND (dosen_penguji_nim IS NULL OR dosen_penguji_nim = '')");
            foreach ($names as $name) {
                $nim = pretesExaminerNimByName($pdo, (string)$name);
                if ($nim !== null) $upd->execute([$nim, $name]);
            }
        }
    } catch (Throwable $e) {
        error_log('pretesExaminerEnsure: ' . $e->getMessage());
    }
}

/**
 * Hitung ulang NIM penguji untuk penugasan (dipanggil setelah plotting otomatis, edit penugasan,
 * sinkron sesi, atau perubahan data tutor). $periode null = semua periode.
 */
function pretesExaminerResync(PDO $pdo, ?string $periode = null): int {
    pretesExaminerEnsure($pdo);
    pretesExaminerDirectory($pdo, true);
    $where = $periode !== null ? ' AND periode = ?' : '';
    $params = $periode !== null ? [$periode] : [];
    $stmt = $pdo->prepare("SELECT DISTINCT TRIM(dosen_penguji) FROM pretes_registrations WHERE dosen_penguji IS NOT NULL AND TRIM(dosen_penguji) <> ''$where");
    $stmt->execute($params);
    $changed = 0;
    $upd = $pdo->prepare("UPDATE pretes_registrations SET dosen_penguji_nim = ? WHERE TRIM(dosen_penguji) = ?$where");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $nim = pretesExaminerNimByName($pdo, (string)$name);
        // Nama yang tidak (lagi) terpetakan tidak mengosongkan NIM yang sudah tersimpan
        if ($nim === null) continue;
        $upd->execute(array_merge([$nim, $name], $params));
        $changed += $upd->rowCount();
    }
    $pdo->prepare("UPDATE pretes_registrations SET dosen_penguji_nim = NULL WHERE (dosen_penguji IS NULL OR TRIM(dosen_penguji) = '')$where")->execute($params);
    return $changed;
}

/** Apakah penugasan ($row berisi dosen_penguji_nim dan/atau dosen_penguji) milik dosen ini (via NIM). */
function pretesExaminerOwns(PDO $pdo, array $user, array $row): bool {
    $nim = pretesExaminerUserNim($user);
    if ($nim === '') return false;
    $rowNim = trim((string)($row['dosen_penguji_nim'] ?? ''));
    if ($rowNim === '') $rowNim = (string)pretesExaminerNimByName($pdo, $row['dosen_penguji'] ?? '');
    return $rowNim !== '' && hash_equals($rowNim, $nim);
}

/** Nama-nama penguji yang dijadwalkan pada sebuah periode (master_pretes.penguji_senin..jumat). */
function pretesExaminerPeriodNames(array $period): array {
    $names = [];
    foreach (['senin', 'selasa', 'rabu', 'kamis', 'jumat'] as $d) {
        foreach (explode('|||', (string)($period['penguji_' . $d] ?? '')) as $n) {
            $n = trim($n);
            if ($n !== '') $names[$n] = true;
        }
    }
    return array_keys($names);
}

/**
 * Dosen terjadwal sebagai penguji pada periode pretes AKTIF: punya peserta terplot (via NIM)
 * atau tercantum di daftar penguji harian periode aktif (nama → NIM).
 */
function pretesExaminerIsScheduled(PDO $pdo, ?array $user): bool {
    if (!$user || strtolower((string)($user['role'] ?? '')) !== 'dosen') return false;
    $nim = pretesExaminerUserNim($user);
    if ($nim === '') return false;
    pretesExaminerEnsure($pdo);
    try {
        $periods = $pdo->query("SELECT * FROM master_pretes WHERE status = 'aktif'")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
    if (!$periods) return false;
    $stmt = $pdo->prepare('SELECT 1 FROM pretes_registrations WHERE periode = ? AND dosen_penguji_nim = ? LIMIT 1');
    foreach ($periods as $p) {
        $stmt->execute([$p['periode'], $nim]);
        if ($stmt->fetchColumn()) return true;
        foreach (pretesExaminerPeriodNames($p) as $name) {
            if (pretesExaminerNimByName($pdo, $name) === $nim) return true;
        }
    }
    return false;
}
