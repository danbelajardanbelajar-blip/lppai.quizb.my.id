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
            padding: 28px 18px;
            background: #eef3f8;
            color: #0f172a;
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            font-size: 13px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }

        .toolbar, .card {
            width: 100%;
            max-width: 820px;
            margin: 0 auto;
        }

        /* ── Toolbar Aksi Atas (Hanya Layar) ── */
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
            padding: 12px 18px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
        }
        .toolbar-info {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #475569;
            font-size: 12.5px;
        }
        .toolbar-info svg {
            color: #0284c7;
            flex-shrink: 0;
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
            border-radius: 9px;
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
        .button:focus-visible {
            outline: 3px solid #38bdf8;
            outline-offset: 2px;
        }

        /* ── Kartu Utama ── */
        .card {
            position: relative;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-top: 6px solid #0369a1;
            border-radius: 16px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        /* Watermark lambang di latar belakang kartu */
        .card-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 380px;
            height: 380px;
            background-image: url('<?= BASE_URL ?>/assets/logo.svg');
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.035;
            pointer-events: none;
            z-index: 0;
        }

        /* ── Kop Surat / Header Resmi Institusi ── */
        .brand {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            padding: 22px 28px 18px;
            background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
            border-bottom: 1px solid #e2e8f0;
        }
        .brand-institution {
            display: flex;
            align-items: center;
            gap: 16px;
            min-width: 0;
        }
        .brand-logo {
            width: 60px;
            height: 60px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .brand-titles {
            min-width: 0;
        }
        .inst-eyebrow {
            margin: 0;
            color: #0369a1;
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 1.2px;
            text-transform: uppercase;
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
            margin: 2px 0 0;
            color: #0f172a;
            font-size: 21px;
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: -0.2px;
        }
        .inst-sub {
            margin: 2px 0 0;
            color: #64748b;
            font-size: 12px;
            font-weight: 500;
        }
        .brand-badge-col {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
            flex-shrink: 0;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border: 1px solid #bae6fd;
            border-radius: 20px;
            background: #f0f9ff;
            color: #0369a1;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }
        .badge::before {
            content: "";
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #0284c7;
        }
        .card-number-badge {
            font-family: 'JetBrains Mono', monospace, Consolas, sans-serif;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            background: #f1f5f9;
            padding: 3px 8px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        /* ── Garis Ganda Kop Surat Resmi ── */
        .kop-rule {
            height: 3px;
            border-top: 2px solid #0369a1;
            border-bottom: 1px solid #0284c7;
            margin: 0;
            opacity: 0.85;
        }

        /* ── Isi Kartu ── */
        .content {
            position: relative;
            z-index: 1;
            padding: 22px 28px;
        }

        /* Banner Periode */
        .period-banner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding: 10px 16px;
            margin-bottom: 18px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #0369a1;
            border-radius: 8px;
        }
        .period-banner-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .period-banner-label {
            color: #64748b;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .period-banner-val {
            margin: 0;
            color: #0f172a;
            font-size: 13.5px;
            font-weight: 800;
        }
        .period-type {
            font-size: 11.5px;
            color: #0369a1;
            font-weight: 700;
            background: #e0f2fe;
            padding: 3px 10px;
            border-radius: 6px;
        }

        /* ── Grid Dua Kolom: Identitas & Jadwal (Kiri) + Pas Foto & QR Code (Kanan) ── */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 180px;
            gap: 18px;
            align-items: start;
        }

        .data-column {
            display: flex;
            flex-direction: column;
            gap: 16px;
            min-width: 0;
        }

        .section-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 18px;
        }
        .section-box-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px dashed #e2e8f0;
        }
        .section-box-header svg {
            color: #0369a1;
            flex-shrink: 0;
        }
        h2 {
            margin: 0;
            font-size: 11.5px;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #0369a1;
            font-weight: 800;
        }

        dl {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 18px;
            margin: 0;
        }
        dl > div { min-width: 0; }
        dt {
            margin-bottom: 2px;
            color: #64748b;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        dd {
            margin: 0;
            color: #0f172a;
            font-size: 13px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }
        .full { grid-column: 1 / -1; }
        .student-name {
            font-size: 16.5px;
            font-weight: 800;
            color: #0369a1;
            line-height: 1.3;
        }
        .nim-val {
            font-family: 'JetBrains Mono', monospace, Consolas, sans-serif;
            letter-spacing: 0.5px;
            font-size: 13.5px;
            color: #0f172a;
        }

        /* ── Kolom Pas Foto & QR Code ── */
        .side-column {
            display: flex;
            flex-direction: column;
            gap: 14px;
            align-items: center;
        }

        /* Kotak Pas Foto 3x4 */
        .photo-box {
            width: 130px;
            height: 165px;
            border: 1.5px dashed #94a3b8;
            border-radius: 10px;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 8px;
            text-align: center;
            box-sizing: border-box;
            box-shadow: inset 0 2px 6px rgba(0,0,0,0.02);
        }
        .photo-box svg {
            color: #94a3b8;
            margin-bottom: 6px;
        }
        .photo-title {
            font-size: 10px;
            font-weight: 800;
            color: #64748b;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }
        .photo-dim {
            font-size: 13px;
            font-weight: 900;
            color: #334155;
            margin: 2px 0 4px;
        }
        .photo-hint {
            font-size: 9.5px;
            color: #94a3b8;
            line-height: 1.25;
        }

        /* Kotak QR Code Verifikasi */
        .qr-card {
            width: 130px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            padding: 10px 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
        }
        .qr-target {
            width: 90px;
            height: 90px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 6px;
        }
        .qr-target img, .qr-target canvas {
            max-width: 100% !important;
            height: auto !important;
            border-radius: 4px;
        }
        .qr-fallback-svg {
            width: 86px;
            height: 86px;
            border-radius: 4px;
        }
        .qr-caption {
            font-size: 9.5px;
            font-weight: 800;
            color: #0369a1;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            line-height: 1.2;
        }
        .qr-subcaption {
            font-size: 8.5px;
            color: #64748b;
            margin-top: 2px;
        }

        /* ── Materi Uji & Tata Tertib ── */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 16px;
        }
        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 14px;
        }
        .info-card-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 800;
            color: #0369a1;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .info-card-title svg {
            flex-shrink: 0;
            color: #0284c7;
        }
        .info-list {
            margin: 0;
            padding-left: 16px;
            color: #334155;
            font-size: 11.5px;
            line-height: 1.45;
        }
        .info-list li {
            margin-bottom: 4px;
        }
        .info-list li:last-child {
            margin-bottom: 0;
        }

        /* ── Catatan Resmi (Notice) ── */
        .notice {
            margin-top: 14px;
            padding: 11px 14px;
            border-left: 3.5px solid #0284c7;
            background: #f0f9ff;
            color: #334155;
            font-size: 11.5px;
            border-radius: 0 8px 8px 0;
        }
        .notice p { margin: 0; }
        .notice p + p { margin-top: 4px; }

        /* ── Lembar Pengesahan Tanda Tangan ── */
        .signature-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px dashed #cbd5e1;
        }
        .sig-box {
            text-align: center;
            font-size: 11.5px;
            color: #334155;
        }
        .sig-role {
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 2px;
        }
        .sig-sub {
            font-size: 10.5px;
            color: #64748b;
        }
        .sig-space {
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .sig-stamp {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border: 1px dashed #0284c7;
            border-radius: 6px;
            color: #0369a1;
            font-size: 9.5px;
            font-weight: 700;
            background: #f0f9ff;
            letter-spacing: 0.3px;
        }
        .sig-name {
            font-weight: 700;
            color: #0f172a;
            border-top: 1px solid #94a3b8;
            padding-top: 4px;
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
            padding: 12px 28px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
        }
        .footer-left {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .footer-dot {
            color: #94a3b8;
        }

        /* ── Halaman Kosong (Belum Terdaftar) ── */
        .empty {
            text-align: center;
            padding: 56px 28px;
        }
        .empty-icon {
            font-size: 48px;
            margin-bottom: 12px;
        }
        .empty h2 {
            font-size: 18px;
            color: #0f172a;
            margin-bottom: 8px;
        }
        .empty p {
            color: #64748b;
            max-width: 440px;
            margin: 0 auto 20px;
            font-size: 13.5px;
        }

        /* ── Responsif Tampilan Layar ── */
        @media (max-width: 768px) {
            body { padding: 16px 10px; }
            .brand, .content { padding: 18px 16px; }
            .brand { flex-direction: column; align-items: flex-start; gap: 12px; }
            .brand-badge-col { align-items: flex-start; }
            .main-grid { grid-template-columns: 1fr; }
            .side-column {
                flex-direction: row;
                justify-content: center;
                gap: 16px;
                width: 100%;
            }
            .info-grid { grid-template-columns: 1fr; }
            .footer { padding: 12px 16px; flex-direction: column; align-items: flex-start; }
        }

        @media (max-width: 520px) {
            dl { grid-template-columns: 1fr; gap: 10px; }
            .signature-grid { grid-template-columns: 1fr; gap: 16px; }
            .side-column { flex-direction: column; }
            .actions, .actions .button { width: 100%; }
        }

        /* ── Spesifikasi Cetak Presisi A4 (Print Stylesheet) ── */
        @page {
            size: A4 portrait;
            margin: 10mm 12mm;
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
                font-size: 11.5px;
            }
            .no-print {
                display: none !important;
            }
            .card {
                max-width: 100% !important;
                box-shadow: none !important;
                border: 1.5px solid #0369a1 !important;
                border-top: 5px solid #0369a1 !important;
                border-radius: 6px !important;
                overflow: visible !important;
                page-break-inside: avoid !important;
            }
            .card-watermark {
                opacity: 0.04 !important;
            }
            .brand {
                padding: 14px 20px 12px !important;
                background: #f8fafc !important;
                break-inside: avoid;
            }
            .brand-logo {
                width: 50px !important;
                height: 50px !important;
            }
            h1 { font-size: 18px !important; }
            .content {
                padding: 14px 20px !important;
                break-inside: avoid;
            }
            .main-grid {
                grid-template-columns: 1fr 140px !important;
                gap: 14px !important;
            }
            .photo-box {
                width: 115px !important;
                height: 145px !important;
                background: #fafafa !important;
            }
            .qr-card {
                width: 115px !important;
                padding: 6px !important;
            }
            .qr-target {
                width: 76px !important;
                height: 76px !important;
            }
            .qr-fallback-svg {
                width: 74px !important;
                height: 74px !important;
            }
            .section-box {
                padding: 12px 14px !important;
                border: 1px solid #cbd5e1 !important;
            }
            dl {
                grid-template-columns: 1fr 1fr !important;
                gap: 8px 14px !important;
            }
            dt { color: #475569 !important; font-size: 10px !important; }
            dd { font-size: 12px !important; }
            .student-name { font-size: 15px !important; }
            .info-grid {
                margin-top: 12px !important;
                gap: 10px !important;
            }
            .info-card {
                padding: 8px 10px !important;
                background: #ffffff !important;
                border: 1px solid #cbd5e1 !important;
            }
            .info-list { font-size: 10.5px !important; }
            .notice {
                margin-top: 10px !important;
                padding: 8px 12px !important;
                background: #f8fafc !important;
                font-size: 10.5px !important;
            }
            .signature-grid {
                margin-top: 14px !important;
                padding-top: 10px !important;
                break-inside: avoid;
            }
            .sig-space { height: 38px !important; }
            .sig-name { min-width: 140px !important; }
            .footer {
                padding: 8px 20px !important;
                font-size: 10px !important;
                background: #ffffff !important;
                border-top: 1px solid #cbd5e1 !important;
                break-inside: avoid;
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
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
            <span>Pilih <strong>“Simpan sebagai PDF”</strong> atau printer A4 untuk mencetak kartu fisik.</span>
        </div>
    </nav>

    <!-- ── Dokumen Kartu Peserta Ujian ── -->
    <main class="card">
        <div class="card-watermark" aria-hidden="true"></div>

        <!-- ── Kop Institusi Resmi ── -->
        <header class="brand">
            <div class="brand-institution">
                <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo LPPAI" class="brand-logo">
                <div class="brand-titles">
                    <p class="inst-eyebrow">LPPAI Corner</p>
                    <h1>Kartu Peserta Pretes</h1>
                    <p class="inst-sub">Lembaga Pengkajian &amp; Pengembangan Al-Islam Kemuhammadiyahan</p>
                </div>
            </div>
            <div class="brand-badge-col">
                <span class="badge"><?= sanitize(ucfirst(str_replace('_', ' ', $cardValue($registration['status'] ?? null, 'terdaftar')))) ?></span>
                <?php if ($registration): 
                    $cardNumber = 'PRE-' . str_pad((string)$registration['id'], 6, '0', STR_PAD_LEFT);
                ?>
                    <span class="card-number-badge"><?= sanitize($cardNumber) ?></span>
                <?php endif; ?>
            </div>
        </header>

        <div class="kop-rule" aria-hidden="true"></div>

        <?php if (!$registration): ?>
            <!-- Tampilan jika mahasiswa belum mendaftar -->
            <div class="empty">
                <div class="empty-icon">📋</div>
                <h2>Belum terdaftar pretes</h2>
                <p>Silakan lakukan pendaftaran pretes terlebih dahulu melalui portal mahasiswa untuk mencetak kartu tanda peserta resmi Anda.</p>
                <a class="button button-primary no-print" href="<?= BASE_URL ?>/pretes-daftar.php">Daftar Pretes Sekarang</a>
            </div>
        <?php else:
            $cardNumber = 'PRE-' . str_pad((string)$registration['id'], 6, '0', STR_PAD_LEFT);
            $tanggal = !empty($registration['tanggal']) ? strtotime($registration['tanggal']) : false;
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
            $qrUrl = BASE_URL . '/pretes-peserta.php?verify=' . rawurlencode($cardNumber);
            $qrPayload = 'LPPAI CORNER | KARTU PRETES' . "\n"
                . 'NO: ' . $cardNumber . "\n"
                . 'NIM: ' . $nimRaw . "\n"
                . 'NAMA: ' . trim((string)($user['nama_lengkap'] ?? '')) . "\n"
                . 'PERIODE: ' . trim((string)($registration['periode'] ?? '')) . "\n"
                . 'VERIFIKASI: ' . $qrUrl;
        ?>
            <div class="content">
                <!-- ── Banner Periode & Skema Pretes ── -->
                <div class="period-banner">
                    <div class="period-banner-left">
                        <span class="period-banner-label">Periode Pretes:</span>
                        <p class="period-banner-val"><?= sanitize($cardValue($registration['periode'] ?? null)) ?></p>
                    </div>
                    <span class="period-type">Standardisasi BTQ &amp; Al-Islam</span>
                </div>

                <!-- ── Grid Utama: Identitas & Jadwal (Kiri) + Pas Foto & QR Code (Kanan) ── -->
                <div class="main-grid">
                    <div class="data-column">
                        <!-- Identitas Peserta -->
                        <section class="section-box identity" aria-labelledby="identitas">
                            <div class="section-box-header">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <h2 id="identitas">Identitas Peserta</h2>
                            </div>
                            <dl>
                                <div class="full">
                                    <dt>Nama lengkap</dt>
                                    <dd class="student-name"><?= sanitize($cardValue($user['nama_lengkap'] ?? null)) ?></dd>
                                </div>
                                <div>
                                    <dt>NIM</dt>
                                    <dd class="nim-val"><?= sanitize($cardValue($user['nim'] ?? null)) ?></dd>
                                </div>
                                <div>
                                    <dt>Nomor peserta</dt>
                                    <dd class="nim-val" style="color:#0369a1;"><?= sanitize($cardNumber) ?></dd>
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
                                    <dt>Status Registrasi</dt>
                                    <dd style="color:#0284c7;font-weight:800;">Terverifikasi Sistem</dd>
                                </div>
                                <?php endif; ?>
                            </dl>
                        </section>

                        <!-- Jadwal Pelaksanaan -->
                        <section class="section-box schedule" aria-labelledby="jadwal">
                            <div class="section-box-header">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <h2 id="jadwal">Jadwal Pelaksanaan</h2>
                            </div>
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

                    <!-- Kolom Samping: Pas Foto & QR Code -->
                    <aside class="side-column" aria-label="Validasi & Pas Foto">
                        <!-- Kotak Pas Foto 3x4 -->
                        <div class="photo-box">
                            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                            <span class="photo-title">Pas Foto</span>
                            <span class="photo-dim">3 × 4 CM</span>
                            <span class="photo-hint">Jas Almamater / Busana Muslim Rapi</span>
                        </div>

                        <!-- Kotak QR Code Verifikasi -->
                        <div class="qr-card">
                            <div id="qrcode-box" class="qr-target" data-payload="<?= sanitize($qrPayload) ?>" title="QR Code Verifikasi Resmi LPPAI">
                                <!-- Fallback SVG Berpresisi Tinggi Jika Skrip Belum Selesai Muat -->
                                <svg class="qr-fallback-svg" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <rect width="100" height="100" fill="#ffffff" rx="4"/>
                                    <!-- Finder Top-Left -->
                                    <rect x="8" y="8" width="24" height="24" rx="2" fill="#0f172a"/>
                                    <rect x="12" y="12" width="16" height="16" fill="#ffffff"/>
                                    <rect x="16" y="16" width="8" height="8" fill="#0f172a"/>
                                    <!-- Finder Top-Right -->
                                    <rect x="68" y="8" width="24" height="24" rx="2" fill="#0f172a"/>
                                    <rect x="72" y="12" width="16" height="16" fill="#ffffff"/>
                                    <rect x="76" y="16" width="8" height="8" fill="#0f172a"/>
                                    <!-- Finder Bottom-Left -->
                                    <rect x="8" y="68" width="24" height="24" rx="2" fill="#0f172a"/>
                                    <rect x="12" y="72" width="16" height="16" fill="#ffffff"/>
                                    <rect x="16" y="76" width="8" height="8" fill="#0f172a"/>
                                    <!-- Decorative Matrix Pattern -->
                                    <rect x="38" y="10" width="6" height="6" fill="#0369a1"/>
                                    <rect x="50" y="14" width="6" height="6" fill="#0f172a"/>
                                    <rect x="42" y="24" width="6" height="6" fill="#0f172a"/>
                                    <rect x="54" y="26" width="6" height="6" fill="#0369a1"/>
                                    <rect x="12" y="42" width="6" height="6" fill="#0f172a"/>
                                    <rect x="22" y="48" width="6" height="6" fill="#0f172a"/>
                                    <rect x="38" y="42" width="16" height="6" fill="#0369a1"/>
                                    <rect x="44" y="52" width="12" height="12" fill="#0f172a"/>
                                    <rect x="64" y="40" width="6" height="6" fill="#0f172a"/>
                                    <rect x="76" y="44" width="6" height="6" fill="#0369a1"/>
                                    <rect x="82" y="52" width="6" height="6" fill="#0f172a"/>
                                    <rect x="38" y="72" width="6" height="6" fill="#0f172a"/>
                                    <rect x="48" y="78" width="8" height="6" fill="#0369a1"/>
                                    <rect x="40" y="86" width="6" height="6" fill="#0f172a"/>
                                    <rect x="68" y="70" width="8" height="8" fill="#0f172a"/>
                                    <rect x="80" y="74" width="6" height="6" fill="#0369a1"/>
                                    <rect x="72" y="84" width="14" height="6" fill="#0f172a"/>
                                </svg>
                            </div>
                            <span class="qr-caption">Verifikasi QR</span>
                            <span class="qr-subcaption">Scan Validasi Dokumen</span>
                        </div>
                    </aside>
                </div>

                <!-- ── Materi Evaluasi & Tata Tertib Peserta ── -->
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-card-title">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                            Materi Evaluasi Pretes
                        </div>
                        <ul class="info-list">
                            <li><strong>Tes Tulis CBT:</strong> Aqidah, Akhlak, Ibadah, &amp; Kemuhammadiyahan.</li>
                            <li><strong>Tes Praktik &amp; Lisan:</strong> Tartil Baca Al-Qur'an (Tajwid &amp; Makhraj), Hafalan Surat Pilihan, Praktik Wudhu &amp; Shalat.</li>
                        </ul>
                    </div>
                    <div class="info-card">
                        <div class="info-card-title">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            Tata Tertib &amp; Petunjuk Ujian
                        </div>
                        <ul class="info-list">
                            <li>Wajib membawa kartu fisik ini dan kartu identitas mahasiswa (KTM / KTP).</li>
                            <li>Hadir di lokasi ujian paling lambat 15 menit sebelum sesi dimulai.</li>
                            <li>Berpakaian rapi, sopan, dan berbusana muslim/muslimah standar universitas.</li>
                            <li>Membawa smartphone baterai penuh untuk pelaksanaan tes CBT.</li>
                        </ul>
                    </div>
                </div>

                <!-- ── Catatan Resmi ── -->
                <aside class="notice">
                    <p><strong>Simpan dan bawa kartu ini saat mengikuti pretes.</strong></p>
                    <p>Periksa kembali jadwal, ruangan, dan penguji di akun LPPAI Anda sebelum pelaksanaan. Data yang belum ditetapkan akan diperbarui setelah pembagian oleh admin.</p>
                </aside>

                <!-- ── Lembar Pengesahan Tanda Tangan ── -->
                <div class="signature-grid">
                    <div class="sig-box">
                        <div class="sig-role">Mahasiswa / Peserta Ujian</div>
                        <div class="sig-sub">Menyatakan siap mengikuti tata tertib</div>
                        <div class="sig-space"></div>
                        <span class="sig-name">( <?= sanitize($cardValue($user['nama_lengkap'] ?? null)) ?> )</span>
                    </div>
                    <div class="sig-box">
                        <div class="sig-role">Dosen Penguji / Panitia LPPAI</div>
                        <div class="sig-sub">Verifikasi kehadiran &amp; asesmen nilai</div>
                        <div class="sig-space">
                            <span class="sig-stamp">✓ LPPAI Corner Verified</span>
                        </div>
                        <span class="sig-name">( <?= sanitize($cardValue($registration['dosen_penguji'] ?? null, 'Dosen Penguji')) ?> )</span>
                    </div>
                </div>
            </div>

            <!-- ── Footer Kartu ── -->
            <footer class="footer">
                <div class="footer-left">
                    <span><?= sanitize($cardNumber) ?></span>
                    <span class="footer-dot">·</span>
                    <span>LPPAI Corner</span>
                    <span class="footer-dot">·</span>
                    <span>Sistem Informasi Akademik Keislaman</span>
                </div>
                <div>
                    <span>Dicetak pada <?= (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('d/m/Y H:i') ?> WIB</span>
                </div>
            </footer>
        <?php endif; ?>
    </main>

    <!-- Skrip Pembangkit QR Code Resmi -->
    <script src="<?= BASE_URL ?>/assets/js/lib/qrcode.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var box = document.getElementById('qrcode-box');
        if (box && typeof QRCode !== 'undefined') {
            var payload = box.getAttribute('data-payload');
            if (payload) {
                box.innerHTML = '';
                new QRCode(box, {
                    text: payload,
                    width: 86,
                    height: 86,
                    colorDark: '#0f172a',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        }
    });
    </script>
</body>
</html>