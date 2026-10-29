<?php
/**
 * LPPAI Corner - Admin: Export Data Peserta & Credentials Tes Tulis ke Excel / CSV
 */

define('PAGE_TITLE', 'Export Data Peserta Pretes ke Excel');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
require_once __DIR__ . '/../includes/sync_eujian.php';
checkAutoSyncEujian($pdo);

// ── Daftar Kolom yang Tersedia untuk Diexport ───────────────────────────────────
$availableColumns = [
    'no'             => ['label' => 'No',                   'default' => true],
    'nama_lengkap'   => ['label' => 'Nama Mahasiswa',        'default' => true],
    'nim'            => ['label' => 'NIM',                   'default' => true],
    'program_studi'  => ['label' => 'Program Studi',         'default' => true],
    'fakultas'       => ['label' => 'Fakultas',              'default' => true],
    'hari_pilihan'   => ['label' => 'Hari Pelaksanaan',      'default' => true],
    'jam'            => ['label' => 'Waktu / Jam',           'default' => true],
    'ruangan'        => ['label' => 'Ruangan',               'default' => true],
    'dosen_penguji'  => ['label' => 'Dosen Penguji',         'default' => true],
    'status'         => ['label' => 'Status Hadir',          'default' => true],
    'username_tes'   => ['label' => 'Username Tes Tulis',    'default' => true],
    'password_tes'   => ['label' => 'Password Tes Tulis',    'default' => true],
    'no_hp'          => ['label' => 'No. WhatsApp / HP',     'default' => true],
    'email'          => ['label' => 'Email',                 'default' => true],
    'periode'        => ['label' => 'Periode Pretes',        'default' => true],
    'tanggal_daftar' => ['label' => 'Tanggal Pendaftaran',   'default' => true],
];

