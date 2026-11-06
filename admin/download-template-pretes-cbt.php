<?php
/**
 * LPPAI Corner - Unduh Template CSV Import Nilai Hasil CBT Pretes
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$filename = 'template_import_cbt_pretes_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// Tulis BOM UTF-8 agar kompatibel langsung dengan Microsoft Excel di Windows
fputs($out, "\xEF\xBB\xBF");

// Header Kolom Sesuai Format CBT
fputcsv($out, ['No.', 'Nomor Induk', 'Nama', 'Kelas', 'Nilai', 'Keterangan']);

// Contoh Data Sampel
fputcsv($out, ['1', '24042061', 'AHMAD NASIK MAULAL HAQQI', 'Jumat', '96', 'Lulus']);
fputcsv($out, ['2', '24081002', "ABDUL AZIZ ASY'ARI", 'Kamis', '0', 'Tidak Lulus']);
fputcsv($out, ['3', '24032035', 'AFIYATUS SHOLICHAH', 'Senin', '54', 'Tidak Lulus']);
fputcsv($out, ['4', '24052007', 'ATHIYAH', 'Senin', '92', 'Lulus']);
fputcsv($out, ['5', '24062011', 'VERONIKA INDRIS SETYOWATI', 'Jumat', '100', 'Lulus']);

fclose($out);
exit;
