<?php
/**
 * API untuk Server-Side Processing Halaman Kelola Pengguna
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

// Filter custom (dari DataTables search pada spesifik kolom atau custom input)
$filterTahunAjaran = $_POST['filterTahunAjaran'] ?? '';
$filterProdi = $_POST['filterProdi'] ?? '';
$filterRole = $_POST['filterRole'] ?? '';

// Urutan kolom
$orderColIndex = $_POST['order'][0]['column'] ?? $_GET['order'][0]['column'] ?? 2;
$orderDir = $_POST['order'][0]['dir'] ?? $_GET['order'][0]['dir'] ?? 'asc';
$orderDir = (strtolower($orderDir) === 'desc') ? 'DESC' : 'ASC';

// Kolom yang bisa di-sort sesuai dengan urutan index thead
$columns = [
    0 => 'u.id', // Checkbox
    1 => 'u.id', // No
    2 => 'COALESCE(u.nim, u.username)', // NIM (Username)
    3 => 'u.nama_lengkap',
    4 => 'u.tempat_lahir',
    5 => 'u.tanggal_lahir',
    6 => 'u.program_studi',
    7 => 'COALESCE(t.calculated_ta, u.tahun_ajaran)', // Tahun Ajaran
    8 => 'u.role'
];

$orderBy = $columns[$orderColIndex] ?? 'u.nama_lengkap';

// Base Query
// Kita butuh subquery untuk calculated_ta
$fromClause = "
    FROM users u
    LEFT JOIN (
        SELECT user_id, MAX(tahun_ajaran) as calculated_ta 
        FROM tutorial_registrations 
        WHERE tahun_ajaran IS NOT NULL AND tahun_ajaran != ''
        GROUP BY user_id
    ) t ON u.id = t.user_id
    WHERE 1=1
";

$whereParams = [];

if ($filterTahunAjaran !== '') {
    $fromClause .= " AND (t.calculated_ta = ? OR (t.calculated_ta IS NULL AND u.tahun_ajaran = ?))";
    $whereParams[] = $filterTahunAjaran;
    $whereParams[] = $filterTahunAjaran;
}

if ($filterProdi !== '') {
    $fromClause .= " AND u.program_studi = ?";
    $whereParams[] = $filterProdi;
}

if ($filterRole !== '') {
    $fromClause .= " AND u.role = ?";
    $whereParams[] = strtolower($filterRole);
}

// 1. Get Total Records (without search filter)
$stmtTotal = $pdo->prepare("SELECT COUNT(u.id) $fromClause");
$stmtTotal->execute($whereParams);
$recordsTotal = $stmtTotal->fetchColumn();

// 2. Add Search Filter if exists
if ($searchValue !== '') {
    $fromClause .= " AND (
        u.nim LIKE ? 
        OR u.username LIKE ? 
        OR u.nama_lengkap LIKE ? 
        OR u.program_studi LIKE ? 
        OR u.tahun_ajaran LIKE ?
        OR t.calculated_ta LIKE ?
    )";
    $searchWildcard = '%' . $searchValue . '%';
    $whereParams = array_merge($whereParams, [
        $searchWildcard, 
        $searchWildcard, 
        $searchWildcard, 
        $searchWildcard, 
        $searchWildcard, 
        $searchWildcard
    ]);
}

// 3. Get Filtered Records Total
$stmtFiltered = $pdo->prepare("SELECT COUNT(u.id) $fromClause");
$stmtFiltered->execute($whereParams);
$recordsFiltered = $stmtFiltered->fetchColumn();

// 4. Get Data
$query = "
    SELECT u.*, COALESCE(t.calculated_ta, u.tahun_ajaran) as calculated_ta
    $fromClause
    ORDER BY $orderBy $orderDir
";

if ($length > 0) {
    $query .= " LIMIT " . (int)$start . ", " . (int)$length;
}

$stmtData = $pdo->prepare($query);
$stmtData->execute($whereParams);
$data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

// Format output
$formattedData = [];
$no = $start + 1;
$csrfToken = csrfToken(); // Dari auth.php

foreach ($data as $row) {
    // Basic sanitization
    $id = $row['id'];
    $nimUsername = htmlspecialchars($row['nim'] ?? $row['username']);
    $namaLengkap = htmlspecialchars($row['nama_lengkap']);
    $tempatLahir = htmlspecialchars($row['tempat_lahir'] ?? '-');
    $tglLahir = !empty($row['tanggal_lahir']) ? date('d/m/Y', strtotime($row['tanggal_lahir'])) : '-';
    $prodi = htmlspecialchars(!empty($row['program_studi']) ? $row['program_studi'] : '-');
    $ta = htmlspecialchars(!empty($row['calculated_ta']) ? $row['calculated_ta'] : (!empty($row['tahun_ajaran']) ? $row['tahun_ajaran'] : '-'));
    
    // Role badge
    $badgeClass = 'badge-primary';
    if ($row['role'] === 'admin') $badgeClass = 'badge-danger';
    if ($row['role'] === 'dosen') $badgeClass = 'badge-success';
    $roleBadge = '<span class="badge ' . $badgeClass . '">' . ucfirst(htmlspecialchars($row['role'])) . '</span>';
    
    // Aksi
    $aksi = '<div style="white-space:nowrap;">';
    
    // Edit Button
    $aksi .= '<button type="button" class="btn btn-sm btn-warning btn-edit-user"
        data-id="'.$id.'"
        data-nama="'.htmlspecialchars($row['nama_lengkap'], ENT_QUOTES, 'UTF-8').'"
        data-email="'.htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8').'"
        data-no-hp="'.htmlspecialchars($row['no_hp'] ?? '', ENT_QUOTES, 'UTF-8').'"
        data-prodi="'.htmlspecialchars($row['program_studi'] ?? '', ENT_QUOTES, 'UTF-8').'"
        data-tmpt-lahir="'.htmlspecialchars($row['tempat_lahir'] ?? '', ENT_QUOTES, 'UTF-8').'"
        data-tgl-lahir="'.htmlspecialchars($row['tanggal_lahir'] ?? '', ENT_QUOTES, 'UTF-8').'"
        data-ta="'.htmlspecialchars($row['tahun_ajaran'] ?? '', ENT_QUOTES, 'UTF-8').'"
        data-role="'.htmlspecialchars($row['role'], ENT_QUOTES, 'UTF-8').'"
        style="margin-right:4px;">✏️ Edit</button>';
        
    // Reset Password
    $aksi .= '<form method="POST" style="display:inline;margin-right:4px;">
        <input type="hidden" name="csrf_token" value="'.$csrfToken.'">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="id" value="'.$id.'">
        <button type="submit" class="btn btn-sm btn-secondary"
            data-confirm="Reset password ke tanggal lahir (ddmmyyyy)?">🔑 Reset Pass</button>
    </form>';
    
    // Login As & Delete (if not current user)
    if ($id != $_SESSION['user_id']) {
        $aksi .= '<form method="POST" target="_blank" style="display:inline;margin-right:4px;">
            <input type="hidden" name="csrf_token" value="'.$csrfToken.'">
            <input type="hidden" name="action" value="login_as">
            <input type="hidden" name="id" value="'.$id.'">
            <button type="submit" class="btn btn-sm btn-info" style="background-color:#0ea5e9;color:white;"
                data-confirm="Buka tab baru dan login sebagai '.htmlspecialchars($row['nama_lengkap'], ENT_QUOTES).'?">🚪 Login As</button>
        </form>';
        
        $aksi .= '<form method="POST" style="display:inline;">
            <input type="hidden" name="csrf_token" value="'.$csrfToken.'">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="'.$id.'">
            <button type="submit" class="btn btn-sm btn-danger"
                data-confirm="Yakin ingin menghapus user ini?"
                data-table="users"
                data-id="'.$id.'">🗑️ Hapus</button>
        </form>';
    }
    
    $aksi .= '</div>';
    
    $formattedData[] = [
        '<div style="text-align: center;"><input type="checkbox" class="check-item" value="'.$id.'"></div>',
        $no++,
        '<strong>' . $nimUsername . '</strong>',
        $namaLengkap,
        $tempatLahir,
        $tglLahir,
        $prodi,
        $ta,
        $roleBadge,
        $aksi
    ];
}

header('Content-Type: application/json');
echo json_encode([
    "draw" => intval($draw),
    "recordsTotal" => intval($recordsTotal),
    "recordsFiltered" => intval($recordsFiltered),
    "data" => $formattedData
]);
