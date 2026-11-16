<?php
/**
 * LPPAI Corner - Admin: Rekapitulasi Nilai & Kelulusan Tutorial RPL Online
 */
define('PAGE_TITLE', 'Rekapitulasi Nilai Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
requireAdmin();

$pdo = getDBConnection();
ensureTutorialRplTables($pdo);

$selectedClassId = (int)($_GET['class_id'] ?? 0);
$selectedStatus = trim((string)($_GET['status'] ?? 'all'));
$classes = tutorialRplGetClasses($pdo);

// Ambil semua data untuk ringkasan statistik
$statQuery = "
    SELECT m.*, u.nama_lengkap, u.nim, u.program_studi, c.nama_kelompok, c.dosen_nama
    FROM tutorial_rpl_members m
    JOIN users u ON m.user_id = u.id
    JOIN tutorial_rpl_classes c ON m.class_id = c.id
";
$statParams = [];
if ($selectedClassId > 0) {
    $statQuery .= " WHERE m.class_id = ? ";
    $statParams[] = $selectedClassId;
}
$stmtStat = $pdo->prepare($statQuery);
$stmtStat->execute($statParams);
$allRows = $stmtStat->fetchAll(PDO::FETCH_ASSOC);

// Hitung metrik
$totalPeserta = count($allRows);
$lulusCount = 0;
$tidakLulusCount = 0;
$prosesCount = 0;
$sumNilai = 0;
$countNilai = 0;

foreach ($allRows as $r) {
    if ($r['status_lulus'] === 'lulus') {
        $lulusCount++;
    } elseif ($r['status_lulus'] === 'tidak_lulus') {
        $tidakLulusCount++;
    } else {
        $prosesCount++;
    }

    if ($r['nilai_akhir'] !== null) {
        $sumNilai += (float)$r['nilai_akhir'];
        $countNilai++;
    }
}

$avgNilai = ($countNilai > 0) ? round($sumNilai / $countNilai, 1) : 0;
$passRate = ($totalPeserta > 0) ? round(($lulusCount / $totalPeserta) * 100, 1) : 0;

// Query filtered untuk ditampilkan di tabel
$query = "
    SELECT m.*,
           u.nama_lengkap, u.nim, u.program_studi, u.fakultas, u.no_hp,
           c.nama_kelompok, c.dosen_nama
    FROM tutorial_rpl_members m
    JOIN users u ON m.user_id = u.id
    JOIN tutorial_rpl_classes c ON m.class_id = c.id
    WHERE 1=1
";
$params = [];
if ($selectedClassId > 0) {
    $query .= " AND m.class_id = ? ";
    $params[] = $selectedClassId;
}
if ($selectedStatus !== 'all' && in_array($selectedStatus, ['lulus', 'tidak_lulus', 'proses'], true)) {
    $query .= " AND m.status_lulus = ? ";
    $params[] = $selectedStatus;
}
$query .= " ORDER BY c.nama_kelompok ASC, u.nama_lengkap ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Export CSV jika diminta
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=rekap_nilai_tutorial_rpl_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['No', 'Nama Mahasiswa', 'NIM', 'Program Studi', 'Kelompok', 'Tutor Pengampu', 'Nilai CBT (50%)', 'Nilai Praktik (50%)', 'Nilai Akhir', 'Status Kelulusan', 'Catatan Tutor']);
    foreach ($rows as $i => $r) {
        fputcsv($out, [
            $i + 1,
            $r['nama_lengkap'],
            $r['nim'],
            $r['program_studi'],
            $r['nama_kelompok'],
            $r['dosen_nama'] ?: '-',
            $r['nilai_ujian_tulis'] !== null ? $r['nilai_ujian_tulis'] : '-',
            $r['nilai_praktik'] !== null ? $r['nilai_praktik'] : '-',
            $r['nilai_akhir'] !== null ? $r['nilai_akhir'] : '-',
            ucfirst($r['status_lulus'] ?? 'proses'),
            $r['eval_notes'] ?? '-'
        ]);
    }
    fclose($out);
    exit;
}

include __DIR__ . '/../includes/header.php';
?>

