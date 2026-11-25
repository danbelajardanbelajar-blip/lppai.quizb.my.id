<?php
/**
 * LPPAI Corner - Helper Manajemen Komponen Tugas & Mode Kelulusan Tutorial RPL Online.
 * Berparitas penuh dengan includes/pretes-components.php.
 */

if (!defined('TUTORIAL_RPL_TASK_FORMS')) {
    define('TUTORIAL_RPL_TASK_FORMS', ['Lisan / Praktik', 'Hafalan', 'Video Rekaman', 'Tertulis / Online']);
}
if (!defined('TUTORIAL_RPL_TASK_CATEGORIES')) {
    define('TUTORIAL_RPL_TASK_CATEGORIES', ['Ibadah Praktik', 'Hafalan & Bacaan', 'Ubudiyah', 'Al-Qur\'an', 'Umum']);
}
if (!defined('TUTORIAL_RPL_PASS_MODES')) {
    define('TUTORIAL_RPL_PASS_MODES', [
        'flexible_3_options' => '3 Opsi Pilihan Bebas (CBT ≥ 70 / Tatap Muka Dosen / Video Drive)',
        'cbt_only' => 'Cukup Lulus CBT Tutorial RPL',
        'cbt_and_tasks' => 'Wajib Lulus CBT + Seluruh Tugas Praktik Aktif',
    ]);
}

function ensureTutorialRplTasksTables(PDO $pdo): void {
    static $done = [];
    $key = spl_object_id($pdo);
    if (!empty($done[$key])) return;
    $done[$key] = true;

    $isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $idType = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $engine = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_tasks (
        id $idType,
        task_key VARCHAR(80) NOT NULL UNIQUE,
        label VARCHAR(150) NOT NULL,
        bentuk VARCHAR(60) NOT NULL DEFAULT 'Lisan / Praktik',
        kategori VARCHAR(60) NOT NULL DEFAULT 'Ibadah Praktik',
        video_url VARCHAR(500) DEFAULT NULL,
        video_title VARCHAR(200) DEFAULT NULL,
        reading_title VARCHAR(200) DEFAULT NULL,
        reading_text TEXT DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 1,
        is_active INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value TEXT DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )$engine");

    // Seed 6 poin tugas ibadah standar hanya jika tabel masih kosong
    $count = (int)$pdo->query('SELECT COUNT(*) FROM tutorial_rpl_tasks')->fetchColumn();
    if ($count === 0) {
        $defaults = [
            ['wudlu_tayamum', 'Praktik Wudlu & Tayamum', 'Lisan / Praktik', 'Ibadah Praktik'],
            ['sholat_fardhu', 'Praktik Sholat Fardhu (Bacaan & Gerakan)', 'Lisan / Praktik', 'Ibadah Praktik'],
            ['dzikir_doa_sholat', 'Bacaan Dzikir & Doa Setelah Sholat', 'Hafalan', 'Hafalan & Bacaan'],
            ['sholat_jenazah', 'Praktik Sholat Jenazah', 'Lisan / Praktik', 'Ibadah Praktik'],
            ['hafalan_surat_pendek', 'Hafalan Surat-Surat Pendek (Juz Amma)', 'Hafalan', 'Al-Qur\'an'],
            ['tahlil_doa_arwah', 'Bacaan Tahlil & Doa Arwah', 'Hafalan', 'Hafalan & Bacaan'],
        ];
        $stmt = $pdo->prepare('INSERT INTO tutorial_rpl_tasks (task_key, label, bentuk, kategori, sort_order, is_active) VALUES (?, ?, ?, ?, ?, 1)');
        foreach ($defaults as $idx => [$tKey, $label, $bentuk, $kat]) {
            $stmt->execute([$tKey, $label, $bentuk, $kat, $idx + 1]);
        }
    }
}

