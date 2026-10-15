<?php
/**
 * LPPAI Corner - Inventaris & Manajemen Arsip Surat
 */
define('PAGE_TITLE', 'Inventaris & Arsip Surat');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
$currentUser = getCurrentUser();

// 1. Pastikan tabel surat_inventaris tersedia
try {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS surat_inventaris (
        id INT AUTO_INCREMENT PRIMARY KEY,
        jenis_surat ENUM('masuk', 'keluar') NOT NULL DEFAULT 'masuk',
        nomor_surat VARCHAR(100) NOT NULL,
        nomor_agenda VARCHAR(50) NULL,
        tanggal_surat DATE NOT NULL,
        tanggal_terima_kirim DATE NOT NULL,
        pengirim VARCHAR(150) NULL,
        penerima VARCHAR(150) NULL,
        perihal VARCHAR(255) NOT NULL,
        kategori VARCHAR(80) NOT NULL DEFAULT 'Umum',
        sifat VARCHAR(30) NOT NULL DEFAULT 'Biasa',
        lokasi_arsip VARCHAR(100) NULL,
        disposisi_kepada VARCHAR(150) NULL,
        disposisi_instruksi TEXT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'Arsip',
        keterangan TEXT NULL,
        file_surat VARCHAR(255) NULL,
        file_nama_asli VARCHAR(255) NULL,
        file_ukuran INT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_jenis_surat (jenis_surat),
        INDEX idx_tanggal_surat (tanggal_surat),
        INDEX idx_nomor_surat (nomor_surat),
        INDEX idx_kategori (kategori)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {
    // Ignore if table exists or permission already granted
}

// 2. Direktori upload berkas
$uploadDir = __DIR__ . '/../uploads/surat/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// 3. Helper upload berkas
function handleFileUpload($file, $uploadDir, &$fileOriginalName, &$fileSize) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'zip'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExts)) {
        throw new Exception("Format berkas tidak diizinkan. Hanya file PDF, JPG, PNG, DOC, DOCX, atau ZIP.");
    }

    // Maksimal 15 MB
    if ($file['size'] > 15 * 1024 * 1024) {
        throw new Exception("Ukuran berkas melebihi batas maksimal 15 MB.");
    }

    $fileOriginalName = $file['name'];
    $fileSize = (int)$file['size'];

    $safeName = 'surat_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception("Gagal memindahkan berkas unggahan.");
    }

    return 'uploads/surat/' . $safeName;
}

$message = '';
$msgType = '';

// 4. Handle POST actions (Create, Update, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid atau telah kedaluwarsa. Silakan coba kembali.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        // ── CREATE ──
        if ($action === 'create') {
            try {
                $jenis_surat          = in_array($_POST['jenis_surat'] ?? '', ['masuk', 'keluar']) ? $_POST['jenis_surat'] : 'masuk';
                $nomor_surat          = trim($_POST['nomor_surat'] ?? '');
                $nomor_agenda         = trim($_POST['nomor_agenda'] ?? '');
                $tanggal_surat        = trim($_POST['tanggal_surat'] ?? date('Y-m-d'));
                $tanggal_terima_kirim = trim($_POST['tanggal_terima_kirim'] ?? date('Y-m-d'));
                $pengirim             = trim($_POST['pengirim'] ?? '');
                $penerima             = trim($_POST['penerima'] ?? '');
                $perihal              = trim($_POST['perihal'] ?? '');
                $kategori             = trim($_POST['kategori'] ?? 'Umum');
                $sifat                = trim($_POST['sifat'] ?? 'Biasa');
                $lokasi_arsip         = trim($_POST['lokasi_arsip'] ?? '');
                $disposisi_kepada     = trim($_POST['disposisi_kepada'] ?? '');
                $disposisi_instruksi  = trim($_POST['disposisi_instruksi'] ?? '');
                $status               = trim($_POST['status'] ?? 'Arsip');
                $keterangan           = trim($_POST['keterangan'] ?? '');

                if (empty($nomor_surat) || empty($perihal) || empty($tanggal_surat)) {
                    throw new Exception("Nomor Surat, Perihal, dan Tanggal Surat wajib diisi.");
                }

                $fileSurat = null;
                $fileOriginal = null;
                $fileSize = null;

                if (isset($_FILES['file_surat']) && $_FILES['file_surat']['error'] === UPLOAD_ERR_OK) {
                    $fileSurat = handleFileUpload($_FILES['file_surat'], $uploadDir, $fileOriginal, $fileSize);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO surat_inventaris 
                    (jenis_surat, nomor_surat, nomor_agenda, tanggal_surat, tanggal_terima_kirim, 
                     pengirim, penerima, perihal, kategori, sifat, lokasi_arsip, 
                     disposisi_kepada, disposisi_instruksi, status, keterangan, 
                     file_surat, file_nama_asli, file_ukuran, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $jenis_surat, $nomor_surat, $nomor_agenda ?: null, $tanggal_surat, $tanggal_terima_kirim,
                    $pengirim ?: null, $penerima ?: null, $perihal, $kategori, $sifat, $lokasi_arsip ?: null,
                    $disposisi_kepada ?: null, $disposisi_instruksi ?: null, $status, $keterangan ?: null,
                    $fileSurat, $fileOriginal, $fileSize, $currentUser['id'] ?? null
                ]);

                header("Location: inventaris-surat.php?msg=created");
                exit;
            } catch (Exception $e) {
                $message = $e->getMessage();
                $msgType = 'danger';
            }

        // ── UPDATE ──
        } elseif ($action === 'update') {
            try {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception("ID surat tidak valid.");
                }

                $jenis_surat          = in_array($_POST['jenis_surat'] ?? '', ['masuk', 'keluar']) ? $_POST['jenis_surat'] : 'masuk';
                $nomor_surat          = trim($_POST['nomor_surat'] ?? '');
                $nomor_agenda         = trim($_POST['nomor_agenda'] ?? '');
                $tanggal_surat        = trim($_POST['tanggal_surat'] ?? date('Y-m-d'));
                $tanggal_terima_kirim = trim($_POST['tanggal_terima_kirim'] ?? date('Y-m-d'));
                $pengirim             = trim($_POST['pengirim'] ?? '');
                $penerima             = trim($_POST['penerima'] ?? '');
                $perihal              = trim($_POST['perihal'] ?? '');
                $kategori             = trim($_POST['kategori'] ?? 'Umum');
                $sifat                = trim($_POST['sifat'] ?? 'Biasa');
                $lokasi_arsip         = trim($_POST['lokasi_arsip'] ?? '');
                $disposisi_kepada     = trim($_POST['disposisi_kepada'] ?? '');
                $disposisi_instruksi  = trim($_POST['disposisi_instruksi'] ?? '');
                $status               = trim($_POST['status'] ?? 'Arsip');
                $keterangan           = trim($_POST['keterangan'] ?? '');

                if (empty($nomor_surat) || empty($perihal) || empty($tanggal_surat)) {
                    throw new Exception("Nomor Surat, Perihal, dan Tanggal Surat wajib diisi.");
                }

                // Ambil data file eksisting
                $stmtExisting = $pdo->prepare("SELECT file_surat, file_nama_asli, file_ukuran FROM surat_inventaris WHERE id = ?");
                $stmtExisting->execute([$id]);
                $existing = $stmtExisting->fetch();
                if (!$existing) {
                    throw new Exception("Data surat tidak ditemukan.");
                }

                $fileSurat = $existing['file_surat'];
                $fileOriginal = $existing['file_nama_asli'];
                $fileSize = $existing['file_ukuran'];

                // Cek hapus file
                if (!empty($_POST['hapus_file_lampiran']) && $_POST['hapus_file_lampiran'] == '1') {
                    if ($fileSurat && file_exists(__DIR__ . '/../' . $fileSurat)) {
                        @unlink(__DIR__ . '/../' . $fileSurat);
                    }
                    $fileSurat = null;
                    $fileOriginal = null;
                    $fileSize = null;
                }

                // Cek upload file baru
                if (isset($_FILES['file_surat']) && $_FILES['file_surat']['error'] === UPLOAD_ERR_OK) {
                    // Hapus file lama jika ada
                    if ($fileSurat && file_exists(__DIR__ . '/../' . $fileSurat)) {
                        @unlink(__DIR__ . '/../' . $fileSurat);
                    }
                    $fileSurat = handleFileUpload($_FILES['file_surat'], $uploadDir, $fileOriginal, $fileSize);
                }

                $stmtUpdate = $pdo->prepare("
                    UPDATE surat_inventaris SET 
                        jenis_surat = ?, nomor_surat = ?, nomor_agenda = ?, 
                        tanggal_surat = ?, tanggal_terima_kirim = ?, 
                        pengirim = ?, penerima = ?, perihal = ?, 
                        kategori = ?, sifat = ?, lokasi_arsip = ?, 
                        disposisi_kepada = ?, disposisi_instruksi = ?, 
                        status = ?, keterangan = ?, 
                        file_surat = ?, file_nama_asli = ?, file_ukuran = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([
                    $jenis_surat, $nomor_surat, $nomor_agenda ?: null, 
                    $tanggal_surat, $tanggal_terima_kirim, 
                    $pengirim ?: null, $penerima ?: null, $perihal, 
                    $kategori, $sifat, $lokasi_arsip ?: null, 
                    $disposisi_kepada ?: null, $disposisi_instruksi ?: null, 
                    $status, $keterangan ?: null, 
                    $fileSurat, $fileOriginal, $fileSize, 
                    $id
                ]);

                header("Location: inventaris-surat.php?msg=updated");
                exit;
            } catch (Exception $e) {
                $message = $e->getMessage();
                $msgType = 'danger';
            }

        // ── DELETE ──
        } elseif ($action === 'delete') {
            try {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception("ID surat tidak valid.");
                }

                $stmtFile = $pdo->prepare("SELECT file_surat FROM surat_inventaris WHERE id = ?");
                $stmtFile->execute([$id]);
                $filePath = $stmtFile->fetchColumn();

                if ($filePath && file_exists(__DIR__ . '/../' . $filePath)) {
                    @unlink(__DIR__ . '/../' . $filePath);
                }

                $pdo->prepare("DELETE FROM surat_inventaris WHERE id = ?")->execute([$id]);

                header("Location: inventaris-surat.php?msg=deleted");
                exit;
            } catch (Exception $e) {
                $message = $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

// 5. Pesan Flash dari Query Parameter
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'created') {
        $message = 'Surat berhasil dicatat ke dalam inventaris.';
        $msgType = 'success';
    } elseif ($_GET['msg'] === 'updated') {
        $message = 'Data inventaris surat berhasil diperbarui.';
        $msgType = 'success';
    } elseif ($_GET['msg'] === 'deleted') {
        $message = 'Data surat dan lampiran berkas berhasil dihapus.';
        $msgType = 'success';
    }
}

