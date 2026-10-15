<?php
/**
 * LPPAI Corner - Pencatatan Pengambilan Sertifikat
 */
define('PAGE_TITLE', 'Pencatatan Pengambilan Sertifikat');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
$currentUser = getCurrentUser();

// 1. Auto-migration skema database jika belum ada
try {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS pengambilan_sertifikat (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tutorial_registration_id INT NULL,
        nim VARCHAR(50) NOT NULL,
        nama_mahasiswa VARCHAR(150) NOT NULL,
        program_studi VARCHAR(100) NULL,
        nomor_sertifikat VARCHAR(100) NOT NULL,
        tahun_ajaran VARCHAR(50) NULL,
        status_pengambilan ENUM('Sudah Diambil', 'Belum Diambil') NOT NULL DEFAULT 'Sudah Diambil',
        tanggal_pengambilan DATETIME NOT NULL,
        metode_pengambilan ENUM('Scan QR', 'Manual Admin', 'Barcode/Scanner') NOT NULL DEFAULT 'Scan QR',
        nama_penerima VARCHAR(150) NULL,
        hubungan_penerima VARCHAR(50) NULL DEFAULT 'Sendiri',
        foto_bukti VARCHAR(255) NULL,
        tanda_tangan_digital MEDIUMTEXT NULL,
        catatan TEXT NULL,
        petugas_id INT NULL,
        petugas_nama VARCHAR(150) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_nim (nim),
        INDEX idx_nomor_sertifikat (nomor_sertifikat),
        INDEX idx_tanggal_pengambilan (tanggal_pengambilan),
        INDEX idx_status (status_pengambilan)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    try {
        $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN is_sertifikat_diambil TINYINT(1) DEFAULT 0 AFTER nomor_sertifikat");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN tgl_sertifikat_diambil DATETIME NULL AFTER is_sertifikat_diambil");
    } catch (Exception $e) {}
} catch (Exception $e) {
    // Ignore if table/columns exist
}