function tutorialRplSettingGet(PDO $pdo, string $key, string $default = ''): string {
    ensureTutorialRplTasksTables($pdo);
    $stmt = $pdo->prepare('SELECT setting_value FROM tutorial_rpl_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return ($val !== false && $val !== null && $val !== '') ? (string)$val : $default;
}

function tutorialRplSettingSet(PDO $pdo, string $key, string $value): void {
    ensureTutorialRplTasksTables($pdo);
    $isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if ($isSqlite) {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO tutorial_rpl_settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
        $stmt->execute([$key, $value]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO tutorial_rpl_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP');
        $stmt->execute([$key, $value]);
    }
}

function tutorialRplPassMode(PDO $pdo): string {
    $mode = tutorialRplSettingGet($pdo, 'rpl_tutorial_pass_mode', 'flexible_3_options');
    return isset(TUTORIAL_RPL_PASS_MODES[$mode]) ? $mode : 'flexible_3_options';
}

function tutorialRplTaskRows(PDO $pdo, bool $includeInactive = true): array {
    ensureTutorialRplTasksTables($pdo);
    $sql = 'SELECT * FROM tutorial_rpl_tasks';
    if (!$includeInactive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY is_active DESC, sort_order ASC, id ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function tutorialRplTaskSave(PDO $pdo, array $input, ?int $id = null): array {
    ensureTutorialRplTasksTables($pdo);
    $label = trim((string)($input['label'] ?? ''));
    if ($label === '') {
        throw new InvalidArgumentException('Nama komponen / tugas wajib diisi.');
    }
    if (mb_strlen($label) > 150) {
        throw new InvalidArgumentException('Nama komponen maksimal 150 karakter.');
    }

    $bentuk = trim((string)($input['bentuk'] ?? TUTORIAL_RPL_TASK_FORMS[0]));
    if (!in_array($bentuk, TUTORIAL_RPL_TASK_FORMS, true)) {
        $bentuk = TUTORIAL_RPL_TASK_FORMS[0];
    }

    $kategori = trim((string)($input['kategori'] ?? TUTORIAL_RPL_TASK_CATEGORIES[0]));
    if (!in_array($kategori, TUTORIAL_RPL_TASK_CATEGORIES, true)) {
        $kategori = TUTORIAL_RPL_TASK_CATEGORIES[0];
    }

    $videoUrl = trim((string)($input['video_url'] ?? ''));
    if ($videoUrl !== '' && !filter_var($videoUrl, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Link video panduan tidak valid. Awali dengan https:// atau http://.');
    }
    $videoTitle = trim((string)($input['video_title'] ?? ''));
    $readingTitle = trim((string)($input['reading_title'] ?? ''));
    $readingText = trim((string)($input['reading_text'] ?? ''));

    if ($id !== null && $id > 0) {
        $stmtChk = $pdo->prepare('SELECT * FROM tutorial_rpl_tasks WHERE id = ?');
        $stmtChk->execute([$id]);
        $existing = $stmtChk->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            throw new InvalidArgumentException('Komponen tugas tidak ditemukan.');
        }
        $stmt = $pdo->prepare('UPDATE tutorial_rpl_tasks SET label = ?, bentuk = ?, kategori = ?, video_url = ?, video_title = ?, reading_title = ?, reading_text = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([
            $label,
            $bentuk,
            $kategori,
            $videoUrl !== '' ? $videoUrl : null,
            $videoTitle !== '' ? $videoTitle : null,
            $readingTitle !== '' ? $readingTitle : null,
            $readingText !== '' ? $readingText : null,
            $id,
        ]);
        $stmtChk->execute([$id]);
        return $stmtChk->fetch(PDO::FETCH_ASSOC);
    }

    $slug = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_'));
    if ($slug === '') $slug = 'tugas';
    $taskKey = substr($slug, 0, 50) . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $maxOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM tutorial_rpl_tasks')->fetchColumn();

    $stmt = $pdo->prepare('INSERT INTO tutorial_rpl_tasks (task_key, label, bentuk, kategori, video_url, video_title, reading_title, reading_text, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
    $stmt->execute([
        $taskKey,
        $label,
        $bentuk,
        $kategori,
        $videoUrl !== '' ? $videoUrl : null,
        $videoTitle !== '' ? $videoTitle : null,
        $readingTitle !== '' ? $readingTitle : null,
        $readingText !== '' ? $readingText : null,
        $maxOrder + 1,
    ]);
    $newId = (int)$pdo->lastInsertId();
    $stmtGet = $pdo->prepare('SELECT * FROM tutorial_rpl_tasks WHERE id = ?');
    $stmtGet->execute([$newId]);
    return $stmtGet->fetch(PDO::FETCH_ASSOC);
}

function tutorialRplTaskSetActive(PDO $pdo, int $id, bool $active): void {
    ensureTutorialRplTasksTables($pdo);
    $stmt = $pdo->prepare('UPDATE tutorial_rpl_tasks SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$active ? 1 : 0, $id]);
    if ($stmt->rowCount() === 0) {
        throw new InvalidArgumentException('Komponen tugas tidak ditemukan.');
    }
}

function tutorialRplTaskMove(PDO $pdo, int $id, int $direction): void {
    ensureTutorialRplTasksTables($pdo);
    $rows = tutorialRplTaskRows($pdo, true);
    $idx = null;
    foreach ($rows as $i => $r) {
        if ((int)$r['id'] === $id) { $idx = $i; break; }
    }
    if ($idx === null) throw new InvalidArgumentException('Komponen tugas tidak ditemukan.');
    $targetIdx = $idx + ($direction < 0 ? -1 : 1);
    if ($targetIdx < 0 || $targetIdx >= count($rows)) return;

    $temp = $rows[$idx];
    $rows[$idx] = $rows[$targetIdx];
    $rows[$targetIdx] = $temp;

    $stmt = $pdo->prepare('UPDATE tutorial_rpl_tasks SET sort_order = ? WHERE id = ?');
    foreach ($rows as $pos => $r) {
        $stmt->execute([$pos + 1, (int)$r['id']]);
    }
}

function tutorialRplTaskDelete(PDO $pdo, int $id): void {
    ensureTutorialRplTasksTables($pdo);
    $stmt = $pdo->prepare('DELETE FROM tutorial_rpl_tasks WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        throw new InvalidArgumentException('Komponen tugas tidak ditemukan.');
    }
}
