<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Fitur cetak dikhususkan untuk admin/pengelola
$user = getCurrentUser();
if ($user['role'] !== 'admin') {
    die("Akses ditolak.");
}

$pdo = getDBConnection();

$mode = $_GET['mode'] ?? 'single';
$reg_id = (int)($_GET['id'] ?? 0);

$whereLulus = "
    (tr.nilai_thaharah IS NOT NULL AND tr.nilai_shalat IS NOT NULL AND tr.nilai_surat_pendek IS NOT NULL AND tr.nilai_amaliyah IS NOT NULL AND tr.nilai_jenazah IS NOT NULL AND tr.nilai_ujian_tulis IS NOT NULL)
    AND tr.nilai_thaharah >= (CASE WHEN LOWER(tr.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr.nilai_shalat >= (CASE WHEN LOWER(tr.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr.nilai_surat_pendek >= (CASE WHEN LOWER(tr.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr.nilai_amaliyah >= (CASE WHEN LOWER(tr.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr.nilai_jenazah >= (CASE WHEN LOWER(tr.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr.nilai_ujian_tulis >= (CASE WHEN LOWER(tr.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
";

$sqlBase = "
    SELECT u.nama_lengkap, u.nim, u.program_studi, u.tempat_lahir, u.tanggal_lahir,
           tr.id as reg_id, tr.tahun_ajaran, tr.tipe_nilai,
           tr.nilai_thaharah, tr.nilai_shalat, tr.nilai_surat_pendek,
           tr.nilai_amaliyah, tr.nilai_jenazah, tr.nilai_ujian_tulis
    FROM tutorial_registrations tr
    JOIN users u ON u.id = tr.user_id
";

$students = [];

if ($mode === 'single') {
    if (!$reg_id) die("Data tidak valid atau tidak ditemukan.");
    $stmt = $pdo->prepare($sqlBase . " WHERE tr.id = ?");
    $stmt->execute([$reg_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) die("Data nilai mahasiswa tidak ditemukan.");

    // Validasi Kelengkapan dan Kelulusan manual untuk single
    $th = (float)$student['nilai_thaharah'];
    $sh = (float)$student['nilai_shalat'];
    $sp = (float)$student['nilai_surat_pendek'];
    $am = (float)$student['nilai_amaliyah'];
    $jn = (float)$student['nilai_jenazah'];
    $ut = (float)$student['nilai_ujian_tulis'];

    $count = 0;
    foreach([$th, $sh, $sp, $am, $jn, $ut] as $v) {
        if ($v > 0) $count++;
    }

    if ($count < 6) {
        die("<h2 style='text-align:center; font-family:sans-serif; margin-top:50px;'>❌ Sertifikat tidak dapat dicetak: Data nilai mahasiswa belum lengkap.</h2>");
    }

    $tipe = strtolower(trim((string)$student['tipe_nilai']));
    $min_score = ($tipe === 'pretest') ? 80 : 70;

    if ($th < $min_score || $sh < $min_score || $sp < $min_score || $am < $min_score || $jn < $min_score || $ut < $min_score) {
        die("<h2 style='text-align:center; font-family:sans-serif; margin-top:50px;'>❌ Sertifikat tidak dapat dicetak: Mahasiswa belum dinyatakan LULUS.</h2>");
    }
    
    $students[] = $student;
} else {
    // Mode all atau range
    $sqlFilter = $sqlBase . " WHERE " . $whereLulus . " ORDER BY u.nama_lengkap ASC";
    if ($mode === 'range') {
        $start = max(1, (int)($_GET['start'] ?? 1));
        $end = max($start, (int)($_GET['end'] ?? 10));
        $limit = $end - $start + 1;
        $offset = $start - 1;
        $sqlFilter .= " LIMIT $limit OFFSET $offset";
    }
    $stmt = $pdo->query($sqlFilter);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($students)) {
        die("<h2 style='text-align:center; font-family:sans-serif; margin-top:50px;'>Tidak ada data mahasiswa LULUS yang ditemukan dalam rentang ini.</h2>");
    }
}

// Formatting Tanggal (Indonesia)
function tgl_indo($tanggal){
    if(!$tanggal) return '-';
	$bulan = array (
		1 =>   'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
		'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
	);
	$pecahkan = explode('-', $tanggal);
    if(count($pecahkan) !== 3) return $tanggal;
	return $pecahkan[2] . ' ' . $bulan[ (int)$pecahkan[1] ] . ' ' . $pecahkan[0];
}
$tglCetak = tgl_indo(date('Y-m-d'));
$bulanRomawi = [1=>"I","II","III","IV","V","VI","VII","VIII","IX","X","XI","XII"];
$bln = date('n');
$thnNow = date('Y');

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Sertifikat Kelulusan</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Pinyon+Script&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @page {
            size: A4 landscape;
            margin: 0;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: 'Playfair Display', serif;
            background-color: #e2e8f0;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        /* Panel Kontrol Print */
        .print-panel {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            z-index: 1000;
            font-family: 'Poppins', sans-serif;
            width: 320px;
        }
        .print-panel h3 {
            margin: 0 0 15px;
            font-size: 16px;
            color: #1e293b;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 8px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            font-size: 12px;
            color: #64748b;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .form-control {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-sizing: border-box;
            font-family: inherit;
            font-size: 13px;
        }
        .btn {
            display: inline-block;
            width: 100%;
            padding: 10px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            box-sizing: border-box;
            transition: all 0.2s;
            margin-bottom: 8px;
            font-family: inherit;
            text-decoration: none;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; }
        .btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-success { background: #10b981; color: white; margin-top: 10px;}
        .btn-success:hover { background: #059669; }

        /* Sertifikat Layout */
        .cert-page {
            width: 297mm;
            height: 210mm;
            background-color: white;
            margin: 20px auto;
            position: relative;
            box-sizing: border-box;
            padding: 15mm;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            background-image: radial-gradient(#cbd5e1 1px, transparent 1px);
            background-size: 20px 20px;
            page-break-after: always;
        }
        .cert-page:last-child {
            page-break-after: auto;
        }
        .cert-border {
            padding: 8px;
            height: 100%;
            box-sizing: border-box;
            position: relative;
        }
        .cert-inner-border {
            height: 100%;
            box-sizing: border-box;
            padding: 15px 30px;
            background: white;
            position: relative;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .kop {
            display: flex;
            align-items: center;
            justify-content: center;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }
        .kop-logo {
            width: 90px;
            margin-right: 25px;
        }
        .kop-text h2 {
            margin: 0;
            font-size: 22px;
            color: #1e3a8a;
            font-weight: 700;
        }
        .kop-text h1 {
            margin: 4px 0;
            font-size: 26px;
            color: #1e3a8a;
            font-weight: 700;
            text-transform: uppercase;
        }
        .kop-text p {
            margin: 0;
            font-size: 13px;
            font-family: 'Poppins', sans-serif;
            color: #475569;
        }

        .cert-title {
            font-family: 'Pinyon Script', cursive;
            font-size: 52px;
            color: #b45309;
            margin: 0 0 5px;
            letter-spacing: 1px;
        }
        .cert-number {
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            color: #334155;
            margin-bottom: 15px;
            font-weight: 600;
        }
        .cert-body {
            font-size: 17px;
            line-height: 1.5;
            margin-bottom: 10px;
            color: #334155;
            flex-grow: 1;
        }
        .student-name {
            font-size: 28px;
            font-weight: 700;
            margin: 10px 0 5px;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .student-detail {
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            color: #475569;
            margin-bottom: 15px;
        }
        .statement {
            font-size: 19px;
            font-weight: 600;
            margin: 10px 0 15px;
            color: #15803d;
        }
        .statement span {
            font-size: 28px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .bottom-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            padding: 0 10px;
            margin-top: auto;
        }
        
        .grades-table-container {
            width: 65%;
            text-align: left;
        }
        .grades-table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Poppins', sans-serif;
            font-size: 12px;
        }
        .grades-table th, .grades-table td {
            border: 1px solid #94a3b8;
            padding: 6px 8px;
        }
        .grades-table th {
            background-color: #f1f5f9;
            color: #1e293b;
            font-weight: 600;
            text-align: center;
        }
        .grades-table td {
            color: #334155;
            font-weight: 500;
        }
        
        .signature-container {
            width: 30%;
            text-align: center;
        }
        .signature-date {
            font-size: 15px;
            margin-bottom: 5px;
        }
        .signature-role {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 55px;
        }
        .signature-name {
            font-size: 17px;
            font-weight: 700;
            text-decoration: underline;
        }

        @media print {
            body {
                background-color: white;
            }
            .cert-page {
                margin: 0;
                box-shadow: none;
                width: 100%;
                height: 100%;
                padding: 10mm;
            }
            .print-panel {
                display: none !important;
            }
        }
    </style>
</head>
<body>

    <div class="print-panel">
        <h3>🖨️ Opsi Cetak Sertifikat</h3>
        
        <?php if ($mode === 'single'): ?>
            <div style="margin-bottom: 15px; padding: 10px; background: #eff6ff; border-radius: 6px; font-size:12px; color:#1e40af;">
                Mode: <strong>Print Individual (1)</strong>
            </div>
            <button class="btn btn-secondary" onclick="window.location='?mode=all<?= $reg_id ? '&id='.$reg_id : '' ?>'">Cetak Semua (Lulus)</button>
        <?php else: ?>
            <div style="margin-bottom: 15px; padding: 10px; background: #f0fdf4; border-radius: 6px; font-size:12px; color:#166534;">
                Mode: <strong><?= $mode === 'all' ? 'Print Semua (Lulus)' : 'Print Range (Lulus)' ?></strong><br>
                Jumlah: <strong><?= count($students) ?> Sertifikat</strong>
            </div>
            <?php if($reg_id): ?>
                <button class="btn btn-secondary" onclick="window.location='?mode=single&id=<?= $reg_id ?>'">Kembali ke Individual</button>
            <?php endif; ?>
        <?php endif; ?>

        <hr style="border:none; border-top:1px solid #e2e8f0; margin:15px 0;">

        <form action="" method="GET">
            <input type="hidden" name="mode" value="range">
            <?php if($reg_id): ?>
                <input type="hidden" name="id" value="<?= $reg_id ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label>Print Dari Urutan Ke:</label>
                <input type="number" name="start" class="form-control" value="<?= $_GET['start'] ?? 1 ?>" min="1" required>
            </div>
            <div class="form-group">
                <label>Sampai Urutan Ke:</label>
                <input type="number" name="end" class="form-control" value="<?= $_GET['end'] ?? 10 ?>" min="1" required>
            </div>
            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary" style="flex:1; margin-bottom:0;">Filter Range</button>
                <button type="button" class="btn btn-secondary" style="flex:1; margin-bottom:0;" onclick="window.location='?mode=all<?= $reg_id ? '&id='.$reg_id : '' ?>'">Reset (All)</button>
            </div>
        </form>

        <button class="btn btn-success" onclick="window.print()">Cetak Ke Printer / PDF</button>
    </div>

    <?php foreach($students as $idx => $data): 
        $th = (float)$data['nilai_thaharah'];
        $sh = (float)$data['nilai_shalat'];
        $sp = (float)$data['nilai_surat_pendek'];
        $am = (float)$data['nilai_amaliyah'];
        $jn = (float)$data['nilai_jenazah'];
        $ut = (float)$data['nilai_ujian_tulis'];
        
        $akhir = round(($th + $sh + $sp + $am + $jn + $ut) / 6, 2);
        
        $predikat = '';
        if ($akhir >= 90) $predikat = 'Istimewa / Mumtaz';
        elseif ($akhir >= 80) $predikat = 'Sangat Baik / Jayyid Jiddan';
        elseif ($akhir >= 70) $predikat = 'Baik / Jayyid';
        else $predikat = 'Cukup / Maqbul';

        $nomorSertifikat = sprintf("No: %03d/LPPAI/UNISDA/%s/%s", $data['reg_id'], $bulanRomawi[$bln], $thnNow);

        $ttlLahir = '';
        if ($data['tempat_lahir'] && $data['tanggal_lahir']) {
            $ttlLahir = $data['tempat_lahir'] . ', ' . tgl_indo($data['tanggal_lahir']);
        } elseif ($data['tanggal_lahir']) {
            $ttlLahir = tgl_indo($data['tanggal_lahir']);
        } else {
            $ttlLahir = '-';
        }
    ?>
    <div class="cert-page">
        <div class="cert-border">
            <div class="cert-inner-border">
                
                <div class="kop">
                    <!-- Path logo dari sistem -->
                    <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo LPPAI" class="kop-logo">
                    <div class="kop-text">
                    </div>
                </div>

                <div class="cert-title">Sertifikat Kelulusan</div>
                <div class="cert-number"><?= htmlspecialchars($nomorSertifikat) ?></div>

                <div class="cert-body">
                    Diberikan kepada:<br>
                    <div class="student-name"><?= htmlspecialchars($data['nama_lengkap']) ?></div>
                    <div class="student-detail">
                        NIM: <strong><?= htmlspecialchars($data['nim'] ?: '-') ?></strong> &nbsp;&nbsp;|&nbsp;&nbsp; 
                        Program Studi: <strong><?= htmlspecialchars($data['program_studi'] ?: '-') ?></strong><br>
                        Tempat, Tanggal Lahir: <?= htmlspecialchars($ttlLahir) ?>
                    </div>

                    <div class="statement">
                        Telah mengikuti dan dinyatakan <br>
                        <span>LULUS</span><br>
                        <div style="font-size: 18px; margin-top: 5px; color:#334155; font-weight:normal;">
                            Ujian Praktik Keagamaan (Tipe: <strong><?= htmlspecialchars(ucwords($data['tipe_nilai'] ?? '-')) ?></strong>)
                        </div>
                    </div>
                </div>

                <div class="bottom-section">
                    <div class="grades-table-container">
                        <div style="font-family:'Poppins',sans-serif; font-size:13px; font-weight:600; margin-bottom:5px; color:#1e293b;">
                            Transkrip Nilai:
                        </div>
                        <table class="grades-table">
                            <tr>
                                <th>Thaharah</th>
                                <th>Shalat</th>
                                <th>Srt. Pendek</th>
                                <th>Amaliyah</th>
                                <th>Jenazah</th>
                                <th>Ujian Tulis</th>
                                <th>Nilai Akhir</th>
                            </tr>
                            <tr>
                                <td style="text-align:center;"><?= number_format($th, 2) ?></td>
                                <td style="text-align:center;"><?= number_format($sh, 2) ?></td>
                                <td style="text-align:center;"><?= number_format($sp, 2) ?></td>
                                <td style="text-align:center;"><?= number_format($am, 2) ?></td>
                                <td style="text-align:center;"><?= number_format($jn, 2) ?></td>
                                <td style="text-align:center;"><?= number_format($ut, 2) ?></td>
                                <td style="text-align:center; font-weight:bold; background:#e2e8f0; font-size:13px;"><?= number_format($akhir, 2) ?></td>
                            </tr>
                        </table>
                        <div style="font-family:'Poppins',sans-serif; font-size:13px; margin-top:5px; color:#334155;">
                            Predikat: <strong><?= $predikat ?></strong>
                        </div>
                    </div>

                    <div class="signature-container">
                        <div class="signature-date">Lamongan, <?= $tglCetak ?></div>
                        <div class="signature-role">Ketua LPPAI,</div>
                        <div class="signature-name">Dr. Zainul Hakim, M.H.I.</div>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php endforeach; ?>

</body>
</html>