// 2. Handle AJAX Actions
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    // ── AJAX 1: SCAN / LOOKUP SERTIFIKAT ──
    if ($action === 'lookup') {
        $rawCode = trim($_POST['code'] ?? $_GET['code'] ?? '');
        if (empty($rawCode)) {
            echo json_encode(['success' => false, 'message' => 'Kode atau nomor sertifikat kosong.']);
            exit;
        }

        // Ekstraksi jika kode berbentuk URL (misal scan URL dari QR Code)
        $cleanCode = $rawCode;
        if (filter_var($rawCode, FILTER_VALIDATE_URL)) {
            $parsedUrl = parse_url($rawCode);
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
                if (!empty($queryParams['code'])) {
                    $cleanCode = $queryParams['code'];
                } elseif (!empty($queryParams['no'])) {
                    $cleanCode = $queryParams['no'];
                } elseif (!empty($queryParams['nim'])) {
                    $cleanCode = $queryParams['nim'];
                }
            }
        } elseif (substr($rawCode, 0, 1) === '{' && substr($rawCode, -1) === '}') {
            // Jika format JSON
            $jsonObj = json_decode($rawCode, true);
            if ($jsonObj) {
                $cleanCode = $jsonObj['no'] ?? $jsonObj['nomor_sertifikat'] ?? $jsonObj['nim'] ?? $rawCode;
            }
        }

        // Cari di database (cocokkan nomor_sertifikat atau nim)
        $sqlLookup = "
            SELECT tr.id as reg_id, tr.nomor_sertifikat, tr.tahun_ajaran, 
                   tr.nilai_thaharah, tr.nilai_shalat, tr.nilai_surat_pendek, 
                   tr.nilai_amaliyah, tr.nilai_jenazah, tr.nilai_ujian_tulis,
                   tr.is_sertifikat_diambil, tr.tgl_sertifikat_diambil,
                   u.id as user_id, u.nim, u.nama_lengkap, u.program_studi, u.no_hp,
                   u.tempat_lahir, u.tanggal_lahir
            FROM tutorial_registrations tr
            JOIN users u ON tr.user_id = u.id
            WHERE (tr.nomor_sertifikat = ? OR u.nim = ?)
              AND tr.nomor_sertifikat IS NOT NULL AND tr.nomor_sertifikat != ''
            ORDER BY tr.id DESC
            LIMIT 1
        ";
        $stmtLookup = $pdo->prepare($sqlLookup);
        $stmtLookup->execute([$cleanCode, $cleanCode]);
        $cert = $stmtLookup->fetch();

        if (!$cert) {
            echo json_encode([
                'success' => false,
                'message' => 'Data sertifikat tidak ditemukan untuk kode: ' . htmlspecialchars($cleanCode) . '. Pastikan nomor sertifikat sudah di-lock atau terbit.'
            ]);
            exit;
        }

        // Cek status pengambilan di tabel pengambilan_sertifikat
        $stmtLog = $pdo->prepare("
            SELECT * FROM pengambilan_sertifikat 
            WHERE nomor_sertifikat = ? AND status_pengambilan = 'Sudah Diambil'
            ORDER BY id DESC LIMIT 1
        ");
        $stmtLog->execute([$cert['nomor_sertifikat']]);
        $logAmbil = $stmtLog->fetch();

        $alreadyTaken = !empty($logAmbil) || ($cert['is_sertifikat_diambil'] == 1);

        echo json_encode([
            'success'       => true,
            'already_taken' => $alreadyTaken,
            'certificate'   => [
                'reg_id'           => $cert['reg_id'],
                'nomor_sertifikat' => $cert['nomor_sertifikat'],
                'nim'              => $cert['nim'],
                'nama_lengkap'     => $cert['nama_lengkap'],
                'program_studi'    => $cert['program_studi'] ?: 'Belum Diatur',
                'tahun_ajaran'     => $cert['tahun_ajaran'],
                'no_hp'            => $cert['no_hp'] ?: '-',
                'ttl'              => ($cert['tempat_lahir'] ? $cert['tempat_lahir'] . ', ' : '') . ($cert['tanggal_lahir'] ? date('d/m/Y', strtotime($cert['tanggal_lahir'])) : '-')
            ],
            'pickup_info'   => $logAmbil ? [
                'id'                 => $logAmbil['id'],
                'tanggal_pengambilan'=> date('d M Y, H:i', strtotime($logAmbil['tanggal_pengambilan'])) . ' WIB',
                'nama_penerima'      => $logAmbil['nama_penerima'],
                'hubungan_penerima'  => $logAmbil['hubungan_penerima'],
                'metode_pengambilan' => $logAmbil['metode_pengambilan'],
                'petugas_nama'       => $logAmbil['petugas_nama'] ?: 'Admin',
                'catatan'            => $logAmbil['catatan'] ?: '-'
            ] : null
        ]);
        exit;
    }

    // ── AJAX 2: CATAT PENYERAHAN / PENGAMBILAN SERTIFIKAT ──
    if ($action === 'catat_ambil') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrf($token)) {
            echo json_encode(['success' => false, 'message' => 'Sesi tidak valid. Silakan refresh halaman.']);
            exit;
        }

        $nomorSertifikat = trim($_POST['nomor_sertifikat'] ?? '');
        $nim             = trim($_POST['nim'] ?? '');
        $namaMhs         = trim($_POST['nama_mahasiswa'] ?? '');
        $prodi           = trim($_POST['program_studi'] ?? '');
        $tahunAjaran     = trim($_POST['tahun_ajaran'] ?? '');
        $regId           = (int)($_POST['reg_id'] ?? 0);
        $hubungan        = trim($_POST['hubungan_penerima'] ?? 'Sendiri');
        $namaPenerima    = trim($_POST['nama_penerima'] ?? '');
        $catatan         = trim($_POST['catatan'] ?? '');
        $metode          = trim($_POST['metode_pengambilan'] ?? 'Scan QR');

        if (empty($nomorSertifikat) || empty($nim)) {
            echo json_encode(['success' => false, 'message' => 'Nomor sertifikat dan NIM wajib ada.']);
            exit;
        }

        if (empty($namaPenerima)) {
            $namaPenerima = ($hubungan === 'Sendiri') ? $namaMhs : 'Perwakilan Mahasiswa';
        }

        // Cek apakah sudah pernah diambil sebelumnya
        $check = $pdo->prepare("SELECT id FROM pengambilan_sertifikat WHERE nomor_sertifikat = ? AND status_pengambilan = 'Sudah Diambil'");
        $check->execute([$nomorSertifikat]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Sertifikat ini sudah tercatat telah diambil sebelumnya.']);
            exit;
        }

        $now = date('Y-m-d H:i:s');
        $petugasId = $currentUser['id'] ?? null;
        $petugasNama = $currentUser['nama_lengkap'] ?? 'Admin';

        // Insert ke pengambilan_sertifikat
        $stmtInsert = $pdo->prepare("
            INSERT INTO pengambilan_sertifikat 
            (tutorial_registration_id, nim, nama_mahasiswa, program_studi, nomor_sertifikat, 
             tahun_ajaran, status_pengambilan, tanggal_pengambilan, metode_pengambilan, 
             nama_penerima, hubungan_penerima, catatan, petugas_id, petugas_nama)
            VALUES (?, ?, ?, ?, ?, ?, 'Sudah Diambil', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtInsert->execute([
            $regId ?: null, $nim, $namaMhs, $prodi, $nomorSertifikat,
            $tahunAjaran, $now, $metode,
            $namaPenerima, $hubungan, $catatan ?: null, $petugasId, $petugasNama
        ]);
        $pengambilanId = $pdo->lastInsertId();

        // Update status di tutorial_registrations
        if ($regId > 0) {
            $pdo->prepare("UPDATE tutorial_registrations SET is_sertifikat_diambil = 1, tgl_sertifikat_diambil = ? WHERE id = ?")
                ->execute([$now, $regId]);
        } else {
            $pdo->prepare("UPDATE tutorial_registrations SET is_sertifikat_diambil = 1, tgl_sertifikat_diambil = ? WHERE nomor_sertifikat = ?")
                ->execute([$now, $nomorSertifikat]);
        }

        echo json_encode([
            'success'          => true,
            'message'          => 'Pengambilan sertifikat berhasil dicatat!',
            'pengambilan_id'   => $pengambilanId,
            'waktu_pengambilan'=> date('d M Y, H:i', strtotime($now)) . ' WIB',
            'nama_penerima'    => $namaPenerima
        ]);
        exit;
    }

    // ── AJAX 3: BATALKAN / RESET PENGAMBILAN SERTIFIKAT ──
    if ($action === 'batal_ambil') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrf($token)) {
            echo json_encode(['success' => false, 'message' => 'Sesi tidak valid.']);
            exit;
        }

        $nomorSertifikat = trim($_POST['nomor_sertifikat'] ?? '');
        if (empty($nomorSertifikat)) {
            echo json_encode(['success' => false, 'message' => 'Nomor sertifikat tidak valid.']);
            exit;
        }

        // Hapus catatan pengambilan
        $pdo->prepare("DELETE FROM pengambilan_sertifikat WHERE nomor_sertifikat = ?")->execute([$nomorSertifikat]);

        // Revert status di tutorial_registrations
        $pdo->prepare("UPDATE tutorial_registrations SET is_sertifikat_diambil = 0, tgl_sertifikat_diambil = NULL WHERE nomor_sertifikat = ?")
            ->execute([$nomorSertifikat]);

        echo json_encode(['success' => true, 'message' => 'Status pengambilan sertifikat berhasil di-reset menjadi Belum Diambil.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
    exit;
}

// 3. Ekspor CSV
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $sqlExport = "
        SELECT tr.nomor_sertifikat, tr.tahun_ajaran, u.nim, u.nama_lengkap, u.program_studi,
               CASE WHEN ps.id IS NOT NULL THEN 'Sudah Diambil' ELSE 'Belum Diambil' END as status_ambil,
               ps.tanggal_pengambilan, ps.nama_penerima, ps.hubungan_penerima, ps.metode_pengambilan, ps.petugas_nama, ps.catatan
        FROM tutorial_registrations tr
        JOIN users u ON tr.user_id = u.id
        LEFT JOIN pengambilan_sertifikat ps ON ps.nomor_sertifikat = tr.nomor_sertifikat AND ps.status_pengambilan = 'Sudah Diambil'
        WHERE tr.nomor_sertifikat IS NOT NULL AND tr.nomor_sertifikat != ''
        ORDER BY (ps.id IS NOT NULL) DESC, ps.tanggal_pengambilan DESC, u.nama_lengkap ASC
    ";
    $rows = $pdo->query($sqlExport)->fetchAll();

    $filename = "Rekap_Pengambilan_Sertifikat_LPPAI_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    fputcsv($output, [
        'No', 'Nomor Sertifikat', 'NIM', 'Nama Mahasiswa', 'Program Studi / Jurusan', 
        'Tahun Ajaran', 'Status Pengambilan', 'Waktu Pengambilan', 'Nama Penerima', 
        'Hubungan Penerima', 'Metode Scan', 'Petugas LPPAI', 'Catatan'
    ]);

    $no = 1;
    foreach ($rows as $r) {
        fputcsv($output, [
            $no++,
            $r['nomor_sertifikat'],
            $r['nim'],
            $r['nama_lengkap'],
            $r['program_studi'] ?: '-',
            $r['tahun_ajaran'] ?: '-',
            $r['status_ambil'],
            $r['tanggal_pengambilan'] ? date('d/m/Y H:i', strtotime($r['tanggal_pengambilan'])) : '-',
            $r['nama_penerima'] ?: '-',
            $r['hubungan_penerima'] ?: '-',
            $r['metode_pengambilan'] ?: '-',
            $r['petugas_nama'] ?: '-',
            $r['catatan'] ?: '-'
        ]);
    }
    fclose($output);
    exit;
}

