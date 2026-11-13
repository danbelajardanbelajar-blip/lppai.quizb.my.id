<?php
// Exercise private configuration in an isolated directory, never production config.
$root = sys_get_temp_dir() . '/lppai-face-config-' . bin2hex(random_bytes(6));
mkdir($root);
mkdir($root . '/includes');
mkdir($root . '/config');
copy(__DIR__ . '/../includes/pretes-face-engine.php', $root . '/includes/pretes-face-engine.php');
$config = $root . '/config/pretes-face.local.php';
$originalUrl = getenv('PRETES_FACE_ENGINE_URL');
$originalToken = getenv('PRETES_FACE_ENGINE_TOKEN');
function configAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
try {
    putenv('PRETES_FACE_ENGINE_URL');
    putenv('PRETES_FACE_ENGINE_TOKEN');
    require $root . '/includes/pretes-face-engine.php';
    configAssert(pretesFaceEngineConfig() === ['url' => '', 'token' => ''], 'Absent settings must stay empty');
    file_put_contents($config, "<?php return ['url' => 'https://face.example.test/', 'token' => 'test-only-secret'];");
    ob_start();
    $settings = pretesFaceEngineConfig();
    $output = ob_get_clean();
    configAssert($output === '', 'Loading private settings must not print credentials');
    configAssert($settings === ['url' => 'https://face.example.test', 'token' => 'test-only-secret'], 'Private settings must load');
    putenv('PRETES_FACE_ENGINE_URL=https://override.example.test/');
    putenv('PRETES_FACE_ENGINE_TOKEN=override-test-only');
    configAssert(pretesFaceEngineConfig() === ['url' => 'https://override.example.test', 'token' => 'override-test-only'], 'Environment must take precedence');
    putenv('PRETES_FACE_ENGINE_URL');
    putenv('PRETES_FACE_ENGINE_TOKEN');
    file_put_contents($config, '<?php return false;');
    configAssert(pretesFaceEngineConfig() === ['url' => '', 'token' => ''], 'Malformed settings must not grant access');
    echo "PASS: private config, no secret output, environment priority, and absent/invalid settings.\n";
} finally {
    putenv($originalUrl === false ? 'PRETES_FACE_ENGINE_URL' : 'PRETES_FACE_ENGINE_URL=' . $originalUrl);
    putenv($originalToken === false ? 'PRETES_FACE_ENGINE_TOKEN' : 'PRETES_FACE_ENGINE_TOKEN=' . $originalToken);
    if (is_file($config)) unlink($config);
    unlink($root . '/includes/pretes-face-engine.php');
    rmdir($root . '/config');
    rmdir($root . '/includes');
    rmdir($root);
}
