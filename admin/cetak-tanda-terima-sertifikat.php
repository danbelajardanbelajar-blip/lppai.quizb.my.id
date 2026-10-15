<?php
/**
 * LPPAI Corner - Cetak Tanda Terima & Berita Acara Pengambilan Sertifikat
 */
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pdo = getDBConnection();
$mode = $_GET['mode'] ?? 'single';
$id   = (int)($_GET['id'] ?? 0);
$noSert = trim($_GET['no'] ?? '');

if (!isAdmin() && $mode !== 'single' && $id == 0 && empty($noSert)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// ── MODE 1: TANDA TERIMA TUNGGAL (SLIP SERAH TERIMA) ─────────────
if ($mode === 'single' || $id > 0 || !empty($noSert)) {
    if ($id > 0) {
        $stmt = $pdo->prepare("
            SELECT ps.*, u.tempat_lahir, u.tanggal_lahir, u.no_hp
            FROM pengambilan_sertifikat ps
            LEFT JOIN users u ON ps.nim = u.nim
            WHERE ps.id = ?
        ");
        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT ps.*, u.tempat_lahir, u.tanggal_lahir, u.no_hp
            FROM pengambilan_sertifikat ps
            LEFT JOIN users u ON ps.nim = u.nim
            WHERE ps.nomor_sertifikat = ?
            ORDER BY ps.id DESC LIMIT 1
        ");
        $stmt->execute([$noSert]);
    }
    $data = $stmt->fetch();

    if (!$data) {
        die("Data tanda terima penyerahan sertifikat tidak ditemukan.");
    }

    if (!isAdmin()) {
        $currentUser = getCurrentUser();
        if ($data['nim'] !== $currentUser['nim']) {
            die("Akses ditolak. Anda hanya berhak melihat tanda terima milik Anda sendiri.");
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Tanda Terima Pengambilan Sertifikat - <?= sanitize($data['nim']) ?></title>
        <link rel="icon" href="<?= BASE_URL ?>/assets/logo.svg" type="image/svg+xml">
        <style>
            @page {
                size: A4 portrait;
                margin: 15mm 20mm;
            }
            body {
                font-family: Arial, sans-serif;
                color: #000;
                background: #f8fafc;
                margin: 0;
                padding: 20px;
                font-size: 13.5px;
                line-height: 1.5;
            }
            .no-print {
                max-width: 720px;
                margin: 0 auto 16px auto;
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #fff;
                padding: 12px 20px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .sheet {
                background: #fff;
                max-width: 720px;
                margin: 0 auto;
                padding: 36px 44px;
                border: 1px solid #ccc;
                box-shadow: 0 4px 15px rgba(0,0,0,0.08);
                box-sizing: border-box;
            }
            .kop {
                display: flex;
                align-items: center;
                gap: 16px;
                border-bottom: 3px double #000;
                padding-bottom: 12px;
                margin-bottom: 20px;
            }
            .kop img {
                width: 70px;
                height: 70px;
                object-fit: contain;
            }
            .kop-text {
                text-align: center;
                flex: 1;
            }
            .kop-text h2 {
                margin: 0;
                font-size: 15px;
                font-weight: bold;
            }
            .kop-text h1 {
                margin: 3px 0;
                font-size: 18px;
                font-weight: 800;
                color: #1a5632;
            }
            .kop-text p {
                margin: 2px 0;
                font-size: 11.5px;
                color: #333;
            }

            .title-box {
                text-align: center;
                margin-bottom: 24px;
            }
            .title-box h3 {
                margin: 0;
                font-size: 16px;
                text-decoration: underline;
                letter-spacing: 0.5px;
            }
            .title-box small {
                font-size: 12px;
                color: #555;
            }

            table.data-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 24px;
            }
            table.data-table th, table.data-table td {
                padding: 8px 10px;
                vertical-align: top;
                border-bottom: 1px solid #eee;
            }
            table.data-table th {
                width: 32%;
                text-align: left;
                color: #333;
                font-weight: bold;
            }

            .btn {
                padding: 8px 16px;
                border-radius: 6px;
                font-size: 13px;
                font-weight: bold;
                text-decoration: none;
                cursor: pointer;
                border: none;
            }
            .btn-print { background: #1a5632; color: #fff; }
            .btn-back { background: #64748b; color: #fff; }

            .signatures {
                display: flex;
                justify-content: space-between;
                margin-top: 40px;
            }
            .sig-box {
                width: 250px;
                text-align: center;
            }

            @media print {
                body { background: #fff; padding: 0; }
                .no-print { display: none; }
                .sheet { border: none; box-shadow: none; padding: 0; max-width: 100%; }
            }
        </style>
    </head>
    <body>
        <div class="no-print">
            <a href="pengambilan-sertifikat.php" class="btn btn-back">🔙 Kembali</a>
            <div>
                <button onclick="window.print()" class="btn btn-print">🖨️ Cetak Bukti Tanda Terima</button>
            </div>
        </div>

        <div class="sheet">
            <!-- Kop LPPAI -->
            <div class="kop">
                <img src="<?= BASE_URL ?>/assets/logo.svg" alt="LPPAI Logo">
                <div class="kop-text">
                    <h2>LEMBAGA PENGEMBANGAN PENDIDIKAN AGAMA ISLAM (LPPAI)</h2>
                    <h1>LPPAI CORNER</h1>
                    <p>Sistem Administrasi, Arsip & Sertifikasi Keislaman Mahasiswa</p>
                    <p>Website: https://lppai.quizb.my.id &bull; Surel: lppai@institusi.ac.id</p>
                </div>
            </div>

            <div class="title-box">
                <h3>BUKTI TANDA TERIMA PENGAMBILAN SERTIFIKAT</h3>
                <small>No. Bukti: BAP-<?= str_pad($data['id'], 5, '0', STR_PAD_LEFT) ?> / LPPAI / <?= date('Y', strtotime($data['tanggal_pengambilan'])) ?></small>
            </div>

            <p style="margin-bottom:16px;">
                Telah diserahkan berkas <strong>Sertifikat Asli Kelulusan Daurah Keislaman</strong> kepada mahasiswa / perwakilan yang bersangkutan dengan rincian sebagai berikut:
            </p>

            <table class="data-table">
                <tr>
                    <th>Nomor Sertifikat</th>
                    <td>: <strong style="font-size:15px; color:#1a5632;"><?= sanitize($data['nomor_sertifikat']) ?></strong></td>
                </tr>
                <tr>
                    <th>Nama Mahasiswa</th>
                    <td>: <strong><?= sanitize($data['nama_mahasiswa']) ?></strong></td>
                </tr>
                <tr>
                    <th>NIM</th>
                    <td>: <?= sanitize($data['nim']) ?></td>
                </tr>
                <tr>
                    <th>Program Studi / Jurusan</th>
                    <td>: <?= sanitize($data['program_studi'] ?: '-') ?></td>
                </tr>
                <tr>
                    <th>Tahun Ajaran</th>
                    <td>: <?= sanitize($data['tahun_ajaran'] ?: '-') ?></td>
                </tr>
                <tr>
                    <th>Waktu Pengambilan</th>
                    <td>: <?= date('d F Y - H:i', strtotime($data['tanggal_pengambilan'])) ?> WIB</td>
                </tr>
                <tr>
                    <th>Nama Yang Mengambil</th>
                    <td>: <strong><?= sanitize($data['nama_penerima']) ?></strong> (<?= sanitize($data['hubungan_penerima']) ?>)</td>
                </tr>
                <tr>
                    <th>Metode Verifikasi</th>
                    <td>: <?= sanitize($data['metode_pengambilan']) ?></td>
                </tr>
                <tr>
                    <th>Catatan / Keterangan</th>
                    <td>: <?= sanitize($data['catatan'] ?: 'Sertifikat telah diterima dalam keadaan baik.') ?></td>
                </tr>
            </table>

            <p style="font-size:12.5px; color:#555; margin-bottom:30px; font-style:italic;">
                * Bukti tanda terima ini sah dan dicetak melalui Sistem Informasi LPPAI Corner. Sertifikat yang telah diserahkan sepenuhnya menjadi tanggung jawab pemegang sertifikat.
            </p>

            <div class="signatures">
                <div class="sig-box">
                    <div>Penerima Sertifikat,</div>
                    <div style="height:70px;"></div>
                    <div style="font-weight:bold; text-decoration:underline;"><?= sanitize($data['nama_penerima']) ?></div>
                    <div>(<?= sanitize($data['hubungan_penerima']) ?>)</div>
                </div>

                <div class="sig-box">
                    <div>Lamongan, <?= date('d F Y', strtotime($data['tanggal_pengambilan'])) ?><br>Petugas LPPAI,</div>
                    <div style="height:70px;"></div>
                    <div style="font-weight:bold; text-decoration:underline;"><?= sanitize($data['petugas_nama'] ?: 'Admin LPPAI') ?></div>
                    <div>Staf Administrasi & Sertifikasi</div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── MODE 2: BERITA ACARA REKAPITULASI PENGAMBILAN SERTIFIKAT ─────
$sqlRekap = "
    SELECT tr.nomor_sertifikat, tr.tahun_ajaran, u.nim, u.nama_lengkap, u.program_studi,
           ps.tanggal_pengambilan, ps.nama_penerima, ps.hubungan_penerima, ps.petugas_nama
    FROM pengambilan_sertifikat ps
    JOIN tutorial_registrations tr ON ps.nomor_sertifikat = tr.nomor_sertifikat
    JOIN users u ON ps.nim = u.nim
    WHERE ps.status_pengambilan = 'Sudah Diambil'
    ORDER BY ps.tanggal_pengambilan DESC
";
$rows = $pdo->query($sqlRekap)->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Berita Acara Rekapitulasi Pengambilan Sertifikat - LPPAI</title>
    <link rel="icon" href="<?= BASE_URL ?>/assets/logo.svg" type="image/svg+xml">
    <style>
        @page {
            size: A4 landscape;
            margin: 12mm 15mm;
        }
        body {
            font-family: Arial, sans-serif;
            color: #000;
            background: #f8fafc;
            margin: 0;
            padding: 20px;
            font-size: 11.5px;
            line-height: 1.4;
        }
        .no-print {
            max-width: 1040px;
            margin: 0 auto 16px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .sheet {
            background: #fff;
            max-width: 1040px;
            margin: 0 auto;
            padding: 28px 36px;
            border: 1px solid #ccc;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            box-sizing: border-box;
        }
        .kop {
            display: flex;
            align-items: center;
            gap: 16px;
            border-bottom: 3px double #000;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .kop img {
            width: 64px;
            height: 64px;
            object-fit: contain;
        }
        .kop-text {
            text-align: center;
            flex: 1;
        }
        .kop-text h2 {
            margin: 0;
            font-size: 15px;
            font-weight: bold;
        }
        .kop-text h1 {
            margin: 2px 0;
            font-size: 18px;
            font-weight: 800;
            color: #1a5632;
        }
        .kop-text p {
            margin: 2px 0;
            font-size: 11px;
            color: #333;
        }

        .title-box {
            text-align: center;
            margin-bottom: 16px;
        }
        .title-box h3 {
            margin: 0;
            font-size: 15px;
            text-transform: uppercase;
        }

        table.rekap-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table.rekap-table th, table.rekap-table td {
            border: 1px solid #333;
            padding: 6px 8px;
            vertical-align: middle;
        }
        table.rekap-table th {
            background-color: #f1f5f9;
            text-align: center;
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
            border: none;
        }
        .btn-print { background: #1a5632; color: #fff; }
        .btn-back { background: #64748b; color: #fff; }

        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 24px;
            page-break-inside: avoid;
        }
        .sig-box {
            width: 240px;
            text-align: center;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .no-print { display: none; }
            .sheet { border: none; box-shadow: none; padding: 0; max-width: 100%; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <a href="pengambilan-sertifikat.php" class="btn btn-back">🔙 Kembali ke Pengambilan Sertifikat</a>
        <div>
            <button onclick="window.print()" class="btn btn-print">🖨️ Cetak / Simpan PDF</button>
        </div>
    </div>

    <div class="sheet">
        <div class="kop">
            <img src="<?= BASE_URL ?>/assets/logo.svg" alt="LPPAI Logo">
            <div class="kop-text">
                <h2>LEMBAGA PENGEMBANGAN PENDIDIKAN AGAMA ISLAM (LPPAI)</h2>
                <h1>BERITA ACARA REKAPITULASI PENYERAHAN SERTIFIKAT</h1>
                <p>Website: https://lppai.quizb.my.id &bull; Surel: lppai@institusi.ac.id</p>
            </div>
        </div>

        <div class="title-box">
            <h3>DAFTAR MAHASISWA YANG TELAH MENGAMBIL SERTIFIKAT</h3>
            <div style="font-size:11px; color:#555; margin-top:3px;">
                Total Diserahkan: <?= count($rows) ?> Sertifikat &bull; Dicetak pada: <?= date('d F Y, H:i') ?> WIB
            </div>
        </div>

        <table class="rekap-table">
            <thead>
                <tr>
                    <th style="width:30px;">No</th>
                    <th style="width:130px;">Nomor Sertifikat</th>
                    <th style="width:90px;">NIM</th>
                    <th>Nama Mahasiswa</th>
                    <th style="width:140px;">Program Studi</th>
                    <th style="width:110px;">Waktu Ambil</th>
                    <th style="width:130px;">Penerima (Hubungan)</th>
                    <th style="width:100px;">Petugas LPPAI</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding:20px; color:#666;">
                            Belum ada data penyerahan sertifikat.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $no = 1; foreach ($rows as $r): ?>
                    <tr>
                        <td style="text-align:center;"><?= $no++ ?></td>
                        <td><strong style="color:#1a5632;"><?= sanitize($r['nomor_sertifikat']) ?></strong></td>
                        <td style="text-align:center;"><?= sanitize($r['nim']) ?></td>
                        <td><strong><?= sanitize($r['nama_lengkap']) ?></strong></td>
                        <td><?= sanitize($r['program_studi'] ?: '-') ?></td>
                        <td style="text-align:center;"><?= date('d/m/Y H:i', strtotime($r['tanggal_pengambilan'])) ?></td>
                        <td>
                            <?= sanitize($r['nama_penerima']) ?>
                            <small style="color:#64748b;">(<?= sanitize($r['hubungan_penerima']) ?>)</small>
                        </td>
                        <td><?= sanitize($r['petugas_nama'] ?: 'Admin') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="signatures">
            <div class="sig-box">
                <div>Petugas Administrasi & Sertifikasi,</div>
                <div style="height:65px;"></div>
                <div style="font-weight:bold; text-decoration:underline;">Staf LPPAI</div>
                <div>Pengelola Arsip Sertifikat</div>
            </div>

            <div class="sig-box">
                <div>Mengetahui,</div>
                <div style="font-weight:bold;">Ketua LPPAI</div>
                <div style="height:65px;"></div>
                <div style="font-weight:bold; text-decoration:underline;">Dr. Zainul Hakim, M.H.I.</div>
                <div>NIP/NIDN. LPPAI</div>
            </div>
        </div>
    </div>
</body>
</html>
