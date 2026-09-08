<?php
define('PAGE_TITLE', 'Data Jaminan Mahasiswa');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();

// Create table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS jaminan_mahasiswa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nim VARCHAR(50) NOT NULL,
    jaminan_atas ENUM('Validasi', 'Al Khidmah', 'Sertifikat') NOT NULL,
    jenis_jaminan ENUM('KTP', 'SIM', 'STNK', 'BPKB') NOT NULL,
    foto_jaminan VARCHAR(255) NULL,
    status_pengambilan TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_jaminan') {
    $nim = trim($_POST['nim'] ?? '');
    $jaminan_atas = trim($_POST['jaminan_atas'] ?? '');
    $jenis_jaminan = trim($_POST['jenis_jaminan'] ?? '');
    
    $foto_jaminan = null;
    
    // Handle file upload
    if (isset($_FILES['foto_jaminan']) && $_FILES['foto_jaminan']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/jaminan/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileExt = strtolower(pathinfo($_FILES['foto_jaminan']['name'], PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png'];
        
        if (in_array($fileExt, $allowedExt)) {
            $fileName = uniqid('jaminan_') . '.' . $fileExt;
            $destPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['foto_jaminan']['tmp_name'], $destPath)) {
                $foto_jaminan = 'uploads/jaminan/' . $fileName;
            }
        }
    }
    
    if ($nim && $jaminan_atas && $jenis_jaminan) {
        $stmt = $pdo->prepare("INSERT INTO jaminan_mahasiswa (nim, jaminan_atas, jenis_jaminan, foto_jaminan) VALUES (?, ?, ?, ?)");
        $stmt->execute([$nim, $jaminan_atas, $jenis_jaminan, $foto_jaminan]);
    }
    
    header("Location: jaminan.php?success=add");
    exit;
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ambil_jaminan') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("UPDATE jaminan_mahasiswa SET status_pengambilan = 1 WHERE id = ?");
        $stmt->execute([$id]);
    }
    header("Location: jaminan.php?success=ambil");
    exit;
}