// 4. Statistik Keseluruhan
$statTotalSertifikat = (int)$pdo->query("SELECT COUNT(*) FROM tutorial_registrations WHERE nomor_sertifikat IS NOT NULL AND nomor_sertifikat != ''")->fetchColumn();
$statSudahDiambil    = (int)$pdo->query("SELECT COUNT(DISTINCT nomor_sertifikat) FROM pengambilan_sertifikat WHERE status_pengambilan = 'Sudah Diambil'")->fetchColumn();
$statBelumDiambil    = max(0, $statTotalSertifikat - $statSudahDiambil);
$statHariIni         = (int)$pdo->query("SELECT COUNT(*) FROM pengambilan_sertifikat WHERE DATE(tanggal_pengambilan) = CURDATE() AND status_pengambilan = 'Sudah Diambil'")->fetchColumn();

// 5. Query Data untuk Tab Tabel
$filterStatus = $_GET['status'] ?? '';
$filterProdi  = $_GET['prodi'] ?? '';
$search       = trim($_GET['q'] ?? '');

$where = ["tr.nomor_sertifikat IS NOT NULL AND tr.nomor_sertifikat != ''"];
$params = [];

if ($filterStatus === 'sudah') {
    $where[] = "ps.id IS NOT NULL";
} elseif ($filterStatus === 'belum') {
    $where[] = "ps.id IS NULL";
}

if ($filterProdi !== '') {
    $where[] = "u.program_studi = ?";
    $params[] = $filterProdi;
}

if ($search !== '') {
    $where[] = "(u.nim LIKE ? OR u.nama_lengkap LIKE ? OR tr.nomor_sertifikat LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like]);
}

$whereClause = implode(" AND ", $where);

