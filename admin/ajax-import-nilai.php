<?php
/**
 * LPPAI Corner - AJAX Import Nilai via Excel
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
    set_time_limit(180);
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
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'])) {
        echo json_encode(['success' => false, 'message' => 'File harus berformat Excel (.xlsx atau .xls).']);
        exit;
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Ukuran file maksimal 5MB.']);
        exit;
    }

    // Load PhpSpreadsheet
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
        echo json_encode(['success' => false, 'message' => 'PhpSpreadsheet tidak ditemukan di server.']);
        exit;
    }

    if ($ext === 'xlsx') {
        $reader = new XlsxReader();
    } else {
        $reader = new XlsReader();
    }
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($file['tmp_name']);

    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();

    if (count($rows) < 2) {
        echo json_encode(['success' => false, 'message' => 'File Excel kosong atau tidak memiliki data.']);
        exit;
    }

    $header = array_map(function($v) {
        return strtolower(trim((string)$v));
    }, $rows[0]);

    $colMap = [];
    foreach ($header as $idx => $h) {
        if (str_contains($h, 'nim')) $colMap['nim'] = $idx;
        elseif (str_contains($h, 'nama')) $colMap['nama'] = $idx;
        elseif (str_contains($h, 'jurusan') || str_contains($h, 'prodi')) $colMap['jurusan'] = $idx;
        elseif (str_contains($h, 'tempat') || str_contains($h, 'tmpt')) $colMap['tempat_lahir'] = $idx;
        elseif (str_contains($h, 'tanggal') || str_contains($h, 'lahir')) $colMap['tanggal_lahir'] = $idx;
        elseif (str_contains($h, 'tahun') || str_contains($h, 'ajaran')) $colMap['tahun_ajaran'] = $idx;
        elseif (str_contains($h, 'thaharah')) $colMap['thaharah'] = $idx;
        elseif (str_contains($h, 'shalat')) $colMap['shalat'] = $idx;
        elseif (str_contains($h, 'pendek') || str_contains($h, 'srt')) $colMap['srt_pendek'] = $idx;
        elseif (str_contains($h, 'amaliyah')) $colMap['amaliyah'] = $idx;
        elseif (str_contains($h, 'jenazah')) $colMap['jenazah'] = $idx;
        elseif (str_contains($h, 'tulis') || str_contains($h, 'ut')) $colMap['ut'] = $idx;
    }

    if (!isset($colMap['nim'])) {
        echo json_encode(['success' => false, 'message' => 'Kolom NIM tidak ditemukan di baris pertama Excel.']);
        exit;
    }

    $pdo = getDBConnection();
    $imported = 0;
    $skipped = 0;
    $errors = [];

    // Prepared statements
    $stmtFindUser = $pdo->prepare("SELECT id FROM users WHERE nim = ? AND role = 'mahasiswa' LIMIT 1");
    $stmtFindReg = $pdo->prepare("SELECT id FROM tutorial_registrations WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmtUpdateUserTgl = $pdo->prepare("UPDATE users SET tanggal_lahir = ? WHERE id = ?");
    $stmtUpdateUserTmpt = $pdo->prepare("UPDATE users SET tempat_lahir = ? WHERE id = ?");
    $stmtUpdateUserTmptTgl = $pdo->prepare("UPDATE users SET tempat_lahir = ?, tanggal_lahir = ? WHERE id = ?");
    
    // Dynamic user update for Jurusan, Tempat Lahir, Tanggal Lahir
    $stmtUpdateUserFull = $pdo->prepare("UPDATE users SET program_studi = ?, tempat_lahir = ?, tanggal_lahir = ? WHERE id = ?");
    $stmtUpdateUserJurusan = $pdo->prepare("UPDATE users SET program_studi = ? WHERE id = ?");
    
    $stmtUpdate = $pdo->prepare("
        UPDATE tutorial_registrations 
        SET tahun_ajaran=?, nilai_thaharah=?, nilai_shalat=?, nilai_surat_pendek=?, 
            nilai_amaliyah=?, nilai_jenazah=?, nilai_ujian_tulis=? 
        WHERE id=?
    ");
    $stmtInsert = $pdo->prepare("
        INSERT INTO tutorial_registrations 
        (user_id, status, gelombang, tahun_ajaran, nilai_thaharah, nilai_shalat, nilai_surat_pendek, nilai_amaliyah, nilai_jenazah, nilai_ujian_tulis)
        VALUES (?, 'lulus', 'lawas', ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtInsertUser = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nim, program_studi, tempat_lahir, tanggal_lahir, role) VALUES (?, ?, ?, ?, ?, ?, ?, 'mahasiswa')");
    $stmtUpdateUserDyn = $pdo->prepare("UPDATE users SET program_studi = ?, tempat_lahir = ?, tanggal_lahir = ? WHERE id = ?");

    $pdo->beginTransaction();

    foreach (array_slice($rows, 1) as $rowNum => $row) {
        $dataRow = $rowNum + 2;

        $nim = trim((string)($row[$colMap['nim']] ?? ''));
        // If row is empty or example row, skip
        if (empty($nim) || $nim === '2024010001') continue;

        $stmtFindUser->execute([$nim]);
        $userId = $stmtFindUser->fetchColumn();

        $ta = trim((string)($row[$colMap['tahun_ajaran'] ?? -1] ?? ''));
        if ($ta === '') $ta = null;

        $tglLahirRaw = trim((string)($row[$colMap['tanggal_lahir'] ?? -1] ?? ''));
        $tglLahir = null;
        if ($tglLahirRaw !== '') {
            if (is_numeric($tglLahirRaw)) {
                try {
                    $tglLahir = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($tglLahirRaw)->format('Y-m-d');
                } catch (Exception $e) {
                    $tglLahir = $tglLahirRaw;
                }
            } else {
                $tglLahir = date('Y-m-d', strtotime($tglLahirRaw));
            }
        }
        
        $tmptLahir = trim((string)($row[$colMap['tempat_lahir'] ?? -1] ?? ''));
        $jurusan = trim((string)($row[$colMap['jurusan'] ?? -1] ?? ''));
        
        if (!$userId) {
            $nama_lengkap = trim((string)($row[$colMap['nama']] ?? ''));
            if ($nama_lengkap === '') $nama_lengkap = "Mahasiswa " . $nim;
            
            try {
                $password = password_hash($nim, PASSWORD_BCRYPT, ['cost' => 8]);
                $stmtInsertUser->execute([$nim, $password, $nama_lengkap, $nim, ($jurusan!==''?$jurusan:null), ($tmptLahir!==''?$tmptLahir:null), $tglLahir]);
                $userId = $pdo->lastInsertId();
            } catch (Exception $e) {
                $errors[] = "Baris $dataRow: Gagal membuat akun otomatis untuk NIM '$nim'. (" . $e->getMessage() . ")";
                $skipped++;
                continue;
            }
        } else {
            // Update user fields if provided
            if ($jurusan !== '' || $tmptLahir !== '' || $tglLahir !== null) {
                // To keep transaction fast and statements prepared, we use the full update statement if anything is changed.
                // We'll read existing first or just overwrite all 3? Let's just use the prepared full update to avoid building dynamic SQL in the loop
                // But it's better to only update what's changed. The existing logic builds SQL. Let's keep existing logic but just prepare it locally since it's rare to change.
                $updates = [];
                $params = [];
                if ($jurusan !== '') { $updates[] = "program_studi = ?"; $params[] = $jurusan; }
                if ($tmptLahir !== '') { $updates[] = "tempat_lahir = ?"; $params[] = $tmptLahir; }
                if ($tglLahir !== null) { $updates[] = "tanggal_lahir = ?"; $params[] = $tglLahir; }
                
                if (!empty($updates)) {
                    $params[] = $userId;
                    $sqlUser = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
                    $pdo->prepare($sqlUser)->execute($params);
                }
            }
        }

        $thaharah = isset($colMap['thaharah']) && trim((string)($row[$colMap['thaharah']] ?? '')) !== '' ? (float)trim($row[$colMap['thaharah']] ?? '') : null;
        $shalat = isset($colMap['shalat']) && trim((string)($row[$colMap['shalat']] ?? '')) !== '' ? (float)trim($row[$colMap['shalat']] ?? '') : null;
        $srt = isset($colMap['srt_pendek']) && trim((string)($row[$colMap['srt_pendek']] ?? '')) !== '' ? (float)trim($row[$colMap['srt_pendek']] ?? '') : null;
        $amaliyah = isset($colMap['amaliyah']) && trim((string)($row[$colMap['amaliyah']] ?? '')) !== '' ? (float)trim($row[$colMap['amaliyah']] ?? '') : null;
        $jenazah = isset($colMap['jenazah']) && trim((string)($row[$colMap['jenazah']] ?? '')) !== '' ? (float)trim($row[$colMap['jenazah']] ?? '') : null;
        $ut = isset($colMap['ut']) && trim((string)($row[$colMap['ut']] ?? '')) !== '' ? (float)trim($row[$colMap['ut']] ?? '') : null;

        $stmtFindReg->execute([$userId]);
        $regId = $stmtFindReg->fetchColumn();

        if ($regId) {
            $stmtUpdate->execute([$ta, $thaharah, $shalat, $srt, $amaliyah, $jenazah, $ut, $regId]);
        } else {
            $stmtInsert->execute([$userId, $ta, $thaharah, $shalat, $srt, $amaliyah, $jenazah, $ut]);
        }
        $imported++;
        
        // Chunk transaction to prevent memory/lock issues on huge files
        if ($imported % 500 === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
        }
    }

    $pdo->commit();

    $msg = "Berhasil mengupdate/menyimpan nilai untuk $imported mahasiswa.";
    if ($skipped > 0) {
        $msg .= "\n\nGagal memproses $skipped baris (Mahasiswa tidak ditemukan).\n" . implode("\n", array_slice($errors, 0, 5));
        if (count($errors) > 5) {
            $msg .= "\n... dan " . (count($errors) - 5) . " error lainnya.";
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $msg
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Excel Import Nilai Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(200); // Ensure we send 200 so ajax success runs
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage() . ' di baris ' . $e->getLine()
    ]);
}
