<?php
/**
 * API untuk Server-Side Processing Halaman Kelola Nilai Master
 */
require_once __DIR__ . '/../includes/auth.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getDBConnection();

// Parameter dari DataTables
$draw = $_POST['draw'] ?? $_GET['draw'] ?? 1;
$start = (int)($_POST['start'] ?? $_GET['start'] ?? 0);
$length = (int)($_POST['length'] ?? $_GET['length'] ?? 10);
$searchValue = trim($_POST['search']['value'] ?? $_GET['search']['value'] ?? '');

$orderColIndex = $_POST['order'][0]['column'] ?? $_GET['order'][0]['column'] ?? 1;
$orderDir = $_POST['order'][0]['dir'] ?? $_GET['order'][0]['dir'] ?? 'asc';
$orderDir = (strtolower($orderDir) === 'desc') ? 'DESC' : 'ASC';

// Kolom yang bisa di-sort
$columns = [
    0 => 'u.nim',
    1 => 'u.nim',
    2 => 'u.nama_lengkap',
    3 => 'u.program_studi',
    4 => 'tr.tahun_ajaran',
    5 => 'nilai_thaharah',
    6 => 'nilai_shalat',
    7 => 'nilai_surat_pendek',
    8 => 'nilai_amaliyah',
    9 => 'nilai_jenazah',
    10 => 'nilai_ujian_tulis',
    11 => '((IFNULL(tr.nilai_thaharah,0) + IFNULL(tr.nilai_shalat,0) + IFNULL(tr.nilai_surat_pendek,0) + IFNULL(tr.nilai_amaliyah,0) + IFNULL(tr.nilai_jenazah,0) + IFNULL(tr.nilai_ujian_tulis,0)) / NULLIF((IF(tr.nilai_thaharah IS NULL,0,1) + IF(tr.nilai_shalat IS NULL,0,1) + IF(tr.nilai_surat_pendek IS NULL,0,1) + IF(tr.nilai_amaliyah IS NULL,0,1) + IF(tr.nilai_jenazah IS NULL,0,1) + IF(tr.nilai_ujian_tulis IS NULL,0,1)), 0))'
];

$orderBy = $columns[$orderColIndex] ?? 'u.nama_lengkap';

// Base Query
$fromClause = "
    FROM users u
    JOIN tutorial_registrations tr ON tr.id = (
        SELECT MAX(id) FROM tutorial_registrations WHERE user_id = u.id
    )
    WHERE u.role = 'mahasiswa' 
    AND CAST(SUBSTRING(tr.tahun_ajaran, 1, 4) AS UNSIGNED) < 2026
";

// 1. Get Total Records (without filtering)
$stmtTotal = $pdo->query("SELECT COUNT(u.id) $fromClause");
$recordsTotal = $stmtTotal->fetchColumn();

$whereParams = [];
if ($searchValue !== '') {
    $fromClause .= " AND (
        u.nim LIKE ? 
        OR u.nama_lengkap LIKE ? 
        OR u.program_studi LIKE ? 
        OR tr.tahun_ajaran LIKE ?
        OR tr.tipe_nilai LIKE ?
        OR tr.nilai_thaharah LIKE ?
        OR tr.nilai_shalat LIKE ?
        OR tr.nilai_surat_pendek LIKE ?
        OR tr.nilai_amaliyah LIKE ?
        OR tr.nilai_jenazah LIKE ?
        OR tr.nilai_ujian_tulis LIKE ?
    )";
    $searchWildcard = "%$searchValue%";
    $whereParams = array_fill(0, 11, $searchWildcard);
}

// 2. Get Filtered Records
$stmtFiltered = $pdo->prepare("SELECT COUNT(u.id) $fromClause");
$stmtFiltered->execute($whereParams);
$recordsFiltered = $stmtFiltered->fetchColumn();

// 3. Get Data with Pagination
$dataQuery = "
    SELECT u.id as user_id, u.nim, u.nama_lengkap, u.program_studi, u.tempat_lahir, u.tanggal_lahir,
           tr.id as reg_id, tr.tahun_ajaran, tr.tipe_nilai,
           tr.nilai_thaharah, tr.nilai_shalat, tr.nilai_surat_pendek,
           tr.nilai_amaliyah, tr.nilai_jenazah, tr.nilai_ujian_tulis
    $fromClause
    ORDER BY $orderBy $orderDir
    LIMIT $length OFFSET $start
";

$stmtData = $pdo->prepare($dataQuery);
$stmtData->execute($whereParams);
$data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

// Format response untuk DataTables
$response = [
    "draw" => intval($draw),
    "recordsTotal" => intval($recordsTotal),
    "recordsFiltered" => intval($recordsFiltered),
    "data" => []
];

