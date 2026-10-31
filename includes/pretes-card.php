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
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 20px; background: #eef3f8; color: #0f172a; font: 14px/1.6 Arial, sans-serif; }
        .toolbar, .card { width: 100%; max-width: 760px; margin: 0 auto; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .toolbar p { margin: 0; color: #475569; font-size: 13px; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; }
        .button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 10px 18px; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; color: #334155; font: 700 14px Arial, sans-serif; text-decoration: none; cursor: pointer; }
        .button-primary { border-color: #0369a1; background: #0369a1; color: #fff; }
        .button:hover { filter: brightness(.95); }
        .button:focus-visible { outline: 3px solid #38bdf8; outline-offset: 3px; }
        .card { overflow: hidden; border: 1px solid #cbd5e1; border-top: 6px solid #0369a1; border-radius: 18px; background: #fff; box-shadow: 0 16px 48px #0f172a0d; }
        .brand { display: flex; align-items: center; gap: 16px; padding: 26px 30px; border-bottom: 1px solid #e2e8f0; }
        .brand img { width: 58px; height: 58px; object-fit: contain; }
        .eyebrow { margin: 0; color: #0369a1; font-size: 11px; font-weight: 700; letter-spacing: 1.6px; text-transform: uppercase; }
        h1 { margin: 3px 0 0; font-size: 25px; line-height: 1.25; }
        .content { padding: 26px 30px; }
        .period { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; padding-bottom: 22px; }
        .period p { margin: 4px 0 0; font-weight: 700; }
        .badge { padding: 5px 12px; border: 1px solid #bae6fd; border-radius: 30px; background: #f0f9ff; color: #075985; font-size: 12px; font-weight: 700; }
        h2 { margin: 0 0 14px; font-size: 12px; letter-spacing: 1px; text-transform: uppercase; color: #0369a1; }
        .identity { padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px; }
        dl { display: grid; grid-template-columns: 1fr 1fr; gap: 18px 24px; margin: 0; }
        dl > div { min-width: 0; }
        dt { margin-bottom: 3px; color: #64748b; font-size: 12px; }
        dd { margin: 0; font-weight: 700; overflow-wrap: anywhere; }
        .full { grid-column: 1 / -1; }
        .student-name { font-size: 20px; }
        .schedule { margin-top: 22px; }
        .schedule dl { padding: 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; }
        .notice { margin-top: 22px; padding: 14px 16px; border-left: 3px solid #0284c7; background: #f0f9ff; color: #334155; font-size: 12px; }
        .notice p { margin: 0; }
        .notice p + p { margin-top: 6px; }
        .footer { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding: 14px 30px; border-top: 1px dashed #cbd5e1; color: #64748b; font-size: 11px; }
        .empty { text-align: center; padding: 46px 26px; }
        .empty p { color: #64748b; }
        @media (max-width: 540px) {
            body { padding: 18px 12px; }
            .brand, .content { padding: 20px; }
            h1 { font-size: 21px; }
            .brand img { width: 46px; height: 46px; }
            dl { grid-template-columns: 1fr; gap: 14px; }
            .footer { padding: 14px 20px; }
            .actions, .actions .button { flex: 1; }
        }
        @page { size: A4 portrait; margin: 15mm; }
        @media print {
            body { padding: 0; background: #fff; color: #000; font-size: 12px; }
            .no-print { display: none !important; }
            .card { max-width: none; box-shadow: none; border-radius: 0; overflow: visible; }
            .brand, .identity, .schedule dl, .notice, .footer { break-inside: avoid; }
            dl { grid-template-columns: 1fr 1fr; }
            dt, .footer { color: #475569; }
            .badge, .notice, .schedule dl { background: #fff; }
            .brand, .content { padding: 22px 26px; }
        }
    </style>
</head>
<body>
    <nav class="toolbar no-print" aria-label="Aksi kartu pretes">
        <div class="actions">
            <a class="button" href="<?= BASE_URL ?>/pretes-peserta.php">← Kembali ke Pretes</a>
            <?php if ($registration): ?>
                <button class="button button-primary" type="button" onclick="window.print()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9V3h12v6M6 18H3V9h18v9h-3M6 14h12v7H6z"/><path d="M17 12h1"/></svg>
                    Cetak / Simpan PDF
                </button>
            <?php endif; ?>
        </div>
        <p>Pilih “Simpan sebagai PDF” pada dialog cetak untuk mengunduh kartu.</p>
    </nav>

    <main class="card">
        <header class="brand">
            <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo LPPAI">
            <div>
                <p class="eyebrow">LPPAI Corner</p>
                <h1>Kartu Peserta Pretes</h1>
            </div>
        </header>

        <?php if (!$registration): ?>
            <div class="empty">
                <h2>Belum terdaftar pretes</h2>
                <p>Silakan lakukan pendaftaran terlebih dahulu untuk mencetak kartu peserta Anda.</p>
                <a class="button button-primary no-print" href="<?= BASE_URL ?>/pretes-daftar.php">Daftar Pretes</a>
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
        ?>
            <div class="content">
                <div class="period">
                    <div><span class="eyebrow">Periode Pretes</span><p><?= sanitize($cardValue($registration['periode'] ?? null)) ?></p></div>
                    <span class="badge"><?= sanitize(ucfirst(str_replace('_', ' ', $cardValue($registration['status'] ?? null, 'terdaftar')))) ?></span>
                </div>

                <section class="identity" aria-labelledby="identitas">
                    <h2 id="identitas">Identitas Peserta</h2>
                    <dl>
                        <div class="full"><dt>Nama lengkap</dt><dd class="student-name"><?= sanitize($cardValue($user['nama_lengkap'] ?? null)) ?></dd></div>
                        <div><dt>NIM</dt><dd><?= sanitize($cardValue($user['nim'] ?? null)) ?></dd></div>
                        <div><dt>Nomor peserta</dt><dd><?= sanitize($cardNumber) ?></dd></div>
                        <div><dt>Program studi</dt><dd><?= sanitize($cardValue($user['program_studi'] ?? null)) ?></dd></div>
                        <div><dt>Fakultas</dt><dd><?= sanitize($cardValue($user['fakultas'] ?? null)) ?></dd></div>
                    </dl>
                </section>

                <section class="schedule" aria-labelledby="jadwal">
                    <h2 id="jadwal">Jadwal Pelaksanaan</h2>
                    <dl>
                        <div><dt>Hari pilihan</dt><dd><?= sanitize($cardValue($registration['hari_pilihan'] ?? null, 'Menunggu penetapan')) ?></dd></div>
                        <div><dt>Tanggal</dt><dd><?= $tanggal !== false ? date('d/m/Y', $tanggal) : 'Menunggu penetapan' ?></dd></div>
                        <div><dt>Jam pelaksanaan</dt><dd><?= sanitize($cardValue($time, '13.00 WIB (Jam 1)')) ?></dd></div>
                        <div><dt>Ruangan</dt><dd><?= sanitize($cardValue($registration['ruangan'] ?? null, $cardValue($registration['ruangan_jadwal'] ?? null, 'Menunggu pembagian'))) ?></dd></div>
                        <div class="full"><dt>Dosen penguji</dt><dd><?= sanitize($cardValue($registration['dosen_penguji'] ?? null, 'Menunggu pembagian')) ?></dd></div>
                    </dl>
                </section>

                <aside class="notice">
                    <p><strong>Simpan dan bawa kartu ini saat mengikuti pretes.</strong></p>
                    <p>Periksa kembali jadwal, ruangan, dan penguji di akun LPPAI Anda sebelum pelaksanaan. Data yang belum ditetapkan akan diperbarui setelah pembagian oleh admin.</p>
                </aside>
            </div>
            <footer class="footer">
                <span><?= sanitize($cardNumber) ?> · LPPAI Corner</span>
                <span>Dicetak pada <?= (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('d/m/Y H:i') ?> WIB</span>
            </footer>
        <?php endif; ?>
    </main>
</body>
</html>
