<?php
/**
 * LPPAI Corner - Rekapitulasi Nilai Pretes Terpadu (Admin & Dosen)
 * Menampilkan rekap nilai 12 komponen, rata-rata, predikat, status kelulusan,
 * filter multi-kriteria, sinkronisasi ke hasil pretes, cetak resmi, serta export Excel/CSV.
 */

define('PAGE_TITLE', 'Rekapitulasi Nilai Pretes');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$currentUser = getCurrentUser();
$role = $currentUser['role'] ?? '';
if (!in_array($role, ['admin', 'dosen'], true)) {
    http_response_code(403);
    exit('Akses ditolak.');
}

$isAdmin = ($role === 'admin');
$isDosen = ($role === 'dosen');

$pdo = getDBConnection();
require_once __DIR__ . '/../includes/pretes-helper.php';
ensurePretesFeatures($pdo);

// ── Ambil Daftar Periode Pretes ───────────────────────────────────────────────
$periods = $pdo->query("SELECT * FROM master_pretes ORDER BY (status = 'aktif') DESC, created_at DESC")->fetchAll();
$selectedPeriodId = filter_var($_GET['pretes_id'] ?? 0, FILTER_VALIDATE_INT);
$selectedMaster = null;

if ($selectedPeriodId > 0) {
    foreach ($periods as $p) {
        if ((int)$p['id'] === (int)$selectedPeriodId) {
            $selectedMaster = $p;
            break;
        }
    }
}
if (!$selectedMaster && !empty($periods)) {
    $selectedMaster = $periods[0];
    $selectedPeriodId = (int)$selectedMaster['id'];
}

$periodeName = $selectedMaster['periode'] ?? '';
$tahunAjaran = $selectedMaster['tahun_ajaran'] ?? '';

// Komponen Pretes (12 Komponen)
$components = pretesComponents();
$componentKeys = array_keys($components);
$totalComponents = count($components);

// ── Helper Predikat & Status Kelulusan ────────────────────────────────────────
function getPretesPredikat($score) {
    if ($score === null || $score === '') return '-';
    $num = (float)$score;
    if ($num >= 85) return 'A';
    if ($num >= 75) return 'B';
    if ($num >= 65) return 'C';
    if ($num >= 55) return 'D';
    return 'E';
}

function getPretesStatusLulus($score, $filledCount, $totalCount) {
    if ($filledCount < $totalCount) return 'menunggu';
    return ((float)$score >= 65) ? 'lulus' : 'tidak_lulus';
}

$message = '';
$msgType = 'info';

