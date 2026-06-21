<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Fitur cetak dikhususkan untuk admin/pengelola
$user = getCurrentUser();
if ($user['role'] !== 'admin') {
    die("Akses ditolak.");
}

$reg_id = (int)($_GET['id'] ?? 0);
if (!$reg_id) die("Data tidak valid atau tidak ditemukan.");

$pdo = getDBConnection();

// Ambil data nilai dan biodata
$stmt = $pdo->prepare("
    SELECT u.nama_lengkap, u.nim, u.program_studi, u.tempat_lahir, u.tanggal_lahir,
           tr.tahun_ajaran, tr.tipe_nilai,
           tr.nilai_thaharah, tr.nilai_shalat, tr.nilai_surat_pendek,
           tr.nilai_amaliyah, tr.nilai_jenazah, tr.nilai_ujian_tulis
    FROM tutorial_registrations tr
    JOIN users u ON u.id = tr.user_id
    WHERE tr.id = ?
");
$stmt->execute([$reg_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) die("Data nilai mahasiswa tidak ditemukan.");

// Validasi Kelengkapan dan Kelulusan
$th = (float)$data['nilai_thaharah'];
$sh = (float)$data['nilai_shalat'];
$sp = (float)$data['nilai_surat_pendek'];
$am = (float)$data['nilai_amaliyah'];
$jn = (float)$data['nilai_jenazah'];
$ut = (float)$data['nilai_ujian_tulis'];

$count = 0; $sum = 0;
foreach([$th, $sh, $sp, $am, $jn, $ut] as $v) {
    if ($v > 0) { $count++; $sum += $v; }
}

if ($count < 6) {
    die("<h2 style='text-align:center; font-family:sans-serif; margin-top:50px;'>❌ Sertifikat tidak dapat dicetak: Data nilai mahasiswa belum lengkap.</h2>");
}

$tipe = strtolower(trim((string)$data['tipe_nilai']));
$min_score = ($tipe === 'pretest') ? 80 : 70;

if ($th < $min_score || $sh < $min_score || $sp < $min_score || $am < $min_score || $jn < $min_score || $ut < $min_score) {
    die("<h2 style='text-align:center; font-family:sans-serif; margin-top:50px;'>❌ Sertifikat tidak dapat dicetak: Mahasiswa belum dinyatakan LULUS.</h2>");
}

$akhir = round($sum / $count, 2);
// Predikat
$predikat = '';
if ($akhir >= 90) $predikat = 'Istimewa / Mumtaz';
elseif ($akhir >= 80) $predikat = 'Sangat Baik / Jayyid Jiddan';
elseif ($akhir >= 70) $predikat = 'Baik / Jayyid';
else $predikat = 'Cukup / Maqbul';

// Tanggal Cetak & Bulan Romawi
$bulanRomawi = [1=>"I","II","III","IV","V","VI","VII","VIII","IX","X","XI","XII"];
$bln = date('n');
$thnNow = date('Y');
$nomorSertifikat = sprintf("No: %03d/LPPAI/UNISDA/%s/%s", $reg_id, $bulanRomawi[$bln], $thnNow);

// Formatting Tanggal (Indonesia)
function tgl_indo($tanggal){
	$bulan = array (
		1 =>   'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
		'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
	);
	$pecahkan = explode('-', $tanggal);
	return $pecahkan[2] . ' ' . $bulan[ (int)$pecahkan[1] ] . ' ' . $pecahkan[0];
}
$tglCetak = tgl_indo(date('Y-m-d'));

$ttlLahir = '';
if ($data['tempat_lahir'] && $data['tanggal_lahir']) {
    $ttlLahir = $data['tempat_lahir'] . ', ' . tgl_indo($data['tanggal_lahir']);
} elseif ($data['tanggal_lahir']) {
    $ttlLahir = tgl_indo($data['tanggal_lahir']);
} else {
    $ttlLahir = '-';
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Sertifikat Kelulusan - <?= htmlspecialchars($data['nama_lengkap']) ?></title>
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
        .cert-container {
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
        }
        .cert-border {
            border: 8px solid #1e3a8a;
            padding: 8px;
            height: 100%;
            box-sizing: border-box;
            position: relative;
        }
        .cert-inner-border {
            border: 2px solid #b45309;
            height: 100%;
            box-sizing: border-box;
            padding: 20px 40px;
            background: white;
            position: relative;
            text-align: center;
        }
        
        .kop {
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 3px double #1e3a8a;
            padding-bottom: 15px;
            margin-bottom: 25px;
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
            margin: 5px 0 5px;
            font-size: 28px;
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
            font-size: 60px;
            color: #b45309;
            margin: 5px 0 5px;
            letter-spacing: 1px;
        }
        .cert-number {
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            color: #334155;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .cert-body {
            font-size: 18px;
            line-height: 1.6;
            margin-bottom: 10px;
            color: #334155;
        }
        .student-name {
            font-size: 30px;
            font-weight: 700;
            margin: 15px 0 5px;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .student-detail {
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            color: #475569;
            margin-bottom: 15px;
        }
        .statement {
            font-size: 20px;
            font-weight: 600;
            margin: 10px 0 25px;
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
            margin-top: 10px;
            padding: 0 10px;
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
            font-size: 16px;
            margin-bottom: 8px;
        }
        .signature-role {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 70px;
        }
        .signature-name {
            font-size: 18px;
            font-weight: 700;
            text-decoration: underline;
        }

        .btn-print {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background-color: #3b82f6;
            color: white;
            padding: 14px 28px;
            border-radius: 50px;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 16px;
            text-decoration: none;
            box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.5);
            cursor: pointer;
            border: none;
            z-index: 1000;
            transition: all 0.2s;
        }
        .btn-print:hover {
            background-color: #2563eb;
            transform: translateY(-2px);
        }

        @media print {
            body {
                background-color: white;
            }
            .cert-container {
                margin: 0;
                box-shadow: none;
                width: 100%;
                height: 100%;
                padding: 10mm;
            }
            .btn-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <button class="btn-print" onclick="window.print()">🖨️ Cetak Sertifikat Sekarang</button>

    <div class="cert-container">
        <div class="cert-border">
            <div class="cert-inner-border">
                
                <div class="kop">
                    <!-- Path logo dari sistem -->
                    <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo LPPAI" class="kop-logo">
                    <div class="kop-text">
                        <h2>LEMBAGA PENGKAJIAN DAN PENGAMALAN AJARAN ISLAM (LPPAI)</h2>
                        <h1>UNIVERSITAS ISLAM DARUL 'ULUM (UNISDA)</h1>
                        <p>Jl. Airlangga No. 03 Sukodadi, Lamongan, Jawa Timur 62253</p>
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

</body>
</html>