$stmtList = $pdo->prepare("
    SELECT tr.id as reg_id, tr.nomor_sertifikat, tr.tahun_ajaran,
           u.id as user_id, u.nim, u.nama_lengkap, u.program_studi, u.no_hp,
           ps.id as pengambilan_id, ps.tanggal_pengambilan, ps.metode_pengambilan,
           ps.nama_penerima, ps.hubungan_penerima, ps.petugas_nama, ps.catatan
    FROM tutorial_registrations tr
    JOIN users u ON tr.user_id = u.id
    LEFT JOIN pengambilan_sertifikat ps ON ps.nomor_sertifikat = tr.nomor_sertifikat AND ps.status_pengambilan = 'Sudah Diambil'
    WHERE $whereClause
    ORDER BY (ps.id IS NOT NULL) ASC, tr.id DESC
");
$stmtList->execute($params);
$certificates = $stmtList->fetchAll();

// Daftar prodi untuk filter
$prodiList = $pdo->query("SELECT DISTINCT program_studi FROM users WHERE program_studi IS NOT NULL AND program_studi != '' ORDER BY program_studi ASC")->fetchAll(PDO::FETCH_COLUMN);

// 6. Recent Logs (10 Terakhir)
$recentLogs = $pdo->query("
    SELECT ps.*, u.program_studi as jurusan_asli
    FROM pengambilan_sertifikat ps
    LEFT JOIN users u ON ps.nim = u.nim
    WHERE ps.status_pengambilan = 'Sudah Diambil'
    ORDER BY ps.tanggal_pengambilan DESC LIMIT 10
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<style>
    .sertifikat-stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .sertifikat-stat-card {
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
    .sertifikat-stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
    }
    .sertifikat-stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        flex-shrink: 0;
    }
    .sertifikat-stat-icon.blue   { background: #eff6ff; color: #1d4ed8; }
    .sertifikat-stat-icon.green  { background: #f0fdf4; color: #15803d; }
    .sertifikat-stat-icon.orange { background: #fff7ed; color: #c2410c; }
    .sertifikat-stat-icon.purple { background: #faf5ff; color: #7e22ce; }

    /* Nav Tabs */
    .nav-tabs-sertifikat {
        display: flex;
        gap: 8px;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 22px;
    }
    .nav-tab-item {
        padding: 11px 20px;
        font-weight: 600;
        font-size: 14px;
        color: #64748b;
        text-decoration: none;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
    }
    .nav-tab-item:hover { color: #1a5632; }
    .nav-tab-item.active {
        color: #1a5632;
        border-bottom-color: #1a5632;
        background: #f0fdf4;
        border-top-left-radius: 8px;
        border-top-right-radius: 8px;
    }

    /* Scanner View Grid */
    .scanner-view-grid {
        display: grid;
        grid-template-columns: 1fr 1.15fr;
        gap: 24px;
        align-items: start;
    }
    @media (max-width: 900px) {
        .scanner-view-grid { grid-template-columns: 1fr; }
    }

    .scanner-camera-box {
        background: #fff;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.06);
        border: 1px solid #e2e8f0;
    }

    #reader {
        width: 100%;
        border-radius: 12px;
        overflow: hidden;
        border: 2px dashed #cbd5e1;
    }
    #reader video { border-radius: 10px; }

    /* Result Card */
    .result-display-card {
        background: #fff;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.06);
        border: 1px solid #e2e8f0;
        min-height: 380px;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .badge-status-taken {
        background: #dcfce7;
        color: #15803d;
        font-weight: 700;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    .badge-status-waiting {
        background: #fef3c7;
        color: #92400e;
        font-weight: 700;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .manual-scan-input {
        width: 100%;
        padding: 12px 16px;
        font-size: 15px;
        border: 2px solid #cbd5e1;
        border-radius: 10px;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .manual-scan-input:focus {
        border-color: #1a5632;
        box-shadow: 0 0 0 3px rgba(26,86,50,0.15);
    }

    .toggle-auto-checkin {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        padding: 10px 14px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 600;
        color: #334155;
        cursor: pointer;
    }
</style>

<!-- Header Judul -->
<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; margin-bottom:20px;">
    <div>
        <h2 style="font-size:22px; font-weight:800; color:#1a5632; margin-bottom:4px;">🎓 Pencatatan Pengambilan Sertifikat</h2>
        <p style="font-size:13.5px; color:#64748b; margin:0;">Pindai QR Code pada sertifikat fisik atau input NIM untuk mencatat penyerahan sertifikat secara instan.</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a href="cetak-tanda-terima-sertifikat.php?mode=rekap" target="_blank" class="btn btn-secondary" style="width:auto;">
            🖨️ Cetak Berita Acara Rekap
        </a>
        <a href="pengambilan-sertifikat.php?action=export_csv" class="btn btn-secondary" style="width:auto;">
            📥 Ekspor Data CSV
        </a>
    </div>
</div>

<!-- ── Statistik Ringkas ────────────────────────────────────── -->
<div class="sertifikat-stat-grid">
    <div class="sertifikat-stat-card">
        <div class="sertifikat-stat-icon blue">🎓</div>
        <div>
            <div style="font-size:26px; font-weight:800; color:#1e293b;"><?= number_format($statTotalSertifikat) ?></div>
            <div style="font-size:12.5px; color:#64748b; font-weight:500;">Total Sertifikat Terbit</div>
        </div>
    </div>
    <div class="sertifikat-stat-card">
        <div class="sertifikat-stat-icon green">✅</div>
        <div>
            <div style="font-size:26px; font-weight:800; color:#15803d;"><?= number_format($statSudahDiambil) ?></div>
            <div style="font-size:12.5px; color:#64748b; font-weight:500;">Sudah Diambil</div>
        </div>
    </div>
    <div class="sertifikat-stat-card">
        <div class="sertifikat-stat-icon orange">⏳</div>
        <div>
            <div style="font-size:26px; font-weight:800; color:#c2410c;"><?= number_format($statBelumDiambil) ?></div>
            <div style="font-size:12.5px; color:#64748b; font-weight:500;">Belum Diambil</div>
        </div>
    </div>
    <div class="sertifikat-stat-card">
        <div class="sertifikat-stat-icon purple">📅</div>
        <div>
            <div style="font-size:26px; font-weight:800; color:#7e22ce;"><?= number_format($statHariIni) ?></div>
            <div style="font-size:12.5px; color:#64748b; font-weight:500;">Diambil Hari Ini</div>
        </div>
    </div>
</div>

<!-- ── Navigasi Tab ─────────────────────────────────────────── -->
<div class="nav-tabs-sertifikat">
    <div class="nav-tab-item active" id="tab_btn_scanner" onclick="switchTab('scanner')">
        <span>📷</span> Scan QR / Input Cepat
    </div>
    <div class="nav-tab-item" id="tab_btn_data" onclick="switchTab('data')">
        <span>📋</span> Data Seluruh Sertifikat (<?= $statTotalSertifikat ?>)
    </div>
    <div class="nav-tab-item" id="tab_btn_logs" onclick="switchTab('logs')">
        <span>📜</span> Riwayat Penyerahan Terakhir
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     TAB 1: SCANNER QR & INPUT CEPAT
     ══════════════════════════════════════════════════════════════ -->
<div id="tab_content_scanner">
    <div class="scanner-view-grid">
        <!-- Kolom Kiri: Kamera Scanner & Input Scanner Gun -->
        <div class="scanner-camera-box">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <h4 style="margin:0; font-size:15px; color:#1e293b; display:flex; align-items:center; gap:8px;">
                    <span>📷</span> Kamera Scanner QR
                </h4>
                <div style="display:flex; gap:6px;">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="toggleCamera()" id="btnToggleCam">
                        🔄 Ganti Kamera
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="restartScanner()">
                        ⚡ Muat Ulang
                    </button>
                </div>
            </div>

            <!-- Area Kamera HTML5 QR Code -->
            <div id="reader"></div>

            <div style="margin-top:16px;">
                <label class="toggle-auto-checkin">
                    <input type="checkbox" id="autoCheckinToggle" checked style="width:18px; height:18px; cursor:pointer;">
                    <span>⚡ Mode Rekam Otomatis (Sekali Scan Langsung Tercatat)</span>
                </label>
            </div>

            <div style="margin-top:20px; border-top:1px solid #e2e8f0; padding-top:16px;">
                <label style="font-weight:700; font-size:13px; color:#334155; display:block; margin-bottom:6px;">
                    ⌨️ Atau Input / Tembak Barcode Scanner :
                </label>
                <div style="display:flex; gap:8px;">
                    <input type="text" id="manualInput" class="manual-scan-input" 
                           placeholder="Ketik / Scan Nomor Sertifikat atau NIM..." 
                           autocomplete="off">
                    <button type="button" class="btn btn-primary" onclick="lookupManualCode()" style="padding:0 20px;">
                        Cari
                    </button>
                </div>
                <small style="color:#64748b; font-size:12px; margin-top:5px; display:block;">
                    💡 Dukungan penuh untuk USB Barcode/QR Scanner Gun (otomatis diproses saat enter).
                </small>
            </div>
        </div>

        <!-- Kolom Kanan: Kartu Hasil & Konfirmasi Penyerahan -->
        <div class="result-display-card" id="resultCard">
            <div id="stateEmpty" style="text-align:center; padding:40px 20px; color:#94a3b8;">
                <div style="font-size:52px; margin-bottom:12px;">📷</div>
                <h3 style="font-size:17px; color:#334155; margin-bottom:6px;">Siap Memindai Sertifikat</h3>
                <p style="font-size:13.5px; max-width:360px; margin:0 auto; line-height:1.5;">
                    Arahkan kamera ke QR Code yang tercetak di sertifikat mahasiswa, atau ketik NIM/Nomor Sertifikat pada kolom pencarian.
                </p>
            </div>

            <div id="stateLoading" style="display:none; text-align:center; padding:40px 20px;">
                <div style="font-size:36px; animation: spin 1s infinite linear; display:inline-block; margin-bottom:12px;">⏳</div>
                <div style="font-size:15px; font-weight:600; color:#1a5632;">Mencari data sertifikat & mahasiswa...</div>
            </div>

            <!-- Tampilan Data Sertifikat Ditemukan -->
            <div id="stateFound" style="display:none;">
                <div id="bannerStatus" style="padding:12px 16px; border-radius:10px; margin-bottom:16px; font-weight:600; font-size:14px; display:flex; align-items:center; justify-content:space-between;">
                    <span id="bannerText"></span>
                    <span id="bannerBadge"></span>
                </div>

                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:18px; margin-bottom:16px;">
                    <div style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">
                        DATA MAHASISWA & SERTIFIKAT
                    </div>
                    <h3 id="resNama" style="font-size:18px; color:#0f172a; margin:0 0 6px 0;"></h3>
                    
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px 16px; font-size:13.5px; margin-top:10px;">
                        <div>
                            <span style="color:#64748b;">NIM :</span> <strong id="resNim" style="color:#1e293b;"></strong>
                        </div>
                        <div>
                            <span style="color:#64748b;">Program Studi :</span> <strong id="resProdi" style="color:#1e293b;"></strong>
                        </div>
                        <div style="grid-column: span 2;">
                            <span style="color:#64748b;">Nomor Sertifikat :</span> <strong id="resNoSertifikat" style="color:#1a5632; font-size:14.5px;"></strong>
                        </div>
                        <div>
                            <span style="color:#64748b;">Tahun Ajaran :</span> <span id="resTahun"></span>
                        </div>
                        <div>
                            <span style="color:#64748b;">TTL :</span> <span id="resTTL"></span>
                        </div>
                    </div>
                </div>

                <!-- Form Konfirmasi Penyerahan (Jika Belum Diambil) -->
                <div id="boxFormPenyerahan">
                    <form id="formPenyerahan" onsubmit="event.preventDefault(); submitPengambilan();">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" id="f_nomor_sertifikat">
                        <input type="hidden" id="f_nim">
                        <input type="hidden" id="f_nama_mahasiswa">
                        <input type="hidden" id="f_program_studi">
                        <input type="hidden" id="f_tahun_ajaran">
                        <input type="hidden" id="f_reg_id">
                        <input type="hidden" id="f_metode" value="Scan QR">

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                            <div>
                                <label style="font-weight:600; font-size:12.5px; color:#334155; display:block; margin-bottom:5px;">
                                    Penerima Sertifikat :
                                </label>
                                <select id="f_hubungan" class="form-control" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px;" onchange="togglePenerimaField(this.value)">
                                    <option value="Sendiri">Diambil Sendiri (Mahasiswa)</option>
                                    <option value="Keluarga">Diwakilkan: Keluarga / Saudara</option>
                                    <option value="Teman">Diwakilkan: Teman / Rekan</option>
                                    <option value="Lainnya">Diwakilkan: Lainnya</option>
                                </select>
                            </div>

                            <div>
                                <label style="font-weight:600; font-size:12.5px; color:#334155; display:block; margin-bottom:5px;">
                                    Nama Pengambil :
                                </label>
                                <input type="text" id="f_nama_penerima" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px;" placeholder="Nama yang mengambil">
                            </div>
                        </div>

                        <div style="margin-bottom:16px;">
                            <label style="font-weight:600; font-size:12.5px; color:#334155; display:block; margin-bottom:5px;">
                                Catatan / Keterangan (Opsional) :
                            </label>
                            <input type="text" id="f_catatan" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px;" placeholder="Misal: Nomor WA pengambil, keterangan jaminan, dll">
                        </div>

                        <button type="submit" id="btnSubmitPenyerahan" class="btn btn-success" style="width:100%; padding:12px; font-size:15px; font-weight:700;">
                            ✅ Konfirmasi & Simpan Pengambilan Sertifikat
                        </button>
                    </form>
                </div>

                <!-- Info Riwayat (Jika Sudah Diambil Sebelumnya) -->
                <div id="boxInfoSudahDiambil" style="display:none;">
                    <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:16px; margin-bottom:16px;">
                        <h4 style="margin:0 0 8px 0; color:#92400e; font-size:14px; font-weight:700;">
                            📋 Rincian Pengambilan Sebelumnya :
                        </h4>
                        <table style="width:100%; font-size:13px; line-height:1.6;">
                            <tr>
                                <td style="width:140px; color:#78350f;">Waktu Diambil :</td>
                                <td id="infoWaktuAmbil" style="font-weight:600; color:#1e293b;"></td>
                            </tr>
                            <tr>
                                <td style="color:#78350f;">Diterima Oleh :</td>
                                <td id="infoPenerima" style="font-weight:600; color:#1e293b;"></td>
                            </tr>
                            <tr>
                                <td style="color:#78350f;">Metode Pencatatan :</td>
                                <td id="infoMetode"></td>
                            </tr>
                            <tr>
                                <td style="color:#78350f;">Petugas LPPAI :</td>
                                <td id="infoPetugas"></td>
                            </tr>
                            <tr>
                                <td style="color:#78350f;">Catatan :</td>
                                <td id="infoCatatan"></td>
                            </tr>
                        </table>
                    </div>

                    <div style="display:flex; gap:10px;">
                        <a id="btnCetakBukti" href="#" target="_blank" class="btn btn-secondary" style="flex:1;">
                            🖨️ Cetak Tanda Terima
                        </a>
                        <button type="button" class="btn btn-danger" onclick="batalPengambilan()" style="flex:1;">
                            🔄 Reset (Batal Ambil)
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     TAB 2: DATA SELURUH SERTIFIKAT & STATUS PENGAMBILAN
     ══════════════════════════════════════════════════════════════ -->
<div id="tab_content_data" style="display:none;">
    <!-- Panel Filter -->
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:20px;">
        <form method="GET" action="pengambilan-sertifikat.php">
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
                <div>
                    <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Cari Mahasiswa / Sertifikat</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="NIM, Nama, No Sertifikat..." 
                           style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none;">
                </div>
                <div>
                    <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Status Pengambilan</label>
                    <select name="status" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; background:#fff;">
                        <option value="">-- Semua Status --</option>
                        <option value="sudah" <?= $filterStatus === 'sudah' ? 'selected' : '' ?>>Sudah Diambil</option>
                        <option value="belum" <?= $filterStatus === 'belum' ? 'selected' : '' ?>>Belum Diambil</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Program Studi</label>
                    <select name="prodi" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; background:#fff;">
                        <option value="">-- Semua Prodi --</option>
                        <?php foreach ($prodiList as $prd): ?>
                            <option value="<?= htmlspecialchars($prd) ?>" <?= $filterProdi === $prd ? 'selected' : '' ?>><?= htmlspecialchars($prd) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="submit" class="btn btn-primary" style="padding:8px 14px; font-size:13px; flex:1;">
                        🔍 Filter
                    </button>
                    <a href="pengambilan-sertifikat.php" class="btn btn-secondary" style="padding:8px 12px; font-size:13px;" title="Reset Filter">
                        🔄
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Tabel Data -->
    <div class="card">
        <div class="card-header" style="background:#fff; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <span style="font-weight:700; color:#1e293b;">
                Daftar Mahasiswa & Sertifikat (<?= count($certificates) ?> Data)
            </span>
            <span style="font-size:12px; color:#64748b;">
                💡 Gunakan tombol <strong>✅ Catat</strong> untuk mencatat penyerahan secara langsung dari tabel.
            </span>
        </div>
        <div class="card-body" style="padding:16px;">
            <div class="table-responsive">
                <table class="table table-hover" id="tableSertifikat" style="width:100%; font-size:13px;">
                    <thead>
                        <tr>
                            <th style="width:35px;">No</th>
                            <th>Mahasiswa</th>
                            <th>Program Studi</th>
                            <th>No. Sertifikat</th>
                            <th>Status</th>
                            <th>Waktu Pengambilan</th>
                            <th>Penerima</th>
                            <th style="width:120px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($certificates)): ?>
                            <?php $no = 1; foreach ($certificates as $c): ?>
                            <tr>
                                <td style="text-align:center; color:#64748b;"><?= $no++ ?></td>
                                <td>
                                    <strong><?= sanitize($c['nama_lengkap']) ?></strong><br>
                                    <small class="text-muted">NIM: <?= sanitize($c['nim']) ?></small>
                                </td>
                                <td><?= sanitize($c['program_studi'] ?: '-') ?></td>
                                <td>
                                    <strong style="color:#1a5632;"><?= sanitize($c['nomor_sertifikat']) ?></strong><br>
                                    <small class="text-muted"><?= sanitize($c['tahun_ajaran'] ?: '-') ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($c['pengambilan_id'])): ?>
                                        <span class="badge-status-taken">✅ Sudah Diambil</span>
                                    <?php else: ?>
                                        <span class="badge-status-waiting">⏳ Belum Diambil</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($c['tanggal_pengambilan'])): ?>
                                        <?= date('d/m/Y H:i', strtotime($c['tanggal_pengambilan'])) ?>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($c['nama_penerima'])): ?>
                                        <strong><?= sanitize($c['nama_penerima']) ?></strong>
                                        <div style="font-size:11px; color:#64748b;">(<?= sanitize($c['hubungan_penerima'] ?: 'Sendiri') ?>)</div>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center; white-space:nowrap;">
                                    <?php if (empty($c['pengambilan_id'])): ?>
                                        <button type="button" class="btn btn-sm btn-success" 
                                                onclick="quickPickUp('<?= htmlspecialchars($c['nomor_sertifikat'], ENT_QUOTES) ?>')">
                                            ✅ Catat Ambil
                                        </button>
                                    <?php else: ?>
                                        <div style="display:inline-flex; gap:4px;">
                                            <a href="cetak-tanda-terima-sertifikat.php?id=<?= $c['pengambilan_id'] ?>" 
                                               target="_blank" class="btn btn-sm btn-info" title="Cetak Tanda Terima" style="padding:4px 8px;">
                                                🖨️
                                            </a>
                                            <button type="button" class="btn btn-sm btn-danger" 
                                                    onclick="batalPengambilanTable('<?= htmlspecialchars($c['nomor_sertifikat'], ENT_QUOTES) ?>')" 
                                                    title="Reset Status" style="padding:4px 8px;">
                                                🔄
                                            </button>
                                        </div>
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

<!-- ══════════════════════════════════════════════════════════════
     TAB 3: RIWAYAT PENYERAHAN TERAKHIR
     ══════════════════════════════════════════════════════════════ -->
<div id="tab_content_logs" style="display:none;">
    <div class="card">
        <div class="card-header" style="background:#fff; border-bottom:1px solid #e2e8f0;">
            <span style="font-weight:700; color:#1e293b;">📜 Riwayat Aktivitas Penyerahan Sertifikat Terbaru</span>
        </div>
        <div class="card-body" style="padding:16px;">
            <div class="table-responsive">
                <table class="table no-datatable table-striped" style="width:100%; font-size:13px;">
                    <thead>
                        <tr>
                            <th>Waktu Penyerahan</th>
                            <th>NIM & Nama Mahasiswa</th>
                            <th>Jurusan / Prodi</th>
                            <th>No. Sertifikat</th>
                            <th>Penerima</th>
                            <th>Metode</th>
                            <th>Petugas Pencatat</th>
                            <th>Catatan</th>
                            <th>Tanda Terima</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentLogs)): ?>
                            <tr>
                                <td colspan="9" style="text-align:center; padding:30px; color:#94a3b8;">
                                    Belum ada catatan aktivitas penyerahan sertifikat.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td><strong><?= date('d/m/Y H:i', strtotime($log['tanggal_pengambilan'])) ?></strong></td>
                                <td>
                                    <strong><?= sanitize($log['nama_mahasiswa']) ?></strong><br>
                                    <small class="text-muted"><?= sanitize($log['nim']) ?></small>
                                </td>
                                <td><?= sanitize($log['program_studi'] ?: $log['jurusan_asli'] ?: '-') ?></td>
                                <td><span style="color:#1a5632; font-weight:600;"><?= sanitize($log['nomor_sertifikat']) ?></span></td>
                                <td>
                                    <?= sanitize($log['nama_penerima']) ?>
                                    <small style="color:#64748b;">(<?= sanitize($log['hubungan_penerima']) ?>)</small>
                                </td>
                                <td><span class="badge" style="background:#e0f2fe; color:#0369a1;"><?= sanitize($log['metode_pengambilan']) ?></span></td>
                                <td><?= sanitize($log['petugas_nama'] ?: 'Admin') ?></td>
                                <td><?= sanitize($log['catatan'] ?: '-') ?></td>
                                <td>
                                    <a href="cetak-tanda-terima-sertifikat.php?id=<?= $log['id'] ?>" target="_blank" class="btn btn-sm btn-secondary" style="padding:3px 8px; font-size:12px;">
                                        🖨️ Cetak
                                    </a>
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

<!-- Load Library Scanner QR -->
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<script>
var csrfToken = '<?= csrfToken() ?>';
var html5QrcodeScanner = null;
var currentCameraFacing = "environment"; // default kamera belakang
var isProcessingScan = false;
var lastScannedCode = '';
var audioCtx = null;

// Sound Effects via Web Audio API
function playBeep(type) {
    try {
        if (!audioCtx) {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        var osc = audioCtx.createOscillator();
        var gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);

        if (type === 'success') {
            osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5
            gain.gain.setValueAtTime(0.3, audioCtx.currentTime);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.15);
        } else if (type === 'warning') {
            osc.frequency.setValueAtTime(440, audioCtx.currentTime);
            gain.gain.setValueAtTime(0.3, audioCtx.currentTime);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.12);
            setTimeout(function() {
                var osc2 = audioCtx.createOscillator();
                var gain2 = audioCtx.createGain();
                osc2.connect(gain2);
                gain2.connect(audioCtx.destination);
                osc2.frequency.setValueAtTime(440, audioCtx.currentTime);
                gain2.gain.setValueAtTime(0.3, audioCtx.currentTime);
                osc2.start();
                osc2.stop(audioCtx.currentTime + 0.12);
            }, 150);
        } else if (type === 'error') {
            osc.frequency.setValueAtTime(220, audioCtx.currentTime);
            gain.gain.setValueAtTime(0.4, audioCtx.currentTime);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.25);
        }
    } catch (e) {}
}

// Inisialisasi Kamera Scanner
function startScanner() {
    var readerEl = document.getElementById('reader');
    if (!readerEl) return;

    if (html5QrcodeScanner) {
        try { html5QrcodeScanner.clear(); } catch(e) {}
    }

    html5QrcodeScanner = new Html5QrcodeScanner(
        "reader", 
        { 
            fps: 10, 
            qrbox: { width: 250, height: 250 },
            aspectRatio: 1.0,
            showTorchButtonIfSupported: true
        }, 
        false
    );
    html5QrcodeScanner.render(onScanSuccess, onScanFailure);
}

function restartScanner() {
    startScanner();
    showToast('Kamera pemindai dimuat ulang.', 'info', 2000);
}

function toggleCamera() {
    restartScanner();
}

// Callback saat QR Code terbaca
function onScanSuccess(decodedText, decodedResult) {
    if (isProcessingScan) return;
    if (decodedText === lastScannedCode) return; // cegah scan berulang kode yang sama dalam hitungan detik

    isProcessingScan = true;
    lastScannedCode = decodedText;

    // Lookup data ke server
    processLookup(decodedText, 'Scan QR');

    // Buka kunci debounce setelah 3 detik
    setTimeout(function() {
        isProcessingScan = false;
        lastScannedCode = '';
    }, 3000);
}

function onScanFailure(error) {
    // abaikan kegagalan frame biasa
}

// Input manual atau Barcode Gun
document.getElementById('manualInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        lookupManualCode();
    }
});

