<?php
/**
 * LPPAI Corner - Admin: Ekspor tab "Semua Mahasiswa" (admin/pretes-peserta.php?tab=semua) sesuai filter.
 * format=xlsx (Excel; fallback CSV bila ekstensi ZIP tidak ada) | format=pdf (dokumen A4 siap cetak / simpan PDF).
 * Filter: periode, status_pretes (sudah_pretes|belum_pretes|belum_daftar|belum_semua), prodi, jenis_program, hari (koma), q.
 */
define('PAGE_TITLE', 'Export Status Pretes Mahasiswa');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/pretes-all-students.php';

$pdo = getDBConnection();
ensurePretesPeriodTargetColumns($pdo);
$active = getActivePretesPeriod($pdo);
$periode = trim((string)($_GET['periode'] ?? ''));
if ($periode === '') $periode = (string)($active['periode'] ?? '__all__');
$filters = pretesAllStudentsFilters($_GET);
$rows = pretesAllStudentsApply(pretesAllStudentsRows($pdo, $periode), $filters);
$stats = pretesAllStudentsStats($rows);
$periodeLabel = ($periode === '__all__' || $periode === '') ? 'Semua Periode' : $periode;
$filterText = pretesAllStudentsFilterText($filters);
$format = strtolower((string)($_GET['format'] ?? 'xlsx'));

$tz = new DateTimeZone('Asia/Jakarta');
$now = new DateTimeImmutable('now', $tz);
$statusText = static fn(string $s): string => ['sudah_pretes' => 'Sudah Pretes', 'belum_pretes' => 'Belum Pretes', 'belum_daftar' => 'Belum Daftar'][$s] ?? $s;
$slug = static fn(string $s): string => trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-');
$fileBase = 'Status_Pretes_Mahasiswa_' . $slug($periodeLabel)
    . ($filters['status_pretes'] !== '' ? '_' . $slug($filters['status_pretes']) : '')
    . ($filters['hari'] ? '_' . implode('-', $filters['hari']) : '') . '_' . $now->format('Ymd_His');

if ($format !== 'pdf') {
    $header = ['No', 'NIM', 'Nama Mahasiswa', 'Program Studi', 'Program', 'Status Pretes', 'Hari Pelaksanaan', 'Periode', 'Nilai Pretes', 'Keterangan Nilai'];
    $lines = function () use ($rows, $header, $statusText) {
        yield $header;
        foreach ($rows as $i => $r) {
            yield [
                $i + 1, (string)$r['nim'], $r['nama_lengkap'], $r['program_studi'] ?: '-', $r['jenis_program'],
                $statusText($r['status_pretes']), $r['reg_id'] ? ($r['hari_pilihan'] ?: '-') : '-', $r['periode'] ?: '-',
                $r['score'] !== null ? ['style' => 3, 'value' => (float)$r['score']] : '-',
                $r['score'] === null ? '-' : ($r['score'] >= 80 ? 'Lulus' : 'Tidak lulus'),
            ];
        }
    };
    if (class_exists('ZipArchive')) {
        require_once __DIR__ . '/../includes/pretes-cbt-xlsx.php';
        cbtSendXlsx(cbtWriteXlsx($lines(), 'Status Pretes', [6, 16, 32, 26, 10, 16, 16, 24, 12, 16]), $fileBase . '.xlsx', true);
        exit;
    }
    // Fallback CSV (UTF-8 BOM agar terbaca Excel)
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileBase . '.csv"');
    header('Cache-Control: private, no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($lines() as $line) {
        fputcsv($out, array_map(static fn($c) => is_array($c) ? $c['value'] : $c, $line));
    }
    fclose($out);
    exit;
}

