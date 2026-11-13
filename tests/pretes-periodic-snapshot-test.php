<?php
require __DIR__ . '/../includes/pretes-helper.php';
function snapshotAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensurePretesProctoringTables($pdo);
snapshotAssert(pretesPeriodicSnapshotNeeded($pdo, 42, true), 'First successful photo should be retained');
$insert = $pdo->prepare("INSERT INTO pretes_proctoring_logs (registration_id,user_id,event_type,status,snapshot_path) VALUES (42,7,?,'MATCH',?)");
$insert->execute(['periodic_check', null]);
snapshotAssert(pretesPeriodicSnapshotNeeded($pdo, 42, true), 'Metadata-only entries must not suppress photo retention');
$insert->execute(['initial_checkin', 'uploads/pretes_proctoring/checkin.jpg']);
snapshotAssert(!pretesPeriodicSnapshotNeeded($pdo, 42, true), 'Reuse recent successful audit photo for five minutes');
snapshotAssert(pretesPeriodicSnapshotNeeded($pdo, 43, true), 'Retention must be isolated per registration');
snapshotAssert(pretesPeriodicSnapshotNeeded($pdo, 42, false), 'Non-matching check always retains evidence');
$pdo->exec("UPDATE pretes_proctoring_logs SET created_at=datetime('now','-6 minutes')");
snapshotAssert(pretesPeriodicSnapshotNeeded($pdo, 42, true), 'A new successful photo must be kept after cooldown');
echo "PASS: periodic photo retention, metadata-only logs, registration isolation, and non-matching evidence.\n";
