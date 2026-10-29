<?php
/**
 * LPPAI Corner - Unduh Template Format Import Excel Akun E-Ujian
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
    $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php',
    $_SERVER['DOCUMENT_ROOT'] . '/../vendor/autoload.php',
    '/public_html/vendor/autoload.php',
];

$hasSpreadsheet = false;
foreach ($autoloadPaths as $p) {
    if (file_exists($p)) {
        require_once $p;
        if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $hasSpreadsheet = true;
            break;
        }
    }
}

$headers = ['NOMOR PESERTA', 'NAMA PESERTA', 'KELOMPOK', 'EMAIL (BILA ADA)', 'KODE AKSES (BILA PERLU)', 'TAGS (BILA PERLU)', 'NO WA', 'USERNAME LOGIN'];
$sampleRows = [
    ['24042062', 'ZANNUBA INAYAH', 'Jumat', '', '7402', '', '', '24042062.22205'],
    ['24042038', 'ZAHWA FATHMA AZZAHRA', 'Jumat', '', '7479', '', '', '24042038.22205'],
    ['24062011', 'VERONIKA INDRIS SETYOWATI', 'Jumat', '', '5839', '', '', '24062011.22205'],
    ['24041001', 'AHMAD FAUZI', 'Senin', '', '3190', '', '', '24041001.22205'],
    ['24041002', 'SITI AISYAH', 'Selasa', '', '4821', '', '', '24041002.22205'],
];

if ($hasSpreadsheet) {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Data Peserta E-Ujian');

    // Header styling
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => '0284C7']
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
        ]
    ];

    $colLetter = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($colLetter . '1', $h);
        $colLetter++;
    }
    $sheet->getStyle('A1:H1')->applyFromArray($headerStyle);
    $sheet->getRowDimension(1)->setRowHeight(28);

    // Data rows
    $rowIdx = 2;
    foreach ($sampleRows as $row) {
        $cLetter = 'A';
        foreach ($row as $v) {
            $sheet->setCellValueExplicit($cLetter . $rowIdx, $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $cLetter++;
        }
        $rowIdx++;
    }

    // Auto column width
    foreach (range('A', 'H') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Template_Import_Akun_EUjian.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
} else {
    // Fallback CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Template_Import_Akun_EUjian.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($out, $headers);
    foreach ($sampleRows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}
