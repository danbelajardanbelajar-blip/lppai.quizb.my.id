<?php
/**
 * LPPAI Corner - Rekapitulasi Nilai Terpadu
 */
define('PAGE_TITLE', 'Rekapitulasi Nilai');
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = getCurrentUser();
$pdo = getDBConnection();

$isAdmin = isAdmin();
$isDosen = isDosen();
$isMahasiswa = !$isAdmin && !$isDosen;

// Mahasiswa yang sedang di tahap pretes aktif → tampilkan kartu Tahap Pretes
if ($isMahasiswa) {
    require_once __DIR__ . '/includes/pretes-stage.php';
    pretesStageGate($pdo, $user, 'Rincian nilai tutorial akan tersedia bila Anda mengikuti program Tutorial. Nilai pretes dapat dilihat di menu Hasil Pretes.');

    // Peserta tutorial yang belum diplot → kartu Menunggu Plotting (seragam dengan Info Tutorial)
    require_once __DIR__ . '/includes/tutorial-waiting.php';
    tutorialWaitingGate($pdo, $user, 'Rincian nilai tutorial akan tersedia setelah Anda mendapatkan kelas dan mulai mengikuti perkuliahan tutorial.');
}

$action = $_GET['action'] ?? '';

// Label gelombang
$gelLabels = [
    'gel1' => 'Gelombang 1 (Ganjil)',
    'gel2' => 'Gelombang 2 (Genap)',
    'mandiri' => 'Mandiri'
];

