<?php
/**
 * LPPAI Corner - Cetak & Bagikan Jadwal Dosen Penguji Pretes
 * Format resmi siap cetak (A4 Landscape) & siap share ke grup WhatsApp para tutor
 */
define('PAGE_TITLE', 'Cetak Jadwal Penguji Pretes');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/pretes-helper.php';

$pdo = getDBConnection();
ensurePretesFeatures($pdo);

$selectedPeriod = trim($_GET['periode'] ?? '');
$currentMaster = null;

if (!empty($selectedPeriod)) {
    $stmtM = $pdo->prepare("SELECT * FROM master_pretes WHERE periode = ? LIMIT 1");
    $stmtM->execute([$selectedPeriod]);
    $currentMaster = $stmtM->fetch();
}

if (!$currentMaster) {
    $currentMaster = $pdo->query("SELECT * FROM master_pretes WHERE status = 'aktif' ORDER BY id DESC LIMIT 1")->fetch()
                  ?: $pdo->query("SELECT * FROM master_pretes ORDER BY id DESC LIMIT 1")->fetch();
}

if (!$currentMaster) {
    echo "Periode pretes tidak ditemukan.";
    exit;
}

$periodeName = $currentMaster['periode'];
$tahunAjaran = $currentMaster['tahun_ajaran'];
$jamPelaksanaan = $currentMaster['jam'] ?: '13.00 WIB (Jam 1)';

// Daftar Dosen
$tutorsList = $pdo->query("SELECT nim as id, nama_lengkap as nama FROM users WHERE role = 'dosen' ORDER BY nama_lengkap ASC")->fetchAll();

// Helper parser daftar penguji
function parsePengujiListLocal($pengujiStr, $tutorsList = []) {
    if (empty($pengujiStr) || trim($pengujiStr) === '') return [];
    $pengujiStr = trim($pengujiStr);
    if (strpos($pengujiStr, '|||') !== false) {
        return array_values(array_filter(array_map('trim', explode('|||', $pengujiStr)), fn($v) => $v !== ''));
    }
    return [$pengujiStr];
}

// Ambil data ruangan dan kuota per hari & per penguji dari pretes_attendance_sessions
$stmtSess = $pdo->prepare("SELECT hari, penguji, ruangan, jam, kuota FROM pretes_attendance_sessions WHERE periode = ?");
$stmtSess->execute([$periodeName]);
$sessionRooms = [];
$sessionKuotas = [];
foreach ($stmtSess->fetchAll() as $sr) {
    $h = ucfirst(strtolower(trim($sr['hari'])));
    $p = trim($sr['penguji']);
    if (!empty($sr['ruangan'])) {
        $sessionRooms[$h][$p] = trim($sr['ruangan']);
    }
    if (!empty($sr['kuota'])) {
        $sessionKuotas[$h][$p] = (int)$sr['kuota'];
    }
}

