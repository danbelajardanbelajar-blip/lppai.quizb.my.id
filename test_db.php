<?php
require __DIR__ . '/includes/db.php';
$pdo = getDBConnection();

$active_gel = $pdo->query("SELECT * FROM master_gelombang ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "Active gel: " . print_r($active_gel, true) . "\n";

$stmtCount = $pdo->prepare("SELECT hari_pilihan, COUNT(*) as cnt FROM tutorial_registrations WHERE gelombang = ? GROUP BY hari_pilihan");
$stmtCount->execute([$active_gel['gelombang'] ?? '']);
$counts = $stmtCount->fetchAll(PDO::FETCH_ASSOC);
echo "Counts with gelombang '{$active_gel['gelombang']}': " . print_r($counts, true) . "\n";

$stmtCount2 = $pdo->query("SELECT hari_pilihan, COUNT(*) as cnt FROM tutorial_registrations GROUP BY hari_pilihan");
$counts2 = $stmtCount2->fetchAll(PDO::FETCH_ASSOC);
echo "Counts without gelombang: " . print_r($counts2, true) . "\n";
