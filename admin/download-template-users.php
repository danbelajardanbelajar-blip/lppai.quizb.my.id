<?php
/**
 * LPPAI Corner - Download Template Import User (Excel)
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
        echo json_encode(['success' => false, 'message' => 'Fatal Error: ' . $err['message'] . ' di ' . $err['file'] . ' baris ' . $err['line']]);
    }
});

set_exception_handler(function($e) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

// Cari autoload.php di berbagai kemungkinan path
$autoloadPaths = [
    '/public_html/vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
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
    header('Content-Type: application/json');
    echo json_encode(['error' => 'PhpSpreadsheet tidak ditemukan. Paths dicoba: ' . implode(', ', $autoloadPaths)]);
    exit;
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Import Pengguna');

// === HEADER ROW ===
$headers = ['NIM', 'Nama Lengkap', 'Tempat Lahir', 'Tanggal Lahir (ddmmyyyy)', 'Email', 'No. HP', 'Program Studi', 'Role (mahasiswa/admin)'];
foreach ($headers as $col => $title) {
    $cell = chr(65 + $col) . '1';
    $sheet->setCellValue($cell, $title);
}

// Style header
$sheet->getStyle('A1:H1')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1a5632']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);

// === CONTOH DATA ===
$sheet->setCellValue('A2', '2024010006');
$sheet->setCellValue('B2', 'Contoh Mahasiswa');
$sheet->setCellValue('C2', 'Jakarta');
$sheet->setCellValue('D2', '01031990');
$sheet->setCellValue('E2', 'contoh@mail.com');
$sheet->setCellValue('F2', '081234567895');
$sheet->setCellValue('G2', 'Teknik Informatika');
$sheet->setCellValue('H2', 'mahasiswa');

$sheet->setCellValue('A3', '2024010007');
$sheet->setCellValue('B3', 'Contoh Kedua');
$sheet->setCellValue('C3', 'Bandung');
$sheet->setCellValue('D3', '15051995');
$sheet->setCellValue('E3', 'contoh2@mail.com');
$sheet->setCellValue('F3', '081234567896');
$sheet->setCellValue('G3', 'Manajemen');
$sheet->setCellValue('H3', 'mahasiswa');

// Style data rows
$sheet->getStyle('A2:H3')->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);
$sheet->getStyle('A2:H3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('f0fff4');

// Auto width kolom
foreach (range('A', 'H') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// === SHEET PETUNJUK ===
$info = $spreadsheet->createSheet();
$info->setTitle('Petunjuk');
$info->setCellValue('A1', 'PETUNJUK PENGISIAN');
$info->setCellValue('A3', 'Kolom');
$info->setCellValue('B3', 'Keterangan');
$petunjuk = [
    ['NIM', 'Nomor Induk Mahasiswa - wajib diisi, akan digunakan sebagai username login'],
    ['Nama Lengkap', 'Nama lengkap mahasiswa - wajib diisi'],
    ['Tanggal Lahir (ddmmyyyy)', 'Format: ddmmyyyy, contoh: 01031990 untuk 1 Maret 1990 - wajib diisi, digunakan sebagai password'],
    ['Email', 'Alamat email (opsional)'],
    ['No. HP', 'Nomor handphone (opsional)'],
    ['Program Studi', 'Program studi mahasiswa (opsional)'],
    ['Role', 'Isi: mahasiswa atau admin (default: mahasiswa)'],
];
foreach ($petunjuk as $i => $row) {
    $info->setCellValue('A' . ($i + 4), $row[0]);
    $info->setCellValue('B' . ($i + 4), $row[1]);
}
$info->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$info->getStyle('A3:B3')->getFont()->setBold(true);
$info->getColumnDimension('A')->setWidth(35);
$info->getColumnDimension('B')->setWidth(70);

$spreadsheet->setActiveSheetIndex(0);

if (ob_get_length()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="template_import_pengguna.xlsx"');
header('Cache-Control: no-cache');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