// Ambil jumlah peserta terplot per dosen dari pretes_registrations
$stmtTutorMhs = $pdo->prepare("
    SELECT hari_pilihan, dosen_penguji, COUNT(*) as jml
    FROM pretes_registrations
    WHERE periode = ? AND dosen_penguji IS NOT NULL AND dosen_penguji != ''
    GROUP BY hari_pilihan, dosen_penguji
");
$stmtTutorMhs->execute([$periodeName]);
$tutorTerplot = [];
foreach ($stmtTutorMhs->fetchAll() as $row) {
    $h = ucfirst(strtolower(trim($row['hari_pilihan'])));
    $p = trim($row['dosen_penguji']);
    $tutorTerplot[$h][$p] = (int)$row['jml'];
}

$days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
$dates = [
    'Senin'  => '2 Nov',
    'Selasa' => '3 Nov',
    'Rabu'   => '4 Nov',
    'Kamis'  => '5 Nov',
    'Jumat'  => '6 Nov'
];
$datesFull = [
    'Senin'  => '2 November 2026',
    'Selasa' => '3 November 2026',
    'Rabu'   => '4 November 2026',
    'Kamis'  => '5 November 2026',
    'Jumat'  => '6 November 2026'
];

// Helper untuk cari kuota & terplot tutor
function getTutorDetailLocal($day, $pName, $sessionRooms, $sessionKuotas, $tutorTerplot) {
    $rName = $sessionRooms[$day][$pName] ?? '';
    if (empty($rName)) {
        foreach (($sessionRooms[$day] ?? []) as $k => $v) {
            if (strcasecmp($k, $pName) === 0 || stripos($k, $pName) !== false || stripos($pName, $k) !== false) {
                $rName = $v;
                break;
            }
        }
    }

    $kuota = $sessionKuotas[$day][$pName] ?? null;
    if ($kuota === null) {
        foreach (($sessionKuotas[$day] ?? []) as $k => $v) {
            if (strcasecmp($k, $pName) === 0 || stripos($k, $pName) !== false || stripos($pName, $k) !== false) {
                $kuota = (int)$v;
                break;
            }
        }
    }

    $terplot = $tutorTerplot[$day][$pName] ?? 0;
    if ($terplot === 0) {
        foreach (($tutorTerplot[$day] ?? []) as $k => $v) {
            if (strcasecmp($k, $pName) === 0 || stripos($k, $pName) !== false || stripos($pName, $k) !== false) {
                $terplot = (int)$v;
                break;
            }
        }
    }

    if (!$kuota) {
        $kuota = $terplot > 0 ? $terplot : 30;
    }

    return [
        'name'    => $pName,
        'room'    => $rName,
        'kuota'   => $kuota,
        'terplot' => $terplot,
    ];
}

// Siapkan data per hari
$scheduleData = [];
foreach ($days as $day) {
    $dayLower = strtolower($day);
    $pengujiStr = $currentMaster["penguji_$dayLower"] ?? '';
    $pengujiArr = parsePengujiListLocal($pengujiStr, $tutorsList);
    
    // Gabungkan dengan penguji dari sesi jika ada
    if (isset($sessionRooms[$day])) {
        foreach (array_keys($sessionRooms[$day]) as $sessPenguji) {
            if (!in_array($sessPenguji, $pengujiArr)) {
                $pengujiArr[] = $sessPenguji;
            }
        }
    }

    $tutorsDetail = [];
    foreach ($pengujiArr as $pName) {
        $tutorsDetail[] = getTutorDetailLocal($day, $pName, $sessionRooms, $sessionKuotas, $tutorTerplot);
    }

    $scheduleData[$day] = [
        'day'           => $day,
        'date'          => $dates[$day],
        'date_full'     => $datesFull[$day],
        'penguji'       => $pengujiArr,
        'tutors_detail' => $tutorsDetail,
    ];
}

// Buat teks WhatsApp otomatis siap salin (menampilkan kuota terplot per dosen, tanpa kuota di bagian bawah)
$waText = "🌟 *JADWAL DOSEN PENGUJI PRETES LPPAI UNISDA*\n";
$waText .= "*Periode:* " . $periodeName . "\n";
$waText .= "*Tahun Ajaran:* " . $tahunAjaran . "\n";
$waText .= "*Pelaksanaan:* 2 – 6 November 2026\n";
$waText .= "*Waktu Ujian:* " . $jamPelaksanaan . "\n";
$waText .= "--------------------------------------------------\n\n";

foreach ($scheduleData as $d) {
    $waText .= "📅 *" . strtoupper($d['day']) . " (" . $d['date_full'] . ")* - " . $jamPelaksanaan . "\n";
    $waText .= "• *Dosen Penguji & Kuota Terplot:*\n";
    if (empty($d['tutors_detail'])) {
        $waText .= "  _(Belum ada penguji yang diplot)_\n";
    } else {
        $n = 1;
        foreach ($d['tutors_detail'] as $td) {
            $rm = !empty($td['room']) ? " (" . $td['room'] . ")" : "";
            $waText .= "  " . $n++ . ". " . $td['name'] . $rm . " - Kuota Terplot: " . $td['kuota'] . " Peserta";
            if ($td['terplot'] > 0 && $td['terplot'] != $td['kuota']) {
                $waText .= " (" . $td['terplot'] . " terisi)";
            }
            $waText .= "\n";
        }
    }
    $waText .= "\n";
}

$waText .= "--------------------------------------------------\n";
$waText .= "⚠️ *Catatan:* Diharapkan Bapak/Ibu Dosen Penguji hadir 15 menit sebelum pelaksanaan ujian. Terima kasih.\n\n";
$waText .= "Kepala LPPAI UNISDA,\n";
$waText .= "*Dr. Zainul Hakim. M.H.I.*\n\n";
$waText .= "_Lembaga Pengembangan Pendidikan Agama Islam (LPPAI) Universitas Islam Darul 'Ulum Lamongan_";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Dosen Penguji Pretes - <?= sanitize($periodeName) ?> - LPPAI UNISDA</title>
    <link rel="icon" href="<?= BASE_URL ?>/assets/logo.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            padding: 24px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ── ACTION TOOLBAR ── */
        .no-print-toolbar {
            max-width: 1280px;
            margin: 0 auto 20px auto;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 700;
            border-radius: 8px;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.2s;
        }
        .btn-print {
            background: #0284c7;
            color: #ffffff;
        }
        .btn-print:hover { background: #0369a1; }
        .btn-wa-copy {
            background: #10b981;
            color: #ffffff;
        }
        .btn-wa-copy:hover { background: #059669; }
        .btn-wa-direct {
            background: #25d366;
            color: #ffffff;
        }
        .btn-wa-direct:hover { background: #1ebd5a; }
        .btn-back {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
        }
        .btn-back:hover { background: #e2e8f0; }

        /* ── PRINT SHEET CONTAINER ── */
        .print-sheet {
            max-width: 1280px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 28px 32px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
        }

        /* ── OFFICIAL LETTERHEAD KOP SURAT ── */
        .kop-surat {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            padding-bottom: 14px;
            border-bottom: 2px solid #0f172a;
            margin-bottom: 18px;
            text-align: center;
        }
        .kop-logo {
            width: 72px;
            height: 72px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .kop-instansi h2 {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        .kop-instansi h1 {
            font-size: 19px;
            font-weight: 900;
            color: #1e3a8a;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 3px;
        }
        .kop-instansi p {
            font-size: 12px;
            color: #475569;
            font-weight: 500;
        }

        /* ── CALLOUT BANNER (PERSIS GAMBAR USER) ── */
        .periode-banner {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .periode-banner h3 {
            font-size: 16px;
            font-weight: 800;
            color: #1e40af;
            margin-bottom: 4px;
        }
        .periode-banner p {
            font-size: 13.5px;
            color: #1d4ed8;
            font-weight: 600;
        }

        /* ── SECTION HEADER ── */
        .section-header-box {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            font-size: 15px;
            font-weight: 800;
            color: #1e293b;
        }

        /* ── 5-COLUMN DAY GRID ── */
        .schedule-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            background: #f8fafc;
            padding: 16px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        .day-column {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .day-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1.5px solid #f1f5f9;
        }
        .day-title {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
        }
        .day-title span {
            color: #0284c7;
            font-weight: 700;
        }
        .time-badge {
            font-size: 11px;
            background: #e2e8f0;
            color: #334155;
            padding: 3px 6px;
            border-radius: 4px;
            font-weight: 700;
            white-space: nowrap;
        }

        /* Tutor Items inside day column */
        .tutor-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }
        .tutor-item {
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            padding: 9px 10px;
            font-size: 12px;
            color: #1e293b;
            line-height: 1.35;
        }
        .tutor-name {
            font-weight: 700;
            color: #0f172a;
            font-size: 12.5px;
            line-height: 1.35;
        }
        .tutor-room {
            display: block;
            font-size: 11px;
            color: #0284c7;
            font-weight: 700;
            margin-top: 3px;
        }
        .tutor-kuota-badge {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4px;
            margin-top: 6px;
            font-size: 11px;
            color: #1e3a8a;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            padding: 3px 8px;
            border-radius: 5px;
            font-weight: 600;
            box-sizing: border-box;
        }

        /* Signature block for formal print */
        .print-signature {
            display: flex;
            margin-top: 30px;
            justify-content: flex-end;
        }
        .signature-box {
            text-align: center;
            width: 260px;
            font-size: 13px;
        }
        .signature-name {
            font-weight: 800;
            text-decoration: underline;
            color: #0f172a;
            font-size: 14px;
        }

        /* ── MEDIA PRINT OPTIMIZATION (A4 LANDSCAPE) ── */
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
            }
            .no-print, .no-print-toolbar {
                display: none !important;
            }
            .print-sheet {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                max-width: 100% !important;
            }
            .schedule-grid {
                grid-template-columns: repeat(5, 1fr) !important;
                gap: 8px !important;
                padding: 10px !important;
                background: #ffffff !important;
                border: 1px solid #cbd5e1 !important;
            }
            .day-column {
                border: 1px solid #cbd5e1 !important;
                padding: 8px !important;
                box-shadow: none !important;
            }
            .tutor-item {
                font-size: 10.5px !important;
                padding: 6px 7px !important;
                border: 1px solid #cbd5e1 !important;
            }
            .tutor-name {
                font-size: 11px !important;
            }
            .tutor-kuota-badge {
                font-size: 9.5px !important;
                padding: 2px 5px !important;
            }
            .print-signature {
                display: flex !important;
            }
            @page {
                size: landscape;
                margin: 10mm;
            }
        }

        @media (max-width: 1024px) {
            .schedule-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 640px) {
            .schedule-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <!-- ── TOOLBAR ATAS (HANYA MUNCUL DI LAYAR) ── -->
    <div class="no-print-toolbar no-print">
        <div style="display:flex; align-items:center; gap:8px;">
            <a href="<?= BASE_URL ?>/admin/pretes-pengaturan-tutor.php?periode=<?= urlencode($periodeName) ?>&tab=sesi" class="btn-action btn-back">
                🔙 Kembali ke Pengaturan Sesi
            </a>
            <span style="font-size:13px; color:#64748b; font-weight:600;">Jadwal Siap Cetak & Siap Share</span>
        </div>

        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <button type="button" onclick="copyWaText()" class="btn-action btn-wa-copy" id="btn-copy-wa">
                📋 Salin Teks WhatsApp
            </button>
            <a href="https://api.whatsapp.com/send?text=<?= rawurlencode($waText) ?>" target="_blank" class="btn-action btn-wa-direct">
                📲 Bagikan ke WhatsApp
            </a>
            <button type="button" onclick="window.print()" class="btn-action btn-print">
                🖨️ Cetak / Simpan PDF
            </button>
        </div>
    </div>

    <!-- ── LEMBAR JADWAL UTAMA ── -->
    <div class="print-sheet">

        <!-- KOP SURAT LPPAI UNISDA -->
        <div class="kop-surat">
            <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo UNISDA" class="kop-logo">
            <div class="kop-instansi">
                <h2>UNIVERSITAS ISLAM DARUL 'ULUM LAMONGAN</h2>
                <h1>LEMBAGA PEMBINAAN DAN PENGEMBANGAN AGAMA ISLAM ( LPPAI )</h1>
                <p>Kantor: Gedung FAI Lt. 1, Jl. Airlangga 3 Sukodadi, Lamongan, Jawa Timur 62253</p>
            </div>
        </div>

        <!-- CALLOUT BANNER (PERSIS GAMBAR USER) -->
        <div class="periode-banner">
            <div>
                <h3>🌟 Periode Pretes Aktif: <strong><?= sanitize($periodeName) ?></strong></h3>
                <p>
                    Tahun Ajaran: <strong><?= sanitize($tahunAjaran) ?></strong> | 
                    Pelaksanaan: <strong>2 – 6 November 2026</strong> | 
                    Jam: <strong><?= sanitize($jamPelaksanaan) ?></strong>
                </p>
            </div>
        </div>

        <!-- SECTION HEADER -->
        <div class="section-header-box">
            <span>👨‍🏫</span> Pengaturan Dosen & Kuota Terplot (Jadwal Penguji Pretes)
        </div>

        <!-- 5-COLUMN DAY GRID -->
        <div class="schedule-grid">
            <?php foreach ($scheduleData as $d): ?>
            <div class="day-column">
                <div>
                    <!-- Header Hari & Jam -->
                    <div class="day-header">
                        <div class="day-title">
                            <?= $d['day'] ?> <span>(<?= $d['date'] ?>)</span>
                        </div>
                        <span class="time-badge">13.00 WIB</span>
                    </div>

                    <!-- Daftar Dosen Penguji & Kuota Terplot Masing-Masing -->
                    <div class="tutor-list">
                        <?php if (empty($d['tutors_detail'])): ?>
                            <div style="font-size:11.5px; color:#94a3b8; font-style:italic; padding:6px;">
                                Belum ada penguji diplot
                            </div>
                        <?php else: ?>
                            <?php foreach ($d['tutors_detail'] as $td): ?>
                            <div class="tutor-item">
                                <div class="tutor-name"><?= sanitize($td['name']) ?></div>
                                <?php if (!empty($td['room'])): ?>
                                <span class="tutor-room">🏢 <?= sanitize($td['room']) ?></span>
                                <?php endif; ?>
                                <div class="tutor-kuota-badge">
                                    <span>👥 Kuota Terplot: <strong><?= $td['kuota'] ?> Peserta</strong></span>
                                    <?php if ($td['terplot'] > 0 && $td['terplot'] != $td['kuota']): ?>
                                    <span style="color:#0369a1; font-weight:700;">(<?= $td['terplot'] ?> terisi)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- TANDA TANGAN FORMAL UNTUK DOKUMEN CETAK -->
        <div class="print-signature">
            <div class="signature-box">
                <p>Lamongan, <?= date('d F Y') ?></p>
                <p style="margin-top:4px;">Kepala LPPAI UNISDA,</p>
                <div style="position: relative; height: 75px; margin: 4px 0; display: flex; justify-content: center; align-items: center;">
                    <img src="<?= BASE_URL ?>/assets/img/ttd_zainul_hakim.png" alt="Tanda Tangan" style="height: 75px; object-fit: contain; mix-blend-mode: multiply;">
                </div>
                <div class="signature-name">Dr. Zainul Hakim. M.H.I.</div>
            </div>
        </div>

    </div>

    <!-- Hidden Textarea for WhatsApp Message -->
    <textarea id="raw-wa-text" style="position:absolute; left:-9999px; top:-9999px;"><?= htmlspecialchars($waText, ENT_QUOTES, 'UTF-8') ?></textarea>

    <script>
    function copyWaText() {
        const text = document.getElementById('raw-wa-text').value;
        const btn = document.getElementById('btn-copy-wa');
        navigator.clipboard.writeText(text).then(() => {
            const oldText = btn.innerHTML;
            btn.innerHTML = '✅ Berhasil Disalin!';
            btn.style.background = '#059669';
            setTimeout(() => {
                btn.innerHTML = oldText;
                btn.style.background = '#10b981';
            }, 2500);
        }).catch(err => {
            prompt('Salin teks jadwal berikut untuk WhatsApp:', text);
        });
    }
    </script>
</body>
</html>