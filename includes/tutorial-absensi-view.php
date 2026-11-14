<?php
/** Admin-only print view; included after the plotting page's existing participant selection. */
if (!isset($printAttendance) || !$printAttendance) {
    http_response_code(404);
    exit;
}
$filters = [];
foreach (['kelas_id', 'hari', 'dosen_pengampu', 'ruangan'] as $key) {
    if (isset($_GET[$key]) && !is_string($_GET[$key])) {
        http_response_code(400);
        exit('Filter absensi tidak valid.');
    }
    $filters[$key] = trim($_GET[$key] ?? '');
}
if ($filters['kelas_id'] !== '' && !filter_var($filters['kelas_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
    http_response_code(400);
    exit('Kelas tutorial tidak valid.');
}
$classMap = array_column($classes, null, 'id');
if ($filters['kelas_id'] !== '' && !isset($classMap[(int)$filters['kelas_id']])) {
    http_response_code(404);
    exit('Kelas tidak ditemukan pada periode yang dipilih.');
}
$groups = [];
foreach ($registrations as $participant) {
    $classId = (int)$participant['tutorial_class_id'];
    if (!isset($classMap[$classId])) continue;
    if ($filters['kelas_id'] !== '' && $classId !== (int)$filters['kelas_id']) continue;
    $class = $classMap[$classId];
    foreach (['hari', 'dosen_pengampu', 'ruangan'] as $key) {
        if ($filters[$key] !== '' && trim((string)($class[$key] ?? '')) !== $filters[$key]) continue 2;
    }
    $groups[$classId][] = $participant;
}
$pages = [];
$participantCount = 0;
foreach ($groups as $classId => $participants) {
    $participantCount += count($participants);
    foreach (array_chunk($participants, 25) as $index => $rows) {
        $pages[] = ['class' => $classMap[$classId], 'rows' => $rows, 'offset' => $index * 25, 'total' => count($participants)];
    }
}
$backUrl = 'tutorial-hasil-plotting.php?' . http_build_query(['gelombang_id' => $current_gel ? $current_gel['id'] : 'all']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Absensi Peserta Tutorial - LPPAI</title>
    <link rel="icon" href="<?= BASE_URL ?>/assets/logo.svg" type="image/svg+xml">
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin:0; background:#e8edf0; color:#172b26; font:10pt Arial,sans-serif; line-height:1.35; }
        .toolbar { max-width:210mm; margin:20px auto; padding:16px; background:white; border-radius:8px; display:flex; flex-wrap:wrap; gap:12px; align-items:center; }
        .toolbar p { flex:1; margin:0; min-width:200px; }
        .button { padding:10px 16px; background:#15583c; color:white; border:0; border-radius:5px; cursor:pointer; text-decoration:none; font:inherit; }
        .back { background:#475569; }
        .sheet { width:210mm; height:296mm; margin:0 auto 20px; padding:12mm; background:white; box-shadow:0 2px 12px #0001; }
        .sheet-fit { display:flow-root; }
        .kop { display:flex; align-items:center; gap:16px; padding-bottom:12px; border-bottom:4px double #15583c; }
        .kop img { width:12mm; height:12mm; object-fit:contain; }
        .kop-text { flex:1; text-align:center; }
        .kop h1,.kop p { font-size:11pt; font-weight:bold; line-height:1.5; margin:0; }
        h2 { text-align:center; font-size:13pt; letter-spacing:1px; margin:17px 0 3px; }
        .subtitle { text-align:center; margin:0 0 16px; font-size:9pt; }
        .meta { display:grid; grid-template-columns:1fr 1fr; gap:7px 18px; margin-bottom:14px; font-size:9pt; }
        .meta div { overflow-wrap:anywhere; }
        table { width:100%; border-collapse:collapse; table-layout:fixed; font-size:9pt; }
        th,td { border:1px solid #455b52; padding:6px; overflow-wrap:anywhere; }
        th { background:#e8f1eb; text-align:center; height:32px; }
        td { height:29px; }
        .center { text-align:center; }
        .note { font-size:8pt; margin-top:9px; color:#46574f; }
        .signatures { display:flex; justify-content:space-between; gap:25px; margin-top:18px; font-size:9pt; break-inside:avoid; }
        .summary { line-height:1.9; }
        .signature { text-align:center; width:48%; overflow-wrap:anywhere; }
        .signature-name { margin-top:48px; text-decoration:underline; font-weight:bold; }
        .footer { display:flex; justify-content:space-between; border-top:1px solid #a9b9b0; margin-top:18px; padding-top:6px; font-size:8pt; color:#46574f; }
        .empty { text-align:center; padding:50px 0; }
        @media screen and (max-width:820px) { .toolbar { margin:12px; } }
        @media print {
            body { background:white; color:black; }
            .toolbar { display:none; }
            .sheet { width:186mm; height:272mm; margin:0; padding:0; box-shadow:none; break-inside:avoid; break-after:page; }
            .sheet:last-child { break-after:auto; }
            tr,.kop,.meta,.footer { break-inside:avoid; }
            th { print-color-adjust:exact; -webkit-print-color-adjust:exact; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="<?= sanitize($backUrl) ?>" class="button back">Kembali</a>
        <p><?= $participantCount ?> peserta · <?= count($groups) ?> kelas · <?= count($pages) ?> lembar A4. Pilih <strong>Simpan sebagai PDF</strong> dan nonaktifkan header/footer browser.</p>
        <?php if ($pages): ?><button type="button" class="button" onclick="window.print()">Cetak / Simpan PDF</button><?php endif; ?>
    </div>
    <?php if (!$pages): ?>
    <main class="sheet empty"><h2>Belum ada peserta</h2><p>Tidak ada peserta pada periode dan filter yang dipilih.</p></main>
    <?php endif; ?>
    <?php foreach ($pages as $pageIndex => $page): $class = $page['class']; ?>
    <section class="sheet"><div class="sheet-fit">
        <header class="kop">
            <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo LPPAI">
            <div class="kop-text"><h1>Lembaga Pembinaan dan Pengembangan Agama Islam (LPPAI)</h1><p>Universitas Islam Darul Ulum Lamongan (UNISDA)</p></div>
        </header>
        <h2>DAFTAR HADIR PESERTA TUTORIAL</h2>
        <p class="subtitle">Semester <?= sanitize($class['semester']) ?> · <?= sanitize($class['gelombang']) ?></p>
        <div class="meta">
            <div><strong>Kelas:</strong> <?= sanitize($class['nama_kelas']) ?></div>
            <div><strong>Hari / Tanggal:</strong> <?= sanitize($class['hari'] ?: 'Belum ditentukan') ?> / ........................</div>
            <div><strong>Dosen Pengampu:</strong> <?= sanitize($class['dosen_pengampu'] ?: 'Belum ditentukan') ?></div>
            <div><strong>Waktu:</strong> <?= sanitize($class['jam'] ?: 'Belum ditentukan') ?></div>
            <div><strong>Ruangan:</strong> <?= sanitize($class['ruangan'] ?: 'Belum ditentukan') ?></div>
            <div><strong>Pertemuan ke:</strong> ............</div>
            <div><strong>Jumlah Peserta Kelas:</strong> <?= $page['total'] ?> mahasiswa</div>
        </div>
        <table>
            <colgroup><col style="width:6%"><col style="width:16%"><col style="width:30%"><col style="width:24%"><col style="width:16%"><col style="width:8%"></colgroup>
            <thead><tr><th>No.</th><th>NIM</th><th>Nama Mahasiswa</th><th>Program Studi</th><th>Tanda Tangan</th><th>Ket.</th></tr></thead>
            <tbody>
            <?php foreach ($page['rows'] as $index => $row): ?>
                <tr><td class="center"><?= $page['offset'] + $index + 1 ?></td><td><?= sanitize($row['nim']) ?></td><td><?= sanitize($row['nama_lengkap']) ?></td><td><?= sanitize($row['program_studi'] ?? '') ?></td><td></td><td></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="note">Ket.: H = Hadir, I = Izin, S = Sakit, A = Alfa. Tanda tangan diisi oleh masing-masing peserta.</p>
        <div class="signatures">
            <div class="summary"><strong>Rekap Kehadiran Lembar Ini</strong><br>Hadir: ........ orang &nbsp; Izin: ........ orang<br>Sakit: ........ orang &nbsp; Alfa: ........ orang<br>Jumlah pada lembar ini: <?= count($page['rows']) ?> mahasiswa</div>
            <div class="signature">Tanggal: ................................<br>Dosen Pengampu<div class="signature-name"><?= sanitize($class['dosen_pengampu'] ?: '(........................................)') ?></div></div>
        </div>
        <footer class="footer"><span>LPPAI · Administrasi Tutorial</span><span>Lembar <?= $pageIndex + 1 ?> dari <?= count($pages) ?></span></footer>
    </div></section>
    <?php endforeach; ?>
    <script>
    function fitAttendanceSheets() {
        document.querySelectorAll('section.sheet').forEach(function(sheet) {
            const fitted = sheet.querySelector('.sheet-fit');
            const style = getComputedStyle(sheet);
            const width = sheet.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
            const height = sheet.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom) - 2;
            fitted.style.zoom = '1';
            const fits = function(scale) {
                fitted.style.width = (width / scale) + 'px';
                fitted.style.zoom = scale;
                return fitted.getBoundingClientRect().height <= height;
            };
            if (!fits(1)) {
                let low = 0.1, high = 1;
                for (let i = 0; i < 24; i++) {
                    const middle = (low + high) / 2;
                    if (fits(middle)) low = middle; else high = middle;
                }
                fits(low);
            }
        });
    }
    window.addEventListener('load', fitAttendanceSheets);
    window.addEventListener('beforeprint', fitAttendanceSheets);
    window.addEventListener('afterprint', fitAttendanceSheets);
    </script>
</body>
</html>
