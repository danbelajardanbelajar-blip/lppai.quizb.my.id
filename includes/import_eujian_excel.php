<?php
/**
 * LPPAI Corner - Helper Impor Akun & Password Resmi E-Ujian dari Excel / CSV
 * Mendukung pembacaan berkas .xlsx (PhpSpreadsheet & Native Zip/XML fallback), .xls, dan .csv
 */

function parseSpreadsheetRows($filePath, $origName = '') {
    $ext = strtolower(pathinfo($origName ?: $filePath, PATHINFO_EXTENSION));
    $rows = [];

    // 1. Jalur CSV
    if ($ext === 'csv') {
        if (($handle = fopen($filePath, 'r')) !== false) {
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            $firstLine = fgets($handle);
            rewind($handle);
            if ($bom === "\xEF\xBB\xBF") fread($handle, 3);

            $delimiters = [',', ';', "\t", '|'];
            $bestDelim = ',';
            $maxC = 0;
            foreach ($delimiters as $d) {
                $c = substr_count($firstLine, $d);
                if ($c > $maxC) { $maxC = $c; $bestDelim = $d; }
            }

            while (($data = fgetcsv($handle, 4096, $bestDelim)) !== false) {
                $rows[] = $data;
            }
            fclose($handle);
            return $rows;
        }
    }

    // 2. Jalur PhpSpreadsheet jika tersedia di vendor
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
    foreach ($autoloadPaths as $p) {
        if (file_exists($p)) {
            require_once $p;
            if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                break;
            }
        }
    }

    if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            return $sheet->toArray(null, true, true, false);
        } catch (Throwable $e) {}
    }

    // 3. Fallback Native XLSX parser menggunakan ZipArchive & SimpleXML
    // Mendukung inlineStr (standar ekspor E-Ujian), sharedStrings, numerik, dan pemetaan sel A1..Z
    if ($ext === 'xlsx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) === true) {
            $strings = [];
            if (($strXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $xml = @simplexml_load_string($strXml);
                if ($xml && isset($xml->si)) {
                    foreach ($xml->si as $si) {
                        if (isset($si->t)) {
                            $strings[] = (string)$si->t;
                        } elseif (isset($si->r)) {
                            $t = '';
                            foreach ($si->r as $r) { $t .= (string)($r->t ?? ''); }
                            $strings[] = $t;
                        } else {
                            $strings[] = '';
                        }
                    }
                }
            }

            if (($sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml')) !== false) {
                $sxml = @simplexml_load_string($sheetXml);
                if ($sxml && isset($sxml->sheetData->row)) {
                    foreach ($sxml->sheetData->row as $r) {
                        $rowVals = [];
                        foreach ($r->c as $c) {
                            $ref = (string)$c['r'];
                            preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
                            $colLetters = $m[1] ?? 'A';
                            $colIdx = 0;
                            for ($i = 0; $i < strlen($colLetters); $i++) {
                                $colIdx = $colIdx * 26 + (ord($colLetters[$i]) - 64);
                            }
                            $colIdx -= 1;

                            $type = (string)$c['t'];
                            $val = '';
                            if ($type === 'inlineStr' && isset($c->is->t)) {
                                $val = (string)$c->is->t;
                            } elseif ($type === 's' && isset($strings[(int)$c->v])) {
                                $val = $strings[(int)$c->v];
                            } elseif (isset($c->v)) {
                                $val = (string)$c->v;
                            }
                            $rowVals[$colIdx] = $val;
                        }

                        if (!empty($rowVals)) {
                            $maxK = max(array_keys($rowVals));
                            $filled = [];
                            for ($k = 0; $k <= $maxK; $k++) {
                                $filled[$k] = $rowVals[$k] ?? '';
                            }
                            $rows[] = $filled;
                        }
                    }
                }
            }
            $zip->close();
            return $rows;
        }
    }

    return $rows;
}

/**
 * Eksekusi impor credentials E-Ujian dari file Excel/CSV ke database
 */
