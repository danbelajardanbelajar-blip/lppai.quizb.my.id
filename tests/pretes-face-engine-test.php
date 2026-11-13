<?php
require_once __DIR__ . '/../includes/pretes-face-engine.php';
function faceAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$challenge = pretesFaceChallenge($pdo, 42, 7);
faceAssert(strlen($challenge['id']) === 48, 'Cryptographic nonce required');
faceAssert(pretesConsumeFaceChallenge($pdo, 42, 7, $challenge['id']) === null, 'Instant challenge cannot pass');
$pdo->exec('UPDATE pretes_face_challenges SET issued_at = issued_at - 6');
faceAssert(pretesConsumeFaceChallenge($pdo, 42, 8, $challenge['id']) === null, 'Wrong user must not pass');
faceAssert(pretesConsumeFaceChallenge($pdo, 43, 7, $challenge['id']) === null, 'Wrong registration must not pass');
faceAssert(pretesConsumeFaceChallenge($pdo, 42, 7, 'forged') === null, 'Wrong nonce must not pass');
faceAssert(pretesConsumeFaceChallenge($pdo, 42, 7, $challenge['id']) !== null, 'Valid challenge should be consumed');
faceAssert(pretesConsumeFaceChallenge($pdo, 42, 7, $challenge['id']) === null, 'Replay must fail');
$challenge = pretesFaceChallenge($pdo, 42, 7);
$pdo->exec('UPDATE pretes_face_challenges SET issued_at = issued_at - 100, expires_at = expires_at - 100');
faceAssert(pretesConsumeFaceChallenge($pdo, 42, 7, $challenge['id']) === null, 'Expired challenge must fail');
putenv('PRETES_FACE_ENGINE_URL');
putenv('PRETES_FACE_ENGINE_TOKEN');
$result = comparePretesFaces('uploads/no-such-file.jpg', ['data:image/jpeg;base64,test']);
faceAssert($result['match'] === false && $result['status'] === 'ERROR', 'No service must fail closed');
putenv('PRETES_FACE_ENGINE_URL=http://public.example.test');
putenv('PRETES_FACE_ENGINE_TOKEN=' . str_repeat('x', 32));
$result = comparePretesFaces('uploads/no-such-file.jpg', []);
faceAssert($result['match'] === false && strpos($result['reason'], 'HTTPS') !== false, 'Public HTTP must fail');
echo "PASS: nonce, expiry, ownership, atomic replay prevention, unavailable engine, and HTTPS enforcement.\n";
