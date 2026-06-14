<?php
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();
$stmt = $pdo->query("UPDATE tutorial_registrations SET tahun_ajaran = '2025-2026' WHERE tahun_ajaran IS NULL OR tahun_ajaran = ''");
echo "Updated " . $stmt->rowCount() . " rows to 2025-2026.";
