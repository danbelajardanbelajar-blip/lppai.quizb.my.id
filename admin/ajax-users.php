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
require_once __DIR__ . '/../includes/user-table-avatar.php';
ensureJenisProgramColumn($pdo);

// Parameter dari DataTables
$draw = $_POST['draw'] ?? $_GET['draw'] ?? 1;
$start = (int)($_POST['start'] ?? $_GET['start'] ?? 0);
$length = (int)($_POST['length'] ?? $_GET['length'] ?? 10);
$searchValue = trim($_POST['search']['value'] ?? $_GET['search']['value'] ?? '');

// Filter custom (dari DataTables search pada spesifik kolom atau custom input)
$filterAngkatan = $_POST['filterAngkatan'] ?? '';
$filterProdi = $_POST['filterProdi'] ?? '';
$filterJenisProgram = $_POST['filterJenisProgram'] ?? '';
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
    7 => 'u.jenis_program',
    8 => 'u.angkatan', // Angkatan
    9 => 'u.role'
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

if ($filterJenisProgram !== '') {
    $fromClause .= " AND u.jenis_program = ?";
    $whereParams[] = normalizeJenisProgram($filterJenisProgram);
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
        OR u.jenis_program LIKE ? 
        OR u.angkatan LIKE ?
    )";
    $searchWildcard = '%' . $searchValue . '%';
    $whereParams = array_merge($whereParams, [
        $searchWildcard, 
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
    $namaDenganIcon = renderUserTableName($row, true);
    $tglLahir = !empty($row['tanggal_lahir']) ? date('d/m/Y', strtotime($row['tanggal_lahir'])) : '-';
    $prodi = htmlspecialchars(!empty($row['program_studi']) ? $row['program_studi'] : '-');
    $jenisProgramVal = normalizeJenisProgram($row['jenis_program'] ?? 'Reguler');
    $jenisProgramHtml = renderJenisProgramBadge($jenisProgramVal, true);
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
    // Password tersimpan sebagai hash → yang bisa ditampilkan hanya password BAWAAN (tgl lahir / 123456).
    // Cocokkan dengan hash agar admin tahu bila pengguna sudah menggantinya (dibatasi ≤ 25 baris/halaman
    // karena password_verify berbiaya ±100 ms per pengguna).
    $passStatus = 'unknown';
    if ($length > 0 && $length <= 25 && !empty($row['password'])) {
        $passStatus = password_verify($passwordLogin, (string)$row['password']) ? 'default' : 'changed';
    }
    
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
        data-jenis-program="'.htmlspecialchars($jenisProgramVal, ENT_QUOTES, 'UTF-8').'"
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

    // Password Box: tertutup (•••), 👁️ tampilkan/sembunyikan, 📋 salin, klik teks = salin otomatis
    if ($passStatus === 'changed') {
        $aksi .= '<span class="user-pass-box is-changed" title="Pengguna sudah mengganti password. Password baru tidak dapat dilihat (tersimpan terenkripsi); gunakan Reset Pass bila perlu."'
            . ' style="display:inline-flex; align-items:center; background:#fff7ed; border:1px solid #fdba74; border-radius:6px; padding:3px 8px; font-size:11.5px; font-weight:700; color:#9a3412; margin-right:4px; vertical-align:middle;">🔒 Sudah diganti</span>';
    } else {
        $passHint = $passStatus === 'default' ? 'Password bawaan masih berlaku' : 'Password bawaan (tgl lahir ddmmyyyy / 123456) — belum dicek apakah sudah diganti';
        $aksi .= '<span class="user-pass-box" data-status="' . $passStatus . '" style="display:inline-flex; align-items:center; background:#f8fafc; border:1px solid ' . ($passStatus === 'default' ? '#86efac' : '#cbd5e1') . '; border-radius:6px; padding:3px 7px; font-family:monospace; font-size:12px; margin-right:4px; vertical-align:middle; gap:5px;" title="' . $passHint . '">'
            . '<span class="pass-text" data-pass="' . $safePass . '" role="button" tabindex="0" onclick="copyUserPass(this)" onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();copyUserPass(this);}" style="min-width:60px; text-align:center; font-weight:700; color:#334155; letter-spacing:1px; cursor:copy;" title="Klik untuk menyalin password">' . $maskPass . '</span>'
            . '<button type="button" class="btn-toggle-pass" onclick="toggleUserPass(this)" aria-label="Tampilkan password" style="background:none; border:none; cursor:pointer; padding:0; font-size:13px; line-height:1; color:#64748b; display:inline-flex; align-items:center;" title="Tampilkan/Sembunyikan Password">👁️</button>'
            . '<button type="button" class="btn-copy-pass" onclick="copyUserPass(this)" aria-label="Salin password" style="background:#e2e8f0; border:none; border-radius:4px; cursor:pointer; padding:1px 6px; font-size:11px; line-height:1.4; color:#1e293b; font-family:inherit; display:inline-flex; align-items:center; gap:3px;" title="Salin password ke clipboard">📋 Salin</button>'
            . '</span>';
    }
    
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
        $namaDenganIcon,
        $noHpHtml,
        $tglLahir,
        $prodi,
        $jenisProgramHtml,
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
