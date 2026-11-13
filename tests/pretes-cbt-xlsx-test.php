<?php
require __DIR__ . '/../includes/pretes-cbt.php';
require __DIR__ . '/../includes/pretes-cbt-xlsx.php';
function xlsxAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function xlsxReject(callable $fn): void { try { $fn(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException('Unsafe Excel input accepted'); }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensurePretesCbtTables($pdo);
$template = cbtReadQuestionXlsx(__DIR__ . '/../downloads/template-bank-soal-cbt.xlsx');
xlsxAssert(count($template) === 2 && $template[0]['cells'] === CBT_XLSX_HEADERS, 'Real template headers and example');
$result = cbtImportQuestions($pdo, $template, false);
xlsxAssert($result['skipped'] === 1 && !$result['errors'], 'Template sample does not duplicate initial bank');
$export = cbtWriteXlsx(cbtBankExportRows($pdo), 'Bank Soal', [24,22,60,28,28,28,28,28,12,16]);
try {
    $roundTrip = cbtReadQuestionXlsx($export);
    xlsxAssert(count($roundTrip) === 41, 'Export all 40 questions');
    $result = cbtImportQuestions($pdo, $roundTrip, false);
    xlsxAssert($result['skipped'] === 40 && $result['added'] === 0, 'Reimport does not duplicate existing bank');
    $attempt = pretesCbtStart($pdo, 7, 42, 1000)['attempt'];
    $snapshot = $pdo->query('SELECT * FROM pretes_cbt_items WHERE attempt_id = ' . $attempt['id'] . ' ORDER BY position')->fetchAll(PDO::FETCH_ASSOC);
    $code = $pdo->query('SELECT code FROM pretes_cbt_questions WHERE id = ' . $snapshot[0]['question_id'])->fetchColumn();
    foreach ($roundTrip as &$row) if (($row['cells'][0] ?? '') === $code) { $row['cells'][2] .= ' (diubah melalui Excel)'; $row['cells'][8] = 'E'; }
    unset($row);
    $result = cbtImportQuestions($pdo, $roundTrip, true);
    xlsxAssert($result['updated'] === 40 && !$result['errors'], 'Explicit overwrite updates matching codes');
    xlsxAssert(pretesCbtState($pdo, 7, 42, 1100)['attempt']['questions'] === $attempt['questions'], 'Excel updates do not mutate active exam snapshot');
    $new = ['row' => 2, 'cells' => ['excel-001','طهارة','Pertanyaan Unicode & <teks> baru?','=1+1','+cmd','@nama','-3','نعم','a','Nonaktif']];
    $rows = [$template[0], $new];
    $result = cbtImportQuestions($pdo, $rows, false);
    xlsxAssert($result['added'] === 1 && !$result['errors'], 'Unicode text, literal formula-like text, and lowercase key');
    $invalid = $new; $invalid['row'] = 3; $invalid['cells'][0] = 'excel-bad'; $invalid['cells'][2] = 'Invalid question'; $invalid['cells'][8] = 'F';
    $valid = $new; $valid['cells'][0] = 'excel-valid'; $valid['cells'][2] = 'Valid new question';
    $before = (int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_questions')->fetchColumn();
    $result = cbtImportQuestions($pdo, [$template[0], $valid, $invalid], false);
    xlsxAssert($result['added'] === 0 && str_contains($result['errors'][0], 'Baris 3'), 'Invalid batch reports physical row and does not partly save');
    xlsxAssert((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_questions')->fetchColumn() === $before, 'Atomic validation');
    $duplicate = $valid; $duplicate['row'] = 3;
    xlsxAssert((bool)cbtImportQuestions($pdo, [$template[0], $valid, $duplicate], false)['errors'], 'Duplicates inside file rejected');
    xlsxReject(fn() => cbtImportQuestions($pdo, [['row'=>1,'cells'=>['Wrong Header']], $new], false));
    $zip = new ZipArchive(); $zip->open($export);
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $xml = preg_replace('/(<c r="C2"[^>]*>)/', '$1<f>1+1</f>', $xml, 1);
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml); $zip->close();
    xlsxReject(fn() => cbtReadQuestionXlsx($export));
    xlsxReject(fn() => cbtXlsxXml('<!DOCTYPE x [<!ENTITY x SYSTEM "file:///unavailable">]><x>&x;</x>'));
    if (isset($argv[1])) {
        if (!is_dir($argv[1])) mkdir($argv[1], 0777, true);
        $safe = cbtWriteXlsx(cbtBankExportRows($pdo), 'Bank Soal', [24,22,60,28,28,28,28,28,12,16]);
        copy($safe, $argv[1] . '/native-bank.xlsx'); unlink($safe);
        $pdo->exec("CREATE TABLE users (id INTEGER, nim TEXT, nama_lengkap TEXT); INSERT INTO users VALUES (7,'001234','Peserta Uji')");
        pretesCbtChange($pdo, 7, 42, 'submit', [], 1200);
        $results = cbtWriteXlsx(cbtResultsExportRows($pdo), 'Hasil CBT', [20,34,24,24,18,18,16,14]);
        copy($results, $argv[1] . '/native-results.xlsx'); unlink($results);
    }
    echo "PASS: actual XLSX template, export/reimport, explicit updates, duplicate protection, atomic validation, Unicode, snapshot safety, and formula/entity rejection.\n";
} finally { unlink($export); }
