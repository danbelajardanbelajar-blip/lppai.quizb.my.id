<?php
if (!isset($schedulePeriod, $scheduleRows)) {
    http_response_code(404);
    exit;
}
$periodLabel = $schedulePeriod['tahun_ajaran'] . ' / ' . $schedulePeriod['semester'] . ' / ' . $schedulePeriod['gelombang'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Jadwal Dosen Tutorial - <?= sanitize($periodLabel) ?></title>
    <link rel="icon" href="<?= BASE_URL ?>/assets/logo.svg" type="image/svg+xml">
    <style>
        @page { size:A4 landscape; margin:12mm; }
        * { box-sizing:border-box; }
        body { margin:0; color:#172b26; background:#e8edf0; font:10pt Arial,sans-serif; line-height:1.4; }
        .toolbar { max-width:297mm; margin:20px auto; padding:16px; background:white; border-radius:10px; display:flex; flex-wrap:wrap; align-items:center; gap:12px; }
        .toolbar form { display:flex; flex:1; flex-wrap:wrap; align-items:center; gap:8px; }
        .toolbar select { max-width:100%; padding:9px; font:inherit; border:1px solid #cbd5e1; border-radius:6px; }
        .button { border:0; border-radius:6px; padding:10px 16px; font:inherit; cursor:pointer; background:#15583c; color:white; text-decoration:none; }
        .back { background:#475569; }
        .sheet { width:297mm; min-height:210mm; margin:0 auto 24px; padding:12mm; background:white; box-shadow:0 2px 12px #0001; }
        .kop { display:flex; gap:16px; align-items:center; border-bottom:4px double #15583c; padding-bottom:12px; }
        .kop img { width:16mm; height:16mm; object-fit:contain; }
        .kop div { flex:1; text-align:center; }
        .kop h1,.kop p { margin:0; font-size:12pt; font-weight:bold; }
        h2 { text-align:center; font-size:15pt; margin:18px 0 5px; letter-spacing:1px; }
        .subtitle { text-align:center; margin:0 0 18px; }
        .summary { display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:12px; font-size:9pt; }
        table { width:100%; border-collapse:collapse; table-layout:fixed; font-size:10pt; }
        th,td { border:1px solid #72867b; padding:9px 8px; overflow-wrap:anywhere; }
        th { background:#e8f1eb; color:#15583c; text-align:left; }
        .center { text-align:center; }
        .footer { margin-top:18px; border-top:1px solid #a9b9b0; padding-top:8px; display:flex; flex-wrap:wrap; justify-content:space-between; gap:8px; color:#52645a; font-size:9pt; }
        .empty { text-align:center; padding:36px; }
        @media screen and (max-width:1150px) { .toolbar { margin:12px; } .preview { overflow-x:auto; } }
        @media print {
            body { background:white; color:black; }
            .toolbar { display:none; }
            .preview { overflow:visible; }
            .sheet { width:auto; min-height:0; padding:0; margin:0; box-shadow:none; }
            thead { display:table-header-group; }
            tr,.kop,.summary,.footer { break-inside:avoid; }
            h2 { break-after:avoid; }
            th { print-color-adjust:exact; -webkit-print-color-adjust:exact; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a class="button back" href="tutorial-hasil-plotting.php?gelombang_id=<?= (int)$schedulePeriod['id'] ?>">Kembali</a>
        <form method="GET" action="tutorial-jadwal-dosen-export.php">
            <input type="hidden" name="gelombang_id" value="<?= (int)$schedulePeriod['id'] ?>">
            <label for="scheduleLecturer">Dosen</label>
            <select name="dosen" id="scheduleLecturer">
                <option value="">Semua dosen</option>
                <?php foreach ($lecturerOptions as $lecturer): ?>
                <option value="<?= sanitize($lecturer) ?>" <?= $selectedLecturer === $lecturer ? 'selected' : '' ?>><?= sanitize($lecturer) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button back" type="submit">Tampilkan</button>
        </form>
        <?php if ($scheduleRows): ?><button class="button" type="button" onclick="window.print()">Cetak / Simpan PDF</button><?php endif; ?>
        <p style="width:100%;margin:0;color:#52645a;font-size:9pt;">Pilih Simpan sebagai PDF untuk dibagikan kepada dosen. Gunakan kertas A4, orientasi landscape, dan nonaktifkan header/footer browser.</p>
    </div>
    <div class="preview"><main class="sheet">
        <header class="kop">
            <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo LPPAI">
            <div><h1>Lembaga Pembinaan dan Pengembangan Agama Islam (LPPAI)</h1><p>Universitas Islam Darul Ulum Lamongan (UNISDA)</p></div>
        </header>
        <h2>JADWAL MENGAJAR TUTORIAL</h2>
        <p class="subtitle">Tahun Ajaran <?= sanitize($schedulePeriod['tahun_ajaran']) ?> · Semester <?= sanitize($schedulePeriod['semester']) ?> · <?= sanitize($schedulePeriod['gelombang']) ?></p>
        <div class="summary"><span><?= $lecturerCount ?> dosen · <?= count($scheduleRows) ?> kelas<?= $selectedLecturer !== '' ? ' · ' . sanitize($selectedLecturer) : '' ?></span><span>Dicetak: <?= date('d/m/Y') ?></span></div>
        <?php if (!$scheduleRows): ?>
        <p class="empty">Belum ada jadwal dosen pada periode dan filter yang dipilih.</p>
        <?php else: ?>
        <table>
            <colgroup><col style="width:5%"><col style="width:28%"><col style="width:22%"><col style="width:10%"><col style="width:18%"><col style="width:17%"></colgroup>
            <thead><tr><th class="center">No.</th><th>Nama Dosen</th><th>Kelas Tutorial</th><th>Hari</th><th>Jam</th><th>Ruangan</th></tr></thead>
            <tbody>
            <?php foreach ($scheduleRows as $index => $row): ?>
                <tr><td class="center"><?= $index + 1 ?></td><td><?= sanitize($row['dosen_pengampu']) ?></td><td><?= sanitize($row['nama_kelas']) ?></td><td><?= sanitize($row['hari'] ?: 'Belum ditentukan') ?></td><td><?= sanitize($row['jam'] ?: 'Belum ditentukan') ?></td><td><?= sanitize($row['ruangan'] ?: 'Belum ditentukan') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <footer class="footer"><span>LPPAI · Jadwal Tutorial</span><span><?= sanitize($periodLabel) ?></span></footer>
    </main></div>
</body>
</html>
