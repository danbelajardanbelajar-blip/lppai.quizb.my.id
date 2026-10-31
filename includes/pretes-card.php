<?php
/** Tampilan kartu; hanya dipanggil setelah autentikasi oleh cetak-kartu-pretes.php. */
if (!isset($user, $registration)) {
    http_response_code(403);
    exit('Akses ditolak.');
}
$cardValue = static function ($value, $fallback = 'Belum tersedia') {
    $value = trim((string)($value ?? ''));
    return $value !== '' ? $value : $fallback;
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kartu Peserta Pretes - <?= sanitize($user['nim'] ?? '') ?></title>
    <link rel="icon" href="<?= BASE_URL ?>/assets/logo.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px 16px;
            background: #eef3f8;
            color: #0f172a;
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            font-size: 12.5px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .toolbar, .card {
            width: 100%;
            max-width: 860px;
            margin: 0 auto;
        }

        /* ── Toolbar Aksi Layar ── */
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 18px;
            padding: 12px 18px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
        }
        .toolbar-info {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #475569;
            font-size: 12px;
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 40px;
            padding: 8px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #ffffff;
            color: #334155;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.18s ease;
        }
        .button:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
            color: #0f172a;
        }
        .button-primary {
            border-color: #0284c7;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(2, 132, 199, 0.25);
        }
        .button-primary:hover {
            filter: brightness(1.08);
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.35);
            color: #ffffff;
        }

        /* ── Kartu Utama ── */
        .card {
            position: relative;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-top: 5px solid #0369a1;
            border-radius: 14px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.07);
            overflow: hidden;
        }

        /* Watermark lambang di latar belakang */
        .card-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 360px;
            height: 360px;
            background-image: url('<?= BASE_URL ?>/assets/logo.svg');
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.035;
            pointer-events: none;
            z-index: 0;
        }

        /* ── Kop Surat Resmi UNISDA LPPAI ── */
        .brand {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 18px 24px 14px;
            background: #ffffff;
        }
        .brand-logo {
            width: 72px;
            height: 72px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .brand-text {
            flex: 1;
            text-align: center;
            min-width: 0;
        }
        .inst-uni {
            margin: 0;
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: 0.6px;
            text-transform: uppercase;
        }
        .inst-lppai {
            margin: 2px 0;
            font-size: 13.5px;
            font-weight: 800;
            color: #0369a1;
            letter-spacing: 0.3px;
        }
        .inst-address {
            margin: 0;
            font-size: 11px;
            color: #475569;
            line-height: 1.35;
        }
        .kop-rule {
            height: 3px;
            border-top: 2.5px solid #0f172a;
            border-bottom: 1px solid #0f172a;
            margin: 0 24px;
        }

        /* ── Banner Judul Kartu ── */
        .card-header-bar {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-bottom: 1px solid #bae6fd;
        }
        .eyebrow {
            margin: 0;
            color: #0369a1;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 1.2px;
            text-transform: uppercase;
        }
        h1 {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
            color: #0369a1;
            letter-spacing: 0.2px;
            text-transform: uppercase;
        }
        .program-tag {
            font-size: 12px;
            font-weight: 700;
            color: #0284c7;
            margin-top: 2px;
        }
        .badge-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border: 1px solid #bae6fd;
            border-radius: 20px;
            background: #ffffff;
            color: #0369a1;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge::before {
            content: "";
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #0284c7;
        }
        .card-id-pill {
            font-family: 'JetBrains Mono', monospace;
            font-size: 11.5px;
            font-weight: 700;
            color: #0f172a;
            background: #ffffff;
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
        }

        /* ── Konten Utama ── */
        .content {
            position: relative;
            z-index: 1;
            padding: 18px 24px;
        }

        /* ── Grid Identitas & Jadwal (Kiri) + Pas Foto (Kanan) ── */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 140px;
            gap: 16px;
            align-items: start;
            margin-bottom: 16px;
        }
        .data-column {
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-width: 0;
        }
        .section-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 14px;
        }
        h2 {
            margin: 0 0 8px;
            font-size: 11px;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #0369a1;
            font-weight: 800;
            border-bottom: 1px dashed #e2e8f0;
            padding-bottom: 4px;
        }
        dl {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 16px;
            margin: 0;
        }
        dl > div { min-width: 0; }
        dt {
            margin-bottom: 1px;
            color: #64748b;
            font-size: 10.5px;
            font-weight: 600;
            text-transform: uppercase;
        }
        dd {
            margin: 0;
            color: #0f172a;
            font-size: 12.5px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }
        .full { grid-column: 1 / -1; }
        .student-name {
            font-size: 15px;
            font-weight: 800;
            color: #0369a1;
            line-height: 1.3;
        }
        .mono-val {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12.5px;
        }

        /* Pas Foto Box */
        .photo-column {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .photo-box {
            width: 130px;
            height: 165px;
            border: 1.5px dashed #94a3b8;
            border-radius: 8px;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 6px;
            text-align: center;
            box-sizing: border-box;
        }
        .photo-box svg {
            color: #94a3b8;
            margin-bottom: 4px;
        }
        .photo-title {
            font-size: 10px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
        }
        .photo-dim {
            font-size: 13px;
            font-weight: 800;
            color: #334155;
            margin: 2px 0;
        }
        .photo-hint {
            font-size: 9px;
            color: #94a3b8;
            line-height: 1.25;
        }

        /* ── Kotak Akun CBT E-Ujian ── */
        .cbt-account-box {
            background: #fffbeb;
            border: 1.5px solid #fde68a;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }
        .cbt-account-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 800;
            color: #92400e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .cbt-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }
        .cbt-item {
            background: #ffffff;
            border: 1px solid #fef3c7;
            border-radius: 8px;
            padding: 8px 12px;
            text-align: center;
        }
        .cbt-lbl {
            font-size: 10px;
            font-weight: 700;
            color: #78350f;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        .cbt-val {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            font-weight: 800;
            color: #b45309;
            word-break: break-all;
        }

        /* ── Tabel Kisi-Kisi Soal & Kriteria Penilaian (Sesuai Gambar) ── */
        .syllabus-section {
            margin-bottom: 16px;
        }
        .syllabus-title {
            text-align: center;
            margin: 0 0 8px;
            font-size: 12px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }
        .syllabus-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            border: 1.5px solid #0f172a;
        }
        .syllabus-table th, .syllabus-table td {
            border: 1px solid #0f172a;
            padding: 6px 8px;
            vertical-align: top;
            line-height: 1.35;
        }
        .syllabus-table th {
            background: #f1f5f9;
            color: #0f172a;
            font-weight: 800;
            text-align: center;
            text-transform: uppercase;
            font-size: 10.5px;
        }
        .syllabus-table td.col-no {
            text-align: center;
            font-weight: 700;
            width: 32px;
        }
        .syllabus-table td.col-materi {
            width: 52%;
        }
        .syllabus-table td.col-kriteria {
            width: 44%;
        }
        .syllabus-heading {
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
        }
        .syllabus-sub {
            margin: 2px 0 0;
            color: #334155;
        }
        .syllabus-list {
            margin: 2px 0 0;
            padding-left: 14px;
            color: #334155;
        }
        .syllabus-list li {
            margin-bottom: 2px;
        }
        .syllabus-footer-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 6px;
            font-weight: 800;
            font-size: 11.5px;
        }
        .passing-grade {
            color: #0f172a;
        }
        .lppai-unisda-brand {
            color: #16a34a;
            letter-spacing: 0.6px;
        }

        /* ── Peraturan & Tata Tertib (Dari Halaman Panduan Mahasiswa) ── */
        .rules-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #0369a1;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 16px;
        }
        .rules-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 800;
            color: #0369a1;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
        }
        .rules-list {
            margin: 0;
            padding-left: 16px;
            font-size: 11px;
            color: #334155;
            line-height: 1.45;
        }
        .rules-list li {
            margin-bottom: 4px;
        }
        .rules-list li:last-child {
            margin-bottom: 0;
        }

        /* ── Lembar Pengesahan Tanda Tangan ── */
        .signature-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px dashed #cbd5e1;
        }
        .sig-box {
            text-align: center;
            font-size: 11px;
            color: #334155;
        }
        .sig-role {
            font-weight: 700;
            color: #0f172a;
        }
        .sig-sub {
            font-size: 10px;
            color: #64748b;
        }
        .sig-space {
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .sig-stamp {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border: 1px dashed #0284c7;
            border-radius: 4px;
            color: #0369a1;
            font-size: 9px;
            font-weight: 700;
            background: #f0f9ff;
        }
        .sig-name {
            font-weight: 700;
            color: #0f172a;
            border-top: 1px solid #94a3b8;
            padding-top: 3px;
            display: inline-block;
            min-width: 170px;
        }

        /* ── Footer Dokumen ── */
        .footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            padding: 10px 24px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #64748b;
            font-size: 10.5px;
        }

        /* ── Empty State ── */
        .empty {
            text-align: center;
            padding: 50px 24px;
        }
        .empty h2 {
            font-size: 16px;
            color: #0f172a;
            border: none;
        }
        .empty p {
            color: #64748b;
            margin: 0 auto 20px;
            max-width: 440px;
        }

        /* ── Responsive Screen ── */
        @media (max-width: 680px) {
            body { padding: 14px 10px; }
            .brand { flex-direction: column; text-align: center; }
            .main-grid { grid-template-columns: 1fr; }
            .photo-column { align-items: center; }
            .cbt-grid { grid-template-columns: 1fr; }
            .syllabus-table { font-size: 10px; }
        }

        /* ── Print Stylesheet Presisi ── */
        @page {
            size: A4 portrait;
            margin: 8mm 10mm;
        }
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            body {
                padding: 0;
                background: #ffffff !important;
                color: #000000 !important;
                font-size: 11px;
            }
            .no-print {
                display: none !important;
            }
            .card {
                max-width: 100% !important;
                box-shadow: none !important;
                border: 1.5px solid #0f172a !important;
                border-top: 4px solid #0369a1 !important;
                border-radius: 0 !important;
                overflow: visible !important;
            }
            .card-watermark {
                opacity: 0.04 !important;
            }
            .brand {
                padding: 10px 16px 8px !important;
            }
            .brand-logo {
                width: 60px !important;
                height: 60px !important;
            }
            .inst-uni { font-size: 13.5px !important; }
            .inst-lppai { font-size: 12px !important; }
            .kop-rule { margin: 0 16px !important; }
            .card-header-bar {
                padding: 8px 16px !important;
                background: #f1f5f9 !important;
            }
            .content {
                padding: 10px 16px !important;
            }
            .main-grid {
                grid-template-columns: 1fr 115px !important;
                gap: 12px !important;
                margin-bottom: 10px !important;
            }
            .section-box {
                padding: 8px 10px !important;
                border: 1px solid #cbd5e1 !important;
            }
            .photo-box {
                width: 105px !important;
                height: 135px !important;
                background: #ffffff !important;
            }
            .cbt-account-box {
                padding: 8px 12px !important;
                margin-bottom: 10px !important;
                background: #fffbeb !important;
            }
            .syllabus-section {
                margin-bottom: 10px !important;
            }
            .syllabus-table th, .syllabus-table td {
                padding: 4px 6px !important;
            }
            .rules-section {
                padding: 8px 10px !important;
                margin-bottom: 10px !important;
            }
            .signature-grid {
                margin-top: 10px !important;
                padding-top: 8px !important;
            }
            .sig-space { height: 34px !important; }
            .footer {
                padding: 6px 16px !important;
                font-size: 9.5px !important;
            }
        }
    </style>