// Handle AJAX request for Select2 Mahasiswa
if (isset($_GET['action']) && $_GET['action'] === 'search_mahasiswa') {
    $search = trim($_GET['q'] ?? '');
    $page = (int)($_GET['page'] ?? 1);
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = "role = 'mahasiswa'";
    $params = [];
    if ($search !== '') {
        $where .= " AND (nim LIKE ? OR nama_lengkap LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $stmt = $pdo->prepare("SELECT nim, nama_lengkap FROM users WHERE $where ORDER BY nama_lengkap ASC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where");
    $stmtCount->execute($params);
    $totalCount = $stmtCount->fetchColumn();

    $items = [];
    foreach ($results as $row) {
        $items[] = [
            'id' => $row['nim'],
            'text' => $row['nim'] . ' - ' . $row['nama_lengkap']
        ];
    }

    header('Content-Type: application/json');
    echo json_encode([
        'results' => $items,
        'pagination' => [
            'more' => ($offset + $limit) < $totalCount
        ]
    ]);
    exit;
}

// Fetch Jaminan data
$stmtData = $pdo->query("
    SELECT j.*, u.nama_lengkap 
    FROM jaminan_mahasiswa j 
    JOIN users u ON j.nim = u.nim 
    ORDER BY j.status_pengambilan ASC, j.created_at DESC
");
$jaminanData = $stmtData->fetchAll();

define('EXTRA_HEAD', '
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    .badge-status { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
    .status-belum { background-color: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
    .status-sudah { background-color: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
</style>
');

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Data Jaminan Mahasiswa</h2>
</div>

<?php if(isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert" style="background:#d1fae5; color:#065f46; padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid #34d399;">
        <?php if($_GET['success'] === 'add') echo "Data jaminan berhasil ditambahkan."; ?>
        <?php if($_GET['success'] === 'ambil') echo "Status jaminan berhasil diubah menjadi sudah diambil."; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="float:right; background:transparent; border:none; color:#065f46; font-weight:bold; cursor:pointer;" onclick="this.parentElement.style.display='none';">&times;</button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Form Tambah Jaminan -->
    <div class="col-md-4 mb-4">
        <div class="card shadow-sm border-0" style="border-radius:12px;">
            <div class="card-header bg-primary text-white" style="border-top-left-radius:12px; border-top-right-radius:12px; padding:15px 20px;">
                <h5 class="mb-0" style="margin:0; font-size:16px;">➕ Tambah Jaminan Baru</h5>
            </div>
            <div class="card-body" style="padding:20px;">
                <form action="jaminan.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_jaminan">
                    
                    <div class="mb-3" style="margin-bottom:15px;">
                        <label class="form-label" style="font-weight:600; font-size:14px; color:#334155; display:block; margin-bottom:8px;">Mahasiswa</label>
                        <select name="nim" id="selectNim" class="form-control" required style="width: 100%;">
                        </select>
                    </div>

                    <div class="mb-3" style="margin-bottom:15px;">
                        <label class="form-label" style="font-weight:600; font-size:14px; color:#334155; display:block; margin-bottom:8px;">Jaminan Atas Keperluan</label>
                        <select name="jaminan_atas" class="form-control" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px;">
                            <option value="">-- Pilih Keperluan --</option>
                            <option value="Validasi">Validasi</option>
                            <option value="Al Khidmah">Al Khidmah</option>
                            <option value="Sertifikat">Sertifikat</option>
                        </select>
                    </div>

                    <div class="mb-3" style="margin-bottom:15px;">
                        <label class="form-label" style="font-weight:600; font-size:14px; color:#334155; display:block; margin-bottom:8px;">Jenis Jaminan</label>
                        <select name="jenis_jaminan" class="form-control" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px;">
                            <option value="">-- Pilih Jenis --</option>
                            <option value="KTP">KTP</option>
                            <option value="SIM">SIM</option>
                            <option value="STNK">STNK</option>
                            <option value="BPKB">BPKB</option>
                        </select>
                    </div>

                    <div class="mb-4" style="margin-bottom:20px;">
                        <label class="form-label" style="font-weight:600; font-size:14px; color:#334155; display:block; margin-bottom:8px;">Upload Foto Bukti (Opsional)</label>
                        <input type="file" name="foto_jaminan" class="form-control" accept="image/jpeg,image/png" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; background:#f8fafc;">
                    </div>

                    <button type="submit" class="btn btn-primary w-100" style="width:100%; padding:10px; background:#3b82f6; border:none; border-radius:6px; color:white; font-weight:600;">Simpan Data</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Data Table Jaminan -->
    <div class="col-md-8 mb-4">
        <div class="card shadow-sm border-0" style="border-radius:12px;">
            <div class="card-header bg-white" style="border-bottom:1px solid #e2e8f0; padding:15px 20px;">
                <h5 class="mb-0" style="margin:0; font-size:16px; color:#1e293b;">Daftar Jaminan Mahasiswa</h5>
            </div>
            <div class="card-body" style="padding:20px; overflow-x:auto;">
                <table id="tableJaminan" class="table table-hover table-striped" style="width:100%; font-size:14px;">
                    <thead>
                        <tr>
                            <th style="border-bottom:2px solid #e2e8f0; padding:10px;">Mahasiswa</th>
                            <th style="border-bottom:2px solid #e2e8f0; padding:10px;">Keperluan</th>
                            <th style="border-bottom:2px solid #e2e8f0; padding:10px;">Jaminan</th>
                            <th style="border-bottom:2px solid #e2e8f0; padding:10px;">Foto</th>
                            <th style="border-bottom:2px solid #e2e8f0; padding:10px;">Status</th>
                            <th style="border-bottom:2px solid #e2e8f0; padding:10px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($jaminanData)): ?>
                            <?php foreach($jaminanData as $row): ?>
                            <tr>
                                <td style="padding:12px 10px; vertical-align:middle;">
                                    <strong><?= htmlspecialchars($row['nama_lengkap']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($row['nim']) ?></small>
                                </td>
                                <td style="padding:12px 10px; vertical-align:middle;">
                                    <?= htmlspecialchars($row['jaminan_atas']) ?>
                                </td>
                                <td style="padding:12px 10px; vertical-align:middle;">
                                    <span style="background:#e2e8f0; color:#334155; padding:3px 8px; border-radius:4px; font-weight:bold; font-size:12px;"><?= htmlspecialchars($row['jenis_jaminan']) ?></span>
                                </td>
                                <td style="padding:12px 10px; vertical-align:middle;">
                                    <?php if($row['foto_jaminan']): ?>
                                        <a href="<?= BASE_URL ?>/<?= htmlspecialchars($row['foto_jaminan']) ?>" target="_blank" style="color:#3b82f6; text-decoration:none;">Lihat Foto</a>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:12px 10px; vertical-align:middle;">
                                    <?php if($row['status_pengambilan'] == 1): ?>
                                        <span class="badge-status status-sudah">Sudah Diambil</span>
                                    <?php else: ?>
                                        <span class="badge-status status-belum">Belum Diambil</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:12px 10px; vertical-align:middle;">
                                    <?php if($row['status_pengambilan'] == 0): ?>
                                        <form method="POST" action="jaminan.php" style="margin:0;" onsubmit="return confirm('Apakah Anda yakin KTP/Identitas ini sudah diambil oleh mahasiswa?');">
                                            <input type="hidden" name="action" value="ambil_jaminan">
                                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-success" style="background:#10b981; border:none; padding:5px 10px; border-radius:4px; color:white; font-size:12px; cursor:pointer;">✅ Set Sudah Diambil</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:12px;"><i>Selesai</i></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        $('#selectNim').select2({
            placeholder: "-- Ketik Nama / NIM --",
            allowClear: true,
            width: '100%',
            ajax: {
                url: 'jaminan.php',
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        action: 'search_mahasiswa',
                        q: params.term, // search term
                        page: params.page
                    };
                },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    return {
                        results: data.results,
                        pagination: {
                            more: data.pagination.more
                        }
                    };
                },
                cache: true
            },
            minimumInputLength: 1
        });

    });
</script>