foreach ($data as $i => $row) {
    $sum = 0; $count = 0;
    if ($row['nilai_thaharah'] !== null) { $sum += (float)$row['nilai_thaharah']; $count++; }
    if ($row['nilai_shalat'] !== null) { $sum += (float)$row['nilai_shalat']; $count++; }
    if ($row['nilai_surat_pendek'] !== null) { $sum += (float)$row['nilai_surat_pendek']; $count++; }
    if ($row['nilai_amaliyah'] !== null) { $sum += (float)$row['nilai_amaliyah']; $count++; }
    if ($row['nilai_jenazah'] !== null) { $sum += (float)$row['nilai_jenazah']; $count++; }
    if ($row['nilai_ujian_tulis'] !== null) { $sum += (float)$row['nilai_ujian_tulis']; $count++; }
    $nilai_akhir = $count > 0 ? round($sum / $count, 2) : null;
    
    // Kelulusan Logic
    $lulus_status = '<span class="badge badge-danger" style="background:#dc3545; color:white; padding:4px 8px; border-radius:4px;">Tidak Lulus</span>';
    if ($count == 6) {
        $th = (float)$row['nilai_thaharah'];
        $sh = (float)$row['nilai_shalat'];
        $sp = (float)$row['nilai_surat_pendek'];
        $am = (float)$row['nilai_amaliyah'];
        $jn = (float)$row['nilai_jenazah'];
        $ut = (float)$row['nilai_ujian_tulis'];
        
        $tipe = strtolower(trim((string)$row['tipe_nilai']));
        $min_score = ($tipe === 'pretest') ? 80 : 70;
        
        if ($th >= $min_score && $sh >= $min_score && $sp >= $min_score && $am >= $min_score && $jn >= $min_score && $ut >= $min_score) {
            $lulus_status = '<span class="badge badge-success" style="background:#28a745; color:white; padding:4px 8px; border-radius:4px;">Lulus</span>';
        }
    } else {
        $lulus_status = '<span class="badge badge-secondary" style="background:#6c757d; color:white; padding:4px 8px; border-radius:4px;">Belum Lengkap</span>';
    }

    $editBtn = '<div style="display: flex; gap: 6px; flex-wrap: nowrap; justify-content: center; align-items: center;">
        <button class="btn btn-sm btn-warning btn-edit-nilai" style="white-space: nowrap;"
            data-user-id="' . $row['user_id'] . '"
            data-reg-id="' . ($row['reg_id'] ?: 0) . '"
            data-nama="' . htmlspecialchars($row['nama_lengkap'], ENT_QUOTES) . '"
            data-nim="' . htmlspecialchars($row['nim'] ?: '-', ENT_QUOTES) . '"
            data-ta="' . htmlspecialchars($row['tahun_ajaran'] ?? '', ENT_QUOTES) . '"
            data-tipe="' . htmlspecialchars($row['tipe_nilai'] ?? '', ENT_QUOTES) . '"
            data-thaharah="' . ($row['nilai_thaharah'] ?? '') . '"
            data-shalat="' . ($row['nilai_shalat'] ?? '') . '"
            data-srt="' . ($row['nilai_surat_pendek'] ?? '') . '"
            data-amaliyah="' . ($row['nilai_amaliyah'] ?? '') . '"
            data-jenazah="' . ($row['nilai_jenazah'] ?? '') . '"
            data-ut="' . ($row['nilai_ujian_tulis'] ?? '') . '"
            data-akhir="' . ($nilai_akhir ?? '') . '"
        >✏️ Edit</button>
        <button class="btn btn-sm btn-danger btn-delete-nilai" style="white-space: nowrap;" data-reg-id="' . ($row['reg_id'] ?: 0) . '">🗑️ Hapus</button>
    </div>';

    $checkbox = '<input type="checkbox" class="check-item" value="' . ($row['reg_id'] ?: 0) . '">';

    $response['data'][] = [
        $checkbox,
        $start + $i + 1, // Nomor urut
        htmlspecialchars($row['nim'] ?: '-'),
        '<strong>' . htmlspecialchars($row['nama_lengkap']) . '</strong>',
        htmlspecialchars($row['program_studi'] ?: '-'),
        htmlspecialchars($row['tahun_ajaran'] ?: '-'),
        htmlspecialchars(ucwords($row['tipe_nilai'] ?? '-')),
        $row['nilai_thaharah'] ?? '-',
        $row['nilai_shalat'] ?? '-',
        $row['nilai_surat_pendek'] ?? '-',
        $row['nilai_amaliyah'] ?? '-',
        $row['nilai_jenazah'] ?? '-',
        $row['nilai_ujian_tulis'] ?? '-',
        '<strong>' . ($nilai_akhir ?? '-') . '</strong>',
        $lulus_status,
        $editBtn
    ];
}

header('Content-Type: application/json');
echo json_encode($response);