// Ambil seluruh daftar periode dari master_gelombang dan tentukan periode aktif
$allGelombang = [];
$active_gel = null;
try {
    $active_gel = $pdo->query("SELECT * FROM master_gelombang WHERE status = 'aktif' ORDER BY created_at DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $allGelombang = $pdo->query("SELECT * FROM master_gelombang ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    if (!$active_gel && !empty($allGelombang)) {
        $active_gel = $allGelombang[0];
    }
} catch (Exception $e) {}

// Parameter filter periode (default selalu 'active' sesuai instruksi)
$selected_gel_id = isset($_GET['periode']) ? trim($_GET['periode']) : (isset($_GET['gelombang_id']) ? trim($_GET['gelombang_id']) : 'active');
$current_gel = null;

if ($selected_gel_id === 'all') {
    $current_gel = null;
} elseif (is_numeric($selected_gel_id)) {
    foreach ($allGelombang as $g) {
        if ($g['id'] == $selected_gel_id) {
            $current_gel = $g;
            break;
        }
    }
    if (!$current_gel) {
        $current_gel = $active_gel;
        $selected_gel_id = 'active';
    }
} else {
    $selected_gel_id = 'active';
    $current_gel = $active_gel;
}

// Persiapan parameter filter periode aktif
$targetYear = '';
$targetYearRaw = '';
$targetSemester = '';
$targetGelombang = '';
$targetYearSem = '';

if ($current_gel) {
    $targetYear = str_replace(['/', ' '], ['-', ''], trim($current_gel['tahun_ajaran']));
    $targetYearRaw = trim($current_gel['tahun_ajaran']);
    $targetSemester = trim($current_gel['semester']);
    $targetGelombang = trim($current_gel['gelombang']);
    $targetYearSem = $targetYear . '-' . $targetSemester;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_nilai') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }
    
    $reg_id = $_POST['reg_id'] ?? '';
    $thaharah = $_POST['nilai_thaharah'] !== '' ? (float)$_POST['nilai_thaharah'] : null;
    $shalat = $_POST['nilai_shalat'] !== '' ? (float)$_POST['nilai_shalat'] : null;
    $srt_pdk = $_POST['nilai_surat_pendek'] !== '' ? (float)$_POST['nilai_surat_pendek'] : null;
    $amaliyah = $_POST['nilai_amaliyah'] !== '' ? (float)$_POST['nilai_amaliyah'] : null;
    $jenazah = $_POST['nilai_jenazah'] !== '' ? (float)$_POST['nilai_jenazah'] : null;
    $ut = $_POST['nilai_ujian_tulis'] !== '' ? (float)$_POST['nilai_ujian_tulis'] : null;
    
    if ($reg_id && ($isAdmin || $isDosen)) {
        $can_edit = true;
        if ($isDosen) {
            $check_stmt = $pdo->prepare("
                SELECT tc.dosen_pengampu 
                FROM tutorial_registrations tr
                JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
                WHERE tr.id = ?
            ");
            $check_stmt->execute([$reg_id]);
            $res = $check_stmt->fetch();
            if (!$res || $res['dosen_pengampu'] !== $user['nama_lengkap']) {
                $can_edit = false;
            }
        }
        
        if ($can_edit) {
            $upd = $pdo->prepare("
                UPDATE tutorial_registrations 
                SET nilai_thaharah = ?, nilai_shalat = ?, nilai_surat_pendek = ?, 
                    nilai_amaliyah = ?, nilai_jenazah = ?, nilai_ujian_tulis = ?
                WHERE id = ?
            ");
            $upd->execute([$thaharah, $shalat, $srt_pdk, $amaliyah, $jenazah, $ut, $reg_id]);
        }
    }
    exit('success');
}

if ($isAdmin || $isDosen) {
    // 1. Fetch daftar kelas untuk Dropdown Filter (hanya dari periode yang dipilih/aktif)
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $concatExpr = ($driver === 'sqlite') 
        ? "GROUP_CONCAT(tc.dosen_pengampu, ', ')" 
        : "GROUP_CONCAT(DISTINCT tc.dosen_pengampu SEPARATOR ', ')";

    $classWhere = [];
    $classParams = [];

    if ($current_gel) {
        $classWhere[] = "tc.gelombang = ?";
        $classParams[] = $targetGelombang;
        $classWhere[] = "(
            TRIM(tc.semester) = ?
            OR REPLACE(TRIM(tc.semester), '/', '-') = ?
            OR REPLACE(TRIM(tc.semester), ' ', '') LIKE ?
            OR tc.semester LIKE ?
        )";
        $classParams[] = $targetSemester;
        $classParams[] = $targetYearSem;
        $classParams[] = '%' . $targetYear . '%';
        $classParams[] = '%' . $targetYear . '%';
    }

    if ($isDosen) {
        $classWhere[] = "tc.dosen_pengampu = ?";
        $classParams[] = $user['nama_lengkap'];
    }

    $whereClassStr = !empty($classWhere) ? "WHERE " . implode(" AND ", $classWhere) : "";

    $stmtClasses = $pdo->prepare("
        SELECT tc.nama_kelas, tc.gelombang, $concatExpr as dosen_pengampu, COUNT(tr.id) as jml_mhs
        FROM tutorial_classes tc
        LEFT JOIN tutorial_registrations tr ON tc.id = tr.tutorial_class_id
        $whereClassStr
        GROUP BY tc.gelombang, tc.nama_kelas
        ORDER BY tc.gelombang ASC, tc.nama_kelas ASC
    ");
    $stmtClasses->execute($classParams);
    $unique_classes = $stmtClasses->fetchAll();
    
    $filter_class = $_GET['class_name'] ?? '';
    $studentsWhere = [];
    $studentsParams = [];

    // 2. Build Query untuk mengambil mahasiswa (Filtered per periode aktif)
    if ($current_gel) {
        $studentsWhere[] = "tc.gelombang = ?";
        $studentsParams[] = $targetGelombang;
        $studentsWhere[] = "(
            TRIM(tc.semester) = ?
            OR REPLACE(TRIM(tc.semester), '/', '-') = ?
            OR REPLACE(TRIM(tc.semester), ' ', '') LIKE ?
            OR tc.semester LIKE ?
        )";
        $studentsParams[] = $targetSemester;
        $studentsParams[] = $targetYearSem;
        $studentsParams[] = '%' . $targetYear . '%';
        $studentsParams[] = '%' . $targetYear . '%';
    }

    if ($isDosen) {
        $studentsWhere[] = "tc.dosen_pengampu = ?";
        $studentsParams[] = $user['nama_lengkap'];
    }

    if ($filter_class !== '') {
        $studentsWhere[] = "tc.nama_kelas = ?";
        $studentsParams[] = $filter_class;
    }

    $whereStudentsStr = !empty($studentsWhere) ? "WHERE " . implode(" AND ", $studentsWhere) : "";

    $sql = "
        SELECT tr.*, u.nama_lengkap, u.nim, u.program_studi, u.jenis_program, tc.nama_kelas, tc.gelombang, tc.semester as class_semester, tc.dosen_pengampu
        FROM tutorial_registrations tr
        JOIN users u ON tr.user_id = u.id
        JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
        $whereStudentsStr
        ORDER BY tc.gelombang ASC, tc.nama_kelas ASC, u.nama_lengkap ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($studentsParams);
    $students = $stmt->fetchAll();
    
    // 3. EXPORT LOGIC
    if ($action === 'export') {
        $possible_paths = [
            __DIR__ . '/vendor/autoload.php',
            __DIR__ . '/../vendor/autoload.php',
            __DIR__ . '/../../vendor/autoload.php',
            __DIR__ . '/../../../vendor/autoload.php',
            $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php',
            $_SERVER['DOCUMENT_ROOT'] . '/../vendor/autoload.php',
            '/public_html/vendor/autoload.php'
        ];
        
        $vendorPath = null;
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                $vendorPath = $path;
                break;
            }
        }
        
        if (!$vendorPath) {
            die("<div style='padding:20px; color:red; font-family:sans-serif;'>Library Excel (PhpSpreadsheet) tidak ditemukan di server. Harap pastikan folder vendor ada di public_html.</div>");
        }
        
        require_once $vendorPath;
        
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $sheet->setCellValue('A1', 'No');
        $sheet->setCellValue('B1', 'Gelombang');
        $sheet->setCellValue('C1', 'Kelas');
        $sheet->setCellValue('D1', 'NIM');
        $sheet->setCellValue('E1', 'Nama Lengkap');
        $sheet->setCellValue('F1', 'Prodi');
        $sheet->setCellValue('G1', 'Jenis Program');
        $sheet->setCellValue('H1', 'Thaharah');
        $sheet->setCellValue('I1', 'Shalat');
        $sheet->setCellValue('J1', 'Surat Pendek');
        $sheet->setCellValue('K1', 'Amaliyah');
        $sheet->setCellValue('L1', 'Jenazah');
        $sheet->setCellValue('M1', 'Ujian Tulis');
        $sheet->setCellValue('N1', 'Nilai Akhir');
        
        // Style headers
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
        ];
        $sheet->getStyle('A1:N1')->applyFromArray($headerStyle);
        
        $row = 2;
        $no = 1;
        foreach($students as $s) {
            $sheet->setCellValue('A'.$row, $no++);
            $sheet->setCellValueExplicit('B'.$row, $s['gelombang'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('C'.$row, $s['nama_kelas']);
            $sheet->setCellValueExplicit('D'.$row, $s['nim'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('E'.$row, $s['nama_lengkap']);
            $sheet->setCellValue('F'.$row, $s['program_studi']);
            $sheet->setCellValue('G'.$row, normalizeJenisProgram($s['jenis_program'] ?? 'Reguler'));
            $sheet->setCellValue('H'.$row, $s['nilai_thaharah'] ?? '-');
            $sheet->setCellValue('I'.$row, $s['nilai_shalat'] ?? '-');
            $sheet->setCellValue('J'.$row, $s['nilai_surat_pendek'] ?? '-');
            $sheet->setCellValue('K'.$row, $s['nilai_amaliyah'] ?? '-');
            $sheet->setCellValue('L'.$row, $s['nilai_jenazah'] ?? '-');
            $sheet->setCellValue('M'.$row, $s['nilai_ujian_tulis'] ?? '-');
            
            $sum = 0; $count = 0;
            if ($s['nilai_thaharah'] !== null) { $sum += (float)$s['nilai_thaharah']; $count++; }
            if ($s['nilai_shalat'] !== null) { $sum += (float)$s['nilai_shalat']; $count++; }
            if ($s['nilai_surat_pendek'] !== null) { $sum += (float)$s['nilai_surat_pendek']; $count++; }
            if ($s['nilai_amaliyah'] !== null) { $sum += (float)$s['nilai_amaliyah']; $count++; }
            if ($s['nilai_jenazah'] !== null) { $sum += (float)$s['nilai_jenazah']; $count++; }
            if ($s['nilai_ujian_tulis'] !== null) { $sum += (float)$s['nilai_ujian_tulis']; $count++; }
            $calc_akhir = $count > 0 ? round($sum / $count, 2) : '-';
            
            $sheet->setCellValue('N'.$row, $calc_akhir);
            
            $sheet->getStyle('A'.$row.':N'.$row)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $row++;
        }
        
        foreach(range('A','N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        $periodLabel = $current_gel ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $targetYear . '_' . $targetSemester . '_' . $targetGelombang) : 'SemuaPeriode';
        $classLabel = $filter_class ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $filter_class) : 'SemuaKelas';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'Rekap_Nilai_' . $periodLabel . '_' . $classLabel . '_' . date('Ymd') . '.xlsx';
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.urlencode($filename).'"');
        header('Cache-Control: max-age=0');
        
        $writer->save('php://output');
        exit;
    }
} else {
    // Mahasiswa Logic (prioritaskan kelas periode aktif)
    $mhsWhere = ["tr.user_id = ?"];
    $mhsParams = [$user['id']];

    if ($current_gel) {
        $mhsWhere[] = "tc.gelombang = ?";
        $mhsParams[] = $targetGelombang;
        $mhsWhere[] = "(
            TRIM(tc.semester) = ?
            OR REPLACE(TRIM(tc.semester), '/', '-') = ?
            OR REPLACE(TRIM(tc.semester), ' ', '') LIKE ?
            OR tc.semester LIKE ?
        )";
        $mhsParams[] = $targetSemester;
        $mhsParams[] = $targetYearSem;
        $mhsParams[] = '%' . $targetYear . '%';
        $mhsParams[] = '%' . $targetYear . '%';
    }

    $whereMhsStr = "WHERE " . implode(" AND ", $mhsWhere);

    $stmt = $pdo->prepare("
        SELECT tr.*, tc.nama_kelas, tc.dosen_pengampu, tc.gelombang, tc.semester as class_semester,
               tc.hari, tc.jam, tc.ruangan
        FROM tutorial_registrations tr
        JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
        $whereMhsStr
        ORDER BY tr.created_at DESC
    ");
    $stmt->execute($mhsParams);
    $my_classes = $stmt->fetchAll();

    // Fallback: Jika belum ada di periode aktif, tampilkan kelas historis milik mahasiswa
    if (empty($my_classes) && $current_gel) {
        $stmtFallback = $pdo->prepare("
            SELECT tr.*, tc.nama_kelas, tc.dosen_pengampu, tc.gelombang, tc.semester as class_semester,
                   tc.hari, tc.jam, tc.ruangan
            FROM tutorial_registrations tr
            JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
            WHERE tr.user_id = ?
            ORDER BY tr.created_at DESC
        ");
        $stmtFallback->execute([$user['id']]);
        $my_classes = $stmtFallback->fetchAll();
    }
}

// ── Helper Metrik & Komponen Penilaian Tutorial LPPAI ──────────────────────
if (!function_exists('getTutorialScoreMeta')) {
    function getTutorialScoreMeta($score): array {
        if ($score === null || $score === '' || !is_numeric($score)) {
            return [
                'grade' => '-',
                'label' => 'Belum Dinilai',
                'color' => '#64748b',
                'bg'    => '#f1f5f9',
                'border'=> '#cbd5e1',
                'tier'  => 'none',
                'pct'   => 0
            ];
        }
        $val = (float)$score;
        $pct = max(0, min(100, round($val, 1)));
        if ($val >= 85) {
            return [
                'grade' => 'A',
                'label' => 'Sangat Memuaskan',
                'color' => '#047857',
                'bg'    => '#d1fae5',
                'border'=> '#6ee7b7',
                'tier'  => 'excellent',
                'pct'   => $pct
            ];
        } elseif ($val >= 75) {
            return [
                'grade' => 'B',
                'label' => 'Baik',
                'color' => '#0369a1',
                'bg'    => '#e0f2fe',
                'border'=> '#7dd3fc',
                'tier'  => 'good',
                'pct'   => $pct
            ];
        } elseif ($val >= 65) {
            return [
                'grade' => 'C',
                'label' => 'Cukup',
                'color' => '#b45309',
                'bg'    => '#fef3c7',
                'border'=> '#fde68a',
                'tier'  => 'fair',
                'pct'   => $pct
            ];
        } elseif ($val >= 55) {
            return [
                'grade' => 'D',
                'label' => 'Kurang',
                'color' => '#c2410c',
                'bg'    => '#ffedd5',
                'border'=> '#fed7aa',
                'tier'  => 'poor',
                'pct'   => $pct
            ];
        } else {
            return [
                'grade' => 'E',
                'label' => 'Sangat Kurang',
                'color' => '#b91c1c',
                'bg'    => '#fee2e2',
                'border'=> '#fca5a5',
                'tier'  => 'fail',
                'pct'   => $pct
            ];
        }
    }
}

$tutorialComponents = [
    'nilai_thaharah' => [
        'name'  => 'Thaharah & Wudhu',
        'icon'  => '💧',
        'short' => 'Thaharah',
        'desc'  => 'Rukun & tata cara bersuci dari hadats kecil/besar, kesempurnaan wudhu, dan tayammum.'
    ],
    'nilai_shalat' => [
        'name'  => 'Praktik Shalat',
        'icon'  => '🕋',
        'short' => 'Shalat',
        'desc'  => 'Gerakan, bacaan fardhu & sunnah, tuma\'ninah, serta kesempurnaan shalat lima waktu.'
    ],
    'nilai_surat_pendek' => [
        'name'  => 'Surat Pendek',
        'icon'  => '📖',
        'short' => 'Srt Pdk',
        'desc'  => 'Kelancaran hafalan surat juz 30, makharijul huruf, dan kaidah tajwid Al-Qur\'an.'
    ],
    'nilai_amaliyah' => [
        'name'  => 'Praktik Amaliyah',
        'icon'  => '🤲',
        'short' => 'Amaliyah',
        'desc'  => 'Doa harian, dzikir ba\'da shalat fardhu, wirid pilihan, dan adab-adab ubudiyah.'
    ],
    'nilai_jenazah' => [
        'name'  => 'Perawatan Jenazah',
        'icon'  => '🕊️',
        'short' => 'Jenazah',
        'desc'  => 'Kewajiban fardhu kifayah: tata cara memandikan, mengafani, menyalatkan, & mengubur.'
    ],
    'nilai_ujian_tulis' => [
        'name'  => 'Ujian Tulis',
        'icon'  => '✍️',
        'short' => 'UT',
        'desc'  => 'Evaluasi komprehensif penguasaan materi Diktat Resmi LPPAI 2026 secara tertulis.'
    ],
];

// Perhitungan Statistik untuk Admin & Dosen
$statTotalStudents = 0;
$statCompleteStudents = 0;
$statOngoingStudents = 0;
$statOverallAvg = null;

if ($isAdmin || $isDosen) {
    $statTotalStudents = count($students);
    $sumAllAvg = 0;
    $countAvgAll = 0;
    foreach ($students as $st) {
        $stFilled = 0;
        $stSum = 0;
        foreach ($tutorialComponents as $k => $m) {
            if ($st[$k] !== null && $st[$k] !== '') {
                $stFilled++;
                $stSum += (float)$st[$k];
            }
        }
        if ($stFilled === 6) {
            $statCompleteStudents++;
        } elseif ($stFilled > 0) {
            $statOngoingStudents++;
        }
        if ($stFilled > 0) {
            $sumAllAvg += ($stSum / $stFilled);
            $countAvgAll++;
        }
    }
    if ($countAvgAll > 0) {
        $statOverallAvg = round($sumAllAvg / $countAvgAll, 2);
    }
}

include __DIR__ . '/includes/header.php';
?>

<style>
/* ── LPPAI Rekap Nilai Modern Design System ──────────────────────── */
.rn-wrap {
    max-width: 1240px;
    margin: 0 auto 30px auto;
}

/* 1. Executive Hero Header */
.rn-hero {
    background: linear-gradient(135deg, #062b18 0%, #0b3b22 45%, #14532d 80%, #0f3d3e 100%);
    border-radius: 20px;
    padding: 26px 30px;
    color: #ffffff;
    margin-bottom: 22px;
    box-shadow: 0 10px 30px -5px rgba(11, 59, 34, 0.28);
    position: relative;
    overflow: hidden;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}
.rn-hero::after {
    content: '';
    position: absolute;
    right: -40px;
    bottom: -50px;
    width: 220px;
    height: 220px;
    background: radial-gradient(circle, rgba(245, 158, 11, 0.15) 0%, rgba(255,255,255,0) 70%);
    pointer-events: none;
}
.rn-hero-main {
    flex: 1;
    min-width: 280px;
    z-index: 1;
}
.rn-hero-tag {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.2);
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .03em;
    color: #d1fae5;
    margin-bottom: 10px;
}
.rn-hero-tag .dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #34d399;
    box-shadow: 0 0 8px #34d399;
}
.rn-hero-title {
    font-size: 24px;
    font-weight: 800;
    color: #ffffff;
    margin: 0 0 6px 0;
    letter-spacing: -0.02em;
    line-height: 1.25;
}
.rn-hero-desc {
    font-size: 14px;
    color: #e2e8f0;
    margin: 0;
    line-height: 1.5;
    opacity: 0.92;
    max-width: 680px;
}
.rn-hero-side {
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
}
.rn-period-badge {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(8px);
    border: 1px solid #a7f3d0;
    padding: 10px 18px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
}
.rn-period-dot {
    width: 11px;
    height: 11px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25);
    animation: rn-pulse 2s infinite;
}
@keyframes rn-pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.15); opacity: 0.75; }
}
.rn-period-info {
    text-align: left;
}
.rn-period-lbl {
    font-size: 11px;
    font-weight: 800;
    color: #059669;
    text-transform: uppercase;
    letter-spacing: .06em;
}
.rn-period-val {
    font-size: 13.5px;
    font-weight: 800;
    color: #064e3b;
    line-height: 1.3;
}

