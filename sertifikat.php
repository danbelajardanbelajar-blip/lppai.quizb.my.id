<?php
/**
 * LPPAI Corner - Halaman Publik Verifikasi & Pengambilan Sertifikat
 * Terbuka untuk umum (publik) tanpa login untuk verifikasi sertifikat.
 * Dilengkapi fitur konfirmasi pengambilan sertifikat mandiri bagi mahasiswa pemilik sertifikat.
 */
require_once __DIR__ . '/includes/auth.php';

$pdo = getDBConnection();
$currentUser = getCurrentUser();
$isLoggedIn  = isLoggedIn();
$isAdminUser = isAdmin();

// Tangkap kode atau nomor sertifikat dari berbagai parameter URL
$rawCode = trim($_GET['no'] ?? $_GET['code'] ?? $_GET['nomor'] ?? $_GET['id'] ?? '');

// Ekstraksi jika kode berbentuk URL (misal scan URL utuh)
$certCode = $rawCode;
if (filter_var($rawCode, FILTER_VALIDATE_URL)) {
    $parsed = parse_url($rawCode);
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $qParams);
        $certCode = $qParams['no'] ?? $qParams['code'] ?? $qParams['nomor'] ?? $rawCode;
    }
}

$cert = null;
$pickupLog = null;
$flashMsg = null;
$flashType = 'success';

