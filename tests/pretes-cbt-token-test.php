<?php
/** Actual session creation and exam-start token validation; isolated SQLite only. */
require_once __DIR__ . '/../includes/pretes-tryout.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensurePretesCbtTables($pdo);
$_SESSION = [];
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$userId = 200;
foreach (['resmi', 'tryout'] as $mode) {
    foreach ([false, true] as $required) {
        $input = ['title' => 'Token test ' . $mode, 'mode' => $mode, 'package_ids' => [1],
            'is_active' => 1, 'camera_mode' => 'tidak_aktif', 'token' => 'ABC123'];
        // An unchecked checkbox is absent in a real submitted form.
        if ($required) $input['token_required'] = '1';
        $sessionId = pretesCbtSaveSession($pdo, $input);
        $session = pretesCbtGetSession($pdo, $sessionId);
        $check((bool)$session['token_required'] === $required, 'Saved checkbox must determine token requirement');
        $check($session['token'] !== '', 'A stored token alone must not require token entry');
        $forced = $mode === 'tryout' ? $session : null;
        $regId = $mode === 'tryout' ? pretesTryoutRegId($sessionId, 1) : 10000 + $sessionId;
        $userId++;
        if ($required) {
            foreach ([null, 'WRONG'] as $token) {
                $rejected = false;
                try { pretesCbtStart($pdo, $userId, $regId, null, $token, $forced); }
                catch (InvalidArgumentException $error) { $rejected = str_contains($error->getMessage(), 'Token ujian tidak valid'); }
                $check($rejected, 'Required missing/wrong token must be rejected');
                $check(!$pdo->inTransaction(), 'Rejected token must roll back transaction');
            }
            $attempt = pretesCbtStart($pdo, $userId, $regId, null, ' abc123 ', $forced)['attempt'];
            $check(!empty($attempt['questions']), 'Correct required token permits starting');
        } else {
            $attempt = pretesCbtStart($pdo, $userId, $regId, null, null, $forced)['attempt'];
            $check(!empty($attempt['questions']), 'Unchecked token checkbox permits starting without token');
            $userId++;
            $attempt = pretesCbtStart($pdo, $userId, $regId, null, 'STALE-WRONG-TOKEN', $forced)['attempt'];
            $check(!empty($attempt['questions']), 'Optional stale token must not block starting');
        }
        echo 'PASS: ' . $mode . ', token_required=' . (int)$required . "\n";
    }
}
foreach (['pages/pretes-ujian.php', 'pages/tryout.php'] as $file) {
    $source = file_get_contents(__DIR__ . '/../' . $file);
    $check(str_contains($source, 'window.TOKEN_REQUIRED ='), 'Both entry pages must publish the flag read by the CBT client');
    $check(!str_contains($source, 'const TOKEN_REQUIRED ='), 'A lexical const must not replace the window property');
}
echo "PASS: checkbox persistence, optional/required tokens, official/tryout starts, rollback and entry-page flags\n";
