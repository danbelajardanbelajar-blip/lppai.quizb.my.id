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
    10 => 'nilai_akhir'
];

$orderBy = $columns[$orderColIndex] ?? 'u.nama_lengkap';

// Base Query
$fromClause = "
    FROM users u
    LEFT JOIN tutorial_registrations tr ON tr.id = (
        SELECT MAX(id) FROM tutorial_registrations WHERE user_id = u.id
    )
    WHERE u.role = 'mahasiswa'
";

$whereParams = [];
if ($searchValue !== '') {
    $fromClause .= " AND (u.nim LIKE ? OR u.nama_lengkap LIKE ? OR u.program_studi LIKE ? OR tr.tahun_ajaran LIKE ?)";
    $searchWildcard = "%$searchValue%";
    $whereParams = [$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard];
}

// 1. Get Total Records (without filtering)
$stmtTotal = $pdo->query("SELECT COUNT(id) FROM users WHERE role = 'mahasiswa'");
$recordsTotal = $stmtTotal->fetchColumn();

// 2. Get Filtered Records
$stmtFiltered = $pdo->prepare("SELECT COUNT(u.id) $fromClause");
$stmtFiltered->execute($whereParams);
$recordsFiltered = $stmtFiltered->fetchColumn();

// 3. Get Data with Pagination
$dataQuery = "
    SELECT u.id as user_id, u.nim, u.nama_lengkap, u.program_studi,
           tr.id as reg_id, tr.tahun_ajaran,
           tr.nilai_thaharah, tr.nilai_shalat, tr.nilai_surat_pendek,
           tr.nilai_amaliyah, tr.nilai_jenazah, tr.nilai_akhir
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
    // Tombol Edit Modal
    $editBtn = '<button class="btn btn-sm btn-warning btn-edit-nilai" 
        data-user-id="' . $row['user_id'] . '"
        data-reg-id="' . ($row['reg_id'] ?: 0) . '"
        data-nama="' . htmlspecialchars($row['nama_lengkap'], ENT_QUOTES) . '"
        data-nim="' . htmlspecialchars($row['nim'] ?: '-', ENT_QUOTES) . '"
        data-ta="' . htmlspecialchars($row['tahun_ajaran'] ?? '', ENT_QUOTES) . '"
        data-thaharah="' . ($row['nilai_thaharah'] ?? '') . '"
        data-shalat="' . ($row['nilai_shalat'] ?? '') . '"
        data-srt="' . ($row['nilai_surat_pendek'] ?? '') . '"
        data-amaliyah="' . ($row['nilai_amaliyah'] ?? '') . '"
        data-jenazah="' . ($row['nilai_jenazah'] ?? '') . '"
        data-akhir="' . ($row['nilai_akhir'] ?? '') . '"
    >✏️ Edit</button>';

    $response['data'][] = [
        $start + $i + 1, // Nomor urut
        htmlspecialchars($row['nim'] ?: '-'),
        '<strong>' . htmlspecialchars($row['nama_lengkap']) . '</strong>',
        htmlspecialchars($row['program_studi'] ?: '-'),
        htmlspecialchars($row['tahun_ajaran'] ?: '-'),
        $row['nilai_thaharah'] ?? '-',
        $row['nilai_shalat'] ?? '-',
        $row['nilai_surat_pendek'] ?? '-',
        $row['nilai_amaliyah'] ?? '-',
        $row['nilai_jenazah'] ?? '-',
        '<strong>' . ($row['nilai_akhir'] ?? '-') . '</strong>',
        $editBtn
    ];
}

header('Content-Type: application/json');
echo json_encode($response);
