<?php
/**
 * LPPAI Corner - Komponen/tugas pretes selain CBT, dapat dikelola admin per program (RPL / Reguler).
 *
 * Tabel pretes_components menyimpan komponen praktik/lisan (nomor 2 dst). Komponen 1 "Ujian tulis" (CBT)
 * selalu ada dan tidak disimpan di tabel. Komponen yang "dikurangi" dinonaktifkan (is_active = 0) agar
 * nilai mahasiswa yang sudah tersimpan tetap aman; hapus permanen hanya untuk komponen yang belum bernilai.
 *
 * Halaman admin: admin/pretes-tugas.php. Dipakai oleh pretesComponents() (includes/pretes-helper.php).
 */

const PRETES_COMPONENT_PROGRAMS = ['reguler' => 'Reguler', 'rpl' => 'RPL'];
const PRETES_COMPONENT_CATEGORIES = ['Praktik Ibadah & Thaharah', "Al-Qur'an & Doa"];
const PRETES_COMPONENT_FORMS = ['Lisan / Praktik', 'Praktik', 'Lisan', 'Hafalan', 'Tugas video'];

/** 11 komponen bawaan (sebelumnya ditulis langsung di pretesComponents()). */
function pretesDefaultComponentRows(): array {
    $ib = PRETES_COMPONENT_CATEGORIES[0];
    $qd = PRETES_COMPONENT_CATEGORIES[1];
    $yt = static fn(string $id) => 'https://www.youtube.com/watch?v=' . $id;
    return [
        ['comp_key' => 'praktik_wudlu', 'label' => 'Praktik wudlu', 'kategori' => $ib, 'video_url' => $yt('zvCIGrVTE-w'), 'video_title' => 'Praktik wudlu lengkap — NU Online'],
        ['comp_key' => 'doa_setelah_wudlu', 'label' => 'Doa setelah wudlu', 'kategori' => $ib],
        ['comp_key' => 'praktik_tayammum', 'label' => 'Praktik tayammum', 'kategori' => $ib, 'video_url' => $yt('I9T56eVHG0o'), 'video_title' => 'Praktik tayammum dan bacaannya — NU Online'],
        ['comp_key' => 'niat_mandi_besar', 'label' => 'Niat mandi besar', 'kategori' => $ib, 'video_url' => $yt('4XOfiHooQdw'), 'video_title' => 'Praktik mandi wajib / mandi besar — NU Online'],
        ['comp_key' => 'niat_jamak_qashar', 'label' => 'Niat jamak qashar', 'kategori' => $ib],
        ['comp_key' => 'bacaan_shalat_subuh', 'label' => 'Bacaan shalat Subuh', 'kategori' => $ib, 'video_url' => $yt('zFVO8q7qmwA'), 'video_title' => 'Praktik shalat Subuh — NU Online'],
        ['comp_key' => 'al_bayyinah', 'label' => 'Al Bayyinah', 'kategori' => $qd],
        ['comp_key' => 'al_alaq', 'label' => "Al 'Alaq", 'kategori' => $qd],
        ['comp_key' => 'al_dluha', 'label' => 'Al Dluha', 'kategori' => $qd],
        ['comp_key' => 'istighasah', 'label' => 'Istighasah', 'kategori' => $qd],
        ['comp_key' => 'shalat_jenazah', 'label' => 'Shalat jenazah', 'kategori' => $ib, 'video_url' => $yt('QiCMDLM941g'), 'video_title' => 'Praktik shalat jenazah laki-laki dan perempuan — NU Online'],
    ];
}

/** Bacaan bawaan (data/pretes-learning-readings.json & istighasah) tersedia untuk kunci ini. */
function pretesComponentHasBuiltinReading(string $key): bool {
    static $keys = null;
    if ($keys === null) {
        $keys = ['istighasah' => true];
        $file = __DIR__ . '/../data/pretes-learning-readings.json';
        if (is_file($file)) {
            $json = json_decode((string)file_get_contents($file), true);
            if (is_array($json)) foreach (array_keys($json) as $k) $keys[$k] = true;
        }
    }
    return isset($keys[$key]);
}

/** 'RPL' (atau mengandung RPL) → 'rpl'; selain itu 'reguler'. */
function pretesProgramKey($jenisProgram): string {
    return str_contains(strtoupper(trim((string)$jenisProgram)), 'RPL') ? 'rpl' : 'reguler';
}

