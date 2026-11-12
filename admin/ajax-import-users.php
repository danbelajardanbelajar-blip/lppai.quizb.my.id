<?php
/**
 * LPPAI Corner - Import Users dari Excel (.xlsx, .xls, & SIAKAD HTML .xls)
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');
error_reporting(E_ALL);

use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use PhpOffice\PhpSpreadsheet\Reader\Html as HtmlReader;

header('Content-Type: application/json');

try {
    set_time_limit(120);
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/user-helper.php';
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
    $postDefaultJenis = trim((string)($_POST['default_jenis_program'] ?? ''));
    $defaultJenisOverride = $postDefaultJenis !== '' ? normalizeJenisProgram($postDefaultJenis) : '';
    $duplicateMode = ($_POST['duplicate_mode'] ?? 'skip') === 'update_jenis_program' ? 'update_jenis_program' : 'skip';

    // Validasi ekstensi
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'html', 'htm'], true)) {
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

    $loadHtmlSpreadsheet = static function (string $tmpPath) {
        $prevLibxml = libxml_use_internal_errors(true);
        try {
            $reader = new HtmlReader();
            $spreadsheet = $reader->load($tmpPath);
            libxml_clear_errors();
            return $spreadsheet;
        } finally {
            libxml_use_internal_errors($prevLibxml);
        }
    };

    try {
        $headSnippet = (string)file_get_contents($file['tmp_name'], false, null, 0, 512);
        $looksLikeHtml = in_array($ext, ['html', 'htm'], true)
            || (bool)preg_match('/^\s*(<!doctype|<html|<table|<meta|<style|<thead|<tbody|<tr)/i', $headSnippet);

        if ($looksLikeHtml) {
            $spreadsheet = $loadHtmlSpreadsheet($file['tmp_name']);
        } elseif ($ext === 'xlsx') {
            $reader = new XlsxReader();
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file['tmp_name']);
        } else {
            try {
                $reader = new XlsReader();
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($file['tmp_name']);
            } catch (Throwable $xlsErr) {
                // Banyak export SIAKAD berekstensi .xls tetapi berisi HTML table
                $spreadsheet = $loadHtmlSpreadsheet($file['tmp_name']);
            }
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal membaca file Excel: ' . $e->getMessage()]);
        exit;
    }

    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false);

    if (empty($rows)) {
        echo json_encode(['success' => false, 'message' => 'File Excel kosong.']);
        exit;
    }

    $detected = detectUserImportHeaderAndMap($rows);
    $headerIdx = $detected['header_row_index'];
    $colMap = $detected['col_map'];

    $required = $duplicateMode === 'update_jenis_program'
        ? ['nim', 'nama_lengkap']
        : ['nim', 'nama_lengkap', 'tanggal_lahir'];
    $missing = array_diff($required, array_keys($colMap));
    if (!empty($missing)) {
        echo json_encode([
            'success' => false,
            'message' => 'Kolom wajib tidak ditemukan: ' . implode(', ', $missing) . '. Pastikan file memiliki kolom NIM, Nama, dan Tanggal Lahir.'
        ]);
        exit;
    }

    $pdo = getDBConnection();
    ensureJenisProgramColumn($pdo);

    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];

    $checkStmt = $pdo->prepare("SELECT id, jenis_program FROM users WHERE nim = ? OR username = ? LIMIT 1");
    $updateJenisStmt = $pdo->prepare("UPDATE users SET jenis_program = ? WHERE id = ?");
    $insertStmt = $pdo->prepare(
        "INSERT INTO users (username, password, nama_lengkap, nim, tempat_lahir, email, no_hp, program_studi, jenis_program, angkatan, tanggal_lahir, role, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $activeGel = $pdo->query("SELECT gelombang FROM master_gelombang ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    $gel = $activeGel ?: 'gel1';
    $stmtReg = $pdo->prepare("INSERT INTO tutorial_registrations (user_id, status, gelombang, tahun_ajaran) VALUES (?, 'terdaftar', ?, ?)");

    foreach (array_slice($rows, $headerIdx + 1) as $rowOffset => $row) {
        $dataRow = $headerIdx + $rowOffset + 2; // nomor baris Excel 1-based

        $nim = trim((string)($row[$colMap['nim']] ?? ''));
        $nama = trim((string)($row[$colMap['nama_lengkap']] ?? ''));
        $tmptLahir = trim((string)($row[$colMap['tempat_lahir'] ?? -1] ?? ''));
        $tglRaw = trim((string)($row[$colMap['tanggal_lahir'] ?? -1] ?? ''));
        $email = trim((string)($row[$colMap['email'] ?? -1] ?? ''));
        $noHp = formatNoHp(trim((string)($row[$colMap['no_hp'] ?? -1] ?? '')));
        $prodi = trim((string)($row[$colMap['program_studi'] ?? -1] ?? ''));
        $kelasCell = trim((string)($row[$colMap['kelas'] ?? -1] ?? ''));
        $jenisCell = trim((string)($row[$colMap['jenis_program'] ?? -1] ?? ''));

        if ($jenisCell !== '') {
            $jenisProgram = normalizeJenisProgram($jenisCell);
        } elseif ($defaultJenisOverride !== '') {
            $jenisProgram = $defaultJenisOverride;
        } elseif ($kelasCell !== '' && stripos($kelasCell, 'rpl') !== false) {
            $jenisProgram = 'RPL';
        } else {
            $jenisProgram = 'Reguler';
        }

        $roleVal = strtolower(trim((string)($row[$colMap['role'] ?? -1] ?? '')));
        $role = in_array($roleVal, ['admin', 'mahasiswa', 'dosen'], true) ? $roleVal : 'mahasiswa';

        // Ekstrak angkatan dari 2 digit awal NIM
        $nimPrefix = substr($nim, 0, 2);
        $angkatan = (is_numeric($nimPrefix) && strlen($nimPrefix) === 2) ? '20' . $nimPrefix : null;

        // Skip baris kosong atau baris footer rekap tanpa NIM valid
        if ($nim === '' && $nama === '') {
            continue;
        }
        if ($nim === '' || $nama === '') {
            $errors[] = "Baris $dataRow: NIM dan nama_lengkap wajib diisi.";
            $skipped++;
            continue;
        }

        $username = $nim;

        // Cek duplikat berdasarkan nim atau username
        $checkStmt->execute([$nim, $username]);
        $existing = $checkStmt->fetch();
        if ($existing) {
            if ($duplicateMode === 'update_jenis_program') {
                if (normalizeJenisProgram($existing['jenis_program'] ?? 'Reguler') !== $jenisProgram) {
                    $updateJenisStmt->execute([$jenisProgram, $existing['id']]);
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                $errors[] = "Baris $dataRow: NIM '$nim' sudah terdaftar, dilewati.";
                $skipped++;
            }
            continue;
        }

        if ($tglRaw === '') {
            $errors[] = "Baris $dataRow: tanggal_lahir wajib diisi untuk pengguna baru (NIM '$nim').";
            $skipped++;
            continue;
        }

        // Parse tanggal lahir (mendukung serial Excel, ddmmyyyy, dd/mm/yyyy, Y-m-d, & bulan Indonesia)
        $tglLahirDB = null;
        if (is_numeric($tglRaw) && strlen($tglRaw) !== 8) {
            try {
                $dtObj = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$tglRaw);
                if ($dtObj instanceof DateTimeInterface) {
                    $tglLahirDB = $dtObj->format('Y-m-d');
                }
            } catch (Throwable $dateErr) {
                $tglLahirDB = null;
            }
        }
        if ($tglLahirDB === null) {
            $tglLahirDB = parseFlexibleDateToYmd($tglRaw);
        }

        if ($tglLahirDB === null) {
            $errors[] = "Baris $dataRow: format tanggal_lahir '$tglRaw' tidak dikenali (gunakan dd/mm/yyyy, ddmmyyyy, atau format tanggal Indonesia).";
            $skipped++;
            continue;
        }

        $dtPassword = DateTimeImmutable::createFromFormat('Y-m-d', $tglLahirDB);
        $passwordRaw = $dtPassword ? $dtPassword->format('dmY') : '12345678';

        // Insert (cost 8 agar lebih cepat saat import bulk)
        $hash = password_hash($passwordRaw, PASSWORD_BCRYPT, ['cost' => 8]);
        $isActive = ($role !== 'mahasiswa' || $nimPrefix === '24' || $angkatan === '2024') ? 1 : 0;
        $insertStmt->execute([
            $username,
            $hash,
            $nama,
            $nim,
            $tmptLahir,
            $email,
            $noHp,
            $prodi,
            $jenisProgram,
            $angkatan,
            $tglLahirDB,
            $role,
            $isActive,
        ]);

        $userId = $pdo->lastInsertId();

        // Jika angkatan berhasil diekstrak dan dia mahasiswa, langsung daftarkan ke tutorial
        if (!empty($angkatan) && $role === 'mahasiswa') {
            $stmtReg->execute([$userId, $gel, $angkatan]);
        }
        $imported++;
    }

    $parts = [];
    if ($imported > 0) $parts[] = "$imported pengguna baru berhasil diimport";
    if ($updated > 0) $parts[] = "$updated pengguna diperbarui jenis programnya";
    if ($skipped > 0) $parts[] = "$skipped baris dilewati";
    $message = !empty($parts) ? implode(', ', $parts) . '.' : 'Tidak ada data yang diproses.';

    echo json_encode([
        'success'  => true,
        'message'  => $message,
        'imported' => $imported,
        'updated'  => $updated,
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