/* 2. Admin & Dosen Stat Summary Cards */
.rn-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}
.rn-stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 16px 18px;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
    display: flex;
    align-items: center;
    gap: 14px;
    transition: transform 0.2s, box-shadow 0.2s;
}
.rn-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
}
.rn-stat-ico {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}
.rn-stat-ico.green { background: #d1fae5; color: #047857; }
.rn-stat-ico.blue  { background: #e0f2fe; color: #0284c7; }
.rn-stat-ico.amber { background: #fef3c7; color: #d97706; }
.rn-stat-ico.purple{ background: #ede9fe; color: #7c3aed; }
.rn-stat-body {
    flex: 1;
    min-width: 0;
}
.rn-stat-num {
    font-size: 22px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
    font-family: 'Plus Jakarta Sans', sans-serif;
}
.rn-stat-title {
    font-size: 12.5px;
    font-weight: 600;
    color: #64748b;
    margin-top: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* 3. Filter Bar */
.rn-filter-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 18px 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
}
.rn-filter-form {
    display: flex;
    gap: 14px;
    align-items: flex-end;
    flex-wrap: wrap;
}
.rn-filter-group {
    flex: 1;
    min-width: 240px;
}
.rn-filter-group label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 7px;
}
.rn-select {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    font-size: 13.5px;
    color: #0f172a;
    background-color: #f8fafc;
    transition: all 0.2s;
    font-weight: 500;
}
.rn-select:focus {
    outline: none;
    border-color: #059669;
    background-color: #ffffff;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
}
.rn-filter-btns {
    display: flex;
    gap: 10px;
    align-items: center;
}
.rn-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
    white-space: nowrap;
}
.rn-btn-reset {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
}
.rn-btn-reset:hover {
    background: #e2e8f0;
    color: #0f172a;
}
.rn-btn-export {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
}
.rn-btn-export:hover {
    background: linear-gradient(135deg, #047857 0%, #065f46 100%);
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(5, 150, 105, 0.35);
}

/* 4. Table Card (Admin / Dosen) */
.rn-table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.05);
    margin-bottom: 24px;
}
.rn-table-head {
    padding: 16px 22px;
    background: #ffffff;
    border-bottom: 1px solid #eef2f6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
.rn-table-title {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}
.rn-badge-active-period {
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.rn-badge-active-period .dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #10b981;
}

/* Custom Data Table Styling */
.rn-table-responsive {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.rn-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13.5px;
}
.rn-table thead th {
    background: #f8fafc;
    color: #475569;
    font-weight: 800;
    font-size: 11.5px;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: 12px 14px;
    border-bottom: 1.5px solid #e2e8f0;
    white-space: nowrap;
    text-align: left;
}
.rn-table thead th.text-center { text-align: center; }
.rn-table tbody td {
    padding: 12px 14px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    color: #334155;
}
.rn-table tbody tr:hover td {
    background: rgba(11, 59, 34, 0.025);
}
.rn-student-meta {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.rn-student-name {
    font-weight: 800;
    color: #0f172a;
    font-size: 14px;
}
.rn-student-nim {
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px;
    color: #64748b;
    font-weight: 600;
}
.rn-score-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 42px;
    padding: 3px 8px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 13px;
    font-family: 'JetBrains Mono', monospace;
}
.rn-score-badge.tier-excellent { background: #d1fae5; color: #047857; border: 1px solid #a7f3d0; }
.rn-score-badge.tier-good { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
.rn-score-badge.tier-fair { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.rn-score-badge.tier-poor { background: #ffedd5; color: #c2410c; border: 1px solid #fed7aa; }
.rn-score-badge.tier-fail { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
.rn-score-badge.tier-none { background: #f1f5f9; color: #94a3b8; border: 1px dashed #cbd5e1; }

.rn-final-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 999px;
    font-weight: 800;
    font-size: 13.5px;
    font-family: 'JetBrains Mono', monospace;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.rn-final-badge.tier-excellent { background: #d1fae5; color: #047857; border: 1px solid #6ee7b7; }
.rn-final-badge.tier-good { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
.rn-final-badge.tier-fair { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.rn-final-badge.tier-poor { background: #ffedd5; color: #c2410c; border: 1px solid #fed7aa; }
.rn-final-badge.tier-fail { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.rn-final-badge.tier-none { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

.rn-btn-edit {
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 700;
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.rn-btn-edit:hover {
    background: #fde68a;
    color: #78350f;
    transform: translateY(-1px);
}

/* 5. Mobile Cards View for Admin / Dosen (<= 768px) */
.rn-mobile-cards {
    display: none;
    padding: 14px;
}
.rn-m-card {
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 14px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
}
.rn-m-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 12px;
}
.rn-m-student {
    flex: 1;
}
.rn-m-name {
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 2px;
}
.rn-m-sub {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 12px;
    color: #64748b;
}
.rn-m-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    background: #f8fafc;
    border: 1px solid #eef2f6;
    border-radius: 10px;
    padding: 10px;
    margin-bottom: 12px;
}
.rn-m-item {
    text-align: center;
    padding: 4px;
}
.rn-m-lbl {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    margin-bottom: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* 6. Mahasiswa View Styles */
.rn-class-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.06);
    margin-bottom: 24px;
}
.rn-class-head {
    background: #f8fafc;
    border-bottom: 1px solid #eef2f7;
    padding: 18px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
.rn-class-title {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 10px;
}
.rn-class-meta-row {
    padding: 12px 24px;
    background: #ffffff;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    font-size: 13.5px;
    color: #475569;
}
.rn-class-meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
}
.rn-class-meta-item strong {
    color: #0f172a;
}

/* Mahasiswa Spotlight Nilai Akhir */
.rn-spotlight-card {
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border: 1.5px solid #e2e8f0;
    border-radius: 18px;
    padding: 24px;
    margin: 20px 24px;
    display: grid;
    grid-template-columns: 1.1fr 1fr;
    gap: 24px;
    align-items: center;
}
.rn-spotlight-left {
    display: flex;
    align-items: center;
    gap: 22px;
    flex-wrap: wrap;
}
.rn-circle-score {
    width: 105px;
    height: 105px;
    border-radius: 50%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    flex-shrink: 0;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
    border: 3px solid #ffffff;
}
.rn-circle-score.tier-excellent { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #ffffff; }
.rn-circle-score.tier-good { background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); color: #ffffff; }
.rn-circle-score.tier-fair { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: #ffffff; }
.rn-circle-score.tier-poor { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); color: #ffffff; }
.rn-circle-score.tier-fail { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: #ffffff; }
.rn-circle-score.tier-none { background: #94a3b8; color: #ffffff; }

.rn-circle-score-val {
    font-size: 26px;
    font-weight: 900;
    line-height: 1;
    font-family: 'JetBrains Mono', monospace;
}
.rn-circle-score-lbl {
    font-size: 10.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .06em;
    opacity: 0.9;
    margin-top: 3px;
}
.rn-spotlight-summary {
    flex: 1;
    min-width: 200px;
}
.rn-predikat-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 13.5px;
    font-weight: 800;
    margin-bottom: 10px;
}
.rn-predikat-badge.tier-excellent { background: #d1fae5; color: #047857; border: 1.5px solid #a7f3d0; }
.rn-predikat-badge.tier-good { background: #e0f2fe; color: #0369a1; border: 1.5px solid #bae6fd; }
.rn-predikat-badge.tier-fair { background: #fef3c7; color: #b45309; border: 1.5px solid #fde68a; }
.rn-predikat-badge.tier-poor { background: #ffedd5; color: #c2410c; border: 1.5px solid #fed7aa; }
.rn-predikat-badge.tier-fail { background: #fee2e2; color: #b91c1c; border: 1.5px solid #fecaca; }
.rn-predikat-badge.tier-none { background: #f1f5f9; color: #64748b; border: 1.5px solid #cbd5e1; }

.rn-progress-track {
    width: 100%;
    height: 10px;
    background: #e2e8f0;
    border-radius: 999px;
    overflow: hidden;
    margin: 8px 0;
}
.rn-progress-fill {
    height: 100%;
    border-radius: 999px;
    transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}

.rn-spotlight-right {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.rn-mini-tile {
    background: #ffffff;
    border: 1px solid #eef2f6;
    border-radius: 12px;
    padding: 12px 14px;
}
.rn-mini-tile-lbl {
    font-size: 11.5px;
    font-weight: 700;
    color: #64748b;
    margin-bottom: 4px;
}
.rn-mini-tile-val {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
}

/* 6 Component Cards Grid */
.rn-components-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    padding: 0 24px 24px 24px;
}
.rn-comp-card {
    background: #ffffff;
    border: 1.5px solid #e5e7eb;
    border-radius: 16px;
    padding: 18px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: all 0.2s ease;
}
.rn-comp-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
    border-color: #cbd5e1;
}
.rn-comp-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 10px;
}
.rn-comp-ico-title {
    display: flex;
    align-items: center;
    gap: 10px;
}
.rn-comp-ico {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    background: #f1f5f9;
}
.rn-comp-name {
    font-size: 14.5px;
    font-weight: 800;
    color: #0f172a;
}
.rn-comp-desc {
    font-size: 12.5px;
    color: #64748b;
    line-height: 1.45;
    margin-bottom: 14px;
    flex: 1;
}
.rn-comp-score-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 6px;
}
.rn-comp-score-big {
    font-size: 24px;
    font-weight: 900;
    font-family: 'JetBrains Mono', monospace;
    color: #0f172a;
}
.rn-comp-tier-chip {
    font-size: 11.5px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
}

/* Rubrik Guidance Card */
.rn-guide-card {
    margin: 0 24px 24px 24px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.rn-guide-content {
    flex: 1;
    min-width: 260px;
}
.rn-guide-content h4 {
    margin: 0 0 6px 0;
    font-size: 14.5px;
    font-weight: 800;
    color: #0f172a;
}
.rn-guide-content p {
    margin: 0;
    font-size: 13px;
    color: #64748b;
    line-height: 1.5;
}

/* 7. Modal Edit Nilai (Admin / Dosen) */
.rn-modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(6px);
    z-index: 99999;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.rn-modal-box {
    background: #ffffff;
    width: 100%;
    max-width: 620px;
    border-radius: 20px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
    border: 1px solid #e2e8f0;
    animation: rn-modal-slide 0.25s ease-out;
}
@keyframes rn-modal-slide {
    from { opacity: 0; transform: translateY(16px) scale(0.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.rn-modal-head {
    padding: 20px 24px;
    border-bottom: 1px solid #f1f5f9;
    background: #f8fafc;
    border-radius: 20px 20px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}
.rn-modal-title {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
}
.rn-modal-sub {
    font-size: 13px;
    color: #64748b;
    margin-top: 4px;
}
.rn-modal-close {
    background: transparent;
    border: none;
    font-size: 24px;
    line-height: 1;
    color: #94a3b8;
    cursor: pointer;
    transition: color 0.15s;
}
.rn-modal-close:hover { color: #0f172a; }
.rn-modal-body {
    padding: 24px;
}
.rn-modal-live-preview {
    background: #f0fdf4;
    border: 1.5px solid #bbf7d0;
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.rn-modal-fields-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
}
.rn-form-field {
    display: flex;
    flex-direction: column;
}
.rn-form-field label {
    font-size: 13px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.rn-input-score {
    width: 100%;
    padding: 9px 12px;
    border: 1.5px solid #cbd5e1;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
    transition: border-color 0.2s, box-shadow 0.2s;
    margin-bottom: 6px;
}
.rn-input-score:focus {
    outline: none;
    border-color: #059669;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
}
.rn-chips-wrap {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.rn-chip {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 2px 7px;
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    cursor: pointer;
    transition: all 0.15s;
}
.rn-chip:hover {
    background: #059669;
    color: #ffffff;
    border-color: #059669;
}
.rn-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding-top: 16px;
    border-top: 1px solid #f1f5f9;
}

/* 8. Responsive Media Queries */
@media (max-width: 1024px) {
    .rn-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .rn-components-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .rn-spotlight-card {
        grid-template-columns: 1fr;
        gap: 20px;
    }
}

@media (max-width: 768px) {
    .rn-hero {
        padding: 20px;
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }
    .rn-hero-side {
        align-items: flex-start;
        width: 100%;
    }
    .rn-period-badge {
        width: 100%;
        box-sizing: border-box;
    }
    .rn-stats-grid {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    .rn-stat-card {
        padding: 12px 14px;
        gap: 10px;
    }
    .rn-stat-ico {
        width: 38px;
        height: 38px;
        font-size: 18px;
    }
    .rn-stat-num {
        font-size: 18px;
    }
    .rn-filter-form {
        flex-direction: column;
        align-items: stretch;
    }
    .rn-filter-group {
        min-width: 100%;
    }
    .rn-filter-btns {
        width: 100%;
        justify-content: stretch;
    }
    .rn-filter-btns .rn-btn {
        flex: 1;
    }
    .rn-desktop-table {
        display: none !important;
    }
    .rn-mobile-cards {
        display: block !important;
    }
    .rn-components-grid {
        grid-template-columns: 1fr;
        padding: 0 16px 16px 16px;
    }
    .rn-spotlight-card {
        margin: 16px;
        padding: 18px;
    }
    .rn-class-head {
        padding: 16px;
    }
    .rn-class-meta-row {
        padding: 12px 16px;
        gap: 12px;
    }
    .rn-guide-card {
        margin: 0 16px 16px 16px;
        padding: 16px;
    }
    .rn-modal-fields-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .rn-hero-title {
        font-size: 20px;
    }
    .rn-stats-grid {
        grid-template-columns: 1fr;
    }
    .rn-spotlight-left {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    .rn-spotlight-summary {
        text-align: center;
    }
    .rn-spotlight-right {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="rn-wrap">

    <!-- 1. EXECUTIVE HERO HEADER -->
    <div class="rn-hero">
        <div class="rn-hero-main">
            <div class="rn-hero-tag">
                <span class="dot"></span>
                <span>LPPAI Academic · Sistem Informasi Nilai Tutorial</span>
            </div>
            <h1 class="rn-hero-title"><?= PAGE_TITLE ?></h1>
            <p class="rn-hero-desc">
                <?php if ($isAdmin): ?>
                    Kelola, pantau kelengkapan asesmen, dan ekspor data rekapitulasi nilai mahasiswa program tutorial LPPAI berdasarkan periode aktif.
                <?php elseif ($isDosen): ?>
                    Kelola dan perbarui nilai 6 komponen ubudiyah mahasiswa pada kelas tutorial yang Anda ampu secara real-time.
                <?php else: ?>
                    Pantau rincian nilai 6 komponen perkuliahan tutorial Anda (Thaharah, Shalat, Surat Pendek, Amaliyah, Jenazah, dan Ujian Tulis) secara transparan.
                <?php endif; ?>
            </p>
        </div>
        <div class="rn-hero-side">
            <?php if ($current_gel): ?>
                <div class="rn-period-badge">
                    <span class="rn-period-dot"></span>
                    <div class="rn-period-info">
                        <div class="rn-period-lbl">Periode Aktif:</div>
                        <div class="rn-period-val">
                            <?= sanitize($current_gel['tahun_ajaran']) ?> &bull; <?= sanitize($current_gel['semester']) ?> &bull; <?= sanitize($gelLabels[$current_gel['gelombang']] ?? $current_gel['gelombang']) ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="rn-period-badge" style="border-color:#cbd5e1;">
                    <span class="rn-period-dot" style="background:#64748b; box-shadow:none;"></span>
                    <div class="rn-period-info">
                        <div class="rn-period-lbl" style="color:#475569;">Mode Tampilan:</div>
                        <div class="rn-period-val" style="color:#1e293b;">Semua Periode (Historis)</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isAdmin || $isDosen): ?>

        <!-- 2. STAT SUMMARY CARDS (Admin & Dosen) -->
        <div class="rn-stats-grid">
            <div class="rn-stat-card">
                <div class="rn-stat-ico green">👥</div>
                <div class="rn-stat-body">
                    <div class="rn-stat-num"><?= number_format($statTotalStudents) ?></div>
                    <div class="rn-stat-title">Total Peserta</div>
                </div>
            </div>
            <div class="rn-stat-card">
                <div class="rn-stat-ico blue">✅</div>
                <div class="rn-stat-body">
                    <div class="rn-stat-num"><?= number_format($statCompleteStudents) ?></div>
                    <div class="rn-stat-title">Nilai Lengkap (6/6)</div>
                </div>
            </div>
            <div class="rn-stat-card">
                <div class="rn-stat-ico amber">⏳</div>
                <div class="rn-stat-body">
                    <div class="rn-stat-num"><?= number_format($statOngoingStudents) ?></div>
                    <div class="rn-stat-title">Penilaian Berjalan</div>
                </div>
            </div>
            <div class="rn-stat-card">
                <div class="rn-stat-ico purple">📊</div>
                <div class="rn-stat-body">
                    <div class="rn-stat-num"><?= $statOverallAvg !== null ? number_format($statOverallAvg, 1) : '-' ?></div>
                    <div class="rn-stat-title">Rata-Rata Nilai</div>
                </div>
            </div>
        </div>

        <!-- 3. FILTER BAR -->
        <div class="rn-filter-card">
            <form method="GET" class="rn-filter-form">
                <!-- Filter Periode Tutorial -->
                <div class="rn-filter-group">
                    <label>
                        <span>📅</span>
                        <span>Periode / Gelombang Tutorial</span>
                    </label>
                    <select name="periode" class="rn-select" onchange="this.form.submit()">
                        <option value="active" <?= $selected_gel_id === 'active' ? 'selected' : '' ?>>
                            🌟 Periode Aktif <?= $active_gel ? '(' . sanitize($active_gel['tahun_ajaran']) . ' - ' . sanitize($active_gel['semester']) . ' - ' . sanitize($gelLabels[$active_gel['gelombang']] ?? $active_gel['gelombang']) . ')' : '' ?>
                        </option>
                        <?php if (!empty($allGelombang)): ?>
                            <optgroup label="Pilih Periode Lain:">
                                <?php foreach ($allGelombang as $g): ?>
                                    <option value="<?= $g['id'] ?>" <?= ($selected_gel_id == $g['id']) ? 'selected' : '' ?>>
                                        <?= sanitize($g['tahun_ajaran']) ?> - <?= sanitize($g['semester']) ?> - <?= sanitize($gelLabels[$g['gelombang']] ?? $g['gelombang']) ?> <?= $g['status'] === 'aktif' ? '(Aktif)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <option value="all" <?= $selected_gel_id === 'all' ? 'selected' : '' ?>>🌐 Semua Periode (Historis)</option>
                    </select>
                </div>

                <!-- Filter Kelas -->
                <div class="rn-filter-group">
                    <label>
                        <span>👥</span>
                        <span>Filter Rombongan Belajar (Kelas)</span>
                    </label>
                    <select name="class_name" class="rn-select" onchange="this.form.submit()">
                        <option value="">-- Tampilkan Semua Kelas --</option>
                        <?php foreach($unique_classes as $c): ?>
                            <option value="<?= sanitize($c['nama_kelas']) ?>" <?= $filter_class == $c['nama_kelas'] ? 'selected' : '' ?>>
                                <?= sanitize($c['nama_kelas']) ?> (<?= sanitize($gelLabels[$c['gelombang']] ?? $c['gelombang']) ?>) - <?= sanitize($c['dosen_pengampu'] ?: 'Tanpa Dosen') ?> (<?= sanitize($c['jml_mhs']) ?> Mhs)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Action Buttons -->
                <div class="rn-filter-btns">
                    <a href="rekap-nilai.php" class="rn-btn rn-btn-reset" title="Kembalikan ke filter default">🔄 Reset</a>
                    <a href="?class_name=<?= urlencode($filter_class) ?>&periode=<?= urlencode($selected_gel_id) ?>&action=export" class="rn-btn rn-btn-export" data-no-spa="true" title="Unduh rekapitulasi nilai format Excel">
                        <span>📄</span>
                        <span>Export Excel</span>
                    </a>
                </div>
            </form>
        </div>

        <!-- 4. TABLE CARD (Admin & Dosen) -->
        <div class="rn-table-card">
            <div class="rn-table-head">
                <div class="rn-table-title">
                    <span>📋</span>
                    <span>Daftar Nilai Mahasiswa: <strong><?= $filter_class !== '' ? sanitize($filter_class) : 'Semua Kelas' ?></strong></span>
                </div>
                <div>
                    <?php if ($current_gel): ?>
                        <span class="rn-badge-active-period">
                            <span class="dot"></span>
                            <span>Periode Aktif: <?= sanitize($current_gel['tahun_ajaran']) ?> (<?= sanitize($current_gel['semester']) ?> - <?= sanitize($gelLabels[$current_gel['gelombang']] ?? $current_gel['gelombang']) ?>)</span>
                        </span>
                    <?php else: ?>
                        <span class="badge" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-size:12px;">Semua Periode (Historis)</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($students)): ?>
                <div style="padding:48px 20px; text-align:center; color:#64748b;">
                    <div style="font-size:42px; margin-bottom:10px;">📭</div>
                    <h3 style="margin:0 0 6px 0; color:#1e293b; font-size:17px;">Belum Ada Data Mahasiswa</h3>
                    <p style="margin:0; font-size:14px;">Tidak ada mahasiswa yang ditemukan untuk kriteria filter periode dan kelas yang dipilih.</p>
                </div>
            <?php else: ?>

                <!-- A. Desktop Data Table View -->
                <div class="rn-table-responsive rn-desktop-table">
                    <table class="rn-table datatable">
                        <thead>
                            <tr>
                                <th width="40" class="text-center">No</th>
                                <th>Kelas</th>
                                <th>NIM &amp; Mahasiswa</th>
                                <th>Prodi</th>
                                <th class="text-center">Jenis Program</th>
                                <th class="text-center">Thaharah</th>
                                <th class="text-center">Shalat</th>
                                <th class="text-center">Srt Pdk</th>
                                <th class="text-center">Amaliyah</th>
                                <th class="text-center">Jenazah</th>
                                <th class="text-center">UT</th>
                                <th class="text-center">Rata-Rata Akhir</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no = 1; foreach($students as $s):
                                $sSum = 0; $sCount = 0;
                                foreach ($tutorialComponents as $k => $m) {
                                    if ($s[$k] !== null && $s[$k] !== '') {
                                        $sSum += (float)$s[$k];
                                        $sCount++;
                                    }
                                }
                                $sAvg = $sCount > 0 ? round($sSum / $sCount, 2) : null;
                                $sMeta = getTutorialScoreMeta($sAvg);
                            ?>
                            <tr>
                                <td class="text-center" style="font-weight:700; color:#94a3b8;"><?= $no++ ?></td>
                                <td>
                                    <div style="font-weight:700; color:#0f172a;"><?= sanitize($s['nama_kelas']) ?></div>
                                    <div style="font-size:11.5px; color:#64748b;">Gel. <?= sanitize($s['gelombang']) ?></div>
                                </td>
                                <td>
                                    <div class="rn-student-meta">
                                        <span class="rn-student-name"><?= sanitize($s['nama_lengkap']) ?></span>
                                        <span class="rn-student-nim"><?= sanitize($s['nim']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-size:13px; color:#475569;"><?= sanitize($s['program_studi'] ?: '-') ?></span>
                                </td>
                                <td class="text-center">
                                    <?= renderJenisProgramBadge($s['jenis_program'] ?? 'Reguler', true) ?>
                                </td>
                                <?php foreach ($tutorialComponents as $k => $m):
                                    $val = $s[$k];
                                    $cMeta = getTutorialScoreMeta($val);
                                ?>
                                    <td class="text-center">
                                        <span class="rn-score-badge tier-<?= $cMeta['tier'] ?>">
                                            <?= $val !== null && $val !== '' ? number_format((float)$val, 1) : '-' ?>
                                        </span>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-center">
                                    <span class="rn-final-badge tier-<?= $sMeta['tier'] ?>">
                                        <span><?= $sAvg !== null ? number_format($sAvg, 2) : '-' ?></span>
                                        <?php if ($sMeta['grade'] !== '-'): ?>
                                            <span style="font-size:11px; opacity:0.85;">(<?= $sMeta['grade'] ?>)</span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="rn-btn-edit" onclick="openEditNilaiModal(<?= (int)$s['id'] ?>, '<?= sanitize(addslashes($s['nama_lengkap'])) ?>', '<?= sanitize($s['nim']) ?>', '<?= sanitize($s['nama_kelas']) ?>', '<?= $s['nilai_thaharah'] ?? '' ?>', '<?= $s['nilai_shalat'] ?? '' ?>', '<?= $s['nilai_surat_pendek'] ?? '' ?>', '<?= $s['nilai_amaliyah'] ?? '' ?>', '<?= $s['nilai_jenazah'] ?? '' ?>', '<?= $s['nilai_ujian_tulis'] ?? '' ?>')">
                                        <span>✏️</span>
                                        <span>Edit</span>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- B. Mobile Cards View (<= 768px) -->
                <div class="rn-mobile-cards">
                    <?php foreach($students as $s):
                        $sSum = 0; $sCount = 0;
                        foreach ($tutorialComponents as $k => $m) {
                            if ($s[$k] !== null && $s[$k] !== '') {
                                $sSum += (float)$s[$k];
                                $sCount++;
                            }
                        }
                        $sAvg = $sCount > 0 ? round($sSum / $sCount, 2) : null;
                        $sMeta = getTutorialScoreMeta($sAvg);
                    ?>
                    <div class="rn-m-card">
                        <div class="rn-m-head">
                            <div class="rn-m-student">
                                <div class="rn-m-name"><?= sanitize($s['nama_lengkap']) ?></div>
                                <div class="rn-m-sub">
                                    <span class="rn-student-nim"><?= sanitize($s['nim']) ?></span>
                                    <span>&bull;</span>
                                    <span><?= sanitize($s['nama_kelas']) ?></span>
                                    <span>&bull;</span>
                                    <?= renderJenisProgramBadge($s['jenis_program'] ?? 'Reguler', true) ?>
                                </div>
                            </div>
                            <span class="rn-final-badge tier-<?= $sMeta['tier'] ?>">
                                <span><?= $sAvg !== null ? number_format($sAvg, 2) : '-' ?></span>
                                <?php if ($sMeta['grade'] !== '-'): ?>
                                    <span style="font-size:11px; opacity:0.85;">(<?= $sMeta['grade'] ?>)</span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <!-- 6 Mini Score Items -->
                        <div class="rn-m-grid">
                            <?php foreach ($tutorialComponents as $k => $m):
                                $val = $s[$k];
                                $cMeta = getTutorialScoreMeta($val);
                            ?>
                            <div class="rn-m-item">
                                <div class="rn-m-lbl"><?= $m['icon'] ?> <?= $m['short'] ?></div>
                                <span class="rn-score-badge tier-<?= $cMeta['tier'] ?>" style="min-width:auto; padding:2px 6px; font-size:12px;">
                                    <?= $val !== null && $val !== '' ? number_format((float)$val, 1) : '-' ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Action Button -->
                        <button type="button" class="rn-btn-edit" style="width:100%; justify-content:center; padding:9px 12px; font-size:13px;" onclick="openEditNilaiModal(<?= (int)$s['id'] ?>, '<?= sanitize(addslashes($s['nama_lengkap'])) ?>', '<?= sanitize($s['nim']) ?>', '<?= sanitize($s['nama_kelas']) ?>', '<?= $s['nilai_thaharah'] ?? '' ?>', '<?= $s['nilai_shalat'] ?? '' ?>', '<?= $s['nilai_surat_pendek'] ?? '' ?>', '<?= $s['nilai_amaliyah'] ?? '' ?>', '<?= $s['nilai_jenazah'] ?? '' ?>', '<?= $s['nilai_ujian_tulis'] ?? '' ?>')">
                            <span>✏️</span>
                            <span>Edit Nilai Mahasiswa</span>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </div>

    <?php else: ?>

        <!-- 5. VIEW MAHASISWA -->
        <?php if (empty($my_classes)): ?>
            <div class="rn-class-box" style="padding: 48px 24px; text-align: center;">
                <div style="font-size: 52px; margin-bottom: 14px;">🏫</div>
                <h3 style="margin: 0 0 8px 0; color: #0f172a; font-size: 19px;">Belum Terdaftar di Kelas Tutorial</h3>
                <p style="margin: 0 0 20px 0; color: #64748b; font-size: 14.5px; max-width: 520px; margin-left: auto; margin-right: auto; line-height: 1.6;">
                    Anda belum terdaftar pada rombongan belajar kelas tutorial aktif. Nilai dan rekapan penilaian akan otomatis tampil di halaman ini begitu jadwal Anda diplot oleh panitia LPPAI.
                </p>
                <a href="<?= BASE_URL ?>/tutorial-info.php" class="btn btn-primary" style="padding: 10px 22px; text-decoration:none;">
                    📘 Cek Halaman Info Tutorial
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($my_classes as $mc):
                $mSum = 0; $mCount = 0;
                $scoresList = [];
                foreach ($tutorialComponents as $k => $m) {
                    if ($mc[$k] !== null && $mc[$k] !== '') {
                        $mVal = (float)$mc[$k];
                        $mSum += $mVal;
                        $mCount++;
                        $scoresList[] = $mVal;
                    }
                }
                $mAvg = $mCount > 0 ? round($mSum / $mCount, 2) : null;
                $mMeta = getTutorialScoreMeta($mAvg);
                $isAllComplete = ($mCount === 6);
                $isPass = ($isAllComplete && $mAvg !== null && $mAvg >= 65);
                $minScore = !empty($scoresList) ? min($scoresList) : null;
                $maxScore = !empty($scoresList) ? max($scoresList) : null;
            ?>
            <div class="rn-class-box">
                <!-- Header Kelas -->
                <div class="rn-class-head">
                    <h2 class="rn-class-title">
                        <span>🏫</span>
                        <span><?= sanitize($mc['nama_kelas']) ?></span>
                    </h2>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <span class="badge" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:700; font-size:12.5px;">
                            <?= sanitize($gelLabels[$mc['gelombang']] ?? $mc['gelombang']) ?>
                        </span>
                        <?php if ($current_gel && $mc['gelombang'] === $current_gel['gelombang']): ?>
                            <span class="badge" style="background:#d1fae5; color:#047857; border:1px solid #a7f3d0; font-weight:700; font-size:12.5px;">
                                ✅ Periode Aktif
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Metadata Kelas Row -->
                <div class="rn-class-meta-row">
                    <div class="rn-class-meta-item">
                        <span>👨‍🏫 Dosen / Tutor:</span>
                        <strong><?= sanitize($mc['dosen_pengampu'] ?: 'Menunggu Penetapan') ?></strong>
                    </div>
                    <?php if (!empty($mc['hari'])): ?>
                    <div class="rn-class-meta-item">
                        <span>🗓️ Jadwal:</span>
                        <strong><?= sanitize($mc['hari']) ?><?= !empty($mc['jam']) ? ', ' . sanitize($mc['jam']) : '' ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($mc['ruangan'])): ?>
                    <div class="rn-class-meta-item">
                        <span>📍 Ruangan:</span>
                        <strong><?= sanitize($mc['ruangan']) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($mc['class_semester'])): ?>
                    <div class="rn-class-meta-item">
                        <span>📅 Semester:</span>
                        <strong><?= sanitize($mc['class_semester']) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Spotlight Nilai Akhir Card -->
                <div class="rn-spotlight-card">
                    <div class="rn-spotlight-left">
                        <div class="rn-circle-score tier-<?= $mMeta['tier'] ?>">
                            <div class="rn-circle-score-val"><?= $mAvg !== null ? number_format($mAvg, 1) : '-' ?></div>
                            <div class="rn-circle-score-lbl">Nilai Akhir</div>
                        </div>
                        <div class="rn-spotlight-summary">
                            <div class="rn-predikat-badge tier-<?= $mMeta['tier'] ?>">
                                <span>Predikat <?= $mMeta['grade'] ?></span>
                                <span>&bull;</span>
                                <span><?= $mMeta['label'] ?></span>
                            </div>
                            <div class="rn-progress-track">
                                <div class="rn-progress-fill" style="width: <?= $mMeta['pct'] ?>%; background: linear-gradient(90deg, <?= $mMeta['color'] ?>, #10b981);"></div>
                            </div>
                            <div style="font-size:13px; color:#64748b; margin-top:6px;">
                                <?php if ($isPass): ?>
                                    <span style="color:#047857; font-weight:700;">🏆 Memenuhi syarat kelulusan program tutorial LPPAI.</span>
                                <?php elseif ($isAllComplete): ?>
                                    <span style="color:#b91c1c; font-weight:700;">⚠️ Rata-rata akhir di bawah batas kelulusan minimal (65.00).</span>
                                <?php elseif ($mCount > 0): ?>
                                    <span style="color:#d97706; font-weight:700;">⏳ Proses penilaian berjalan (<?= $mCount ?> dari 6 komponen dinilai).</span>
                                <?php else: ?>
                                    <span style="color:#64748b; font-weight:600;">⏳ Dosen tutor belum menginputkan nilai komponen Anda.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="rn-spotlight-right">
                        <div class="rn-mini-tile">
                            <div class="rn-mini-tile-lbl">STATUS ASESMEN</div>
                            <div class="rn-mini-tile-val" style="color:<?= $isAllComplete ? '#047857' : '#d97706' ?>;">
                                <?= $mCount ?> / 6 Selesai
                            </div>
                        </div>
                        <div class="rn-mini-tile">
                            <div class="rn-mini-tile-lbl">BATAS KELULUSAN</div>
                            <div class="rn-mini-tile-val" style="color:#0f172a;">Min. 65.00</div>
                        </div>
                        <div class="rn-mini-tile">
                            <div class="rn-mini-tile-lbl">NILAI TERTINGGI</div>
                            <div class="rn-mini-tile-val" style="color:#047857;">
                                <?= $maxScore !== null ? number_format($maxScore, 1) : '-' ?>
                            </div>
                        </div>
                        <div class="rn-mini-tile">
                            <div class="rn-mini-tile-lbl">NILAI TERENDAH</div>
                            <div class="rn-mini-tile-val" style="color:#b45309;">
                                <?= $minScore !== null ? number_format($minScore, 1) : '-' ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 6 Component Cards Grid -->
                <div class="rn-components-grid">
                    <?php foreach ($tutorialComponents as $k => $m):
                        $val = $mc[$k];
                        $cMeta = getTutorialScoreMeta($val);
                    ?>
                    <div class="rn-comp-card">
                        <div>
                            <div class="rn-comp-head">
                                <div class="rn-comp-ico-title">
                                    <div class="rn-comp-ico"><?= $m['icon'] ?></div>
                                    <div class="rn-comp-name"><?= $m['name'] ?></div>
                                </div>
                                <span class="rn-score-badge tier-<?= $cMeta['tier'] ?>">
                                    <?= $val !== null && $val !== '' ? number_format((float)$val, 1) : '-' ?>
                                </span>
                            </div>
                            <div class="rn-comp-desc"><?= $m['desc'] ?></div>
                        </div>

                        <div>
                            <div class="rn-comp-score-row">
                                <div class="rn-comp-score-big">
                                    <?= $val !== null && $val !== '' ? number_format((float)$val, 1) : '-' ?>
                                </div>
                                <span class="rn-comp-tier-chip" style="background:<?= $cMeta['bg'] ?>; color:<?= $cMeta['color'] ?>; border:1px solid <?= $cMeta['border'] ?>;">
                                    <?= $cMeta['label'] ?>
                                </span>
                            </div>
                            <div class="rn-progress-track" style="margin-bottom:0;">
                                <div class="rn-progress-fill" style="width: <?= $cMeta['pct'] ?>%; background: <?= $cMeta['color'] ?>;"></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Rubrik & Narahubung Card -->
                <div class="rn-guide-card">
                    <div class="rn-guide-content">
                        <h4>💡 Informasi Skala Penilaian &amp; Remedial</h4>
                        <p>
                            Penilaian tutorial LPPAI mengacu pada standar kelulusan: <strong>A</strong> (&ge; 85), <strong>B</strong> (75&ndash;84.9), <strong>C</strong> (65&ndash;74.9 - Batas Minimal), <strong>D</strong> (55&ndash;64.9), dan <strong>E</strong> (&lt; 55). Jika ada nilai yang belum masuk atau perlu klarifikasi, hubungi dosen tutor pengampu kelas Anda atau narahubung resmi LPPAI.
                        </p>
                    </div>
                    <div>
                        <a href="https://wa.me/6281515726827?text=<?= rawurlencode("Assalamu'alaikum Bu Umami, saya mahasiswa (" . ($user['nama_lengkap'] ?? '') . " - " . ($user['nim'] ?? '') . ") ingin menanyakan rincian nilai tutorial kelas " . ($mc['nama_kelas'] ?? '') . ".") ?>" target="_blank" rel="noopener noreferrer" class="rn-btn" style="background:#22c55e; color:#ffffff; padding:10px 18px; border-radius:10px; font-size:13px; box-shadow:0 3px 10px rgba(34,197,94,0.3);">
                            <span>💬</span>
                            <span>Bantuan WhatsApp TU LPPAI</span>
                        </a>
                    </div>
                </div>

            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php endif; ?>

</div>

<?php if ($isAdmin || $isDosen): ?>
<!-- 7. MODAL EDIT NILAI (Admin & Dosen) -->
<div id="editNilaiModal" class="rn-modal-overlay">
    <div class="rn-modal-box">
        <div class="rn-modal-head">
            <div>
                <h3 class="rn-modal-title">✏️ Input &amp; Edit Nilai Mahasiswa</h3>
                <div class="rn-modal-sub">
                    Mahasiswa: <strong id="edit_nilai_nama" style="color:#0f172a;"></strong> (<span id="edit_nilai_nim"></span>) &bull; Kelas: <span id="edit_nilai_kelas" style="font-weight:700; color:#059669;"></span>
                </div>
            </div>
            <button type="button" class="rn-modal-close" onclick="closeEditNilaiModal()" aria-label="Tutup Modal">&times;</button>
        </div>
        
        <form method="POST" id="formEditNilai" class="rn-modal-body">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="edit_nilai">
            <input type="hidden" name="reg_id" id="edit_nilai_reg_id" value="">

            <!-- Live Average Preview Banner -->
            <div class="rn-modal-live-preview">
                <div style="font-size:13px; color:#166534; font-weight:700;">
                    📊 Rata-Rata Sementara:
                </div>
                <div id="edit_live_avg" style="font-size:16px; font-weight:900; font-family:'JetBrains Mono',monospace; color:#15803d;">
                    -
                </div>
            </div>
            
            <div class="rn-modal-fields-grid">
                <?php
                $fields = [
                    ['id' => 'edit_thaharah', 'name' => 'nilai_thaharah', 'label' => 'Thaharah & Wudhu', 'ico' => '💧'],
                    ['id' => 'edit_shalat', 'name' => 'nilai_shalat', 'label' => 'Praktik Shalat', 'ico' => '🕋'],
                    ['id' => 'edit_srt_pdk', 'name' => 'nilai_surat_pendek', 'label' => 'Surat Pendek', 'ico' => '📖'],
                    ['id' => 'edit_amaliyah', 'name' => 'nilai_amaliyah', 'label' => 'Praktik Amaliyah', 'ico' => '🤲'],
                    ['id' => 'edit_jenazah', 'name' => 'nilai_jenazah', 'label' => 'Perawatan Jenazah', 'ico' => '🕊️'],
                    ['id' => 'edit_ut', 'name' => 'nilai_ujian_tulis', 'label' => 'Ujian Tulis Diktat', 'ico' => '✍️'],
                ];
                foreach ($fields as $f):
                ?>
                <div class="rn-form-field">
                    <label for="<?= $f['id'] ?>">
                        <span><?= $f['ico'] ?></span>
                        <span><?= $f['label'] ?></span>
                    </label>
                    <input type="number" step="0.01" min="0" max="100" name="<?= $f['name'] ?>" id="<?= $f['id'] ?>" class="rn-input-score" placeholder="0.00 - 100.00" oninput="calculateLiveModalAvg()">
                    <div class="rn-chips-wrap">
                        <?php foreach([60, 65, 70, 75, 80, 85, 90, 95, 100] as $val): ?>
                            <button type="button" class="rn-chip" onclick="setScoreField('<?= $f['id'] ?>', '<?= $val ?>')"><?= $val ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="rn-modal-footer">
                <button type="button" class="rn-btn rn-btn-reset" onclick="closeEditNilaiModal()">Batal</button>
                <button type="submit" id="btnSubmitNilai" class="rn-btn rn-btn-export">
                    <span>💾</span>
                    <span>Simpan Perubahan Nilai</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
window.openEditNilaiModal = function(regId, nama, nim, kelas, thaharah, shalat, srtPdk, amaliyah, jenazah, ut) {
    document.getElementById('edit_nilai_reg_id').value = regId;
    document.getElementById('edit_nilai_nama').textContent = nama;
    document.getElementById('edit_nilai_nim').textContent = nim;
    document.getElementById('edit_nilai_kelas').textContent = kelas;
    
    document.getElementById('edit_thaharah').value = thaharah;
    document.getElementById('edit_shalat').value = shalat;
    document.getElementById('edit_srt_pdk').value = srtPdk;
    document.getElementById('edit_amaliyah').value = amaliyah;
    document.getElementById('edit_jenazah').value = jenazah;
    document.getElementById('edit_ut').value = ut;
    
    calculateLiveModalAvg();
    document.getElementById('editNilaiModal').style.display = 'flex';
};

window.closeEditNilaiModal = function() {
    document.getElementById('editNilaiModal').style.display = 'none';
};

window.setScoreField = function(fieldId, val) {
    const el = document.getElementById(fieldId);
    if (el) {
        el.value = val;
        calculateLiveModalAvg();
    }
};

window.calculateLiveModalAvg = function() {
    const ids = ['edit_thaharah', 'edit_shalat', 'edit_srt_pdk', 'edit_amaliyah', 'edit_jenazah', 'edit_ut'];
    let sum = 0;
    let count = 0;
    ids.forEach(function(id) {
        const el = document.getElementById(id);
        if (el && el.value !== '' && !isNaN(parseFloat(el.value))) {
            sum += parseFloat(el.value);
            count++;
        }
    });
    const previewEl = document.getElementById('edit_live_avg');
    if (!previewEl) return;
    if (count > 0) {
        const avg = sum / count;
        let grade = 'E';
        if (avg >= 85) grade = 'A (Sangat Memuaskan)';
        else if (avg >= 75) grade = 'B (Baik)';
        else if (avg >= 65) grade = 'C (Cukup)';
        else if (avg >= 55) grade = 'D (Kurang)';
        else grade = 'E (Sangat Kurang)';
        previewEl.innerHTML = avg.toFixed(2) + ' <span style="font-size:12px; font-weight:700;">&bull; Predikat ' + grade + ' (' + count + '/6 Komponen)</span>';
    } else {
        previewEl.textContent = 'Belum ada nilai yang diinput';
    }
};

// Close modal on backdrop click
document.getElementById('editNilaiModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditNilaiModal();
    }
});

// Submit Form Handler
if (document.getElementById('formEditNilai')) {
    document.getElementById('formEditNilai').addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('btnSubmitNilai');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span>⏳</span><span>Menyimpan...</span>';
        }
        const formData = new FormData(this);
        fetch('', { method: 'POST', body: formData })
        .then(res => {
            if (!res.ok) throw new Error('Server error');
            return res.text();
        })
        .then(resText => {
            closeEditNilaiModal();
            alert('Nilai mahasiswa berhasil diperbarui.');
            window.location.reload();
        })
        .catch(err => {
            alert('Terjadi kesalahan saat menyimpan nilai. Silakan coba lagi.');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<span>💾</span><span>Simpan Perubahan Nilai</span>';
            }
        });
    });
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
