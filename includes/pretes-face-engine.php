<?php
/** Server-authoritative face recognition bridge. Never trust a browser match score. */
function pretesFaceEngineConfig(): array {
    // Shared hosting may not expose PHP-FPM environment settings. This private,
    // ignored PHP file is copied over SSH; it must never be committed to Git.
    $local = [];
    $path = __DIR__ . '/../config/pretes-face.local.php';
    if (is_file($path)) {
        $settings = require $path;
        if (is_array($settings)) $local = $settings;
    }
    return [
        'url' => rtrim((string)(getenv('PRETES_FACE_ENGINE_URL') ?: ($local['url'] ?? '')), '/'),
        'token' => (string)(getenv('PRETES_FACE_ENGINE_TOKEN') ?: ($local['token'] ?? '')),
    ];
}

function pretesFaceEngineError(string $message): array {
    return ['match' => false, 'similarity' => 0.0, 'status' => 'ERROR', 'reason' => $message];
}

function ensurePretesFaceChallengeTable(PDO $pdo): void {
    try {
        $pdo->query('SELECT 1 FROM pretes_face_challenges LIMIT 0');
    } catch (PDOException $e) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS pretes_face_challenges (
            id VARCHAR(64) PRIMARY KEY, registration_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            direction VARCHAR(5) NOT NULL, issued_at INTEGER NOT NULL, expires_at INTEGER NOT NULL,
            consumed_at INTEGER DEFAULT NULL
        )');
    }
}

function pretesFaceChallenge(PDO $pdo, int $registrationId, int $userId): array {
    ensurePretesFaceChallengeTable($pdo);
    $challenge = [
        'id' => bin2hex(random_bytes(24)),
        'registration_id' => $registrationId,
        'direction' => random_int(0, 1) ? 'left' : 'right',
        'issued_at' => time(),
        'expires_at' => time() + 90,
    ];
    $pdo->prepare('DELETE FROM pretes_face_challenges WHERE user_id = ? OR expires_at < ?')->execute([$userId, time()]);
    $pdo->prepare('INSERT INTO pretes_face_challenges (id, registration_id, user_id, direction, issued_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$challenge['id'], $registrationId, $userId, $challenge['direction'], $challenge['issued_at'], $challenge['expires_at']]);
    return $challenge;
}

function pretesConsumeFaceChallenge(PDO $pdo, int $registrationId, int $userId, string $id): ?array {
    ensurePretesFaceChallengeTable($pdo);
    // Atomic consumption prevents concurrent requests replaying the same challenge.
    $stmt = $pdo->prepare('UPDATE pretes_face_challenges SET consumed_at = ? WHERE id = ? AND registration_id = ? AND user_id = ? AND consumed_at IS NULL AND expires_at >= ? AND issued_at <= ?');
    $stmt->execute([time(), $id, $registrationId, $userId, time(), time() - 3]);
    if ($stmt->rowCount() !== 1) return null;
    $stmt = $pdo->prepare('SELECT * FROM pretes_face_challenges WHERE id = ?');
    $stmt->execute([$id]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);
    return $challenge;
}

function comparePretesFaces(string $referencePath, array $frames, ?array $challenge = null): array {
    $config = pretesFaceEngineConfig();
    if ($config['url'] === '' || strlen($config['token']) < 32) {
        return pretesFaceEngineError('Mesin verifikasi wajah belum dikonfigurasi oleh admin. Ujian belum dapat dibuka.');
    }
    $parts = parse_url($config['url']);
    $local = in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true);
    if (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && $local)) {
        return pretesFaceEngineError('Koneksi mesin verifikasi harus memakai HTTPS.');
    }
    if (!function_exists('curl_init')) return pretesFaceEngineError('Ekstensi cURL server belum tersedia.');
    $base = realpath(__DIR__ . '/../uploads');
    $reference = realpath(__DIR__ . '/../' . ltrim(str_replace('\\', '/', $referencePath), '/'));
    if (!$base || !$reference || strpos($reference, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($reference)) {
        return pretesFaceEngineError('Pasfoto pendaftaran tidak ditemukan. Perbarui pasfoto Anda.');
    }
    if (filesize($reference) > 8 * 1024 * 1024) return pretesFaceEngineError('Pasfoto terlalu besar. Unggah ulang foto maksimal 8 MB.');
    $expected = $challenge ? 3 : 1;
    if (count($frames) !== $expected) return pretesFaceEngineError('Jumlah foto tantangan tidak sesuai. Silakan ulangi.');
    foreach ($frames as $frame) {
        if (!is_string($frame) || strlen($frame) > 700000 || !preg_match('#^data:image/jpeg;base64,#', $frame)) {
            return pretesFaceEngineError('Foto kamera tidak valid atau terlalu besar.');
        }
    }
    $payload = json_encode([
        'reference' => base64_encode(file_get_contents($reference)),
        'frames' => $frames,
        'direction' => $challenge['direction'] ?? null,
    ], JSON_THROW_ON_ERROR);
    $curl = curl_init($config['url'] . '/verify');
    $body = '';
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $config['token']],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$body) {
            if (strlen($body) + strlen($chunk) > 32768) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($ok === false || $http !== 200) {
        error_log('Pretes face engine unavailable, HTTP ' . $http);
        return pretesFaceEngineError('Mesin verifikasi sedang tidak tersedia. Silakan coba lagi atau hubungi admin.');
    }
    $result = json_decode($body, true);
    if (!is_array($result) || ($result['engine'] ?? '') !== 'opencv-sface-v1'
        || !is_bool($result['match'] ?? null) || !is_numeric($result['similarity'] ?? null)
        || !is_string($result['reason'] ?? null) || !is_string($result['status'] ?? null)
        || !is_finite((float)$result['similarity'])
        || (float)$result['similarity'] < -100 || (float)$result['similarity'] > 100
        || ($result['match'] && (($result['status'] ?? '') !== 'MATCH' || ($challenge && ($result['motion_passed'] ?? false) !== true)))) {
        return pretesFaceEngineError('Respons mesin verifikasi tidak valid. Akses ujian tetap terkunci.');
    }
    return $result;
}