function lookupManualCode() {
    var val = document.getElementById('manualInput').value.trim();
    if (!val) {
        showToast('Silakan masukkan nomor sertifikat atau NIM terlebih dahulu.', 'warning');
        return;
    }
    processLookup(val, 'Barcode/Scanner');
}

// Proses Query Lookup ke Backend
function processLookup(code, metode) {
    var stateEmpty   = document.getElementById('stateEmpty');
    var stateLoading = document.getElementById('stateLoading');
    var stateFound   = document.getElementById('stateFound');

    stateEmpty.style.display = 'none';
    stateLoading.style.display = 'block';
    stateFound.style.display = 'none';

    var fd = new FormData();
    fd.append('action', 'lookup');
    fd.append('code', code);

    fetch('pengambilan-sertifikat.php?ajax=1', {
        method: 'POST',
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        stateLoading.style.display = 'none';

        if (!res.success) {
            playBeep('error');
            stateEmpty.style.display = 'block';
            stateEmpty.innerHTML = `
                <div style="font-size:48px; margin-bottom:12px;">❌</div>
                <h3 style="font-size:16px; color:#b91c1c; margin-bottom:6px;">Tidak Ditemukan</h3>
                <p style="font-size:13.5px; color:#64748b; margin-bottom:14px;">${res.message}</p>
                <button type="button" class="btn btn-secondary btn-sm" onclick="resetCard()">🔄 Coba Lagi</button>
            `;
            return;
        }

        renderCertificateData(res, metode);
    })
    .catch(function(err) {
        stateLoading.style.display = 'none';
        stateEmpty.style.display = 'block';
        showToast('Terjadi kesalahan saat memeriksa data.', 'danger');
    });
}