// 6. Pengaturan Filter & Pencarian
$filterJenis    = trim($_GET['jenis'] ?? '');
$filterKategori = trim($_GET['kategori'] ?? '');
$filterSifat    = trim($_GET['sifat'] ?? '');
$filterStatus   = trim($_GET['status'] ?? '');
$filterTglAwal  = trim($_GET['tgl_awal'] ?? '');
$filterTglAkhir = trim($_GET['tgl_akhir'] ?? '');
$searchQuery    = trim($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];

if ($filterJenis === 'masuk' || $filterJenis === 'keluar') {
    $where[] = "jenis_surat = ?";
    $params[] = $filterJenis;
}

if ($filterKategori !== '') {
    $where[] = "kategori = ?";
    $params[] = $filterKategori;
}

if ($filterSifat !== '') {
    $where[] = "sifat = ?";
    $params[] = $filterSifat;
}

if ($filterStatus !== '') {
    $where[] = "status = ?";
    $params[] = $filterStatus;
}

if ($filterTglAwal !== '') {
    $where[] = "tanggal_surat >= ?";
    $params[] = $filterTglAwal;
}

if ($filterTglAkhir !== '') {
    $where[] = "tanggal_surat <= ?";
    $params[] = $filterTglAkhir;
}

if ($searchQuery !== '') {
    $where[] = "(nomor_surat LIKE ? OR perihal LIKE ? OR pengirim LIKE ? OR penerima LIKE ? OR lokasi_arsip LIKE ? OR nomor_agenda LIKE ?)";
    $like = "%$searchQuery%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
}

$whereClause = implode(" AND ", $where);

// 7. Ekspor CSV jika diminta
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $stmtExport = $pdo->prepare("SELECT * FROM surat_inventaris WHERE $whereClause ORDER BY tanggal_surat DESC, id DESC");
    $stmtExport->execute($params);
    $rows = $stmtExport->fetchAll();

    $filename = "Inventaris_Surat_LPPAI_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM untuk Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, [
        'ID', 'Jenis Surat', 'No. Agenda', 'Nomor Surat', 'Tanggal Surat', 
        'Tanggal Diterima/Dikirim', 'Pengirim', 'Penerima', 'Perihal', 
        'Kategori', 'Sifat', 'Status', 'Lokasi Arsip Fisik', 
        'Disposisi Kepada', 'Instruksi Disposisi', 'Keterangan', 'Nama File Lampiran', 'Dibuat Pada'
    ]);

    foreach ($rows as $r) {
        fputcsv($output, [
            $r['id'],
            $r['jenis_surat'] === 'masuk' ? 'Surat Masuk' : 'Surat Keluar',
            $r['nomor_agenda'] ?? '',
            $r['nomor_surat'],
            $r['tanggal_surat'],
            $r['tanggal_terima_kirim'],
            $r['pengirim'] ?? '',
            $r['penerima'] ?? '',
            $r['perihal'],
            $r['kategori'],
            $r['sifat'],
            $r['status'],
            $r['lokasi_arsip'] ?? '',
            $r['disposisi_kepada'] ?? '',
            $r['disposisi_instruksi'] ?? '',
            $r['keterangan'] ?? '',
            $r['file_nama_asli'] ?? ($r['file_surat'] ? 'Ada Berkas' : 'Tidak Ada'),
            $r['created_at']
        ]);
    }
    fclose($output);
    exit;
}