// ── Handle Action POST: Sinkronisasi Nilai ke Hasil Pretes (Admin Only) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $token = $_POST['csrf_token'] ?? '';

    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid. Silakan muat ulang halaman.';
        $msgType = 'danger';
    } elseif ($action === 'sync_to_results') {
        if (!$isAdmin) {
            $message = 'Hanya Administrator yang berwenang melakukan sinkronisasi hasil.';
            $msgType = 'danger';
        } else {
            // Ambil semua peserta di periode terpilih yang memiliki asesmen
            $stmtSync = $pdo->prepare("
                SELECT pr.id as reg_id, pr.user_id, pr.periode, pa.scores_json
                FROM pretes_registrations pr
                JOIN pretes_assessments pa ON pa.registration_id = pr.id
                WHERE pr.periode = ?
            ");
            $stmtSync->execute([$periodeName]);
            $toSync = $stmtSync->fetchAll();

            $syncedCount = 0;
            $skippedCount = 0;

            foreach ($toSync as $item) {
                $scores = json_decode($item['scores_json'] ?? '{}', true);
                $sum = 0;
                $filled = 0;
                foreach ($componentKeys as $ck) {
                    $val = is_array($scores) ? ($scores[$ck] ?? null) : null;
                    if ($val !== null && $val !== '' && is_numeric($val)) {
                        $sum += (float)$val;
                        $filled++;
                    }
                }

                if ($filled === 0) {
                    $skippedCount++;
                    continue;
                }

                $avg = round($sum / $filled, 2);
                $statusLulus = ($filled === $totalComponents && $avg >= 65) ? 'lulus' : (($filled === $totalComponents) ? 'tidak_lulus' : 'belum_diumumkan');
                
                $predikat = getPretesPredikat($avg);
                $keterangan = "Nilai Rata-rata: {$avg} ({$predikat}) - {$filled}/{$totalComponents} komponen dinilai.";

                // Check pretes_results
                $check = $pdo->prepare("SELECT id FROM pretes_results WHERE user_id = ?");
                $check->execute([$item['user_id']]);
                $existing = $check->fetch();

                if ($existing) {
                    $upd = $pdo->prepare("UPDATE pretes_results SET nilai = ?, status_lulus = ?, keterangan = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?");
                    $upd->execute([$avg, $statusLulus, $keterangan, $item['user_id']]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO pretes_results (user_id, nilai, status_lulus, keterangan) VALUES (?, ?, ?, ?)");
                    $ins->execute([$item['user_id'], $avg, $statusLulus, $keterangan]);
                }
                $syncedCount++;
            }

            $message = "Berhasil mensinkronkan {$syncedCount} nilai peserta ke Hasil Pretes resmi." . ($skippedCount > 0 ? " ({$skippedCount} peserta dilewati karena belum ada komponen yang dinilai)" : "");
            $msgType = 'success';
        }
    }
}

// ── Parameter Filter GET ──────────────────────────────────────────────────────
$filterHari         = trim($_GET['hari'] ?? '');
$filterDosen        = trim($_GET['dosen'] ?? '');
$filterRuang        = trim($_GET['ruang'] ?? '');
$filterKelengkapan  = trim($_GET['kelengkapan'] ?? '');
$filterStatus       = trim($_GET['status_lulus'] ?? '');
$filterSearch       = trim($_GET['q'] ?? '');

// Jika Dosen login, otomatis kunci filter dosen ke namanya sendiri
if ($isDosen) {
    $filterDosen = trim($currentUser['nama_lengkap'] ?? '');
}

// ── Query Data Peserta & Nilai Pretes ─────────────────────────────────────────
$sql = "
    SELECT pr.id as reg_id, pr.user_id, pr.periode, pr.hari_pilihan, pr.jam, pr.ruangan, pr.dosen_penguji,
           pr.status as status_hadir, pr.tanggal_daftar,
           u.nama_lengkap, u.nim, u.program_studi, u.fakultas, u.no_hp,
           pa.scores_json, pa.updated_at as assessed_at,
           ar.attended_at
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    LEFT JOIN pretes_assessments pa ON pa.registration_id = pr.id
    LEFT JOIN pretes_attendance_records ar ON ar.registration_id = pr.id
    WHERE pr.periode = ?
";
$params = [$periodeName];

if ($isDosen) {
    $sql .= " AND TRIM(pr.dosen_penguji) = ?";
    $params[] = $filterDosen;
} elseif (!empty($filterDosen)) {
    $sql .= " AND TRIM(pr.dosen_penguji) = ?";
    $params[] = $filterDosen;
}

if (!empty($filterHari)) {
    $sql .= " AND LOWER(TRIM(pr.hari_pilihan)) = LOWER(?)";
    $params[] = $filterHari;
}

if (!empty($filterRuang)) {
    $sql .= " AND TRIM(pr.ruangan) = ?";
    $params[] = $filterRuang;
}

$sql .= " ORDER BY 
    CASE LOWER(TRIM(COALESCE(pr.hari_pilihan, '')))
        WHEN 'senin' THEN 1
        WHEN 'selasa' THEN 2
        WHEN 'rabu' THEN 3
        WHEN 'kamis' THEN 4
        WHEN 'jumat' THEN 5
        ELSE 6
    END,
    pr.ruangan ASC,
    u.nama_lengkap ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Parse Nilai & Hitung Statistik / Indikator ─────────────────────────────────
$roster = [];
$totalPeserta = count($rawRows);
$hadirCount = 0;
$lengkapCount = 0;
$sebagianCount = 0;
$kosongCount = 0;
$lulusCount = 0;
$tidakLulusCount = 0;
$sumTotalAvg = 0;
$countAssessed = 0;

$dosenList = [];
$ruangList = [];

foreach ($rawRows as $row) {
    if (!empty($row['dosen_penguji'])) {
        $dosenList[$row['dosen_penguji']] = true;
    }
    if (!empty($row['ruangan'])) {
        $ruangList[$row['ruangan']] = true;
    }

    $isHadir = (strtolower($row['status_hadir'] ?? '') === 'hadir' || !empty($row['attended_at']));
    if ($isHadir) $hadirCount++;

    $scores = json_decode($row['scores_json'] ?? '{}', true);
    $filledScores = [];
    $sum = 0;
    $filled = 0;

    foreach ($components as $key => $label) {
        $val = is_array($scores) ? ($scores[$key] ?? null) : null;
        if ($val !== null && $val !== '' && is_numeric($val)) {
            $fVal = (float)$val;
            $filledScores[$key] = $fVal;
            $sum += $fVal;
            $filled++;
        } else {
            $filledScores[$key] = null;
        }
    }

    $avg = ($filled > 0) ? round($sum / $filled, 2) : null;
    $predikat = getPretesPredikat($avg);
    $statusLulus = getPretesStatusLulus($avg, $filled, $totalComponents);

    if ($filled === $totalComponents) {
        $lengkapCount++;
        if ($avg >= 65) $lulusCount++;
        else $tidakLulusCount++;
    } elseif ($filled > 0) {
        $sebagianCount++;
    } else {
        $kosongCount++;
    }

    if ($avg !== null) {
        $sumTotalAvg += $avg;
        $countAssessed++;
    }

    $itemData = [
        'reg_id'        => (int)$row['reg_id'],
        'user_id'       => (int)$row['user_id'],
        'nama_lengkap'  => $row['nama_lengkap'],
        'nim'           => $row['nim'],
        'program_studi' => $row['program_studi'] ?: '-',
        'fakultas'      => $row['fakultas'] ?: '-',
        'hari_pilihan'  => ucfirst(strtolower(trim($row['hari_pilihan'] ?: 'Senin'))),
        'jam'           => $row['jam'] ?: '13.00 WIB',
        'ruangan'       => $row['ruangan'] ?: '-',
        'dosen_penguji' => $row['dosen_penguji'] ?: '-',
        'is_hadir'      => $isHadir,
        'scores'        => $filledScores,
        'filled_count'  => $filled,
        'average'       => $avg,
        'predikat'      => $predikat,
        'status_lulus'  => $statusLulus,
        'assessed_at'   => $row['assessed_at'],
    ];

    // Filter Kelengkapan di memori jika diset
    if (!empty($filterKelengkapan)) {
        if ($filterKelengkapan === 'lengkap' && $filled < $totalComponents) continue;
        if ($filterKelengkapan === 'sebagian' && ($filled === 0 || $filled === $totalComponents)) continue;
        if ($filterKelengkapan === 'kosong' && $filled > 0) continue;
    }

    // Filter Status Kelulusan di memori jika diset
    if (!empty($filterStatus)) {
        if ($filterStatus === 'lulus' && $statusLulus !== 'lulus') continue;
        if ($filterStatus === 'tidak_lulus' && $statusLulus !== 'tidak_lulus') continue;
        if ($filterStatus === 'menunggu' && $statusLulus !== 'menunggu') continue;
    }

    // Filter Search text
    if (!empty($filterSearch)) {
        $q = strtolower($filterSearch);
        $searchBody = strtolower($itemData['nama_lengkap'] . ' ' . $itemData['nim'] . ' ' . $itemData['program_studi'] . ' ' . $itemData['dosen_penguji']);
        if (strpos($searchBody, $q) === false) continue;
    }

    $roster[] = $itemData;
}

$rataRataAngkatan = ($countAssessed > 0) ? round($sumTotalAvg / $countAssessed, 2) : 0;
ksort($dosenList);
ksort($ruangList);

// ── MODE EXPORT: XLSX / CSV ───────────────────────────────────────────────────
$exportFormat = strtolower(trim($_GET['export'] ?? ''));
if (in_array($exportFormat, ['xlsx', 'csv', 'excel'], true)) {
    if ($exportFormat === 'excel') $exportFormat = 'xlsx';
    $safePeriode = preg_replace('/[^a-zA-Z0-9]/', '_', $periodeName ?: 'Semua');
    $safeDosen = !empty($filterDosen) ? '_' . preg_replace('/[^a-zA-Z0-9]/', '_', substr($filterDosen, 0, 15)) : '';
    $timestamp = date('Ymd_His');
    $filename = "Rekapitulasi_Nilai_Pretes_{$safePeriode}{$safeDosen}_{$timestamp}";

    // Cek ketersediaan PhpSpreadsheet
    $autoloadPaths = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php',
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

    if ($exportFormat === 'xlsx' && $hasSpreadsheet) {
        while (ob_get_level()) ob_end_clean();
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekap Nilai Pretes');

        // Judul Lembar Kerja
        $sheet->setCellValue('A1', 'LEMBAGA PENGEMBANGAN PENDIDIKAN AGAMA ISLAM (LPPAI) UNISDA');
        $sheet->setCellValue('A2', "REKAPITULASI NILAI PRETES PAI - PERIODE: {$periodeName}");
        $sheet->mergeCells('A1:U1');
        $sheet->mergeCells('A2:U2');
        $sheet->getStyle('A1:A2')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1:A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // Header Tabel Kolom
        $headers = [
            'No', 'NIM', 'Nama Mahasiswa', 'Prodi', 'Fakultas', 'Hari', 'Ruangan', 'Dosen Penguji', 'Kehadiran',
            'Ujian Tulis', 'Praktik Wudlu', 'Doa Stlh Wudlu', 'Tayammum', 'Mandi Besar', 'Jamak Qashar',
            'Shalat Subuh', 'Al Bayyinah', "Al 'Alaq", 'Al Dluha', 'Istighasah', 'Shalat Jenazah',
            'Terisi', 'Rata-rata', 'Predikat', 'Status Kelulusan'
        ];

        $colChar = 'A';
        foreach ($headers as $idx => $h) {
            $sheet->setCellValue("{$colChar}4", $h);
            $sheet->getColumnDimension($colChar)->setAutoSize(true);
            $colChar++;
        }
        $lastCol = chr(ord('A') + count($headers) - 1);

        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0D5C3A']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(26);

        $rowNum = 5;
        foreach ($roster as $idx => $r) {
            $c = 'A';
            $sheet->setCellValue("{$c}{$rowNum}", $idx + 1); $c++;
            $sheet->setCellValueExplicit("{$c}{$rowNum}", (string)$r['nim'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING); $c++;
            $sheet->setCellValue("{$c}{$rowNum}", $r['nama_lengkap']); $c++;
            $sheet->setCellValue("{$c}{$rowNum}", $r['program_studi']); $c++;
            $sheet->setCellValue("{$c}{$rowNum}", $r['fakultas']); $c++;
            $sheet->setCellValue("{$c}{$rowNum}", $r['hari_pilihan']); $c++;
            $sheet->setCellValue("{$c}{$rowNum}", $r['ruangan']); $c++;
            $sheet->setCellValue("{$c}{$rowNum}", $r['dosen_penguji']); $c++;
            $sheet->setCellValue("{$c}{$rowNum}", $r['is_hadir'] ? 'Hadir' : 'Belum Hadir'); $c++;

            foreach ($componentKeys as $ck) {
                $v = $r['scores'][$ck];
                $sheet->setCellValue("{$c}{$rowNum}", $v !== null ? $v : '-');
                $c++;
            }

            $sheet->setCellValue("{$c}{$rowNum}", "{$r['filled_count']}/{$totalComponents}"); $c++;
            $sheet->setCellValue("{$c}{$rowNum}", $r['average'] !== null ? $r['average'] : '-'); $c++;
            $sheet->setCellValue("{$c}{$rowNum}", $r['predikat']); $c++;
            
            $statusText = ($r['status_lulus'] === 'lulus') ? 'LULUS' : (($r['status_lulus'] === 'tidak_lulus') ? 'TIDAK LULUS' : 'MENUNGGU');
            $sheet->setCellValue("{$c}{$rowNum}", $statusText); $c++;

            $rowNum++;
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"{$filename}.xlsx\"");
        header('Cache-Control: max-age=0');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    } else {
        // Fallback: Export CSV dengan UTF-8 BOM
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
        echo "\xEF\xBB\xBF"; // UTF-8 BOM

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'No', 'NIM', 'Nama Mahasiswa', 'Program Studi', 'Fakultas', 'Hari', 'Ruangan', 'Dosen Penguji', 'Kehadiran',
            'Ujian Tulis', 'Praktik Wudlu', 'Doa Setelah Wudlu', 'Praktik Tayammum', 'Niat Mandi Besar', 'Niat Jamak Qashar',
            'Bacaan Shalat Subuh', 'Al Bayyinah', "Al 'Alaq", 'Al Dluha', 'Istighasah', 'Shalat Jenazah',
            'Komponen Terisi', 'Rata-Rata', 'Predikat', 'Status Kelulusan'
        ]);

        foreach ($roster as $idx => $r) {
            $rowCSV = [
                $idx + 1,
                "'" . (string)$r['nim'],
                $r['nama_lengkap'],
                $r['program_studi'],
                $r['fakultas'],
                $r['hari_pilihan'],
                $r['ruangan'],
                $r['dosen_penguji'],
                $r['is_hadir'] ? 'Hadir' : 'Belum Hadir',
            ];
            foreach ($componentKeys as $ck) {
                $val = $r['scores'][$ck];
                $rowCSV[] = ($val !== null) ? $val : '-';
            }
            $rowCSV[] = "{$r['filled_count']}/{$totalComponents}";
            $rowCSV[] = ($r['average'] !== null) ? $r['average'] : '-';
            $rowCSV[] = $r['predikat'];
            $rowCSV[] = ($r['status_lulus'] === 'lulus') ? 'LULUS' : (($r['status_lulus'] === 'tidak_lulus') ? 'TIDAK LULUS' : 'MENUNGGU');

            fputcsv($out, $rowCSV);
        }
        fclose($out);
        exit;
    }
}