function importEujianSpreadsheet($filePath, $origName, $pdo, $overwrite = true) {
    $rows = parseSpreadsheetRows($filePath, $origName);
    if (empty($rows) || count($rows) < 1) {
        return [
            'success' => false,
            'message' => 'File Excel/CSV kosong atau tidak dapat dibaca.'
        ];
    }

    // 1. Pemetaan Kolom Cerdas (Smart Column Mapping)
    $headerRow = $rows[0];
    $colMap = [
        'nim'      => null,
        'nama'     => null,
        'hari'     => null,
        'password' => null,
        'username' => null
    ];

    $hasHeader = false;
    foreach ($headerRow as $idx => $h) {
        $hClean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$h));
        if ($colMap['nim'] === null && (str_contains($hClean, 'nomorpeserta') || str_contains($hClean, 'nim') || str_contains($hClean, 'nopeserta') || str_contains($hClean, 'npm'))) {
            $colMap['nim'] = $idx;
            $hasHeader = true;
        } elseif ($colMap['username'] === null && (str_contains($hClean, 'usernamelogin') || str_contains($hClean, 'username') || str_contains($hClean, 'login') || str_contains($hClean, 'user'))) {
            $colMap['username'] = $idx;
            $hasHeader = true;
        } elseif ($colMap['password'] === null && (str_contains($hClean, 'kodeakses') || str_contains($hClean, 'password') || str_contains($hClean, 'kode') || str_contains($hClean, 'pass') || str_contains($hClean, 'pin'))) {
            $colMap['password'] = $idx;
            $hasHeader = true;
        } elseif ($colMap['hari'] === null && (str_contains($hClean, 'kelompok') || str_contains($hClean, 'hari') || str_contains($hClean, 'sesi') || str_contains($hClean, 'group') || str_contains($hClean, 'jadwal'))) {
            $colMap['hari'] = $idx;
            $hasHeader = true;
        } elseif ($colMap['nama'] === null && (str_contains($hClean, 'namapeserta') || str_contains($hClean, 'nama'))) {
            $colMap['nama'] = $idx;
            $hasHeader = true;
        }
    }

    // Fallback jika tidak ada baris header atau header tidak terdeteksi
    $startIndex = 1;
    if (!$hasHeader || $colMap['nim'] === null) {
        // Cek apakah baris 0 adalah data
        $firstCell = trim((string)($headerRow[0] ?? ''));
        if (is_numeric($firstCell) || strlen($firstCell) >= 6) {
            $startIndex = 0;
            $colMap['nim'] = 0;
            $colMap['nama'] = 1;
            $colMap['hari'] = 2;
            $colMap['password'] = 4;
            $colMap['username'] = 7;
        } else {
            return [
                'success' => false,
                'message' => 'Kolom NIM / Nomor Peserta tidak ditemukan di file Excel. Pastikan terdapat kolom "NOMOR PESERTA" atau "NIM".'
            ];
        }
    }

    // 2. Ambil mapping pendaftaran yang aktif di database
    $stmtMap = $pdo->query("
        SELECT pr.id as reg_id, u.nim, pr.hari_pilihan 
        FROM pretes_registrations pr
        JOIN users u ON pr.user_id = u.id
    ");
    $allRegs = $stmtMap->fetchAll(PDO::FETCH_ASSOC);

    $nimMap = [];
    foreach ($allRegs as $row) {
        $rawNim = (string)$row['nim'];
        $cleanNim = trim(str_replace(' ', '', $rawNim));
        $nimMap[$rawNim] = (int)$row['reg_id'];
        $nimMap[$cleanNim] = (int)$row['reg_id'];
        $nimMap[ltrim($cleanNim, '0')] = (int)$row['reg_id'];
    }

    $validDays = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];

    // 3. Siapkan Prepared Statement
    $stmtUpdateWithHari = $pdo->prepare("
        UPDATE pretes_registrations 
        SET username_tes = ?, password_tes = ?, hari_pilihan = ?
        WHERE id = ?
    ");
    $stmtUpdateNoHari = $pdo->prepare("
        UPDATE pretes_registrations 
        SET username_tes = ?, password_tes = ?
        WHERE id = ?
    ");

    $matched = 0;
    $updated = 0;
    $skippedNims = [];

    // Pastikan DDL di luar transaksi
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Throwable $ignore) {}

    try {
        $pdo->beginTransaction();

        $totalRows = count($rows);
        for ($i = $startIndex; $i < $totalRows; $i++) {
            $r = $rows[$i];
            if (empty($r)) continue;

            $rawNim = trim((string)($r[$colMap['nim']] ?? ''));
            if ($rawNim === '') continue;

            $cleanNim = str_replace(' ', '', $rawNim);
            $regId = $nimMap[$rawNim] ?? ($nimMap[$cleanNim] ?? ($nimMap[ltrim($cleanNim, '0')] ?? null));

            if (!$regId) {
                $skippedNims[] = $rawNim;
                continue;
            }

            // Kode Akses / Password
            $rawPass = trim((string)($colMap['password'] !== null ? ($r[$colMap['password']] ?? '') : ''));
            // Normalisasi notasi eksponensial (misal 1.54E+3) jika ada
            if (is_numeric($rawPass)) {
                if (str_contains($rawPass, 'E') || str_contains($rawPass, 'e') || str_contains($rawPass, '.')) {
                    $rawPass = (string)(int)round((float)$rawPass);
                }
                if (strlen($rawPass) <= 4) {
                    $rawPass = str_pad($rawPass, 4, '0', STR_PAD_LEFT);
                }
            }

            // Username Login
            $rawUser = trim((string)($colMap['username'] !== null ? ($r[$colMap['username']] ?? '') : ''));
            if ($rawUser === '') {
                $rawUser = $rawNim . '.22205';
            }

            // Kelompok / Hari Pelaksanaan
            $rawHari = trim((string)($colMap['hari'] !== null ? ($r[$colMap['hari']] ?? '') : ''));
            $hariNorm = ucfirst(strtolower($rawHari));
            if (!in_array($hariNorm, $validDays)) {
                $hariNorm = '';
            }

            $matched++;
            if ($hariNorm !== '') {
                $stmtUpdateWithHari->execute([$rawUser, $rawPass, $hariNorm, $regId]);
                $updated += $stmtUpdateWithHari->rowCount();
            } else {
                $stmtUpdateNoHari->execute([$rawUser, $rawPass, $regId]);
                $updated += $stmtUpdateNoHari->rowCount();
            }
        }

        // Catat timestamp impor terakhir
        try {
            $stmtSet = $pdo->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
            $stmtSet->execute(['eujian_last_excel_import', date('Y-m-d H:i:s')]);
        } catch (Throwable $ignore) {}

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        $skipCount = count($skippedNims);
        $skipMsg = '';
        if ($skipCount > 0) {
            $sample = array_slice($skippedNims, 0, 4);
            $skipMsg = " Namun ada $skipCount NIM di Excel yang belum terdaftar pretes (" . implode(', ', $sample) . ($skipCount > 4 ? ', ...' : '') . ").";
        }

        return [
            'success' => true,
            'matched' => $matched,
            'updated' => $updated,
            'skipped' => $skippedNims,
            'message' => "Kredensial dari file Excel berhasil diimpor. Sebanyak $matched peserta dicocokkan dan diperbarui.$skipMsg"
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $ignore) {}
        }
        return [
            'success' => false,
            'message' => 'Terjadi kesalahan saat memproses data Excel: ' . $e->getMessage()
        ];
    }
}
