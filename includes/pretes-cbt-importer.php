<?php
/**
 * LPPAI Corner - Importer Nilai Ujian Tulis CBT untuk Pretes
 * Mendukung format ekspor CBT / E-Ujian dengan kolom:
 * No., Nomor Induk, Nama, Kelas, Nilai, Keterangan
 */

require_once __DIR__ . '/pretes-helper.php';

/**
 * Normalisasi nama hari/kelas pretes ke Bahasa Indonesia standar (Kapital di awal)
 */
function normalizePretesDay($day) {
    $cleaned = strtolower(trim((string)$day));
    if ($cleaned === '') return 'Senin';
    if (str_contains($cleaned, 'senin') || $cleaned === 'mon' || $cleaned === 'monday') return 'Senin';
    if (str_contains($cleaned, 'selasa') || $cleaned === 'tue' || $cleaned === 'tuesday') return 'Selasa';
    if (str_contains($cleaned, 'rabu') || $cleaned === 'wed' || $cleaned === 'wednesday') return 'Rabu';
    if (str_contains($cleaned, 'kamis') || $cleaned === 'thu' || $cleaned === 'thursday') return 'Kamis';
    if (str_contains($cleaned, 'jumat') || str_contains($cleaned, "jum'at") || $cleaned === 'fri' || $cleaned === 'friday') return 'Jumat';
    return ucfirst($cleaned);
}

/**
 * Deteksi posisi kolom dari baris header (fleksibel & tahan variasi nama kolom)
 */
function detectPretesCbtColumns($headerRow) {
    $map = [
        'no' => -1,
        'nim' => -1,
        'nama' => -1,
        'kelas' => -1,
        'nilai' => -1,
        'keterangan' => -1,
    ];

    if (!is_array($headerRow)) return $map;

    foreach ($headerRow as $idx => $col) {
        $h = strtolower(trim((string)$col));
        if ($h === '') continue;

        if (preg_match('/^(no|nomor)\.?$/i', $h) || $h === '#') {
            $map['no'] = $idx;
        } elseif (str_contains($h, 'induk') || str_contains($h, 'nim') || str_contains($h, 'peserta') || str_contains($h, 'username')) {
            $map['nim'] = $idx;
        } elseif (str_contains($h, 'nama')) {
            $map['nama'] = $idx;
        } elseif (str_contains($h, 'kelas') || str_contains($h, 'hari') || str_contains($h, 'rombel') || str_contains($h, 'sesi')) {
            $map['kelas'] = $idx;
        } elseif (str_contains($h, 'keterangan') || str_contains($h, 'ket') || str_contains($h, 'status')) {
            $map['keterangan'] = $idx;
        } elseif (str_contains($h, 'nilai') || str_contains($h, 'skor') || str_contains($h, 'score') || str_contains($h, 'cbt') || str_contains($h, 'hasil')) {
            $map['nilai'] = $idx;
        }
    }

    // Fallback posisi standar jika ada minimal 5 atau 6 kolom:
    // Kolom standar: [0: No, 1: Nomor Induk, 2: Nama, 3: Kelas, 4: Nilai, 5: Keterangan]
    $totalCols = count($headerRow);
    if ($map['nim'] === -1 && $totalCols >= 5) {
        $map['nim'] = 1;
    }
    if ($map['nama'] === -1 && $totalCols >= 5) {
        $map['nama'] = 2;
    }
    if ($map['kelas'] === -1 && $totalCols >= 5) {
        $map['kelas'] = 3;
    }
    if ($map['nilai'] === -1 && $totalCols >= 5) {
        $map['nilai'] = 4;
    }
    if ($map['keterangan'] === -1 && $totalCols >= 6) {
        $map['keterangan'] = 5;
    }
    if ($map['no'] === -1 && $totalCols >= 5) {
        $map['no'] = 0;
    }

    return $map;
}

/**
 * Parsing teks mentah hasil salin & tempel (copy-paste) tabel CSV / TSV
 */
function parsePretesCbtText($rawText) {
    $rows = [];
    $rawText = trim((string)$rawText);
    if ($rawText === '') return $rows;

    // Hapus BOM UTF-8 jika ada
    if (str_starts_with($rawText, "\xEF\xBB\xBF")) {
        $rawText = substr($rawText, 3);
    }

    $lines = preg_split("/\r\n|\n|\r/", $rawText);
    if (empty($lines)) return $rows;

    // Deteksi pemisah (koma, tab, titik-koma, pipe) dari baris non-kosong pertama
    $firstLine = '';
    foreach ($lines as $l) {
        $tl = trim($l);
        if ($tl !== '') { $firstLine = $tl; break; }
    }

    $delimiters = [',', "\t", ';', '|'];
    $bestDelim = ',';
    $maxCount = 0;
    foreach ($delimiters as $d) {
        $c = substr_count($firstLine, $d);
        if ($c > $maxCount) {
            $maxCount = $c;
            $bestDelim = $d;
        }
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $parsed = str_getcsv($line, $bestDelim);
        if (!empty($parsed)) {
            $rows[] = $parsed;
        }
    }

    return $rows;
}