/** PDO untuk komponen: diset eksplisit (test) atau koneksi aplikasi. */
function pretesComponentsPdo(?PDO $set = null, bool $clear = false): ?PDO {
    static $pdo = null;
    if ($clear) { $pdo = null; return null; }
    if ($set) { $pdo = $set; return $pdo; }
    if ($pdo) return $pdo;
    static $resolving = false;
    if ($resolving) return null; // cegah rekursi bila getDBConnection() sendiri memanggil pretesComponents()
    if (function_exists('getDBConnection')) {
        $resolving = true;
        try { $pdo = getDBConnection(); } catch (Throwable $e) { $pdo = null; } finally { $resolving = false; }
    }
    return $pdo instanceof PDO ? $pdo : null;
}

function pretesComponentsCache(?string $program = null, ?array $value = null, bool $reset = false) {
    static $cache = [];
    if ($reset) { $cache = []; return null; }
    if ($program === null) return null;
    if ($value !== null) { $cache[$program] = $value; return $value; }
    return $cache[$program] ?? null;
}

function ensurePretesComponentTable(PDO $pdo): void {
    static $ready = [];
    $id = spl_object_id($pdo);
    if (isset($ready[$id])) return;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_components (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            program VARCHAR(20) NOT NULL,
            comp_key VARCHAR(60) NOT NULL,
            label VARCHAR(150) NOT NULL,
            bentuk VARCHAR(60) NOT NULL DEFAULT 'Lisan / Praktik',
            kategori VARCHAR(80) NOT NULL DEFAULT 'Praktik Ibadah & Thaharah',
            video_url VARCHAR(500) DEFAULT NULL,
            video_title VARCHAR(200) DEFAULT NULL,
            reading_title VARCHAR(200) DEFAULT NULL,
            reading_text TEXT DEFAULT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (program, comp_key)
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_components (
            id INT AUTO_INCREMENT PRIMARY KEY,
            program VARCHAR(20) NOT NULL,
            comp_key VARCHAR(60) NOT NULL,
            label VARCHAR(150) NOT NULL,
            bentuk VARCHAR(60) NOT NULL DEFAULT 'Lisan / Praktik',
            kategori VARCHAR(80) NOT NULL DEFAULT 'Praktik Ibadah & Thaharah',
            video_url VARCHAR(500) DEFAULT NULL,
            video_title VARCHAR(200) DEFAULT NULL,
            reading_title VARCHAR(200) DEFAULT NULL,
            reading_text TEXT DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_pretes_component (program, comp_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // Seed: program yang belum punya baris diisi 11 komponen bawaan (tampilan & nilai tidak berubah)
    foreach (array_keys(PRETES_COMPONENT_PROGRAMS) as $program) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pretes_components WHERE program = ?');
        $stmt->execute([$program]);
        if ((int)$stmt->fetchColumn() > 0) continue;
        $ins = $pdo->prepare('INSERT INTO pretes_components (program, comp_key, label, bentuk, kategori, video_url, video_title, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
        foreach (pretesDefaultComponentRows() as $i => $row) {
            $ins->execute([$program, $row['comp_key'], $row['label'], 'Lisan / Praktik', $row['kategori'],
                $row['video_url'] ?? null, $row['video_title'] ?? null, ($i + 1) * 10]);
        }
    }
    $ready[$id] = true;
}

/* ------------------------------------------------------------------
 * Pengaturan pretes (key-value) & mode kelulusan RPL
 * ------------------------------------------------------------------ */
const PRETES_RPL_PASS_MODES = [
    'cbt_only' => 'Cukup CBT resmi ≥ 80 (langsung lulus)',
    'cbt_tasks' => 'CBT resmi ≥ 80 + tugas online & penilaian tutor',
];
const PRETES_RPL_PASS_MODE_DEFAULT = 'cbt_only';

function ensurePretesSettingsTable(PDO $pdo): void {
    static $ready = [];
    $id = spl_object_id($pdo);
    if (isset($ready[$id])) return;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_settings (
            setting_key VARCHAR(60) PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_settings (
            setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $ready[$id] = true;
}

/** Cache pengaturan per request; $reset untuk test/setelah simpan. */
function pretesSettingsCache(?string $key = null, ?string $value = null, bool $reset = false) {
    static $cache = [];
    if ($reset) { $cache = []; return null; }
    if ($key === null) return null;
    if ($value !== null) { $cache[$key] = $value; return $value; }
    return $cache[$key] ?? null;
}

function pretesSettingGet(string $key, string $default = ''): string {
    $cached = pretesSettingsCache($key);
    if ($cached !== null) return $cached;
    $pdo = pretesComponentsPdo();
    if (!$pdo) return $default; // tanpa DB: nilai bawaan, tidak di-cache
    try {
        ensurePretesSettingsTable($pdo);
        $stmt = $pdo->prepare('SELECT setting_value FROM pretes_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        $val = ($val === false || $val === null) ? $default : (string)$val;
    } catch (Throwable $e) {
        error_log('pretesSettingGet: ' . $e->getMessage());
        return $default;
    }
    return pretesSettingsCache($key, $val);
}

function pretesSettingSet(PDO $pdo, string $key, string $value): void {
    ensurePretesSettingsTable($pdo);
    $upd = $pdo->prepare('UPDATE pretes_settings SET setting_value = ?, updated_at = CURRENT_TIMESTAMP WHERE setting_key = ?');
    $upd->execute([$value, $key]);
    if ($upd->rowCount() === 0) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM pretes_settings WHERE setting_key = ?');
        $chk->execute([$key]);
        if ((int)$chk->fetchColumn() === 0) {
            $pdo->prepare('INSERT INTO pretes_settings (setting_key, setting_value) VALUES (?, ?)')->execute([$key, $value]);
        }
    }
    pretesSettingsCache(null, null, true);
    pretesComponentsCache(null, null, true);
}

function pretesRplPassMode(): string {
    $mode = pretesSettingGet('rpl_pass_mode', PRETES_RPL_PASS_MODE_DEFAULT);
    return isset(PRETES_RPL_PASS_MODES[$mode]) ? $mode : PRETES_RPL_PASS_MODE_DEFAULT;
}

/** true = mahasiswa RPL lulus pretes cukup dengan CBT resmi ≥ 80 (tanpa tugas online/penilaian tutor). */
function pretesRplCbtOnly(): bool {
    return pretesRplPassMode() === 'cbt_only';
}

/** Baris komponen sebuah program, terurut. */
function pretesComponentRows(PDO $pdo, string $program, bool $includeInactive = false): array {
    ensurePretesComponentTable($pdo);
    $sql = 'SELECT * FROM pretes_components WHERE program = ?' . ($includeInactive ? '' : ' AND is_active = 1') . ' ORDER BY sort_order ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$program]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Daftar komponen aktif (kunci → label), diawali Ujian tulis.
 * $program null = gabungan komponen aktif semua program (untuk kolom rekap, impor, validasi umum).
 */
function pretesComponentsFor(?string $program = null): array {
    $base = ['ujian_tulis' => 'Ujian tulis'];
    // Mode "cukup CBT": komponen wajib RPL hanya Ujian tulis (CBT). Daftar praktik RPL tetap tersimpan.
    if ($program === 'rpl' && pretesRplCbtOnly()) return $base;
    $cacheKey = $program ?? '*';
    $cached = pretesComponentsCache($cacheKey);
    if ($cached !== null) return $cached;
    $pdo = pretesComponentsPdo();
    $list = null;
    if ($pdo) {
        try {
            $programs = $program !== null && isset(PRETES_COMPONENT_PROGRAMS[$program]) ? [$program] : array_keys(PRETES_COMPONENT_PROGRAMS);
            $list = $base;
            foreach ($programs as $p) {
                foreach (pretesComponentRows($pdo, $p) as $row) {
                    if (!isset($list[$row['comp_key']])) $list[$row['comp_key']] = $row['label'];
                }
            }
        } catch (Throwable $e) {
            error_log('pretesComponentsFor: ' . $e->getMessage());
            $list = null;
        }
    }
    if ($list === null) {
        // Tanpa database (mis. test unit) → komponen bawaan, tidak di-cache agar PDO yang diset kemudian dipakai
        $list = $base;
        foreach (pretesDefaultComponentRows() as $row) $list[$row['comp_key']] = $row['label'];
        return $list;
    }
    return pretesComponentsCache($cacheKey, $list);
}

/** Kelompok tampilan panel penilaian: ['Ujian CBT' => [...], kategori => [kunci => label], ...]. */
function pretesComponentCategoriesFor(?string $program): array {
    $groups = ['Ujian CBT' => ['ujian_tulis' => 'Ujian tulis (CBT)']];
    if ($program === 'rpl' && pretesRplCbtOnly()) return $groups;
    $pdo = pretesComponentsPdo();
    $rows = [];
    if ($pdo) {
        try {
            $programs = $program !== null && isset(PRETES_COMPONENT_PROGRAMS[$program]) ? [$program] : array_keys(PRETES_COMPONENT_PROGRAMS);
            $seen = [];
            foreach ($programs as $p) foreach (pretesComponentRows($pdo, $p) as $r) {
                if (isset($seen[$r['comp_key']])) continue;
                $seen[$r['comp_key']] = true;
                $rows[] = $r;
            }
        } catch (Throwable $e) { $rows = []; }
    }
    if (!$rows) $rows = pretesDefaultComponentRows();
    foreach ($rows as $r) {
        $cat = trim((string)($r['kategori'] ?? '')) ?: PRETES_COMPONENT_CATEGORIES[0];
        $groups[$cat][$r['comp_key']] = $r['label'];
    }
    return $groups;
}

/** Jumlah komponen praktik/lisan aktif (tanpa Ujian tulis) untuk program. */
function pretesPracticalCount(?string $program): int {
    return max(0, count(pretesComponentsFor($program)) - 1);
}

/** Kunci aman dari label: huruf kecil, angka, garis bawah; unik dalam program. */
function pretesComponentMakeKey(PDO $pdo, string $program, string $label): string {
    $ascii = function_exists('iconv') ? (@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label) : $label;
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $ascii), '_'));
    $base = substr($base !== '' ? $base : 'komponen', 0, 50);
    if ($base === 'ujian_tulis') $base = 'ujian_tulis_praktik';
    $key = $base;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pretes_components WHERE program = ? AND comp_key = ?');
    for ($i = 2; ; $i++) {
        $stmt->execute([$program, $key]);
        if ((int)$stmt->fetchColumn() === 0) return $key;
        $key = $base . '_' . $i;
    }
}

/** Apakah ada nilai tersimpan untuk kunci komponen ini (pada program mana pun). */
function pretesComponentHasScores(PDO $pdo, string $key): bool {
    try {
        $stmt = $pdo->prepare('SELECT scores_json FROM pretes_assessments WHERE scores_json LIKE ?');
        $stmt->execute(['%"' . $key . '"%']);
        while (($json = $stmt->fetchColumn()) !== false) {
            $scores = json_decode((string)$json, true);
            if (is_array($scores) && isset($scores[$key]) && $scores[$key] !== '' && is_numeric($scores[$key])) return true;
        }
    } catch (Throwable $e) {
        return true; // ragu → anggap ada nilai (lebih aman: tidak boleh dihapus permanen)
    }
    return false;
}

/**
 * Simpan (tambah bila $id null, edit bila ada). Mengembalikan baris tersimpan.
 * @throws InvalidArgumentException
 */
function pretesComponentSave(PDO $pdo, string $program, array $input, ?int $id = null): array {
    ensurePretesComponentTable($pdo);
    if (!isset(PRETES_COMPONENT_PROGRAMS[$program])) throw new InvalidArgumentException('Program tidak dikenal.');
    $label = trim(preg_replace('/\s+/u', ' ', (string)($input['label'] ?? '')));
    if ($label === '' || mb_strlen($label) > 150) throw new InvalidArgumentException('Nama komponen wajib diisi (maksimal 150 karakter).');
    $bentuk = trim((string)($input['bentuk'] ?? 'Lisan / Praktik'));
    if (!in_array($bentuk, PRETES_COMPONENT_FORMS, true)) $bentuk = 'Lisan / Praktik';
    $kategori = trim((string)($input['kategori'] ?? ''));
    if (!in_array($kategori, PRETES_COMPONENT_CATEGORIES, true)) $kategori = PRETES_COMPONENT_CATEGORIES[0];
    $videoUrl = trim((string)($input['video_url'] ?? ''));
    if ($videoUrl !== '' && (!filter_var($videoUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $videoUrl) || strlen($videoUrl) > 500)) {
        throw new InvalidArgumentException('Link video harus berupa alamat http/https yang valid.');
    }
    $videoTitle = mb_substr(trim((string)($input['video_title'] ?? '')), 0, 200);
    $readingTitle = mb_substr(trim((string)($input['reading_title'] ?? '')), 0, 200);
    $readingText = trim(str_replace("\r\n", "\n", (string)($input['reading_text'] ?? '')));
    if (mb_strlen($readingText) > 20000) throw new InvalidArgumentException('Teks bacaan terlalu panjang (maksimal 20.000 karakter).');
    $params = [$label, $bentuk, $kategori, $videoUrl ?: null, $videoTitle ?: null, $readingTitle ?: null, $readingText !== '' ? $readingText : null];

    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM pretes_components WHERE id = ? AND program = ?');
        $stmt->execute([$id, $program]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) throw new InvalidArgumentException('Komponen tidak ditemukan.');
        $pdo->prepare('UPDATE pretes_components SET label = ?, bentuk = ?, kategori = ?, video_url = ?, video_title = ?, reading_title = ?, reading_text = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute(array_merge($params, [$id]));
    } else {
        $key = pretesComponentMakeKey($pdo, $program, $label);
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM pretes_components WHERE program = ?');
        $stmt->execute([$program]);
        $order = (int)$stmt->fetchColumn() + 10;
        $pdo->prepare('INSERT INTO pretes_components (program, comp_key, label, bentuk, kategori, video_url, video_title, reading_title, reading_text, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')->execute(array_merge([$program, $key], $params, [$order]));
        $id = (int)$pdo->lastInsertId();
    }
    pretesComponentsCache(null, null, true);
    $stmt = $pdo->prepare('SELECT * FROM pretes_components WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/** Aktifkan / nonaktifkan. Minimal satu komponen aktif harus tersisa per program. */
function pretesComponentSetActive(PDO $pdo, string $program, int $id, bool $active): void {
    ensurePretesComponentTable($pdo);
    if (!$active) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pretes_components WHERE program = ? AND is_active = 1 AND id <> ?');
        $stmt->execute([$program, $id]);
        if ((int)$stmt->fetchColumn() === 0) throw new InvalidArgumentException('Minimal satu komponen praktik harus tetap aktif.');
    }
    $stmt = $pdo->prepare('UPDATE pretes_components SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND program = ?');
    $stmt->execute([$active ? 1 : 0, $id, $program]);
    if ($stmt->rowCount() === 0) throw new InvalidArgumentException('Komponen tidak ditemukan.');
    pretesComponentsCache(null, null, true);
}

/** Geser urutan naik (-1) / turun (+1) dengan menukar sort_order tetangga. */
function pretesComponentMove(PDO $pdo, string $program, int $id, int $direction): void {
    $rows = pretesComponentRows($pdo, $program, true);
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $pos = array_search($id, $ids, true);
    if ($pos === false) throw new InvalidArgumentException('Komponen tidak ditemukan.');
    $target = $pos + ($direction < 0 ? -1 : 1);
    if ($target < 0 || $target >= count($ids)) return;
    [$ids[$pos], $ids[$target]] = [$ids[$target], $ids[$pos]];
    $upd = $pdo->prepare('UPDATE pretes_components SET sort_order = ? WHERE id = ?');
    foreach ($ids as $i => $rid) $upd->execute([($i + 1) * 10, $rid]);
    pretesComponentsCache(null, null, true);
}

/** Hapus permanen: hanya bila belum ada nilai tersimpan untuk kunci ini. */
function pretesComponentDelete(PDO $pdo, string $program, int $id): void {
    ensurePretesComponentTable($pdo);
    $stmt = $pdo->prepare('SELECT * FROM pretes_components WHERE id = ? AND program = ?');
    $stmt->execute([$id, $program]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('Komponen tidak ditemukan.');
    if (pretesComponentHasScores($pdo, (string)$row['comp_key'])) {
        throw new InvalidArgumentException('Komponen "' . $row['label'] . '" sudah memiliki nilai mahasiswa sehingga tidak dapat dihapus permanen. Nonaktifkan saja.');
    }
    if ((int)$row['is_active'] === 1) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM pretes_components WHERE program = ? AND is_active = 1 AND id <> ?');
        $c->execute([$program, $id]);
        if ((int)$c->fetchColumn() === 0) throw new InvalidArgumentException('Minimal satu komponen praktik harus tetap aktif.');
    }
    $pdo->prepare('DELETE FROM pretes_components WHERE id = ?')->execute([$id]);
    pretesComponentsCache(null, null, true);
}

/** ID video YouTube dari URL (untuk tautan "Tonton"), null bila bukan YouTube. */
function pretesYoutubeId(?string $url): ?string {
    if (!$url) return null;
    if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) return $m[1];
    return null;
}
