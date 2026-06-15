<?php
/**
 * LPPAI Corner - Download Template Import Nilai Excel
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');
error_reporting(E_ALL);

ob_start();

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Fatal Error: ' . $err['message']]);
    }
});

require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

// Cari autoload.php
$autoloadPaths = [
    '/public_html/vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
    $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php',
    $_SERVER['DOCUMENT_ROOT'] . '/../vendor/autoload.php'
];
$autoloaded = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloaded = true;
        break;
    }
}
if (!$autoloaded) {
    die("PhpSpreadsheet tidak ditemukan di server.");
}

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set Headers
$headers = [
    'A1' => 'NIM',
    'B1' => 'Nama Mahasiswa (Opsional)',
    'C1' => 'Tanggal Lahir (Opsional)',
    'D1' => 'Tahun Ajaran (Misal: 2025-2026)',
    'E1' => 'Thaharah',
    'F1' => 'Shalat',
    'G1' => 'Srt Pendek',
    'H1' => 'Amaliyah',
    'I1' => 'Jenazah',
    'J1' => 'Nilai Akhir'
];

foreach ($headers as $cell => $val) {
    $sheet->setCellValue($cell, $val);
}

// Styling headers
$styleArray = [
    'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
    'fill' => [
        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
        'startColor' => ['argb' => 'FF3B82F6']
    ],
    'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]],
    'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
];
$sheet->getStyle('A1:J1')->applyFromArray($styleArray);

// Auto width
foreach(range('A','J') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Add some dummy data to row 2 as an example (but keep it simple or empty)
$sheet->setCellValue('A2', '2024010001');
$sheet->setCellValue('B2', 'Ahmad Fauzi');
$sheet->setCellValue('C2', '2000-12-31');
$sheet->setCellValue('D2', '2025-2026');
$sheet->setCellValue('E2', '80');
$sheet->setCellValue('F2', '85');
$sheet->setCellValue('G2', '90');
$sheet->setCellValue('H2', '88');
$sheet->setCellValue('I2', '75');
$sheet->setCellValue('J2', '83.6');

$sheet->getStyle('A2:J2')->getFont()->setItalic(true);
$sheet->getStyle('A2:J2')->getFont()->getColor()->setARGB('FF666666');

$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$filename = 'Template_Import_Nilai_Master.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.urlencode($filename).'"');
header('Cache-Control: max-age=0');

ob_clean(); // clean output buffer to avoid corruption
$writer->save('php://output');
exit;
