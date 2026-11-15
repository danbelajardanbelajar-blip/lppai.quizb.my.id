<?php
/**
 * LPPAI Corner - API Pesan (JSON, POST + CSRF)
 *
 * Aksi:
 *  poll    {since_id}                  → {unread, rev, incoming[]}              (semua pengguna login)
 *  thread  {thread_id?, after_id?, before_id?} → {thread, messages[], has_more} (non-admin: percakapannya sendiri)
 *  send    {thread_id?, body, client_id?}      → {message}
 *  read    {thread_id?}                → {ok}
 *  threads {q?}                        → {threads[]}                             (admin)
 *  users   {q}                         → {users[]}                               (admin)
 *  start   {user_id}                   → {thread}                                (admin)
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/messaging.php';

function msgReply(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) msgReply(401, ['success' => false, 'message' => 'Sesi login berakhir. Silakan login kembali.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') msgReply(405, ['success' => false, 'message' => 'Gunakan metode POST.']);
$raw = file_get_contents('php://input', false, null, 0, 16385);
if (strlen((string)$raw) > 16384) msgReply(413, ['success' => false, 'message' => 'Permintaan terlalu besar.']);
$input = json_decode((string)$raw, true);
if (!is_array($input) || !is_string($input['csrf_token'] ?? null) || !verifyCsrf($input['csrf_token'])) {
    msgReply(403, ['success' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman.']);
}

$user = getCurrentUser();
// Lepaskan kunci sesi lebih awal agar polling tidak menahan request lain
session_write_close();
$pdo = getDBConnection();
msgEnsureTables($pdo);
$isAdmin = msgIsAdmin($user);
$action = (string)($input['action'] ?? '');
$int = static fn(string $k): int => max(0, (int)($input[$k] ?? 0));

try {
    switch ($action) {
        case 'poll':
            msgReply(200, ['success' => true] + msgPoll($pdo, $user, $int('since_id')));
        case 'thread':
            msgReply(200, ['success' => true] + msgFetchThread($pdo, $user, $int('thread_id') ?: null, $int('after_id'), $int('before_id')));
        case 'send':
            $message = msgSend($pdo, $user, $int('thread_id') ?: null, (string)($input['body'] ?? ''), isset($input['client_id']) ? (string)$input['client_id'] : null);
            msgReply(200, ['success' => true, 'message' => $message]);
        case 'read':
            $thread = msgResolveThread($pdo, $user, $int('thread_id') ?: null, false);
            if ($thread) msgMarkRead($pdo, $user, (int)$thread['id']);
            msgReply(200, ['success' => true]);
        case 'threads':
            if (!$isAdmin) msgReply(403, ['success' => false, 'message' => 'Hanya admin.']);
            msgReply(200, ['success' => true, 'threads' => msgAdminThreads($pdo, $user, trim((string)($input['q'] ?? '')))]);
        case 'users':
            if (!$isAdmin) msgReply(403, ['success' => false, 'message' => 'Hanya admin.']);
            msgReply(200, ['success' => true, 'users' => msgSearchUsers($pdo, (string)($input['q'] ?? ''))]);
        case 'start':
            if (!$isAdmin) msgReply(403, ['success' => false, 'message' => 'Hanya admin.']);
            msgReply(200, ['success' => true, 'thread' => msgAdminStartThread($pdo, $user, $int('user_id'))]);
        default:
            msgReply(400, ['success' => false, 'message' => 'Aksi pesan tidak dikenal.']);
    }
} catch (InvalidArgumentException $e) {
    msgReply(422, ['success' => false, 'message' => $e->getMessage()]);
} catch (RuntimeException $e) {
    msgReply(429, ['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Messages API error: ' . $e->getMessage());
    msgReply(500, ['success' => false, 'message' => 'Layanan pesan sedang bermasalah. Coba lagi sebentar lagi.']);
}
