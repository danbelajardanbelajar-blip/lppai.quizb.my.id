<?php
// Verify actual page dispatch occurs before rendering/auth includes, using a stub API.
$root = sys_get_temp_dir() . '/lppai-face-route-' . bin2hex(random_bytes(6));
mkdir($root);
mkdir($root . '/pages');
mkdir($root . '/api');
copy(__DIR__ . '/../pages/pretes-ujian.php', $root . '/pages/pretes-ujian.php');
file_put_contents($root . '/api/pretes-proctoring-check.php', '<?php echo json_encode(["api_dispatched" => true]);');
try {
    $code = '<?php $_GET["face_api"] = "1"; require ' . var_export($root . '/pages/pretes-ujian.php', true) . ';';
    file_put_contents($root . '/run.php', $code);
    $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($root . '/run.php');
    exec($command, $output, $status);
    $data = json_decode(implode("\n", $output), true);
    if ($status !== 0 || ($data['api_dispatched'] ?? false) !== true) {
        throw new RuntimeException('Same-page API must dispatch before HTML and normal page initialization.');
    }
    echo "PASS: public exam page dispatches face API without HTML or duplicate initialization.\n";
} finally {
    if (is_file($root . '/run.php')) unlink($root . '/run.php');
    unlink($root . '/pages/pretes-ujian.php');
    unlink($root . '/api/pretes-proctoring-check.php');
    rmdir($root . '/pages');
    rmdir($root . '/api');
    rmdir($root);
}