</head>
<body>
    <!-- ── Toolbar Aksi & Panduan Cetak ── -->
    <nav class="toolbar no-print" aria-label="Aksi kartu pretes">
        <div class="actions">
            <a class="button" href="<?= BASE_URL ?>/pretes-peserta.php">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                Kembali ke Pretes
            </a>
            <?php if ($registration): ?>
                <button class="button button-primary" type="button" onclick="window.print()">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9V3h12v6M6 18H3V9h18v9h-3M6 14h12v7H6z"/><path d="M17 12h1"/></svg>
                    Cetak / Simpan PDF
                </button>
            <?php endif; ?>
        </div>
        <div class="toolbar-info">
            <span>Pilih <strong>“Simpan sebagai PDF”</strong> untuk mengunduh berkas kartu peserta Anda.</span>
        </div>
    </nav>

    <!-- ── Dokumen Kartu Peserta Ujian ── -->
    <main class="card">
        <div class="card-watermark" aria-hidden="true"></div>

        <!-- ── Kop Institusi Resmi Sesuai Dokumen LPPAI UNISDA ── -->
        <header class="brand">
            <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo UNISDA LPPAI" class="brand-logo">
            <div class="brand-text">
                <p class="inst-uni">UNIVERSITAS ISLAM DARUL 'ULUM LAMONGAN</p>
                <h2 class="inst-lppai" style="border:none;margin:2px 0;padding:0;">LEMBAGA PEMBINAAN DAN PENGEMBANGAN AGAMA ISLAM ( LPPAI )</h2>
                <p class="inst-address">Kantor: Gedung FAI Lt. 1, Jl. Airlangga 3 Sukodadi, Lamongan, Jawa Timur 62253</p>
            </div>
        </header>

        <div class="kop-rule" aria-hidden="true"></div>

        <!-- ── Bar Judul Kartu ── -->
        <div class="card-header-bar">
            <div>
                <p class="eyebrow" style="margin:0 0 2px;">LPPAI Corner</p>
                <h1>Kartu Peserta Pretes</h1>
                <div class="program-tag">Tutorial Ubudiyah Unisda · Periode <?= sanitize($cardValue($registration['periode'] ?? null)) ?></div>
            </div>
            <div class="badge-group">
                <span class="badge"><?= sanitize(ucfirst(str_replace('_', ' ', $cardValue($registration['status'] ?? null, 'terdaftar')))) ?></span>
                <?php if ($registration): 
                    $cardNumber = 'PRE-' . str_pad((string)$registration['id'], 6, '0', STR_PAD_LEFT);
                ?>
                    <span class="card-id-pill"><?= sanitize($cardNumber) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$registration): ?>
            <!-- Mahasiswa Belum Mendaftar -->
            <div class="empty">
                <h2>Belum terdaftar pretes</h2>
                <p>Silakan lakukan pendaftaran pretes terlebih dahulu melalui portal mahasiswa untuk mencetak kartu peserta resmi Anda.</p>
                <a class="button button-primary no-print" href="<?= BASE_URL ?>/pretes-daftar.php">Daftar Pretes Sekarang</a>
            </div>
        <?php else:
            $cardNumber = 'PRE-' . str_pad((string)$registration['id'], 6, '0', STR_PAD_LEFT);
            
            // Tanggal ujian: tanggal 2 sampai 6 November 2026
            $dayDates = [
                'senin' => '2026-11-02',
                'selasa' => '2026-11-03',
                'rabu'   => '2026-11-04',
                'kamis'  => '2026-11-05',
                'jumat'  => '2026-11-06',
            ];
            $hariLower = strtolower(trim((string)($registration['hari_pilihan'] ?? '')));
            $tanggal = !empty($registration['tanggal']) ? strtotime($registration['tanggal']) : false;
            if ($tanggal === false && isset($dayDates[$hariLower])) {
                $tanggal = strtotime($dayDates[$hariLower]);
            }
            
            $time = $cardValue($registration['jam'] ?? null, '');
            if ($time === '' && !empty($registration['waktu_mulai'])) {
                $time = substr($registration['waktu_mulai'], 0, 5);
                if (!empty($registration['waktu_selesai'])) $time .= ' – ' . substr($registration['waktu_selesai'], 0, 5);
                $time .= ' WIB';
            }
            $nimRaw = trim((string)($user['nim'] ?? ''));
            $angkatan = trim((string)($user['angkatan'] ?? ''));
            if ($angkatan === '' && strlen($nimRaw) >= 2 && ctype_digit(substr($nimRaw, 0, 2))) {
                $angkatan = '20' . substr($nimRaw, 0, 2);
            }
        ?>
            <div class="content">
                <!-- ── Grid Identitas & Jadwal (Kiri) + Pas Foto 3x4 (Kanan) ── -->
                <div class="main-grid">
                    <div class="data-column">
                        <!-- Identitas Peserta -->
                        <section class="section-box identity" aria-labelledby="identitas">
                            <h2 id="identitas">Identitas Peserta</h2>
                            <dl>
                                <div class="full">
                                    <dt>Nama lengkap</dt>
                                    <dd class="student-name"><?= sanitize($cardValue($user['nama_lengkap'] ?? null)) ?></dd>
                                </div>
                                <div>
                                    <dt>NIM</dt>
                                    <dd class="mono-val"><?= sanitize($cardValue($user['nim'] ?? null)) ?></dd>
                                </div>
                                <div>
                                    <dt>Nomor peserta</dt>
                                    <dd class="mono-val" style="color:#0369a1;"><?= sanitize($cardNumber) ?></dd>
                                </div>
                                <div>
                                    <dt>Program studi</dt>
                                    <dd><?= sanitize($cardValue($user['program_studi'] ?? null)) ?></dd>
                                </div>
                                <div>
                                    <dt>Fakultas</dt>
                                    <dd><?= sanitize($cardValue($user['fakultas'] ?? null)) ?></dd>
                                </div>
                                <?php if ($angkatan !== ''): ?>
                                <div>
                                    <dt>Tahun Angkatan</dt>
                                    <dd><?= sanitize($angkatan) ?></dd>
                                </div>
                                <div>
                                    <dt>Program</dt>
                                    <dd>Tutorial Ubudiyah Unisda</dd>
                                </div>
                                <?php endif; ?>
                            </dl>
                        </section>

                        <!-- Jadwal Pelaksanaan -->
                        <section class="section-box schedule" aria-labelledby="jadwal">
                            <h2 id="jadwal">Jadwal Pelaksanaan</h2>
                            <dl>
                                <div>
                                    <dt>Hari pilihan</dt>
                                    <dd><?= sanitize($cardValue($registration['hari_pilihan'] ?? null, 'Menunggu penetapan')) ?></dd>
                                </div>
                                <div>
                                    <dt>Tanggal</dt>
                                    <dd><?= $tanggal !== false ? date('d/m/Y', $tanggal) : 'Menunggu penetapan' ?></dd>
                                </div>
                                <div>
                                    <dt>Jam pelaksanaan</dt>
                                    <dd><?= sanitize($cardValue($time, '13.00 WIB (Jam 1)')) ?></dd>
                                </div>
                                <div>
                                    <dt>Ruangan</dt>
                                    <dd><?= sanitize($cardValue($registration['ruangan'] ?? null, $cardValue($registration['ruangan_jadwal'] ?? null, 'Menunggu pembagian'))) ?></dd>
                                </div>
                                <div class="full">
                                    <dt>Dosen penguji</dt>
                                    <dd><?= sanitize($cardValue($registration['dosen_penguji'] ?? null, 'Menunggu pembagian')) ?></dd>
                                </div>
                            </dl>
                        </section>
                    </div>

                    <!-- Kolom Samping: Pas Foto 3x4 (Barcode Dihilangkan) -->
                    <aside class="photo-column" aria-label="Pas Foto">
                        <div class="photo-box">
                            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                            <span class="photo-title">Pas Foto</span>
                            <span class="photo-dim">3 × 4 CM</span>
                            <span class="photo-hint">Jas Almamater / Busana Muslim Rapi</span>
                        </div>
                    </aside>
                </div>

                <!-- ── Kredensial Akun CBT E-Ujian Mahasiswa (Wajib Dituliskan) ── -->
                <section class="cbt-account-box" aria-label="Akun CBT E-Ujian">
                    <div class="cbt-account-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <span>Akun Login CBT E-Ujian Mahasiswa</span>
                    </div>
                    <div class="cbt-grid">
                        <div class="cbt-item">
                            <div class="cbt-lbl">Kode Sekolah / ID Lembaga</div>
                            <div class="cbt-val">22205</div>
                        </div>
                        <div class="cbt-item">
                            <div class="cbt-lbl">Username CBT</div>
                            <div class="cbt-val"><?= sanitize($cardValue($registration['username_tes'] ?? null, 'Menunggu TU')) ?></div>
                        </div>
                        <div class="cbt-item">
                            <div class="cbt-lbl">Password / Kode Akses</div>
                            <div class="cbt-val"><?= sanitize($cardValue($registration['password_tes'] ?? null, 'Menunggu TU')) ?></div>
                        </div>
                    </div>
                </section>

                <!-- ── KISI-KISI SOAL DAN KRITERIA PENILAIAN PRE TES LPPAI (Sesuai Gambar Dokumen Resmi) ── -->
                <section class="syllabus-section" aria-label="Kisi-Kisi Soal dan Kriteria Penilaian">
                    <div class="syllabus-title">KISI-KISI SOAL DAN KRITERIA PENILAIAN PRE TES LPPAI</div>
                    <table class="syllabus-table">
                        <thead>
                            <tr>
                                <th class="col-no">NO</th>
                                <th class="col-materi">MATERI TES</th>
                                <th class="col-kriteria">KRITERIA PENILAIAN</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="col-no">1</td>
                                <td class="col-materi">
                                    <div class="syllabus-heading">1. Ujian CBT (Wajib Instal E-ujian)</div>
                                    <div class="syllabus-sub">Materi : Najis, alat Thaharah, Wudhu, Tayamum, Mandi, Sholat Fardhu, Sholat Jenazah, Memandikan Jenazah, Amaliyah Masyarakat</div>
                                </td>
                                <td class="col-kriteria">
                                    <ul class="syllabus-list">
                                        <li>Jawaban benar</li>
                                        <li>Jawaban bukan hasil browsing</li>
                                        <li>50 Soal waktu 30 menit</li>
                                    </ul>
                                </td>
                            </tr>
                            <tr>
                                <td class="col-no">2</td>
                                <td class="col-materi">
                                    <div class="syllabus-heading">THAHARAH</div>
                                    <ol class="syllabus-list" style="padding-left:16px;">
                                        <li>Praktek Wudhu</li>
                                        <li>Do'a Setelah Wudhu</li>
                                        <li>Praktek Tayamum</li>
                                        <li>Niat Mandi Besar</li>
                                    </ol>
                                </td>
                                <td class="col-kriteria">
                                    <ul class="syllabus-list">
                                        <li>Kelancaran bacaan</li>
                                        <li>Niat dan kebenaran praktek (Tes Praktek)</li>
                                    </ul>
                                </td>
                            </tr>
                            <tr>
                                <td class="col-no">3</td>
                                <td class="col-materi">
                                    <div class="syllabus-heading">SHALAT</div>
                                    <ol class="syllabus-list" style="padding-left:16px;">
                                        <li>Niat Shalat Jama' dan Qashar</li>
                                        <li>Bacaan Shalat (Subuh)</li>
                                    </ol>
                                </td>
                                <td class="col-kriteria">
                                    <ul class="syllabus-list">
                                        <li>Kebenaran Jawaban (Tes Lisan)</li>
                                        <li>Kelancaran bacaan</li>
                                        <li>Tidak ada kesalahan fatal pada do'a dan gerakan shalat</li>
                                    </ul>
                                </td>
                            </tr>
                            <tr>
                                <td class="col-no">4</td>
                                <td class="col-materi">
                                    <div class="syllabus-heading">SURAT-SURAT PENDEK</div>
                                    <ol class="syllabus-list" style="padding-left:16px;">
                                        <li>Al-Bayyinah</li>
                                        <li>Al-'Alaq</li>
                                        <li>Ad-Dhuha</li>
                                    </ol>
                                </td>
                                <td class="col-kriteria">
                                    <ul class="syllabus-list">
                                        <li>Kelancaran bacaan</li>
                                        <li>Kefasihan bacaan</li>
                                        <li>Mahasiswa tau nama surat (bukan hanya bisa menghafal tapi tidak tau surat apa yang dia baca)</li>
                                    </ul>
                                </td>
                            </tr>
                            <tr>
                                <td class="col-no">5</td>
                                <td class="col-materi">
                                    <div class="syllabus-heading">AMALIYAH DI MASYARAKAT</div>
                                    <ol class="syllabus-list" style="padding-left:16px;">
                                        <li>Istighotsah</li>
                                    </ol>
                                </td>
                                <td class="col-kriteria">
                                    <ul class="syllabus-list">
                                        <li>Kelancaran bacaan</li>
                                        <li>Runtutnya (urut) susunan bacaan</li>
                                    </ul>
                                </td>
                            </tr>
                            <tr>
                                <td class="col-no">6</td>
                                <td class="col-materi">
                                    <div class="syllabus-heading">KIFAYAH JENAZAH</div>
                                    <ol class="syllabus-list" style="padding-left:16px;">
                                        <li>Shalat Jenazah</li>
                                    </ol>
                                </td>
                                <td class="col-kriteria">
                                    <ul class="syllabus-list">
                                        <li>Tidak ada kesalahan fatal pada do'a dan gerakan shalat</li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="syllabus-footer-bar">
                        <span class="passing-grade">NILAI MINIMAL KELULUSAN ( 80 )</span>
                        <span class="lppai-unisda-brand">LPPAI UNISDA</span>
                    </div>
                </section>

                <!-- ── Semua Peraturan Halaman Panduan Pretes Mahasiswa ── -->
                <section class="rules-section" aria-label="Tata Tertib & Petunjuk Ujian">
                    <div class="rules-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span>Tata Tertib &amp; Petunjuk Pelaksanaan Pretes Mahasiswa</span>
                    </div>
                    <ol class="rules-list">
                        <li><strong>Try Out Mandiri dari Rumah:</strong> Manfaatkan waktu sebelum hari pelaksanaan (rentang tanggal 2 s.d. 6 November 2026) untuk mencoba login simulasi dari rumah menggunakan Kode Sekolah <strong>22205</strong> beserta username &amp; password Anda, agar terbiasa dengan aplikasi CBT.</li>
                        <li><strong>Dilarang 2 HP di Meja Ujian:</strong> Saat ujian resmi di kampus, dilarang meletakkan 2 HP di atas meja. Pelanggaran berakibat akun dinonaktifkan otomatis oleh Pengawas (gugur). HP cadangan wajib dimatikan dan ditaruh di dalam tas di depan kelas.</li>
                        <li><strong>Hadir 15 Menit Lebih Awal:</strong> Hadir di ruangan ujian paling lambat 15 menit sebelum waktu tes dimulai. Jika koneksi terputus atau baterai habis saat ujian, jawaban tersimpan aman di server; segera hidupkan HP atau lapor pengawas di ruangan.</li>
                        <li><strong>Catat di Kertas Sebelum Masuk Aplikasi:</strong> Aplikasi ujian mengunci layar HP secara penuh (<em>Kiosk Mode</em>), sehingga Anda tidak dapat membuka catatan di HP saat ujian berlangsung. Catat Kode Sekolah (<strong>22205</strong>), Username, dan Password di secarik kertas sebelum membuka aplikasi.</li>
                        <li><strong>Aplikasi Resmi Ujian:</strong>
                            Pengguna HP Android wajib memasang aplikasi resmi <strong>E-Ujian Browser</strong> dari Google Play Store (masukkan Kode Sekolah: <strong>22205</strong>).
                            Pengguna iPhone / iPad wajib menggunakan <strong>Safe Exam Browser (SEB)</strong> dengan file konfigurasi resmi (<code>seb-config-lppai.seb</code>).
                        </li>
                        <li><strong>Ketertiban &amp; Kejujuran:</strong> Menjaga ketenangan, ketertiban, berpakaian rapi dan sopan (busana muslim/muslimah), serta menjunjung tinggi kejujuran akademik.</li>
                    </ol>
                </section>

                <!-- ── Lembar Pengesahan Tanda Tangan ── -->
                <div class="signature-grid">
                    <div class="sig-box">
                        <div class="sig-role">Mahasiswa / Peserta Pretes</div>
                        <div class="sig-sub">Menyatakan siap mengikuti seluruh tata tertib</div>
                        <div class="sig-space"></div>
                        <span class="sig-name">( <?= sanitize($cardValue($user['nama_lengkap'] ?? null)) ?> )</span>
                    </div>
                    <div class="sig-box">
                        <div class="sig-role">Dosen Penguji / LPPAI UNISDA</div>
                        <div class="sig-sub">Verifikasi kehadiran &amp; asesmen ujian</div>
                        <div class="sig-space">
                            <span class="sig-stamp">✓ LPPAI UNISDA Verified</span>
                        </div>
                        <span class="sig-name">( <?= sanitize($cardValue($registration['dosen_penguji'] ?? null, 'Dosen Penguji')) ?> )</span>
                    </div>
                </div>
            </div>

            <!-- ── Footer Kartu ── -->
            <footer class="footer">
                <div>
                    <span><?= sanitize($cardNumber) ?></span>
                    <span> · </span>
                    <span>LPPAI UNISDA</span>
                    <span> · </span>
                    <span>Universitas Islam Darul 'Ulum Lamongan</span>
                </div>
                <div>
                    <span>Dicetak pada <?= (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('d/m/Y H:i') ?> WIB</span>
                </div>
            </footer>
        <?php endif; ?>
    </main>
</body>
</html>