// Render Data ke Tampilan
function renderCertificateData(res, metode) {
    var cert = res.certificate;
    var stateFound = document.getElementById('stateFound');
    stateFound.style.display = 'block';

    document.getElementById('resNama').innerText = cert.nama_lengkap;
    document.getElementById('resNim').innerText = cert.nim;
    document.getElementById('resProdi').innerText = cert.program_studi;
    document.getElementById('resNoSertifikat').innerText = cert.nomor_sertifikat;
    document.getElementById('resTahun').innerText = cert.tahun_ajaran || '-';
    document.getElementById('resTTL').innerText = cert.ttl;

    // Set Hidden Form fields
    document.getElementById('f_nomor_sertifikat').value = cert.nomor_sertifikat;
    document.getElementById('f_nim').value = cert.nim;
    document.getElementById('f_nama_mahasiswa').value = cert.nama_lengkap;
    document.getElementById('f_program_studi').value = cert.program_studi;
    document.getElementById('f_tahun_ajaran').value = cert.tahun_ajaran || '';
    document.getElementById('f_reg_id').value = cert.reg_id || 0;
    document.getElementById('f_metode').value = metode;
    document.getElementById('f_nama_penerima').value = cert.nama_lengkap;

    var bannerStatus = document.getElementById('bannerStatus');
    var bannerText   = document.getElementById('bannerText');
    var bannerBadge  = document.getElementById('bannerBadge');
    var boxForm      = document.getElementById('boxFormPenyerahan');
    var boxInfo      = document.getElementById('boxInfoSudahDiambil');

    if (res.already_taken) {
        // SUDAH DIAMBIL
        playBeep('warning');
        bannerStatus.style.background = '#fef2f2';
        bannerStatus.style.border = '1px solid #fecaca';
        bannerStatus.style.color = '#991b1b';
        bannerText.innerHTML = '⚠️ PERINGATAN: Sertifikat Ini SUDAH PERNAH DIAMBIL!';
        bannerBadge.innerHTML = '<span class="badge-status-taken">Sudah Diambil</span>';

        boxForm.style.display = 'none';
        boxInfo.style.display = 'block';

        if (res.pickup_info) {
            document.getElementById('infoWaktuAmbil').innerText = res.pickup_info.tanggal_pengambilan;
            document.getElementById('infoPenerima').innerText = res.pickup_info.nama_penerima + ' (' + res.pickup_info.hubungan_penerima + ')';
            document.getElementById('infoMetode').innerText = res.pickup_info.metode_pengambilan;
            document.getElementById('infoPetugas').innerText = res.pickup_info.petugas_nama;
            document.getElementById('infoCatatan').innerText = res.pickup_info.catatan;
            document.getElementById('btnCetakBukti').href = 'cetak-tanda-terima-sertifikat.php?id=' + res.pickup_info.id;
        }
    } else {
        // BELUM DIAMBIL
        playBeep('success');
        bannerStatus.style.background = '#f0fdf4';
        bannerStatus.style.border = '1px solid #bbf7d0';
        bannerStatus.style.color = '#166534';
        bannerText.innerHTML = '🎉 Sertifikat Ditemukan (Siap Diserahkan)';
        bannerBadge.innerHTML = '<span class="badge-status-waiting">Belum Diambil</span>';

        boxForm.style.display = 'block';
        boxInfo.style.display = 'none';

        // Auto-Checkin jika fitur diaktifkan
        var autoCheckin = document.getElementById('autoCheckinToggle').checked;
        if (autoCheckin) {
            submitPengambilan();
        }
    }
}