// Handle POST: Konfirmasi Pengambilan Mandiri oleh Pemilik atau Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_pickup') {
    $token = $_POST['csrf_token'] ?? '';
    $regId = (int)($_POST['reg_id'] ?? 0);

    if (!verifyCsrf($token)) {
        $flashMsg = 'Sesi tidak valid atau kedaluwarsa. Silakan refresh halaman dan coba lagi.';
        $flashType = 'danger';
    } elseif (!$isLoggedIn) {
        $flashMsg = 'Anda wajib login terlebih dahulu untuk mengonfirmasi pengambilan sertifikat.';
        $flashType = 'danger';
    } else {
        // Ambil data sertifikat yang dimaksud
        $stmtC = $pdo->prepare("
            SELECT tr.*, u.nim, u.nama_lengkap, u.program_studi, u.fakultas
            FROM tutorial_registrations tr
            JOIN users u ON tr.user_id = u.id
            WHERE tr.id = ?
        ");
        $stmtC->execute([$regId]);
        $targetCert = $stmtC->fetch();

        if (!$targetCert) {
            $flashMsg = 'Data sertifikat tidak ditemukan.';
            $flashType = 'danger';
        } else {
            // Cek otorisasi: Harus pemilik sah sertifikat atau Admin LPPAI
            $isTargetOwner = ($currentUser['id'] == $targetCert['user_id'] || (!empty($targetCert['nim']) && $currentUser['nim'] === $targetCert['nim']));

            if (!$isTargetOwner && !$isAdminUser) {
                $flashMsg = 'Akses ditolak! Anda hanya dapat mengonfirmasi sertifikat milik Anda sendiri (NIM: ' . htmlspecialchars($targetCert['nim']) . ').';
                $flashType = 'danger';
            } elseif ($targetCert['is_sertifikat_diambil'] == 1) {
                $flashMsg = 'Sertifikat ini sudah tercatat telah diambil sebelumnya.';
                $flashType = 'warning';
            } else {
                // Proses pencatatan
                $namaPenerima = trim($_POST['nama_penerima'] ?? $currentUser['nama_lengkap']);
                $hubungan = trim($_POST['hubungan_penerima'] ?? 'Sendiri (Pemilik Sertifikat)');
                $catatan = trim($_POST['catatan'] ?? 'Konfirmasi mandiri via scan QR Sertifikat');
                $metode = 'Scan QR';
                $petugasNama = $isTargetOwner ? 'Mahasiswa Mandiri (QR)' : ($currentUser['nama_lengkap'] . ' (Admin)');
                $petugasId = $isAdminUser ? $currentUser['id'] : null;

                try {
                    $pdo->beginTransaction();

                    // Simpan ke log pengambilan_sertifikat
                    $sqlIns = "
                        INSERT INTO pengambilan_sertifikat (
                            registration_id, nim, nama_mahasiswa, program_studi, nomor_sertifikat,
                            tahun_ajaran, status_pengambilan, tanggal_pengambilan, metode_pengambilan,
                            nama_penerima, hubungan_penerima, catatan, petugas_id, petugas_nama
                        ) VALUES (?, ?, ?, ?, ?, ?, 'Sudah Diambil', NOW(), ?, ?, ?, ?, ?, ?)
                    ";
                    $stmtIns = $pdo->prepare($sqlIns);
                    $stmtIns->execute([
                        $targetCert['id'],
                        $targetCert['nim'],
                        $targetCert['nama_lengkap'],
                        $targetCert['program_studi'],
                        $targetCert['nomor_sertifikat'],
                        $targetCert['tahun_ajaran'] ?? date('Y'),
                        $metode,
                        $namaPenerima,
                        $hubungan,
                        $catatan,
                        $petugasId,
                        $petugasNama
                    ]);

                    // Update flag di tutorial_registrations
                    $stmtUp = $pdo->prepare("
                        UPDATE tutorial_registrations 
                        SET is_sertifikat_diambil = 1, tgl_sertifikat_diambil = NOW()
                        WHERE id = ?
                    ");
                    $stmtUp->execute([$targetCert['id']]);

                    $pdo->commit();

                    $flashMsg = 'Alhamdulillah! Pengambilan sertifikat fisik berhasil dikonfirmasi dan resmi tercatat dalam sistem LPPAI.';
                    $flashType = 'success';
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $flashMsg = 'Gagal mencatat pengambilan: ' . $e->getMessage();
                    $flashType = 'danger';
                }
            }
        }
    }
}

// Cari data sertifikat jika ada parameter
if (!empty($certCode)) {
    // Cari berdasarkan nomor_sertifikat, NIM, atau id registrasi
    $sqlLookup = "
        SELECT tr.id as reg_id, tr.nomor_sertifikat, tr.tahun_ajaran,
               tr.nilai_thaharah, tr.nilai_shalat, tr.nilai_surat_pendek,
               tr.nilai_amaliyah, tr.nilai_jenazah, tr.nilai_ujian_tulis,
               tr.is_sertifikat_diambil, tr.tgl_sertifikat_diambil,
               tr.created_at as tgl_daftar,
               u.id as user_id, u.nim, u.nama_lengkap, u.program_studi, u.fakultas,
               u.tempat_lahir, u.tanggal_lahir, u.no_hp
        FROM tutorial_registrations tr
        JOIN users u ON tr.user_id = u.id
        WHERE (tr.nomor_sertifikat = ? OR u.nim = ? OR tr.id = ?)
          AND tr.nomor_sertifikat IS NOT NULL AND tr.nomor_sertifikat != ''
        ORDER BY tr.id DESC
        LIMIT 1
    ";
    $stmtLookup = $pdo->prepare($sqlLookup);
    $stmtLookup->execute([$certCode, $certCode, is_numeric($certCode) ? (int)$certCode : 0]);
    $cert = $stmtLookup->fetch();

    if ($cert) {
        // Ambil riwayat pengambilan fisik jika ada
        $stmtPick = $pdo->prepare("
            SELECT * FROM pengambilan_sertifikat 
            WHERE nomor_sertifikat = ? OR registration_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmtPick->execute([$cert['nomor_sertifikat'], $cert['reg_id']]);
        $pickupLog = $stmtPick->fetch();
    }
}

// Helper Predikat Nilai
function hitungPredikatHuruf($val) {
    if ($val >= 80) return ['A', 'Memuaskan'];
    if ($val >= 75) return ['B+', 'Sangat Baik'];
    if ($val >= 70) return ['B', 'Baik'];
    if ($val >= 65) return ['C+', 'Cukup Baik'];
    if ($val >= 60) return ['C', 'Cukup'];
    if ($val >= 40) return ['D', 'Kurang'];
    return ['E', 'Sangat Kurang'];
}

function formatTanggalIndo($dateStr) {
    if (empty($dateStr)) return '-';
    $bulanIndo = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $timestamp = strtotime($dateStr);
    if (!$timestamp) return $dateStr;
    $d = date('j', $timestamp);
    $m = $bulanIndo[(int)date('n', $timestamp)];
    $y = date('Y', $timestamp);
    return "$d $m $y";
}

// Cek status kepemilikan bagi user yang sedang login
$isOwner = false;
if ($isLoggedIn && $cert) {
    $isOwner = ($currentUser['id'] == $cert['user_id'] || (!empty($cert['nim']) && $currentUser['nim'] === $cert['nim']));
}

$pageTitle = $cert ? "Sertifikat - " . htmlspecialchars($cert['nama_lengkap']) : "Verifikasi Sertifikat LPPAI UNISDA";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $pageTitle ?></title>
    <link rel="icon" href="<?= BASE_URL ?>/assets/logo.svg" type="image/svg+xml">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Pinyon+Script&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #1e3a8a;
            --primary-dark: #0f172a;
            --primary-light: #3b82f6;
            --accent-gold: #b45309;
            --accent-green: #15803d;
            --bg-page: #f8fafc;
            --card-border: #e2e8f0;
            --text-dark: #0f172a;
            --text-muted: #64748b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-page);
            color: var(--text-dark);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            padding-bottom: 50px;
        }

        /* Top Navbar */
        .site-header {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 12px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .header-inner {
            max-width: 900px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }
        .brand img {
            width: 36px;
            height: 36px;
            object-fit: contain;
        }
        .brand-text h1 {
            font-size: 15px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: 0.2px;
            line-height: 1.2;
        }
        .brand-text p {
            font-size: 11px;
            color: var(--text-muted);
            margin: 0;
            line-height: 1.2;
        }
        .auth-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-nav {
            font-size: 13px;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-nav-outline {
            border: 1px solid #cbd5e1;
            color: #334155;
            background: #ffffff;
        }
        .btn-nav-outline:hover {
            background: #f1f5f9;
        }
        .btn-nav-primary {
            background: var(--primary);
            color: #ffffff;
            border: 1px solid var(--primary);
        }
        .btn-nav-primary:hover {
            background: #1d4ed8;
        }

        /* Container */
        .container {
            max-width: 900px;
            margin: 24px auto;
            padding: 0 16px;
        }

        /* Flash Message */
        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14.5px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .alert-warning {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        /* Verification Badge Top */
        .verify-banner {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: #ffffff;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.2);
        }
        .verify-badge-icon {
            font-size: 36px;
            line-height: 1;
        }
        .verify-badge-text h2 {
            font-size: 17px;
            font-weight: 800;
            letter-spacing: 0.3px;
        }
        .verify-badge-text p {
            font-size: 13px;
            opacity: 0.95;
            margin-top: 2px;
        }

        /* Certificate Preview Card */
        .cert-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 14px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.06);
            overflow: hidden;
            margin-bottom: 24px;
            position: relative;
        }
        .cert-card-header {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .cert-id-tag {
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            background: #ffffff;
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            font-family: monospace;
        }
        .cert-status-tag {
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .tag-taken {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
        }
        .tag-pending {
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        /* Certificate Body Canvas */
        .cert-canvas {
            padding: 30px 24px;
            text-align: center;
            background: #ffffff;
            position: relative;
            background-image: radial-gradient(#f1f5f9 1.5px, transparent 1.5px);
            background-size: 24px 24px;
        }
        .cert-frame {
            border: 2px solid #cbd5e1;
            border-radius: 8px;
            padding: 24px 18px;
            background: #ffffff;
            position: relative;
        }
        .cert-univ-title {
            font-family: 'Cinzel', serif;
            font-size: 13px;
            color: #475569;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        .cert-lppai-title {
            font-size: 15px;
            font-weight: 800;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        .cert-script-title {
            font-family: 'Pinyon Script', cursive;
            font-size: 46px;
            color: var(--accent-gold);
            margin: 12px 0 2px;
            line-height: 1.1;
        }
        .cert-no {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
        }
        .cert-presented {
            font-size: 13.5px;
            color: #64748b;
            font-style: italic;
        }
        .cert-student-name {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin: 6px 0;
        }
        .cert-meta-grid {
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
            font-size: 13px;
            color: #334155;
            margin-bottom: 16px;
        }
        .cert-meta-item strong {
            color: #0f172a;
        }
        .cert-statement {
            font-size: 15px;
            color: #334155;
            line-height: 1.4;
            margin: 10px 0 16px;
        }
        .cert-statement .badge-lulus {
            display: inline-block;
            background: #15803d;
            color: #ffffff;
            font-weight: 800;
            font-size: 18px;
            padding: 3px 18px;
            border-radius: 6px;
            letter-spacing: 2px;
            margin: 4px 0;
        }

        /* Grades Table */
        .grades-box {
            margin: 20px auto;
            max-width: 600px;
        }
        .grades-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
            background: #ffffff;
        }
        .grades-table th, .grades-table td {
            border: 1px solid #cbd5e1;
            padding: 7px 10px;
            text-align: center;
        }
        .grades-table th {
            background: #f1f5f9;
            color: #1e293b;
            font-weight: 700;
        }
        .grades-table td {
            color: #334155;
            font-weight: 600;
        }
        .grades-table .highlight-score {
            background: #eff6ff;
            color: var(--primary);
            font-weight: 800;
        }

        /* Signatory */
        .cert-sign {
            margin-top: 24px;
            display: flex;
            justify-content: flex-end;
            text-align: center;
        }
        .cert-sign-inner {
            min-width: 200px;
        }
        .cert-sign-date {
            font-size: 13px;
            color: #475569;
        }
        .cert-sign-role {
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 45px;
        }
        .cert-sign-name {
            font-size: 14.5px;
            font-weight: 800;
            color: #0f172a;
            text-decoration: underline;
        }

        /* Action Cards Section */
        .action-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }
        .action-card h3 {
            font-size: 17px;
            font-weight: 800;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .action-card p {
            font-size: 14px;
            color: #475569;
            line-height: 1.5;
            margin-bottom: 16px;
        }

        /* Pickup Box for Owner */
        .pickup-owner-panel {
            background: linear-gradient(to bottom right, #f0fdf4, #ecfdf5);
            border: 1.5px solid #86efac;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }
        .owner-badge-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #15803d;
            color: #ffffff;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .form-group {
            margin-bottom: 14px;
            text-align: left;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            background: #ffffff;
            transition: border-color 0.2s;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .btn-confirm {
            background: #059669;
            color: #ffffff;
            border: none;
            padding: 14px 20px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
        }
        .btn-confirm:hover {
            background: #047857;
            transform: translateY(-1px);
        }

        /* Pickup Info for Taken */
        .pickup-detail-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
            margin: 12px 0 16px;
        }
        .pickup-detail-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        .pickup-detail-table td:first-child {
            width: 35%;
            color: #64748b;
            font-weight: 600;
        }
        .pickup-detail-table td:last-child {
            color: #0f172a;
            font-weight: 700;
        }

        /* Search Section */
        .search-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
        }
        .search-form {
            display: flex;
            gap: 10px;
            max-width: 500px;
            margin: 14px auto 0;
        }
        .search-input {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }
        .search-btn {
            background: var(--primary);
            color: #ffffff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
        }

        /* Footer */
        .page-footer {
            text-align: center;
            margin-top: 40px;
            font-size: 12.5px;
            color: #94a3b8;
        }

        @media (max-width: 600px) {
            .cert-canvas {
                padding: 16px 10px;
            }
            .cert-frame {
                padding: 16px 10px;
            }
            .cert-script-title {
                font-size: 36px;
            }
            .cert-student-name {
                font-size: 20px;
            }
            .search-form {
                flex-direction: column;
            }
            .cert-sign {
                justify-content: center;
            }
        }
    </style>
</head>
<body>

    <!-- Header Navbar -->
    <header class="site-header">
        <div class="header-inner">
            <a href="<?= BASE_URL ?>/sertifikat.php" class="brand">
                <img src="<?= BASE_URL ?>/assets/logo.svg" alt="LPPAI Logo">
                <div class="brand-text">
                    <h1>LPPAI UNISDA</h1>
                    <p>Verifikasi & Pengambilan Sertifikat</p>
                </div>
            </a>
            <div class="auth-actions">
                <?php if ($isLoggedIn): ?>
                    <a href="<?= $isAdminUser ? BASE_URL . '/admin/dashboard.php' : BASE_URL . '/dashboard.php' ?>" class="btn-nav btn-nav-outline">
                        🏠 Dashboard
                    </a>
                    <a href="<?= BASE_URL ?>/pages/logout.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn-nav btn-nav-outline" title="Keluar">
                        🚪 Logout
                    </a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/index.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn-nav btn-nav-primary">
                        🔑 Login
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <div class="container">
        
        <!-- Notifikasi Flash -->
        <?php if ($flashMsg): ?>
            <div class="alert alert-<?= $flashType ?>">
                <span style="font-size:20px;"><?= $flashType === 'success' ? '✅' : '⚠️' ?></span>
                <div><?= sanitize($flashMsg) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($cert): ?>
            <!-- JIKA SERTIFIKAT DITEMUKAN -->

            <?php
                // Hitung Nilai & Predikat
                $th = (float)$cert['nilai_thaharah'];
                $sh = (float)$cert['nilai_shalat'];
                $sp = (float)$cert['nilai_surat_pendek'];
                $am = (float)$cert['nilai_amaliyah'];
                $jn = (float)$cert['nilai_jenazah'];
                $ut = (float)$cert['nilai_ujian_tulis'];
                
                $rataAkhir = round(($th + $sh + $sp + $am + $jn) / 5, 2);
                list($hurufAkhir, $predikatTeks) = hitungPredikatHuruf($rataAkhir);

                $ttlLahir = '-';
                if ($cert['tempat_lahir'] && $cert['tanggal_lahir']) {
                    $ttlLahir = ucwords(strtolower($cert['tempat_lahir'])) . ', ' . formatTanggalIndo($cert['tanggal_lahir']);
                } elseif ($cert['tanggal_lahir']) {
                    $ttlLahir = formatTanggalIndo($cert['tanggal_lahir']);
                }
            ?>

            <!-- Top Verified Badge -->
            <div class="verify-banner">
                <div class="verify-badge-icon">🛡️</div>
                <div class="verify-badge-text">
                    <h2>SERTIFIKAT RESMI & TERVERIFIKASI</h2>
                    <p>Dokumen ini diterbitkan sah oleh Lembaga Pengembangan Pendidikan Agama Islam (LPPAI) Universitas Islam Darul 'Ulum Lamongan.</p>
                </div>
            </div>

            <!-- Tampilan Sertifikat -->
            <div class="cert-card">
                <div class="cert-card-header">
                    <div>
                        <span style="font-size:12px; color:#64748b; font-weight:600;">No. Registrasi:</span>
                        <span class="cert-id-tag">#REG-<?= $cert['reg_id'] ?></span>
                    </div>
                    <div>
                        <?php if ($cert['is_sertifikat_diambil'] == 1): ?>
                            <span class="cert-status-tag tag-taken">✅ SUDAH DIAMBIL</span>
                        <?php else: ?>
                            <span class="cert-status-tag tag-pending">📦 BELUM DIAMBIL (SIAP AMBIL)</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="cert-canvas">
                    <div class="cert-frame">
                        <div class="cert-univ-title">Universitas Islam Darul 'Ulum Lamongan</div>
                        <div class="cert-lppai-title">Lembaga Pengembangan Pendidikan Agama Islam</div>
                        
                        <div class="cert-script-title">Sertifikat Kelulusan</div>
                        <div class="cert-no">Nomor: <?= htmlspecialchars($cert['nomor_sertifikat']) ?></div>

                        <div class="cert-presented">Diberikan kepada:</div>
                        <div class="cert-student-name"><?= htmlspecialchars($cert['nama_lengkap']) ?></div>

                        <div class="cert-meta-grid">
                            <div class="cert-meta-item">NIM: <strong><?= htmlspecialchars($cert['nim']) ?></strong></div>
                            <div class="cert-meta-item">Program Studi: <strong><?= htmlspecialchars($cert['program_studi'] ?: '-') ?></strong></div>
                            <?php if (!empty($cert['fakultas'])): ?>
                                <div class="cert-meta-item">Fakultas: <strong><?= htmlspecialchars($cert['fakultas']) ?></strong></div>
                            <?php endif; ?>
                            <div class="cert-meta-item">TTL: <strong><?= htmlspecialchars($ttlLahir) ?></strong></div>
                        </div>

                        <div class="cert-statement">
                            Telah mengikuti dan dinyatakan:<br>
                            <span class="badge-lulus">LULUS</span><br>
                            dengan Predikat: <strong><?= $predikatTeks ?> (Nilai <?= $hurufAkhir ?>)</strong><br>
                            <span style="font-size:13.5px; color:#475569;">
                                dalam Program <em>"Daurah Keislaman"</em> LPPAI UNISDA Lamongan.
                            </span>
                        </div>

                        <!-- Tabel Nilai -->
                        <div class="grades-box">
                            <table class="grades-table">
                                <thead>
                                    <tr>
                                        <th>Thaharah</th>
                                        <th>Shalat</th>
                                        <th>Srt. Pendek</th>
                                        <th>Amaliyah</th>
                                        <th>Jenazah</th>
                                        <th class="highlight-score">Nilai Akhir</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><?= number_format($th, 2) ?></td>
                                        <td><?= number_format($sh, 2) ?></td>
                                        <td><?= number_format($sp, 2) ?></td>
                                        <td><?= number_format($am, 2) ?></td>
                                        <td><?= number_format($jn, 2) ?></td>
                                        <td class="highlight-score"><?= number_format($rataAkhir, 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?= hitungPredikatHuruf($th)[0] ?></strong></td>
                                        <td><strong><?= hitungPredikatHuruf($sh)[0] ?></strong></td>
                                        <td><strong><?= hitungPredikatHuruf($sp)[0] ?></strong></td>
                                        <td><strong><?= hitungPredikatHuruf($am)[0] ?></strong></td>
                                        <td><strong><?= hitungPredikatHuruf($jn)[0] ?></strong></td>
                                        <td class="highlight-score"><strong><?= $hurufAkhir ?></strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Tanda Tangan -->
                        <div class="cert-sign">
                            <div class="cert-sign-inner">
                                <div class="cert-sign-date">Lamongan, <?= formatTanggalIndo(date('Y-m-d')) ?></div>
                                <div class="cert-sign-role">Kepala LPPAI,</div>
                                <div class="cert-sign-name">Dr. Zainul Hakim, M.H.I.</div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- BAGIAN STATUS & PENGAMBILAN SERTIFIKAT -->
            <?php if ($cert['is_sertifikat_diambil'] == 1): ?>
                <!-- JIKA SUDAH DIAMBIL -->
                <div class="action-card" style="border-left: 5px solid #10b981;">
                    <h3>✅ Informasi Pengambilan Sertifikat Fisik</h3>
                    <p>Sertifikat fisik telah resmi diserahkan dan tercatat dalam sistem LPPAI UNISDA dengan rincian sebagai berikut:</p>
                    
                    <table class="pickup-detail-table">
                        <tr>
                            <td>Status Fisik</td>
                            <td><span style="color:#15803d; font-weight:800;">SUDAH DIAMBIL</span></td>
                        </tr>
                        <tr>
                            <td>Waktu Pengambilan</td>
                            <td><?= !empty($cert['tgl_sertifikat_diambil']) ? date('d F Y, H:i', strtotime($cert['tgl_sertifikat_diambil'])) . ' WIB' : '-' ?></td>
                        </tr>
                        <?php if ($pickupLog): ?>
                            <tr>
                                <td>Nama Penerima</td>
                                <td><?= htmlspecialchars($pickupLog['nama_penerima'] ?: $cert['nama_lengkap']) ?></td>
                            </tr>
                            <tr>
                                <td>Hubungan Penerima</td>
                                <td><?= htmlspecialchars($pickupLog['hubungan_penerima'] ?: 'Sendiri') ?></td>
                            </tr>
                            <tr>
                                <td>Metode Pengambilan</td>
                                <td><?= htmlspecialchars($pickupLog['metode_pengambilan'] ?: 'Scan QR') ?></td>
                            </tr>
                            <?php if (!empty($pickupLog['petugas_nama'])): ?>
                                <tr>
                                    <td>Dicatat Oleh</td>
                                    <td><?= htmlspecialchars($pickupLog['petugas_nama']) ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if (!empty($pickupLog['catatan'])): ?>
                                <tr>
                                    <td>Catatan</td>
                                    <td><?= htmlspecialchars($pickupLog['catatan']) ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endif; ?>
                    </table>

                    <?php if ($isOwner || $isAdminUser): ?>
                        <div style="margin-top: 16px;">
                            <?php 
                                $ttId = $pickupLog['id'] ?? 0;
                                $ttUrl = $ttId > 0 
                                    ? BASE_URL . '/admin/cetak-tanda-terima-sertifikat.php?id=' . $ttId
                                    : BASE_URL . '/admin/cetak-tanda-terima-sertifikat.php?no=' . urlencode($cert['nomor_sertifikat']);
                            ?>
                            <a href="<?= $ttUrl ?>" target="_blank" class="btn-nav btn-nav-primary" style="padding:10px 18px; font-size:14px;">
                                🖨️ Lihat / Cetak Tanda Terima Pengambilan
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- JIKA BELUM DIAMBIL (DUAL PURPOSE QR SCANNER) -->

                <?php if ($isOwner): ?>
                    <!-- KONDISI 1: SUDAH LOGIN SEBAGAI PEMILIK SAH SERTIFIKAT -->
                    <div class="pickup-owner-panel">
                        <div class="owner-badge-chip">🎓 PEMILIK SAH TERVERIFIKASI</div>
                        <h3 style="font-size:19px; font-weight:800; color:#065f46; margin-bottom:6px;">
                            Halo, <?= htmlspecialchars($currentUser['nama_lengkap']) ?>!
                        </h3>
                        <p style="font-size:14px; color:#047857; margin-bottom:18px;">
                            Sertifikat ini terdaftar atas nama dan NIM Anda (<strong><?= htmlspecialchars($currentUser['nim']) ?></strong>). 
                            Jika Anda saat ini sedang mengambil sertifikat fisik di loket/kantor LPPAI, silakan konfirmasi pengambilan di bawah ini:
                        </p>

                        <form method="POST" action="" onsubmit="return confirm('Apakah Anda yakin ingin mengonfirmasi bahwa Anda telah menerima fisik sertifikat ini?');">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="confirm_pickup">
                            <input type="hidden" name="reg_id" value="<?= $cert['reg_id'] ?>">

                            <div class="form-group">
                                <label>Nama Pengambil / Penerima:</label>
                                <input type="text" name="nama_penerima" class="form-control" value="<?= htmlspecialchars($currentUser['nama_lengkap']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label>Status / Hubungan Pengambil:</label>
                                <select name="hubungan_penerima" class="form-control">
                                    <option value="Sendiri (Pemilik Sertifikat)" selected>Saya Sendiri (Pemilik Sertifikat)</option>
                                    <option value="Diwakilkan - Keluarga / Wali">Diwakilkan (Keluarga / Wali)</option>
                                    <option value="Diwakilkan - Teman / Rekan Mahasiswa">Diwakilkan (Teman Seangkatan)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Catatan Tambahan (Opsional):</label>
                                <input type="text" name="catatan" class="form-control" placeholder="Contoh: Diambil langsung di loket LPPAI">
                            </div>

                            <button type="submit" class="btn-confirm">
                                <span>✅</span>
                                <span>Konfirmasi Saya Sudah Menerima Sertifikat Ini</span>
                            </button>
                        </form>
                    </div>

                <?php elseif ($isAdminUser): ?>
                    <!-- KONDISI 2: LOGIN SEBAGAI ADMIN / PETUGAS LPPAI -->
                    <div class="action-card" style="border-left: 5px solid #3b82f6; background:#eff6ff;">
                        <h3 style="color:#1e40af;">🛡️ Mode Administrator / Petugas LPPAI</h3>
                        <p style="color:#1e3a8a;">
                            Anda login sebagai <strong><?= htmlspecialchars($currentUser['nama_lengkap']) ?></strong> (Petugas/Admin).
                            Sertifikat fisik mahasiswa ini belum tercatat diambil. Anda dapat mencatat pengambilan secara langsung:
                        </p>
                        
                        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:14px;">
                            <a href="<?= BASE_URL ?>/admin/pengambilan-sertifikat.php?code=<?= urlencode($cert['nomor_sertifikat']) ?>" class="btn-nav btn-nav-primary" style="padding:10px 18px; font-size:14px;">
                                ⚡ Buka di Panel Pengambilan Admin (Lengkap dengan Tanda Tangan Digital)
                            </a>
                        </div>
                    </div>

                <?php elseif ($isLoggedIn): ?>
                    <!-- KONDISI 3: LOGIN DENGAN AKUN MAHASISWA LAIN (BUKAN PEMILIK) -->
                    <div class="action-card" style="border-left: 5px solid #f59e0b; background:#fffbeb;">
                        <h3 style="color:#92400e;">⚠️ Akun Tidak Cocok</h3>
                        <p style="color:#78350f;">
                            Anda saat ini login sebagai <strong><?= htmlspecialchars($currentUser['nama_lengkap']) ?></strong> (NIM: <?= htmlspecialchars($currentUser['nim']) ?>).
                            Sertifikat ini adalah milik mahasiswa <strong><?= htmlspecialchars($cert['nama_lengkap']) ?></strong> (NIM: <?= htmlspecialchars($cert['nim']) ?>).
                        </p>
                        <p style="color:#78350f; font-size:13.5px;">
                            Untuk melakukan konfirmasi pengambilan sertifikat ini, Anda harus login menggunakan akun mahasiswa pemilik sah sertifikat.
                        </p>
                        <div style="margin-top:14px;">
                            <a href="<?= BASE_URL ?>/pages/logout.php?redirect=<?= urlencode('sertifikat.php?no=' . $cert['nomor_sertifikat']) ?>" class="btn-nav btn-nav-primary">
                                🔄 Ganti Akun & Login Pemilik Sertifikat
                            </a>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- KONDISI 4: PENGUNJUNG PUBLIK (BELUM LOGIN) -->
                    <div class="action-card" style="border-left: 5px solid #3b82f6;">
                        <h3>📦 Ingin Mengambil Sertifikat Ini?</h3>
                        <p>
                            Sertifikat fisik atas nama <strong><?= htmlspecialchars($cert['nama_lengkap']) ?></strong> (NIM: <?= htmlspecialchars($cert['nim']) ?>) 
                            saat ini <strong>Belum Diambil</strong> dan tersedia di Kantor LPPAI (Gedung A UNISDA Lamongan).
                        </p>
                        <p style="font-size:13.5px; color:#64748b;">
                            Bagi mahasiswa pemilik sertifikat, Anda dapat mencatat dan mengonfirmasi pengambilan secara mandiri dengan login ke akun Anda.
                        </p>
                        
                        <div style="margin-top:16px;">
                            <a href="<?= BASE_URL ?>/index.php?redirect=<?= urlencode('sertifikat.php?no=' . $cert['nomor_sertifikat']) ?>" class="btn-nav btn-nav-primary" style="padding:12px 20px; font-size:14.5px;">
                                🔑 Login Akun Mahasiswa untuk Konfirmasi Pengambilan
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        <?php else: ?>
            <!-- JIKA TIDAK ADA PARAMETER ATAU SERTIFIKAT TIDAK DITEMUKAN -->
            <div class="search-card">
                <div style="font-size:52px; margin-bottom:12px;">🔍</div>
                <?php if (!empty($certCode)): ?>
                    <h2 style="font-size:20px; color:#b91c1c; margin-bottom:8px;">Sertifikat Tidak Ditemukan</h2>
                    <p style="font-size:14px; color:#64748b; max-width:500px; margin:0 auto 16px;">
                        Data sertifikat dengan nomor atau kata kunci "<strong><?= htmlspecialchars($certCode) ?></strong>" tidak ditemukan dalam database atau belum diterbitkan.
                    </p>
                <?php else: ?>
                    <h2 style="font-size:20px; color:#1e293b; margin-bottom:8px;">Verifikasi Keaslian Sertifikat LPPAI</h2>
                    <p style="font-size:14px; color:#64748b; max-width:500px; margin:0 auto 16px;">
                        Masukkan nomor sertifikat atau NIM mahasiswa pada kolom di bawah untuk memverifikasi keabsahan dokumen kelulusan LPPAI UNISDA.
                    </p>
                <?php endif; ?>

                <form method="GET" action="" class="search-form">
                    <input type="text" name="no" class="search-input" placeholder="Masukkan Nomor Sertifikat atau NIM..." value="<?= htmlspecialchars($certCode) ?>" required>
                    <button type="submit" class="search-btn">Verifikasi</button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <footer class="page-footer">
            &copy; <?= date('Y') ?> LPPAI Universitas Islam Darul 'Ulum Lamongan. Semua Hak Dilindungi.
        </footer>
    </div>

</body>
</html>
