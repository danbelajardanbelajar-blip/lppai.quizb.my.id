<?php
/**
 * LPPAI Corner - Tampilan tab "Semua Mahasiswa" (dipanggil admin/pretes-peserta.php saat ?tab=semua).
 * Variabel: $selectedPeriode, $allStudentFilters, $allStudentRows, $allStudentStats, $allStudentProdis, $allStudentAngkatans.
 * Kolom sama dengan tab Peserta Terdaftar: No, Nama, NIM, Prodi, Program, Hari / Periode, Nilai Pretes, Aksi.
 * Filter server (GET) + kotak cari tabel; ekspor PDF/Excel memakai filter yang sama (admin/pretes-mahasiswa-export.php).
 */
$f = $allStudentFilters;
$st = $allStudentStats;
$baseQs = ['tab' => 'semua', 'periode' => $selectedPeriode];
$statusLink = static function (string $status) use ($baseQs, $f): string {
    $q = $baseQs + array_filter(['status_pretes' => $status, 'prodi' => $f['prodi'], 'jenis_program' => $f['jenis_program'],
        'angkatan' => $f['angkatan'], 'hari' => $f['hari'] ? implode(',', $f['hari']) : ''], static fn($v) => $v !== '');
    return '?' . htmlspecialchars(http_build_query($q), ENT_QUOTES, 'UTF-8');
};
$exportQs = array_filter(['periode' => $selectedPeriode, 'status_pretes' => $f['status_pretes'], 'prodi' => $f['prodi'],
    'jenis_program' => $f['jenis_program'], 'angkatan' => $f['angkatan'], 'hari' => $f['hari'] ? implode(',', $f['hari']) : ''], static fn($v) => $v !== '');