/**
 * Eksekusi impor data hasil CBT ke pretes_assessments (komponen 'ujian_tulis')
 * dan pretes_registrations.
 *
 * @param PDO $pdo Koneksi database PDO
 * @param array $rawRows Kumpulan baris mentah dari CSV / Excel / Paste
 * @param string $periode Nama periode pretes (contoh: "Pretes Gelombang 1 2026/2027")
 * @param int $adminId ID admin yang melakukan impor
 * @param array $options Opsi tambahan (overwrite, auto_register)
 * @return array Laporan hasil impor lengkap
 */
function importPretesCbtData($pdo, array $rawRows, $periode, $adminId, array $options = []) {
    ensurePretesFeatures($pdo);

    $overwrite = !isset($options['overwrite']) || !empty($options['overwrite']);
    $autoRegister = !isset($options['auto_register']) || !empty($options['auto_register']);

    $periode = trim((string)$periode);
    if ($periode === '') {
        return [
            'success' => false,
            'message' => 'Periode pretes tidak boleh kosong.',
            'total_rows' => 0,
            'imported' => 0,
            'updated' => 0,
            'inserted' => 0,
            'created_registrations' => 0,
            'skipped' => 0,
            'failed' => 1,
            'errors' => ['Periode pretes harus dipilih.'],
            'details' => []
        ];
    }

    if (empty($rawRows)) {
        return [
            'success' => false,
            'message' => 'Tidak ada data baris yang dapat diproses.',
            'total_rows' => 0,
            'imported' => 0,
            'updated' => 0,
            'inserted' => 0,
            'created_registrations' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => ['Data kosong.'],
            'details' => []
        ];
    }

    // Deteksi header dari baris pertama
    $firstRow = $rawRows[0];
    $colMap = detectPretesCbtColumns($firstRow);

    // Cek apakah baris pertama adalah header
    $hasHeader = false;
    if ($colMap['nim'] !== -1) {
        $cellVal = strtolower(trim((string)($firstRow[$colMap['nim']] ?? '')));
        if (str_contains($cellVal, 'induk') || str_contains($cellVal, 'nim') || str_contains($cellVal, 'peserta')) {
            $hasHeader = true;
        }
    }

    $dataStartIndex = $hasHeader ? 1 : 0;
    $totalRaw = count($rawRows);

    $importedCount = 0;
    $updatedCount = 0;
    $insertedCount = 0;
    $createdRegs = 0;
    $skippedCount = 0;
    $failedCount = 0;
    $errors = [];
    $details = [];

    // Prepared statements untuk efisiensi
    $stmtUserByNim = $pdo->prepare("SELECT id, nim, nama_lengkap FROM users WHERE TRIM(nim) = ? OR TRIM(nim) = ? LIMIT 1");
    $stmtUserByName = $pdo->prepare("SELECT id, nim, nama_lengkap FROM users WHERE LOWER(TRIM(nama_lengkap)) = LOWER(TRIM(?)) LIMIT 1");
    $stmtReg = $pdo->prepare("SELECT id, user_id, hari_pilihan, ruangan, periode, status FROM pretes_registrations WHERE user_id = ? AND periode = ? LIMIT 1");
    $stmtInsReg = $pdo->prepare("INSERT INTO pretes_registrations (user_id, hari_pilihan, jam, periode, status) VALUES (?, ?, '13.00 WIB (Jam 1)', ?, 'terdaftar')");
    $stmtUpdRegHari = $pdo->prepare("UPDATE pretes_registrations SET hari_pilihan = ? WHERE id = ?");
    $stmtAss = $pdo->prepare("SELECT scores_json FROM pretes_assessments WHERE registration_id = ?");
    $stmtInsAss = $pdo->prepare("INSERT INTO pretes_assessments (registration_id, scores_json, assessed_by) VALUES (?, ?, ?)");
    $stmtUpdAss = $pdo->prepare("UPDATE pretes_assessments SET scores_json = ?, assessed_by = ?, updated_at = CURRENT_TIMESTAMP WHERE registration_id = ?");

    for ($i = $dataStartIndex; $i < $totalRaw; $i++) {
        $row = $rawRows[$i];
        if (!is_array($row) || empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) {
            continue; // Lewati baris kosong
        }

        $lineNum = $i + 1;

        // Ambil nilai per kolom berdasarkan colMap
        $rawNim = isset($row[$colMap['nim']]) ? trim((string)$row[$colMap['nim']]) : '';
        $rawNama = isset($row[$colMap['nama']]) ? trim((string)$row[$colMap['nama']]) : '';
        $rawKelas = isset($row[$colMap['kelas']]) ? trim((string)$row[$colMap['kelas']]) : '';
        $rawNilai = isset($row[$colMap['nilai']]) ? trim((string)$row[$colMap['nilai']]) : '';
        $rawKet = isset($row[$colMap['keterangan']]) ? trim((string)$row[$colMap['keterangan']]) : '';

        // Bersihkan NIM dari tanda kutip, spasi, atau suffix .22205
        $cleanNim = trim($rawNim, " '\"\t\r\n");
        $cleanNim = preg_replace('/\.22205$/i', '', $cleanNim);

        // Jika baris ini merupakan header berulang (misal penggabungan berkas)
        $lowerNim = strtolower($cleanNim);
        if ($lowerNim === 'nomor induk' || $lowerNim === 'nim' || strtolower($rawNilai) === 'nilai') {
            continue;
        }

        if ($cleanNim === '') {
            $failedCount++;
            $errors[] = "[Baris $lineNum] Nomor Induk / NIM kosong.";
            $details[] = [
                'line' => $lineNum,
                'nim' => '-',
                'nama' => $rawNama,
                'kelas' => $rawKelas,
                'nilai' => $rawNilai,
                'status' => 'Gagal: NIM kosong',
                'type' => 'failed'
            ];
            continue;
        }

        // Validasi angka nilai
        if ($rawNilai === '' || $rawNilai === '-') {
            $skippedCount++;
            $details[] = [
                'line' => $lineNum,
                'nim' => $cleanNim,
                'nama' => $rawNama,
                'kelas' => $rawKelas,
                'nilai' => '-',
                'status' => 'Dilewati: Nilai CBT kosong',
                'type' => 'skipped'
            ];
            continue;
        }

        $normalizedNilai = str_replace(',', '.', $rawNilai);
        if (!is_numeric($normalizedNilai)) {
            $failedCount++;
            $errors[] = "[Baris $lineNum] Nilai CBT '{$rawNilai}' untuk NIM {$cleanNim} bukan format angka yang valid.";
            $details[] = [
                'line' => $lineNum,
                'nim' => $cleanNim,
                'nama' => $rawNama,
                'kelas' => $rawKelas,
                'nilai' => $rawNilai,
                'status' => 'Gagal: Format nilai tidak valid',
                'type' => 'failed'
            ];
            continue;
        }

        $numNilai = (float)$normalizedNilai;
        if ($numNilai < 0 || $numNilai > 100) {
            $failedCount++;
            $errors[] = "[Baris $lineNum] Nilai CBT {$numNilai} untuk NIM {$cleanNim} di luar rentang sah (0 - 100).";
            $details[] = [
                'line' => $lineNum,
                'nim' => $cleanNim,
                'nama' => $rawNama,
                'kelas' => $rawKelas,
                'nilai' => $numNilai,
                'status' => 'Gagal: Nilai harus 0-100',
                'type' => 'failed'
            ];
            continue;
        }

        // Cari user di database
        $trimmedNimZero = ltrim($cleanNim, '0');
        $stmtUserByNim->execute([$cleanNim, $trimmedNimZero !== '' ? $trimmedNimZero : $cleanNim]);
        $student = $stmtUserByNim->fetch(PDO::FETCH_ASSOC);

        if (!$student && !empty($rawNama)) {
            $stmtUserByName->execute([$rawNama]);
            $student = $stmtUserByName->fetch(PDO::FETCH_ASSOC);
        }

        if (!$student) {
            $failedCount++;
            $errors[] = "[Baris $lineNum] Mahasiswa NIM '{$cleanNim}' (" . ($rawNama ?: 'Tanpa Nama') . ") tidak ditemukan di tabel users.";
            $details[] = [
                'line' => $lineNum,
                'nim' => $cleanNim,
                'nama' => $rawNama,
                'kelas' => $rawKelas,
                'nilai' => $numNilai,
                'status' => 'Gagal: Mahasiswa tidak ditemukan di sistem',
                'type' => 'failed'
            ];
            continue;
        }

        $userId = (int)$student['id'];
        $displayName = $student['nama_lengkap'];

        // Cari pendaftaran di pretes_registrations pada periode terpilih
        $stmtReg->execute([$userId, $periode]);
        $reg = $stmtReg->fetch(PDO::FETCH_ASSOC);

        $isNewRegistration = false;
        if (!$reg) {
            if ($autoRegister) {
                $hari = normalizePretesDay($rawKelas);
                if (!in_array($hari, ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'], true)) {
                    $hari = 'Senin';
                }
                $stmtInsReg->execute([$userId, $hari, $periode]);
                $regId = (int)$pdo->lastInsertId();
                $isNewRegistration = true;
                $createdRegs++;
            } else {
                $failedCount++;
                $errors[] = "[Baris $lineNum] NIM {$cleanNim} ({$displayName}) belum terdaftar pada Pretes periode '{$periode}'.";
                $details[] = [
                    'line' => $lineNum,
                    'nim' => $cleanNim,
                    'nama' => $displayName,
                    'kelas' => $rawKelas,
                    'nilai' => $numNilai,
                    'status' => 'Gagal: Belum terdaftar di periode pretes ini',
                    'type' => 'failed'
                ];
                continue;
            }
        } else {
            $regId = (int)$reg['id'];
            // Jika hari_pilihan di registrasi masih kosong dan di CBT ada info hari
            if (empty($reg['hari_pilihan']) && !empty($rawKelas)) {
                $hari = normalizePretesDay($rawKelas);
                if (in_array($hari, ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'], true)) {
                    $stmtUpdRegHari->execute([$hari, $regId]);
                }
            }
        }

        // Ambil atau siapkan data asesmen (12 komponen)
        $stmtAss->execute([$regId]);
        $ass = $stmtAss->fetch(PDO::FETCH_ASSOC);

        $scores = [];
        $isExistingAssessment = false;
        if ($ass && !empty($ass['scores_json'])) {
            $scores = json_decode($ass['scores_json'], true) ?: [];
            $isExistingAssessment = true;
        }

        // Pastikan seluruh kunci komponen pretes terdefinisi
        foreach (pretesComponents() as $ck => $clbl) {
            if (!array_key_exists($ck, $scores)) {
                $scores[$ck] = null;
            }
        }

        // Cek opsi overwrite jika nilai CBT sebelumnya sudah ada
        $existingCbt = $scores['ujian_tulis'] ?? null;
        if ($existingCbt !== null && $existingCbt !== '' && !$overwrite) {
            $skippedCount++;
            $details[] = [
                'line' => $lineNum,
                'nim' => $cleanNim,
                'nama' => $displayName,
                'kelas' => $rawKelas,
                'nilai' => $numNilai,
                'status' => "Dilewati: Nilai CBT sudah ada ({$existingCbt})",
                'type' => 'skipped'
            ];
            continue;
        }

        // Simpan nilai CBT ke komponen 'ujian_tulis'
        $scores['ujian_tulis'] = $numNilai;
        $scoresJson = json_encode($scores, JSON_UNESCAPED_UNICODE);

        if ($isExistingAssessment) {
            $stmtUpdAss->execute([$scoresJson, $adminId, $regId]);
            $updatedCount++;
            $details[] = [
                'line' => $lineNum,
                'nim' => $cleanNim,
                'nama' => $displayName,
                'kelas' => $rawKelas,
                'nilai' => $numNilai,
                'status' => 'Nilai CBT berhasil diperbarui',
                'type' => 'updated'
            ];
        } else {
            $stmtInsAss->execute([$regId, $scoresJson, $adminId]);
            $insertedCount++;
            $details[] = [
                'line' => $lineNum,
                'nim' => $cleanNim,
                'nama' => $displayName,
                'kelas' => $rawKelas,
                'nilai' => $numNilai,
                'status' => $isNewRegistration ? 'Mahasiswa didaftarkan & Nilai CBT disimpan' : 'Nilai CBT disimpan',
                'type' => 'created'
            ];
        }
        $importedCount++;
    }

    $success = ($importedCount > 0);
    $msg = "Proses impor CBT selesai: {$importedCount} nilai berhasil disimpan/diperbarui";
    if ($createdRegs > 0) {
        $msg .= " ({$createdRegs} peserta baru otomatis didaftarkan)";
    }
    if ($skippedCount > 0) {
        $msg .= ", {$skippedCount} dilewati";
    }
    if ($failedCount > 0) {
        $msg .= ", {$failedCount} gagal";
    }
    $msg .= ".";

    return [
        'success' => $success,
        'message' => $msg,
        'total_rows' => $totalRaw - ($hasHeader ? 1 : 0),
        'imported' => $importedCount,
        'updated' => $updatedCount,
        'inserted' => $insertedCount,
        'created_registrations' => $createdRegs,
        'skipped' => $skippedCount,
        'failed' => $failedCount,
        'errors' => $errors,
        'details' => $details,
    ];
}
