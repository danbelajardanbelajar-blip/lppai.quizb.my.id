<?php
require_once __DIR__ . '/pretes-components.php';
/**
 * LPPAI Corner - Helper Manajemen Komponen Tugas & Mode Kelulusan Tutorial RPL Online.
 * Berparitas penuh dengan includes/pretes-components.php.
 */

if (!defined('TUTORIAL_RPL_TASK_FORMS')) {
    define('TUTORIAL_RPL_TASK_FORMS', array_values(array_unique(array_merge(PRETES_COMPONENT_FORMS, ['Video Rekaman', 'Tertulis / Online']))));
}
if (!defined('TUTORIAL_RPL_TASK_CATEGORIES')) {
    define('TUTORIAL_RPL_TASK_CATEGORIES', array_values(array_unique(array_merge(PRETES_COMPONENT_CATEGORIES, ['Ibadah Praktik', 'Hafalan & Bacaan', 'Ubudiyah', 'Al-Qur\'an', 'Umum']))));
}
if (!defined('TUTORIAL_RPL_PASS_MODES')) {
    define('TUTORIAL_RPL_PASS_MODES', [
        'flexible_3_options' => '3 Opsi Pilihan Bebas (CBT ≥ 70 / Tatap Muka Dosen / Video Drive)',
        'cbt_only' => 'Cukup Lulus CBT Tutorial RPL',
        'cbt_and_tasks' => 'Wajib Lulus CBT + Seluruh Tugas Praktik Aktif',
    ]);
}

function ensureTutorialRplTasksTables(PDO $pdo, ?int $actorId = null): void {
    static $done = null;
    $done ??= new WeakMap();
    if (isset($done[$pdo])) return;

    $isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $idType = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $engine = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_tasks (
        id $idType,
        task_key VARCHAR(80) NOT NULL UNIQUE,
        label VARCHAR(150) NOT NULL,
        bentuk VARCHAR(60) NOT NULL DEFAULT 'Lisan / Praktik',
        kategori VARCHAR(80) NOT NULL DEFAULT 'Ibadah Praktik',
        video_url VARCHAR(500) DEFAULT NULL,
        video_title VARCHAR(200) DEFAULT NULL,
        reading_title VARCHAR(200) DEFAULT NULL,
        reading_text TEXT DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 1,
        is_active INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )$engine");
    if (!$isSqlite) {
        $categoryColumn=$pdo->query("SHOW COLUMNS FROM tutorial_rpl_tasks LIKE 'kategori'")->fetch(PDO::FETCH_ASSOC);
        if ($categoryColumn && preg_match('/varchar\((\d+)\)/i',$categoryColumn['Type'],$match) && (int)$match[1]<80) {
            $pdo->exec("ALTER TABLE tutorial_rpl_tasks MODIFY kategori VARCHAR(80) NOT NULL DEFAULT 'Ibadah Praktik'");
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value TEXT DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )$engine");

    $longText = $isSqlite ? 'TEXT' : 'LONGTEXT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS tutorial_rpl_task_imports (
        id $idType, actor_id INT NULL, source_hash VARCHAR(64) NOT NULL,
        backup_json $longText NOT NULL, source_json $longText NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )$engine");
    tutorialRplTaskCopyPretesRpl($pdo, false, $actorId);
    $done[$pdo] = true;
}

/** Read only the RPL source; never seed or update pretes tables while importing tutorial tasks. */
function tutorialRplTaskPretesRplSource(PDO $pdo): array {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $exists=$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='pretes_components'")->fetchColumn();
    } else {
        $exists=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pretes_components'")->fetchColumn();
    }
    if ($exists) {
        $stmt=$pdo->prepare('SELECT comp_key,label,bentuk,kategori,video_url,video_title,reading_title,reading_text,sort_order,is_active FROM pretes_components WHERE program=? ORDER BY sort_order,id');
        $stmt->execute(['rpl']);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) return $rows;
    }
    // Same defaults the source page initializes when RPL components have not been configured.
    $rows=[];
    foreach (pretesDefaultComponentRows() as $i=>$row) $rows[]=[
        'comp_key'=>$row['comp_key'],'label'=>$row['label'],'bentuk'=>'Lisan / Praktik','kategori'=>$row['kategori'],
        'video_url'=>$row['video_url'] ?? null,'video_title'=>$row['video_title'] ?? null,
        'reading_title'=>null,'reading_text'=>null,'sort_order'=>($i+1)*10,'is_active'=>1,
    ];
    return $rows;
}

/** Tables must be initialized. One-time migration, or an explicitly confirmed administrator copy. */
function tutorialRplTaskCopyPretesRpl(PDO $pdo, bool $force = false, ?int $actorId = null): int {
    $marker='pretes_rpl_list_copy_v1';
    $ownsTransaction=!$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' FOR UPDATE' : '';
        $stmt=$pdo->prepare('SELECT setting_value FROM tutorial_rpl_settings WHERE setting_key=?'.$lock);$stmt->execute([$marker]);
        if (!$force && $stmt->fetchColumn()!==false) { if ($ownsTransaction) $pdo->commit(); return 0; }
        $source=tutorialRplTaskPretesRplSource($pdo);
        $backup=$pdo->query('SELECT * FROM tutorial_rpl_tasks ORDER BY sort_order,id')->fetchAll(PDO::FETCH_ASSOC);
        $sourceJson=json_encode($source,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $hash=hash('sha256',$sourceJson);
        $pdo->prepare('INSERT INTO tutorial_rpl_task_imports(actor_id,source_hash,backup_json,source_json) VALUES(?,?,?,?)')
            ->execute([$actorId,$hash,json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$sourceJson]);
        $keys=[];
        foreach ($source as $row) {
            $key=(string)$row['comp_key'];$keys[]=$key;
            $find=$pdo->prepare('SELECT id FROM tutorial_rpl_tasks WHERE task_key=?');$find->execute([$key]);$id=$find->fetchColumn();
            $values=[$row['label'],$row['bentuk'],$row['kategori'],$row['video_url'],$row['video_title'],$row['reading_title'],$row['reading_text'],(int)$row['sort_order'],(int)$row['is_active']];
            if ($id!==false) {
                $pdo->prepare('UPDATE tutorial_rpl_tasks SET label=?,bentuk=?,kategori=?,video_url=?,video_title=?,reading_title=?,reading_text=?,sort_order=?,is_active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute(array_merge($values,[$id]));
            } else {
                $pdo->prepare('INSERT INTO tutorial_rpl_tasks(label,bentuk,kategori,video_url,video_title,reading_title,reading_text,sort_order,is_active,task_key) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($values,[$key]));
            }
        }
        $pdo->prepare('DELETE FROM tutorial_rpl_tasks WHERE task_key NOT IN ('.implode(',',array_fill(0,count($keys),'?')).')')->execute($keys);
        $insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? 'INSERT INTO tutorial_rpl_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=CURRENT_TIMESTAMP'
            : 'INSERT INTO tutorial_rpl_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP';
        $pdo->prepare($insert)->execute([$marker,$hash]);
        if ($ownsTransaction) $pdo->commit();
        return count($source);
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
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
    $sql .= ' ORDER BY sort_order ASC, id ASC';
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
