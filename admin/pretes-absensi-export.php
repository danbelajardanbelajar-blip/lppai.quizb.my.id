<?php
/** Lembar absensi pretes A4, khusus administrator. */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
header('Cache-Control: private, no-store');
header('Content-Type: text/html; charset=UTF-8');

$pdo = getDBConnection();
$rawId = $_GET['pretes_id'] ?? null;
$pretesId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$pretesId) {
    http_response_code(400);
    exit('Periode pretes tidak valid. Silakan buka export dari halaman jadwal pretes.');
}
$stmt = $pdo->prepare('SELECT id, periode, tahun_ajaran, jam FROM master_pretes WHERE id = ?');
$stmt->execute([$pretesId]);
$pretes = $stmt->fetch();
if (!$pretes) {
    http_response_code(404);
    exit('Periode pretes tidak ditemukan.');
}

$filters = [];
foreach (['hari', 'dosen_penguji', 'ruangan'] as $key) {
    if (isset($_GET[$key]) && !is_string($_GET[$key])) {
        http_response_code(400);
        exit('Filter absensi tidak valid.');
    }
    $filters[$key] = trim($_GET[$key] ?? '');
}
if ($filters['hari'] !== '' && !in_array($filters['hari'], ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'], true)) {
    http_response_code(400);
    exit('Hari pelaksanaan tidak valid.');
}
$where = ['pr.periode = ?'];
$params = [$pretes['periode']];
if ($filters['hari'] !== '') {
    $where[] = "COALESCE(NULLIF(TRIM(pr.hari_pilihan), ''), 'Senin') = ?";
    $params[] = $filters['hari'];
}
foreach (['dosen_penguji', 'ruangan'] as $field) {
    if ($filters[$field] !== '') {
        $where[] = "TRIM(pr.$field) = ?";
        $params[] = $filters[$field];
    }
}
$stmt = $pdo->prepare('SELECT pr.hari_pilihan, pr.jam, pr.dosen_penguji, pr.ruangan,
    u.nama_lengkap, u.nim, u.program_studi
    FROM pretes_registrations pr JOIN users u ON u.id = pr.user_id
    WHERE ' . implode(' AND ', $where) . "
    ORDER BY FIELD(COALESCE(NULLIF(TRIM(pr.hari_pilihan), ''), 'Senin'), 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'),
    pr.jam, pr.dosen_penguji, pr.ruangan, u.nama_lengkap, u.nim, pr.id");
$stmt->execute($params);
$participants = $stmt->fetchAll();

// Satu sesi (hari, jam, penguji, ruangan) selalu menjadi satu lembar.
$groups = [];
foreach ($participants as $participant) {
    $meta = [
        'hari' => ucfirst(strtolower(trim($participant['hari_pilihan'] ?? ''))) ?: 'Senin',
        'jam' => trim($participant['jam'] ?? '') ?: ($pretes['jam'] ?: 'Belum ditentukan'),
        'penguji' => trim($participant['dosen_penguji'] ?? '') ?: 'Belum ditentukan',
        'ruangan' => trim($participant['ruangan'] ?? '') ?: 'Belum ditentukan',
    ];
    $key = json_encode(array_values($meta));
    if (!isset($groups[$key])) $groups[$key] = ['meta' => $meta, 'peserta' => []];
    $groups[$key]['peserta'][] = $participant;
}
$pages = [];
foreach ($groups as $group) {
    $pages[] = ['meta' => $group['meta'], 'rows' => $group['peserta'], 'total' => count($group['peserta'])];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Absensi Pretes - <?= sanitize($pretes['periode']) ?></title>
    <link rel="icon" href="<?= BASE_URL ?>/assets/logo.svg" type="image/svg+xml">
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e8edf0; color: #172b26; font: 10pt Arial, sans-serif; line-height: 1.35; }
        .toolbar { max-width: 210mm; margin: 20px auto; padding: 16px; background: white; border-radius: 8px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        .toolbar p { flex: 1; margin: 0; min-width: 200px; }
        .button { padding: 10px 16px; background: #15583c; color: white; border: 0; border-radius: 5px; cursor: pointer; text-decoration: none; font: inherit; }
        .back { background: #475569; }
        .sheet { position: relative; overflow: hidden; width: 210mm; height: 296mm; margin: 0 auto 20px; padding: 12mm; background: white; box-shadow: 0 2px 12px #0001; }
        .sheet-content { position: absolute; top: 12mm; left: 12mm; width: 186mm; transform-origin: top left; display: flow-root; }
        .kop { display: flex; align-items: center; gap: 16px; padding-bottom: 12px; border-bottom: 4px double #15583c; }
        .kop img { width: 12mm; height: 12mm; object-fit: contain; }
        .kop-text { flex: 1; text-align: center; }
        .kop h1, .kop p { font-size: 11pt; font-weight: bold; line-height: 1.5; margin: 0; }
        h2 { text-align: center; font-size: 13pt; letter-spacing: 1px; margin: 17px 0 3px; }
        .subtitle { text-align: center; margin: 0 0 16px; font-size: 9pt; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 7px 18px; margin-bottom: 14px; font-size: 9pt; }
        .meta div { overflow-wrap: anywhere; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 9pt; }
        th, td { border: 1px solid #455b52; padding: 6px; overflow-wrap: anywhere; }
        th { background: #e8f1eb; text-align: center; height: 32px; }
        td { height: 29px; }
        .center { text-align: center; }
        .note { font-size: 8pt; margin-top: 9px; color: #46574f; }
        .signatures { display: flex; justify-content: space-between; gap: 25px; margin-top: 18px; font-size: 9pt; break-inside: avoid; }
        .summary { line-height: 1.9; }
        .signature { text-align: center; width: 48%; overflow-wrap: anywhere; }
        .signature-name { margin-top: 48px; text-decoration: underline; font-weight: bold; }
        .footer { display: flex; justify-content: space-between; border-top: 1px solid #a9b9b0; margin-top: 18px; padding-top: 6px; font-size: 8pt; color: #46574f; }
        .empty { text-align: center; padding: 50px 0; }
        @media screen and (max-width: 820px) { .toolbar { margin: 12px; } }
        @media print {
            body { background: white; color: black; }
            .toolbar { display: none; }
            .sheet { width: 186mm; height: 272mm; margin: 0; padding: 0; box-shadow: none; break-inside: avoid; break-after: page; }
            .sheet:last-child { break-after: auto; }
            .sheet-content { top: 0; left: 0; }
            tr, .kop, .meta, .footer { break-inside: avoid; }
            thead { display: table-header-group; }
            th { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="pretes-jadwal.php" class="button back">Kembali</a>
        <p><?= count($participants) ?> peserta · <?= count($pages) ?> sesi / lembar. Setiap sesi otomatis disesuaikan menjadi satu halaman A4. Pilih <strong>Simpan sebagai PDF</strong> dan nonaktifkan header/footer browser.</p>
        <?php if ($pages): ?><button type="button" class="button" onclick="window.print()">Cetak / Simpan PDF</button><?php endif; ?>
    </div>
    <?php if (!$pages): ?>
    <main class="sheet empty"><h2>Belum ada peserta</h2><p>Tidak ada peserta pada periode dan filter yang dipilih.</p></main>
    <?php endif; ?>
    <?php foreach ($pages as $pageIndex => $page): $meta = $page['meta']; ?>
    <section class="sheet">
        <div class="sheet-content">
        <header class="kop">
            <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo LPPAI">
            <div class="kop-text">
                <h1>Lembaga Pembinaan dan Pengembangan Agama Islam (LPPAI)</h1>
                <p>Universitas Islam Darul Ulum Lamongan (UNISDA)</p>
            </div>
        </header>
        <h2>DAFTAR HADIR PESERTA PRETES</h2>
        <p class="subtitle">Tahun Ajaran <?= sanitize($pretes['tahun_ajaran']) ?></p>
        <div class="meta">
            <div><strong>Periode:</strong> <?= sanitize($pretes['periode']) ?></div>
            <div><strong>Hari / Tanggal:</strong> <?= sanitize($meta['hari']) ?> / ........................</div>
            <div><strong>Dosen Penguji:</strong> <?= sanitize($meta['penguji']) ?></div>
            <div><strong>Waktu:</strong> <?= sanitize($meta['jam']) ?></div>
            <div><strong>Ruangan:</strong> <?= sanitize($meta['ruangan']) ?></div>
            <div><strong>Jumlah Peserta Kelompok:</strong> <?= $page['total'] ?> mahasiswa</div>
        </div>
        <table>
            <colgroup><col style="width:6%"><col style="width:16%"><col style="width:30%"><col style="width:24%"><col style="width:16%"><col style="width:8%"></colgroup>
            <thead><tr><th>No.</th><th>NIM</th><th>Nama Mahasiswa</th><th>Program Studi</th><th>Tanda Tangan</th><th>Ket.</th></tr></thead>
            <tbody>
                <?php foreach ($page['rows'] as $i => $row): ?>
                <tr>
                    <td class="center"><?= $i + 1 ?></td>
                    <td><?= sanitize($row['nim']) ?></td>
                    <td><?= sanitize($row['nama_lengkap']) ?></td>
                    <td><?= sanitize($row['program_studi'] ?? '') ?></td>
                    <td></td><td></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="note">Ket.: H = Hadir, I = Izin, S = Sakit, A = Alfa. Tanda tangan diisi oleh masing-masing peserta.</p>
        <div class="signatures">
            <div class="summary"><strong>Rekap Kehadiran Lembar Ini</strong><br>Hadir: ........ orang &nbsp; Izin: ........ orang<br>Sakit: ........ orang &nbsp; Alfa: ........ orang<br>Jumlah pada lembar ini: <?= count($page['rows']) ?> mahasiswa</div>
            <div class="signature">Tanggal: ................................<br>Dosen Penguji<div class="signature-name"><?= sanitize($meta['penguji'] === 'Belum ditentukan' ? '(........................................)' : $meta['penguji']) ?></div></div>
        </div>
        <footer class="footer"><span>LPPAI · Administrasi Pretes</span><span>Lembar <?= $pageIndex + 1 ?> dari <?= count($pages) ?></span></footer>
        </div>
    </section>
    <?php endforeach; ?>
    <script>
    // Ukur konten sebenarnya, termasuk nama panjang, agar seluruh sesi muat.
    // Lebar dibesarkan sebelum skala diterapkan sehingga tabel tetap selebar kertas.
    function fitAttendanceSheets() {
        document.querySelectorAll('.sheet-content').forEach(function(content) {
            const sheet = content.parentElement;
            const style = getComputedStyle(sheet);
            const width = sheet.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
            const height = sheet.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom) - 2;
            content.style.transform = 'none';
            content.style.width = width + 'px';
            const fits = function(scale) {
                content.style.width = (width / scale) + 'px';
                return content.scrollHeight * scale <= height;
            };
            let scale = 1;
            if (!fits(scale)) {
                let low = 0;
                let high = 1;
                for (let i = 0; i < 24; i++) {
                    const middle = (low + high) / 2;
                    if (fits(middle)) low = middle;
                    else high = middle;
                }
                scale = low;
            }
            content.style.width = (width / scale) + 'px';
            content.style.transform = 'scale(' + scale + ')';
        });
    }
    window.addEventListener('load', fitAttendanceSheets);
    window.addEventListener('beforeprint', fitAttendanceSheets);
    if (document.fonts) document.fonts.ready.then(fitAttendanceSheets);
    </script>
</body>
</html>