// 8. Statistik Data Surat
$statTotal   = (int)$pdo->query("SELECT COUNT(*) FROM surat_inventaris")->fetchColumn();
$statMasuk   = (int)$pdo->query("SELECT COUNT(*) FROM surat_inventaris WHERE jenis_surat = 'masuk'")->fetchColumn();
$statKeluar  = (int)$pdo->query("SELECT COUNT(*) FROM surat_inventaris WHERE jenis_surat = 'keluar'")->fetchColumn();
$statBulanIni = (int)$pdo->query("SELECT COUNT(*) FROM surat_inventaris WHERE DATE_FORMAT(tanggal_surat, '%Y-%m') = '" . date('Y-m') . "'")->fetchColumn();
$statDisposisi = (int)$pdo->query("SELECT COUNT(*) FROM surat_inventaris WHERE status IN ('Disposisi', 'Proses')")->fetchColumn();

// 9. Query Ambil Data Terfilter
$stmtData = $pdo->prepare("SELECT * FROM surat_inventaris WHERE $whereClause ORDER BY tanggal_surat DESC, id DESC");
$stmtData->execute($params);
$suratList = $stmtData->fetchAll();

// 10. Daftar Kategori & Sifat Standar
$kategoriList = [
    'Undangan', 'Permohonan', 'Pemberitahuan', 'Surat Keputusan (SK)', 
    'Surat Tugas', 'Rekomendasi', 'Kerjasama / MoU', 'Surat Keterangan', 
    'Berita Acara', 'Pengantar', 'Nota Dinas', 'Umum', 'Lainnya'
];

$sifatList = ['Biasa', 'Penting', 'Segera', 'Rahasia'];
$statusList = ['Arsip', 'Disposisi', 'Proses', 'Selesai'];