function togglePenerimaField(val) {
    var fNama = document.getElementById('f_nama_penerima');
    var mhsName = document.getElementById('f_nama_mahasiswa').value;
    if (val === 'Sendiri') {
        fNama.value = mhsName;
    } else {
        fNama.value = '';
        fNama.focus();
    }
}

// Submit Catat Pengambilan
function submitPengambilan() {
    var btn = document.getElementById('btnSubmitPenyerahan');
    btn.disabled = true;
    btn.innerText = 'Menyimpan...';

    var fd = new FormData();
    fd.append('action', 'catat_ambil');
    fd.append('csrf_token', csrfToken);
    fd.append('nomor_sertifikat', document.getElementById('f_nomor_sertifikat').value);
    fd.append('nim', document.getElementById('f_nim').value);
    fd.append('nama_mahasiswa', document.getElementById('f_nama_mahasiswa').value);
    fd.append('program_studi', document.getElementById('f_program_studi').value);
    fd.append('tahun_ajaran', document.getElementById('f_tahun_ajaran').value);
    fd.append('reg_id', document.getElementById('f_reg_id').value);
    fd.append('metode_pengambilan', document.getElementById('f_metode').value);
    fd.append('hubungan_penerima', document.getElementById('f_hubungan').value);
    fd.append('nama_penerima', document.getElementById('f_nama_penerima').value);
    fd.append('catatan', document.getElementById('f_catatan').value);

    fetch('pengambilan-sertifikat.php?ajax=1', {
        method: 'POST',
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.innerText = '✅ Konfirmasi & Simpan Pengambilan Sertifikat';

        if (res.success) {
            playBeep('success');
            showToast('✅ Pengambilan sertifikat ' + document.getElementById('f_nama_mahasiswa').value + ' berhasil dicatat!', 'success', 5000);

            // Ubah banner menjadi sudah diambil
            var bannerStatus = document.getElementById('bannerStatus');
            bannerStatus.style.background = '#dcfce7';
            bannerStatus.style.border = '1px solid #86efac';
            bannerStatus.style.color = '#15803d';
            document.getElementById('bannerText').innerHTML = '✅ PENYERAHAN SERTIFIKAT BERHASIL DICATAT!';
            document.getElementById('bannerBadge').innerHTML = '<span class="badge-status-taken">Sudah Diambil</span>';

            document.getElementById('boxFormPenyerahan').style.display = 'none';
            document.getElementById('boxInfoSudahDiambil').style.display = 'block';

            document.getElementById('infoWaktuAmbil').innerText = res.waktu_pengambilan;
            document.getElementById('infoPenerima').innerText = res.nama_penerima;
            document.getElementById('infoMetode').innerText = document.getElementById('f_metode').value;
            document.getElementById('infoPetugas').innerText = '<?= addslashes($currentUser['nama_lengkap'] ?? 'Admin') ?>';
            document.getElementById('infoCatatan').innerText = document.getElementById('f_catatan').value || '-';
            document.getElementById('btnCetakBukti').href = 'cetak-tanda-terima-sertifikat.php?id=' + res.pengambilan_id;

            // Bersihkan input manual
            document.getElementById('manualInput').value = '';
        } else {
            playBeep('error');
            showToast(res.message, 'danger');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerText = '✅ Konfirmasi & Simpan Pengambilan Sertifikat';
        showToast('Terjadi kesalahan koneksi server.', 'danger');
    });
}

// Batal Pengambilan
function batalPengambilan() {
    var noSertifikat = document.getElementById('f_nomor_sertifikat').value;
    if (!noSertifikat) return;

    if (!confirm('Apakah Anda yakin ingin membatalkan status pengambilan sertifikat ini? Status akan dikembalikan menjadi Belum Diambil.')) {
        return;
    }

    var fd = new FormData();
    fd.append('action', 'batal_ambil');
    fd.append('csrf_token', csrfToken);
    fd.append('nomor_sertifikat', noSertifikat);

    fetch('pengambilan-sertifikat.php?ajax=1', {
        method: 'POST',
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast('Status berhasil di-reset menjadi Belum Diambil.', 'info');
            resetCard();
        } else {
            showToast(res.message, 'danger');
        }
    });
}

