<?php
const CBT_XLSX_HEADERS = ['Kode Soal', 'Kategori', 'Pertanyaan', 'Pilihan A', 'Pilihan B', 'Pilihan C', 'Pilihan D', 'Pilihan E', 'Kunci', 'Status'];

function cbtXlsxXml(string $text): SimpleXMLElement {
    if (stripos($text, '<!DOCTYPE') !== false || stripos($text, '<!ENTITY') !== false) throw new InvalidArgumentException('XML dengan entity tidak didukung.');
    $old = libxml_use_internal_errors(true);
    try { $xml = simplexml_load_string($text, SimpleXMLElement::class, LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    if ($xml === false) throw new InvalidArgumentException('Struktur Excel tidak valid. Simpan ulang sebagai .xlsx.');
    return $xml;
}

function cbtXlsxText(SimpleXMLElement $node): string {
    $parts = $node->xpath('./*[local-name()="t"] | ./*[local-name()="r"]/*[local-name()="t"]');
    return implode('', array_map(static fn($part) => (string)$part, $parts ?: []));
}

function cbtReadQuestionXlsx(string $file): array {
    if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) throw new RuntimeException('Ekstensi PHP ZIP dan SimpleXML diperlukan untuk Excel .xlsx.');
    if (!is_file($file) || filesize($file) > 5 * 1024 * 1024) throw new InvalidArgumentException('File Excel maksimal 5 MB.');
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new InvalidArgumentException('Berkas bukan Excel .xlsx yang valid.');
    try {
        if ($zip->numFiles > 2000 || $zip->locateName('xl/vbaProject.bin') !== false) throw new InvalidArgumentException('Berkas Excel tidak didukung. Gunakan template .xlsx tanpa macro.');
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += $stat['size'];
            if ($stat['size'] > 12 * 1024 * 1024 || $total > 25 * 1024 * 1024 || !empty($stat['encryption_method'])) throw new InvalidArgumentException('Isi Excel terlalu besar atau terenkripsi.');
        }
        $get = static function (string $name) use ($zip): SimpleXMLElement {
            $value = $zip->getFromName($name);
            if ($value === false) throw new InvalidArgumentException('Struktur workbook Excel tidak lengkap.');
            return cbtXlsxXml($value);
        };
        $book = $get('xl/workbook.xml');
        $sheets = $book->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]');
        if (!$sheets) throw new InvalidArgumentException('Workbook tidak memiliki lembar soal.');
        $chosen = $sheets[0];
        foreach ($sheets as $sheet) if (strcasecmp(trim((string)$sheet['name']), 'Bank Soal') === 0) $chosen = $sheet;
        $attributes = $chosen->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationship = (string)$attributes['id'];
        $sheetPath = null;
        foreach ($get('xl/_rels/workbook.xml.rels')->xpath('//*[local-name()="Relationship"]') as $rel) {
            if ((string)$rel['Id'] !== $relationship) continue;
            if ((string)$rel['TargetMode'] === 'External') throw new InvalidArgumentException('Lembar Excel eksternal tidak didukung.');
            $target = (string)$rel['Target'];
            $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        }
        if (!$sheetPath || !preg_match('#^xl/worksheets/[a-zA-Z0-9_.-]+\.xml$#', $sheetPath)) throw new InvalidArgumentException('Lokasi lembar soal tidak valid.');
        $strings = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) foreach ($get('xl/sharedStrings.xml')->xpath('//*[local-name()="si"]') as $value) $strings[] = cbtXlsxText($value);
        $rows = [];
        foreach ($get($sheetPath)->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
            $values = [];
            foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                if (!preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/', (string)$cell['r'], $ref)) throw new InvalidArgumentException('Referensi sel tidak valid.');
                $column = 0;
                foreach (str_split($ref[1]) as $letter) $column = $column * 26 + ord($letter) - 64;
                if ($column > 64) continue;
                if ($cell->xpath('./*[local-name()="f"]')) throw new InvalidArgumentException('Baris ' . (int)$row['r'] . ': formula tidak diterima. Gunakan Paste Values di Excel.');
                $v = $cell->xpath('./*[local-name()="v"]');
                $value = $v ? (string)$v[0] : '';
                if ((string)$cell['t'] === 's') {
                    if (!ctype_digit($value) || !array_key_exists((int)$value, $strings)) throw new InvalidArgumentException('Referensi teks Excel tidak valid.');
                    $value = $strings[(int)$value];
                } elseif ((string)$cell['t'] === 'inlineStr') {
                    $inline = $cell->xpath('./*[local-name()="is"]');
                    $value = $inline ? cbtXlsxText($inline[0]) : '';
                }
                $values[$column - 1] = trim($value);
            }
            if (count(array_filter($values, static fn($v) => $v !== '')) === 0) continue;
            $rows[] = ['row' => (int)$row['r'], 'cells' => $values];
            if (count($rows) > 1001) throw new InvalidArgumentException('Maksimal 1.000 soal dalam satu impor.');
        }
        if (count($rows) < 2) throw new InvalidArgumentException('Lembar Bank Soal kosong. Isi baris setelah judul kolom.');
        return $rows;
    } finally { $zip->close(); }
}