$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$printDate = $now->format('j') . ' ' . $months[(int)$now->format('n')] . ' ' . $now->format('Y') . ', ' . $now->format('H:i') . ' WIB';
$backUrl = 'pretes-peserta.php?' . http_build_query(['tab' => 'semua', 'periode' => $periode]);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Status Pretes Mahasiswa - <?= $e($periodeLabel) ?></title>
<link rel="icon" href="<?= BASE_URL ?>/assets/logo.svg" type="image/svg+xml">
<style>
*{box-sizing:border-box;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
body{margin:0;background:#e2e8f0;color:#0f172a;font-family:Arial,"Segoe UI",Roboto,Helvetica,sans-serif;font-size:10pt;line-height:1.4}
.toolbar{background:#fff;border-bottom:1.5px solid #cbd5e1;padding:12px 24px;position:sticky;top:0;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.toolbar a,.toolbar button{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer;border:1px solid #cbd5e1;background:#f1f5f9;color:#334155}
.toolbar button{background:#dc2626;color:#fff;border-color:#b91c1c}
.sheet{width:210mm;min-height:297mm;padding:15mm;margin:20px auto;background:#fff;box-shadow:0 4px 20px rgba(0,0,0,.1)}
.kop{display:flex;align-items:center;gap:16px;padding-bottom:12px;border-bottom:3.5px double #0f172a;margin-bottom:16px}
.kop img{width:70px;height:70px;object-fit:contain}
.kop div{flex:1;text-align:center}
.kop h1{font-size:13pt;margin:0;text-transform:uppercase}
.kop h2{font-size:11pt;margin:3px 0 0;color:#15583c;text-transform:uppercase}
.kop p{font-size:8.5pt;color:#475569;margin:4px 0 0}
.title{text-align:center;margin-bottom:12px}
.title h2{font-size:13pt;margin:0 0 4px;text-transform:uppercase;text-decoration:underline}
.meta{display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;font-size:9pt;background:#f8fafc;border:1px solid #cbd5e1;border-radius:6px;padding:8px 12px;margin-bottom:12px}
.meta b{color:#334155}
table{width:100%;border-collapse:collapse;font-size:9pt}
th{background:#15583c;color:#fff;padding:6px;border:1px solid #0f3d2a;text-align:center}
td{padding:5px 6px;border:1px solid #cbd5e1;vertical-align:top}
tr:nth-child(even) td{background:#fafbfc}
.c{text-align:center}.nim{font-family:Consolas,monospace;font-weight:700}
.st{display:inline-block;padding:1px 6px;border-radius:4px;font-size:7.5pt;font-weight:800;white-space:nowrap}
.st.sudah_pretes{background:#dcfce7;color:#166534}.st.belum_pretes{background:#fef3c7;color:#92400e}.st.belum_daftar{background:#fee2e2;color:#991b1b}
.ok{color:#15803d;font-weight:800}.bad{color:#b91c1c;font-weight:800}.muted{color:#94a3b8}
.rekap{margin-top:16px;font-size:9pt;background:#f8fafc;border:1px solid #cbd5e1;border-radius:6px;padding:8px 12px;width:60%;page-break-inside:avoid}
@media print{body{background:#fff}.toolbar{display:none}.sheet{margin:0;box-shadow:none;width:auto;min-height:0;padding:0}@page{size:A4 portrait;margin:12mm}thead{display:table-header-group}tr{page-break-inside:avoid}}
@media(max-width:820px){.sheet{width:auto;margin:10px;padding:14px}.meta{grid-template-columns:1fr}.rekap{width:auto}}
</style>
</head>
<body>
<div class="toolbar">
    <a href="<?= $e($backUrl) ?>">← Kembali</a>
    <span style="font-size:12px;font-weight:700;color:#1e40af;"><?= (int)$stats['total'] ?> mahasiswa · <?= $e($filterText) ?></span>
    <button type="button" onclick="window.print()" id="btn-print">🖨️ Cetak / Simpan PDF</button>
</div>
<main class="sheet">
    <header class="kop">
        <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo LPPAI">
        <div>
            <h1>Lembaga Pembinaan dan Pengembangan Agama Islam (LPPAI)</h1>
            <h2>Universitas Islam Darul 'Ulum Lamongan (UNISDA)</h2>
            <p>Jl. Airlangga No. 03 Sukodadi, Lamongan - Jawa Timur 62253</p>
        </div>
    </header>
    <div class="title"><h2>Daftar Status Pretes Mahasiswa</h2><div style="font-size:9.5pt;font-weight:600;">Periode: <?= $e($periodeLabel) ?></div></div>
    <div class="meta">
        <div><b>Filter:</b> <?= $e($filterText) ?></div>
        <div><b>Jumlah:</b> <?= (int)$stats['total'] ?> mahasiswa</div>
        <div><b>Tanggal Cetak:</b> <?= $e($printDate) ?></div>
        <div><b>Rincian:</b> Sudah <?= (int)$stats['sudah_pretes'] ?> · Belum pretes <?= (int)$stats['belum_pretes'] ?> · Belum daftar <?= (int)$stats['belum_daftar'] ?></div>
    </div>
    <table>
        <thead><tr><th style="width:5%">No</th><th style="width:13%">NIM</th><th>Nama Mahasiswa</th><th style="width:18%">Program Studi</th><th style="width:9%">Program</th><th style="width:14%">Status / Hari</th><th style="width:11%">Nilai Pretes</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="7" class="c muted" style="padding:28px;"><em>Tidak ada mahasiswa yang sesuai filter.</em></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td class="c"><?= $i + 1 ?></td>
                <td class="c nim"><?= $e($r['nim'] ?: '-') ?></td>
                <td><?= $e($r['nama_lengkap']) ?></td>
                <td><?= $e($r['program_studi'] ?: '-') ?></td>
                <td class="c"><?= $e($r['jenis_program']) ?></td>
                <td class="c"><span class="st <?= $e($r['status_pretes']) ?>"><?= $e($statusText($r['status_pretes'])) ?></span><?php if ($r['reg_id'] && $r['hari_pilihan'] !== ''): ?><div style="font-size:8pt;color:#475569;"><?= $e($r['hari_pilihan']) ?></div><?php endif; ?></td>
                <td class="c"><?php if ($r['score'] !== null): $ok = $r['score'] >= 80; ?><span class="<?= $ok ? 'ok' : 'bad' ?>"><?= $e(pretesFormatScore($r['score'])) ?></span><div style="font-size:7.5pt;" class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'Lulus' : 'Tidak lulus' ?></div><?php else: ?><span class="muted">-</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="rekap">
        <b>Rekapitulasi:</b><br>
        • Total: <b><?= (int)$stats['total'] ?></b> mahasiswa<br>
        • Sudah pretes: <b><?= (int)$stats['sudah_pretes'] ?></b> · Belum pretes (sudah daftar): <b><?= (int)$stats['belum_pretes'] ?></b> · Belum daftar pretes: <b><?= (int)$stats['belum_daftar'] ?></b><br>
        <span style="font-size:8pt;color:#64748b;">Dicetak melalui Sistem Informasi LPPAI Corner UNISDA.</span>
    </div>
</main>
</body>
</html>
