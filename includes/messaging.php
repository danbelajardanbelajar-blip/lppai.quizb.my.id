<?php
/**
 * LPPAI Corner - Layanan Pesan (Mahasiswa/Dosen ↔ Admin LPPAI)
 *
 * Model: setiap pengguna non-admin memiliki SATU percakapan dengan tim "Admin LPPAI"
 * (message_threads.user_id unik). Semua admin dapat membaca & membalas (kotak masuk tim).
 * Admin dapat memulai percakapan ke pengguna mana pun.
 *
 * Tabel:
 *  - message_threads(id, user_id UNIQUE, status, last_message_at, last_message_preview,
 *                    last_sender_role, user_unread, admin_unread, created_at, updated_at)
 *  - messages(id, thread_id, sender_id, sender_role, body, client_id, created_at)
 */

const MSG_MAX_LENGTH = 2000;
const MSG_RATE_LIMIT_PER_MINUTE = 15;
const MSG_PAGE_SIZE = 50;

function msgEnsureTables(PDO $pdo): void {
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) return;
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS message_threads (
        id $id,
        user_id INT NOT NULL UNIQUE,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        last_message_at INT NOT NULL DEFAULT 0,
        last_message_preview VARCHAR(200) DEFAULT NULL,
        last_sender_role VARCHAR(20) DEFAULT NULL,
        user_unread INT NOT NULL DEFAULT 0,
        admin_unread INT NOT NULL DEFAULT 0,
        created_at INT NOT NULL,
        updated_at INT NOT NULL
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id $id,
        thread_id INT NOT NULL,
        sender_id INT NOT NULL,
        sender_role VARCHAR(20) NOT NULL,
        body TEXT NOT NULL,
        client_id VARCHAR(64) DEFAULT NULL,
        created_at INT NOT NULL
    )$engine");
    try {
        if ($sqlite) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_thread ON messages(thread_id, id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_sender ON messages(sender_id, created_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_threads_last ON message_threads(last_message_at)');
        } else {
            foreach ([
                ['messages', 'idx_messages_thread', '(thread_id, id)'],
                ['messages', 'idx_messages_sender', '(sender_id, created_at)'],
                ['message_threads', 'idx_threads_last', '(last_message_at)'],
            ] as [$table, $index, $cols]) {
                $exists = $pdo->query("SHOW INDEX FROM $table WHERE Key_name = '$index'")->fetch();
                if (!$exists) $pdo->exec("CREATE INDEX $index ON $table $cols");
            }
        }
    } catch (Throwable $e) {
        error_log('msgEnsureTables index: ' . $e->getMessage());
    }
    $done[$key] = true;
}

function msgIsAdmin(array $user): bool {
    return strtolower((string)($user['role'] ?? '')) === 'admin';
}

/** Ringkas teks untuk pratinjau daftar percakapan. */
function msgPreview(string $body): string {
    $flat = trim(preg_replace('/\s+/u', ' ', $body));
    return mb_strlen($flat) > 120 ? mb_substr($flat, 0, 117) . '…' : $flat;
}

/** Normalisasi & validasi isi pesan. */
function msgCleanBody(string $body): string {
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/[^\P{C}\n\t]/u', '', $body) ?? '';   // buang karakter kontrol (kecuali baris baru/tab)
    $body = preg_replace("/\n{4,}/", "\n\n\n", $body) ?? $body;
    $body = trim($body);
    if ($body === '') throw new InvalidArgumentException('Pesan tidak boleh kosong.');
    if (mb_strlen($body) > MSG_MAX_LENGTH) throw new InvalidArgumentException('Pesan maksimal ' . MSG_MAX_LENGTH . ' karakter.');
    return $body;
}

