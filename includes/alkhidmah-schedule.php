<?php
/**
 * LPPAI Corner - Jadwal Kegiatan Majlis Dzikir Al Khidmah (Masjid KH. Soefyan Abdul Wahab).
 *
 * Peserta  : mahasiswa peserta tutorial aktif (tutorialActiveParticipants()).
 * Sesi     : 3 kegiatan — Jumat pertama bulan Desember, Januari, Februari (tanggal bisa diubah admin).
 * Aturan   : setiap mahasiswa WAJIB mengikuti SEKALI sesuai jadwalnya; peserta dibagi ke 3 sesi dengan
 *            kuota (bawaan = total / 3 dibulatkan ke atas) dan dikelompokkan bersama teman se-prodi.
 * Tabel    : alkhidmah_jadwal_sesi, alkhidmah_jadwal_peserta (satu set jadwal aktif).
 * Dipakai  : admin/tutorial-pengaturan-generate.php (generate) dan pages/dashboard.php (jadwal mahasiswa).
 */
require_once __DIR__ . '/tutorial-membership.php';

const ALKHIDMAH_LOKASI = 'Masjid KH. Soefyan Abdul Wahab';
const ALKHIDMAH_KEGIATAN = 'Majlis Dzikir Al Khidmah';
const ALKHIDMAH_BULAN_SESI = [12, 1, 2]; // Desember, Januari, Februari

