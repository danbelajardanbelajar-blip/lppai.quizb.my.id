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
$filterAngkatan = $_POST['filterAngkatan'] ?? '';
$filterProdi = $_POST['filterProdi'] ?? '';
$filterRole = $_POST['filterRole'] ?? '';
$filterActive = $_POST['filterActive'] ?? '';

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
    4 => 'u.no_hp',
    5 => 'u.tanggal_lahir',
    6 => 'u.program_studi',
    7 => 'u.angkatan', // Angkatan
    8 => 'u.role'
];

$orderBy = $columns[$orderColIndex] ?? 'u.nama_lengkap';

// Base Query
$fromClause = "
    FROM users u
    WHERE 1=1
";

$whereParams = [];

if ($filterAngkatan !== '') {
    $fromClause .= " AND u.angkatan = ?";
    $whereParams[] = $filterAngkatan;
}

if ($filterProdi !== '') {
    $fromClause .= " AND u.program_studi = ?";
    $whereParams[] = $filterProdi;
}

if ($filterRole !== '') {
    $fromClause .= " AND u.role = ?";
    $whereParams[] = strtolower($filterRole);
}

if ($filterActive !== '') {
    $fromClause .= " AND u.is_active = ?";
    $whereParams[] = (int)$filterActive;
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
        OR u.no_hp LIKE ?
        OR u.program_studi LIKE ? 
        OR u.angkatan LIKE ?
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
    SELECT u.*
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
    $tglLahir = !empty($row['tanggal_lahir']) ? date('d/m/Y', strtotime($row['tanggal_lahir'])) : '-';
    $prodi = htmlspecialchars(!empty($row['program_studi']) ? $row['program_studi'] : '-');
    $angkatan = htmlspecialchars(!empty($row['angkatan']) ? $row['angkatan'] : '-');
    
    // Format No HP with WhatsApp direct link
    $rawHp = trim($row['no_hp'] ?? '');
    $cleanPhone = formatNoHp($rawHp);
    if (!empty($cleanPhone) && strlen($cleanPhone) >= 9) {
        $noHpHtml = '<a href="https://wa.me/' . $cleanPhone . '" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-success" style="padding:3px 8px; font-size:11.5px; display:inline-flex; align-items:center; gap:4px; text-decoration:none; border-radius:6px; font-weight:600; white-space:nowrap;" title="Chat via WhatsApp">'
            . '<span>💬</span> ' . htmlspecialchars($rawHp) . '</a>';
    } elseif (!empty($rawHp)) {
        $noHpHtml = '<span style="color:#64748b; font-size:12px;">' . htmlspecialchars($rawHp) . '</span>';
    } else {
        $noHpHtml = '<span style="color:#94a3b8; font-size:12px;">-</span>';
    }

    // Password Login (Default: ddmmyyyy dari tanggal lahir, atau 123456 jika kosong)
    if (!empty($row['tanggal_lahir'])) {
        $dt = DateTime::createFromFormat('Y-m-d', $row['tanggal_lahir']);
        $passwordLogin = $dt ? $dt->format('dmY') : date('dmY', strtotime($row['tanggal_lahir']));
    } else {
        $passwordLogin = '123456';
    }
    $safePass = htmlspecialchars($passwordLogin, ENT_QUOTES, 'UTF-8');
    $maskPass = str_repeat('•', strlen($passwordLogin));
    
    // Role badge
    $badgeClass = 'badge-primary';
    if ($row['role'] === 'admin') $badgeClass = 'badge-danger';
    if ($row['role'] === 'dosen') $badgeClass = 'badge-success';
    $roleBadge = '<span class="badge ' . $badgeClass . '">' . ucfirst(htmlspecialchars($row['role'])) . '</span>';
    
    // Status Badge Button
    $statusBadge = '';
    if (isset($row['is_active'])) {
        if ($row['is_active'] == 1) {
            $statusBadge = '<form method="POST" style="display:inline;margin-left:4px;" action="users.php">
                <input type="hidden" name="csrf_token" value="'.$csrfToken.'">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="id" value="'.$id.'">
                <button type="submit" class="badge badge-success" style="border:none;cursor:pointer;padding:4px 6px;" title="Klik untuk menonaktifkan" data-confirm="Nonaktifkan akun ini?">✔️ Aktif</button>
            </form>';
        } else {
            $statusBadge = '<form method="POST" style="display:inline;margin-left:4px;" action="users.php">
                <input type="hidden" name="csrf_token" value="'.$csrfToken.'">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="id" value="'.$id.'">
                <button type="submit" class="badge badge-warning" style="border:none;cursor:pointer;padding:4px 6px;" title="Klik untuk mengaktifkan" data-confirm="Aktifkan akun ini sekarang?">⏳ Blm Aktif</button>
            </form>';
        }
    }
    $roleBadge .= $statusBadge;
    
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
        data-angkatan="'.htmlspecialchars($row['angkatan'] ?? '', ENT_QUOTES, 'UTF-8').'"
        data-role="'.htmlspecialchars($row['role'], ENT_QUOTES, 'UTF-8').'"
        style="margin-right:4px;">✏️ Edit</button>';
        
    // Reset Password
    $aksi .= '<form method="POST" style="display:inline;margin-right:4px;" action="users.php">
        <input type="hidden" name="csrf_token" value="'.$csrfToken.'">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="id" value="'.$id.'">
        <button type="submit" class="btn btn-sm btn-secondary"
            data-confirm="Reset password ke tanggal lahir (ddmmyyyy)?">🔑 Reset Pass</button>
    </form>';

    // Password Box (Hide with eye toggle) di sebelah Reset Pass
    $aksi .= '<span class="user-pass-box" style="display:inline-flex; align-items:center; background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:3px 7px; font-family:monospace; font-size:12px; margin-right:4px; vertical-align:middle; gap:4px;" title="Password Login">'
        . '<span class="pass-text" data-pass="' . $safePass . '" style="min-width:60px; text-align:center; font-weight:700; color:#334155; user-select:all; letter-spacing:1px;">' . $maskPass . '</span>'
        . '<button type="button" class="btn-toggle-pass" onclick="toggleUserPass(this)" style="background:none; border:none; cursor:pointer; padding:0; font-size:13px; line-height:1; color:#64748b; display:inline-flex; align-items:center;" title="Tampilkan/Sembunyikan Password">👁️</button>'
        . '</span>';
    
    // Login As & Delete (if not current user)
    if ($id != $_SESSION['user_id']) {
        $aksi .= '<form method="POST" target="_blank" style="display:inline;margin-right:4px;" action="users.php">
            <input type="hidden" name="csrf_token" value="'.$csrfToken.'">
            <input type="hidden" name="action" value="login_as">
            <input type="hidden" name="id" value="'.$id.'">
            <button type="submit" class="btn btn-sm btn-info" style="background-color:#0ea5e9;color:white;"
                data-confirm="Buka tab baru dan login sebagai '.htmlspecialchars($row['nama_lengkap'], ENT_QUOTES).'?">🚪 Login As</button>
        </form>';
        
        $aksi .= '<form method="POST" style="display:inline;" action="users.php">
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
        $noHpHtml,
        $tglLahir,
        $prodi,
        $angkatan,
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
