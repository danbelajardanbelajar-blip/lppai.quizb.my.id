<?php
/**
 * LPPAI Corner - Cetak Inventaris Surat & Lembar Disposisi
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
$mode = $_GET['mode'] ?? 'rekap';
$id   = (int)($_GET['id'] ?? 0);

// ── MODE 1: LEMBAR DISPOSISI SURAT ──────────────────────────────
if ($mode === 'disposisi') {
    if ($id <= 0) {
        die("ID Surat tidak valid.");
    }

    $stmt = $pdo->prepare("SELECT * FROM surat_inventaris WHERE id = ?");
    $stmt->execute([$id]);
    $surat = $stmt->fetch();

    if (!$surat) {
        die("Data surat tidak ditemukan.");
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Lembar Disposisi - <?= sanitize($surat['nomor_surat']) ?></title>
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
                font-size: 13px;
                line-height: 1.4;
            }
            .no-print {
                max-width: 760px;
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
                max-width: 760px;
                margin: 0 auto;
                padding: 30px 40px;
                border: 1px solid #ccc;
                box-shadow: 0 4px 15px rgba(0,0,0,0.08);
                box-sizing: border-box;
            }
            /* KOP SURAT */
            .kop {
                display: flex;
                align-items: center;
                gap: 16px;
                border-bottom: 3px double #000;
                padding-bottom: 12px;
                margin-bottom: 18px;
            }
            .kop img {
                width: 72px;
                height: 72px;
                object-fit: contain;
            }
            .kop-text {
                text-align: center;
                flex: 1;
            }
            .kop-text h2 {
                margin: 0;
                font-size: 16px;
                font-weight: bold;
                letter-spacing: 0.5px;
            }
            .kop-text h1 {
                margin: 4px 0;
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
                margin-bottom: 20px;
            }
            .title-box h3 {
                margin: 0;
                font-size: 15px;
                text-decoration: underline;
                letter-spacing: 1px;
            }

            table.disposisi-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 16px;
            }
            table.disposisi-table th, table.disposisi-table td {
                border: 1px solid #000;
                padding: 8px 10px;
                vertical-align: top;
            }
            table.disposisi-table th {
                background-color: #f2f2f2;
                text-align: left;
                width: 25%;
                font-weight: bold;
            }

            .checkbox-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px 16px;
                font-size: 12.5px;
            }
            .checkbox-item {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .box-check {
                width: 14px;
                height: 14px;
                border: 1px solid #000;
                display: inline-block;
                text-align: center;
                line-height: 14px;
                font-size: 11px;
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

            @media print {
                body { background: #fff; padding: 0; }
                .no-print { display: none; }
                .sheet { border: none; box-shadow: none; padding: 0; max-width: 100%; }
            }
        </style>
    </head>
    <body>
        <div class="no-print">
            <a href="inventaris-surat.php" class="btn btn-back">🔙 Kembali</a>
            <div>
                <button onclick="window.print()" class="btn btn-print">🖨️ Cetak Lembar Disposisi</button>
            </div>
        </div>

        <div class="sheet">
            <!-- Kop Surat -->
            <div class="kop">
                <img src="<?= BASE_URL ?>/assets/logo.svg" alt="LPPAI Logo">
                <div class="kop-text">
                    <h2>LEMBAGA PENGEMBANGAN PENDIDIKAN AGAMA ISLAM (LPPAI)</h2>
                    <h1>LPPAI CORNER</h1>
                    <p>Sistem Administrasi, Arsip Persuratan & Pendidikan Agama Islam</p>
                    <p>Website: https://lppai.quizb.my.id &bull; Surel: lppai@institusi.ac.id</p>
                </div>
            </div>

            <div class="title-box">
                <h3>LEMBAR DISPOSISI SURAT</h3>
            </div>

            <table class="disposisi-table">
                <tr>
                    <th>No. Agenda / Registrasi</th>
                    <td><strong><?= sanitize($surat['nomor_agenda'] ?: '-') ?></strong></td>
                    <th style="width:20%;">Sifat Surat</th>
                    <td style="width:30%;"><strong><?= sanitize($surat['sifat']) ?></strong></td>
                </tr>
                <tr>
                    <th>Nomor Surat</th>
                    <td><strong><?= sanitize($surat['nomor_surat']) ?></strong></td>
                    <th>Tanggal Surat</th>
                    <td><?= date('d F Y', strtotime($surat['tanggal_surat'])) ?></td>
                </tr>
                <tr>
                    <th>Asal Surat / Pengirim</th>
                    <td colspan="3"><strong><?= sanitize($surat['pengirim'] ?: '-') ?></strong></td>
                </tr>
                <tr>
                    <th>Tanggal Diterima</th>
                    <td><?= date('d F Y', strtotime($surat['tanggal_terima_kirim'])) ?></td>
                    <th>Lokasi Arsip Fisik</th>
                    <td><?= sanitize($surat['lokasi_arsip'] ?: 'Lemari Arsip LPPAI') ?></td>
                </tr>
                <tr>
                    <th>Perihal / Isi Ringkas</th>
                    <td colspan="3" style="font-weight:600;"><?= sanitize($surat['perihal']) ?></td>
                </tr>
                <tr>
                    <th>Kategori Surat</th>
                    <td colspan="3"><?= sanitize($surat['kategori']) ?> &bull; Status: <strong><?= sanitize($surat['status']) ?></strong></td>
                </tr>
            </table>

            <!-- Tabel Disposisi dan Instruksi -->
            <table class="disposisi-table">
                <tr>
                    <td style="width:50%; vertical-align:top;">
                        <strong>DITERUSKAN KEPADA :</strong>
                        <div style="margin-top:10px;" class="checkbox-grid">
                            <div class="checkbox-item">
                                <span class="box-check"><?= stripos($surat['disposisi_kepada'] ?? '', 'Ketua') !== false ? '✓' : '' ?></span>
                                <span>Ketua LPPAI</span>
                            </div>
                            <div class="checkbox-item">
                                <span class="box-check"><?= stripos($surat['disposisi_kepada'] ?? '', 'Sekretaris') !== false ? '✓' : '' ?></span>
                                <span>Sekretaris LPPAI</span>
                            </div>
                            <div class="checkbox-item">
                                <span class="box-check"><?= stripos($surat['disposisi_kepada'] ?? '', 'Tutorial') !== false ? '✓' : '' ?></span>
                                <span>Ka. Divisi Tutorial</span>
                            </div>
                            <div class="checkbox-item">
                                <span class="box-check"><?= stripos($surat['disposisi_kepada'] ?? '', 'Pretes') !== false ? '✓' : '' ?></span>
                                <span>Ka. Divisi Pretes</span>
                            </div>
                            <div class="checkbox-item">
                                <span class="box-check"><?= stripos($surat['disposisi_kepada'] ?? '', 'Keuangan') !== false ? '✓' : '' ?></span>
                                <span>Bendahara / Keuangan</span>
                            </div>
                            <div class="checkbox-item">
                                <span class="box-check"><?= (!empty($surat['disposisi_kepada']) && stripos($surat['disposisi_kepada'], 'Ketua') === false && stripos($surat['disposisi_kepada'], 'Sekretaris') === false && stripos($surat['disposisi_kepada'], 'Tutorial') === false && stripos($surat['disposisi_kepada'], 'Pretes') === false && stripos($surat['disposisi_kepada'], 'Keuangan') === false) ? '✓' : '' ?></span>
                                <span>Lainnya / Dosen</span>
                            </div>
                        </div>
                        <?php if (!empty($surat['disposisi_kepada'])): ?>
                            <div style="margin-top:12px; font-size:12px; color:#1a5632;">
                                <strong>Catatan Pejabat Tujuan:</strong><br>
                                <?= sanitize($surat['disposisi_kepada']) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="width:50%; vertical-align:top;">
                        <strong>PETUNJUK / INSTRUKSI :</strong>
                        <div style="margin-top:10px;" class="checkbox-grid">
                            <div class="checkbox-item">
                                <span class="box-check"></span>
                                <span>Tindak lanjuti & selesaikan</span>
                            </div>
                            <div class="checkbox-item">
                                <span class="box-check"></span>
                                <span>Siapkan bahan / balasan</span>
                            </div>
                            <div class="checkbox-item">
                                <span class="box-check"></span>
                                <span>Hadiri / Wakili</span>
                            </div>
                            <div class="checkbox-item">
                                <span class="box-check"></span>
                                <span>Koordinasikan segera</span>
                            </div>
                            <div class="checkbox-item">
                                <span class="box-check"></span>
                                <span>Pelajari / Telaah</span>
                            </div>
                            <div class="checkbox-item">
                                <span class="box-check"></span>
                                <span>Arsipkan / Simpan</span>
                            </div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="height:110px; vertical-align:top;">
                        <strong>CATATAN / INSTRUKSI KHUSUS PIMPINAN :</strong>
                        <div style="margin-top:8px; font-style:italic; font-size:13px; color:#111;">
                            <?= !empty($surat['disposisi_instruksi']) ? nl2br(sanitize($surat['disposisi_instruksi'])) : '................................................................................................................................................................................................................' ?>
                        </div>
                    </td>
                </tr>
            </table>

            <!-- Kolom Tanda Tangan -->
            <div style="margin-top:24px; display:flex; justify-content:flex-end; text-align:center;">
                <div style="width:240px;">
                    <div>Dicetak pada: <?= date('d F Y') ?></div>
                    <div style="font-weight:bold; margin-top:4px;">Ketua LPPAI</div>
                    <div style="height:70px;"></div>
                    <div style="font-weight:bold; text-decoration:underline;">Zainul Hakim, M.Pd.I</div>
                    <div>NIP/NIDN. LPPAI</div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── MODE 2: REKAPITULASI BUKU AGENDA INVENTARIS SURAT ───────────
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

$stmtData = $pdo->prepare("SELECT * FROM surat_inventaris WHERE $whereClause ORDER BY tanggal_surat DESC, id DESC");
$stmtData->execute($params);
$rows = $stmtData->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Agenda & Inventaris Surat - LPPAI Corner</title>
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
            line-height: 1.35;
        }
        .no-print {
            max-width: 1060px;
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
            max-width: 1060px;
            margin: 0 auto;
            padding: 26px 32px;
            border: 1px solid #ccc;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            box-sizing: border-box;
        }
        /* KOP SURAT */
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
            margin-bottom: 14px;
        }
        .title-box h3 {
            margin: 0;
            font-size: 15px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .meta-info {
            font-size: 11px;
            color: #444;
            margin-top: 4px;
        }

        table.rekap-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table.rekap-table th, table.rekap-table td {
            border: 1px solid #333;
            padding: 6px 8px;
            vertical-align: top;
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

        .signature-container {
            display: flex;
            justify-content: space-between;
            margin-top: 24px;
            page-break-inside: avoid;
        }
        .signature-box {
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
        <a href="inventaris-surat.php" class="btn btn-back">🔙 Kembali ke Inventaris Surat</a>
        <div>
            <button onclick="window.print()" class="btn btn-print">🖨️ Cetak / Simpan PDF</button>
        </div>
    </div>

    <div class="sheet">
        <!-- Kop Surat -->
        <div class="kop">
            <img src="<?= BASE_URL ?>/assets/logo.svg" alt="LPPAI Logo">
            <div class="kop-text">
                <h2>LEMBAGA PENGEMBANGAN PENDIDIKAN AGAMA ISLAM (LPPAI)</h2>
                <h1>BUKU AGENDA & INVENTARIS SURAT</h1>
                <p>Website: https://lppai.quizb.my.id &bull; Surel: lppai@institusi.ac.id</p>
            </div>
        </div>

        <div class="title-box">
            <h3>
                LAPORAN AGENDA <?= $filterJenis === 'masuk' ? 'SURAT MASUK' : ($filterJenis === 'keluar' ? 'SURAT KELUAR' : 'SURAT MASUK & SURAT KELUAR') ?>
            </h3>
            <div class="meta-info">
                <?php if ($filterTglAwal || $filterTglAkhir): ?>
                    Periode: <?= $filterTglAwal ? date('d/m/Y', strtotime($filterTglAwal)) : 'Awal' ?> s/d <?= $filterTglAkhir ? date('d/m/Y', strtotime($filterTglAkhir)) : 'Sekarang' ?> &bull;
                <?php endif; ?>
                Total Surat: <?= count($rows) ?> berkas &bull; Dicetak pada: <?= date('d F Y, H:i') ?> WIB
            </div>
        </div>

        <table class="rekap-table">
            <thead>
                <tr>
                    <th style="width:30px;">No</th>
                    <th style="width:55px;">Jenis</th>
                    <th style="width:75px;">No. Agenda</th>
                    <th style="width:130px;">Nomor & Tanggal Surat</th>
                    <th style="width:130px;">Asal / Tujuan Surat</th>
                    <th>Perihal & Kategori</th>
                    <th style="width:65px;">Sifat</th>
                    <th style="width:90px;">Lokasi Arsip</th>
                    <th style="width:70px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center; padding:20px; color:#666;">
                            Tidak ada data inventaris surat yang sesuai dengan kriteria filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $no = 1; foreach ($rows as $r): ?>
                    <tr>
                        <td style="text-align:center;"><?= $no++ ?></td>
                        <td style="text-align:center; font-weight:bold;">
                            <?= $r['jenis_surat'] === 'masuk' ? 'Masuk' : 'Keluar' ?>
                        </td>
                        <td style="text-align:center;"><?= sanitize($r['nomor_agenda'] ?: '-') ?></td>
                        <td>
                            <strong><?= sanitize($r['nomor_surat']) ?></strong><br>
                            <span style="font-size:10.5px; color:#444;">Tgl: <?= date('d/m/Y', strtotime($r['tanggal_surat'])) ?></span>
                        </td>
                        <td>
                            <?php if ($r['jenis_surat'] === 'masuk'): ?>
                                <small style="color:#15803d; font-weight:bold;">Dari:</small> <?= sanitize($r['pengirim'] ?: '-') ?>
                            <?php else: ?>
                                <small style="color:#c2410c; font-weight:bold;">Tujuan:</small> <?= sanitize($r['penerima'] ?: '-') ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= sanitize($r['perihal']) ?></strong><br>
                            <span style="font-size:10.5px; color:#555;">[<?= sanitize($r['kategori']) ?>]</span>
                            <?php if (!empty($r['disposisi_kepada'])): ?>
                                <br><small style="color:#1d4ed8;">Disp: <?= sanitize($r['disposisi_kepada']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;"><?= sanitize($r['sifat']) ?></td>
                        <td><?= sanitize($r['lokasi_arsip'] ?: '-') ?></td>
                        <td style="text-align:center;"><?= sanitize($r['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Tanda Tangan Laporan -->
        <div class="signature-container">
            <div class="signature-box">
                <div>Petugas Administrasi & Arsip,</div>
                <div style="height:65px;"></div>
                <div style="font-weight:bold; text-decoration:underline;">Staff Sekretariat LPPAI</div>
                <div>Pengelola Inventaris Surat</div>
            </div>

            <div class="signature-box">
                <div>Mengetahui,</div>
                <div style="font-weight:bold;">Ketua LPPAI</div>
                <div style="height:65px;"></div>
                <div style="font-weight:bold; text-decoration:underline;">Zainul Hakim, M.Pd.I</div>
                <div>NIP/NIDN. LPPAI</div>
            </div>
        </div>
    </div>
</body>
</html>