function cbtQuestionIdentity(string $text): string {
    $text = preg_replace('/\s+/u', ' ', trim($text));
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}
function cbtTextLength(string $text): int { return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text); }

function cbtImportQuestions(PDO $pdo, array $rows, bool $overwrite): array {
    $result = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    if (count($rows) < 2 || count($rows) > 1001) throw new InvalidArgumentException('Isi 1–1.000 soal pada lembar Bank Soal.');
    $header = array_shift($rows);
    $map = [];
    foreach ($header['cells'] as $index => $value) {
        $name = strtolower(trim((string)$value));
        if (isset($map[$name])) throw new InvalidArgumentException('Judul kolom ganda: ' . $value);
        $map[$name] = $index;
    }
    foreach (CBT_XLSX_HEADERS as $name) if (!array_key_exists(strtolower($name), $map)) throw new InvalidArgumentException('Kolom wajib tidak ditemukan: ' . $name . '. Gunakan template terbaru.');
    $clean = []; $codes = []; $texts = [];
    foreach ($rows as $row) {
        $fields = [];
        foreach (CBT_XLSX_HEADERS as $name) $fields[$name] = trim((string)($row['cells'][$map[strtolower($name)]] ?? ''));
        $errors = [];
        $code = $fields['Kode Soal'];
        if ($code !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $code)) $errors[] = 'Kode Soal hanya huruf, angka, - atau _, maksimal 64 karakter';
        if ($fields['Kategori'] === '' || cbtTextLength($fields['Kategori']) > 80) $errors[] = 'kategori wajib, maksimal 80 karakter';
        if ($fields['Pertanyaan'] === '' || cbtTextLength($fields['Pertanyaan']) > 10000) $errors[] = 'pertanyaan wajib, maksimal 10.000 karakter';
        $options = [];
        foreach (range('A', 'E') as $letter) {
            $options[$letter] = $fields['Pilihan ' . $letter];
            if ($options[$letter] === '' || cbtTextLength($options[$letter]) > 2000) $errors[] = 'Pilihan ' . $letter . ' wajib, maksimal 2.000 karakter';
        }
        if (count(array_unique(array_map('cbtQuestionIdentity', $options))) !== 5) $errors[] = 'pilihan A–E harus berbeda';
        $correct = strtoupper($fields['Kunci']);
        if (!in_array($correct, range('A', 'E'), true)) $errors[] = 'kunci harus A, B, C, D, atau E';
        $status = strtolower($fields['Status']);
        if (!in_array($status, ['aktif', 'nonaktif', '1', '0'], true)) $errors[] = 'status harus Aktif atau Nonaktif';
        $identity = cbtQuestionIdentity($fields['Pertanyaan']);
        if ($code !== '' && isset($codes[strtolower($code)])) $errors[] = 'Kode Soal ganda dalam file';
        if (isset($texts[$identity])) $errors[] = 'pertanyaan ganda dalam file';
        if ($code !== '') $codes[strtolower($code)] = true;
        $texts[$identity] = true;
        if ($errors) $result['errors'][] = 'Baris ' . $row['row'] . ': ' . implode('; ', $errors) . '.';
        $clean[] = [$code, $fields['Kategori'], $fields['Pertanyaan'], json_encode($options, JSON_UNESCAPED_UNICODE), $correct, in_array($status, ['aktif', '1'], true) ? 1 : 0];
    }
    if ($result['errors']) { $result['errors'] = array_slice($result['errors'], 0, 50); return $result; }
    $pdo->beginTransaction();
    try {
        $known = []; $knownText = []; $oldText = [];
        foreach ($pdo->query('SELECT id, code, question_text FROM pretes_cbt_questions')->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            $known[strtolower($entry['code'])] = (int)$entry['id'];
            $knownText[cbtQuestionIdentity($entry['question_text'])] = (int)$entry['id'];
            $oldText[(int)$entry['id']] = cbtQuestionIdentity($entry['question_text']);
        }
        $insert = $pdo->prepare('INSERT INTO pretes_cbt_questions (code, category, question_text, options_json, correct_answer, is_active) VALUES (?, ?, ?, ?, ?, ?)');
        $update = $pdo->prepare('UPDATE pretes_cbt_questions SET category = ?, question_text = ?, options_json = ?, correct_answer = ?, is_active = ? WHERE id = ?');
        foreach ($clean as $entry) {
            $id = $entry[0] !== '' ? ($known[strtolower($entry[0])] ?? null) : null;
            $textId = $knownText[cbtQuestionIdentity($entry[2])] ?? null;
            if (($id && !$overwrite) || ($textId && $textId !== $id)) { $result['skipped']++; continue; }
            if ($id) {
                if (isset($oldText[$id]) && ($knownText[$oldText[$id]] ?? null) === $id) unset($knownText[$oldText[$id]]);
                $update->execute(array_merge(array_slice($entry, 1), [$id]));
                $result['updated']++;
            } else {
                if ($entry[0] === '') $entry[0] = 'custom-' . bin2hex(random_bytes(12));
                $insert->execute($entry);
                $id = (int)$pdo->lastInsertId();
                $known[strtolower($entry[0])] = $id;
                $result['added']++;
            }
            $knownText[cbtQuestionIdentity($entry[2])] = $id;
            $oldText[$id] = cbtQuestionIdentity($entry[2]);
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function cbtXmlEscape(string $value): string {
    $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Runtime Excel export without a Composer dependency. Strings are never written as formulas. */
function cbtWriteXlsx(iterable $rows, string $name, array $widths): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Ekstensi PHP ZIP diperlukan untuk ekspor Excel.');
    $sheetFile = tempnam(sys_get_temp_dir(), 'cbt-sheet-');
    $file = tempnam(sys_get_temp_dir(), 'cbt-xlsx-');
    $zip = new ZipArchive();
    $handle = null;
    try {
        $handle = fopen($sheetFile, 'wb');
        fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView showGridLines="0" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>');
        foreach ($widths as $i => $width) fwrite($handle, '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$width . '" customWidth="1"/>');
        fwrite($handle, '</cols><sheetData>');
        $number = 0;
        foreach ($rows as $values) {
            if (++$number > 10001 || ftell($handle) > 32 * 1024 * 1024) throw new RuntimeException('Ekspor terlalu besar. Maksimal 10.000 baris atau 32 MB data.');
            fwrite($handle, '<row r="' . $number . '" ht="' . ($number === 1 ? 30 : 70) . '" customHeight="1">');
            foreach (array_values($values) as $i => $value) {
                $ref = chr(65 + $i) . $number;
                $style = $number === 1 ? 1 : 0;
                if (is_array($value)) { $style = $value['style']; $value = $value['value']; }
                if ($value === null) continue;
                if (is_int($value) || is_float($value)) fwrite($handle, '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>');
                else fwrite($handle, '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . cbtXmlEscape((string)$value) . '</t></is></c>');
            }
            fwrite($handle, '</row>');
        }
        fwrite($handle, '</sheetData><autoFilter ref="A1:' . chr(64 + count($widths)) . max(1, $number) . '"/></worksheet>');
        fclose($handle); $handle = null;
        if ($zip->open($file, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('File Excel belum dapat dibuat.');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . cbtXmlEscape($name) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy hh:mm"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="4"><xf fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf><xf fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->addFile($sheetFile, 'xl/worksheets/sheet1.xml');
        if (!$zip->close()) throw new RuntimeException('Ekspor Excel belum dapat diselesaikan.');
        return $file;
    } catch (Throwable $error) {
        if (is_file($file)) unlink($file);
        throw $error;
    } finally {
        if (is_resource($handle)) fclose($handle);
        if (is_file($sheetFile)) unlink($sheetFile);
    }
}

function cbtBankExportRows(PDO $pdo): Generator {
    yield CBT_XLSX_HEADERS;
    $stmt = $pdo->query('SELECT * FROM pretes_cbt_questions ORDER BY id');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $options = json_decode($row['options_json'], true);
        yield [$row['code'], $row['category'], $row['question_text'], $options['A'], $options['B'], $options['C'], $options['D'], $options['E'], $row['correct_answer'], $row['is_active'] ? 'Aktif' : 'Nonaktif'];
    }
}

function cbtResultsExportRows(PDO $pdo): Generator {
    yield ['NIM', 'Nama Peserta', 'Mulai (WIB)', 'Selesai (WIB)', 'Status', 'Jawaban Benar', 'Jumlah Soal', 'Nilai'];
    $stmt = $pdo->query('SELECT a.*, u.nim, u.nama_lengkap FROM pretes_cbt_attempts a JOIN users u ON u.id = a.user_id ORDER BY a.started_at DESC');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        yield [(string)$row['nim'], $row['nama_lengkap'], ['style' => 2, 'value' => ((int)$row['started_at'] + 25200) / 86400 + 25569],
            $row['submitted_at'] !== null ? ['style' => 2, 'value' => ((int)$row['submitted_at'] + 25200) / 86400 + 25569] : null,
            $row['submitted_at'] !== null ? 'Selesai' : 'Berlangsung',
            $row['correct_count'] !== null ? (int)$row['correct_count'] : null, 25,
            $row['score'] !== null ? ['style' => 3, 'value' => (float)$row['score']] : null];
    }
}

function cbtSendXlsx(string $file, string $filename, bool $temporary = false): void {
    if (!is_file($file)) throw new RuntimeException('Template belum tersedia. Tarik kode terbaru dari Git.');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    try { readfile($file); } finally { if ($temporary) unlink($file); }
    exit;
}