<style>
@media print {
    body { background: #fff !important; }
    .sidebar, .navbar, .btn, #filterSection, .breadcrumb-nav { display: none !important; }
    .card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; }
}
</style>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0; font-size:22px; font-weight:800; color:var(--text-dark);">
            🎓 Rekapitulasi Nilai &amp; Kelulusan Tutorial RPL
        </h2>
        <p style="margin:0; font-size:13.5px; color:#64748b;">
            Penggabungan nilai ujian tulis CBT tutorial (50%) dan evaluasi praktik hafalan (50%) dengan passing grade kelulusan 80.00.
        </p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button type="button" onclick="window.print();" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:6px;">
            <span>🖨️</span> Cetak Rekap
        </button>
        <a href="?export=csv<?= $selectedClassId > 0 ? '&class_id=' . $selectedClassId : '' ?><?= $selectedStatus !== 'all' ? '&status=' . urlencode($selectedStatus) : '' ?>" class="btn btn-success" style="display:inline-flex; align-items:center; gap:6px;">
            <span>📥</span> Ekspor CSV
        </a>
    </div>
</div>

<!-- Stat Metric Cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:16px; margin-bottom:24px;">
    <div class="card" style="margin:0; border-radius:12px; border-left:4px solid #6366f1;">
        <div class="card-body" style="padding:16px 20px;">
            <span style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Total Peserta</span>
            <div style="font-size:22px; font-weight:800; color:#4338ca; margin-top:4px;">
                <?= $totalPeserta ?> Mahasiswa
            </div>
            <small style="color:#64748b;">Terdaftar dalam kelas RPL</small>
        </div>
    </div>
    <div class="card" style="margin:0; border-radius:12px; border-left:4px solid #10b981;">
        <div class="card-body" style="padding:16px 20px;">
            <span style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Jumlah Lulus</span>
            <div style="font-size:22px; font-weight:800; color:#15803d; margin-top:4px;">
                <?= $lulusCount ?> (<?= $passRate ?>%)
            </div>
            <small style="color:#64748b;">Nilai akhir &ge; 80.00</small>
        </div>
    </div>
    <div class="card" style="margin:0; border-radius:12px; border-left:4px solid #ef4444;">
        <div class="card-body" style="padding:16px 20px;">
            <span style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Belum Lulus</span>
            <div style="font-size:22px; font-weight:800; color:#b91c1c; margin-top:4px;">
                <?= $tidakLulusCount ?> Mahasiswa
            </div>
            <small style="color:#64748b;">Nilai akhir &lt; 80.00</small>
        </div>
    </div>
    <div class="card" style="margin:0; border-radius:12px; border-left:4px solid #f59e0b;">
        <div class="card-body" style="padding:16px 20px;">
            <span style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">Dalam Proses / Rata-rata</span>
            <div style="font-size:22px; font-weight:800; color:#b45309; margin-top:4px;">
                <?= $prosesCount ?> blm · <?= $avgNilai ?>
            </div>
            <small style="color:#64748b;">Rata-rata nilai akhir terhitung</small>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div id="filterSection" class="card" style="margin-bottom:20px; border-radius:12px;">
    <div class="card-body" style="padding:16px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <div>
                <label style="font-size:12.5px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Kelompok:</label>
                <select class="form-control" onchange="applyFilters();" id="filterClass" style="min-width:220px;">
                    <option value="0">-- Semua Kelompok --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $selectedClassId === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= sanitize($c['nama_kelompok']) ?> (<?= sanitize($c['dosen_nama'] ?: '-') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12.5px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Status Kelulusan:</label>
                <select class="form-control" onchange="applyFilters();" id="filterStatus" style="min-width:180px;">
                    <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>-- Semua Status --</option>
                    <option value="lulus" <?= $selectedStatus === 'lulus' ? 'selected' : '' ?>>Lulus (<?= $lulusCount ?>)</option>
                    <option value="tidak_lulus" <?= $selectedStatus === 'tidak_lulus' ? 'selected' : '' ?>>Belum Lulus (<?= $tidakLulusCount ?>)</option>
                    <option value="proses" <?= $selectedStatus === 'proses' ? 'selected' : '' ?>>Dalam Proses (<?= $prosesCount ?>)</option>
                </select>
            </div>
        </div>
        <div style="flex:1; max-width:320px; min-width:240px;">
            <label style="font-size:12.5px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Pencarian Peserta:</label>
            <input type="text" id="searchTable" onkeyup="filterRows();" class="form-control" placeholder="Cari nama, NIM, atau prodi...">
        </div>
    </div>
</div>

<!-- Tabel Rekapitulasi Nilai -->
<div class="card" style="box-shadow:var(--shadow); border-radius:12px; overflow:hidden;">
    <div class="card-header" style="background:#f8fafc; font-weight:700; color:var(--text-dark); display:flex; justify-content:space-between; align-items:center;">
        <span>📋 Tabel Rekap Nilai Akhir &amp; Kelulusan (<?= count($rows) ?> Mahasiswa)</span>
        <span class="badge badge-primary" style="font-size:12px;">Passing Grade: 80.00</span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($rows)): ?>
            <div style="text-align:center; padding:40px 20px; color:#64748b;">
                <div style="font-size:36px; margin-bottom:8px;">🎓</div>
                <h4 style="margin:0 0 4px 0; color:#334155;">Tidak Ada Data Penilaian</h4>
                <p style="margin:0; font-size:13.5px;">Tidak ditemukan data yang sesuai dengan filter yang dipilih.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" id="nilaiTable" style="margin:0; width:100%;">
                    <thead>
                        <tr style="background:#f1f5f9; text-align:left; font-size:12.5px; color:#475569;">
                            <th style="padding:12px 16px; width:40px;">No</th>
                            <th style="padding:12px 16px;">Mahasiswa</th>
                            <th style="padding:12px 16px;">Kelompok &amp; Tutor</th>
                            <th style="padding:12px 16px; text-align:center;">CBT Tutorial (50%)</th>
                            <th style="padding:12px 16px; text-align:center;">Praktik Hafalan (50%)</th>
                            <th style="padding:12px 16px; text-align:center;">Nilai Akhir</th>
                            <th style="padding:12px 16px; text-align:center;">Status Kelulusan</th>
                            <th style="padding:12px 16px;">Catatan Tutor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $idx => $r):
                            $cbt = $r['nilai_ujian_tulis'] !== null ? (float)$r['nilai_ujian_tulis'] : null;
                            $prak = $r['nilai_praktik'] !== null ? (float)$r['nilai_praktik'] : null;
                            $akhir = $r['nilai_akhir'] !== null ? (float)$r['nilai_akhir'] : null;
                        ?>
                            <tr class="nilai-row" style="border-bottom:1px solid #e2e8f0; font-size:13.5px;">
                                <td style="padding:14px 16px; color:#64748b; vertical-align:middle; font-weight:700;"><?= $idx + 1 ?></td>
                                <td style="padding:14px 16px; vertical-align:middle;">
                                    <div class="m-name" style="font-weight:700; color:#0f172a;"><?= sanitize($r['nama_lengkap']) ?></div>
                                    <small class="m-info" style="color:#64748b;">
                                        NIM: <?= sanitize($r['nim'] ?: '-') ?> · <?= sanitize($r['program_studi'] ?: '') ?>
                                        <?= !empty($r['no_hp']) ? ' · 📞 ' . sanitize($r['no_hp']) : '' ?>
                                    </small>
                                </td>
                                <td style="padding:14px 16px; vertical-align:middle;">
                                    <div style="font-weight:600; color:#0369a1;"><?= sanitize($r['nama_kelompok']) ?></div>
                                    <small style="color:#64748b;">👨‍🏫 <?= sanitize($r['dosen_nama'] ?: '-') ?></small>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle; font-weight:700;">
                                    <?php if ($cbt !== null): ?>
                                        <span style="color:#0f172a;"><?= $cbt ?></span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-weight:normal;">Belum CBT</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle; font-weight:700;">
                                    <?php if ($prak !== null): ?>
                                        <span style="color:#0f172a;"><?= $prak ?></span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-weight:normal;">Belum Praktik</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                    <?php if ($akhir !== null): ?>
                                        <strong style="color:<?= $akhir >= 80.0 ? '#15803d' : '#b91c1c' ?>; font-size:16px;">
                                            <?= $akhir ?>
                                        </strong>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                    <?php if ($r['status_lulus'] === 'lulus'): ?>
                                        <span class="badge badge-emerald" style="font-size:12px; font-weight:700; padding:4px 12px;">LULUS</span>
                                    <?php elseif ($r['status_lulus'] === 'tidak_lulus'): ?>
                                        <span class="badge badge-danger" style="font-size:12px; font-weight:700; padding:4px 12px;">BELUM LULUS</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" style="font-size:11.5px; padding:4px 10px;">Dalam Proses</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 16px; vertical-align:middle; font-size:12.5px; color:#475569; max-width:220px;">
                                    <?= !empty($r['eval_notes']) ? sanitize($r['eval_notes']) : '<span style="color:#94a3b8;">-</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function applyFilters() {
    const classId = document.getElementById('filterClass').value;
    const status = document.getElementById('filterStatus').value;
    let url = '?class_id=' + encodeURIComponent(classId) + '&status=' + encodeURIComponent(status);
    location.href = url;
}

function filterRows() {
    const term = document.getElementById('searchTable').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.nilai-row');
    rows.forEach(r => {
        const text = r.innerText.toLowerCase();
        if (!term || text.includes(term)) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
