<?php
/**
 * LPPAI Corner - Download Template Import Pengambilan Sertifikat
 * Format: Excel (.xlsx) dengan Fallback CSV otomatis
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$format = strtolower($_GET['format'] ?? 'xlsx');

// Cari autoload.php untuk PhpSpreadsheet
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

// Jika request format CSV atau PhpSpreadsheet tidak tersedia, kirim CSV murni (100% kompatibel)
if ($format === 'csv' || !$autoloaded || !class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    $filename = 'Template_Import_Pengambilan_Sertifikat.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Output UTF-8 BOM untuk Excel
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, ['NIM', 'Nomor Sertifikat', 'Tanggal Pengambilan', 'Nama Penerima', 'Catatan']);
    fputcsv($output, ['210101001', '001/LPPAI/SERT/2026', date('Y-m-d'), 'Ahmad Fauzi', 'Diambil langsung di Loket TU']);
    fputcsv($output, ['210101002', '', date('Y-m-d'), '', 'Sertifikat diambil']);
    fputcsv($output, ['210101003', '003/LPPAI/SERT/2026', '', '', '']);
    fclose($output);
    exit;
}

// Jika PhpSpreadsheet tersedia, generate file XLSX bergaya profesional
try {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Data Pengambilan');

    // Set Header Kolom
    $headers = [
        'A1' => 'NIM',
        'B1' => 'Nomor Sertifikat',
        'C1' => 'Tanggal Pengambilan (YYYY-MM-DD)',
        'D1' => 'Nama Penerima (Opsional)',
        'E1' => 'Catatan / Keterangan (Opsional)'
    ];

    foreach ($headers as $cell => $val) {
        $sheet->setCellValue($cell, $val);
    }

    // Styling Header
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['argb' => 'FF1A5632'] // Hijau LPPAI
        ],
        'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFCBD5E1']]],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER]
    ];
    $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);
    $sheet->getRowDimension(1)->setRowHeight(28);

    // Format Kolom A & B sebagai Text agar angka NIM/No Sertifikat tidak rusak
    $sheet->getStyle('A')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
    $sheet->getStyle('B')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

    // Contoh Data Baris 2 & 3
    $sheet->setCellValueExplicit('A2', '210101001', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('B2', '001/LPPAI/SERT/2026', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('C2', date('Y-m-d'));
    $sheet->setCellValue('D2', 'Ahmad Fauzi');
    $sheet->setCellValue('E2', 'Diambil langsung di Loket TU');

    $sheet->setCellValueExplicit('A3', '210101002', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('B3', '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('C3', date('Y-m-d'));
    $sheet->setCellValue('D3', '');
    $sheet->setCellValue('E3', 'Nomor sertifikat otomatis dicari sistem');

    $sheet->getStyle('A2:E3')->getFont()->setItalic(true)->getColor()->setARGB('FF475569');

    // Auto fit column width
    foreach (range('A', 'E') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $filename = 'Template_Import_Pengambilan_Sertifikat.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . urlencode($filename) . '"');
    header('Cache-Control: max-age=0');

    if (ob_get_length()) ob_clean();
    $writer->save('php://output');
    exit;
} catch (Exception $e) {
    // Fallback ke CSV jika terjadi error rendering Excel
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Template_Import_Pengambilan_Sertifikat.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, ['NIM', 'Nomor Sertifikat', 'Tanggal Pengambilan', 'Nama Penerima', 'Catatan']);
    fputcsv($output, ['210101001', '001/LPPAI/SERT/2026', date('Y-m-d'), 'Ahmad Fauzi', 'Diambil sendiri']);
    fclose($output);
    exit;
}
