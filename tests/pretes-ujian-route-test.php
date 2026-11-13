<?php
// Verify actual page dispatch occurs before rendering/auth includes, using a stub API.
$root = sys_get_temp_dir() . '/lppai-face-route-' . bin2hex(random_bytes(6));
mkdir($root);
mkdir($root . '/pages');
mkdir($root . '/api');
mkdir($root . '/admin');
file_put_contents($root . '/admin/pretes-bank-soal.php', '<?php echo json_encode(["bank_dispatched" => true]);');
mkdir($root . '/assets');
mkdir($root . '/assets/js');
file_put_contents($root . '/assets/js/pretes-cbt.js', 'window.PretesCbt = {};');
copy(__DIR__ . '/../pages/pretes-ujian.php', $root . '/pages/pretes-ujian.php');
file_put_contents($root . '/api/pretes-proctoring-check.php', '<?php echo json_encode(["api_dispatched" => true]);');
file_put_contents($root . '/api/pretes-cbt.php', '<?php echo json_encode(["cbt_dispatched" => true]);');
try {
    $code = '<?php $_GET["face_api"] = "1"; require ' . var_export($root . '/pages/pretes-ujian.php', true) . ';';
    file_put_contents($root . '/run.php', $code);
    $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($root . '/run.php');
    exec($command, $output, $status);
    $data = json_decode(implode("\n", $output), true);
    if ($status !== 0 || ($data['api_dispatched'] ?? false) !== true) {
        throw new RuntimeException('Same-page API must dispatch before HTML and normal page initialization.');
    }
    file_put_contents($root . '/run.php', '<?php $_GET["cbt_api"] = "1"; require ' . var_export($root . '/pages/pretes-ujian.php', true) . ';');
    $output = [];
    exec($command, $output, $status);
    $data = json_decode(implode("\n", $output), true);
    if ($status !== 0 || ($data['cbt_dispatched'] ?? false) !== true) throw new RuntimeException('CBT must use the public page route.');
    file_put_contents($root . '/run.php', '<?php $_GET["cbt_asset"] = "1"; require ' . var_export($root . '/pages/pretes-ujian.php', true) . ';');
    $output = [];
    exec($command, $output, $status);
    if ($status !== 0 || implode('', $output) !== 'window.PretesCbt = {};') throw new RuntimeException('CBT JavaScript must be served through the public page route.');
    file_put_contents($root . '/run.php', '<?php $_GET["cbt_bank"] = "1"; require ' . var_export($root . '/pages/pretes-ujian.php', true) . ';');
    $output = [];
    exec($command, $output, $status);
    $data = json_decode(implode('', $output), true);
    if ($status !== 0 || ($data['bank_dispatched'] ?? false) !== true) throw new RuntimeException('Bank admin must use the public page route.');
    echo "PASS: public exam page dispatches face API without HTML or duplicate initialization.\n";
} finally {
    if (is_file($root . '/run.php')) unlink($root . '/run.php');
    unlink($root . '/pages/pretes-ujian.php');
    unlink($root . '/api/pretes-proctoring-check.php');
    unlink($root . '/api/pretes-cbt.php');
    unlink($root . '/admin/pretes-bank-soal.php');
    rmdir($root . '/admin');
    unlink($root . '/assets/js/pretes-cbt.js');
    rmdir($root . '/assets/js');
    rmdir($root . '/assets');
    rmdir($root . '/pages');
    rmdir($root . '/api');
    rmdir($root);
}
