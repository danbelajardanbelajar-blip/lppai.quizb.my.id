<?php
require_once __DIR__ . '/../includes/pretes-cbt.php';
require_once __DIR__ . '/../includes/pretes-cbt-xlsx.php';

$file = 'd:/all_project/lppai.quizb.my.id/downloads/bank-soal-pretes-177-soal.xlsx';

echo "Reading generated Excel file: $file...\n";
$rows = cbtReadQuestionXlsx($file);
echo "cbtReadQuestionXlsx successful! Read " . count($rows) . " rows (including header).\n";

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensurePretesCbtTables($pdo);

// Delete default seed questions to test clean import of all 177
$pdo->exec('DELETE FROM pretes_cbt_questions');

echo "Testing cbtImportQuestions into package 1...\n";
$res = cbtImportQuestions($pdo, $rows, false, 1);

echo "Import Result:\n";
echo " - Added: " . $res['added'] . "\n";
echo " - Updated: " . $res['updated'] . "\n";
echo " - Skipped: " . $res['skipped'] . "\n";
echo " - Errors count: " . count($res['errors']) . "\n";

if (!empty($res['errors'])) {
    echo "Errors found:\n";
    foreach (array_slice($res['errors'], 0, 10) as $e) {
        echo "   * $e\n";
    }
    exit(1);
}

assert($res['added'] === 177, "Expected exactly 177 questions added, got " . $res['added']);
assert($res['skipped'] === 0, "Expected 0 skipped");
assert(empty($res['errors']), "Expected 0 errors");

// Check category breakdown in database
$counts = $pdo->query('SELECT category, COUNT(*) as c FROM pretes_cbt_questions GROUP BY category ORDER BY category')->fetchAll(PDO::FETCH_KEY_PAIR);
echo "Category breakdown in imported database:\n";
foreach ($counts as $cat => $cnt) {
    echo " - $cat: $cnt butir\n";
}

echo "\nALL 177 QUESTIONS VALIDATED AND IMPORTED 100% SUCCESSFULLY!\n";
