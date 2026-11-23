<?php
define('PAGE_TITLE', 'Periode Pendaftaran Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/tutorial-rpl-periods.php';

$error = '';
$periods = [];
$ready = false;
$lecturers = []; $scheduleRows = [];
$defaults = tutorialRplRegistrationPolicy();
$form = ['id' => 0, 'version' => 1, 'nama' => 'Tutorial RPL 2026/2027', 'gelombang' => 'Gelombang 1',
    'opens_at' => $defaults['opens_at'], 'closes_at' => $defaults['closes_at'], 'quota' => 0, 'active_slot' => null];
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
try {
    $pdo = getDBConnection();
    ensureTutorialRplPeriodTable($pdo);
    $lecturers = $pdo->query("SELECT id,nama_lengkap FROM users WHERE role='dosen' ORDER BY nama_lengkap,id")->fetchAll(PDO::FETCH_ASSOC);
    $ready = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $scheduleRows = is_array($_POST['schedules'] ?? null) ? $_POST['schedules'] : [];
        foreach (array_keys($form) as $key) {
            if (isset($_POST[$key]) && !is_string($_POST[$key])) throw new InvalidArgumentException('Format data periode tidak valid.');
        }
        $form = array_replace($form, array_intersect_key($_POST, $form));
        $form['active_slot'] = ($_POST['active_slot'] ?? '') === '1' ? '1' : null;
        if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) {
            http_response_code(403);
            throw new InvalidArgumentException('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.');
        }
        if (($_POST['schedules_complete'] ?? '') !== '1') throw new InvalidArgumentException('Formulir dosen belum terkirim lengkap. Muat ulang halaman dan coba kembali dengan jumlah baris lebih sedikit.');
        tutorialRplSavePeriod($pdo, array_replace($_POST,['schedules'=>$_POST['schedules'] ?? []]), getCurrentUser());
        $_SESSION['rpl_period_saved'] = true;
        header('Location: ' . BASE_URL . '/admin/tutorial-rpl-periode.php');
        exit;
    }
    $periods = $pdo->query('SELECT * FROM tutorial_rpl_registration_periods ORDER BY opens_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    if (isset($_GET['edit'])) {
        $id = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $found = false;
        foreach ($periods as $period) {
            if ($id && (int)$period['id'] === $id) { $form = $period; $scheduleRows = tutorialRplPeriodTutors($pdo,$id); $found = true; break; }
        }
        if (!$found) { http_response_code(404); $error = 'Periode tidak ditemukan.'; }
    }
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('RPL registration period: ' . $e->getMessage());
    $error = 'Periode belum dapat disimpan atau dimuat. Muat ulang halaman; pastikan tidak ada periode aktif lain.';
}
// Keep the history visible when a submitted form fails validation.
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '') {
    try { $periods = $pdo->query('SELECT * FROM tutorial_rpl_registration_periods ORDER BY opens_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { error_log('RPL period list: ' . $e->getMessage()); }
}
$saved = !empty($_SESSION['rpl_period_saved']);
unset($_SESSION['rpl_period_saved']);
$renderSchedule = static function($index, $row) use ($lecturers,$escape) {
    $row = is_array($row) ? array_filter($row,'is_scalar') : [];
    ?>
    <div class="rpl-tutor-row" style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:10px;align-items:end;margin-bottom:12px">
    <label>Dosen<select class="form-control" name="schedules[<?= $escape($index) ?>][dosen_id]" required><option value="">Pilih dosen</option><?php if (!empty($row['dosen_id']) && !in_array((int)$row['dosen_id'],array_map('intval',array_column($lecturers,'id')),true)): ?><option value="<?= (int)$row['dosen_id'] ?>" selected>Dosen #<?= (int)$row['dosen_id'] ?> (akun tidak tersedia)</option><?php endif; ?><?php foreach ($lecturers as $lecturer): ?><option value="<?= (int)$lecturer['id'] ?>" <?= (int)($row['dosen_id'] ?? 0)===(int)$lecturer['id'] ? 'selected' : '' ?>><?= $escape($lecturer['nama_lengkap']) ?></option><?php endforeach; ?></select></label>
    <label>Hari<select class="form-control" name="schedules[<?= $escape($index) ?>][hari]" required><option value="">Pilih hari</option><?php foreach (TUTORIAL_RPL_DAYS as $day): ?><option <?= ($row['hari'] ?? '')===$day ? 'selected' : '' ?>><?= $escape($day) ?></option><?php endforeach; ?></select></label>
    <label>Kuota dosen<input class="form-control" type="number" min="1" max="1000000" name="schedules[<?= $escape($index) ?>][quota]" required value="<?= $escape($row['quota'] ?? 30) ?>"></label>
    <button class="btn btn-secondary" type="button" data-remove-rpl-tutor aria-label="Hapus baris dosen">Hapus</button></div>
    <?php
};
include __DIR__ . '/../includes/header.php';
?>
<style>
.rpl-period-hero{background:linear-gradient(120deg,#073d2b,#15715a);color:#fff;padding:26px;border-radius:18px;margin-bottom:20px}
.rpl-period-hero h2{color:#fff;margin:0 0 8px}.rpl-period-hero p{margin:0;opacity:.9}
.rpl-period-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.rpl-period-grid label{display:block;font-weight:600;margin-bottom:6px}.rpl-period-grid input{width:100%;box-sizing:border-box}
.rpl-period-note{padding:14px 18px;border:1px solid #bfdbfe;background:#eff6ff;color:#1e40af;border-radius:12px;margin-bottom:18px}
.rpl-period-state{display:inline-block;padding:4px 10px;border-radius:20px;background:#e2e8f0;color:#334155;white-space:nowrap}.rpl-period-state.open{background:#dcfce7;color:#166534}
@media(max-width:640px){.rpl-period-grid{grid-template-columns:1fr}}
@media(max-width:720px){.rpl-tutor-row{grid-template-columns:1fr!important;border-bottom:1px solid #e2e8f0;padding-bottom:16px}}
</style>
<div class="rpl-period-hero"><h2>Periode Pendaftaran Tutorial RPL</h2><p>Atur jadwal dan kuota khusus mahasiswa RPL, terpisah dari tutorial reguler.</p></div>
<div class="rpl-period-note">Periode aktif mengikuti jadwal WIB untuk pendaftaran mandiri maupun melalui admin. <a href="<?= BASE_URL ?>/admin/tutorial-rpl-pendaftaran.php">Buka pendaftaran peserta RPL</a>.</div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<?php if ($saved): ?><div class="alert alert-success" role="status">Periode berhasil disimpan.</div><?php endif; ?>
<?php if ($ready): ?>
<section class="card"><div class="card-header"><?= (int)$form['id'] > 0 ? 'Edit periode' : 'Buat periode baru' ?></div><div class="card-body">
<form method="post" action="<?= BASE_URL ?>/admin/tutorial-rpl-periode.php">
<input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
<input type="hidden" name="id" value="<?= $escape($form['id']) ?>"><input type="hidden" name="version" value="<?= $escape($form['version']) ?>">
<div class="rpl-period-grid">
<div><label for="nama">Nama periode</label><input class="form-control" id="nama" name="nama" maxlength="100" required value="<?= $escape($form['nama']) ?>"></div>
<div><label for="gelombang">Gelombang</label><input class="form-control" id="gelombang" name="gelombang" maxlength="50" required value="<?= $escape($form['gelombang']) ?>"></div>
<div><label for="opens_at">Buka pendaftaran (WIB)</label><input class="form-control" type="datetime-local" step="1" id="opens_at" name="opens_at" required value="<?= $escape(str_replace(' ', 'T', $form['opens_at'])) ?>"></div>
<div><label for="closes_at">Tutup pendaftaran (WIB)</label><input class="form-control" type="datetime-local" step="1" id="closes_at" name="closes_at" required value="<?= $escape(str_replace(' ', 'T', $form['closes_at'])) ?>"></div>
<div><label for="quota">Batas total pendaftar (opsional)</label><input class="form-control" type="number" id="quota" name="quota" min="0" max="1000000" required value="<?= $escape($form['quota']) ?>"><small>0 = mengikuti kuota dosen per hari tanpa batas tambahan. Jika diisi, batas total ini juga berlaku bersama untuk kedua jalur.</small></div>
<div><label><input style="width:auto" type="checkbox" name="active_slot" value="1" <?= (int)$form['active_slot'] === 1 ? 'checked' : '' ?>> Aktifkan periode</label><small>Hanya satu periode aktif. Status buka/tutup mengikuti jadwal WIB. Hilangkan centang untuk menonaktifkan tanpa menghapus riwayat.</small></div>
</div>
<fieldset style="margin-top:24px;border:1px solid #dbe5df;border-radius:12px;padding:16px"><legend style="font-weight:700;padding:0 8px">Dosen, pilihan hari, dan kuota</legend>
<p>Tambahkan akun dosen yang sudah tersedia. Satu dosen dapat memiliki beberapa hari; kuota berlaku per dosen pada masing-masing hari. Mahasiswa hanya memilih hari, lalu sistem mencatat tempat yang masih tersedia. Pembagian kelas dilakukan terpisah.</p>
<?php if (!$lecturers): ?><div class="alert alert-warning">Belum ada akun dosen. Tambahkan akun dengan peran dosen melalui Kelola Pengguna terlebih dahulu.</div><?php endif; ?>
<div id="rpl-tutor-rows"><?php foreach (array_values($scheduleRows) as $i=>$row) $renderSchedule($i,$row); ?></div>
<button class="btn btn-secondary" type="button" id="add-rpl-tutor">+ Tambah dosen dan hari</button>
<template id="rpl-tutor-template"><?php $renderSchedule('__INDEX__',[]); ?></template>
</fieldset>
<input type="hidden" name="schedules_complete" value="1">
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:20px"><button type="submit" class="btn btn-primary">Simpan periode</button><a class="btn btn-secondary" href="<?= BASE_URL ?>/admin/tutorial-rpl-periode.php">Buat baru / batal edit</a></div>
</form></div></section>
<?php endif; ?>
<section class="card" style="margin-top:20px"><div class="card-header">Daftar periode RPL</div><div class="card-body" style="overflow-x:auto">
<table class="table"><thead><tr><th>Periode</th><th>Jadwal (WIB)</th><th>Kuota</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($periods as $period): $state = tutorialRplRegistrationWindow(null, tutorialRplPeriodPolicy($period)); $labels = ['inactive'=>'Nonaktif','soon'=>'Terjadwal','open'=>'Dibuka sesuai jadwal','closed'=>'Ditutup sesuai jadwal']; ?>
<tr><td><strong><?= $escape($period['nama']) ?></strong><br><?= $escape($period['gelombang']) ?></td><td><?= $escape(tutorialRplPeriodSchedule($period)) ?></td><td><?= (int)$period['quota'] === 0 ? 'Mengikuti kuota dosen' : (int)$period['quota'] ?></td><td><span class="rpl-period-state <?= $escape($state) ?>"><?= $escape($labels[$state]) ?></span></td><td><a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/admin/tutorial-rpl-periode.php?edit=<?= (int)$period['id'] ?>">Edit</a></td></tr>
<?php endforeach; ?>
<?php if (!$periods): ?><tr><td colspan="5">Belum ada periode. Buat periode pertama menggunakan formulir di atas.</td></tr><?php endif; ?>
</tbody></table></div></section>
<script>
(()=>{const rows=document.getElementById('rpl-tutor-rows'),add=document.getElementById('add-rpl-tutor'),template=document.getElementById('rpl-tutor-template');if(!rows||!add||!template)return;let index=rows.children.length;add.addEventListener('click',()=>{rows.insertAdjacentHTML('beforeend',template.innerHTML.replaceAll('__INDEX__',String(index++)));});rows.addEventListener('click',event=>{const button=event.target.closest('[data-remove-rpl-tutor]');if(button)button.closest('.rpl-tutor-row').remove();});})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
