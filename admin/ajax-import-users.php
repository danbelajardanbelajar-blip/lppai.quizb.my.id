<?php
/**
 * LPPAI Corner - Import Users dari Excel
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');
error_reporting(E_ALL);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;

header('Content-Type: application/json');

try {
set_time_limit(120);
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method tidak valid.']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!verifyCsrf($token)) {
    echo json_encode(['success' => false, 'message' => 'Sesi tidak valid.']);
    exit;
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File tidak ditemukan atau gagal diupload.']);
    exit;
}

$file = $_FILES['csv_file'];

// Validasi ekstensi
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'xls'])) {
    echo json_encode(['success' => false, 'message' => 'File harus berformat Excel (.xlsx atau .xls).']);
    exit;
}

// Validasi ukuran maksimal 5MB
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'Ukuran file maksimal 5MB.']);
    exit;
}

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
    echo json_encode(['success' => false, 'message' => 'PhpSpreadsheet tidak ditemukan. Paths dicoba: ' . implode(', ', $autoloadPaths)]);
    exit;
}


try {
    if ($ext === 'xlsx') {
        $reader = new XlsxReader();
    } else {
        $reader = new XlsReader();
    }
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($file['tmp_name']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Gagal membaca file Excel: ' . $e->getMessage()]);
    exit;
}

$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray(null, true, true, false);

if (empty($rows)) {
    echo json_encode(['success' => false, 'message' => 'File Excel kosong.']);
    exit;
}

// Baris pertama = header, normalize
$headerRow = array_map(fn($h) => strtolower(trim(str_replace([' (ddmmyyyy)', ' (mahasiswa/admin)', 'no. '], ['', '', 'no_'], (string)$h))), $rows[0]);
// Mapping lebih fleksibel
$colMap = [];
foreach ($headerRow as $idx => $h) {
    if (str_contains($h, 'nim')) $colMap['nim'] = $idx;
    elseif (str_contains($h, 'nama')) $colMap['nama_lengkap'] = $idx;
    elseif (str_contains($h, 'tempat')) $colMap['tempat_lahir'] = $idx;
    elseif (str_contains($h, 'tanggal') || str_contains($h, 'lahir') || str_contains($h, 'tgl')) $colMap['tanggal_lahir'] = $idx;
    elseif (str_contains($h, 'email')) $colMap['email'] = $idx;
    elseif (str_contains($h, 'hp') || str_contains($h, 'phone') || str_contains($h, 'telp')) $colMap['no_hp'] = $idx;
    elseif (str_contains($h, 'prodi') || str_contains($h, 'program') || str_contains($h, 'studi')) $colMap['program_studi'] = $idx;
    elseif (str_contains($h, 'tahun') || str_contains($h, 'ajaran') || str_contains($h, 'angkatan')) $colMap['tahun_ajaran'] = $idx;
    elseif (str_contains($h, 'role') || str_contains($h, 'peran')) $colMap['role'] = $idx;
}

$required = ['nim', 'nama_lengkap', 'tanggal_lahir'];
$missing = array_diff($required, array_keys($colMap));
if (!empty($missing)) {
    echo json_encode(['success' => false, 'message' => 'Kolom wajib tidak ditemukan: ' . implode(', ', $missing) . '. Pastikan menggunakan template yang benar.']);
    exit;
}

$pdo = getDBConnection();
$imported = 0;
$skipped = 0;
$errors = [];

foreach (array_slice($rows, 1) as $rowNum => $row) {
    $dataRow = $rowNum + 2; // row number in Excel (1-based + header)

    $nim = trim((string)($row[$colMap['nim']] ?? ''));
    $nama = trim((string)($row[$colMap['nama_lengkap']] ?? ''));
    $tmptLahir = trim((string)($row[$colMap['tempat_lahir'] ?? -1] ?? ''));
    $tglRaw = trim((string)($row[$colMap['tanggal_lahir']] ?? ''));
    $email = trim((string)($row[$colMap['email'] ?? -1] ?? ''));
    $noHp = trim((string)($row[$colMap['no_hp'] ?? -1] ?? ''));
    $prodi = trim((string)($row[$colMap['program_studi'] ?? -1] ?? ''));
    $ta = trim((string)($row[$colMap['tahun_ajaran'] ?? -1] ?? ''));
    $roleVal = strtolower(trim((string)($row[$colMap['role'] ?? -1] ?? '')));
    $role = in_array($roleVal, ['admin', 'mahasiswa', 'dosen']) ? $roleVal : 'mahasiswa';

    // Skip baris kosong
    if (empty($nim) && empty($nama)) continue;

    // Validasi wajib
    if (empty($nim) || empty($nama) || empty($tglRaw)) {
        $errors[] = "Baris $dataRow: nim, nama_lengkap, tanggal_lahir wajib diisi.";
        $skipped++;
        continue;
    }

    // Parse tanggal lahir - berbagai format
    $dt = false;
    // Jika angka (Excel serial date)
    if (is_numeric($tglRaw) && strlen($tglRaw) != 8) {
        // Excel date serial
        $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$tglRaw);
    } else {
        foreach (['dmY', 'd/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd-m-y', 'dmy'] as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $tglRaw);
            if ($dt) break;
        }
    }

    if (!$dt) {
        $errors[] = "Baris $dataRow: format tanggal_lahir '$tglRaw' tidak dikenali (gunakan ddmmyyyy, contoh: 01031990).";
        $skipped++;
        continue;
    }

    $tglLahirDB = $dt->format('Y-m-d');
    $passwordRaw = $dt->format('dmY'); // ddmmyyyy
    $username = $nim;

    // Cek duplikat
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$username]);
    if ($check->fetch()) {
        $errors[] = "Baris $dataRow: NIM '$nim' sudah terdaftar, dilewati.";
        $skipped++;
        continue;
    }

    // Insert (cost 8 agar lebih cepat saat import bulk)
    $hash = password_hash($passwordRaw, PASSWORD_BCRYPT, ['cost' => 8]);
    $stmt = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nim, tempat_lahir, email, no_hp, program_studi, tanggal_lahir, tahun_ajaran, role) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$username, $hash, $nama, $nim, $tmptLahir, $email, $noHp, $prodi, $tglLahirDB, $ta, $role]);
    $imported++;
}

$message = "$imported pengguna berhasil diimport.";
if ($skipped > 0) $message .= " $skipped baris dilewati.";

    echo json_encode([
        'success'  => true,
        'message'  => $message,
        'imported' => $imported,
        'skipped'  => $skipped,
        'errors'   => $errors,
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']',
    ]);
}
exit;