$statusBadge = [
    'sudah_pretes' => ['#dcfce7', '#166534', 'Sudah Pretes'],
    'belum_pretes' => ['#fef3c7', '#92400e', 'Belum Pretes'],
    'belum_daftar' => ['#fee2e2', '#991b1b', 'Belum Daftar'],
];
?>
<div class="card" id="card-semua-mahasiswa">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <span>👥 Semua Mahasiswa — Status Pretes <?= $selectedPeriode === '__all__' ? '(Semua Periode)' : '(' . sanitize($selectedPeriode) . ')' ?></span>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="pretes-mahasiswa-export.php?<?= sanitize(http_build_query($exportQs + ['format' => 'pdf'])) ?>" target="_blank" rel="noopener" data-no-spa
               class="btn btn-sm sm-export" data-format="pdf" id="btn-sm-export-pdf"
               style="background:#dc2626;color:#fff;border:1px solid #b91c1c;font-weight:700;font-size:12.5px;padding:7px 14px;border-radius:8px;text-decoration:none;">📄 Export PDF (sesuai filter)</a>
            <a href="pretes-mahasiswa-export.php?<?= sanitize(http_build_query($exportQs + ['format' => 'xlsx'])) ?>" data-no-spa
               class="btn btn-sm sm-export" data-format="xlsx" id="btn-sm-export-xlsx"
               style="background:#16a34a;color:#fff;border:1px solid #15803d;font-weight:700;font-size:12.5px;padding:7px 14px;border-radius:8px;text-decoration:none;">📗 Export Excel (sesuai filter)</a>
        </div>
    </div>
    <div class="card-body">
        <!-- Ringkasan status (klik untuk menyaring) -->
        <div class="sm-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-bottom:16px;">
            <?php foreach ([
                ['', '👥 Semua Mahasiswa', $st['total'], '#eff6ff', '#1e40af'],
                ['sudah_pretes', '✅ Sudah Pretes', $st['sudah_pretes'], '#f0fdf4', '#166534'],
                ['belum_pretes', '⏳ Belum Pretes (sudah daftar)', $st['belum_pretes'], '#fffbeb', '#92400e'],
                ['belum_daftar', '❌ Belum Daftar Pretes', $st['belum_daftar'], '#fef2f2', '#991b1b'],
            ] as [$key, $label, $n, $bg, $fg]): ?>
                <a href="<?= $statusLink($key) ?>" class="sm-stat <?= $f['status_pretes'] === $key ? 'active' : '' ?>" data-status="<?= $key ?: 'semua' ?>"
                   style="display:block;text-decoration:none;background:<?= $bg ?>;color:<?= $fg ?>;border:1.5px solid <?= $f['status_pretes'] === $key ? $fg : 'transparent' ?>;border-radius:12px;padding:10px 14px;">
                    <span style="display:block;font-size:12px;font-weight:700;"><?= $label ?></span>
                    <strong style="font-size:22px;"><?= (int)$n ?></strong>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Filter -->
        <form method="GET" id="form-filter-semua" data-no-spa style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:16px;">
            <input type="hidden" name="tab" value="semua">
            <input type="hidden" name="periode" value="<?= sanitize($selectedPeriode) ?>">
            <input type="hidden" name="hari" id="sm-hari" value="<?= sanitize(implode(',', $f['hari'])) ?>">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;">
                <label style="font-size:12px;font-weight:600;color:#475569;">📝 Status Pretes
                    <select name="status_pretes" id="sm-status" onchange="this.form.submit()" style="display:block;width:100%;margin-top:5px;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#fff;">
                        <option value="">Semua Mahasiswa (<?= (int)$st['total'] ?>)</option>
                        <option value="sudah_pretes" <?= $f['status_pretes'] === 'sudah_pretes' ? 'selected' : '' ?>>Sudah Pretes (<?= (int)$st['sudah_pretes'] ?>)</option>
                        <option value="belum_pretes" <?= $f['status_pretes'] === 'belum_pretes' ? 'selected' : '' ?>>Belum Pretes — sudah daftar (<?= (int)$st['belum_pretes'] ?>)</option>
                        <option value="belum_daftar" <?= $f['status_pretes'] === 'belum_daftar' ? 'selected' : '' ?>>Belum Daftar Pretes (<?= (int)$st['belum_daftar'] ?>)</option>
                        <option value="belum_semua" <?= $f['status_pretes'] === 'belum_semua' ? 'selected' : '' ?>>Belum Pretes + Belum Daftar (<?= (int)($st['belum_pretes'] + $st['belum_daftar']) ?>)</option>
                    </select>
                </label>
                <label style="font-size:12px;font-weight:600;color:#475569;">🎓 Program Studi
                    <select name="prodi" id="sm-prodi" onchange="this.form.submit()" style="display:block;width:100%;margin-top:5px;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#fff;">
                        <option value="">Semua Prodi</option>
                        <?php foreach ($allStudentProdis as $pr): ?><option value="<?= sanitize($pr) ?>" <?= $f['prodi'] === $pr ? 'selected' : '' ?>><?= sanitize($pr) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label style="font-size:12px;font-weight:600;color:#475569;">🏷️ Program
                    <select name="jenis_program" id="sm-jenis" onchange="this.form.submit()" style="display:block;width:100%;margin-top:5px;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#fff;">
                        <option value="">Semua Program</option>
                        <option value="Reguler" <?= $f['jenis_program'] === 'Reguler' ? 'selected' : '' ?>>Reguler</option>
                        <option value="RPL" <?= $f['jenis_program'] === 'RPL' ? 'selected' : '' ?>>RPL</option>
                    </select>
                </label>
                <label style="font-size:12px;font-weight:600;color:#475569;">📆 Angkatan
                    <select name="angkatan" id="sm-angkatan" onchange="this.form.submit()" style="display:block;width:100%;margin-top:5px;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#fff;">
                        <option value="">Semua Angkatan</option>
                        <?php foreach ($allStudentAngkatans ?? [] as $ang): ?><option value="<?= sanitize($ang) ?>" <?= $f['angkatan'] === $ang ? 'selected' : '' ?>><?= sanitize($ang) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <div style="font-size:12px;font-weight:600;color:#475569;">📅 Hari Pelaksanaan <small style="font-weight:500;color:#94a3b8;">(bisa beberapa)</small>
                    <div id="sm-hari-group" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:5px;">
                        <?php foreach (pretesDisplayDays($selectedPeriodMeta ?? null, array_count_values(array_filter(array_column($allStudentRows, 'hari_pilihan')))) as $d): // + Sabtu/Ahad periode RPL ?>
                            <label class="hari-chip"><input type="checkbox" class="sm-hari-cb" value="<?= $d ?>" <?= in_array($d, $f['hari'], true) ? 'checked' : '' ?>><span><?= $d ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px;">
                <a href="?<?= sanitize(http_build_query($baseQs)) ?>" class="btn btn-sm" id="sm-reset" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;">✕ Reset Filter</a>
                <span style="font-size:12px;color:#64748b;">Menampilkan <strong id="sm-count"><?= count($allStudentRows) ?></strong> mahasiswa · <?= sanitize(pretesAllStudentsFilterText($f)) ?>. Cari nama/NIM lewat kotak "Cari" pada tabel; kata kunci ikut diekspor.</span>
            </div>
            <style>
                .hari-chip{position:relative;display:inline-flex;align-items:center;cursor:pointer;margin:0}
                .hari-chip input{position:absolute;opacity:0;width:1px;height:1px}
                .hari-chip span{display:inline-flex;align-items:center;gap:5px;min-height:32px;padding:4px 11px;border:1.5px solid #cbd5e1;border-radius:999px;background:#fff;color:#1e293b;font-size:12.5px;font-weight:600}
                .hari-chip input:checked + span{background:#2563eb;border-color:#2563eb;color:#fff}
                .hari-chip input:checked + span::before{content:'\2713'}
                .hari-chip input:focus-visible + span{outline:2px solid #93c5fd;outline-offset:1px}
            </style>
        </form>

        <?php if (!$allStudentRows): ?>
            <div class="empty-state"><div class="icon">🔍</div><h3>Tidak ada mahasiswa</h3><p>Tidak ada mahasiswa yang cocok dengan filter ini.</p></div>
        <?php else: ?>
        <div class="table-responsive">
            <table id="table-semua-mahasiswa">
                <thead><tr><th>No</th><th>Nama</th><th>NIM</th><th>Prodi</th><th>Program</th><th>Hari / Periode</th><th>Nilai Pretes</th><th style="min-width:120px;">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($allStudentRows as $i => $r): [$bg, $fg, $lbl] = $statusBadge[$r['status_pretes']]; ?>
                    <tr data-status-pretes="<?= $r['status_pretes'] ?>" data-user-id="<?= (int)$r['user_id'] ?>">
                        <td><?= $i + 1 ?></td>
                        <td data-search="<?= sanitize($r['nama_lengkap']) ?>">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <?php if ($r['foto'] !== ''): ?>
                                    <img src="<?= BASE_URL ?>/<?= sanitize(ltrim(str_replace('\\', '/', $r['foto']), '/')) ?>" alt="Foto" style="width:34px;height:44px;object-fit:cover;border-radius:4px;border:1px solid #cbd5e1;">
                                <?php else: ?>
                                    <div style="width:34px;height:44px;border-radius:4px;background:#f1f5f9;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:10px;font-weight:700;">3x4</div>
                                <?php endif; ?>
                                <strong><?= adminUserProfileLink($r['nama_lengkap'], $r['user_id']) ?></strong>
                            </div>
                        </td>
                        <td data-search="<?= sanitize($r['nim'] . ' ' . $r['angkatan']) ?>"><?= sanitize($r['nim'] ?: '-') ?><?php if ($r['angkatan'] !== ''): ?><div style="font-size:11px;color:#64748b;">Angkatan <?= sanitize($r['angkatan']) ?></div><?php endif; ?></td>
                        <td><?= sanitize($r['program_studi'] ?: '-') ?></td>
                        <td data-order="<?= sanitize($r['jenis_program']) ?>"><?= renderJenisProgramBadge($r['jenis_program'], true) ?></td>
                        <td data-order="<?= sanitize($r['hari_pilihan']) ?>">
                            <?php if ($r['reg_id']): ?>
                                <strong><?= sanitize($r['hari_pilihan'] ?: '-') ?></strong> <span style="font-size:11px;color:#64748b;">(<?= sanitize($r['jam'] ?: '13.00 WIB') ?>)</span>
                                <?php if ($selectedPeriode === '__all__' && $r['periode'] !== ''): ?><div style="font-size:11px;color:#475569;margin-top:3px;"><?= sanitize($r['periode']) ?></div><?php endif; ?>
                            <?php else: ?>
                                <span style="color:#94a3b8;">Belum daftar</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($r['score'] !== null): $ok = $r['score'] >= 80; ?>
                            <td data-order="<?= number_format($r['score'], 2, '.', '') ?>" style="white-space:nowrap;">
                                <strong style="font-size:14px;color:<?= $ok ? '#15803d' : '#b91c1c' ?>;"><?= sanitize(pretesFormatScore($r['score'])) ?></strong>
                                <div style="font-size:10.5px;font-weight:700;color:<?= $ok ? '#15803d' : '#b91c1c' ?>;"><?= $ok ? 'Lulus' : 'Tidak lulus' ?></div>
                            </td>
                        <?php else: ?>
                            <td data-order="-1" style="color:#94a3b8;text-align:center;">-</td>
                        <?php endif; ?>
                        <td style="white-space:nowrap;">
                            <span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:800;background:<?= $bg ?>;color:<?= $fg ?>;margin-bottom:5px;"><?= $lbl ?></span><br>
                            <a href="<?= BASE_URL ?>/admin/profil-pengguna.php?id=<?= (int)$r['user_id'] ?>" class="btn btn-sm" data-no-spa style="background:#f1f5f9;color:#1e293b;border:1px solid #cbd5e1;padding:4px 9px;font-size:11.5px;">👤 Profil</a>
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
(function () {
    var form = document.getElementById('form-filter-semua');
    if (!form) return;
    // Hari multi-pilih → hidden "hari" (dipisah koma) lalu kirim form
    form.querySelectorAll('.sm-hari-cb').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var days = [];
            form.querySelectorAll('.sm-hari-cb').forEach(function (c) { if (c.checked) days.push(c.value); });
            document.getElementById('sm-hari').value = days.join(',');
            form.submit();
        });
    });
    // Ekspor: tambahkan kata kunci kotak cari tabel (q) ke tautan ekspor
    document.querySelectorAll('.sm-export').forEach(function (a) {
        a.addEventListener('click', function () {
            var q = '';
            try {
                if (window.jQuery && jQuery.fn.DataTable && jQuery.fn.DataTable.isDataTable('#table-semua-mahasiswa')) {
                    q = String(jQuery('#table-semua-mahasiswa').DataTable().search() || '').trim();
                }
            } catch (e) {}
            var url = new URL(a.getAttribute('href'), window.location.href);
            if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
            a.setAttribute('href', url.pathname.split('/').pop() + url.search);
        });
    });
})();
</script>
