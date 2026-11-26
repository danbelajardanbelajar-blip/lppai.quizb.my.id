<?php
/**
 * LPPAI Corner - Admin: Rekapitulasi Kehadiran Tutorial Terpadu
 * Rekap Kehadiran Mahasiswa (Matriks P1-P8, H/I/S/A, %, Kelayakan Ujian)
 * dan Rekap Kehadiran Mengajar Dosen (Progres P1-P8, Tanggal, Status Tuntas/Berjalan).
 */
if (!defined('PAGE_TITLE')) define('PAGE_TITLE', 'Rekap Kehadiran Tutorial');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$user = getCurrentUser();
$pdo = getDBConnection();
require_once __DIR__ . '/../includes/admin-tutorial-registration.php';
require_once __DIR__ . '/../includes/user-helper.php';

// ── 1. Ambil Periode Gelombang (Default ke Periode Aktif) ──────────────────────
$allGelombang = $pdo->query("SELECT * FROM master_gelombang ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

$active_gel = null;
foreach ($allGelombang as $g) {
    if ($g['status'] === 'aktif') {
        $active_gel = $g;
        break;
    }
}
if (!$active_gel && !empty($allGelombang)) {
    $active_gel = $allGelombang[0];
}

$selected_gel_id = isset($_GET['gelombang_id']) ? trim($_GET['gelombang_id']) : 'active';
$current_gel = null;

if ($selected_gel_id === 'all') {
    $current_gel = null;
} elseif (is_numeric($selected_gel_id)) {
    foreach ($allGelombang as $g) {
        if ((int)$g['id'] === (int)$selected_gel_id) {
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

// ── 2. Filter & Parameter Tambahan ───────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'mahasiswa';
if (!in_array($activeTab, ['mahasiswa', 'dosen'], true)) {
    $activeTab = 'mahasiswa';
}

$selectedClassId = (int)($_GET['class_id'] ?? 0);
$selectedDosen = trim((string)($_GET['dosen'] ?? ''));
$selectedJurusan = trim((string)($_GET['jurusan'] ?? ''));
$selectedHari = trim((string)($_GET['hari'] ?? ''));
$selectedStatus = trim((string)($_GET['status_kehadiran'] ?? 'all')); // 'all', 'memenuhi', 'rawan'
$searchQuery = trim((string)($_GET['q'] ?? ''));

// ── 3. Ambil Daftar Kelas Berdasarkan Periode ──────────────────────────────────
$classes = [];
if ($current_gel) {
    $targetYear = str_replace('/', '-', trim($current_gel['tahun_ajaran']));
    $targetSemester = trim($current_gel['semester']);
    $targetGelombang = trim($current_gel['gelombang']);
    $targetYearSem = $targetYear . '-' . $targetSemester;

    $stmtClasses = $pdo->prepare("
        SELECT * FROM tutorial_classes
        WHERE gelombang = ?
          AND (
              TRIM(semester) = ?
              OR REPLACE(TRIM(semester), '/', '-') = ?
              OR semester LIKE ?
          )
        ORDER BY gelombang, hari, nama_kelas
    ");
    $stmtClasses->execute([
        $targetGelombang,
        $targetSemester,
        $targetYearSem,
        '%' . $targetYear . '%'
    ]);
    $classes = $stmtClasses->fetchAll(PDO::FETCH_ASSOC);
} else {
    $classes = $pdo->query("SELECT * FROM tutorial_classes ORDER BY gelombang, hari, nama_kelas")->fetchAll(PDO::FETCH_ASSOC);
}

$classIds = array_column($classes, 'id');
$classLookup = [];
$uniqueDosenList = [];
$uniqueHariMap = [];
foreach ($classes as $c) {
    $classLookup[$c['id']] = $c;
    if (!empty($c['dosen_pengampu']) && !in_array($c['dosen_pengampu'], $uniqueDosenList, true)) {
        $uniqueDosenList[] = $c['dosen_pengampu'];
    }
    $hariVal = trim((string)($c['hari'] ?? ''));
    if ($hariVal !== '') {
        $hariKey = mb_strtolower($hariVal, 'UTF-8');
        if (!isset($uniqueHariMap[$hariKey])) {
            $uniqueHariMap[$hariKey] = $hariVal;
        }
    }
}
sort($uniqueDosenList);
$hariOrder = ['senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4, 'jumat' => 5, 'sabtu' => 6, 'minggu' => 7];
$uniqueHariList = array_values($uniqueHariMap);
usort($uniqueHariList, function ($a, $b) use ($hariOrder) {
    $ka = mb_strtolower($a, 'UTF-8');
    $kb = mb_strtolower($b, 'UTF-8');
    $oa = $hariOrder[$ka] ?? 99;
    $ob = $hariOrder[$kb] ?? 99;
    if ($oa !== $ob) {
        return $oa <=> $ob;
    }
    return strcasecmp($a, $b);
});

// ── 4. Ambil Data Mahasiswa, Absensi, dan Sesi QR ──────────────────────────────
$students = [];
$uniqueJurusanList = [];
$attendanceMap = []; // [class_id => [user_id => [pertemuan_ke => status]]]
$classMeetingDates = []; // [class_id => [pertemuan_ke => tanggal]]
$classMeetingHadir = []; // [class_id => [pertemuan_ke => countHadir]]
$classMeetingFilled = []; // [class_id => [pertemuan_ke => countTotalFilled]]
$qrSessionsByClass = []; // [class_id => [pertemuan_ke => qrRow]]

if (!empty($classIds)) {
    $inClassPlaceholders = implode(',', array_fill(0, count($classIds), '?'));

    // Mahasiswa terdaftar
    $sqlStudents = "
        SELECT tr.id as reg_id, tr.user_id, tr.tutorial_class_id,
               u.nama_lengkap, u.nim, u.program_studi, u.jenis_program,
               tc.nama_kelas, tc.dosen_pengampu, tc.hari, tc.jam, tc.ruangan, tc.semester, tc.gelombang
        FROM tutorial_registrations tr
        JOIN users u ON tr.user_id = u.id
        JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
        WHERE tr.tutorial_class_id IN ($inClassPlaceholders)
        ORDER BY tc.nama_kelas ASC, u.nama_lengkap ASC
    ";
    $stmtSt = $pdo->prepare($sqlStudents);
    $stmtSt->execute($classIds);
    $allStudents = $stmtSt->fetchAll(PDO::FETCH_ASSOC);

    $jurusanMap = [];
    foreach ($allStudents as $st) {
        $prodiVal = trim((string)($st['program_studi'] ?? ''));
        if ($prodiVal !== '') {
            $prodiKey = mb_strtolower($prodiVal, 'UTF-8');
            if (!isset($jurusanMap[$prodiKey])) {
                $jurusanMap[$prodiKey] = $prodiVal;
            }
        }
    }
    $uniqueJurusanList = array_values($jurusanMap);
    natcasesort($uniqueJurusanList);
    $uniqueJurusanList = array_values($uniqueJurusanList);

    // Filter mahasiswa jika ada filter kelas, dosen, hari, jurusan, status, search
    foreach ($allStudents as $st) {
        if ($selectedClassId > 0 && (int)$st['tutorial_class_id'] !== $selectedClassId) {
            continue;
        }
        if ($selectedDosen !== '' && $st['dosen_pengampu'] !== $selectedDosen) {
            continue;
        }
        if ($selectedHari !== '' && mb_strtolower(trim((string)($st['hari'] ?? '')), 'UTF-8') !== mb_strtolower($selectedHari, 'UTF-8')) {
            continue;
        }
        if ($selectedJurusan !== '' && mb_strtolower(trim((string)($st['program_studi'] ?? '')), 'UTF-8') !== mb_strtolower($selectedJurusan, 'UTF-8')) {
            continue;
        }
        if ($searchQuery !== '') {
            $sqLower = mb_strtolower($searchQuery, 'UTF-8');
            $nameMatch = mb_stripos($st['nama_lengkap'], $sqLower) !== false;
            $nimMatch = mb_stripos($st['nim'], $sqLower) !== false;
            if (!$nameMatch && !$nimMatch) {
                continue;
            }
        }
        $students[] = $st;
    }

    // Catatan absensi
    $sqlAtt = "
        SELECT tutorial_class_id, user_id, pertemuan_ke, status_hadir, tanggal
        FROM tutorial_attendance
        WHERE tutorial_class_id IN ($inClassPlaceholders)
        ORDER BY pertemuan_ke ASC
    ";
    $stmtAtt = $pdo->prepare($sqlAtt);
    $stmtAtt->execute($classIds);
    $allAttendanceRows = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allAttendanceRows as $row) {
        $cid = (int)$row['tutorial_class_id'];
        $uid = (int)$row['user_id'];
        $p = (int)$row['pertemuan_ke'];
        $st = strtolower(trim((string)$row['status_hadir']));

        $attendanceMap[$cid][$uid][$p] = $st;

        if (!empty($row['tanggal']) && empty($classMeetingDates[$cid][$p])) {
            $classMeetingDates[$cid][$p] = $row['tanggal'];
        }
        if (in_array($st, ['hadir', 'izin', 'sakit', 'absen'], true)) {
            $classMeetingFilled[$cid][$p] = ($classMeetingFilled[$cid][$p] ?? 0) + 1;
            if ($st === 'hadir') {
                $classMeetingHadir[$cid][$p] = ($classMeetingHadir[$cid][$p] ?? 0) + 1;
            }
        }
    }

    // Sesi QR
    $sqlQr = "
        SELECT tutorial_class_id, pertemuan_ke, created_at, expires_at
        FROM tutorial_qr_sessions
        WHERE tutorial_class_id IN ($inClassPlaceholders)
        ORDER BY pertemuan_ke ASC
    ";
    $stmtQr = $pdo->prepare($sqlQr);
    $stmtQr->execute($classIds);
    $allQrRows = $stmtQr->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allQrRows as $qr) {
        $cid = (int)$qr['tutorial_class_id'];
        $p = (int)$qr['pertemuan_ke'];
        if (!isset($qrSessionsByClass[$cid][$p])) {
            $qrSessionsByClass[$cid][$p] = $qr;
        }
        if (empty($classMeetingDates[$cid][$p]) && !empty($qr['created_at'])) {
            $classMeetingDates[$cid][$p] = substr($qr['created_at'], 0, 10);
        }
    }
}

// ── 5. Komputasi Rekapitulasi Mahasiswa ────────────────────────────────────────
$max_pertemuan = 8;
$studentSummaries = [];
$filteredStudents = [];
$totalEligible = 0;
$totalWarning = 0;
$sumPercentages = 0;

foreach ($students as $st) {
    $cid = (int)$st['tutorial_class_id'];
    $uid = (int)$st['user_id'];

    $h = 0; $iz = 0; $sk = 0; $ab = 0;
    for ($p = 1; $p <= $max_pertemuan; $p++) {
        $status = $attendanceMap[$cid][$uid][$p] ?? 'belum';
        if ($status === 'hadir') $h++;
        elseif ($status === 'izin') $iz++;
        elseif ($status === 'sakit') $sk++;
        elseif ($status === 'absen') $ab++;
    }

    $pct = round(($h / $max_pertemuan) * 100, 1);
    $isEligible = ($h >= 6); // Syarat minimal 75% kehadiran tutorial (>= 6 dari 8 pertemuan)

    if ($selectedStatus === 'memenuhi' && !$isEligible) {
        continue;
    }
    if ($selectedStatus === 'rawan' && $isEligible) {
        continue;
    }

    if ($isEligible) {
        $totalEligible++;
    } else {
        $totalWarning++;
    }
    $sumPercentages += $pct;

    $studentSummaries[$uid] = [
        'hadir' => $h,
        'izin' => $iz,
        'sakit' => $sk,
        'absen' => $ab,
        'persen' => $pct,
        'is_eligible' => $isEligible
    ];
    $filteredStudents[] = $st;
}

$avgStudentAttendance = count($filteredStudents) > 0 ? round($sumPercentages / count($filteredStudents), 1) : 0;

// ── 6. Komputasi Rekapitulasi Mengajar Dosen ──────────────────────────────────
$dosenClassReports = [];
$totalDosenCompletedClasses = 0;
$totalConductedAllClasses = 0;

foreach ($classes as $c) {
    if ($selectedClassId > 0 && (int)$c['id'] !== $selectedClassId) {
        continue;
    }
    if ($selectedDosen !== '' && $c['dosen_pengampu'] !== $selectedDosen) {
        continue;
    }
    if ($selectedHari !== '' && mb_strtolower(trim((string)($c['hari'] ?? '')), 'UTF-8') !== mb_strtolower($selectedHari, 'UTF-8')) {
        continue;
    }

    $cid = (int)$c['id'];
    $meetingsConducted = 0;
    $meetingsDetail = [];

    for ($p = 1; $p <= $max_pertemuan; $p++) {
        $filledCount = $classMeetingFilled[$cid][$p] ?? 0;
        $hadirCount = $classMeetingHadir[$cid][$p] ?? 0;
        $qrExists = isset($qrSessionsByClass[$cid][$p]);
        $isHeld = ($filledCount > 0 || $qrExists);

        if ($isHeld) {
            $meetingsConducted++;
        }

        $meetingsDetail[$p] = [
            'pertemuan_ke' => $p,
            'is_held' => $isHeld,
            'tanggal' => $classMeetingDates[$cid][$p] ?? null,
            'hadir' => $hadirCount,
            'total_diisi' => $filledCount
        ];
    }

    $conductedPct = round(($meetingsConducted / $max_pertemuan) * 100);
    $isCompleted = ($meetingsConducted >= $max_pertemuan);
    if ($isCompleted) {
        $totalDosenCompletedClasses++;
    }
    $totalConductedAllClasses += $meetingsConducted;

    // Hitung jumlah mahasiswa di kelas ini
    $classStudentCount = 0;
    foreach ($allStudents ?? [] as $st) {
        if ((int)$st['tutorial_class_id'] === $cid) {
            $classStudentCount++;
        }
    }

    $dosenClassReports[] = [
        'class' => $c,
        'student_count' => $classStudentCount,
        'conducted_count' => $meetingsConducted,
        'conducted_pct' => $conductedPct,
        'is_completed' => $isCompleted,
        'meetings_detail' => $meetingsDetail
    ];
}

$avgConductedMeetings = count($dosenClassReports) > 0 ? round($totalConductedAllClasses / count($dosenClassReports), 1) : 0;

// ── 7. Handle Export CSV: Mahasiswa atau Dosen ────────────────────────────────
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'export_csv_mahasiswa') {
        $filename = 'rekap-kehadiran-mahasiswa-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM agar Excel membuka dengan rapi
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($out, [
            'No', 'NIM', 'Nama Mahasiswa', 'Program Studi', 'Jenis Program',
            'Kelas Tutorial', 'Dosen Pengampu', 'Jadwal', 'Ruangan',
            'P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8',
            'Total Hadir (H)', 'Total Izin (I)', 'Total Sakit (S)', 'Total Alpa (A)',
            'Persentase Hadir (%)', 'Status Kelayakan Ujian'
        ]);

        $no = 1;
        foreach ($filteredStudents as $st) {
            $cid = (int)$st['tutorial_class_id'];
            $uid = (int)$st['user_id'];
            $sum = $studentSummaries[$uid] ?? ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'absen' => 0, 'persen' => 0, 'is_eligible' => false];

            $row = [
                $no++,
                "'" . $st['nim'],
                $st['nama_lengkap'],
                $st['program_studi'],
                $st['jenis_program'] ?? 'Reguler',
                $st['nama_kelas'],
                $st['dosen_pengampu'],
                $st['hari'] . ', ' . $st['jam'],
                $st['ruangan']
            ];

            for ($p = 1; $p <= 8; $p++) {
                $statusVal = $attendanceMap[$cid][$uid][$p] ?? 'belum';
                $label = '-';
                if ($statusVal === 'hadir') $label = 'H';
                elseif ($statusVal === 'izin') $label = 'I';
                elseif ($statusVal === 'sakit') $label = 'S';
                elseif ($statusVal === 'absen') $label = 'A';
                $row[] = $label;
            }

            $row[] = $sum['hadir'];
            $row[] = $sum['izin'];
            $row[] = $sum['sakit'];
            $row[] = $sum['absen'];
            $row[] = $sum['persen'] . '%';
            $row[] = $sum['is_eligible'] ? 'Memenuhi Syarat' : 'Perlu Perhatian';

            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    if ($_GET['action'] === 'export_csv_dosen') {
        $filename = 'rekap-kehadiran-dosen-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($out, [
            'No', 'Dosen Pengampu', 'Kelas Tutorial', 'Jadwal', 'Ruangan',
            'Semester', 'Gelombang', 'Total Mahasiswa',
            'P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8',
            'Pertemuan Terlaksana', 'Persentase (%)', 'Status Perkuliahan'
        ]);

        $no = 1;
        foreach ($dosenClassReports as $rep) {
            $c = $rep['class'];
            $row = [
                $no++,
                $c['dosen_pengampu'],
                $c['nama_kelas'],
                $c['hari'] . ', ' . $c['jam'],
                $c['ruangan'],
                $c['semester'],
                $c['gelombang'],
                $rep['student_count']
            ];

            for ($p = 1; $p <= 8; $p++) {
                $det = $rep['meetings_detail'][$p];
                $row[] = $det['is_held'] ? ('✓ (' . ($det['tanggal'] ? date('d/m/y', strtotime($det['tanggal'])) : 'Ada') . ')') : '-';
            }

            $row[] = $rep['conducted_count'] . '/8';
            $row[] = $rep['conducted_pct'] . '%';
            $row[] = $rep['is_completed'] ? 'Tuntas' : ($rep['conducted_count'] > 0 ? 'Sedang Berjalan' : 'Belum Mulai');

            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    if ($_GET['action'] === 'export_pdf') {
        $dtNow = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        $indonesianMonths = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $printDateFormatted = $dtNow->format('j') . ' ' . $indonesianMonths[(int)$dtNow->format('n')] . ' ' . $dtNow->format('Y') . ', ' . $dtNow->format('H:i') . ' WIB';
        $signatureDate = $dtNow->format('j') . ' ' . $indonesianMonths[(int)$dtNow->format('n')] . ' ' . $dtNow->format('Y');

        $backParams = http_build_query([
            'tab' => $activeTab,
            'gelombang_id' => $selected_gel_id,
            'class_id' => $selectedClassId,
            'dosen' => $selectedDosen,
            'hari' => $selectedHari,
            'jurusan' => $selectedJurusan,
            'status_kehadiran' => $selectedStatus,
            'q' => $searchQuery
        ]);
        $backUrl = '?' . $backParams;
        $periodeLabel = $current_gel
            ? ($current_gel['tahun_ajaran'] . ' (' . $current_gel['gelombang'] . ' - ' . $current_gel['semester'] . ')')
            : 'Semua Periode';
        $kelasLabel = ($selectedClassId > 0 && isset($classLookup[$selectedClassId]))
            ? ($classLookup[$selectedClassId]['nama_kelas'] . ' (' . $classLookup[$selectedClassId]['hari'] . ', ' . $classLookup[$selectedClassId]['jam'] . ')')
            : 'Semua Kelas';
        $statusLabelMap = ['all' => 'Semua Status', 'memenuhi' => 'Memenuhi (>= 75%)', 'rawan' => 'Rawan (< 75%)'];
        $statusLabel = $statusLabelMap[$selectedStatus] ?? 'Semua Status';
        $docTitle = $activeTab === 'dosen'
            ? 'REKAPITULASI KEHADIRAN MENGAJAR DOSEN TUTORIAL'
            : 'REKAPITULASI KEHADIRAN MAHASISWA TUTORIAL';
        ?>
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?= htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') ?></title>
            <link rel="icon" href="<?= BASE_URL ?>/assets/logo.svg" type="image/svg+xml">
            <style>
                @page { size: A4 landscape; margin: 10mm; }
                * { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
                body { margin: 0; padding: 0; background: #e2e8f0; color: #0f172a; font-family: Arial, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 9pt; line-height: 1.35; }
                .toolbar-screen { background: #ffffff; border-bottom: 1.5px solid #cbd5e1; padding: 12px 24px; position: sticky; top: 0; z-index: 1000; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
                .toolbar-screen .actions-left, .toolbar-screen .actions-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
                .btn-toolbar { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; border: none; }
                .btn-back { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
                .btn-print { background: #dc2626; color: #ffffff; }
                .badge-info-stat { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; }
                .page-sheet { width: 297mm; min-height: 210mm; padding: 12mm; margin: 16px auto; background: #ffffff; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
                .kop-surat { display: flex; align-items: center; gap: 14px; padding-bottom: 10px; border-bottom: 3px double #0f172a; margin-bottom: 12px; }
                .kop-logo { width: 62px; height: 62px; object-fit: contain; flex-shrink: 0; }
                .kop-text { flex: 1; text-align: center; }
                .kop-text h1 { margin: 0; font-size: 13pt; font-weight: 800; text-transform: uppercase; color: #0f172a; }
                .kop-text h2 { margin: 2px 0 0; font-size: 11pt; font-weight: 700; color: #1e293b; }
                .kop-text p { margin: 2px 0 0; font-size: 8.5pt; color: #475569; }
                .doc-heading { text-align: center; margin-bottom: 10px; }
                .doc-heading h3 { margin: 0; font-size: 11.5pt; font-weight: 800; text-transform: uppercase; text-decoration: underline; }
                .filter-summary { display: flex; flex-wrap: wrap; gap: 6px 18px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; margin-bottom: 12px; font-size: 8.5pt; }
                .filter-summary span strong { color: #0f172a; }
                table.pdf-table { width: 100%; border-collapse: collapse; font-size: 8pt; }
                table.pdf-table th, table.pdf-table td { border: 1px solid #94a3b8; padding: 4px 5px; vertical-align: middle; }
                table.pdf-table th { background: #f1f5f9; color: #0f172a; font-weight: 700; text-align: center; }
                table.pdf-table tbody tr:nth-child(even) { background: #f8fafc; }
                .text-center { text-align: center; }
                .mono { font-family: monospace; }
                .st-h { color: #166534; font-weight: 700; background: #dcfce7; }
                .st-i { color: #0369a1; font-weight: 700; background: #e0f2fe; }
                .st-s { color: #92400e; font-weight: 700; background: #fef3c7; }
                .st-a { color: #991b1b; font-weight: 700; background: #fee2e2; }
                .st-b { color: #94a3b8; }
                .doc-footer-area { display: flex; justify-content: space-between; align-items: flex-start; margin-top: 16px; gap: 20px; page-break-inside: avoid; }
                .rekap-box { border: 1px solid #cbd5e1; background: #f8fafc; border-radius: 6px; padding: 8px 12px; font-size: 8.5pt; line-height: 1.5; min-width: 280px; }
                .signature-box { text-align: center; min-width: 220px; font-size: 9pt; }
                .signature-space { height: 52px; }
                @media print {
                    body { background: #ffffff; }
                    .toolbar-screen { display: none !important; }
                    .page-sheet { width: 100%; min-height: auto; margin: 0; padding: 0; box-shadow: none; }
                }
            </style>
        </head>
        <body>
            <div class="toolbar-screen">
                <div class="actions-left">
                    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-toolbar btn-back">← Kembali ke Rekap</a>
                    <span class="badge-info-stat">
                        <?= $activeTab === 'dosen' ? ('Total: ' . count($dosenClassReports) . ' Kelas') : ('Total: ' . count($filteredStudents) . ' Mahasiswa') ?>
                    </span>
                </div>
                <div class="actions-right">
                    <button type="button" class="btn-toolbar btn-print" onclick="window.print()">📄 Simpan / Cetak PDF</button>
                </div>
            </div>

            <main class="page-sheet">
                <header class="kop-surat">
                    <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo LPPAI" class="kop-logo" onerror="this.style.display='none'">
                    <div class="kop-text">
                        <h1>Lembaga Pengembangan Pendidikan Agama Islam (LPPAI)</h1>
                        <h2>Universitas Islam Darul 'Ulum Lamongan</h2>
                        <p>Dicetak pada: <?= htmlspecialchars($printDateFormatted, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </header>

                <div class="doc-heading">
                    <h3><?= htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') ?></h3>
                </div>

                <div class="filter-summary">
                    <span>Periode: <strong><?= htmlspecialchars($periodeLabel, ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <span>Kelas: <strong><?= htmlspecialchars($kelasLabel, ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <span>Dosen: <strong><?= htmlspecialchars($selectedDosen !== '' ? $selectedDosen : 'Semua Dosen', ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <span>Hari: <strong><?= htmlspecialchars($selectedHari !== '' ? $selectedHari : 'Semua Hari', ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <?php if ($activeTab === 'mahasiswa'): ?>
                        <span>Jurusan: <strong><?= htmlspecialchars($selectedJurusan !== '' ? $selectedJurusan : 'Semua Jurusan', ENT_QUOTES, 'UTF-8') ?></strong></span>
                        <span>Syarat: <strong><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></strong></span>
                        <?php if ($searchQuery !== ''): ?>
                            <span>Kata Kunci: <strong><?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?></strong></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ($activeTab === 'mahasiswa'): ?>
                    <table class="pdf-table">
                        <thead>
                            <tr>
                                <th width="26">No</th>
                                <th width="82">NIM</th>
                                <th>Nama Mahasiswa &amp; Prodi</th>
                                <th>Kelas &amp; Dosen</th>
                                <th width="24">P1</th><th width="24">P2</th><th width="24">P3</th><th width="24">P4</th>
                                <th width="24">P5</th><th width="24">P6</th><th width="24">P7</th><th width="24">P8</th>
                                <th width="24">H</th><th width="24">I</th><th width="24">S</th><th width="24">A</th>
                                <th width="42">%</th>
                                <th width="86">Kelayakan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filteredStudents)): ?>
                                <tr><td colspan="18" class="text-center" style="padding:14px;">Tidak ada data mahasiswa yang cocok dengan filter aktif.</td></tr>
                            <?php else: ?>
                                <?php $no = 1; foreach ($filteredStudents as $st):
                                    $cid = (int)$st['tutorial_class_id'];
                                    $uid = (int)$st['user_id'];
                                    $sum = $studentSummaries[$uid] ?? ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'absen' => 0, 'persen' => 0, 'is_eligible' => false];
                                ?>
                                <tr>
                                    <td class="text-center"><?= $no++ ?></td>
                                    <td class="mono"><?= htmlspecialchars($st['nim'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($st['nama_lengkap'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                        <span style="color:#475569;"><?= htmlspecialchars($st['program_studi'] ?: '-', ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($st['jenis_program'] ?? 'Reguler', ENT_QUOTES, 'UTF-8') ?>)</span>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($st['nama_kelas'], ENT_QUOTES, 'UTF-8') ?></strong> (<?= htmlspecialchars($st['hari'], ENT_QUOTES, 'UTF-8') ?>)<br>
                                        <span style="color:#475569;"><?= htmlspecialchars($st['dosen_pengampu'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <?php for ($p = 1; $p <= 8; $p++):
                                        $stVal = $attendanceMap[$cid][$uid][$p] ?? 'belum';
                                        $cls = 'st-b'; $lbl = '-';
                                        if ($stVal === 'hadir') { $cls = 'st-h'; $lbl = 'H'; }
                                        elseif ($stVal === 'izin') { $cls = 'st-i'; $lbl = 'I'; }
                                        elseif ($stVal === 'sakit') { $cls = 'st-s'; $lbl = 'S'; }
                                        elseif ($stVal === 'absen') { $cls = 'st-a'; $lbl = 'A'; }
                                    ?>
                                        <td class="text-center <?= $cls ?>"><?= $lbl ?></td>
                                    <?php endfor; ?>
                                    <td class="text-center"><strong><?= $sum['hadir'] ?></strong></td>
                                    <td class="text-center"><?= $sum['izin'] ?></td>
                                    <td class="text-center"><?= $sum['sakit'] ?></td>
                                    <td class="text-center"><?= $sum['absen'] ?></td>
                                    <td class="text-center"><strong><?= $sum['persen'] ?>%</strong></td>
                                    <td class="text-center" style="font-weight:700; color:<?= $sum['is_eligible'] ? '#065f46' : '#be123c' ?>;">
                                        <?= $sum['is_eligible'] ? 'Memenuhi' : 'Perlu Perhatian' ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <div class="doc-footer-area">
                        <div class="rekap-box">
                            <strong>Ringkasan Rekap Mahasiswa:</strong><br>
                            • Total Peserta: <strong><?= count($filteredStudents) ?></strong> mahasiswa<br>
                            • Memenuhi Syarat (&ge; 75%): <strong><?= $totalEligible ?></strong> mahasiswa<br>
                            • Perlu Perhatian (&lt; 75%): <strong><?= $totalWarning ?></strong> mahasiswa<br>
                            • Rata-rata Kehadiran: <strong><?= $avgStudentAttendance ?>%</strong>
                        </div>
                        <div class="signature-box">
                            Lamongan, <?= htmlspecialchars($signatureDate, ENT_QUOTES, 'UTF-8') ?><br>
                            <strong>Koordinator Tutorial LPPAI</strong>
                            <div class="signature-space"></div>
                            <strong>( _______________________ )</strong>
                        </div>
                    </div>
                <?php else: ?>
                    <table class="pdf-table">
                        <thead>
                            <tr>
                                <th width="26">No</th>
                                <th>Dosen Pengampu</th>
                                <th>Kelas &amp; Jadwal</th>
                                <th width="46">Peserta</th>
                                <th width="42">P1</th><th width="42">P2</th><th width="42">P3</th><th width="42">P4</th>
                                <th width="42">P5</th><th width="42">P6</th><th width="42">P7</th><th width="42">P8</th>
                                <th width="58">Progres</th>
                                <th width="76">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($dosenClassReports)): ?>
                                <tr><td colspan="14" class="text-center" style="padding:14px;">Tidak ada data kelas dosen yang cocok dengan filter aktif.</td></tr>
                            <?php else: ?>
                                <?php $no = 1; foreach ($dosenClassReports as $rep): $c = $rep['class']; ?>
                                <tr>
                                    <td class="text-center"><?= $no++ ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($c['dosen_pengampu'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                        <span style="color:#475569;">Ruangan: <?= htmlspecialchars($c['ruangan'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($c['nama_kelas'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                        <span style="color:#475569;"><?= htmlspecialchars($c['hari'] . ', ' . $c['jam'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td class="text-center"><?= $rep['student_count'] ?></td>
                                    <?php for ($p = 1; $p <= 8; $p++):
                                        $det = $rep['meetings_detail'][$p];
                                        $dateStr = $det['tanggal'] ? date('d/m', strtotime($det['tanggal'])) : 'Ada';
                                    ?>
                                        <td class="text-center <?= $det['is_held'] ? 'st-h' : 'st-b' ?>">
                                            <?= $det['is_held'] ? ('✓<br><small>' . htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') . '</small>') : '-' ?>
                                        </td>
                                    <?php endfor; ?>
                                    <td class="text-center"><strong><?= $rep['conducted_count'] ?>/8</strong> (<?= $rep['conducted_pct'] ?>%)</td>
                                    <td class="text-center"><?= $rep['is_completed'] ? 'Tuntas' : ($rep['conducted_count'] > 0 ? 'Berjalan' : 'Belum Mulai') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <div class="doc-footer-area">
                        <div class="rekap-box">
                            <strong>Ringkasan Mengajar Dosen:</strong><br>
                            • Total Kelas: <strong><?= count($dosenClassReports) ?></strong> kelas<br>
                            • Rata-rata Terlaksana: <strong><?= $avgConductedMeetings ?> / 8</strong> pertemuan<br>
                            • Kelas Tuntas (8 Pertemuan): <strong><?= $totalDosenCompletedClasses ?></strong> kelas
                        </div>
                        <div class="signature-box">
                            Lamongan, <?= htmlspecialchars($signatureDate, ENT_QUOTES, 'UTF-8') ?><br>
                            <strong>Koordinator Tutorial LPPAI</strong>
                            <div class="signature-space"></div>
                            <strong>( _______________________ )</strong>
                        </div>
                    </div>
                <?php endif; ?>
            </main>

            <?php if (isset($_GET['print']) && $_GET['print'] === '1'): ?>
            <script>
                window.addEventListener('load', function() {
                    setTimeout(function() { window.print(); }, 300);
                });
            </script>
            <?php endif; ?>
        </body>
        </html>
        <?php
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<!-- Print & Custom Styling -->
<style>
@media print {
    .no-print, .topbar, .sidebar, .sidebar-overlay, .btn, .tabs, .user-menu-container, footer, .sidebar-header, .filter-card {
        display: none !important;
    }
    .main-content {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
    }
    .card {
        box-shadow: none !important;
        border: 1px solid #94a3b8 !important;
        margin-bottom: 20px !important;
        page-break-inside: avoid;
    }
    .print-only {
        display: block !important;
    }
    .badge {
        border: 1px solid #334155 !important;
        color: #0f172a !important;
        background: transparent !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    table {
        font-size: 11px !important;
        width: 100% !important;
        border-collapse: collapse !important;
    }
    th, td {
        padding: 5px 4px !important;
        border: 1px solid #cbd5e1 !important;
    }
}
.print-only {
    display: none;
}
.rekap-badge-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 11px;
}
.rekap-badge-hadir { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.rekap-badge-izin { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
.rekap-badge-sakit { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
.rekap-badge-absen { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.rekap-badge-belum { background: #f1f5f9; color: #94a3b8; border: 1px solid #e2e8f0; }
</style>

<!-- Kop Cetak Resmi -->
<div class="print-only" style="margin-bottom: 24px; text-align: center; border-bottom: 2px solid #0f172a; padding-bottom: 12px;">
    <h2 style="margin: 0; font-size: 18px; text-transform: uppercase; color: #0f172a; font-weight: 800;">LEMBAGA PENGEMBANGAN PENDIDIKAN AGAMA ISLAM (LPPAI)</h2>
    <h3 style="margin: 4px 0 0 0; font-size: 14px; font-weight: 700; color: #334155;">
        <?= $activeTab === 'dosen' ? 'MONITORING & REKAPITULASI KEHADIRAN MENGAJAR DOSEN' : 'REKAPITULASI KEHADIRAN MAHASISWA TUTORIAL' ?>
    </h3>
    <div style="font-size: 12px; margin-top: 6px; color: #475569;">
        Periode: <strong><?= $current_gel ? sanitize($current_gel['tahun_ajaran']) . ' (' . sanitize($current_gel['gelombang']) . ' - ' . sanitize($current_gel['semester']) . ')' : 'Semua Periode' ?></strong>
        &nbsp;|&nbsp; Dicetak pada: <strong><?= date('d/m/Y H:i') ?> WIB</strong>
    </div>
</div>

<!-- Header Page Card -->
<div class="card welcome-card no-print" style="margin-bottom: 20px;">
    <div class="card-body" style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
        <div class="welcome-avatar" style="background:linear-gradient(135deg, #0b3b22, #156138);color:#ffffff; font-size: 28px;">
            📊
        </div>
        <div style="flex:1;min-width:240px;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
                <h2 style="font-size:22px;margin:0;font-weight:800;color:var(--primary-dark);letter-spacing:-0.3px;">Rekapitulasi Kehadiran Tutorial</h2>
                <span class="badge badge-gold">Pusat Data Presensi</span>
            </div>
            <div class="welcome-chips">
                <span class="welcome-chip">📚 Periode: <strong><?= $current_gel ? sanitize($current_gel['tahun_ajaran']) . ' (' . sanitize($current_gel['gelombang']) . ')' : 'Semua Periode' ?></strong></span>
                <span class="welcome-chip">🏫 Total Kelas: <strong><?= count($classes) ?> Kelas</strong></span>
                <span class="welcome-chip">👥 Mahasiswa: <strong><?= count($students) ?> Peserta</strong></span>
            </div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="button" class="btn btn-outline" onclick="window.print()" style="font-weight:600; display:inline-flex; align-items:center; gap:6px;">
                🖨️ Cetak Rekap
            </button>
            <?php
            $baseFilterQuery = http_build_query([
                'tab' => $activeTab,
                'gelombang_id' => $selected_gel_id,
                'class_id' => $selectedClassId,
                'dosen' => $selectedDosen,
                'hari' => $selectedHari,
                'jurusan' => $selectedJurusan,
                'status_kehadiran' => $selectedStatus,
                'q' => $searchQuery
            ]);
            $exportUrl = '?' . $baseFilterQuery . '&action=' . ($activeTab === 'dosen' ? 'export_csv_dosen' : 'export_csv_mahasiswa');
            $exportPdfUrl = '?' . $baseFilterQuery . '&action=export_pdf&print=1';
            ?>
            <a href="<?= htmlspecialchars($exportPdfUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" data-no-spa class="btn" style="background:#dc2626; color:#ffffff; border:1px solid #b91c1c; font-weight:600; display:inline-flex; align-items:center; gap:6px;">
                📄 Export PDF
            </a>
            <a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary" style="font-weight:600; display:inline-flex; align-items:center; gap:6px;">
                📥 Unduh CSV / Excel
            </a>
        </div>
    </div>
</div>

<!-- Tabs Navigasi: Rekap Mahasiswa vs Rekap Dosen -->
<div class="tabs no-print" style="display:flex; gap:10px; margin-bottom:20px; border-bottom:1px solid #e2e8f0; flex-wrap:wrap;">
    <a href="?tab=mahasiswa&gelombang_id=<?= urlencode($selected_gel_id) ?>&class_id=<?= $selectedClassId ?>&dosen=<?= urlencode($selectedDosen) ?>&hari=<?= urlencode($selectedHari) ?>&jurusan=<?= urlencode($selectedJurusan) ?>" style="padding:10px 22px; text-decoration:none; color:<?= $activeTab==='mahasiswa' ? 'var(--primary)' : '#64748b' ?>; border-bottom:2px solid <?= $activeTab==='mahasiswa' ? 'var(--primary)' : 'transparent' ?>; font-weight:<?= $activeTab==='mahasiswa' ? 'bold' : 'normal' ?>; font-size:14px; display:inline-flex; align-items:center; gap:8px;">
        <span>👥 Rekap Kehadiran Mahasiswa</span>
        <span style="font-size:11px; background:<?= $activeTab==='mahasiswa' ? 'var(--primary)' : '#e2e8f0' ?>; color:<?= $activeTab==='mahasiswa' ? '#ffffff' : '#475569' ?>; padding:2px 8px; border-radius:9999px;">
            <?= count($filteredStudents) ?>
        </span>
    </a>
    <a href="?tab=dosen&gelombang_id=<?= urlencode($selected_gel_id) ?>&class_id=<?= $selectedClassId ?>&dosen=<?= urlencode($selectedDosen) ?>&hari=<?= urlencode($selectedHari) ?>&jurusan=<?= urlencode($selectedJurusan) ?>" style="padding:10px 22px; text-decoration:none; color:<?= $activeTab==='dosen' ? 'var(--primary)' : '#64748b' ?>; border-bottom:2px solid <?= $activeTab==='dosen' ? 'var(--primary)' : 'transparent' ?>; font-weight:<?= $activeTab==='dosen' ? 'bold' : 'normal' ?>; font-size:14px; display:inline-flex; align-items:center; gap:8px;">
        <span>👨‍🏫 Rekap Kehadiran Mengajar Dosen</span>
        <span style="font-size:11px; background:<?= $activeTab==='dosen' ? 'var(--primary)' : '#e2e8f0' ?>; color:<?= $activeTab==='dosen' ? '#ffffff' : '#475569' ?>; padding:2px 8px; border-radius:9999px;">
            <?= count($dosenClassReports) ?> Kelas
        </span>
    </a>
</div>

<!-- Filter Toolbar Card -->
<div class="card filter-card no-print" style="margin-bottom: 24px; border: 1px solid #e2e8f0;">
    <div class="card-body" style="padding: 18px 20px;">
        <form method="GET" style="display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end;">
            <input type="hidden" name="tab" value="<?= sanitize($activeTab) ?>">

            <!-- Filter Periode -->
            <div style="flex:1; min-width:200px;">
                <label style="display:block; margin-bottom:6px; font-weight:700; font-size:12px; color:#475569; text-transform:uppercase;">
                    Periode Tutorial
                </label>
                <select name="gelombang_id" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;" onchange="this.form.submit()">
                    <?php if ($active_gel): ?>
                        <option value="active" <?= $selected_gel_id === 'active' ? 'selected' : '' ?>>
                            ★ Aktif: <?= sanitize($active_gel['tahun_ajaran']) ?> (<?= sanitize($active_gel['gelombang']) ?> - <?= sanitize($active_gel['semester']) ?>)
                        </option>
                    <?php endif; ?>
                    <option value="all" <?= $selected_gel_id === 'all' ? 'selected' : '' ?>>-- Semua Periode --</option>
                    <?php foreach ($allGelombang as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= (string)$selected_gel_id === (string)$g['id'] ? 'selected' : '' ?>>
                            <?= sanitize($g['tahun_ajaran']) ?> (<?= sanitize($g['gelombang']) ?> - <?= sanitize($g['semester']) ?>) <?= $g['status'] === 'aktif' ? '[Aktif]' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter Kelas -->
            <div style="flex:1; min-width:180px;">
                <label style="display:block; margin-bottom:6px; font-weight:700; font-size:12px; color:#475569; text-transform:uppercase;">
                    Kelas Tutorial
                </label>
                <select name="class_id" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;" onchange="this.form.submit()">
                    <option value="0">-- Semua Kelas --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $selectedClassId === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= sanitize($c['nama_kelas']) ?> (<?= sanitize($c['hari']) ?>, <?= sanitize($c['jam']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter Dosen -->
            <div style="flex:1; min-width:180px;">
                <label style="display:block; margin-bottom:6px; font-weight:700; font-size:12px; color:#475569; text-transform:uppercase;">
                    Dosen Pengampu
                </label>
                <select name="dosen" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;" onchange="this.form.submit()">
                    <option value="">-- Semua Dosen --</option>
                    <?php foreach ($uniqueDosenList as $dName): ?>
                        <option value="<?= htmlspecialchars($dName) ?>" <?= $selectedDosen === $dName ? 'selected' : '' ?>>
                            <?= sanitize($dName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter Hari -->
            <div style="flex:1; min-width:150px;">
                <label style="display:block; margin-bottom:6px; font-weight:700; font-size:12px; color:#475569; text-transform:uppercase;">
                    Hari
                </label>
                <select name="hari" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;" onchange="this.form.submit()">
                    <option value="">-- Semua Hari --</option>
                    <?php foreach ($uniqueHariList as $hariOpt): ?>
                        <option value="<?= htmlspecialchars($hariOpt, ENT_QUOTES, 'UTF-8') ?>" <?= mb_strtolower($selectedHari, 'UTF-8') === mb_strtolower($hariOpt, 'UTF-8') ? 'selected' : '' ?>>
                            <?= sanitize($hariOpt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($activeTab === 'mahasiswa'): ?>
                <!-- Filter Jurusan -->
                <div style="flex:1; min-width:180px;">
                    <label style="display:block; margin-bottom:6px; font-weight:700; font-size:12px; color:#475569; text-transform:uppercase;">
                        Jurusan / Prodi
                    </label>
                    <select name="jurusan" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;" onchange="this.form.submit()">
                        <option value="">-- Semua Jurusan --</option>
                        <?php foreach ($uniqueJurusanList as $jurOpt): ?>
                            <option value="<?= htmlspecialchars($jurOpt, ENT_QUOTES, 'UTF-8') ?>" <?= mb_strtolower($selectedJurusan, 'UTF-8') === mb_strtolower($jurOpt, 'UTF-8') ? 'selected' : '' ?>>
                                <?= sanitize($jurOpt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Filter Status Syarat -->
                <div style="flex:1; min-width:160px;">
                    <label style="display:block; margin-bottom:6px; font-weight:700; font-size:12px; color:#475569; text-transform:uppercase;">
                        Syarat Kehadiran
                    </label>
                    <select name="status_kehadiran" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;" onchange="this.form.submit()">
                        <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>Semua Status</option>
                        <option value="memenuhi" <?= $selectedStatus === 'memenuhi' ? 'selected' : '' ?>>✓ Memenuhi (≥ 75%)</option>
                        <option value="rawan" <?= $selectedStatus === 'rawan' ? 'selected' : '' ?>>⚠ Rawan (&lt; 75%)</option>
                    </select>
                </div>

                <!-- Cari Mahasiswa -->
                <div style="flex:1.2; min-width:200px;">
                    <label style="display:block; margin-bottom:6px; font-weight:700; font-size:12px; color:#475569; text-transform:uppercase;">
                        Cari Nama / NIM
                    </label>
                    <input type="text" name="q" value="<?= sanitize($searchQuery) ?>" placeholder="Ketik nama atau NIM..." style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;">
                </div>
            <?php elseif ($selectedJurusan !== ''): ?>
                <input type="hidden" name="jurusan" value="<?= sanitize($selectedJurusan) ?>">
            <?php endif; ?>

            <div style="display:flex; gap:6px;">
                <button type="submit" class="btn btn-primary" style="padding:8px 16px; font-weight:600;">
                    Terapkan
                </button>
                <a href="?tab=<?= sanitize($activeTab) ?>&gelombang_id=active" class="btn btn-outline" style="padding:8px 14px; font-weight:600;">
                    Reset
                </a>
            </div>
        </form>
    </div>
</div>

<?php if ($activeTab === 'mahasiswa'): ?>
    <!-- ============================================================== -->
    <!-- TAB 1: REKAP KEHADIRAN MAHASISWA                               -->
    <!-- ============================================================== -->

    <!-- KPI Grid Mahasiswa -->
    <div class="stat-grid" style="margin-bottom: 24px;">
        <div class="stat-card">
            <div class="stat-icon green">👥</div>
            <div class="stat-info">
                <h3><?= number_format(count($filteredStudents)) ?></h3>
                <p>Total Mahasiswa</p>
                <span style="font-size:11px; color:#64748b;">Peserta Sesuai Filter</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue">📈</div>
            <div class="stat-info">
                <h3><?= $avgStudentAttendance ?>%</h3>
                <p>Rata-rata Kehadiran</p>
                <span style="font-size:11px; color:#64748b;">Standar Kelulusan 75%</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">✓</div>
            <div class="stat-info">
                <h3><?= number_format($totalEligible) ?></h3>
                <p>Memenuhi Syarat (≥ 75%)</p>
                <span style="font-size:11px; color:#059669;">Minimal 6 dari 8 Pertemuan</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">⚠</div>
            <div class="stat-info">
                <h3><?= number_format($totalWarning) ?></h3>
                <p>Perlu Perhatian (&lt; 75%)</p>
                <span style="font-size:11px; color:#be123c;">Belum Memenuhi Syarat</span>
            </div>
        </div>
    </div>

    <!-- Tabel Rekap Mahasiswa -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div style="font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px;">
                <span>📋</span>
                <span>Matriks Presensi Kumulatif Mahasiswa</span>
            </div>
            <!-- Legend (No Print) -->
            <div class="no-print" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:12px;">
                <span style="font-weight:700; color:#475569;">Keterangan:</span>
                <span style="display:inline-flex; align-items:center; gap:4px;"><span class="rekap-badge-status rekap-badge-hadir" style="width:18px;height:18px;font-size:9px;">H</span> Hadir</span>
                <span style="display:inline-flex; align-items:center; gap:4px;"><span class="rekap-badge-status rekap-badge-izin" style="width:18px;height:18px;font-size:9px;">I</span> Izin</span>
                <span style="display:inline-flex; align-items:center; gap:4px;"><span class="rekap-badge-status rekap-badge-sakit" style="width:18px;height:18px;font-size:9px;">S</span> Sakit</span>
                <span style="display:inline-flex; align-items:center; gap:4px;"><span class="rekap-badge-status rekap-badge-absen" style="width:18px;height:18px;font-size:9px;">A</span> Alpa</span>
                <span style="display:inline-flex; align-items:center; gap:4px;"><span class="rekap-badge-status rekap-badge-belum" style="width:18px;height:18px;font-size:9px;">-</span> Belum</span>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($filteredStudents)): ?>
                <div class="empty-state" style="padding:40px 20px; text-align:center;">
                    <div style="font-size:40px; margin-bottom:10px;">👥</div>
                    <h3 style="margin-bottom:6px;">Tidak Ada Data Mahasiswa</h3>
                    <p style="color:#64748b;">Tidak ada data mahasiswa tutorial yang cocok dengan kriteria filter saat ini.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th width="35" style="text-align:center;">No</th>
                                <th width="110">NIM</th>
                                <th>Mahasiswa</th>
                                <th>Kelas & Dosen</th>
                                <th width="32" style="text-align:center;">P1</th>
                                <th width="32" style="text-align:center;">P2</th>
                                <th width="32" style="text-align:center;">P3</th>
                                <th width="32" style="text-align:center;">P4</th>
                                <th width="32" style="text-align:center;">P5</th>
                                <th width="32" style="text-align:center;">P6</th>
                                <th width="32" style="text-align:center;">P7</th>
                                <th width="32" style="text-align:center;">P8</th>
                                <th width="40" style="text-align:center; color:#166534;" title="Total Hadir">H</th>
                                <th width="40" style="text-align:center; color:#0369a1;" title="Total Izin">I</th>
                                <th width="40" style="text-align:center; color:#92400e;" title="Total Sakit">S</th>
                                <th width="40" style="text-align:center; color:#991b1b;" title="Total Alpa">A</th>
                                <th width="65" style="text-align:center;">% Hadir</th>
                                <th width="125" style="text-align:center;">Status Kelayakan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no = 1; foreach ($filteredStudents as $st):
                                $cid = (int)$st['tutorial_class_id'];
                                $uid = (int)$st['user_id'];
                                $sum = $studentSummaries[$uid];
                            ?>
                            <tr>
                                <td align="center" style="color:#64748b;"><?= $no++ ?></td>
                                <td>
                                    <span style="font-family:monospace; font-size:13px; font-weight:600; color:#334155;"><?= sanitize($st['nim']) ?></span>
                                </td>
                                <td>
                                    <strong><?= adminUserProfileLink($st['nama_lengkap'], $uid) ?></strong><br>
                                    <small style="color:#64748b;"><?= sanitize($st['program_studi']) ?> <?= renderJenisProgramBadge($st['jenis_program'] ?? 'Reguler', true) ?></small>
                                </td>
                                <td>
                                    <strong><?= sanitize($st['nama_kelas']) ?></strong><br>
                                    <small style="color:#64748b;"><?= sanitize($st['dosen_pengampu']) ?></small>
                                </td>
                                <?php for ($p = 1; $p <= 8; $p++):
                                    $stVal = $attendanceMap[$cid][$uid][$p] ?? 'belum';
                                    $badgeClass = 'rekap-badge-belum';
                                    $label = '-';
                                    if ($stVal === 'hadir') { $badgeClass = 'rekap-badge-hadir'; $label = 'H'; }
                                    elseif ($stVal === 'izin') { $badgeClass = 'rekap-badge-izin'; $label = 'I'; }
                                    elseif ($stVal === 'sakit') { $badgeClass = 'rekap-badge-sakit'; $label = 'S'; }
                                    elseif ($stVal === 'absen') { $badgeClass = 'rekap-badge-absen'; $label = 'A'; }
                                ?>
                                    <td align="center">
                                        <span class="rekap-badge-status <?= $badgeClass ?>" title="P<?= $p ?>: <?= strtoupper($stVal) ?>">
                                            <?= $label ?>
                                        </span>
                                    </td>
                                <?php endfor; ?>
                                <td align="center"><strong style="color:#166534;"><?= $sum['hadir'] ?></strong></td>
                                <td align="center" style="color:#0369a1;"><?= $sum['izin'] ?></td>
                                <td align="center" style="color:#92400e;"><?= $sum['sakit'] ?></td>
                                <td align="center" style="color:#991b1b;"><?= $sum['absen'] ?></td>
                                <td align="center">
                                    <strong style="color: <?= $sum['is_eligible'] ? '#059669' : '#e11d48' ?>; font-size:13px;">
                                        <?= $sum['persen'] ?>%
                                    </strong>
                                </td>
                                <td align="center">
                                    <?php if ($sum['is_eligible']): ?>
                                        <span class="badge" style="background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-weight:700;font-size:11px;">
                                            ✓ Memenuhi
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#fff1f2;color:#be123c;border:1px solid #fecdd3;font-weight:700;font-size:11px;">
                                            ⚠ Perlu Perhatian
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- ============================================================== -->
    <!-- TAB 2: REKAP KEHADIRAN MENGAJAR DOSEN                          -->
    <!-- ============================================================== -->

    <!-- KPI Grid Dosen -->
    <div class="stat-grid" style="margin-bottom: 24px;">
        <div class="stat-card">
            <div class="stat-icon green">👨‍🏫</div>
            <div class="stat-info">
                <h3><?= number_format(count($uniqueDosenList)) ?></h3>
                <p>Total Dosen Pengampu</p>
                <span style="font-size:11px; color:#64748b;">Pengajar Terjadwal</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue">🏫</div>
            <div class="stat-info">
                <h3><?= number_format(count($dosenClassReports)) ?></h3>
                <p>Total Kelas Tutorial</p>
                <span style="font-size:11px; color:#64748b;">Kelas di Periode Terpilih</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">📊</div>
            <div class="stat-info">
                <h3><?= $avgConductedMeetings ?> / 8</h3>
                <p>Rata-rata Terlaksana</p>
                <span style="font-size:11px; color:#64748b;">Pertemuan per Kelas</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">🏆</div>
            <div class="stat-info">
                <h3><?= number_format($totalDosenCompletedClasses) ?></h3>
                <p>Kelas Tuntas (8 Pertemuan)</p>
                <span style="font-size:11px; color:#059669;">100% Selesai Dilaksanakan</span>
            </div>
        </div>
    </div>

    <!-- Tabel Rekap Mengajar Dosen -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div style="font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px;">
                <span>👨‍🏫</span>
                <span>Matriks Keterlaksanaan Mengajar Dosen per Kelas</span>
            </div>
            <div class="no-print" style="font-size:12px; color:#64748b;">
                Simbol <strong>✓</strong> menandakan perkuliahan telah terlaksana dengan catatan presensi mahasiswa / QR code.
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($dosenClassReports)): ?>
                <div class="empty-state" style="padding:40px 20px; text-align:center;">
                    <div style="font-size:40px; margin-bottom:10px;">🏫</div>
                    <h3 style="margin-bottom:6px;">Tidak Ada Data Kelas Dosen</h3>
                    <p style="color:#64748b;">Tidak ada data kelas tutorial yang cocok dengan kriteria filter saat ini.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th width="35" style="text-align:center;">No</th>
                                <th>Dosen Pengampu</th>
                                <th>Kelas & Jadwal</th>
                                <th width="70" style="text-align:center;">Peserta</th>
                                <th width="42" style="text-align:center;">P1</th>
                                <th width="42" style="text-align:center;">P2</th>
                                <th width="42" style="text-align:center;">P3</th>
                                <th width="42" style="text-align:center;">P4</th>
                                <th width="42" style="text-align:center;">P5</th>
                                <th width="42" style="text-align:center;">P6</th>
                                <th width="42" style="text-align:center;">P7</th>
                                <th width="42" style="text-align:center;">P8</th>
                                <th width="120" style="text-align:center;">Progres Mengajar</th>
                                <th width="125" style="text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no = 1; foreach ($dosenClassReports as $rep):
                                $c = $rep['class'];
                            ?>
                            <tr>
                                <td align="center" style="color:#64748b;"><?= $no++ ?></td>
                                <td>
                                    <strong style="color:#0f172a;"><?= sanitize($c['dosen_pengampu']) ?></strong><br>
                                    <small style="color:#64748b;">Ruangan: <?= sanitize($c['ruangan']) ?></small>
                                </td>
                                <td>
                                    <strong><?= sanitize($c['nama_kelas']) ?></strong><br>
                                    <span class="badge" style="background:#f1f5f9; color:#475569; font-size:11px;">
                                        <?= sanitize($c['hari']) ?>, <?= sanitize($c['jam']) ?>
                                    </span>
                                </td>
                                <td align="center">
                                    <span style="font-weight:700; color:#334155;"><?= $rep['student_count'] ?></span>
                                    <small style="display:block; color:#64748b; font-size:10px;">mhs</small>
                                </td>
                                <?php for ($p = 1; $p <= 8; $p++):
                                    $det = $rep['meetings_detail'][$p];
                                    $isHeld = $det['is_held'];
                                    $dateStr = $det['tanggal'] ? date('d/m', strtotime($det['tanggal'])) : '';
                                ?>
                                    <td align="center">
                                        <?php if ($isHeld): ?>
                                            <div style="background:#ecfdf5; border:1px solid #86efac; border-radius:6px; padding:3px 2px; text-align:center;" title="Pertemuan <?= $p ?> Terlaksana (<?= $det['tanggal'] ?>) - <?= $det['hadir'] ?> Hadir">
                                                <div style="color:#047857; font-weight:800; font-size:12px;">✓</div>
                                                <div style="color:#166534; font-size:9px; font-weight:600;"><?= $dateStr ?: 'Ada' ?></div>
                                            </div>
                                        <?php else: ?>
                                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:3px 2px; text-align:center; color:#94a3b8;" title="Pertemuan <?= $p ?> Belum Terlaksana">
                                                <div style="font-size:12px; font-weight:700;">—</div>
                                                <div style="font-size:9px;">-</div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endfor; ?>
                                <td align="center">
                                    <div style="display:flex; justify-content:space-between; font-size:11px; font-weight:700; margin-bottom:4px; color:#334155;">
                                        <span><?= $rep['conducted_count'] ?>/8</span>
                                        <span><?= $rep['conducted_pct'] ?>%</span>
                                    </div>
                                    <div style="background:#e2e8f0; border-radius:9999px; height:7px; overflow:hidden;">
                                        <div style="background:linear-gradient(90deg, #10b981, #059669); width:<?= $rep['conducted_pct'] ?>%; height:100%; border-radius:9999px;"></div>
                                    </div>
                                </td>
                                <td align="center">
                                    <?php if ($rep['is_completed']): ?>
                                        <span class="badge" style="background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-weight:700;font-size:11px;">
                                            ✓ Tuntas
                                        </span>
                                    <?php elseif ($rep['conducted_count'] > 0): ?>
                                        <span class="badge" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;font-weight:700;font-size:11px;">
                                            ● Berjalan (<?= $rep['conducted_count'] ?>/8)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;font-weight:700;font-size:11px;">
                                            ○ Belum Mulai
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Tanda Tangan Khusus Cetak -->
<div class="print-only" style="margin-top: 36px; page-break-inside: avoid;">
    <div style="display: flex; justify-content: space-between; font-size: 12px;">
        <div style="text-align: center; width: 220px;">
            <p>Mengetahui,<br>Ketua LPPAI</p>
            <br><br><br><br>
            <p><strong>( _______________________ )</strong></p>
        </div>
        <div style="text-align: center; width: 220px;">
            <p><?= date('d F Y') ?><br>Koordinator Tutorial LPPAI</p>
            <br><br><br><br>
            <p><strong>( _______________________ )</strong></p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