function ensureAlkhidmahScheduleTables(PDO $pdo): void {
    static $ready = [];
    $id = spl_object_id($pdo);
    if (isset($ready[$id])) return;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS alkhidmah_jadwal_sesi (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sesi_ke INTEGER NOT NULL,
            tanggal DATE NOT NULL,
            waktu VARCHAR(60) DEFAULT NULL,
            lokasi VARCHAR(150) NOT NULL,
            kuota INTEGER NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS alkhidmah_jadwal_peserta (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sesi_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL UNIQUE,
            program_studi VARCHAR(150) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS alkhidmah_jadwal_sesi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sesi_ke INT NOT NULL,
            tanggal DATE NOT NULL,
            waktu VARCHAR(60) DEFAULT NULL,
            lokasi VARCHAR(150) NOT NULL,
            kuota INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS alkhidmah_jadwal_peserta (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sesi_id INT NOT NULL,
            user_id INT NOT NULL,
            program_studi VARCHAR(150) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_alkhidmah_user (user_id),
            KEY idx_alkhidmah_sesi (sesi_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $ready[$id] = true;
}

/** Jumat pertama pada bulan & tahun tertentu (Y-m-d). */
function alkhidmahFirstFriday(int $year, int $month): string {
    $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('Asia/Jakarta'));
    $dow = (int)$d->format('N'); // 1 = Senin ... 5 = Jumat
    return $d->modify('+' . ((5 - $dow + 7) % 7) . ' days')->format('Y-m-d');
}

/**
 * Tanggal bawaan 3 sesi: Jumat pertama Desember, Januari, Februari yang terdekat.
 * Maret–Desember → Desember tahun ini; Januari–Februari → Desember tahun lalu (siklus berjalan).
 */
function alkhidmahDefaultDates(?DateTimeImmutable $today = null): array {
    $today ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
    $y = (int)$today->format('Y');
    $decYear = (int)$today->format('n') <= 2 ? $y - 1 : $y;
    return [alkhidmahFirstFriday($decYear, 12), alkhidmahFirstFriday($decYear + 1, 1), alkhidmahFirstFriday($decYear + 1, 2)];
}

function alkhidmahFormatDate(string $ymd, bool $withDay = true): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('Asia/Jakarta'));
    if (!$d) return $ymd;
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return ($withDay ? $days[(int)$d->format('w')] . ', ' : '') . $d->format('j') . ' ' . $months[(int)$d->format('n')] . ' ' . $d->format('Y');
}

/**
 * Bagi peserta ke sesi dengan kuota, mengelompokkan teman se-prodi (best-fit decreasing).
 * - Prodi diproses dari yang terbesar. Satu prodi masuk UTUH ke sesi yang sudah berisi prodi yang sama
 *   (bila muat), selain itu ke sesi dengan sisa kapasitas TERKECIL yang masih muat (best fit) agar
 *   prodi lain tetap punya ruang utuh.
 * - Prodi yang tidak muat utuh dipecah seminimal mungkin (sesi berisi prodi sama, lalu sisa terbanyak).
 * - 'soft' (opsional) = target kapasitas per sesi (mis. ceil(total/3)) agar sebaran tetap rata walau kuota
 *   dinaikkan; peserta yang tidak muat pada target dimasukkan pada putaran kedua sampai batas 'remaining'.
 * - Sisa yang tidak kebagian kuota → 'unassigned'.
 *
 * @param array $participants [['id','nama_lengkap','program_studi',...], ...]
 * @param array $sessions     [key => ['remaining' => int, 'prodi' => [nama => true], 'soft' => ?int], ...]
 * @return array{assign: array<string|int, array>, unassigned: array}
 */
function alkhidmahPlan(array $participants, array $sessions): array {
    $groups = [];
    foreach ($participants as $p) {
        $prodi = trim((string)($p['program_studi'] ?? '')) ?: '(Prodi belum diisi)';
        $groups[$prodi][] = $p;
    }
    foreach ($groups as &$g) usort($g, static fn($a, $b) => strcasecmp((string)$a['nama_lengkap'], (string)$b['nama_lengkap']));
    unset($g);
    uksort($groups, static fn($a, $b) => (count($groups[$b]) <=> count($groups[$a])) ?: strcasecmp((string)$a, (string)$b));

    $order = array_keys($sessions);
    $assign = array_fill_keys($order, []);
    $used = array_fill_keys($order, 0);
    $cap = static fn(array $s, int $used, bool $soft): int => max(0, ($soft && isset($s['soft']) ? min((int)$s['soft'], (int)$s['remaining']) : (int)$s['remaining']) - $used);
    $place = static function (string $prodi, array $members, bool $soft) use (&$sessions, &$assign, &$used, $order, $cap): array {
        $n = count($members);
        // Utuh: sesi se-prodi lebih dulu, lalu best fit (sisa terkecil yang muat), seri → urutan sesi
        $best = null;
        foreach ($order as $i => $k) {
            $c = $cap($sessions[$k], $used[$k], $soft);
            if ($c < $n) continue;
            $key = [isset($sessions[$k]['prodi'][$prodi]) ? 0 : 1, $c, $i];
            if ($best === null || $key < $best[1]) $best = [$k, $key];
        }
        $targets = $best !== null ? [$best[0]] : $order;
        if ($best === null) {
            usort($targets, static function ($a, $b) use ($sessions, $used, $prodi, $cap, $soft) {
                $pa = isset($sessions[$a]['prodi'][$prodi]) ? 0 : 1;
                $pb = isset($sessions[$b]['prodi'][$prodi]) ? 0 : 1;
                return ($pa <=> $pb) ?: ($cap($sessions[$b], $used[$b], $soft) <=> $cap($sessions[$a], $used[$a], $soft));
            });
        }
        foreach ($targets as $k) {
            while ($members && $cap($sessions[$k], $used[$k], $soft) > 0) {
                $assign[$k][] = array_shift($members) + ['_prodi' => $prodi];
                $used[$k]++;
                $sessions[$k]['prodi'][$prodi] = true;
            }
            if (!$members) break;
        }
        return $members; // sisa yang belum dapat tempat
    };
    $left = [];
    foreach ($groups as $prodi => $members) {
        $rest = $place((string)$prodi, $members, true);
        if ($rest) $left[(string)$prodi] = $rest;
    }
    $unassigned = [];
    foreach ($left as $prodi => $members) { // putaran 2: sampai batas kuota sebenarnya
        foreach ($place($prodi, $members, false) as $m) $unassigned[] = $m + ['_prodi' => $prodi];
    }
    return ['assign' => $assign, 'unassigned' => $unassigned];
}

/** Validasi 3 tanggal (Y-m-d, unik). @return string[] terurut */
function alkhidmahValidateDates(array $dates): array {
    $clean = [];
    foreach ($dates as $d) {
        $d = trim((string)$d);
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        if (!$dt || $dt->format('Y-m-d') !== $d) throw new InvalidArgumentException('Tanggal kegiatan tidak valid: ' . ($d === '' ? '(kosong)' : $d) . '.');
        $clean[] = $d;
    }
    if (count($clean) !== 3) throw new InvalidArgumentException('Isi tepat 3 tanggal kegiatan.');
    if (count(array_unique($clean)) !== 3) throw new InvalidArgumentException('Ketiga tanggal kegiatan harus berbeda.');
    sort($clean);
    return $clean;
}

/** Jadwal aktif: sesi (urut tanggal) + jumlah peserta & sebaran prodi. */
function alkhidmahCurrentSchedule(PDO $pdo, bool $withMembers = false): array {
    ensureAlkhidmahScheduleTables($pdo);
    $sessions = $pdo->query('SELECT * FROM alkhidmah_jadwal_sesi ORDER BY tanggal ASC, sesi_ke ASC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sessions as &$s) {
        $st = $pdo->prepare('SELECT COALESCE(NULLIF(p.program_studi, \'\'), \'(Prodi belum diisi)\') AS prodi, COUNT(*) AS n
            FROM alkhidmah_jadwal_peserta p WHERE p.sesi_id = ? GROUP BY prodi ORDER BY n DESC, prodi ASC');
        $st->execute([(int)$s['id']]);
        $s['prodi_counts'] = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        $s['jumlah'] = array_sum($s['prodi_counts']);
        if ($withMembers) {
            $st = $pdo->prepare('SELECT u.id, u.nama_lengkap, u.nim, p.program_studi FROM alkhidmah_jadwal_peserta p
                JOIN users u ON u.id = p.user_id WHERE p.sesi_id = ? ORDER BY p.program_studi ASC, u.nama_lengkap ASC');
            $st->execute([(int)$s['id']]);
            $s['members'] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    unset($s);
    return $sessions;
}

/**
 * Generate jadwal.
 * mode 'rebuild' : hapus jadwal lama, susun ulang seluruh peserta tutorial aktif ke 3 tanggal.
 * mode 'add'     : pertahankan jadwal & tanggal lama; hanya peserta aktif yang belum terjadwal ditambahkan
 *                  (sesuai sisa kuota, mengutamakan sesi yang sudah berisi prodi yang sama).
 * $quota <= 0 → bawaan ceil(total peserta aktif / 3).
 * @return array{total:int, scheduled:int, quota:int, unassigned:array, sessions:array, mode:string}
 */
function alkhidmahGenerate(PDO $pdo, string $mode, array $dates, int $quota = 0, string $waktu = '', ?array $participants = null): array {
    ensureAlkhidmahScheduleTables($pdo);
    $participants ??= tutorialActiveParticipants($pdo);
    $total = count($participants);
    if ($total === 0) throw new InvalidArgumentException('Belum ada mahasiswa peserta tutorial aktif untuk dijadwalkan.');
    $waktu = mb_substr(trim($waktu), 0, 60);
    $tx = !$pdo->inTransaction();
    if ($tx) $pdo->beginTransaction();
    try {
        $existing = $pdo->query('SELECT * FROM alkhidmah_jadwal_sesi ORDER BY tanggal ASC, sesi_ke ASC')->fetchAll(PDO::FETCH_ASSOC);
        if ($mode === 'add' && $existing) {
            if ($quota > 0) {
                $pdo->prepare('UPDATE alkhidmah_jadwal_sesi SET kuota = ?')->execute([$quota]);
                foreach ($existing as &$e) $e['kuota'] = $quota;
                unset($e);
            }
            $quota = (int)max(array_column($existing, 'kuota'));
            $scheduled = array_map('intval', $pdo->query('SELECT user_id FROM alkhidmah_jadwal_peserta')->fetchAll(PDO::FETCH_COLUMN));
            $scheduledSet = array_flip($scheduled);
            $todo = array_values(array_filter($participants, static fn($p) => !isset($scheduledSet[(int)$p['id']])));
            $sessions = [];
            foreach ($existing as $e) {
                $st = $pdo->prepare('SELECT COALESCE(program_studi, \'\') FROM alkhidmah_jadwal_peserta WHERE sesi_id = ?');
                $st->execute([(int)$e['id']]);
                $prodis = $st->fetchAll(PDO::FETCH_COLUMN);
                $prodiSet = [];
                foreach ($prodis as $pr) $prodiSet[trim((string)$pr) ?: '(Prodi belum diisi)'] = true;
                $sessions[(int)$e['id']] = ['remaining' => max(0, (int)$e['kuota'] - count($prodis)), 'prodi' => $prodiSet];
            }
        } else {
            $mode = 'rebuild';
            $dates = alkhidmahValidateDates($dates);
            if ($quota <= 0) $quota = (int)ceil($total / 3);
            $pdo->exec('DELETE FROM alkhidmah_jadwal_peserta');
            $pdo->exec('DELETE FROM alkhidmah_jadwal_sesi');
            $ins = $pdo->prepare('INSERT INTO alkhidmah_jadwal_sesi (sesi_ke, tanggal, waktu, lokasi, kuota) VALUES (?, ?, ?, ?, ?)');
            $sessions = [];
            foreach ($dates as $i => $d) {
                $ins->execute([$i + 1, $d, $waktu !== '' ? $waktu : null, ALKHIDMAH_LOKASI, $quota]);
                $sessions[(int)$pdo->lastInsertId()] = ['remaining' => $quota, 'prodi' => [], 'soft' => (int)ceil($total / 3)];
            }
            $todo = $participants;
        }
        $plan = alkhidmahPlan($todo, $sessions);
        $insP = $pdo->prepare('INSERT INTO alkhidmah_jadwal_peserta (sesi_id, user_id, program_studi) VALUES (?, ?, ?)');
        $added = 0;
        foreach ($plan['assign'] as $sesiId => $members) {
            foreach ($members as $m) {
                $insP->execute([(int)$sesiId, (int)$m['id'], trim((string)($m['program_studi'] ?? '')) ?: null]);
                $added++;
            }
        }
        if ($tx) $pdo->commit();
    } catch (Throwable $e) {
        if ($tx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['total' => $total, 'scheduled' => $added, 'quota' => $quota, 'unassigned' => $plan['unassigned'],
        'sessions' => alkhidmahCurrentSchedule($pdo), 'mode' => $mode];
}

/** Jadwal Majlis milik seorang mahasiswa, atau null. */
function alkhidmahScheduleForUser(PDO $pdo, int $userId): ?array {
    if ($userId <= 0) return null;
    try {
        $st = $pdo->prepare('SELECT s.* FROM alkhidmah_jadwal_peserta p JOIN alkhidmah_jadwal_sesi s ON s.id = p.sesi_id WHERE p.user_id = ?');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null; // tabel belum dibuat (jadwal belum pernah digenerate)
    }
}