// ── Tangkap Parameter Filter ───────────────────────────────────────────────────
$filterHari   = trim($_GET['hari'] ?? '');
$filterProdi  = trim($_GET['prodi'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterCred   = trim($_GET['cred'] ?? '');
$filterSearch = trim($_GET['q'] ?? ($_GET['nama'] ?? ($_GET['nim'] ?? '')));
$format       = strtolower(trim($_GET['format'] ?? 'xlsx'));
if (!in_array($format, ['xlsx', 'csv'])) {
    $format = 'xlsx';
}

// Kolom yang dipilih
$selectedCols = isset($_GET['cols']) && is_array($_GET['cols']) ? $_GET['cols'] : [];
if (empty($selectedCols)) {
    $selectedCols = array_keys(array_filter($availableColumns, fn($c) => $c['default']));
} else {
    // Validasi agar hanya kolom terdaftar yang dipakai
    $selectedCols = array_values(array_intersect($selectedCols, array_keys($availableColumns)));
    if (empty($selectedCols)) {
        $selectedCols = array_keys($availableColumns);
    }
}

// ── Buat Kueri Filter Dinamis ──────────────────────────────────────────────────
$whereConditions = ["1=1"];
$params = [];

if ($filterHari !== '') {
    $whereConditions[] = "LOWER(TRIM(pr.hari_pilihan)) = LOWER(?)";
    $params[] = $filterHari;
}

if ($filterProdi !== '') {
    $whereConditions[] = "u.program_studi = ?";
    $params[] = $filterProdi;
}

if ($filterStatus !== '') {
    $whereConditions[] = "pr.status = ?";
    $params[] = $filterStatus;
}

if ($filterCred === 'has_cred') {
    $whereConditions[] = "(pr.username_tes IS NOT NULL AND pr.username_tes != '')";
} elseif ($filterCred === 'no_cred') {
    $whereConditions[] = "(pr.username_tes IS NULL OR pr.username_tes = '')";
}

if ($filterSearch !== '') {
    $whereConditions[] = "(u.nama_lengkap LIKE ? OR u.nim LIKE ? OR pr.username_tes LIKE ?)";
    $like = '%' . $filterSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $whereConditions);

$querySql = "
    SELECT pr.id as reg_id, pr.user_id, pr.periode, pr.hari_pilihan, pr.jam, pr.dosen_penguji, pr.ruangan, pr.tanggal_daftar,
           pr.status, pr.username_tes, pr.password_tes,
           u.nama_lengkap, u.nim, u.program_studi, u.fakultas, u.email, u.no_hp
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    WHERE {$whereSql}
    ORDER BY 
        CASE LOWER(TRIM(COALESCE(pr.hari_pilihan, '')))
            WHEN 'senin' THEN 1
            WHEN 'selasa' THEN 2
            WHEN 'rabu' THEN 3
            WHEN 'kamis' THEN 4
            WHEN 'jumat' THEN 5
            ELSE 6
        END,
        u.nama_lengkap ASC
";

$stmt = $pdo->prepare($querySql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Mode Download (Excel .xlsx / CSV) ──────────────────────────────────────────
$isDownload = (isset($_GET['download']) && $_GET['download'] === '1') || (isset($_GET['action']) && $_GET['action'] === 'export');

if ($isDownload) {
    // Siapkan label kolom terpilih
    $headerLabels = [];
    foreach ($selectedCols as $colKey) {
        $headerLabels[] = $availableColumns[$colKey]['label'] ?? $colKey;
    }

    $safeHari = $filterHari ? preg_replace('/[^a-zA-Z0-9]/', '', ucfirst(strtolower($filterHari))) : 'SemuaHari';
    $timestamp = date('Ymd_His');
    $filenameBase = "Data_Peserta_Pretes_LPPAI_{$safeHari}_{$timestamp}";

    // Cari PhpSpreadsheet jika format xlsx
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
        '/home/' . get_current_user() . '/public_html/vendor/autoload.php',
    ];

    $hasSpreadsheet = false;
    foreach ($autoloadPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                $hasSpreadsheet = true;
                break;
            }
        }
    }

    // Eksekusi Pembuatan XLSX via PhpSpreadsheet
    if ($format === 'xlsx' && $hasSpreadsheet) {
        // Bersihkan output buffer sebelum kirim file binary
        while (ob_get_level()) {
            ob_end_clean();
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('LPPAI Corner')
            ->setLastModifiedBy('Admin LPPAI')
            ->setTitle('Data Peserta Pretes LPPAI')
            ->setSubject('Data Peserta & Credentials Tes Tulis Pretes')
            ->setDescription('Ekspor data pendaftaran dan credentials pretes dari LPPAI Corner');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Peserta Pretes');

        $totalCols = count($selectedCols);
        // Hitung kolom Excel akhir (misal A sampai P)
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totalCols);

        // 1. Baris Judul Banner
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1', 'DATA PESERTA & CREDENTIALS TES TULIS PRETES LPPAI');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
              ->getStartColor()->setARGB('FF1A5632'); // LPPAI Green
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
              ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(32);

        // 2. Baris Sub-Judul (Ringkasan Filter & Waktu Unduh)
        $subInfo = [];
        $subInfo[] = "Hari: " . ($filterHari ?: 'Semua Hari');
        if ($filterProdi) $subInfo[] = "Prodi: " . $filterProdi;
        if ($filterStatus) $subInfo[] = "Status: " . ucfirst(str_replace('_', ' ', $filterStatus));
        if ($filterCred) $subInfo[] = "Credentials: " . ($filterCred === 'has_cred' ? 'Sudah Ada' : 'Belum Ada');
        if ($filterSearch) $subInfo[] = "Pencarian: \"{$filterSearch}\"";
        $subInfo[] = "Total Data: " . count($rows) . " Mahasiswa";
        $subInfo[] = "Diunduh: " . date('d/m/Y H:i:s') . " WIB";

        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->setCellValue('A2', implode('  |  ', $subInfo));
        $sheet->getStyle('A2')->getFont()->setSize(10)->setItalic(true)->getColor()->setARGB('FF334155');
        $sheet->getStyle('A2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
              ->getStartColor()->setARGB('FFF1F5F9');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
              ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(2)->setRowHeight(22);

        // Baris 3: Pemisah
        $sheet->getRowDimension(3)->setRowHeight(8);

        // 3. Header Tabel (Baris 4)
        $headerRow = 4;
        foreach ($headerLabels as $colIdx => $label) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
            $sheet->setCellValue("{$colLetter}{$headerRow}", $label);
        }

        $headerStyle = [
            'font' => [
                'bold'  => true,
                'color' => ['argb' => 'FFFFFFFF'],
                'size'  => 11,
            ],
            'fill' => [
                'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF0D2818'], // Hijau Tua Elegan
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color'       => ['argb' => 'FF64748B'],
                ],
            ],
        ];
        $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$headerRow}")->applyFromArray($headerStyle);
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        // 4. Pengisian Data (Mulai Baris 5)
        $currentRow = 5;
        foreach ($rows as $index => $row) {
            $isEven = ($index % 2 === 0);
            $rowBgColor = $isEven ? 'FFFFFFFF' : 'FFF8FAFC';

            foreach ($selectedCols as $colIdx => $colKey) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
                $cellCoordinate = "{$colLetter}{$currentRow}";

                $val = '';
                $dataType = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;

                switch ($colKey) {
                    case 'no':
                        $val = $index + 1;
                        $dataType = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC;
                        break;
                    case 'nama_lengkap':
                        $val = $row['nama_lengkap'] ?? '';
                        break;
                    case 'nim':
                        // Wajib string agar digit panjang dan angka nol di awal tidak hilang di Excel
                        $val = (string)($row['nim'] ?? '');
                        break;
                    case 'program_studi':
                        $val = $row['program_studi'] ?? '';
                        break;
                    case 'fakultas':
                        $val = $row['fakultas'] ?? '';
                        break;
                    case 'hari_pilihan':
                        $val = ucfirst(strtolower(trim($row['hari_pilihan'] ?? '')));
                        break;
                    case 'jam':
                        $val = $row['jam'] ?? '13.00 WIB';
                        break;
                    case 'ruangan':
                        $val = $row['ruangan'] ?? '-';
                        break;
                    case 'dosen_penguji':
                        $val = $row['dosen_penguji'] ?? '-';
                        break;
                    case 'status':
                        $st = strtolower(trim($row['status'] ?? 'terdaftar'));
                        $val = ucfirst(str_replace('_', ' ', $st));
                        break;
                    case 'username_tes':
                        $val = (string)($row['username_tes'] ?? '-');
                        break;
                    case 'password_tes':
                        $val = (string)($row['password_tes'] ?? '-');
                        break;
                    case 'no_hp':
                        $val = (string)($row['no_hp'] ?? '-');
                        break;
                    case 'email':
                        $val = $row['email'] ?? '-';
                        break;
                    case 'periode':
                        $val = $row['periode'] ?? '-';
                        break;
                    case 'tanggal_daftar':
                        $val = !empty($row['tanggal_daftar']) ? date('d/m/Y H:i', strtotime($row['tanggal_daftar'])) : '-';
                        break;
                }

                $sheet->setCellValueExplicit($cellCoordinate, $val, $dataType);

                // Styling per Kolom
                $alignHoriz = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT;
                if (in_array($colKey, ['no', 'nim', 'hari_pilihan', 'jam', 'ruangan', 'status', 'username_tes', 'password_tes', 'no_hp', 'periode', 'tanggal_daftar'])) {
                    $alignHoriz = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;
                }

                $sheet->getStyle($cellCoordinate)->getAlignment()
                    ->setHorizontal($alignHoriz)
                    ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

                // Highlight username & password
                if ($colKey === 'username_tes' && !empty($row['username_tes'])) {
                    $sheet->getStyle($cellCoordinate)->getFont()->getColor()->setARGB('FF1D4ED8'); // Biru
                } elseif ($colKey === 'password_tes' && !empty($row['password_tes'])) {
                    $sheet->getStyle($cellCoordinate)->getFont()->getColor()->setARGB('FFB45309'); // Amber
                    $sheet->getStyle($cellCoordinate)->getFont()->setBold(true);
                }
            }

            // Garis border dan zebra shading baris
            $sheet->getStyle("A{$currentRow}:{$lastColLetter}{$currentRow}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB($rowBgColor);

            $sheet->getStyle("A{$currentRow}:{$lastColLetter}{$currentRow}")->getBorders()
                ->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                ->getColor()->setARGB('FFE2E8F0');

            $sheet->getRowDimension($currentRow)->setRowHeight(22);
            $currentRow++;
        }

        // Auto-size kolom dengan margin aman
        for ($c = 1; $c <= $totalCols; $c++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        // Freeze panes agar header tetap terlihat saat scroll ke bawah
        $sheet->freezePane('A5');

        // Output ke Browser
        $filename = "{$filenameBase}.xlsx";
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    // ── Jalur Fallback CSV (Juga aktif jika user memilih format .csv) ───────────
    while (ob_get_level()) {
        ob_end_clean();
    }

    $filename = "{$filenameBase}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // Output UTF-8 BOM agar terbaca sempurna dengan format Indonesia di Microsoft Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Tulis Header Kolom
    fputcsv($output, $headerLabels);

    // Tulis Baris Data
    foreach ($rows as $index => $row) {
        $line = [];
        foreach ($selectedCols as $colKey) {
            switch ($colKey) {
                case 'no':
                    $line[] = $index + 1;
                    break;
                case 'nama_lengkap':
                    $line[] = $row['nama_lengkap'] ?? '';
                    break;
                case 'nim':
                    // Tambahkan tanda petik tunggal ' di awal atau pastikan string agar tidak terpotong di CSV Excel
                    $line[] = "'" . ($row['nim'] ?? '');
                    break;
                case 'program_studi':
                    $line[] = $row['program_studi'] ?? '';
                    break;
                case 'fakultas':
                    $line[] = $row['fakultas'] ?? '';
                    break;
                case 'hari_pilihan':
                    $line[] = ucfirst(strtolower(trim($row['hari_pilihan'] ?? '')));
                    break;
                case 'jam':
                    $line[] = $row['jam'] ?? '13.00 WIB';
                    break;
                case 'ruangan':
                    $line[] = $row['ruangan'] ?? '-';
                    break;
                case 'dosen_penguji':
                    $line[] = $row['dosen_penguji'] ?? '-';
                    break;
                case 'status':
                    $line[] = ucfirst(str_replace('_', ' ', strtolower(trim($row['status'] ?? 'terdaftar'))));
                    break;
                case 'username_tes':
                    $line[] = $row['username_tes'] ?? '-';
                    break;
                case 'password_tes':
                    $line[] = $row['password_tes'] ?? '-';
                    break;
                case 'no_hp':
                    $line[] = "'" . ($row['no_hp'] ?? '-');
                    break;
                case 'email':
                    $line[] = $row['email'] ?? '-';
                    break;
                case 'periode':
                    $line[] = $row['periode'] ?? '-';
                    break;
                case 'tanggal_daftar':
                    $line[] = !empty($row['tanggal_daftar']) ? date('d/m/Y H:i', strtotime($row['tanggal_daftar'])) : '-';
                    break;
                default:
                    $line[] = '';
                    break;
            }
        }
        fputcsv($output, $line);
    }

    fclose($output);
    exit;
}

