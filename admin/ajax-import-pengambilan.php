<?php
/**
 * LPPAI Corner - AJAX Import Pengambilan Sertifikat via Excel / CSV
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    set_time_limit(300); // 5 menit untuk dataset besar
    require_once __DIR__ . '/../includes/auth.php';
    requireAdmin();

    $currentUser = getCurrentUser();
    $pdo = getDBConnection();

    // Pastikan kolom metode_pengambilan cukup panjang (VARCHAR 50)
    try {
        $pdo->exec("ALTER TABLE pengambilan_sertifikat MODIFY COLUMN metode_pengambilan VARCHAR(50) NOT NULL DEFAULT 'Scan QR'");
    } catch (Exception $e) {}

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Metode request tidak valid.']);
        exit;
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        echo json_encode(['success' => false, 'message' => 'Sesi keamanan telah berakhir (CSRF token invalid). Silakan muat ulang halaman.']);
        exit;
    }

    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'Ukuran file melebihi upload_max_filesize di php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'Ukuran file melebihi MAX_FILE_SIZE formulir.',
            UPLOAD_ERR_PARTIAL    => 'File hanya terunggah sebagian.',
            UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang dipilih.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary server tidak ditemukan.',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk server.'
        ];
        $errCode = $_FILES['excel_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        echo json_encode(['success' => false, 'message' => $uploadErrors[$errCode] ?? 'File gagal diunggah.']);
        exit;
    }

    $file = $_FILES['excel_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
        echo json_encode(['success' => false, 'message' => 'Format file tidak didukung. Mohon gunakan file Excel (.xlsx, .xls) atau CSV (.csv).']);
        exit;
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Ukuran file terlalu besar. Maksimal 10 MB.']);
        exit;
    }

    $rows = [];

    // JALUR 1: Jika file CSV, parsing secara native (cepat & tanpa ketergantungan library)
    if ($ext === 'csv') {
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle !== false) {
            // Cek BOM UTF-8
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            // Auto detect delimiter (, ; \t)
            $firstLine = fgets($handle);
            rewind($handle);
            if ($bom === "\xEF\xBB\xBF") fread($handle, 3);

            $delimiters = [',', ';', "\t", '|'];
            $bestDelimiter = ',';
            $maxCount = 0;
            foreach ($delimiters as $delim) {
                $c = substr_count($firstLine, $delim);
                if ($c > $maxCount) {
                    $maxCount = $c;
                    $bestDelimiter = $delim;
                }
            }

            while (($data = fgetcsv($handle, 4096, $bestDelimiter)) !== false) {
                $rows[] = $data;
            }
            fclose($handle);
        }
    } else {
        // JALUR 2: File Excel (.xlsx, .xls) menggunakan PhpSpreadsheet
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

        if (!$autoloaded || !class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            echo json_encode([
                'success' => false, 
                'message' => 'PhpSpreadsheet tidak ditemukan di server untuk membaca format .' . $ext . '. Silakan simpan / ubah file Anda ke format .CSV lalu unggah kembali.'
            ]);
            exit;
        }

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file['tmp_name']);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal membaca isi file Excel: ' . $e->getMessage()]);
            exit;
        }
    }

    if (empty($rows) || count($rows) < 1) {
        echo json_encode(['success' => false, 'message' => 'File kosong atau tidak memiliki baris data.']);
        exit;
    }

    // ── 1. PEMETAAN HEADER KOLOM (Smart Column Mapping) ──
    $headerRow = $rows[0];
    $colMap = [
        'nim'                 => null,
        'nomor_sertifikat'    => null,
        'tanggal_pengambilan' => null,
        'nama_penerima'       => null,
        'catatan'             => null
    ];

    $hasHeader = false;
    foreach ($headerRow as $idx => $rawHeader) {
        $h = strtolower(trim((string)$rawHeader));
        $hClean = preg_replace('/[^a-z0-9_]/', '', str_replace([' ', '-', '.'], '_', $h));

        if ($colMap['nim'] === null && (str_contains($hClean, 'nim') || str_contains($hClean, 'no_induk') || str_contains($hClean, 'npm') || str_contains($hClean, 'peserta'))) {
            $colMap['nim'] = $idx;
            $hasHeader = true;
        } elseif ($colMap['nomor_sertifikat'] === null && (str_contains($hClean, 'sertifikat') || str_contains($hClean, 'no_seri') || str_contains($hClean, 'noseri') || (str_contains($hClean, 'nomor') && !str_contains($hClean, 'hp')))) {
            $colMap['nomor_sertifikat'] = $idx;
            $hasHeader = true;
        } elseif ($colMap['tanggal_pengambilan'] === null && (str_contains($hClean, 'tanggal') || str_contains($hClean, 'tgl') || str_contains($hClean, 'date') || str_contains($hClean, 'waktu'))) {
            $colMap['tanggal_pengambilan'] = $idx;
            $hasHeader = true;
        } elseif ($colMap['nama_penerima'] === null && (str_contains($hClean, 'penerima') || str_contains($hClean, 'nama'))) {
            $colMap['nama_penerima'] = $idx;
            $hasHeader = true;
        } elseif ($colMap['catatan'] === null && (str_contains($hClean, 'catatan') || str_contains($hClean, 'keterangan') || str_contains($hClean, 'ket') || str_contains($hClean, 'note'))) {
            $colMap['catatan'] = $idx;
            $hasHeader = true;
        }
    }

    $startRowIndex = 1;

    // Fallback jika baris pertama bukan header (langsung data)
    if (!$hasHeader) {
        // Asumsikan kolom 0 = NIM, kolom 1 = Nomor Sertifikat
        $colMap['nim'] = 0;
        $colMap['nomor_sertifikat'] = 1;
        $startRowIndex = 0;
    } elseif ($colMap['nim'] === null && $colMap['nomor_sertifikat'] === null) {
        echo json_encode([
            'success' => false, 
            'message' => 'Kolom NIM atau Nomor Sertifikat tidak ditemukan pada file. Pastikan baris pertama berisi judul kolom seperti "NIM" atau "Nomor Sertifikat".'
        ]);
        exit;
    }

    // Default tanggal pengambilan jika kosong di file
    $customDefaultDate = trim($_POST['default_date'] ?? '');
    $defaultDateTime = (!empty($customDefaultDate) && strtotime($customDefaultDate) !== false) 
        ? date('Y-m-d H:i:s', strtotime($customDefaultDate)) 
        : date('Y-m-d H:i:s');

    // ── 2. PREPARED STATEMENTS UNTUK KECEPATAN & INTEGRITAS ──
    // Cari data sertifikat berdasarkan NIM dan No Sertifikat
    $stmtFindBoth = $pdo->prepare("
        SELECT tr.*, u.nim, u.nama_lengkap, u.program_studi 
        FROM tutorial_registrations tr
        JOIN users u ON tr.user_id = u.id
        WHERE (u.nim = ? OR u.username = ?) AND tr.nomor_sertifikat = ?
          AND tr.nomor_sertifikat IS NOT NULL AND tr.nomor_sertifikat != ''
        ORDER BY tr.id DESC LIMIT 1
    ");

    // Cari data sertifikat berdasarkan NIM saja
    $stmtFindNim = $pdo->prepare("
        SELECT tr.*, u.nim, u.nama_lengkap, u.program_studi 
        FROM tutorial_registrations tr
        JOIN users u ON tr.user_id = u.id
        WHERE (u.nim = ? OR u.username = ?)
          AND tr.nomor_sertifikat IS NOT NULL AND tr.nomor_sertifikat != ''
        ORDER BY tr.id DESC LIMIT 1
    ");

    // Cari data sertifikat berdasarkan Nomor Sertifikat saja
    $stmtFindCert = $pdo->prepare("
        SELECT tr.*, u.nim, u.nama_lengkap, u.program_studi 
        FROM tutorial_registrations tr
        JOIN users u ON tr.user_id = u.id
        WHERE tr.nomor_sertifikat = ?
          AND tr.nomor_sertifikat IS NOT NULL AND tr.nomor_sertifikat != ''
        ORDER BY tr.id DESC LIMIT 1
    ");

    // Cek apakah sudah pernah diambil di tabel pengambilan_sertifikat
    $stmtCheckTaken = $pdo->prepare("
        SELECT id, tanggal_pengambilan, metode_pengambilan, nama_penerima 
        FROM pengambilan_sertifikat 
        WHERE (nomor_sertifikat = ? OR tutorial_registration_id = ?) 
          AND status_pengambilan = 'Sudah Diambil'
        ORDER BY id DESC LIMIT 1
    ");

    // Insert pengambilan baru
    $stmtInsertPickup = $pdo->prepare("
        INSERT INTO pengambilan_sertifikat (
            tutorial_registration_id, nim, nama_mahasiswa, program_studi, nomor_sertifikat,
            tahun_ajaran, status_pengambilan, tanggal_pengambilan, metode_pengambilan,
            nama_penerima, hubungan_penerima, catatan, petugas_id, petugas_nama
        ) VALUES (?, ?, ?, ?, ?, ?, 'Sudah Diambil', ?, 'Import Excel', ?, 'Sendiri', ?, ?, ?)
    ");

    // Update status di tutorial_registrations
    $stmtUpdateReg = $pdo->prepare("
        UPDATE tutorial_registrations 
        SET is_sertifikat_diambil = 1, tgl_sertifikat_diambil = ? 
        WHERE id = ?
    ");

    $adminId = $currentUser['id'] ?? null;
    $adminName = $currentUser['nama_lengkap'] ?? 'Admin TU';

    $totalProcessed = 0;
    $countSuccess = 0;
    $countSkipped = 0;
    $countNotFound = 0;
    $results = [];

    // ── 3. LOOP PEMROSESAN BARIS EXCEL ──
    for ($i = $startRowIndex; $i < count($rows); $i++) {
        $row = $rows[$i];
        $excelRowNumber = $i + 1;

        $rawNim = '';
        if ($colMap['nim'] !== null && isset($row[$colMap['nim']])) {
            $rawNim = trim((string)$row[$colMap['nim']]);
        }

        $rawCert = '';
        if ($colMap['nomor_sertifikat'] !== null && isset($row[$colMap['nomor_sertifikat']])) {
            $rawCert = trim((string)$row[$colMap['nomor_sertifikat']]);
        }

        // Lewati baris yang benar-benar kosong
        if (empty($rawNim) && empty($rawCert)) {
            continue;
        }

        $totalProcessed++;

        // Ekstraksi tanggal pengambilan dari baris
        $pickupDateStr = $defaultDateTime;
        if ($colMap['tanggal_pengambilan'] !== null && !empty($row[$colMap['tanggal_pengambilan']])) {
            $rawDate = trim((string)$row[$colMap['tanggal_pengambilan']]);
            $parsedDate = null;

            // Jika serial date numeric dari Excel
            if (is_numeric($rawDate) && class_exists('\PhpOffice\PhpSpreadsheet\Shared\Date')) {
                try {
                    $dtObj = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$rawDate);
                    $parsedDate = $dtObj->format('Y-m-d H:i:s');
                } catch (Exception $e) {}
            }

            // Jika format teks tanggal
            if (!$parsedDate) {
                foreach (['Y-m-d H:i:s', 'Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y', 'd/m/Y H:i'] as $fmt) {
                    $dtObj = DateTime::createFromFormat($fmt, $rawDate);
                    if ($dtObj !== false) {
                        $parsedDate = $dtObj->format('Y-m-d H:i:s');
                        break;
                    }
                }
            }

            // Fallback strtotime
            if (!$parsedDate && strtotime($rawDate) !== false) {
                $parsedDate = date('Y-m-d H:i:s', strtotime($rawDate));
            }

            if ($parsedDate) {
                $pickupDateStr = $parsedDate;
            }
        }

        $namaPenerima = '';
        if ($colMap['nama_penerima'] !== null && isset($row[$colMap['nama_penerima']])) {
            $namaPenerima = trim((string)$row[$colMap['nama_penerima']]);
        }

        $catatan = 'Import riwayat pengambilan via file Excel/CSV';
        if ($colMap['catatan'] !== null && !empty($row[$colMap['catatan']])) {
            $catatan = trim((string)$row[$colMap['catatan']]);
        }

        // Pencarian Sertifikat di Database
        $matchedCert = null;

        // Prioritas 1: Pencocokan ganda (NIM + Nomor Sertifikat)
        if (!empty($rawNim) && !empty($rawCert)) {
            $stmtFindBoth->execute([$rawNim, $rawNim, $rawCert]);
            $matchedCert = $stmtFindBoth->fetch();
        }

        // Prioritas 2: Pencocokan via NIM saja (jika nomor sertifikat kosong atau tidak cocok)
        if (!$matchedCert && !empty($rawNim)) {
            $stmtFindNim->execute([$rawNim, $rawNim]);
            $matchedCert = $stmtFindNim->fetch();
        }

        // Prioritas 3: Pencocokan via Nomor Sertifikat saja (jika NIM kosong atau tidak cocok)
        if (!$matchedCert && !empty($rawCert)) {
            $stmtFindCert->execute([$rawCert]);
            $matchedCert = $stmtFindCert->fetch();
        }

        // KONDISI A: Data tidak ditemukan
        if (!$matchedCert) {
            $countNotFound++;
            $results[] = [
                'row'              => $excelRowNumber,
                'nim'              => $rawNim ?: '-',
                'nama'             => $namaPenerima ?: '-',
                'nomor_sertifikat' => $rawCert ?: '-',
                'status'           => 'not_found',
                'status_badge'     => 'danger',
                'status_text'      => 'Tidak Ditemukan',
                'message'          => 'Data mahasiswa atau nomor sertifikat belum terbit di sistem.'
            ];
            continue;
        }

        // KONDISI B: Cek apakah sudah pernah diambil
        $isAlreadyTaken = (!empty($matchedCert['is_sertifikat_diambil']) && $matchedCert['is_sertifikat_diambil'] == 1);
        $pickupInfo = null;

        try {
            $stmtCheckTaken->execute([$matchedCert['nomor_sertifikat'], $matchedCert['id']]);
            $pickupInfo = $stmtCheckTaken->fetch();
            if ($pickupInfo) {
                $isAlreadyTaken = true;
            }
        } catch (Exception $e) {}

        if ($isAlreadyTaken) {
            $countSkipped++;
            $tglInfo = !empty($pickupInfo['tanggal_pengambilan']) 
                ? date('d/m/Y', strtotime($pickupInfo['tanggal_pengambilan'])) 
                : (!empty($matchedCert['tgl_sertifikat_diambil']) ? date('d/m/Y', strtotime($matchedCert['tgl_sertifikat_diambil'])) : 'Sebelumnya');

            $results[] = [
                'row'              => $excelRowNumber,
                'nim'              => $matchedCert['nim'],
                'nama'             => $matchedCert['nama_lengkap'],
                'nomor_sertifikat' => $matchedCert['nomor_sertifikat'],
                'status'           => 'skipped',
                'status_badge'     => 'warning',
                'status_text'      => 'Sudah Pernah Diambil',
                'message'          => 'Dilewati: sudah tercatat diambil pada ' . $tglInfo . '.'
            ];
            continue;
        }

        // KONDISI C: Berhasil dicatat
        try {
            $penerimaFinal = !empty($namaPenerima) ? $namaPenerima : $matchedCert['nama_lengkap'];

            $stmtInsertPickup->execute([
                $matchedCert['id'],
                $matchedCert['nim'],
                $matchedCert['nama_lengkap'],
                $matchedCert['program_studi'],
                $matchedCert['nomor_sertifikat'],
                $matchedCert['tahun_ajaran'] ?? date('Y'),
                $pickupDateStr,
                $penerimaFinal,
                $catatan,
                $adminId,
                $adminName
            ]);

            try {
                $stmtUpdateReg->execute([$pickupDateStr, $matchedCert['id']]);
            } catch (Exception $e) {}

            $countSuccess++;
            $results[] = [
                'row'              => $excelRowNumber,
                'nim'              => $matchedCert['nim'],
                'nama'             => $matchedCert['nama_lengkap'],
                'nomor_sertifikat' => $matchedCert['nomor_sertifikat'],
                'status'           => 'success',
                'status_badge'     => 'success',
                'status_text'      => 'Berhasil Dicatat',
                'message'          => 'Status pengambilan berhasil disimpan (' . date('d/m/Y', strtotime($pickupDateStr)) . ').'
            ];
        } catch (Exception $e) {
            $countNotFound++;
            $results[] = [
                'row'              => $excelRowNumber,
                'nim'              => $matchedCert['nim'],
                'nama'             => $matchedCert['nama_lengkap'],
                'nomor_sertifikat' => $matchedCert['nomor_sertifikat'],
                'status'           => 'error',
                'status_badge'     => 'danger',
                'status_text'      => 'Gagal Simpan',
                'message'          => 'Database error: ' . $e->getMessage()
            ];
        }
    }

    echo json_encode([
        'success'         => true,
        'message'         => "Proses import selesai. Berhasil: $countSuccess, Sudah Diambil: $countSkipped, Tidak Ditemukan: $countNotFound.",
        'total_processed' => $totalProcessed,
        'count_success'   => $countSuccess,
        'count_skipped'   => $countSkipped,
        'count_not_found' => $countNotFound,
        'results'         => $results
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem saat memproses import: ' . $e->getMessage()
    ]);
    exit;
}
