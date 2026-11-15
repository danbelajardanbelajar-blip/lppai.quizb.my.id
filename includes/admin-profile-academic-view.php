<?php
/** Admin-only academic details; included after requireAdmin() by profil-pengguna.php. */
$latestPretes = $profileHistory['pretes'][0] ?? null;
$latestTutorial = $profileHistory['tutorial'][0] ?? null;
$latestResult = $profileHistory['results'][0] ?? null;
$display = static fn($value) => sanitize($value === null || trim((string)$value) === '' ? '-' : (string)$value);
$tutorialScores = [
    'nilai_thaharah' => 'Thaharah', 'nilai_shalat' => 'Shalat',
    'nilai_surat_pendek' => 'Surat Pendek', 'nilai_amaliyah' => 'Amaliyah',
    'nilai_jenazah' => 'Jenazah', 'nilai_ujian_tulis' => 'Ujian Tulis', 'nilai_akhir' => 'Nilai Akhir',
];
?>
<section class="card"><div class="card-header">Status Kegiatan &amp; Kelulusan</div><div class="card-body">
    <p style="color:#64748b;">Ringkasan pendaftaran terakhir. Status pendaftaran, hasil penilaian, dan hasil resmi ditampilkan terpisah.</p>
    <dl class="admin-profile-fields">
        <div><dt>Status Pretes</dt><dd><?= $display(adminProfilePretesActivity($latestPretes)) ?></dd></div>
        <div><dt>Status Tutorial</dt><dd><?= $display(adminProfileTutorialActivity($latestTutorial)) ?></dd></div>
        <div><dt>Hasil Penilaian Pretes Terakhir</dt><dd><?= $display($latestPretes ? adminProfileStatus($latestPretes['assessment_status']) : 'Belum Ada Penilaian') ?></dd></div>
        <div><dt>Kelulusan Pretes Resmi Terakhir</dt><dd><?= $display($latestResult ? adminProfileStatus($latestResult['status_lulus'] ?? '') : 'Belum Ada Hasil Resmi') ?></dd></div>
        <div><dt>Kelulusan Tutorial Terakhir</dt><dd><?= $display($latestTutorial ? adminProfileStatus($latestTutorial['status'] ?? '') : 'Belum Ada Hasil') ?></dd></div>
        <div><dt>Nomor Sertifikat Terakhir</dt><dd><?= $display($latestTutorial['nomor_sertifikat'] ?? null) ?></dd></div>
    </dl>
</div></section>

<section class="card"><div class="card-header">Riwayat Pendaftaran &amp; Kelas Pretes</div><div class="card-body">
<?php if (!$profileHistory['pretes']): ?><p>Belum ada riwayat pretes yang tersedia.</p><?php else: ?>
<p><?= count($profileHistory['pretes']) ?> pendaftaran. Kelompok pretes ditampilkan berdasarkan jadwal, ruangan, dan penguji.</p>
<?php foreach ($profileHistory['pretes'] as $row): ?>
<article class="admin-profile-record">
    <h3><?= $display($row['periode'] ?? null) ?></h3>
    <dl class="admin-profile-fields">
        <?php foreach (['id'=>'ID Pendaftaran','pretes_schedule_id'=>'ID Jadwal / Kelas Pretes','hari_pilihan'=>'Hari','jam'=>'Jam','ruangan'=>'Ruangan / Kelompok Pretes','tanggal_daftar'=>'Tanggal Pendaftaran'] as $field=>$label): ?>
        <div><dt><?= $display($label) ?></dt><dd><?= $display($row[$field] ?? null) ?></dd></div>
        <?php endforeach; ?>
        <div><dt>Dosen Penguji</dt><dd><?= adminUserProfileLink($row['dosen_penguji'] ?? '-') ?></dd></div>
        <div><dt>Status Pendaftaran</dt><dd><?= $display(adminProfileStatus($row['status'] ?? '')) ?></dd></div>
        <div><dt>Status Periode</dt><dd><?= $display(adminProfileStatus($row['period_status'])) ?></dd></div>
        <div><dt>Status Kegiatan</dt><dd><?= $display(adminProfilePretesActivity($row)) ?></dd></div>
        <div><dt>Hasil Penilaian</dt><dd><?= $display(adminProfileStatus($row['assessment_status'])) ?></dd></div>
        <?php if (!empty($row['foto'])): ?>
        <?php
            $pretesPhotoUrl = null;
            $rawP = trim((string)$row['foto']);
            if (preg_match('~^https?://~i', $rawP) || preg_match('~^data:image/~i', $rawP)) {
                $pretesPhotoUrl = $rawP;
            } elseif ($rawP !== '') {
                $pretesPhotoUrl = rtrim(BASE_URL, '/') . '/' . ltrim(str_replace('\\', '/', $rawP), '/');
            }
        ?>
        <?php if ($pretesPhotoUrl): ?>
        <div><dt>Pasfoto Pretes</dt><dd>
            <div class="admin-pretes-photo-thumb" style="display:inline-block;position:relative;cursor:zoom-in;border-radius:6px;overflow:hidden;" title="🔍 Klik untuk memperbesar pasfoto pretes" onclick="openPhotoZoom('<?= htmlspecialchars($pretesPhotoUrl, ENT_QUOTES, 'UTF-8') ?>', 'Pasfoto Pretes — <?= htmlspecialchars(addslashes($display($row['periode'] ?? '')), ENT_QUOTES, 'UTF-8') ?>')">
                <img src="<?= htmlspecialchars($pretesPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Pasfoto" style="width:40px;height:54px;object-fit:cover;border-radius:6px;border:1.5px solid #cbd5e1;box-shadow:0 2px 6px rgba(0,0,0,0.08);pointer-events:none;" draggable="false" oncontextmenu="return false;">
                <div style="position:absolute;inset:0;cursor:zoom-in;" oncontextmenu="return false;"></div>
            </div>
        </dd></div>
        <?php endif; ?>
        <?php endif; ?>
    </dl>
    <details><summary>Rincian Nilai Pretes (<?= count(pretesComponents()) ?> komponen)</summary>
        <dl class="admin-profile-fields">
        <?php foreach (pretesComponents() as $key=>$label): ?>
            <div><dt><?= $display($label) ?></dt><dd><?= $display($row['scores'][$key] ?? null) ?></dd></div>
        <?php endforeach; ?>
        </dl>
    </details>