include __DIR__ . '/../includes/header.php';
?>
<style>
    .surat-stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .surat-stat-card {
        background: #fff;
        border-radius: 14px;
        padding: 18px 20px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.06);
        display: flex;
        align-items: center;
        gap: 16px;
        border: 1px solid rgba(0,0,0,0.04);
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .surat-stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
    }
    .surat-stat-icon {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        flex-shrink: 0;
    }
    .surat-stat-icon.blue   { background: #eff6ff; color: #1d4ed8; }
    .surat-stat-icon.green  { background: #f0fdf4; color: #15803d; }
    .surat-stat-icon.orange { background: #fff7ed; color: #c2410c; }
    .surat-stat-icon.purple { background: #faf5ff; color: #7e22ce; }
    .surat-stat-icon.red    { background: #fef2f2; color: #b91c1c; }

    .nav-tabs-surat {
        display: flex;
        gap: 8px;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 20px;
        padding-bottom: 0;
    }
    .nav-tab-item {
        padding: 10px 18px;
        font-weight: 600;
        font-size: 14px;
        color: #64748b;
        text-decoration: none;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .nav-tab-item:hover {
        color: #1a5632;
    }
    .nav-tab-item.active {
        color: #1a5632;
        border-bottom-color: #1a5632;
        background: #f0fdf4;
        border-top-left-radius: 8px;
        border-top-right-radius: 8px;
    }

    .badge-jenis-masuk {
        background: #dcfce7;
        color: #15803d;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 11.5px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .badge-jenis-keluar {
        background: #ffedd5;
        color: #c2410c;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 11.5px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .badge-sifat-rahasia { background: #fee2e2; color: #b91c1c; font-weight: 600; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
    .badge-sifat-penting { background: #fef3c7; color: #92400e; font-weight: 600; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
    .badge-sifat-segera  { background: #f3e8ff; color: #7e22ce; font-weight: 600; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
    .badge-sifat-biasa   { background: #f1f5f9; color: #475569; font-weight: 600; padding: 2px 8px; border-radius: 4px; font-size: 11px; }

    .badge-status-arsip     { background: #f1f5f9; color: #475569; }
    .badge-status-disposisi { background: #dbeafe; color: #1e40af; }
    .badge-status-proses    { background: #fef3c7; color: #92400e; }
    .badge-status-selesai   { background: #d1fae5; color: #065f46; }

    .filter-panel {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 18px;
        margin-bottom: 22px;
    }
    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 12px;
        align-items: end;
    }

    /* Modal Styling */
    .modal-surat-content {
        max-width: 820px;
        max-height: 90vh;
        overflow-y: auto;
    }
    .form-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    @media (max-width: 768px) {
        .form-grid-2 { grid-template-columns: 1fr; }
        .surat-stat-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 480px) {
        .surat-stat-grid { grid-template-columns: 1fr; }
    }

    .detail-table th { width: 32%; background: #f8fafc; font-weight: 600; color: #475569; padding: 8px 12px; border-bottom: 1px solid #e2e8f0; }
    .detail-table td { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; }
</style>
?>

<!-- Header Halaman & Aksi Cepat -->
<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; margin-bottom:20px;">
    <div>
        <h2 style="font-size:22px; font-weight:800; color:#1a5632; margin-bottom:4px;">📑 Inventaris & Arsip Surat</h2>
        <p style="font-size:13.5px; color:#64748b; margin:0;">Pencatatan, pengarsipan fisik & digital, disposisi, serta pelacakan surat masuk dan surat keluar LPPAI.</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button type="button" class="btn btn-primary" onclick="openTambahModal()" style="width:auto;">
            ➕ Catat Surat Baru
        </button>
        <a href="cetak-inventaris-surat.php?<?= http_build_query($_GET) ?>" target="_blank" class="btn btn-secondary" style="width:auto;">
            🖨️ Cetak Agenda
        </a>
        <a href="inventaris-surat.php?action=export_csv&<?= http_build_query($_GET) ?>" class="btn btn-secondary" style="width:auto;">
            📥 Ekspor CSV
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="display:flex; justify-content:space-between; align-items:center;">
        <div><?= sanitize($message) ?></div>
        <button type="button" onclick="this.parentElement.style.display='none'" style="background:none;border:none;font-size:18px;cursor:pointer;">&times;</button>
    </div>
<?php endif; ?>

<!-- ── Statistik Ringkas ────────────────────────────────────── -->
<div class="surat-stat-grid">
    <div class="surat-stat-card">
        <div class="surat-stat-icon blue">📑</div>
        <div>
            <div style="font-size:24px; font-weight:800; color:#1e293b;"><?= number_format($statTotal) ?></div>
            <div style="font-size:12.5px; color:#64748b; font-weight:500;">Total Seluruh Surat</div>
        </div>
    </div>
    <div class="surat-stat-card">
        <div class="surat-stat-icon green">📥</div>
        <div>
            <div style="font-size:24px; font-weight:800; color:#15803d;"><?= number_format($statMasuk) ?></div>
            <div style="font-size:12.5px; color:#64748b; font-weight:500;">Surat Masuk</div>
        </div>
    </div>
    <div class="surat-stat-card">
        <div class="surat-stat-icon orange">📤</div>
        <div>
            <div style="font-size:24px; font-weight:800; color:#c2410c;"><?= number_format($statKeluar) ?></div>
            <div style="font-size:12.5px; color:#64748b; font-weight:500;">Surat Keluar</div>
        </div>
    </div>
    <div class="surat-stat-card">
        <div class="surat-stat-icon purple">📅</div>
        <div>
            <div style="font-size:24px; font-weight:800; color:#7e22ce;"><?= number_format($statBulanIni) ?></div>
            <div style="font-size:12.5px; color:#64748b; font-weight:500;">Surat Bulan Ini</div>
        </div>
    </div>
    <div class="surat-stat-card">
        <div class="surat-stat-icon red">⏳</div>
        <div>
            <div style="font-size:24px; font-weight:800; color:#b91c1c;"><?= number_format($statDisposisi) ?></div>
            <div style="font-size:12.5px; color:#64748b; font-weight:500;">Perlu Tindak Lanjut</div>
        </div>
    </div>
</div>

<!-- ── Tab Jenis Surat ──────────────────────────────────────── -->
<div class="nav-tabs-surat">
    <a href="inventaris-surat.php" class="nav-tab-item <?= ($filterJenis === '') ? 'active' : '' ?>">
        <span>📑</span> Semua Surat (<?= $statTotal ?>)
    </a>
    <a href="inventaris-surat.php?jenis=masuk" class="nav-tab-item <?= ($filterJenis === 'masuk') ? 'active' : '' ?>">
        <span>📥</span> Surat Masuk (<?= $statMasuk ?>)
    </a>
    <a href="inventaris-surat.php?jenis=keluar" class="nav-tab-item <?= ($filterJenis === 'keluar') ? 'active' : '' ?>">
        <span>📤</span> Surat Keluar (<?= $statKeluar ?>)
    </a>
</div>

<!-- ── Panel Filter & Pencarian ─────────────────────────────── -->
<div class="filter-panel">
    <form method="GET" action="inventaris-surat.php">
        <?php if ($filterJenis): ?>
            <input type="hidden" name="jenis" value="<?= htmlspecialchars($filterJenis) ?>">
        <?php endif; ?>

        <div class="filter-grid">
            <div>
                <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:5px;">Cari Surat</label>
                <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" 
                       placeholder="No. Surat, Perihal, Asal/Tujuan..." 
                       style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none;">
            </div>

            <div>
                <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:5px;">Kategori</label>
                <select name="kategori" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; background:#fff;">
                    <option value="">-- Semua Kategori --</option>
                    <?php foreach ($kategoriList as $kat): ?>
                        <option value="<?= $kat ?>" <?= ($filterKategori === $kat) ? 'selected' : '' ?>><?= $kat ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:5px;">Sifat Surat</label>
                <select name="sifat" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; background:#fff;">
                    <option value="">-- Semua Sifat --</option>
                    <?php foreach ($sifatList as $sft): ?>
                        <option value="<?= $sft ?>" <?= ($filterSifat === $sft) ? 'selected' : '' ?>><?= $sft ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:5px;">Status</label>
                <select name="status" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; background:#fff;">
                    <option value="">-- Semua Status --</option>
                    <?php foreach ($statusList as $st): ?>
                        <option value="<?= $st ?>" <?= ($filterStatus === $st) ? 'selected' : '' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:5px;">Dari Tanggal</label>
                <input type="date" name="tgl_awal" value="<?= htmlspecialchars($filterTglAwal) ?>"
                       style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; background:#fff;">
            </div>

            <div>
                <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:5px;">Sampai Tanggal</label>
                <input type="date" name="tgl_akhir" value="<?= htmlspecialchars($filterTglAkhir) ?>"
                       style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; background:#fff;">
            </div>

            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary" style="padding:8px 14px; font-size:13px; flex:1;">
                    🔍 Filter
                </button>
                <a href="inventaris-surat.php<?= $filterJenis ? '?jenis=' . urlencode($filterJenis) : '' ?>" 
                   class="btn btn-secondary" style="padding:8px 12px; font-size:13px;" title="Reset Filter">
                    🔄
                </a>
            </div>
        </div>
    </form>
</div>

<!-- ── Tabel Data Inventaris Surat ──────────────────────────── -->
<div class="card">
    <div class="card-header" style="background:#fff; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
        <span style="font-weight:700; color:#1e293b;">
            Daftar Inventaris Surat (<?= count($suratList) ?> data)
        </span>
        <span style="font-size:12px; color:#64748b;">
            💡 Klik tombol <strong>👁️ Detail</strong> untuk melihat lembar disposisi dan pratinjau berkas.
        </span>
    </div>
    <div class="card-body" style="padding:16px;">
        <div class="table-responsive">
            <table class="table table-hover" id="tableSurat" style="width:100%; font-size:13.5px;">
                <thead>
                    <tr>
                        <th style="width:40px;">No</th>
                        <th>Jenis</th>
                        <th>Nomor & Tanggal</th>
                        <th>Asal / Tujuan</th>
                        <th>Perihal & Kategori</th>
                        <th>Sifat & Status</th>
                        <th>Lokasi Berkas</th>
                        <th>Lampiran</th>
                        <th style="width:130px; text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($suratList)): ?>
                        <?php $no = 1; foreach ($suratList as $s): ?>
                        <tr>
                            <td style="text-align:center; color:#64748b;"><?= $no++ ?></td>
                            <td>
                                <?php if ($s['jenis_surat'] === 'masuk'): ?>
                                    <span class="badge-jenis-masuk">📥 Masuk</span>
                                <?php else: ?>
                                    <span class="badge-jenis-keluar">📤 Keluar</span>
                                <?php endif; ?>
                                <?php if (!empty($s['nomor_agenda'])): ?>
                                    <div style="font-size:11px; color:#64748b; margin-top:3px;">
                                        Agd: <strong><?= sanitize($s['nomor_agenda']) ?></strong>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="color:#0f172a;"><?= sanitize($s['nomor_surat']) ?></strong>
                                <div style="font-size:12px; color:#64748b; margin-top:2px;">
                                    Tgl: <?= date('d/m/Y', strtotime($s['tanggal_surat'])) ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($s['jenis_surat'] === 'masuk'): ?>
                                    <span style="font-size:11px; color:#15803d; font-weight:600;">DARI:</span><br>
                                    <strong><?= sanitize($s['pengirim'] ?: '-') ?></strong>
                                <?php else: ?>
                                    <span style="font-size:11px; color:#c2410c; font-weight:600;">KEPADA:</span><br>
                                    <strong><?= sanitize($s['penerima'] ?: '-') ?></strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight:600; color:#1e293b; max-width:280px; line-height:1.4;">
                                    <?= sanitize($s['perihal']) ?>
                                </div>
                                <span style="display:inline-block; margin-top:4px; font-size:11.5px; background:#f1f5f9; color:#475569; padding:2px 8px; border-radius:4px;">
                                    🏷️ <?= sanitize($s['kategori']) ?>
                                </span>
                            </td>
                            <td>
                                <!-- Sifat Badge -->
                                <?php
                                    $sifatClass = 'badge-sifat-biasa';
                                    if ($s['sifat'] === 'Rahasia') $sifatClass = 'badge-sifat-rahasia';
                                    elseif ($s['sifat'] === 'Penting') $sifatClass = 'badge-sifat-penting';
                                    elseif ($s['sifat'] === 'Segera') $sifatClass = 'badge-sifat-segera';
                                ?>
                                <span class="<?= $sifatClass ?>"><?= sanitize($s['sifat']) ?></span>
                                <br>
                                <!-- Status Badge -->
                                <?php
                                    $statusClass = 'badge-status-arsip';
                                    if ($s['status'] === 'Disposisi') $statusClass = 'badge-status-disposisi';
                                    elseif ($s['status'] === 'Proses') $statusClass = 'badge-status-proses';
                                    elseif ($s['status'] === 'Selesai') $statusClass = 'badge-status-selesai';
                                ?>
                                <span class="badge <?= $statusClass ?>" style="margin-top:4px; font-size:11px;">
                                    <?= sanitize($s['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($s['lokasi_arsip'])): ?>
                                    <span style="font-size:12px; color:#334155; display:inline-flex; align-items:center; gap:4px;">
                                        🗄️ <?= sanitize($s['lokasi_arsip']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:12px;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($s['file_surat'])): ?>
                                    <a href="<?= BASE_URL ?>/<?= htmlspecialchars($s['file_surat']) ?>" 
                                       target="_blank" 
                                       class="btn btn-sm btn-secondary" 
                                       style="padding:4px 8px; font-size:11.5px; width:auto; text-decoration:none;" 
                                       title="<?= sanitize($s['file_nama_asli'] ?? 'Lihat Berkas') ?>">
                                        📎 Berkas
                                    </a>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:12px;">Tidak Ada</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center; white-space:nowrap;">
                                <div style="display:inline-flex; gap:4px;">
                                    <!-- Detail Button -->
                                    <button type="button" 
                                            class="btn btn-sm btn-info btn-detail-surat" 
                                            data-json='<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>' 
                                            title="Lihat Detail & Disposisi"
                                            style="padding:5px 9px;">
                                        👁️
                                    </button>

                                    <!-- Edit Button -->
                                    <button type="button" 
                                            class="btn btn-sm btn-warning btn-edit-surat" 
                                            data-json='<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>' 
                                            title="Edit Data Surat"
                                            style="padding:5px 9px;">
                                        ✏️
                                    </button>

                                    <!-- Delete Button -->
                                    <button type="button" 
                                            class="btn btn-sm btn-danger btn-delete-surat" 
                                            data-id="<?= $s['id'] ?>" 
                                            data-nomor="<?= htmlspecialchars($s['nomor_surat'], ENT_QUOTES, 'UTF-8') ?>" 
                                            title="Hapus Surat"
                                            style="padding:5px 9px;">
                                        🗑️
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL 1: TAMBAH SURAT BARU
     ══════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modalTambahSurat">
    <div class="modal-content modal-surat-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
            <h3 style="margin:0; font-size:18px; color:#1a5632;">➕ Catat Surat Masuk / Keluar Baru</h3>
            <button type="button" onclick="closeTambahModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#94a3b8;">&times;</button>
        </div>

        <form method="POST" action="inventaris-surat.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create">

            <!-- Pilihan Jenis Surat -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:16px;">
                <label style="font-weight:700; font-size:13px; color:#1e293b; display:block; margin-bottom:8px;">Jenis Surat :</label>
                <div style="display:flex; gap:24px;">
                    <label style="cursor:pointer; font-weight:600; display:flex; align-items:center; gap:6px;">
                        <input type="radio" name="jenis_surat" value="masuk" checked onchange="toggleJenisForm('tambah', this.value)">
                        📥 Surat Masuk (Diterima)
                    </label>
                    <label style="cursor:pointer; font-weight:600; display:flex; align-items:center; gap:6px;">
                        <input type="radio" name="jenis_surat" value="keluar" onchange="toggleJenisForm('tambah', this.value)">
                        📤 Surat Keluar (Dikirim)
                    </label>
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label>Nomor Surat <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="nomor_surat" placeholder="Contoh: 014/LPPAI/UND/X/2026" required>
                </div>

                <div class="form-group">
                    <label>Nomor Agenda / Registrasi (Opsional)</label>
                    <input type="text" name="nomor_agenda" placeholder="Contoh: AGD-2026/045">
                </div>

                <div class="form-group">
                    <label>Tanggal Surat <span style="color:#ef4444;">*</span></label>
                    <input type="date" name="tanggal_surat" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label id="lbl_tambah_tgl_terima_kirim">Tanggal Diterima <span style="color:#ef4444;">*</span></label>
                    <input type="date" name="tanggal_terima_kirim" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-group" id="group_tambah_pengirim">
                    <label>Asal Surat / Pengirim <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="pengirim" placeholder="Nama Instansi / Pengirim">
                </div>

                <div class="form-group" id="group_tambah_penerima">
                    <label>Tujuan / Penerima Surat</label>
                    <input type="text" name="penerima" placeholder="Bagian / Pejabat Tujuan">
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label>Perihal / Hal Surat <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="perihal" placeholder="Isi perihal atau subjek pokok surat..." required>
                </div>

                <div class="form-group">
                    <label>Kategori Surat</label>
                    <select name="kategori" required>
                        <?php foreach ($kategoriList as $kat): ?>
                            <option value="<?= $kat ?>"><?= $kat ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Sifat Surat</label>
                    <select name="sifat" required>
                        <?php foreach ($sifatList as $sft): ?>
                            <option value="<?= $sft ?>"><?= $sft ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Lokasi Penyimpanan Arsip Fisik</label>
                    <input type="text" name="lokasi_arsip" placeholder="Contoh: Lemari A - Map 02 / Box Arsip 2026">
                </div>

                <div class="form-group">
                    <label>Status Surat</label>
                    <select name="status">
                        <?php foreach ($statusList as $st): ?>
                            <option value="<?= $st ?>"><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Disposisi (Khusus Surat Masuk) -->
            <div id="disposisi_tambah_box" style="background:#f1f5f9; border-radius:10px; padding:14px; margin-top:10px; margin-bottom:16px;">
                <div style="font-weight:700; font-size:13px; color:#1e293b; margin-bottom:10px; display:flex; align-items:center; gap:6px;">
                    <span>✍️</span> Disposisi / Instruksi Tindak Lanjut (Opsional)
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label>Disposisi Kepada :</label>
                        <input type="text" name="disposisi_kepada" placeholder="Contoh: Ketua LPPAI / Sekretaris / Ka. Tutorial">
                    </div>
                    <div class="form-group">
                        <label>Instruksi / Catatan Disposisi :</label>
                        <input type="text" name="disposisi_instruksi" placeholder="Contoh: Hadir mewakili / Tindak lanjuti / Arsipkan">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Ringkasan Isi / Catatan Tambahan (Opsional)</label>
                <textarea name="keterangan" rows="3" placeholder="Tuliskan poin penting atau ringkasan isi surat..."></textarea>
            </div>

            <div class="form-group">
                <label>Unggah Scan Berkas Surat (PDF, JPG, PNG, DOC, DOCX - Maks. 15MB)</label>
                <input type="file" name="file_surat" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.zip">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:16px;">
                <button type="button" class="btn btn-secondary" onclick="closeTambahModal()" style="width:auto;">Batal</button>
                <button type="submit" class="btn btn-primary" style="width:auto;">💾 Simpan Surat ke Inventaris</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL 2: EDIT DATA SURAT
     ══════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modalEditSurat">
    <div class="modal-content modal-surat-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
            <h3 style="margin:0; font-size:18px; color:#1a5632;">✏️ Edit Data Inventaris Surat</h3>
            <button type="button" onclick="closeEditModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#94a3b8;">&times;</button>
        </div>

        <form method="POST" action="inventaris-surat.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">

            <!-- Pilihan Jenis Surat -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:16px;">
                <label style="font-weight:700; font-size:13px; color:#1e293b; display:block; margin-bottom:8px;">Jenis Surat :</label>
                <div style="display:flex; gap:24px;">
                    <label style="cursor:pointer; font-weight:600; display:flex; align-items:center; gap:6px;">
                        <input type="radio" name="jenis_surat" id="edit_jenis_masuk" value="masuk" onchange="toggleJenisForm('edit', this.value)">
                        📥 Surat Masuk (Diterima)
                    </label>
                    <label style="cursor:pointer; font-weight:600; display:flex; align-items:center; gap:6px;">
                        <input type="radio" name="jenis_surat" id="edit_jenis_keluar" value="keluar" onchange="toggleJenisForm('edit', this.value)">
                        📤 Surat Keluar (Dikirim)
                    </label>
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label>Nomor Surat <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="nomor_surat" id="edit_nomor_surat" required>
                </div>

                <div class="form-group">
                    <label>Nomor Agenda / Registrasi</label>
                    <input type="text" name="nomor_agenda" id="edit_nomor_agenda">
                </div>

                <div class="form-group">
                    <label>Tanggal Surat <span style="color:#ef4444;">*</span></label>
                    <input type="date" name="tanggal_surat" id="edit_tanggal_surat" required>
                </div>

                <div class="form-group">
                    <label id="lbl_edit_tgl_terima_kirim">Tanggal Diterima <span style="color:#ef4444;">*</span></label>
                    <input type="date" name="tanggal_terima_kirim" id="edit_tanggal_terima_kirim" required>
                </div>

                <div class="form-group" id="group_edit_pengirim">
                    <label>Asal Surat / Pengirim</label>
                    <input type="text" name="pengirim" id="edit_pengirim">
                </div>

                <div class="form-group" id="group_edit_penerima">
                    <label>Tujuan / Penerima Surat</label>
                    <input type="text" name="penerima" id="edit_penerima">
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label>Perihal / Hal Surat <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="perihal" id="edit_perihal" required>
                </div>

                <div class="form-group">
                    <label>Kategori Surat</label>
                    <select name="kategori" id="edit_kategori" required>
                        <?php foreach ($kategoriList as $kat): ?>
                            <option value="<?= $kat ?>"><?= $kat ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Sifat Surat</label>
                    <select name="sifat" id="edit_sifat" required>
                        <?php foreach ($sifatList as $sft): ?>
                            <option value="<?= $sft ?>"><?= $sft ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Lokasi Penyimpanan Arsip Fisik</label>
                    <input type="text" name="lokasi_arsip" id="edit_lokasi_arsip">
                </div>

                <div class="form-group">
                    <label>Status Surat</label>
                    <select name="status" id="edit_status">
                        <?php foreach ($statusList as $st): ?>
                            <option value="<?= $st ?>"><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Disposisi (Khusus Surat Masuk) -->
            <div id="disposisi_edit_box" style="background:#f1f5f9; border-radius:10px; padding:14px; margin-top:10px; margin-bottom:16px;">
                <div style="font-weight:700; font-size:13px; color:#1e293b; margin-bottom:10px; display:flex; align-items:center; gap:6px;">
                    <span>✍️</span> Disposisi / Instruksi Tindak Lanjut
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label>Disposisi Kepada :</label>
                        <input type="text" name="disposisi_kepada" id="edit_disposisi_kepada">
                    </div>
                    <div class="form-group">
                        <label>Instruksi / Catatan Disposisi :</label>
                        <input type="text" name="disposisi_instruksi" id="edit_disposisi_instruksi">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Ringkasan Isi / Catatan Tambahan</label>
                <textarea name="keterangan" id="edit_keterangan" rows="3"></textarea>
            </div>

            <!-- File info & replace -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:16px;">
                <label style="font-weight:700; font-size:13px; color:#1e293b; display:block; margin-bottom:6px;">Berkas Lampiran Saat Ini :</label>
                <div id="edit_file_preview" style="margin-bottom:10px; font-size:13px; color:#64748b;">
                    Tidak ada berkas terlampir.
                </div>
                <div style="margin-bottom:10px;" id="box_hapus_lampiran">
                    <label style="cursor:pointer; font-size:12.5px; color:#b91c1c; display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="hapus_file_lampiran" value="1">
                        🗑️ Hapus berkas lampiran saat ini
                    </label>
                </div>
                <div>
                    <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Ganti / Unggah Berkas Baru :</label>
                    <input type="file" name="file_surat" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.zip">
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:16px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()" style="width:auto;">Batal</button>
                <button type="submit" class="btn btn-primary" style="width:auto;">💾 Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL 3: DETAIL LENGKAP & DISPOSISI SURAT
     ══════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modalDetailSurat">
    <div class="modal-content modal-surat-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
            <div>
                <h3 style="margin:0; font-size:18px; color:#1a5632;" id="detail_title">Detail Surat</h3>
                <span id="detail_badge_jenis" style="margin-top:4px; display:inline-block;"></span>
            </div>
            <div style="display:flex; gap:8px;">
                <a id="btn_cetak_disposisi" href="#" target="_blank" class="btn btn-sm btn-secondary" style="width:auto;">
                    🖨️ Cetak Disposisi
                </a>
                <button type="button" onclick="closeDetailModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#94a3b8;">&times;</button>
            </div>
        </div>

        <div style="overflow-x:auto;">
            <table class="table detail-table no-datatable" style="width:100%; font-size:13.5px; border-collapse:collapse;">
                <tbody>
                    <tr>
                        <th>Nomor Surat</th>
                        <td><strong id="detail_nomor_surat" style="font-size:14.5px; color:#0f172a;"></strong></td>
                    </tr>
                    <tr>
                        <th>Nomor Agenda</th>
                        <td id="detail_nomor_agenda">-</td>
                    </tr>
                    <tr>
                        <th>Tanggal Surat</th>
                        <td id="detail_tanggal_surat">-</td>
                    </tr>
                    <tr>
                        <th id="detail_lbl_tgl_terima_kirim">Tanggal Diterima</th>
                        <td id="detail_tanggal_terima_kirim">-</td>
                    </tr>
                    <tr id="row_detail_pengirim">
                        <th>Asal Surat / Pengirim</th>
                        <td><strong id="detail_pengirim">-</strong></td>
                    </tr>
                    <tr id="row_detail_penerima">
                        <th>Tujuan / Penerima</th>
                        <td><strong id="detail_penerima">-</strong></td>
                    </tr>
                    <tr>
                        <th>Perihal / Hal</th>
                        <td id="detail_perihal" style="font-weight:600; color:#1e293b;">-</td>
                    </tr>
                    <tr>
                        <th>Kategori Surat</th>
                        <td id="detail_kategori">-</td>
                    </tr>
                    <tr>
                        <th>Sifat & Status</th>
                        <td>
                            <span id="detail_sifat" class="badge"></span>
                            <span id="detail_status" class="badge" style="margin-left:6px;"></span>
                        </td>
                    </tr>
                    <tr>
                        <th>Lokasi Berkas Fisik</th>
                        <td id="detail_lokasi_arsip">-</td>
                    </tr>
                    <tr>
                        <th>Disposisi Kepada</th>
                        <td id="detail_disposisi_kepada" style="font-weight:600; color:#1d4ed8;">-</td>
                    </tr>
                    <tr>
                        <th>Instruksi Disposisi</th>
                        <td id="detail_disposisi_instruksi" style="font-style:italic;">-</td>
                    </tr>
                    <tr>
                        <th>Ringkasan / Keterangan</th>
                        <td id="detail_keterangan">-</td>
                    </tr>
                    <tr>
                        <th>Berkas Digital</th>
                        <td id="detail_file_surat">-</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Embedded Viewer jika Berkas Gambar / PDF -->
        <div id="detail_preview_container" style="display:none; margin-top:20px; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden;">
            <div style="background:#f8fafc; padding:10px 14px; font-weight:600; font-size:13px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                <span>Pratinjau Berkas</span>
                <a id="detail_preview_download_btn" href="#" target="_blank" class="btn btn-sm btn-primary" style="padding:4px 10px; font-size:12px;">⬇️ Unduh Berkas</a>
            </div>
            <div id="detail_preview_body" style="padding:16px; text-align:center; max-height:450px; overflow-y:auto; background:#f1f5f9;">
            </div>
        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:14px;">
            <button type="button" class="btn btn-secondary" onclick="closeDetailModal()" style="width:auto;">Tutup</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL 4: KONFIRMASI HAPUS
     ══════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modalDeleteSurat">
    <div class="modal-content" style="max-width:440px; text-align:center;">
        <div style="font-size:48px; margin-bottom:12px;">🗑️</div>
        <h3 style="margin-bottom:8px; color:#1e293b;">Konfirmasi Hapus Surat</h3>
        <p style="font-size:13.5px; color:#64748b; margin-bottom:20px;">
            Apakah Anda yakin ingin menghapus surat nomor <br>
            <strong id="delete_nomor_display" style="color:#ef4444;"></strong>?<br>
            Berkas lampiran fisik digital juga akan dihapus permanen dari server.
        </p>

        <form method="POST" action="inventaris-surat.php">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete_id">

            <div style="display:flex; justify-content:center; gap:12px;">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()" style="min-width:110px;">Batal</button>
                <button type="submit" class="btn btn-danger" style="min-width:110px;">Ya, Hapus</button>
            </div>
        </form>
    </div>
</div>

<script>
// Toggle field dinamika antara Surat Masuk dan Surat Keluar
function toggleJenisForm(mode, val) {
    var isMasuk = (val === 'masuk');
    var lblTgl = document.getElementById('lbl_' + mode + '_tgl_terima_kirim');
    if (lblTgl) {
        lblTgl.innerHTML = (isMasuk ? 'Tanggal Diterima ' : 'Tanggal Dikirim ') + '<span style="color:#ef4444;">*</span>';
    }

    var dispBox = document.getElementById('disposisi_' + mode + '_box');
    if (dispBox) {
        dispBox.style.display = isMasuk ? 'block' : 'none';
    }
}

// Modal Tambah
function openTambahModal() {
    document.getElementById('modalTambahSurat').classList.add('show');
}
function closeTambahModal() {
    document.getElementById('modalTambahSurat').classList.remove('show');
}

// Modal Edit
function openEditModal(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_nomor_surat').value = data.nomor_surat || '';
    document.getElementById('edit_nomor_agenda').value = data.nomor_agenda || '';
    document.getElementById('edit_tanggal_surat').value = data.tanggal_surat || '';
    document.getElementById('edit_tanggal_terima_kirim').value = data.tanggal_terima_kirim || '';
    document.getElementById('edit_pengirim').value = data.pengirim || '';
    document.getElementById('edit_penerima').value = data.penerima || '';
    document.getElementById('edit_perihal').value = data.perihal || '';
    document.getElementById('edit_kategori').value = data.kategori || 'Umum';
    document.getElementById('edit_sifat').value = data.sifat || 'Biasa';
    document.getElementById('edit_lokasi_arsip').value = data.lokasi_arsip || '';
    document.getElementById('edit_status').value = data.status || 'Arsip';
    document.getElementById('edit_disposisi_kepada').value = data.disposisi_kepada || '';
    document.getElementById('edit_disposisi_instruksi').value = data.disposisi_instruksi || '';
    document.getElementById('edit_keterangan').value = data.keterangan || '';

    if (data.jenis_surat === 'keluar') {
        document.getElementById('edit_jenis_keluar').checked = true;
        toggleJenisForm('edit', 'keluar');
    } else {
        document.getElementById('edit_jenis_masuk').checked = true;
        toggleJenisForm('edit', 'masuk');
    }

    // Info berkas
    var previewEl = document.getElementById('edit_file_preview');
    var boxHapusEl = document.getElementById('box_hapus_lampiran');
    if (data.file_surat) {
        var baseUrl = '<?= BASE_URL ?>';
        var sizeKb = data.file_ukuran ? ' (' + Math.round(data.file_ukuran / 1024) + ' KB)' : '';
        previewEl.innerHTML = '📎 <a href="' + baseUrl + '/' + data.file_surat + '" target="_blank" style="color:#1d4ed8; font-weight:600;">' + (data.file_nama_asli || 'Lihat Berkas Lampiran') + '</a>' + sizeKb;
        boxHapusEl.style.display = 'block';
    } else {
        previewEl.innerHTML = 'Tidak ada berkas terlampir.';
        boxHapusEl.style.display = 'none';
    }

    document.getElementById('modalEditSurat').classList.add('show');
}
function closeEditModal() {
    document.getElementById('modalEditSurat').classList.remove('show');
}

// Modal Detail
function openDetailModal(data) {
    var baseUrl = '<?= BASE_URL ?>';
    document.getElementById('detail_title').innerText = 'Detail ' + (data.jenis_surat === 'masuk' ? 'Surat Masuk' : 'Surat Keluar');
    document.getElementById('detail_badge_jenis').innerHTML = data.jenis_surat === 'masuk' 
        ? '<span class="badge-jenis-masuk">📥 Surat Masuk</span>' 
        : '<span class="badge-jenis-keluar">📤 Surat Keluar</span>';

    document.getElementById('detail_nomor_surat').innerText = data.nomor_surat || '-';
    document.getElementById('detail_nomor_agenda').innerText = data.nomor_agenda || '-';
    document.getElementById('detail_tanggal_surat').innerText = data.tanggal_surat ? formatDate(data.tanggal_surat) : '-';
    
    var isMasuk = (data.jenis_surat === 'masuk');
    document.getElementById('detail_lbl_tgl_terima_kirim').innerText = isMasuk ? 'Tanggal Diterima' : 'Tanggal Dikirim';
    document.getElementById('detail_tanggal_terima_kirim').innerText = data.tanggal_terima_kirim ? formatDate(data.tanggal_terima_kirim) : '-';

    document.getElementById('row_detail_pengirim').style.display = isMasuk ? 'table-row' : 'none';
    document.getElementById('detail_pengirim').innerText = data.pengirim || '-';

    document.getElementById('row_detail_penerima').style.display = isMasuk ? 'none' : 'table-row';
    document.getElementById('detail_penerima').innerText = data.penerima || '-';

    document.getElementById('detail_perihal').innerText = data.perihal || '-';
    document.getElementById('detail_kategori').innerText = data.kategori || '-';

    // Sifat
    var sifatEl = document.getElementById('detail_sifat');
    sifatEl.className = 'badge ' + (data.sifat === 'Rahasia' ? 'badge-sifat-rahasia' : (data.sifat === 'Penting' ? 'badge-sifat-penting' : (data.sifat === 'Segera' ? 'badge-sifat-segera' : 'badge-sifat-biasa')));
    sifatEl.innerText = data.sifat || 'Biasa';

    // Status
    var statusEl = document.getElementById('detail_status');
    statusEl.className = 'badge ' + (data.status === 'Disposisi' ? 'badge-status-disposisi' : (data.status === 'Proses' ? 'badge-status-proses' : (data.status === 'Selesai' ? 'badge-status-selesai' : 'badge-status-arsip')));
    statusEl.innerText = data.status || 'Arsip';

    document.getElementById('detail_lokasi_arsip').innerText = data.lokasi_arsip || '-';
    document.getElementById('detail_disposisi_kepada').innerText = data.disposisi_kepada || '-';
    document.getElementById('detail_disposisi_instruksi').innerText = data.disposisi_instruksi || '-';
    document.getElementById('detail_keterangan').innerText = data.keterangan || '-';

    // Tombol Cetak Disposisi
    var btnCetakDisp = document.getElementById('btn_cetak_disposisi');
    btnCetakDisp.href = 'cetak-inventaris-surat.php?mode=disposisi&id=' + data.id;

    // File info & preview
    var fileCell = document.getElementById('detail_file_surat');
    var prevContainer = document.getElementById('detail_preview_container');
    var prevBody = document.getElementById('detail_preview_body');
    var prevDownBtn = document.getElementById('detail_preview_download_btn');

    if (data.file_surat) {
        var fileUrl = baseUrl + '/' + data.file_surat;
        var ext = data.file_surat.split('.').pop().toLowerCase();
        fileCell.innerHTML = '<a href="' + fileUrl + '" target="_blank" class="btn btn-sm btn-primary" style="padding:3px 10px; font-size:12px; width:auto;">📎 Unduh ' + (data.file_nama_asli || 'Berkas') + '</a>';
        
        prevDownBtn.href = fileUrl;
        prevContainer.style.display = 'block';

        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(ext) !== -1) {
            prevBody.innerHTML = '<img src="' + fileUrl + '" style="max-width:100%; height:auto; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.1);">';
        } else if (ext === 'pdf') {
            prevBody.innerHTML = '<iframe src="' + fileUrl + '" style="width:100%; height:420px; border:none; border-radius:6px;"></iframe>';
        } else {
            prevBody.innerHTML = '<div style="padding:30px; color:#64748b;">Berkas format <strong>.' + ext.toUpperCase() + '</strong> tidak dapat dipratinjau langsung. Silakan klik tombol <em>Unduh Berkas</em> di atas.</div>';
        }
    } else {
        fileCell.innerHTML = '<span style="color:#94a3b8;">Tidak ada berkas digital terlampir.</span>';
        prevContainer.style.display = 'none';
        prevBody.innerHTML = '';
    }

    document.getElementById('modalDetailSurat').classList.add('show');
}
function closeDetailModal() {
    document.getElementById('modalDetailSurat').classList.remove('show');
}

// Modal Delete
function openDeleteModal(id, nomor) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_nomor_display').innerText = nomor;
    document.getElementById('modalDeleteSurat').classList.add('show');
}
function closeDeleteModal() {
    document.getElementById('modalDeleteSurat').classList.remove('show');
}

function formatDate(isoStr) {
    if (!isoStr) return '';
    var parts = isoStr.split('-');
    if (parts.length === 3) {
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }
    return isoStr;
}

// Event Listeners
document.addEventListener('click', function(e) {
    var btnEdit = e.target.closest('.btn-edit-surat');
    if (btnEdit) {
        var data = JSON.parse(btnEdit.dataset.json);
        openEditModal(data);
        return;
    }

    var btnDetail = e.target.closest('.btn-detail-surat');
    if (btnDetail) {
        var data = JSON.parse(btnDetail.dataset.json);
        openDetailModal(data);
        return;
    }

    var btnDelete = e.target.closest('.btn-delete-surat');
    if (btnDelete) {
        openDeleteModal(btnDelete.dataset.id, btnDelete.dataset.nomor);
        return;
    }

    // Klik di luar modal untuk tutup
    if (e.target.classList.contains('modal-backdrop')) {
        e.target.classList.remove('show');
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeTambahModal();
        closeEditModal();
        closeDetailModal();
        closeDeleteModal();
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