// ── MODE CETAK RESMI (A4 LANDSCAPE) ───────────────────────────────────────────
$isPrint = (isset($_GET['print']) && $_GET['print'] === '1');
if ($isPrint) {
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Rekapitulasi Nilai Pretes - <?= sanitize($periodeName) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Times New Roman', Times, serif; background: #e2e8f0; margin: 0; padding: 20px; color: #000; }
        .no-print-toolbar {
            max-width: 1200px; margin: 0 auto 16px; background: #ffffff; padding: 12px 20px;
            border-radius: 8px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        .btn { padding: 8px 16px; border-radius: 6px; font-weight: bold; cursor: pointer; text-decoration: none; border: none; font-size: 13px; }
        .btn-print { background: #059669; color: #fff; }
        .btn-back { background: #64748b; color: #fff; }
        .sheet {
            max-width: 1200px; margin: 0 auto; background: #fff; padding: 30px 35px; box-shadow: 0 4px 10px rgba(0,0,0,0.15); border-radius: 4px;
        }
        .kop { display: flex; align-items: center; border-bottom: 3px double #000; padding-bottom: 12px; margin-bottom: 16px; }
        .kop img { width: 75px; height: 75px; margin-right: 18px; object-fit: contain; }
        .kop-text { text-align: center; flex: 1; }
        .kop-text h2 { margin: 0; font-size: 14pt; font-weight: bold; }
        .kop-text h1 { margin: 2px 0; font-size: 17pt; font-weight: bold; }
        .kop-text p { margin: 2px 0 0; font-size: 9.5pt; font-style: italic; }
        
        .doc-title { text-align: center; margin: 16px 0 12px; }
        .doc-title h3 { margin: 0; font-size: 14pt; text-decoration: underline; text-transform: uppercase; }
        .doc-title p { margin: 4px 0 0; font-size: 10.5pt; }

        .meta-info { display: flex; justify-content: space-between; font-size: 10pt; margin-bottom: 12px; font-weight: 600; }
        
        table.rekap-table { width: 100%; border-collapse: collapse; font-size: 9pt; }
        table.rekap-table th, table.rekap-table td { border: 1px solid #000; padding: 5px 4px; text-align: center; }
        table.rekap-table th { background: #f1f5f9; font-weight: bold; }
        table.rekap-table td.text-left { text-align: left; }
        
        .badge-lulus { font-weight: bold; color: #047857; }
        .badge-tdk-lulus { font-weight: bold; color: #b91c1c; }
        
        .signature-container { display: flex; justify-content: flex-end; margin-top: 30px; font-size: 10.5pt; }
        .signature-box { text-align: center; width: 260px; }
        .signature-name { font-weight: bold; text-decoration: underline; margin-top: 4px; }

        @media print {
            body { background: #fff !important; padding: 0 !important; }
            .no-print-toolbar { display: none !important; }
            .sheet { box-shadow: none !important; padding: 0 !important; max-width: 100% !important; }
            @page { size: landscape; margin: 10mm; }
        }
    </style>
</head>
<body>
    <div class="no-print-toolbar">
        <div>
            <a href="pretes-rekap-nilai.php?pretes_id=<?= (int)$selectedPeriodId ?>" class="btn btn-back">⬅️ Kembali ke Tabel Rekap</a>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-print">🖨️ Cetak Dokumen / Simpan PDF</button>
        </div>
    </div>

    <div class="sheet">
        <div class="kop">
            <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo UNISDA">
            <div class="kop-text">
                <h2>UNIVERSITAS ISLAM DARUL 'ULUM LAMONGAN</h2>
                <h1>LEMBAGA PEMBINAAN DAN PENGEMBANGAN AGAMA ISLAM ( LPPAI )</h1>
                <p>Kantor: Gedung FAI Lt. 1, Jl. Airlangga 3 Sukodadi, Lamongan, Jawa Timur 62253</p>
            </div>
        </div>

        <div class="doc-title">
            <h3>REKAPITULASI HASIL PENILAIAN PRETES PAI</h3>
            <p>Periode: <strong><?= sanitize($periodeName) ?></strong> | Tahun Ajaran: <strong><?= sanitize($tahunAjaran) ?></strong></p>
        </div>

        <div class="meta-info">
            <div>
                Dosen Penguji: <strong><?= sanitize($filterDosen ?: 'Semua Penguji') ?></strong>
                <?= !empty($filterHari) ? " | Hari: <strong>" . sanitize($filterHari) . "</strong>" : "" ?>
                <?= !empty($filterRuang) ? " | Ruang: <strong>" . sanitize($filterRuang) . "</strong>" : "" ?>
            </div>
            <div>
                Tanggal Cetak: <strong><?= date('d/m/Y') ?></strong> | Total Peserta: <strong><?= count($roster) ?></strong>
            </div>
        </div>

        <table class="rekap-table">
            <thead>
                <tr>
                    <th rowspan="2" style="width:25px;">No</th>
                    <th rowspan="2" style="width:75px;">NIM</th>
                    <th rowspan="2">Nama Mahasiswa</th>
                    <th rowspan="2" style="width:70px;">Prodi</th>
                    <th rowspan="2" style="width:50px;">Hari / Ruang</th>
                    <th colspan="12">Komponen Nilai Pretes</th>
                    <th rowspan="2" style="width:40px;">Rata-rata</th>
                    <th rowspan="2" style="width:30px;">Predikat</th>
                    <th rowspan="2" style="width:65px;">Status</th>
                </tr>
                <tr>
                    <th title="Ujian Tulis" style="width:26px;">UT</th>
                    <th title="Praktik Wudlu" style="width:26px;">PW</th>
                    <th title="Doa Setelah Wudlu" style="width:26px;">DW</th>
                    <th title="Praktik Tayammum" style="width:26px;">PT</th>
                    <th title="Niat Mandi Besar" style="width:26px;">MB</th>
                    <th title="Niat Jamak Qashar" style="width:26px;">JQ</th>
                    <th title="Bacaan Shalat Subuh" style="width:26px;">SS</th>
                    <th title="Al Bayyinah" style="width:26px;">BY</th>
                    <th title="Al 'Alaq" style="width:26px;">AL</th>
                    <th title="Al Dluha" style="width:26px;">DL</th>
                    <th title="Istighasah" style="width:26px;">IS</th>
                    <th title="Shalat Jenazah" style="width:26px;">SJ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($roster)): ?>
                <tr>
                    <td colspan="20" style="padding:20px; font-style:italic;">Tidak ada data peserta yang sesuai dengan filter.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($roster as $idx => $r): ?>
                <tr>
                    <td><?= $idx + 1 ?></td>
                    <td><?= sanitize($r['nim']) ?></td>
                    <td class="text-left" style="font-weight:600;"><?= sanitize($r['nama_lengkap']) ?></td>
                    <td><?= sanitize($r['program_studi']) ?></td>
                    <td><?= sanitize($r['hari_pilihan']) ?><br><small style="color:#475569;"><?= sanitize($r['ruangan']) ?></small></td>
                    
                    <?php foreach ($componentKeys as $ck): 
                        $val = $r['scores'][$ck];
                    ?>
                        <td><?= $val !== null ? number_format($val, 0) : '-' ?></td>
                    <?php endforeach; ?>

                    <td style="font-weight:bold;"><?= $r['average'] !== null ? number_format($r['average'], 1) : '-' ?></td>
                    <td style="font-weight:bold;"><?= $r['predikat'] ?></td>
                    <td>
                        <?php if ($r['status_lulus'] === 'lulus'): ?>
                            <span class="badge-lulus">LULUS</span>
                        <?php elseif ($r['status_lulus'] === 'tidak_lulus'): ?>
                            <span class="badge-tdk-lulus">TIDAK LULUS</span>
                        <?php else: ?>
                            <span style="color:#d97706;">MENUNGGU</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-top:16px; font-size:9pt; line-height:1.4;">
            <strong>Keterangan Singkatan Komponen:</strong><br>
            UT: Ujian Tulis | PW: Praktik Wudlu | DW: Doa Setelah Wudlu | PT: Praktik Tayammum | MB: Niat Mandi Besar | JQ: Niat Jamak Qashar<br>
            SS: Bacaan Shalat Subuh | BY: Al Bayyinah | AL: Al 'Alaq | DL: Al Dluha | IS: Istighasah | SJ: Shalat Jenazah<br>
            * Batas minimal kelulusan pretes PAI adalah rata-rata nilai <strong>65.00</strong> dengan 12 komponen lengkap dinilai.
        </div>

        <div class="signature-container">
            <div class="signature-box">
                <p>Lamongan, <?= date('d F Y') ?></p>
                <p style="margin-top:4px;">Kepala LPPAI UNISDA,</p>
                <div style="height:70px; display:flex; align-items:center; justify-content:center;">
                    <img src="<?= BASE_URL ?>/assets/img/ttd_zainul_hakim.png" alt="Tanda Tangan" style="height:70px; object-fit:contain; mix-blend-mode:multiply;">
                </div>
                <div class="signature-name">Dr. Zainul Hakim. M.H.I.</div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
    exit;
}

// ── TAMPILAN DASHBOARD WEB (DEFAULT) ──────────────────────────────────────────
include __DIR__ . '/../includes/header.php';
?>

<style>
/* Styling Elegan LPPAI Corner Rekapitulasi Pretes */
.rekap-hero {
    background: linear-gradient(135deg, #0d5c3a 0%, #15803d 50%, #047857 100%);
    border-radius: 16px;
    padding: 22px 24px;
    color: #ffffff;
    margin-bottom: 24px;
    box-shadow: 0 4px 18px rgba(13, 92, 58, 0.16);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}
.rekap-hero-title h2 {
    margin: 0;
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.3px;
}
.rekap-hero-meta {
    margin-top: 4px;
    font-size: 13.5px;
    color: #e2e8f0;
}
.rekap-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}
.rekap-kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    transition: transform 0.2s, box-shadow 0.2s;
}
.rekap-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.06);
}
.rekap-kpi-val {
    font-size: 24px;
    font-weight: 800;
    line-height: 1.1;
    margin-bottom: 4px;
}
.rekap-kpi-label {
    font-size: 12px;
    color: #64748b;
    font-weight: 600;
}
.filter-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px 20px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}
.day-pill-btn {
    padding: 6px 14px;
    border-radius: 20px;
    border: 1.5px solid #cbd5e1;
    background: #f8fafc;
    color: #334155;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.day-pill-btn:hover {
    background: #e2e8f0;
    border-color: #94a3b8;
}
.day-pill-btn.active {
    background: #0d5c3a !important;
    border-color: #0d5c3a !important;
    color: #ffffff !important;
    box-shadow: 0 2px 8px rgba(13, 92, 58, 0.25);
}
.table-responsive {
    overflow-x: auto;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
}
.rekap-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13px;
}
.rekap-table th {
    background: #f8fafc;
    color: #1e293b;
    font-weight: 700;
    padding: 12px 10px;
    border-bottom: 2px solid #e2e8f0;
    text-align: center;
    white-space: nowrap;
}
.rekap-table td {
    padding: 10px 8px;
    border-bottom: 1px solid #f1f5f9;
    text-align: center;
    vertical-align: middle;
}
.rekap-table tr:hover td {
    background: #f0fdf4;
}
.col-sticky-left {
    position: sticky;
    left: 0;
    background: #ffffff;
    z-index: 2;
}
.rekap-table tr:hover .col-sticky-left {
    background: #f0fdf4;
}
.badge-predikat {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 6px;
    font-weight: 800;
    font-size: 12px;
}
.pred-A { background: #dcfce7; color: #15803d; }
.pred-B { background: #e0f2fe; color: #0284c7; }
.pred-C { background: #fef3c7; color: #b45309; }
.pred-D { background: #ffedd5; color: #c2410c; }
.pred-E { background: #fee2e2; color: #b91c1c; }
.score-pill {
    font-weight: 700;
    font-size: 12px;
    padding: 2px 6px;
    border-radius: 4px;
    background: #f1f5f9;
    color: #334155;
    display: inline-block;
}
.score-pill.good { background: #dcfce7; color: #166534; }
.score-pill.mid  { background: #fef9c3; color: #854d0e; }
.score-pill.low  { background: #fee2e2; color: #991b1b; }
.badge-lulus-box {
    padding: 4px 10px;
    border-radius: 20px;
    font-weight: 800;
    font-size: 11px;
    text-transform: uppercase;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.status-lulus { background: #dcfce7; color: #15803d; }
.status-tidak { background: #fee2e2; color: #b91c1c; }
.status-menunggu { background: #fef3c7; color: #b45309; }
</style>

<?php if ($message): ?>
<div class="alert alert-<?= $msgType ?>" style="border-radius:12px; margin-bottom:20px; font-weight:600;">
    <?= $msgType === 'success' ? '✅' : '⚠️' ?> <?= sanitize($message) ?>
</div>
<?php endif; ?>

<!-- ── HERO BANNER ─────────────────────────────────────────────────────────── -->
<div class="rekap-hero">
    <div class="rekap-hero-title">
        <div style="display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,0.2); padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; margin-bottom:6px;">
            <span>🎓</span> <span>Akademik LPPAI</span>
        </div>
        <h2>Rekapitulasi Nilai Pretes</h2>
        <div class="rekap-hero-meta">
            Periode: <strong><?= sanitize($periodeName) ?></strong>
            <?php if (!empty($tahunAjaran)): ?>
                · TA: <strong><?= sanitize($tahunAjaran) ?></strong>
            <?php endif; ?>
            · Mode: <strong><?= $isAdmin ? 'Administrator (Semua Penguji)' : 'Dosen Penguji (' . sanitize($currentUser['nama_lengkap']) . ')' ?></strong>
        </div>
    </div>

    <!-- Toolbar Kanan (Periode Selector, Sync, Export, Print) -->
    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
        <form method="GET" data-no-spa style="margin:0;">
            <select name="pretes_id" onchange="this.form.submit()" style="padding:8px 12px; border-radius:8px; border:none; font-size:13px; font-weight:700; background:#ffffff; color:#0f172a; cursor:pointer;">
                <?php foreach ($periods as $p): ?>
                <option value="<?= $p['id'] ?>" <?= ((int)$p['id'] === (int)$selectedPeriodId) ? 'selected' : '' ?>>
                    📅 <?= sanitize($p['periode']) ?> <?= $p['status'] === 'aktif' ? '(Aktif)' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($isAdmin): ?>
        <form method="POST" data-no-spa style="margin:0;" onsubmit="return confirm('Apakah Anda yakin ingin mensinkronkan seluruh nilai rata-rata dan status kelulusan peserta periode ini ke tabel Hasil Pretes resmi?')">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="sync_to_results">
            <button type="submit" class="btn" style="background:#f59e0b; color:#fff; font-weight:700; padding:8px 14px; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
                🔄 Sinkronkan ke Hasil
            </button>
        </form>
        <?php endif; ?>

        <a href="?pretes_id=<?= (int)$selectedPeriodId ?>&hari=<?= urlencode($filterHari) ?>&dosen=<?= urlencode($filterDosen) ?>&ruang=<?= urlencode($filterRuang) ?>&kelengkapan=<?= urlencode($filterKelengkapan) ?>&status_lulus=<?= urlencode($filterStatus) ?>&export=xlsx" class="btn" style="background:#ffffff; color:#0d5c3a; font-weight:700; padding:8px 14px; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
            📊 Export Excel
        </a>

        <a href="?pretes_id=<?= (int)$selectedPeriodId ?>&hari=<?= urlencode($filterHari) ?>&dosen=<?= urlencode($filterDosen) ?>&ruang=<?= urlencode($filterRuang) ?>&kelengkapan=<?= urlencode($filterKelengkapan) ?>&status_lulus=<?= urlencode($filterStatus) ?>&print=1" target="_blank" class="btn" style="background:rgba(255,255,255,0.25); color:#ffffff; font-weight:700; padding:8px 14px; border-radius:8px; text-decoration:none; border:1px solid rgba(255,255,255,0.4); display:inline-flex; align-items:center; gap:6px;">
            🖨️ Cetak Resmi
        </a>
    </div>
</div>

<!-- ── SUMMARY KPI CARDS ───────────────────────────────────────────────────── -->
<div class="rekap-kpi-grid">
    <div class="rekap-kpi-card">
        <div class="rekap-kpi-val" style="color:#0f172a;"><?= $totalPeserta ?></div>
        <div class="rekap-kpi-label">👥 Total Peserta Pretes</div>
    </div>
    <div class="rekap-kpi-card">
        <div class="rekap-kpi-val" style="color:#0284c7;"><?= $hadirCount ?></div>
        <div class="rekap-kpi-label">✓ Hadir Absensi</div>
    </div>
    <div class="rekap-kpi-card">
        <div class="rekap-kpi-val" style="color:#059669;"><?= $lengkapCount ?></div>
        <div class="rekap-kpi-label">✅ Dinilai Lengkap (12/12)</div>
    </div>
    <div class="rekap-kpi-card">
        <div class="rekap-kpi-val" style="color:#ea580c;"><?= $sebagianCount + $kosongCount ?></div>
        <div class="rekap-kpi-label">⏳ Belum Lengkap / Kosong</div>
    </div>
    <div class="rekap-kpi-card">
        <div class="rekap-kpi-val" style="color:#16a34a;"><?= $lulusCount ?></div>
        <div class="rekap-kpi-label">🎉 Lulus Pretes (≥65)</div>
    </div>
    <div class="rekap-kpi-card">
        <div class="rekap-kpi-val" style="color:#dc2626;"><?= $tidakLulusCount ?></div>
        <div class="rekap-kpi-label">❌ Tidak Lulus (&lt;65)</div>
    </div>
    <div class="rekap-kpi-card">
        <div class="rekap-kpi-val" style="color:#7c3aed;"><?= number_format($rataRataAngkatan, 1) ?></div>
        <div class="rekap-kpi-label">📈 Rata-rata Angkatan</div>
    </div>
</div>

<!-- ── FILTER TOOLBAR PANEL ────────────────────────────────────────────────── -->
<div class="filter-card">
    <form method="GET" data-no-spa id="form-filter-rekap">
        <input type="hidden" name="pretes_id" value="<?= (int)$selectedPeriodId ?>">

        <!-- Baris 1: Quick Day Pills -->
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
            <span style="font-size:13px; font-weight:700; color:#475569; margin-right:4px;">Filter Hari:</span>
            <?php
            $days = ['Semua' => '', 'Senin' => 'Senin', 'Selasa' => 'Selasa', 'Rabu' => 'Rabu', 'Kamis' => 'Kamis', 'Jumat' => 'Jumat'];
            foreach ($days as $label => $val):
                $isActive = (strtolower($filterHari) === strtolower($val)) || ($val === '' && empty($filterHari));
                $urlPill = "?pretes_id={$selectedPeriodId}&hari=" . urlencode($val) . "&dosen=" . urlencode($filterDosen) . "&ruang=" . urlencode($filterRuang) . "&kelengkapan=" . urlencode($filterKelengkapan) . "&status_lulus=" . urlencode($filterStatus);
            ?>
                <a href="<?= $urlPill ?>" class="day-pill-btn <?= $isActive ? 'active' : '' ?>">
                    <?= $label ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Baris 2: Dropdowns Multi-Kriteria -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:flex-end;">
            <?php if ($isAdmin): ?>
            <div>
                <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Dosen Penguji</label>
                <select name="dosen" class="form-control" style="width:100%; padding:8px; border-radius:8px; font-size:13px;" onchange="this.form.submit()">
                    <option value="">-- Semua Penguji --</option>
                    <?php foreach (array_keys($dosenList) as $d): ?>
                    <option value="<?= sanitize($d) ?>" <?= ($filterDosen === $d) ? 'selected' : '' ?>><?= sanitize($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div>
                <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Ruangan</label>
                <select name="ruang" class="form-control" style="width:100%; padding:8px; border-radius:8px; font-size:13px;" onchange="this.form.submit()">
                    <option value="">-- Semua Ruangan --</option>
                    <?php foreach (array_keys($ruangList) as $r): ?>
                    <option value="<?= sanitize($r) ?>" <?= ($filterRuang === $r) ? 'selected' : '' ?>><?= sanitize($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Kelengkapan Nilai</label>
                <select name="kelengkapan" class="form-control" style="width:100%; padding:8px; border-radius:8px; font-size:13px;" onchange="this.form.submit()">
                    <option value="">-- Semua Status --</option>
                    <option value="lengkap" <?= ($filterKelengkapan === 'lengkap') ? 'selected' : '' ?>>✅ Lengkap (12/12)</option>
                    <option value="sebagian" <?= ($filterKelengkapan === 'sebagian') ? 'selected' : '' ?>>⏳ Sebagian (1-11)</option>
                    <option value="kosong" <?= ($filterKelengkapan === 'kosong') ? 'selected' : '' ?>>⭕ Belum Dinilai (0)</option>
                </select>
            </div>

            <div>
                <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Status Kelulusan</label>
                <select name="status_lulus" class="form-control" style="width:100%; padding:8px; border-radius:8px; font-size:13px;" onchange="this.form.submit()">
                    <option value="">-- Semua Kelulusan --</option>
                    <option value="lulus" <?= ($filterStatus === 'lulus') ? 'selected' : '' ?>>🎉 Lulus (≥65)</option>
                    <option value="tidak_lulus" <?= ($filterStatus === 'tidak_lulus') ? 'selected' : '' ?>>❌ Tidak Lulus (&lt;65)</option>
                    <option value="menunggu" <?= ($filterStatus === 'menunggu') ? 'selected' : '' ?>>⏳ Belum Lengkap</option>
                </select>
            </div>

            <div>
                <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Pencarian Peserta</label>
                <input type="text" name="q" value="<?= sanitize($filterSearch) ?>" placeholder="NIM / Nama Mahasiswa..." class="form-control" style="width:100%; padding:8px; border-radius:8px; font-size:13px;">
            </div>

            <div style="display:flex; gap:6px;">
                <button type="submit" class="btn btn-primary" style="padding:8px 16px; border-radius:8px; font-weight:700; font-size:13px;">
                    🔍 Terapkan
                </button>
                <a href="pretes-rekap-nilai.php?pretes_id=<?= (int)$selectedPeriodId ?>" class="btn btn-secondary" style="padding:8px 14px; border-radius:8px; font-weight:700; font-size:13px; text-decoration:none; background:#f1f5f9; color:#475569;">
                    Reset
                </a>
            </div>
        </div>
    </form>
</div>

<!-- ── TABEL REKAPITULASI NILAI PRETES ─────────────────────────────────────── -->
<div class="card" style="border-radius:14px; overflow:hidden; border:1px solid #e2e8f0; margin-bottom:30px;">
    <div class="card-header" style="background:#f8fafc; padding:16px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div style="font-weight:800; font-size:16px; color:#0f172a;">
            📋 Tabel Nilai Rinci 12 Komponen Pretes (<?= count($roster) ?> Mahasiswa)
        </div>
        <div style="font-size:12.5px; color:#64748b;">
            💡 Skor berwarna: <span class="score-pill good">≥75</span> <span class="score-pill mid">65–74</span> <span class="score-pill low">&lt;65</span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="rekap-table" id="table-rekap-pretes">
            <thead>
                <tr>
                    <th rowspan="2" class="col-sticky-left" style="width:40px;">No</th>
                    <th rowspan="2" class="col-sticky-left" style="left:40px; min-width:200px; text-align:left;">Nama & NIM</th>
                    <th rowspan="2" style="min-width:130px; text-align:left;">Prodi</th>
                    <th rowspan="2" style="min-width:110px;">Jadwal & Ruang</th>
                    <th rowspan="2" style="min-width:150px; text-align:left;">Dosen Penguji</th>
                    <th rowspan="2" style="width:75px;">Absensi</th>
                    <th colspan="12" style="background:#f0fdf4; color:#166534; border-bottom:1px solid #bbf7d0;">
                        12 Komponen Penilaian Pretes PAI
                    </th>
                    <th rowspan="2" style="width:70px;">Rata-rata</th>
                    <th rowspan="2" style="width:50px;">Predikat</th>
                    <th rowspan="2" style="width:95px;">Status</th>
                    <th rowspan="2" style="width:90px;">Aksi</th>
                </tr>
                <tr>
                    <th title="1. Ujian Tulis (CBT)" style="width:36px; font-size:11px;">UT</th>
                    <th title="2. Praktik Wudlu" style="width:36px; font-size:11px;">PW</th>
                    <th title="3. Doa Setelah Wudlu" style="width:36px; font-size:11px;">DW</th>
                    <th title="4. Praktik Tayammum" style="width:36px; font-size:11px;">PT</th>
                    <th title="5. Niat Mandi Besar" style="width:36px; font-size:11px;">MB</th>
                    <th title="6. Niat Jamak Qashar" style="width:36px; font-size:11px;">JQ</th>
                    <th title="7. Bacaan Shalat Subuh" style="width:36px; font-size:11px;">SS</th>
                    <th title="8. Al Bayyinah" style="width:36px; font-size:11px;">BY</th>
                    <th title="9. Al 'Alaq" style="width:36px; font-size:11px;">AL</th>
                    <th title="10. Al Dluha" style="width:36px; font-size:11px;">DL</th>
                    <th title="11. Istighasah" style="width:36px; font-size:11px;">IS</th>
                    <th title="12. Shalat Jenazah" style="width:36px; font-size:11px;">SJ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($roster)): ?>
                <tr>
                    <td colspan="23" style="padding:32px; text-align:center; color:#64748b;">
                        Tidak ada data mahasiswa yang ditemukan untuk filter yang dipilih.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($roster as $idx => $r): ?>
                <tr>
                    <td class="col-sticky-left"><?= $idx + 1 ?></td>
                    <td class="col-sticky-left" style="left:40px; text-align:left;">
                        <strong style="color:#0f172a; font-size:13.5px;"><?= sanitize($r['nama_lengkap']) ?></strong>
                        <div style="font-size:11.5px; color:#64748b; font-family:monospace;"><?= sanitize($r['nim']) ?></div>
                    </td>
                    <td style="text-align:left; font-size:12.5px;"><?= sanitize($r['program_studi']) ?></td>
                    <td style="font-size:12px;">
                        <span style="font-weight:700; color:#0f172a;"><?= sanitize($r['hari_pilihan']) ?></span>
                        <div style="color:#64748b;"><?= sanitize($r['ruangan']) ?></div>
                    </td>
                    <td style="text-align:left; font-size:12px; font-weight:600; color:#334155;">
                        <?= sanitize($r['dosen_penguji']) ?>
                    </td>
                    <td>
                        <?php if ($r['is_hadir']): ?>
                            <span class="badge" style="background:#dcfce7; color:#15803d; font-size:11px; font-weight:700; padding:2px 8px; border-radius:20px;">✓ Hadir</span>
                        <?php else: ?>
                            <span class="badge" style="background:#f1f5f9; color:#94a3b8; font-size:11px; font-weight:600; padding:2px 8px; border-radius:20px;">Belum</span>
                        <?php endif; ?>
                    </td>

                    <!-- 12 Komponen Nilai -->
                    <?php foreach ($componentKeys as $ck): 
                        $val = $r['scores'][$ck];
                        $pillClass = '';
                        if ($val !== null) {
                            if ($val >= 75) $pillClass = 'good';
                            elseif ($val >= 65) $pillClass = 'mid';
                            else $pillClass = 'low';
                        }
                    ?>
                        <td>
                            <?php if ($val !== null): ?>
                                <span class="score-pill <?= $pillClass ?>" title="<?= sanitize($components[$ck]) ?>: <?= $val ?>">
                                    <?= number_format($val, 0) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#cbd5e1;">-</span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>

                    <!-- Rata-Rata -->
                    <td>
                        <?php if ($r['average'] !== null): ?>
                            <strong style="font-size:13.5px; color:#0f172a;"><?= number_format($r['average'], 1) ?></strong>
                            <div style="font-size:10px; color:#64748b;"><?= $r['filled_count'] ?>/12</div>
                        <?php else: ?>
                            <span style="color:#94a3b8;">-</span>
                        <?php endif; ?>
                    </td>

                    <!-- Predikat -->
                    <td>
                        <?php if ($r['predikat'] !== '-'): ?>
                            <span class="badge-predikat pred-<?= $r['predikat'] ?>"><?= $r['predikat'] ?></span>
                        <?php else: ?>
                            <span style="color:#94a3b8;">-</span>
                        <?php endif; ?>
                    </td>

                    <!-- Status Kelulusan -->
                    <td>
                        <?php if ($r['status_lulus'] === 'lulus'): ?>
                            <span class="badge-lulus-box status-lulus">✓ LULUS</span>
                        <?php elseif ($r['status_lulus'] === 'tidak_lulus'): ?>
                            <span class="badge-lulus-box status-tidak">✕ GAGAL</span>
                        <?php else: ?>
                            <span class="badge-lulus-box status-menunggu" title="<?= $r['filled_count'] ?>/12 komponen terisi">⏳ MENUNGGU</span>
                        <?php endif; ?>
                    </td>

                    <!-- Aksi -->
                    <td>
                        <?php 
                        $inputUrl = ($isDosen ? BASE_URL . "/dosen/pretes.php" : BASE_URL . "/admin/pretes-nilai.php") . "?pretes_id={$selectedPeriodId}&registration_id={$r['reg_id']}#nilai-pretes";
                        ?>
                        <a href="<?= $inputUrl ?>" class="btn btn-sm btn-outline-primary" style="padding:4px 8px; font-size:11.5px; font-weight:700; text-decoration:none; white-space:nowrap; border-radius:6px;" data-no-spa>
                            ✏️ Nilai
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// DataTables & Vanilla JS Auto-Enhancement
document.addEventListener('DOMContentLoaded', function() {
    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable) {
        var table = document.getElementById('table-rekap-pretes');
        if (table && !window.jQuery.fn.DataTable.isDataTable(table)) {
            window.jQuery(table).DataTable({
                pageLength: 25,
                lengthMenu: [10, 25, 50, 100, 250],
                order: [],
                autoWidth: false,
                scrollX: true,
                language: {
                    search: "Cari di tabel:",
                    lengthMenu: "Tampilkan _MENU_ baris",
                    info: "Menampilkan _START_ s/d _END_ dari _TOTAL_ mahasiswa",
                    infoEmpty: "Tidak ada data",
                    infoFiltered: "(difilter dari _MAX_ total data)",
                    zeroRecords: "Data tidak ditemukan",
                    paginate: {
                        first: "«",
                        last: "»",
                        next: "›",
                        previous: "‹"
                    }
                }
            });
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