</article>
<?php endforeach; endif; ?>
</div></section>

<section class="card"><div class="card-header">Riwayat Hasil Resmi Pretes</div><div class="card-body">
<?php if (!$profileHistory['results']): ?><p>Belum ada hasil resmi pretes yang tersedia.</p><?php else: ?>
<p style="color:#64748b;">Hasil resmi dicatat terpisah dari penilaian tiap pendaftaran pretes.</p>
<?php foreach ($profileHistory['results'] as $row): ?>
<article class="admin-profile-record"><dl class="admin-profile-fields">
    <div><dt>Kelulusan</dt><dd><?= $display(adminProfileStatus($row['status_lulus'] ?? '')) ?></dd></div>
    <?php foreach (['pretes_schedule_id'=>'ID Jadwal Pretes','nilai'=>'Nilai','keterangan'=>'Keterangan','created_at'=>'Tanggal Pencatatan'] as $field=>$label): ?>
    <div><dt><?= $display($label) ?></dt><dd><?= $display($row[$field] ?? null) ?></dd></div>
    <?php endforeach; ?>
</dl></article>
<?php endforeach; endif; ?>
</div></section>

<section class="card"><div class="card-header">Riwayat Tutorial &amp; Kelas Tutorial</div><div class="card-body">
<?php if (!$profileHistory['tutorial']): ?><p>Belum ada riwayat tutorial yang tersedia.</p><?php else: ?>
<p><?= count($profileHistory['tutorial']) ?> pendaftaran tutorial.</p>
<?php foreach ($profileHistory['tutorial'] as $row): ?>
<article class="admin-profile-record">
    <h3><?= $display(($row['nama_kelas'] ?? '') ?: 'Menunggu Pembagian Kelas') ?></h3>
    <dl class="admin-profile-fields">
        <?php foreach (['id'=>'ID Pendaftaran','tutorial_class_id'=>'ID Kelas Tutorial','tahun_ajaran'=>'Tahun Ajaran','class_semester'=>'Semester Kelas','gelombang'=>'Gelombang Pendaftaran','class_gelombang'=>'Gelombang Kelas','hari_pilihan'=>'Hari Pilihan','hari'=>'Hari Kelas','jam'=>'Jam','ruangan'=>'Ruangan','tipe_nilai'=>'Tipe Nilai','created_at'=>'Tanggal Pendaftaran','nomor_sertifikat'=>'Nomor Sertifikat'] as $field=>$label): ?>
        <div><dt><?= $display($label) ?></dt><dd><?= $display($row[$field] ?? null) ?></dd></div>
        <?php endforeach; ?>
        <div><dt>Dosen Pengampu</dt><dd><?= adminUserProfileLink($row['dosen_pengampu'] ?? '-') ?></dd></div>
        <div><dt>Status Pendaftaran / Kelulusan</dt><dd><?= $display(adminProfileStatus($row['status'] ?? '')) ?></dd></div>
    </dl>
    <details><summary>Rincian Nilai Tutorial</summary><dl class="admin-profile-fields">
    <?php foreach ($tutorialScores as $key=>$label): ?>
        <div><dt><?= $display($label) ?></dt><dd><?= $display($row[$key] ?? null) ?></dd></div>
    <?php endforeach; ?>
    </dl></details>
</article>
<?php endforeach; endif; ?>
</div></section>
