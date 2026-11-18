<?php
/**
 * LPPAI Corner - Admin: Export Dokumen PDF Data Peserta Pretes
 * Format Resmi Cetak / Simpan PDF (A4 Portrait)
 * Berisi kolom: No, NIM, Nama Mahasiswa, Program (RPL / Non RPL), Jurusan (Program Studi), Nilai Pretes
 * Filter hari bisa lebih dari satu: hari=Senin,Rabu
 */

define('PAGE_TITLE', 'Export Data Peserta Pretes ke PDF');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
require_once __DIR__ . '/../includes/pretes-helper.php';
ensurePretesPeriodTargetColumns($pdo);
require_once __DIR__ . '/../includes/user-helper.php';

// Ambil periode pretes aktif dan daftar opsi periode
$activeExportPretes = getActivePretesPeriod($pdo);
$rawFilterPeriode = trim($_GET['periode'] ?? ($activeExportPretes['periode'] ?? ''));
$filterPeriode = ($rawFilterPeriode === '__all__' || strtolower($rawFilterPeriode) === 'all') ? '' : $rawFilterPeriode;

// Hari multi-pilih ("Senin,Rabu" atau hari[]=...); hanya Senin-Jumat yang diterima
$filterHariList = pretesParseHariFilter($_GET['hari'] ?? '');
$filterHari   = implode(', ', $filterHariList);
$filterProdi  = trim($_GET['prodi'] ?? '');
$filterJenis  = trim($_GET['jenis_program'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterCred   = trim($_GET['cred'] ?? '');
$filterSearch = trim($_GET['q'] ?? ($_GET['nama'] ?? ($_GET['nim'] ?? '')));

// Kueri filter data dinamis
$whereConditions = ["1=1"];
$params = [];

// Kecualikan akun percobaan / uji coba (username atau NIM 12345) dari dokumen export
$whereConditions[] = "LOWER(TRIM(COALESCE(u.username, ''))) <> '12345'";
$whereConditions[] = "TRIM(COALESCE(u.nim, '')) <> '12345'";

if ($filterPeriode !== '') {
    $whereConditions[] = "pr.periode = ?";
    $params[] = $filterPeriode;
}

if ($filterHariList) {
    $whereConditions[] = "LOWER(TRIM(pr.hari_pilihan)) IN (" . implode(',', array_fill(0, count($filterHariList), 'LOWER(?)')) . ")";
    foreach ($filterHariList as $hariItem) $params[] = $hariItem;
}

if ($filterProdi !== '') {
    $whereConditions[] = "u.program_studi = ?";
    $params[] = $filterProdi;
}

if ($filterJenis !== '') {
    $whereConditions[] = "u.jenis_program = ?";
    $params[] = normalizeJenisProgram($filterJenis);
}

if ($filterStatus !== '') {
    $whereConditions[] = "pr.status = ?";
    $params[] = $filterStatus;
}

if ($filterCred === 'has_cred') {
    $whereConditions[] = "(pr.username_tes IS NOT NULL AND pr.username_tes != '')";
} elseif ($filterCred === 'no_cred') {
    $whereConditions[] = "(pr.username_tes IS NULL OR pr.username_tes = '')";
}

if ($filterSearch !== '') {
    $whereConditions[] = "(u.nama_lengkap LIKE ? OR u.nim LIKE ? OR pr.username_tes LIKE ?)";
    $like = '%' . $filterSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $whereConditions);

$querySql = "
    SELECT pr.id as reg_id, pr.user_id, pr.periode, pr.hari_pilihan, pr.jam, pr.ruangan, pr.dosen_penguji,
           pr.status, pr.tanggal_daftar,
           u.nama_lengkap, u.nim, u.program_studi, u.jenis_program, u.fakultas
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    WHERE {$whereSql}
    ORDER BY 
        u.program_studi ASC,
        CASE WHEN UPPER(TRIM(COALESCE(u.jenis_program, ''))) = 'RPL' THEN 1 ELSE 2 END,
        u.nama_lengkap ASC
";

$stmt = $pdo->prepare($querySql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPeserta = count($rows);
// Nilai hasil pretes resmi (CBT resmi / cadangan ujian_tulis); belum pretes -> "-"
$officialScores = pretesOfficialScoresByRegistration($pdo, array_column($rows, 'reg_id'));
$scoredCount = 0;
$passCount = 0;
foreach ($rows as $r) {
    $os = $officialScores[(int)$r['reg_id']] ?? null;
    if ($os) { $scoredCount++; if ($os['score'] >= 80) $passCount++; }
}
$rplCount = 0;
$nonRplCount = 0;
$prodiStats = [];

foreach ($rows as $r) {
    $isRpl = (normalizeJenisProgram($r['jenis_program'] ?? 'Reguler') === 'RPL');
    if ($isRpl) {
        $rplCount++;
    } else {
        $nonRplCount++;
    }
    $ps = trim($r['program_studi'] ?? '') ?: 'Belum Terdata';
    $prodiStats[$ps] = ($prodiStats[$ps] ?? 0) + 1;
}

// Format tanggal cetak saat ini (WIB)
$dtNow = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$indonesianMonths = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$printDateFormatted = $dtNow->format('j') . ' ' . $indonesianMonths[(int)$dtNow->format('n')] . ' ' . $dtNow->format('Y') . ', ' . $dtNow->format('H:i') . ' WIB';
$signatureDate = $dtNow->format('j') . ' ' . $indonesianMonths[(int)$dtNow->format('n')] . ' ' . $dtNow->format('Y');

$backUrl = 'pretes-peserta.php' . ($rawFilterPeriode !== '' ? '?periode=' . urlencode($rawFilterPeriode) : '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Peserta Pretes - <?= htmlspecialchars($filterPeriode ?: 'Semua Periode', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="<?= BASE_URL ?>/assets/logo.svg" type="image/svg+xml">
    <style>
        /* ── Reset & Pengaturan Dasar Dokumen Cetak ── */
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            margin: 0;
            padding: 0;
            background: #e2e8f0;
            color: #0f172a;
            font-family: Arial, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, sans-serif;
            font-size: 10pt;
            line-height: 1.4;
        }

        /* ── Toolbar Aksi Layar (Hidden Saat Cetak) ── */
        .toolbar-screen {
            background: #ffffff;
            border-bottom: 1.5px solid #cbd5e1;
            padding: 12px 24px;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .toolbar-screen .actions-left,
        .toolbar-screen .actions-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn-toolbar {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
        }
        .btn-back {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
        }
        .btn-back:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .btn-print {
            background: #dc2626;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(220,38,38,0.3);
        }
        .btn-print:hover {
            background: #b91c1c;
        }
        .badge-info-stat {
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        /* ── Lembar Dokumen A4 ── */
        .page-sheet {
            width: 210mm;
            min-height: 297mm;
            padding: 15mm 15mm 20mm 15mm;
            margin: 20px auto;
            background: #ffffff;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            position: relative;
        }

        /* ── Kop Surat LPPAI Resmi ── */
        .kop-surat {
            display: flex;
            align-items: center;
            gap: 16px;
            padding-bottom: 12px;
            border-bottom: 3.5px double #0f172a;
            margin-bottom: 18px;
        }
        .kop-logo {
            width: 75px;
            height: 75px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .kop-body {
            flex: 1;
            text-align: center;
        }
        .kop-institution {
            font-size: 13pt;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.25;
        }
        .kop-university {
            font-size: 11.5pt;
            font-weight: 700;
            color: #15583c;
            margin: 3px 0 0 0;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .kop-address {
            font-size: 8.5pt;
            color: #475569;
            margin: 4px 0 0 0;
            line-height: 1.35;
        }

        /* ── Judul Dokumen ── */
        .doc-title-box {
            text-align: center;
            margin-bottom: 16px;
        }
        .doc-title {
            font-size: 13pt;
            font-weight: 800;
            text-transform: uppercase;
            color: #0f172a;
            margin: 0 0 4px 0;
            letter-spacing: 0.8px;
            text-decoration: underline;
        }
        .doc-subtitle {
            font-size: 9.5pt;
            color: #334155;
            margin: 0;
            font-weight: 600;
        }

        /* ── Kotak Metadata & Filter ── */
        .meta-box {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 20px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 8.5pt;
            margin-bottom: 16px;
        }
        .meta-item {
            display: flex;
            align-items: baseline;
            gap: 6px;
        }
        .meta-label {
            font-weight: 700;
            color: #475569;
            min-width: 95px;
        }
        .meta-value {
            color: #0f172a;
            font-weight: 600;
        }

        /* ── Tabel Data Peserta (5 Kolom: No, NIM, Nama, Program, Jurusan) ── */
        table.peserta-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
            table-layout: fixed;
            margin-bottom: 20px;
        }
        table.peserta-table th,
        table.peserta-table td {
            border: 1px solid #475569;
            padding: 6px 8px;
            vertical-align: middle;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        table.peserta-table th {
            background: #f1f5f9;
            color: #0f172a;
            font-weight: 800;
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            text-align: center;
        }
        table.peserta-table tr:nth-child(even) td {
            background: #fafbfc;
        }
        .col-no {
            width: 5%;
            text-align: center;
            font-weight: 600;
        }
        .col-nim {
            width: 15%;
            text-align: center;
            font-family: Consolas, "Courier New", monospace;
            font-size: 9.5pt;
            font-weight: 700;
            color: #0f172a;
        }
        .col-nama {
            width: 31%;
            text-align: left;
            font-weight: 600;
            color: #0f172a;
        }
        .col-program {
            width: 15%;
            text-align: center;
        }
        .col-jurusan {
            width: 21%;
            text-align: left;
            color: #1e293b;
        }
        .col-nilai {
            width: 13%;
            text-align: center;
            white-space: nowrap;
        }
        .nilai-angka {
            display: block;
            font-size: 10.5pt;
            font-weight: 800;
        }
        .nilai-ket {
            display: block;
            font-size: 7.5pt;
            font-weight: 700;
        }
        .nilai-lulus { color: #15803d; }
        .nilai-gagal { color: #b91c1c; }
        .nilai-kosong { color: #94a3b8; font-weight: 700; }

        /* ── Badge Program RPL / Non RPL ── */
        .badge-program {
            display: inline-block;
            padding: 2.5px 8px;
            border-radius: 4px;
            font-size: 8pt;
            font-weight: 800;
            letter-spacing: 0.2px;
            text-align: center;
            white-space: nowrap;
        }
        .badge-rpl {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #93c5fd;
        }
        .badge-non-rpl {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
        }

        /* ── Rekapitulasi & Tanda Tangan Dokumen ── */
        .doc-footer-area {
            margin-top: 24px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            page-break-inside: avoid;
            break-inside: avoid;
            font-size: 9pt;
        }
        .rekap-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 10px 14px;
            width: 48%;
            line-height: 1.6;
        }
        .rekap-box strong {
            color: #0f172a;
        }
        .signature-box {
            width: 45%;
            text-align: center;
            line-height: 1.4;
        }
        .signature-title {
            margin-bottom: 55px;
        }
        .signature-name {
            font-weight: 800;
            text-decoration: underline;
            color: #0f172a;
        }
        .signature-role {
            font-size: 8.5pt;
            color: #475569;
        }

        /* ── Aturan Cetak Print / PDF ── */
        @page {
            size: A4 portrait;
            margin: 12mm 10mm 12mm 10mm;
        }
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
                font-size: 9pt;
            }
            .toolbar-screen {
                display: none !important;
            }
            .page-sheet {
                width: 100% !important;
                min-height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
            }
            table.peserta-table {
                font-size: 8.5pt;
            }
            table.peserta-table th,
            table.peserta-table td {
                border: 1px solid #000000 !important;
                padding: 5px 6px;
            }
            table.peserta-table th {
                background: #f1f5f9 !important;
            }
            thead {
                display: table-header-group;
            }
            tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .badge-rpl {
                background: #dbeafe !important;
                color: #1e3a8a !important;
                border: 1px solid #1e3a8a !important;
            }
            .badge-non-rpl {
                background: #f1f5f9 !important;
                color: #0f172a !important;
                border: 1px solid #64748b !important;
            }
            .rekap-box, .meta-box {
                border: 1px solid #000000 !important;
                background: #ffffff !important;
            }
            .doc-footer-area {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>

    <!-- Toolbar Layar (Khusus Browser) -->
    <div class="toolbar-screen">
        <div class="actions-left">
            <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-toolbar btn-back">
                ← Kembali ke Data Peserta
            </a>
            <span class="badge-info-stat">
                👥 Total: <?= $totalPeserta ?> Peserta
            </span>
            <span class="badge-info-stat" style="background:#eff6ff;color:#1e40af;border-color:#93c5fd;">
                🎓 RPL: <?= $rplCount ?>
            </span>
            <span class="badge-info-stat" style="background:#f1f5f9;color:#334155;border-color:#cbd5e1;">
                🏛️ Non RPL (Reguler): <?= $nonRplCount ?>
            </span>
        </div>

        <div class="actions-right">
            <button type="button" class="btn-toolbar btn-print" onclick="window.print()">
                🖨️ Cetak / Simpan PDF
            </button>
        </div>
    </div>

    <!-- Lembar Dokumen A4 -->
    <main class="page-sheet">

        <!-- Kop Surat LPPAI -->
        <header class="kop-surat">
            <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo LPPAI" class="kop-logo">
            <div class="kop-body">
                <h1 class="kop-institution">Lembaga Pembinaan dan Pengembangan Agama Islam (LPPAI)</h1>
                <h2 class="kop-university">Universitas Islam Darul 'Ulum Lamongan (UNISDA)</h2>
                <p class="kop-address">
                    Jl. Airlangga No. 03 Sukodadi, Lamongan - Jawa Timur 62253<br>
                    Website: https://lppai.quizb.my.id &nbsp;•&nbsp; Email: lppai@unisda.ac.id
                </p>
            </div>
        </header>

        <!-- Judul Dokumen -->
        <div class="doc-title-box">
            <h2 class="doc-title">Daftar Peserta Pretes Mahasiswa</h2>
            <p class="doc-subtitle">
                Periode: <strong><?= htmlspecialchars($filterPeriode ?: 'Semua Periode Pretes', ENT_QUOTES, 'UTF-8') ?></strong>
            </p>
        </div>

        <!-- Metadata & Keterangan Filter -->
        <div class="meta-box">
            <div class="meta-item">
                <span class="meta-label">Periode:</span>
                <span class="meta-value"><?= htmlspecialchars($filterPeriode ?: 'Seluruh Periode Terdata', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Total Peserta:</span>
                <span class="meta-value"><strong><?= $totalPeserta ?></strong> Mahasiswa (<?= $rplCount ?> RPL, <?= $nonRplCount ?> Non RPL)</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Filter Jurusan:</span>
                <span class="meta-value"><?= htmlspecialchars($filterProdi ?: 'Semua Jurusan / Prodi', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Filter Program:</span>
                <span class="meta-value"><?= htmlspecialchars($filterJenis ?: 'Semua Program (RPL & Non RPL)', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php if ($filterHari !== '' || $filterStatus !== '' || $filterSearch !== ''): ?>
                <div class="meta-item">
                    <span class="meta-label">Filter Lainnya:</span>
                    <span class="meta-value">
                        <?= htmlspecialchars(implode(' • ', array_filter([
                            $filterHari ? "Hari $filterHari" : '',
                            $filterStatus ? "Status " . ucfirst($filterStatus) : '',
                            $filterSearch ? "Cari '$filterSearch'" : ''
                        ])), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
            <?php endif; ?>
            <div class="meta-item">
                <span class="meta-label">Tanggal Cetak:</span>
                <span class="meta-value"><?= $printDateFormatted ?></span>
            </div>
        </div>

        <!-- Tabel 6 Kolom: No, NIM, Nama Mahasiswa, Program (RPL / Non RPL), Jurusan, Nilai Pretes -->
        <table class="peserta-table">
            <thead>
                <tr>
                    <th class="col-no">No.</th>
                    <th class="col-nim">NIM</th>
                    <th class="col-nama">Nama Mahasiswa</th>
                    <th class="col-program">Program</th>
                    <th class="col-jurusan">Jurusan / Program Studi</th>
                    <th class="col-nilai">Nilai Pretes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:35px 20px; color:#64748b;">
                            <em>Tidak ada data peserta pretes yang sesuai dengan kriteria / filter yang dipilih.</em>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $idx => $row): ?>
                        <?php 
                            $isRpl = (normalizeJenisProgram($row['jenis_program'] ?? 'Reguler') === 'RPL');
                        ?>
                        <tr>
                            <td class="col-no"><?= $idx + 1 ?></td>
                            <td class="col-nim"><?= htmlspecialchars($row['nim'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="col-nama">
                                <?= adminUserProfileLink($row['nama_lengkap'], $row['user_id']) ?>
                            </td>
                            <td class="col-program">
                                <?php if ($isRpl): ?>
                                    <span class="badge-program badge-rpl">RPL</span>
                                <?php else: ?>
                                    <span class="badge-program badge-non-rpl">Non RPL (Reguler)</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-jurusan"><?= htmlspecialchars($row['program_studi'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                            <?php $os = $officialScores[(int)$row['reg_id']] ?? null; ?>
                            <td class="col-nilai">
                                <?php if ($os): ?>
                                    <?php $osPass = $os['score'] >= 80; ?>
                                    <span class="nilai-angka <?= $osPass ? 'nilai-lulus' : 'nilai-gagal' ?>"><?= htmlspecialchars(pretesFormatScore($os['score']), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="nilai-ket <?= $osPass ? 'nilai-lulus' : 'nilai-gagal' ?>"><?= $osPass ? 'Lulus' : 'Tidak lulus' ?></span>
                                <?php else: ?>
                                    <span class="nilai-kosong">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Area Rekapitulasi & Tanda Tangan -->
        <div class="doc-footer-area">
            <div class="rekap-box">
                <strong>Rekapitulasi Peserta Pretes:</strong><br>
                • Total Peserta: <strong><?= $totalPeserta ?></strong> orang<br>
                • Program RPL: <strong><?= $rplCount ?></strong> orang<br>
                • Program Non RPL (Reguler): <strong><?= $nonRplCount ?></strong> orang<br>
                • Sudah Pretes (bernilai): <strong><?= $scoredCount ?></strong> orang (Lulus ≥ 80: <strong><?= $passCount ?></strong>, Tidak lulus: <strong><?= $scoredCount - $passCount ?></strong>)<br>
                • Belum Pretes: <strong><?= $totalPeserta - $scoredCount ?></strong> orang<br>
                <span style="font-size:8pt;color:#64748b;">Dicetak secara resmi melalui Sistem Informasi LPPAI Corner UNISDA.</span>
            </div>

            <div class="signature-box">
                <div class="signature-title">
                    Lamongan, <?= $signatureDate ?><br>
                    <strong>Pengelola Pretes LPPAI UNISDA</strong>
                </div>
                <div class="signature-name">
                    Administrator LPPAI
                </div>
                <div class="signature-role">
                    Universitas Islam Darul 'Ulum Lamongan
                </div>
            </div>
        </div>

    </main>

    <?php if (isset($_GET['print']) && $_GET['print'] === '1'): ?>
        <script>
            window.addEventListener('load', function() {
                setTimeout(function() {
                    window.print();
                }, 300);
            });
        </script>
    <?php endif; ?>

</body>
</html>
