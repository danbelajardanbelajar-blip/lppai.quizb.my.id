<?php
/**
 * LPPAI Corner - Tampilan data tambahan Profil Pengguna (dipanggil admin/profil-pengguna.php).
 * Variabel: $pdo, $profileUser, $profileHistory. Data: includes/admin-profile-extra.php.
 * Bagian 1–12 khusus mahasiswa; 13–15 (login, aktivitas, pesan) untuk semua peran.
 * Password tes pretes TIDAK ditampilkan (hanya status "tersimpan / belum ada").
 */
require_once __DIR__ . '/admin-profile-extra.php';
$extra = adminProfileExtraData($pdo, $profileUser, $profileHistory['pretes'] ?? []);
$isMhs = ($profileUser['role'] ?? '') === 'mahasiswa';
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$dash = static fn($v) => ($v === null || $v === '') ? '-' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$score = static fn($v) => $v === null ? '-' : (function_exists('pretesFormatScore') ? pretesFormatScore((float)$v) : number_format((float)$v, 2, ',', ''));
$fileUrl = static function ($path): ?string {
    $p = trim((string)$path);
    if ($p === '') return null;
    if (preg_match('~^https?://~i', $p)) return $p;
    return rtrim(BASE_URL, '/') . '/' . ltrim(str_replace('\\', '/', $p), '/');
};
$section = static function (string $no, string $title, string $countText = '') use ($e): void {
    echo '<div class="card-header ape-head"><span><span class="ape-no">' . $e($no) . '</span> ' . $e($title) . '</span>'
        . ($countText !== '' ? '<span class="ape-count">' . $e($countText) . '</span>' : '') . '</div>';
};
$empty = static fn(string $msg) => '<p class="ape-empty">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>';
$passLabel = static function (?float $v) {
    if ($v === null) return '';
    return $v >= 80 ? ' <span class="ape-pill ok">Lulus</span>' : ' <span class="ape-pill bad">Tidak lulus</span>';
};
$statusPill = static function (string $s) use ($e) {
    $map = ['lulus' => ['ok', 'Lulus'], 'tidak_lulus' => ['bad', 'Tidak lulus'], 'menunggu' => ['wait', 'Menunggu penilaian']];
    [$cls, $lbl] = $map[$s] ?? ['wait', $s];
    return '<span class="ape-pill ' . $cls . '">' . $e($lbl) . '</span>';
};
?>
<style>
.ape-wrap{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-top:20px}
.ape-wrap>.card{margin:0;min-width:0}
.ape-wide{grid-column:1/-1}
.ape-head{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
.ape-no{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;border-radius:50%;background:#dcfce7;color:#166534;font-size:12px;font-weight:800;margin-right:4px}
.ape-count{font-size:12px;font-weight:700;color:#475569;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:999px;padding:2px 10px}
.ape-empty{margin:0;color:#94a3b8;font-size:13px}
.ape-pill{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap}
.ape-pill.ok{background:#dcfce7;color:#166534}.ape-pill.bad{background:#fee2e2;color:#991b1b}.ape-pill.wait{background:#e0f2fe;color:#075985}.ape-pill.muted{background:#f1f5f9;color:#475569}
.ape-stats{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.ape-stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:6px 12px;font-size:12px;color:#475569}
.ape-stat strong{display:block;font-size:16px;color:#0f172a}
.ape-wrap .admin-profile-table table{font-size:13px}
.ape-wrap .admin-profile-table td{vertical-align:top}
.ape-muted{color:#64748b;font-size:12px}
@media(max-width:900px){.ape-wrap{grid-template-columns:minmax(0,1fr)}}
</style>

<h2 style="margin:28px 0 0;font-size:18px;color:#0f172a;">📂 Data Lengkap Pengguna</h2>
<p class="ape-muted" style="margin:4px 0 0;">Data tambahan dari modul CBT, presensi, tutorial RPL, sertifikat, jaminan, login, aktivitas, dan pesan. Password tes tidak ditampilkan.</p>
<div class="ape-wrap" id="admin-profile-extra">

<?php if ($isMhs): ?>
<!-- 1. Hasil CBT pretes resmi -->
<section class="card ape-wide" id="ape-cbt-resmi"><?php $section('1', 'Hasil CBT Pretes Resmi', count($extra['cbt_resmi']) . ' sesi'); ?><div class="card-body">
<?php if (!$extra['cbt_resmi']): ?><?= $empty('Belum pernah mengerjakan CBT pretes resmi.') ?><?php else: ?>
<div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Periode</th><th>Mulai</th><th>Selesai</th><th>Durasi</th><th>Status</th><th>Benar / Salah</th><th>Nilai</th></tr></thead><tbody>
<?php foreach ($extra['cbt_resmi'] as $a): ?><tr>
<td><?= $dash($a['periode']) ?></td><td><?= $e(adminProfileExtraWib($a['started_at'])) ?></td><td><?= $e(adminProfileExtraWib($a['submitted_at'])) ?></td>
<td><?= $a['duration'] !== null ? $e(adminProfileExtraDuration($a['duration'])) : '-' ?></td><td><?= $e($a['status']) ?></td>
<td><?= $a['correct'] === null ? '-' : ($a['total'] > 0 ? $e($a['correct'] . ' / ' . $a['wrong']) . ' <span class="ape-muted">dari ' . (int)$a['total'] . '</span>' : $e($a['correct']) . ' benar') ?></td>
<td><strong><?= $score($a['score']) ?></strong><?= $passLabel($a['score']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></section>

<!-- 2. Riwayat tryout CBT -->
<section class="card" id="ape-cbt-tryout"><?php
    $tryScores = array_values(array_filter(array_column($extra['cbt_tryout'], 'score'), static fn($v) => $v !== null));
    $section('2', 'Riwayat Tryout CBT', count($extra['cbt_tryout']) . ' kali'); ?><div class="card-body">
<?php if (!$extra['cbt_tryout']): ?><?= $empty('Belum pernah mengerjakan tryout.') ?><?php else: ?>
<div class="ape-stats"><div class="ape-stat">Tertinggi<strong><?= $tryScores ? $score(max($tryScores)) : '-' ?></strong></div><div class="ape-stat">Rata-rata<strong><?= $tryScores ? $score(array_sum($tryScores) / count($tryScores)) : '-' ?></strong></div><div class="ape-stat">Terakhir<strong><?= $score($extra['cbt_tryout'][0]['score']) ?></strong></div></div>
<div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Mulai</th><th>Status</th><th>Benar</th><th>Nilai</th></tr></thead><tbody>
<?php foreach (array_slice($extra['cbt_tryout'], 0, 15) as $a): ?><tr><td><?= $e(adminProfileExtraWib($a['started_at'])) ?></td><td><?= $e($a['status']) ?></td><td><?= $a['correct'] === null ? '-' : ((int)$a['correct'] . ($a['total'] > 0 ? '/' . (int)$a['total'] : '')) ?></td><td><strong><?= $score($a['score']) ?></strong></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></section>

<!-- 3. Gangguan saat CBT -->
<section class="card" id="ape-interruptions"><?php $section('3', 'Gangguan Saat Ujian CBT', count($extra['interruptions']) . ' kejadian'); ?><div class="card-body">
<?php if (!$extra['interruptions']): ?><?= $empty('Tidak ada catatan gangguan (koneksi putus / keluar layar ujian).') ?><?php else: ?>
<div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Waktu</th><th>Alasan</th><th>Posisi Soal</th><th>Dilanjutkan</th></tr></thead><tbody>
<?php foreach ($extra['interruptions'] as $ev): ?><tr><td><?= $e(adminProfileExtraWib((int)$ev['paused_at'])) ?></td><td><?= $dash($ev['reason']) ?></td><td>No. <?= (int)$ev['interrupted_position'] ?></td><td><?= $e(adminProfileExtraWib($ev['resumed_at'] !== null ? (int)$ev['resumed_at'] : null)) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></section>

<!-- 4. Log pengawas AI -->
<section class="card" id="ape-proctoring"><?php $pr = $extra['proctoring']; $section('4', 'Log Pengawas AI', $pr['total'] . ' log'); ?><div class="card-body">
<?php if (!$pr['total'] && !$pr['challenges_issued']): ?><?= $empty('Belum ada log pengawas AI.') ?><?php else: ?>
<div class="ape-stats"><?php foreach ($pr['counts'] as $st => $n): ?><div class="ape-stat"><?= $e($st) ?><strong><?= (int)$n ?></strong></div><?php endforeach; ?><div class="ape-stat">Tantangan wajah<strong><?= (int)$pr['challenges_passed'] ?>/<?= (int)$pr['challenges_issued'] ?></strong></div></div>
<?php if ($pr['logs']): ?><div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Waktu</th><th>Jenis</th><th>Status</th><th>Kemiripan</th><th>Catatan</th></tr></thead><tbody>
<?php foreach ($pr['logs'] as $lg): ?><tr><td><?= $e(adminProfileExtraWib($lg['created_at'])) ?></td><td><?= $dash($lg['event_type']) ?></td><td><?= $dash($lg['status']) ?></td><td><?= $lg['similarity_score'] !== null ? $e(number_format((float)$lg['similarity_score'] * ((float)$lg['similarity_score'] <= 1 ? 100 : 1), 1, ',', '')) . '%' : '-' ?></td><td><?= $dash($lg['notes']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><p class="ape-muted" style="margin-top:6px;">Menampilkan 15 log terakhir.</p><?php endif; ?>
<?php endif; ?></div></section>

<!-- 5. Presensi pretes -->
<section class="card" id="ape-pretes-presence"><?php $section('5', 'Presensi Pretes', count($extra['pretes_presence']) . ' hadir'); ?><div class="card-body">
<?php if (!$extra['pretes_presence']): ?><?= $empty('Belum ada catatan kehadiran pretes.') ?><?php else: ?>
<div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Periode</th><th>Waktu Hadir</th><th>Hari / Ruangan</th><th>Penguji</th><th>Sumber</th></tr></thead><tbody>
<?php foreach ($extra['pretes_presence'] as $p): ?><tr><td><?= $dash($p['periode']) ?></td><td><?= $e(adminProfileExtraWib($p['attended_at'])) ?></td><td><?= $dash(trim(($p['hari'] ?? '') . ' / ' . ($p['ruangan'] ?? ''), ' /')) ?></td><td><?= $dash($p['penguji']) ?></td><td><?= $e($p['sumber']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></section>

<!-- 6. Kredensial tes pretes (tanpa password) -->
<section class="card" id="ape-credentials"><?php $section('6', 'Kredensial Tes Pretes'); ?><div class="card-body">
<?php if (!$extra['credentials']): ?><?= $empty('Belum ada pendaftaran pretes.') ?><?php else: ?>
<div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Periode</th><th>Username Tes</th><th>Password</th></tr></thead><tbody>
<?php foreach ($extra['credentials'] as $c): ?><tr><td><?= $dash($c['periode']) ?></td><td><code><?= $dash($c['username_tes']) ?></code></td><td><?= $c['password_set'] ? '<span class="ape-pill muted">Tersimpan (disembunyikan)</span>' : '<span class="ape-muted">Belum ada</span>' ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></section>

<!-- 7. Status kelulusan per pendaftaran pretes -->
<section class="card" id="ape-pretes-status"><?php $section('7', 'Status Kelulusan per Periode Pretes'); ?><div class="card-body">
<?php if (!$extra['pretes_status']): ?><?= $empty('Belum ada pendaftaran pretes.') ?><?php else: ?>
<div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Periode</th><th>Nilai Resmi</th><th>Status</th><th>Dasar Kelulusan</th></tr></thead><tbody>
<?php foreach ($extra['pretes_status'] as $s): ?><tr><td><?= $dash($s['periode']) ?></td><td><?= $score($s['official_score']) ?></td><td><?= $statusPill($s['status']) ?></td><td class="ape-muted"><?= $e($s['basis']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></section>

<!-- 8. Presensi tutorial -->
<section class="card" id="ape-tutorial-presence"><?php $tp = $extra['tutorial_presence']; $section('8', 'Presensi Tutorial', count($tp['rows']) . ' pertemuan'); ?><div class="card-body">
<?php if (!$tp['rows']): ?><?= $empty('Belum ada catatan presensi tutorial.') ?><?php else: ?>
<div class="ape-stats"><?php foreach (['hadir' => 'Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit', 'alpha' => 'Alfa', 'alfa' => 'Alfa'] as $k => $lbl): if (!isset($tp['counts'][$k])) continue; ?><div class="ape-stat"><?= $e($lbl) ?><strong><?= (int)$tp['counts'][$k] ?></strong></div><?php endforeach; ?></div>
<div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Kelas</th><th>Pertemuan</th><th>Tanggal</th><th>Status</th></tr></thead><tbody>
<?php foreach ($tp['rows'] as $r): ?><tr><td><?= $dash($r['nama_kelas']) ?></td><td>Ke-<?= (int)$r['pertemuan_ke'] ?></td><td><?= $e(adminProfileExtraWib($r['tanggal'], 'd/m/Y')) ?></td><td><?= $e(ucfirst((string)$r['status_hadir'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></section>

<!-- 9. Tutorial RPL Online -->
<section class="card" id="ape-rpl"><?php $section('9', 'Tutorial RPL Online', count($extra['rpl']['members']) . ' kelas'); ?><div class="card-body">
<?php if (!$extra['rpl']['members'] && !$extra['rpl']['cbt']): ?><?= $empty('Tidak terdaftar di Tutorial RPL Online.') ?><?php else: ?>
<?php if ($extra['rpl']['members']): ?><div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Kelompok</th><th>Tutor</th><th>Tugas Drive</th><th>Nilai (Tulis / Praktik / Akhir)</th><th>Status</th></tr></thead><tbody>
<?php foreach ($extra['rpl']['members'] as $m): ?><tr><td><?= $dash($m['nama_kelompok']) ?><div class="ape-muted"><?= $dash(trim(($m['periode'] ?? '') . ' · ' . ($m['gelombang'] ?? ''), ' ·')) ?></div></td><td><?= $dash($m['dosen_nama']) ?></td>
<td><?= !empty($m['drive_verified']) ? '<span class="ape-pill ok">Terverifikasi</span>' : (!empty($m['drive_submitted']) ? '<span class="ape-pill wait">Dikirim</span>' : '<span class="ape-muted">Belum</span>') ?></td>
<td><?= $score($m['nilai_ujian_tulis'] !== null ? (float)$m['nilai_ujian_tulis'] : null) ?> / <?= $score($m['nilai_praktik'] !== null ? (float)$m['nilai_praktik'] : null) ?> / <strong><?= $score($m['nilai_akhir'] !== null ? (float)$m['nilai_akhir'] : null) ?></strong></td>
<td><?= $statusPill((string)$m['status_lulus'] === 'proses' ? 'menunggu' : (string)$m['status_lulus']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php if ($extra['rpl']['cbt']): ?><p style="margin:12px 0 6px;font-weight:700;font-size:13px;">CBT Tutorial RPL</p><div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Mulai</th><th>Selesai</th><th>Benar</th><th>Nilai</th></tr></thead><tbody>
<?php foreach ($extra['rpl']['cbt'] as $c): ?><tr><td><?= $e(adminProfileExtraWib((int)$c['started_at'])) ?></td><td><?= $e(adminProfileExtraWib($c['submitted_at'] !== null ? (int)$c['submitted_at'] : null)) ?></td><td><?= $c['correct_count'] !== null ? (int)$c['correct_count'] . '/' . (int)$c['total_questions'] : '-' ?></td><td><strong><?= $score($c['score'] !== null ? (float)$c['score'] : null) ?></strong></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php endif; ?></div></section>

<!-- 10. Presensi Majlis Al Khidmah -->
<section class="card" id="ape-alkhidmah"><?php $section('10', 'Presensi Majlis Al Khidmah', count($extra['alkhidmah']) . ' kali'); ?><div class="card-body">
<?php if (!$extra['alkhidmah']): ?><?= $empty(trim((string)($profileUser['nim'] ?? '')) === '' ? 'NIM kosong — presensi Al Khidmah dicatat per NIM.' : 'Belum ada presensi Al Khidmah.') ?><?php else: ?>
<div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Tanggal</th><th>Hadir</th><th>Pulang</th></tr></thead><tbody>
<?php foreach ($extra['alkhidmah'] as $k): $fh = $fileUrl($k['foto_hadir']); $fp = $fileUrl($k['foto_pulang']); ?><tr><td><?= $e(adminProfileExtraWib($k['tanggal'], 'd/m/Y')) ?></td>
<td><?= $dash($k['waktu_hadir']) ?><?php if ($fh): ?> · <a href="<?= $e($fh) ?>" target="_blank" rel="noopener" data-no-spa>📷 Swafoto</a><?php endif; ?></td>
<td><?= $dash($k['waktu_pulang']) ?><?php if ($fp): ?> · <a href="<?= $e($fp) ?>" target="_blank" rel="noopener" data-no-spa>📷 Swafoto</a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></section>

<!-- 11. Pengambilan sertifikat -->
<section class="card" id="ape-sertifikat"><?php $section('11', 'Pengambilan Sertifikat', count($extra['sertifikat']) . ' catatan'); ?><div class="card-body">
<?php if (!$extra['sertifikat']): ?><?= $empty('Belum ada catatan pengambilan sertifikat.') ?><?php else: ?>
<div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Nomor Sertifikat</th><th>Status</th><th>Tanggal</th><th>Metode</th><th>Penerima</th></tr></thead><tbody>
<?php foreach ($extra['sertifikat'] as $s): ?><tr><td><code><?= $dash($s['nomor_sertifikat']) ?></code><div class="ape-muted"><?= $dash($s['tahun_ajaran']) ?></div></td>
<td><?= ($s['status_pengambilan'] ?? '') === 'Sudah Diambil' ? '<span class="ape-pill ok">Sudah Diambil</span>' : '<span class="ape-pill wait">' . $dash($s['status_pengambilan']) . '</span>' ?></td>
<td><?= $e(adminProfileExtraWib($s['tanggal_pengambilan'])) ?></td><td><?= $dash($s['metode_pengambilan']) ?></td><td><?= $dash($s['nama_penerima']) ?><div class="ape-muted"><?= $dash($s['hubungan_penerima']) ?></div></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></section>

<!-- 12. Jaminan mahasiswa -->
<section class="card" id="ape-jaminan"><?php $section('12', 'Jaminan Mahasiswa', count($extra['jaminan']) . ' catatan'); ?><div class="card-body">
<?php if (!$extra['jaminan']): ?><?= $empty('Tidak ada catatan jaminan.') ?><?php else: ?>
<div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Jaminan Atas</th><th>Jenis</th><th>Dicatat</th><th>Status</th></tr></thead><tbody>
<?php foreach ($extra['jaminan'] as $j): ?><tr><td><?= $dash($j['jaminan_atas']) ?></td><td><?= $dash($j['jenis_jaminan']) ?></td><td><?= $e(adminProfileExtraWib($j['created_at'])) ?></td><td><?= (int)$j['status_pengambilan'] === 1 ? '<span class="ape-pill ok">Sudah Diambil</span>' : '<span class="ape-pill bad">Belum Diambil</span>' ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></section>
<?php endif; ?>

<!-- 13. Riwayat login -->
<section class="card" id="ape-logins"><?php $lg = $extra['logins']; $section('13', 'Riwayat Login', $lg['total'] . ' login'); ?><div class="card-body">
<?php if (!$lg['total']): ?><?= $empty('Belum ada catatan login.') ?><?php else: ?>
<div class="ape-stats"><div class="ape-stat">Login berhasil terakhir<strong style="font-size:13px;"><?= $e(adminProfileExtraWib($lg['last_success'])) ?></strong></div><div class="ape-stat">Gagal<strong><?= (int)$lg['failed'] ?></strong></div></div>
<div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Waktu</th><th>Status</th><th>Perangkat / Browser</th><th>IP</th></tr></thead><tbody>
<?php foreach ($lg['rows'] as $r): ?><tr><td><?= $e(adminProfileExtraWib($r['created_at'])) ?></td><td><?= ($r['status'] ?? '') === 'success' ? '<span class="ape-pill ok">Berhasil</span>' : '<span class="ape-pill bad">Gagal</span>' ?><?php if (!empty($r['keterangan'])): ?><div class="ape-muted"><?= $e($r['keterangan']) ?></div><?php endif; ?></td><td><?= $dash(trim(($r['device'] ?? '') . ' / ' . ($r['browser'] ?? ''), ' /')) ?></td><td><code><?= $dash($r['ip_address']) ?></code></td></tr><?php endforeach; ?>
</tbody></table></div><p class="ape-muted" style="margin-top:6px;">Menampilkan 10 login terakhir.</p><?php endif; ?></div></section>

<!-- 14. Log aktivitas -->
<section class="card" id="ape-clicks"><?php $ck = $extra['clicks']; $section('14', 'Aktivitas Terakhir', $ck['total'] . ' aktivitas'); ?><div class="card-body">
<?php if (!$ck['rows']): ?><?= $empty('Belum ada catatan aktivitas.') ?><?php else: ?>
<div class="admin-profile-table"><table class="no-datatable"><thead><tr><th>Waktu</th><th>Halaman</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($ck['rows'] as $r): ?><tr><td><?= $e(adminProfileExtraWib($r['created_at'])) ?></td><td><?= $dash($r['page_title'] ?: $r['page_url']) ?><div class="ape-muted"><?= $dash($r['page_url']) ?></div></td><td><?= $dash($r['element_text']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><p class="ape-muted" style="margin-top:6px;">15 aktivitas terakhir · <a href="<?= BASE_URL ?>/admin/log-aktivitas.php" data-no-spa>Buka Log Aktivitas</a></p><?php endif; ?></div></section>

<!-- 15. Pesan -->
<section class="card <?= $isMhs ? 'ape-wide' : '' ?>" id="ape-messages"><?php $ms = $extra['messages']; $section('15', 'Percakapan Pesan', $ms['total'] . ' pesan'); ?><div class="card-body">
<?php if (!$ms['thread'] || !$ms['total']): ?><?= $empty('Belum ada percakapan dengan pengguna ini.') ?><?php else: $t = $ms['thread']; ?>
<div class="ape-stats"><div class="ape-stat">Dari pengguna<strong><?= (int)$ms['from_user'] ?></strong></div><div class="ape-stat">Dari admin<strong><?= (int)$ms['from_admin'] ?></strong></div><div class="ape-stat">Belum dibaca admin<strong><?= (int)($t['admin_unread'] ?? 0) ?></strong></div><div class="ape-stat">Pesan terakhir<strong style="font-size:13px;"><?= $e(adminProfileExtraWib((int)($t['last_message_at'] ?? 0))) ?></strong></div></div>
<p style="margin:0 0 10px;font-size:13px;"><span class="ape-muted"><?= ($t['last_sender_role'] ?? '') === 'admin' ? 'Admin:' : 'Pengguna:' ?></span> <?= $dash($t['last_message_preview'] ?? '') ?></p>
<?php endif; ?>
<button type="button" class="btn btn-primary btn-sm" id="ape-open-chat" data-user-id="<?= (int)$profileUser['id'] ?>" data-thread-id="<?= (int)($ms['thread']['id'] ?? 0) ?>">💬 Buka percakapan dengan pengguna ini</button>
<span class="ape-muted" id="ape-open-chat-msg" role="status"></span>
</div></section>
</div>

<script>
(function () {
    var btn = document.getElementById('ape-open-chat');
    if (!btn) return;
    btn.addEventListener('click', async function () {
        var msg = document.getElementById('ape-open-chat-msg');
        var cfg = window.LPPAI_MSG;
        if (!cfg || !window.LppaiMessages) { if (msg) msg.textContent = ' Widget Pesan belum siap. Muat ulang halaman.'; return; }
        var threadId = parseInt(btn.dataset.threadId || '0', 10);
        try {
            if (!threadId) {
                var res = await fetch(cfg.api, { method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'start', user_id: parseInt(btn.dataset.userId, 10), csrf_token: cfg.csrf }) });
                var data = await res.json();
                if (!res.ok || !data.success) throw new Error(data.message || 'Gagal membuka percakapan.');
                threadId = data.thread.id;
                btn.dataset.threadId = String(threadId);
            }
            window.LppaiMessages.open(threadId);
            if (msg) msg.textContent = '';
        } catch (err) {
            if (msg) msg.textContent = ' ' + err.message;
        }
    });
})();
</script>