function msgGetThreadById(PDO $pdo, int $threadId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM message_threads WHERE id = ?');
    $stmt->execute([$threadId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function msgGetThreadForUser(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM message_threads WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function msgGetOrCreateThread(PDO $pdo, int $userId): array {
    $thread = msgGetThreadForUser($pdo, $userId);
    if ($thread) return $thread;
    $now = time();
    try {
        $pdo->prepare('INSERT INTO message_threads (user_id, status, last_message_at, created_at, updated_at) VALUES (?, \'open\', 0, ?, ?)')
            ->execute([$userId, $now, $now]);
    } catch (PDOException $e) {
        // Balapan dua permintaan: baris sudah dibuat permintaan lain (UNIQUE user_id)
    }
    $thread = msgGetThreadForUser($pdo, $userId);
    if (!$thread) throw new RuntimeException('Percakapan tidak dapat dibuat.');
    return $thread;
}

/**
 * Tentukan percakapan yang boleh diakses pengguna.
 * Non-admin: selalu percakapannya sendiri (dibuat bila perlu). Admin: wajib thread_id yang valid.
 */
function msgResolveThread(PDO $pdo, array $user, ?int $threadId, bool $create = false): ?array {
    if (msgIsAdmin($user)) {
        return $threadId ? msgGetThreadById($pdo, $threadId) : null;
    }
    $thread = msgGetThreadForUser($pdo, (int)$user['id']);
    // thread_id dari klien wajib milik pengguna ini (cek sebelum membuat percakapan baru)
    if ($threadId && (!$thread || (int)$thread['id'] !== $threadId)) {
        throw new InvalidArgumentException('Percakapan bukan milik akun Anda.');
    }
    if (!$thread && $create) $thread = msgGetOrCreateThread($pdo, (int)$user['id']);
    return $thread;
}

/** Kirim pesan; idempoten per client_id. @return array pesan terformat */
function msgSend(PDO $pdo, array $user, ?int $threadId, string $body, ?string $clientId = null, ?int $now = null): array {
    $now ??= time();
    $body = msgCleanBody($body);
    $isAdmin = msgIsAdmin($user);
    $thread = msgResolveThread($pdo, $user, $threadId, true);
    if (!$thread) throw new InvalidArgumentException('Pilih percakapan terlebih dahulu.');
    $clientId = $clientId !== null ? substr(preg_replace('/[^A-Za-z0-9_-]/', '', $clientId), 0, 64) : null;

    if ($clientId) {
        $dup = $pdo->prepare('SELECT * FROM messages WHERE thread_id = ? AND sender_id = ? AND client_id = ?');
        $dup->execute([(int)$thread['id'], (int)$user['id'], $clientId]);
        if ($row = $dup->fetch(PDO::FETCH_ASSOC)) return msgFormat($row, $user);
    }
    $rate = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE sender_id = ? AND created_at > ?');
    $rate->execute([(int)$user['id'], $now - 60]);
    if ((int)$rate->fetchColumn() >= MSG_RATE_LIMIT_PER_MINUTE) {
        throw new RuntimeException('Terlalu banyak pesan dalam satu menit. Tunggu sebentar lalu coba lagi.');
    }

    $role = $isAdmin ? 'admin' : (strtolower((string)($user['role'] ?? 'mahasiswa')) ?: 'mahasiswa');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO messages (thread_id, sender_id, sender_role, body, client_id, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(int)$thread['id'], (int)$user['id'], $role, $body, $clientId, $now]);
        $msgId = (int)$pdo->lastInsertId();
        $counter = $isAdmin ? 'user_unread = user_unread + 1, admin_unread = 0' : 'admin_unread = admin_unread + 1, user_unread = 0';
        $pdo->prepare("UPDATE message_threads SET last_message_at = ?, last_message_preview = ?, last_sender_role = ?, status = 'open', updated_at = ?, $counter WHERE id = ?")
            ->execute([$now, msgPreview($body), $role, $now, (int)$thread['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return msgFormat(['id' => $msgId, 'thread_id' => (int)$thread['id'], 'sender_id' => (int)$user['id'], 'sender_role' => $role,
        'body' => $body, 'client_id' => $clientId, 'created_at' => $now, 'sender_name' => $user['nama_lengkap'] ?? ''], $user);
}

/** Format pesan untuk klien (tanpa HTML; klien menampilkan via textContent). */
function msgFormat(array $row, array $viewer): array {
    $isMine = (int)$row['sender_id'] === (int)$viewer['id'];
    $fromAdmin = $row['sender_role'] === 'admin';
    $name = $fromAdmin
        ? (msgIsAdmin($viewer) ? ('Admin · ' . ($row['sender_name'] ?? 'LPPAI')) : 'Admin LPPAI')
        : (string)($row['sender_name'] ?? 'Pengguna');
    return [
        'id' => (int)$row['id'],
        'thread_id' => (int)$row['thread_id'],
        'mine' => $isMine,
        'from_admin' => $fromAdmin,
        'sender_name' => $isMine ? 'Anda' : $name,
        'body' => (string)$row['body'],
        'client_id' => $row['client_id'] ?? null,
        'created_at' => (int)$row['created_at'],
    ];
}

/** Ambil pesan percakapan (setelah after_id atau halaman terakhir), lalu tandai dibaca. */
function msgFetchThread(PDO $pdo, array $user, ?int $threadId, int $afterId = 0, int $beforeId = 0, bool $markRead = true): array {
    $thread = msgResolveThread($pdo, $user, $threadId, false);
    if (!$thread) return ['thread' => null, 'messages' => [], 'has_more' => false];
    $sql = 'SELECT m.*, u.nama_lengkap AS sender_name FROM messages m LEFT JOIN users u ON u.id = m.sender_id WHERE m.thread_id = ?';
    $params = [(int)$thread['id']];
    if ($afterId > 0) { $sql .= ' AND m.id > ? ORDER BY m.id ASC LIMIT ' . (MSG_PAGE_SIZE * 4); $params[] = $afterId; }
    elseif ($beforeId > 0) { $sql .= ' AND m.id < ? ORDER BY m.id DESC LIMIT ' . MSG_PAGE_SIZE; $params[] = $beforeId; }
    else { $sql .= ' ORDER BY m.id DESC LIMIT ' . MSG_PAGE_SIZE; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($afterId <= 0) $rows = array_reverse($rows);
    $hasMore = false;
    if ($afterId <= 0 && $rows) {
        $chk = $pdo->prepare('SELECT 1 FROM messages WHERE thread_id = ? AND id < ? LIMIT 1');
        $chk->execute([(int)$thread['id'], (int)$rows[0]['id']]);
        $hasMore = (bool)$chk->fetchColumn();
    }
    if ($markRead) {
        msgMarkRead($pdo, $user, (int)$thread['id']);
        $thread = msgGetThreadById($pdo, (int)$thread['id']) ?? $thread;
    }
    return ['thread' => msgThreadSummary($pdo, $thread, $user), 'messages' => array_map(fn($r) => msgFormat($r, $user), $rows), 'has_more' => $hasMore];
}

function msgMarkRead(PDO $pdo, array $user, int $threadId): void {
    $col = msgIsAdmin($user) ? 'admin_unread' : 'user_unread';
    $pdo->prepare("UPDATE message_threads SET $col = 0 WHERE id = ?")->execute([$threadId]);
}

/** Ringkasan percakapan untuk klien. */
function msgThreadSummary(PDO $pdo, array $thread, array $viewer): array {
    $peer = null;
    if (msgIsAdmin($viewer)) {
        $stmt = $pdo->prepare('SELECT id, nama_lengkap, nim, username, role, program_studi FROM users WHERE id = ?');
        $stmt->execute([(int)$thread['user_id']]);
        $peer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return [
        'id' => (int)$thread['id'],
        'user_id' => (int)$thread['user_id'],
        'title' => msgIsAdmin($viewer) ? ($peer['nama_lengkap'] ?? ('Pengguna #' . $thread['user_id'])) : 'Admin LPPAI',
        'subtitle' => msgIsAdmin($viewer)
            ? trim(($peer['nim'] ?: ($peer['username'] ?? '')) . ' · ' . ucfirst((string)($peer['role'] ?? '')) . (!empty($peer['program_studi']) ? ' · ' . $peer['program_studi'] : ''), ' ·')
            : 'Layanan bantuan LPPAI · biasanya dibalas pada jam kerja',
        'preview' => (string)($thread['last_message_preview'] ?? ''),
        'last_sender_role' => $thread['last_sender_role'] ?? null,
        'last_message_at' => (int)$thread['last_message_at'],
        'unread' => (int)(msgIsAdmin($viewer) ? $thread['admin_unread'] : $thread['user_unread']),
    ];
}

/** Daftar percakapan untuk admin (terbaru di atas), opsional pencarian nama/NIM. */
function msgAdminThreads(PDO $pdo, array $admin, string $q = '', int $limit = 40): array {
    $limit = max(1, min(100, $limit));
    $sql = 'SELECT t.* FROM message_threads t JOIN users u ON u.id = t.user_id WHERE t.last_message_at > 0';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (u.nama_lengkap LIKE ? OR u.nim LIKE ? OR u.username LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    $sql .= ' ORDER BY t.admin_unread > 0 DESC, t.last_message_at DESC LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map(fn($t) => msgThreadSummary($pdo, $t, $admin), $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Cari pengguna non-admin untuk memulai percakapan (admin). */
function msgSearchUsers(PDO $pdo, string $q, int $limit = 15): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("SELECT id, nama_lengkap, nim, username, role, program_studi FROM users
        WHERE role <> 'admin' AND (nama_lengkap LIKE ? OR nim LIKE ? OR username LIKE ?)
        ORDER BY nama_lengkap ASC LIMIT " . max(1, min(30, $limit)));
    $stmt->execute([$like, $like, $like]);
    return array_map(static fn($u) => [
        'id' => (int)$u['id'],
        'name' => (string)$u['nama_lengkap'],
        'meta' => trim(($u['nim'] ?: $u['username']) . ' · ' . ucfirst((string)$u['role']) . (!empty($u['program_studi']) ? ' · ' . $u['program_studi'] : ''), ' ·'),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Admin memulai/membuka percakapan dengan pengguna non-admin. */
function msgAdminStartThread(PDO $pdo, array $admin, int $userId): array {
    if (!msgIsAdmin($admin)) throw new InvalidArgumentException('Hanya admin yang dapat memulai percakapan.');
    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || strtolower((string)$u['role']) === 'admin') throw new InvalidArgumentException('Pengguna tujuan tidak valid.');
    return msgThreadSummary($pdo, msgGetOrCreateThread($pdo, $userId), $admin);
}

/**
 * Status ringan untuk polling: jumlah belum dibaca + penanda versi (id pesan terakhir yang relevan)
 * + pesan masuk terbaru sejak $sinceId (untuk notifikasi).
 */
function msgPoll(PDO $pdo, array $user, int $sinceId = 0): array {
    if (msgIsAdmin($user)) {
        $unread = (int)$pdo->query('SELECT COALESCE(SUM(admin_unread), 0) FROM message_threads')->fetchColumn();
        $rev = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM messages')->fetchColumn();
        $incomingSql = "SELECT m.*, u.nama_lengkap AS sender_name FROM messages m LEFT JOIN users u ON u.id = m.sender_id
            WHERE m.id > ? AND m.sender_role <> 'admin' ORDER BY m.id DESC LIMIT 5";
        $params = [$sinceId];
    } else {
        $thread = msgGetThreadForUser($pdo, (int)$user['id']);
        if (!$thread) return ['unread' => 0, 'rev' => 0, 'incoming' => []];
        $unread = (int)$thread['user_unread'];
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM messages WHERE thread_id = ?');
        $stmt->execute([(int)$thread['id']]);
        $rev = (int)$stmt->fetchColumn();
        $incomingSql = "SELECT m.*, u.nama_lengkap AS sender_name FROM messages m LEFT JOIN users u ON u.id = m.sender_id
            WHERE m.thread_id = ? AND m.id > ? AND m.sender_id <> ? ORDER BY m.id DESC LIMIT 5";
        $params = [(int)$thread['id'], $sinceId, (int)$user['id']];
    }
    $incoming = [];
    if ($sinceId > 0 && $rev > $sinceId) {
        $stmt = $pdo->prepare($incomingSql);
        $stmt->execute($params);
        $incoming = array_map(fn($r) => msgFormat($r, $user), array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC)));
    }
    return ['unread' => $unread, 'rev' => $rev, 'incoming' => $incoming];
}