function batalPengambilanTable(noSertifikat) {
    if (!confirm('Yakin ingin mereset status pengambilan sertifikat ' + noSertifikat + ' menjadi Belum Diambil?')) {
        return;
    }

    var fd = new FormData();
    fd.append('action', 'batal_ambil');
    fd.append('csrf_token', csrfToken);
    fd.append('nomor_sertifikat', noSertifikat);

    fetch('pengambilan-sertifikat.php?ajax=1', {
        method: 'POST',
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast(res.message, 'success');
            setTimeout(function() { window.location.reload(); }, 600);
        } else {
            showToast(res.message, 'danger');
        }
    });
}

function quickPickUp(noSertifikat) {
    switchTab('scanner');
    document.getElementById('manualInput').value = noSertifikat;
    lookupManualCode();
}

function resetCard() {
    document.getElementById('stateEmpty').style.display = 'block';
    document.getElementById('stateEmpty').innerHTML = `
        <div style="font-size:52px; margin-bottom:12px;">📷</div>
        <h3 style="font-size:17px; color:#334155; margin-bottom:6px;">Siap Memindai Sertifikat</h3>
        <p style="font-size:13.5px; max-width:360px; margin:0 auto; line-height:1.5;">
            Arahkan kamera ke QR Code yang tercetak di sertifikat mahasiswa, atau ketik NIM/Nomor Sertifikat pada kolom pencarian.
        </p>
    `;
    document.getElementById('stateLoading').style.display = 'none';
    document.getElementById('stateFound').style.display = 'none';
    document.getElementById('manualInput').value = '';
}

// Navigasi Tab
function switchTab(tab) {
    document.getElementById('tab_btn_scanner').classList.remove('active');
    document.getElementById('tab_btn_data').classList.remove('active');
    document.getElementById('tab_btn_logs').classList.remove('active');

    document.getElementById('tab_content_scanner').style.display = 'none';
    document.getElementById('tab_content_data').style.display = 'none';
    document.getElementById('tab_content_logs').style.display = 'none';

    if (tab === 'scanner') {
        document.getElementById('tab_btn_scanner').classList.add('active');
        document.getElementById('tab_content_scanner').style.display = 'block';
        if (!html5QrcodeScanner) startScanner();
    } else if (tab === 'data') {
        document.getElementById('tab_btn_data').classList.add('active');
        document.getElementById('tab_content_data').style.display = 'block';
    } else if (tab === 'logs') {
        document.getElementById('tab_btn_logs').classList.add('active');
        document.getElementById('tab_content_logs').style.display = 'block';
    }
}

// Jalankan scanner saat halaman siap
document.addEventListener('DOMContentLoaded', function() {
    startScanner();
});
// Jalankan langsung juga untuk lingkungan SPA
if (document.readyState === 'complete' || document.readyState === 'interactive') {
    startScanner();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