// ── Ambil Data Ringkasan untuk Tampilan Halaman Web ───────────────────────────
$totalPesertaAll = (int)$pdo->query("SELECT COUNT(*) FROM pretes_registrations pr JOIN users u ON pr.user_id = u.id")->fetchColumn();
$totalWithCred   = (int)$pdo->query("SELECT COUNT(*) FROM pretes_registrations WHERE username_tes IS NOT NULL AND username_tes != ''")->fetchColumn();
$totalNoCred     = $totalPesertaAll - $totalWithCred;
$totalFiltered   = count($rows);

// Daftar Hari & Jumlah masing-masing di database
$dayStats = ['Senin' => 0, 'Selasa' => 0, 'Rabu' => 0, 'Kamis' => 0, 'Jumat' => 0];
$stmtDays = $pdo->query("
    SELECT TRIM(hari_pilihan) as hari, COUNT(*) as cnt 
    FROM pretes_registrations 
    GROUP BY TRIM(hari_pilihan)
");
while ($r = $stmtDays->fetch()) {
    $dh = ucfirst(strtolower(trim($r['hari'] ?? '')));
    if (isset($dayStats[$dh])) {
        $dayStats[$dh] = (int)$r['cnt'];
    }
}

// Daftar Semua Program Studi
$allProdi = $pdo->query("
    SELECT DISTINCT u.program_studi, COUNT(*) as cnt
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    WHERE u.program_studi IS NOT NULL AND u.program_studi != ''
    GROUP BY u.program_studi
    ORDER BY u.program_studi ASC
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<!-- ── Breadcrumb & Top Navigation ──────────────────────────────────────── -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
    <div>
        <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:#64748b;margin-bottom:4px;">
            <a href="dashboard.php" style="color:#64748b;text-decoration:none;">Dashboard</a>
            <span>›</span>
            <a href="pretes-peserta.php" style="color:#64748b;text-decoration:none;">Data Peserta Pretes</a>
            <span>›</span>
            <span style="color:#1a5632;font-weight:600;">Export ke Excel</span>
        </div>
        <h2 style="font-size:22px;font-weight:800;color:#0f172a;margin:0;display:flex;align-items:center;gap:10px;">
            <span>📗</span> Halaman Export Data Peserta & Credentials Pretes
        </h2>
        <p style="font-size:13px;color:#64748b;margin:4px 0 0;">
            Unduh data lengkap peserta tes tulis pretes dalam format spreadsheet (.xlsx / .csv) dengan pengaturan filter dan opsi kolom kustom.
        </p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <a href="pretes-peserta.php" class="btn btn-sm btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:10px;font-weight:600;text-decoration:none;">
            ← Kembali ke Data Peserta
        </a>
    </div>
</div>

<!-- ── Statistik Ringkas ────────────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;">
    <div class="stat-card" style="background:#fff;border-radius:14px;padding:18px 20px;border:1px solid #e2e8f0;display:flex;align-items:center;gap:16px;">
        <div class="stat-icon blue" style="width:48px;height:48px;border-radius:12px;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:22px;">
            👥
        </div>
        <div class="stat-info">
            <h3 style="font-size:24px;font-weight:800;color:#0f172a;margin:0;"><?= $totalPesertaAll ?></h3>
            <p style="font-size:13px;color:#64748b;margin:0;">Total Semua Peserta</p>
        </div>
    </div>

    <div class="stat-card" style="background:#fff;border-radius:14px;padding:18px 20px;border:1.5px solid #16a34a;display:flex;align-items:center;gap:16px;box-shadow:0 2px 8px rgba(22,163,74,0.08);">
        <div class="stat-icon green" style="width:48px;height:48px;border-radius:12px;background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center;font-size:22px;">
            🎯
        </div>
        <div class="stat-info">
            <h3 style="font-size:24px;font-weight:800;color:#16a34a;margin:0;"><?= $totalFiltered ?></h3>
            <p style="font-size:13px;color:#166534;margin:0;font-weight:600;">Peserta Siap Diexport (Sesuai Filter)</p>
        </div>
    </div>

    <div class="stat-card" style="background:#fff;border-radius:14px;padding:18px 20px;border:1px solid #e2e8f0;display:flex;align-items:center;gap:16px;">
        <div class="stat-icon" style="width:48px;height:48px;border-radius:12px;background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;font-size:22px;">
            🔑
        </div>
        <div class="stat-info">
            <h3 style="font-size:24px;font-weight:800;color:#0f172a;margin:0;"><?= $totalWithCred ?></h3>
            <p style="font-size:13px;color:#64748b;margin:0;">Sudah Punya Credentials</p>
        </div>
    </div>

    <div class="stat-card" style="background:#fff;border-radius:14px;padding:18px 20px;border:1px solid #e2e8f0;display:flex;align-items:center;gap:16px;">
        <div class="stat-icon orange" style="width:48px;height:48px;border-radius:12px;background:#fff7ed;color:#ea580c;display:flex;align-items:center;justify-content:center;font-size:22px;">
            ⏳
        </div>
        <div class="stat-info">
            <h3 style="font-size:24px;font-weight:800;color:#0f172a;margin:0;"><?= $totalNoCred ?></h3>
            <p style="font-size:13px;color:#64748b;margin:0;">Belum Ada Credentials</p>
        </div>
    </div>
</div>

<!-- ── Quick Export 1-Klik ──────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 2px 10px rgba(0,0,0,0.03);">
    <div class="card-header" style="padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <strong style="font-size:14px;color:#0f172a;display:flex;align-items:center;gap:8px;">
            <span>⚡</span> Tombol Cepat Ekspor Excel (1-Klik Unduh)
        </strong>
        <span style="font-size:12px;color:#64748b;">Langsung mengunduh file .xlsx dengan seluruh kolom aktif</span>
    </div>
    <div class="card-body" style="padding:18px 20px;">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <!-- Unduh Semua -->
            <a href="pretes-peserta-export.php?download=1&format=xlsx" data-no-spa="true"
               class="btn" style="background:#1a5632;color:#fff;border:none;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:8px;text-decoration:none;box-shadow:0 2px 6px rgba(26,86,50,0.25);transition:all 0.2s;">
                📥 Unduh SEMUA Peserta (<?= $totalPesertaAll ?>)
            </a>

            <!-- Per Hari -->
            <?php foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'] as $day): ?>
                <a href="pretes-peserta-export.php?download=1&format=xlsx&hari=<?= $day ?>" data-no-spa="true"
                   class="btn" style="background:#f8fafc;color:#1e293b;border:1.5px solid #cbd5e1;border-radius:10px;padding:9px 14px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:6px;text-decoration:none;transition:all 0.2s;">
                    📅 Khusus Hari <?= $day ?> (<?= $dayStats[$day] ?? 0 ?>)
                </a>
            <?php endforeach; ?>

            <!-- Khusus Yang Sudah Ada Credentials -->
            <a href="pretes-peserta-export.php?download=1&format=xlsx&cred=has_cred" data-no-spa="true"
               class="btn" style="background:#eff6ff;color:#1d4ed8;border:1.5px solid #bfdbfe;border-radius:10px;padding:9px 14px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:6px;text-decoration:none;transition:all 0.2s;">
                🔑 Khusus Ber-Credentials (<?= $totalWithCred ?>)
            </a>
        </div>
    </div>
</div>

<!-- ── Panel Pengaturan & Filter Export Kustom ──────────────────────────── -->
<div class="card" style="margin-bottom:24px;background:#ffffff;border:1.5px solid #e2e8f0;border-radius:16px;box-shadow:0 4px 16px rgba(0,0,0,0.04);">
    <div class="card-header" style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:16px 20px;border-top-left-radius:16px;border-top-right-radius:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:10px;background:#e0f2fe;color:#0284c7;font-size:16px;">
                ⚙️
            </span>
            <div>
                <strong style="font-size:15px;color:#0f172a;display:block;">Filter & Kustomisasi Kolom Export</strong>
                <span style="font-size:12px;color:#64748b;">Pilih kriteria data dan centang kolom yang ingin disertakan di file Excel</span>
            </div>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="pretes-peserta-export.php" class="btn btn-sm" style="background:#ffffff;border:1px solid #cbd5e1;color:#475569;font-weight:600;font-size:12px;padding:6px 14px;border-radius:8px;text-decoration:none;">
                🔄 Reset Form
            </a>
        </div>
    </div>

    <div class="card-body" style="padding:22px 24px;">
        <form method="GET" action="pretes-peserta-export.php" id="form-export">
            <!-- 1. Grid Filter Data -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:22px;">
                <!-- Filter Hari -->
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">
                        📅 Hari Pelaksanaan
                    </label>
                    <select name="hari" id="filter-hari" style="width:100%;padding:9px 12px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:13px;background:#fff;outline:none;">
                        <option value="">Semua Hari (<?= $totalPesertaAll ?>)</option>
                        <?php foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'] as $day): ?>
                            <option value="<?= $day ?>" <?= ($filterHari === $day) ? 'selected' : '' ?>>
                                <?= $day ?> (<?= $dayStats[$day] ?? 0 ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Filter Program Studi -->
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">
                        🎓 Program Studi
                    </label>
                    <select name="prodi" id="filter-prodi" style="width:100%;padding:9px 12px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:13px;background:#fff;outline:none;">
                        <option value="">Semua Prodi (<?= count($allProdi) ?>)</option>
                        <?php foreach ($allProdi as $p): ?>
                            <option value="<?= sanitize($p['program_studi']) ?>" <?= ($filterProdi === $p['program_studi']) ? 'selected' : '' ?>>
                                <?= sanitize($p['program_studi']) ?> (<?= $p['cnt'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Filter Status Hadir -->
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">
                        📋 Status Kehadiran
                    </label>
                    <select name="status" id="filter-status" style="width:100%;padding:9px 12px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:13px;background:#fff;outline:none;">
                        <option value="">Semua Status Kehadiran</option>
                        <option value="terdaftar" <?= ($filterStatus === 'terdaftar') ? 'selected' : '' ?>>Terdaftar</option>
                        <option value="hadir" <?= ($filterStatus === 'hadir') ? 'selected' : '' ?>>Hadir</option>
                        <option value="tidak_hadir" <?= ($filterStatus === 'tidak_hadir') ? 'selected' : '' ?>>Tidak Hadir</option>
                    </select>
                </div>

                <!-- Filter Credentials -->
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">
                        🔑 Status Credentials
                    </label>
                    <select name="cred" id="filter-cred" style="width:100%;padding:9px 12px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:13px;background:#fff;outline:none;">
                        <option value="">Semua Status Credentials</option>
                        <option value="has_cred" <?= ($filterCred === 'has_cred') ? 'selected' : '' ?>>Sudah Ada Credentials (<?= $totalWithCred ?>)</option>
                        <option value="no_cred" <?= ($filterCred === 'no_cred') ? 'selected' : '' ?>>Belum Ada Credentials (<?= $totalNoCred ?>)</option>
                    </select>
                </div>

                <!-- Pencarian Cepat -->
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">
                        🔍 Cari Nama / NIM
                    </label>
                    <input type="text" name="q" value="<?= sanitize($filterSearch) ?>" placeholder="Ketik nama / NIM..."
                           style="width:100%;padding:9px 12px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:13px;background:#fff;outline:none;box-sizing:border-box;">
                </div>
            </div>

            <!-- 2. Pilihan Kolom yang Diexport -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;margin-bottom:20px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
                    <div style="font-size:13px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:6px;">
                        <span>📋</span> Pilih Kolom yang Disertakan dalam File:
                    </div>
                    <div style="display:flex;gap:10px;">
                        <button type="button" onclick="toggleAllColumns(true)" style="background:none;border:none;color:#2563eb;font-size:12px;font-weight:600;cursor:pointer;padding:0;">
                            ✓ Centang Semua
                        </button>
                        <span style="color:#cbd5e1;">|</span>
                        <button type="button" onclick="toggleAllColumns(false)" style="background:none;border:none;color:#64748b;font-size:12px;font-weight:600;cursor:pointer;padding:0;">
                            ✗ Batalkan Semua
                        </button>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(180px, 1fr));gap:10px;">
                    <?php foreach ($availableColumns as $colKey => $colMeta): ?>
                        <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:#334155;cursor:pointer;background:#ffffff;border:1px solid #e2e8f0;padding:7px 10px;border-radius:8px;">
                            <input type="checkbox" name="cols[]" value="<?= $colKey ?>" class="col-checkbox"
                                   <?= in_array($colKey, $selectedCols) ? 'checked' : '' ?>
                                   style="cursor:pointer;width:15px;height:15px;accent-color:#1a5632;">
                            <span><?= sanitize($colMeta['label']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 3. Format File & Tombol Aksi Utama -->
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;padding-top:12px;border-top:1px solid #f1f5f9;">
                <!-- Pilihan Format -->
                <div style="display:flex;align-items:center;gap:16px;">
                    <span style="font-size:13px;font-weight:700;color:#334155;">Format File:</span>
                    <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer;color:#1e293b;">
                        <input type="radio" name="format" value="xlsx" <?= ($format === 'xlsx') ? 'checked' : '' ?> style="accent-color:#1a5632;width:16px;height:16px;">
                        <span>Microsoft Excel (.xlsx) <small style="color:#16a34a;font-weight:bold;">[Rekomendasi]</small></span>
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer;color:#1e293b;">
                        <input type="radio" name="format" value="csv" <?= ($format === 'csv') ? 'checked' : '' ?> style="accent-color:#1a5632;width:16px;height:16px;">
                        <span>CSV (.csv)</span>
                    </label>
                </div>

                <!-- Tombol Submit & Download -->
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <!-- Tombol Preview / Terapkan Filter -->
                    <button type="submit" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;font-weight:600;">
                        🔍 Terapkan Filter Pratinjau
                    </button>

                    <!-- Tombol Download Langsung -->
                    <button type="button" onclick="triggerDownload()" class="btn btn-primary" data-no-spa="true"
                            style="background:#1a5632;border-color:#1a5632;display:inline-flex;align-items:center;gap:8px;padding:10px 22px;border-radius:10px;font-weight:700;box-shadow:0 3px 10px rgba(26,86,50,0.3);">
                        📥 Unduh File Sekarang (<?= $totalFiltered ?> Data)
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Tabel Pratinjau Data (Live Preview) ──────────────────────────────── -->
<div class="card" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 2px 10px rgba(0,0,0,0.03);">
    <div class="card-header" style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:18px;">👁️</span>
            <div>
                <strong style="font-size:15px;color:#0f172a;display:block;">
                    Pratinjau Data yang Akan Diexport (<?= $totalFiltered ?> Peserta)
                </strong>
                <span style="font-size:12px;color:#64748b;">
                    Menampilkan data sesuai filter yang aktif sebelum Anda mengunduh file
                </span>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <span style="font-size:12px;background:#e0f2fe;color:#0369a1;padding:4px 10px;border-radius:20px;font-weight:700;">
                <?= count($selectedCols) ?> Kolom Terpilih
            </span>
        </div>
    </div>

    <div class="card-body" style="padding:0;">
        <?php if (empty($rows)): ?>
            <div style="padding:50px 20px;text-align:center;color:#64748b;">
                <div style="font-size:40px;margin-bottom:10px;">🔍</div>
                <h4 style="font-size:16px;color:#1e293b;margin:0 0 6px;">Tidak ada data peserta yang cocok dengan filter</h4>
                <p style="font-size:13px;margin:0;">Silakan ubah kriteria filter di atas atau klik Reset Form.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive" style="max-height:550px;overflow-y:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:12.5px;text-align:left;">
                    <thead style="position:sticky;top:0;background:#0d2818;color:#fff;z-index:10;">
                        <tr>
                            <?php foreach ($selectedCols as $colKey): ?>
                                <th style="padding:10px 14px;font-weight:600;white-space:nowrap;border-bottom:2px solid #1a5632;">
                                    <?= sanitize($availableColumns[$colKey]['label'] ?? $colKey) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $idx => $r): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;background:<?= ($idx % 2 === 0) ? '#ffffff' : '#f8fafc' ?>;">
                                <?php foreach ($selectedCols as $colKey): ?>
                                    <td style="padding:10px 14px;color:#334155;vertical-align:middle;white-space:nowrap;">
                                        <?php switch ($colKey): 
                                            case 'no': ?>
                                                <span style="font-weight:600;color:#64748b;"><?= $idx + 1 ?></span>
                                                <?php break; ?>

                                            <?php case 'nama_lengkap': ?>
                                                <strong style="color:#0f172a;"><?= sanitize($r['nama_lengkap']) ?></strong>
                                                <?php break; ?>

                                            <?php case 'nim': ?>
                                                <code style="font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:4px;color:#334155;"><?= sanitize($r['nim']) ?></code>
                                                <?php break; ?>

                                            <?php case 'program_studi': ?>
                                                <?= sanitize($r['program_studi'] ?: '-') ?>
                                                <?php break; ?>

                                            <?php case 'fakultas': ?>
                                                <?= sanitize($r['fakultas'] ?: '-') ?>
                                                <?php break; ?>

                                            <?php case 'hari_pilihan': ?>
                                                <span style="display:inline-block;padding:2px 8px;border-radius:6px;background:#dbeafe;color:#1e40af;font-weight:600;font-size:11.5px;">
                                                    <?= sanitize(ucfirst(strtolower(trim($r['hari_pilihan'] ?? '')))) ?: '-' ?>
                                                </span>
                                                <?php break; ?>

                                            <?php case 'jam': ?>
                                                <span style="color:#64748b;font-size:12px;"><?= sanitize($r['jam'] ?: '13.00 WIB') ?></span>
                                                <?php break; ?>

                                            <?php case 'ruangan': ?>
                                                <?= sanitize($r['ruangan'] ?: '-') ?>
                                                <?php break; ?>

                                            <?php case 'dosen_penguji': ?>
                                                <?= sanitize($r['dosen_penguji'] ?: '-') ?>
                                                <?php break; ?>

                                            <?php case 'status': ?>
                                                <?php 
                                                $st = strtolower(trim($r['status'] ?? 'terdaftar'));
                                                $bgSt = '#f1f5f9'; $cSt = '#475569';
                                                if ($st === 'hadir') { $bgSt = '#dcfce7'; $cSt = '#15803d'; }
                                                elseif ($st === 'tidak_hadir') { $bgSt = '#fee2e2'; $cSt = '#b91c1c'; }
                                                ?>
                                                <span style="display:inline-block;padding:2px 8px;border-radius:6px;background:<?= $bgSt ?>;color:<?= $cSt ?>;font-weight:600;font-size:11.5px;">
                                                    <?= ucfirst(str_replace('_', ' ', $st)) ?>
                                                </span>
                                                <?php break; ?>

                                            <?php case 'username_tes': ?>
                                                <?php if (!empty($r['username_tes'])): ?>
                                                    <code style="background:#eff6ff;color:#1d4ed8;padding:2px 6px;border-radius:4px;font-size:12px;font-weight:600;">
                                                        <?= sanitize($r['username_tes']) ?>
                                                    </code>
                                                <?php else: ?>
                                                    <span style="color:#cbd5e1;font-style:italic;">— belum ada —</span>
                                                <?php endif; ?>
                                                <?php break; ?>

                                            <?php case 'password_tes': ?>
                                                <?php if (!empty($r['password_tes'])): ?>
                                                    <code style="background:#fef3c7;color:#b45309;padding:2px 6px;border-radius:4px;font-size:12px;font-weight:700;">
                                                        <?= sanitize($r['password_tes']) ?>
                                                    </code>
                                                <?php else: ?>
                                                    <span style="color:#cbd5e1;font-style:italic;">— belum ada —</span>
                                                <?php endif; ?>
                                                <?php break; ?>

                                            <?php case 'no_hp': ?>
                                                <?= sanitize($r['no_hp'] ?: '-') ?>
                                                <?php break; ?>

                                            <?php case 'email': ?>
                                                <?= sanitize($r['email'] ?: '-') ?>
                                                <?php break; ?>

                                            <?php case 'periode': ?>
                                                <?= sanitize($r['periode'] ?: '-') ?>
                                                <?php break; ?>

                                            <?php case 'tanggal_daftar': ?>
                                                <span style="color:#64748b;font-size:11.5px;">
                                                    <?= !empty($r['tanggal_daftar']) ? date('d/m/Y H:i', strtotime($r['tanggal_daftar'])) : '-' ?>
                                                </span>
                                                <?php break; ?>

                                            <?php default: ?>
                                                -
                                        <?php endswitch; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="padding:12px 20px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <span>Menampilkan <strong><?= count($rows) ?></strong> data peserta siap diexport.</span>
                <span>Klik tombol hijau <strong>"Unduh File Sekarang"</strong> di atas untuk menyimpan file spreadsheet ke komputer Anda.</span>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Fungsi Centang Semua / Batalkan Kolom
function toggleAllColumns(status) {
    document.querySelectorAll('.col-checkbox').forEach(function(cb) {
        cb.checked = status;
    });
}

// Fungsi Trigger Download Langsung dari Form
function triggerDownload() {
    var form = document.getElementById('form-export');
    var formData = new FormData(form);
    var params = new URLSearchParams();

    params.set('download', '1');

    for (var pair of formData.entries()) {
        params.append(pair[0], pair[1]);
    }

    var downloadUrl = 'pretes-peserta-export.php?' + params.toString();
    window.location.href = downloadUrl;
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
