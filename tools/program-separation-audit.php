<?php
declare(strict_types=1);

// No web access, connection string, production config, migration or apply switch.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/program-separation/audit.php';

if ($argc === 2 && $argv[1] === '--help') {
    echo "Usage: php tools/program-separation-audit.php --input=offline-snapshot.json\n";
    echo "Offline JSON only. Writes report to stdout; never connects to a database or applies changes.\n";
    exit(0);
}
if ($argc !== 2 || !str_starts_with($argv[1], '--input=') || substr($argv[1], 8) === '') {
    fwrite(STDERR, "Use --help. Only --input is accepted; --apply is unavailable.\n"); exit(2);
}
$path = realpath(substr($argv[1], 8));
if (!$path || !is_file($path) || !is_readable($path) || filesize($path) > 33554432) {
    fwrite(STDERR, "Input must be a readable local snapshot, at most 32 MiB.\n"); exit(2);
}
try {
    $raw = file_get_contents($path, false, null, 0, 33554433);
    if ($raw === false) throw new RuntimeException('Snapshot could not be read.');
    if (strlen($raw) > 33554432) throw new InvalidArgumentException('Snapshot is too large.');
    $snapshot = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($snapshot)) throw new InvalidArgumentException('Snapshot must be a JSON object.');
    $report = \Lppai\ProgramSeparation\Audit::inspect($snapshot);
    $report['snapshot_sha256'] = hash('sha256', $raw);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($report['issues'] ? 3 : 0);
} catch (Throwable $e) {
    // Never print input contents or connection information on validation failure.
    fwrite(STDERR, "Snapshot invalid or incomplete. Check schema and allowed columns in documentation.\n"); exit(2);
}
