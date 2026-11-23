<?php
define('PAGE_TITLE', 'Periode Pendaftaran Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/tutorial-rpl-periods.php';

$error = '';
$periods = [];
$ready = false;
$defaults = tutorialRplRegistrationPolicy();
$form = ['id' => 0, 'version' => 1, 'nama' => 'Tutorial RPL 2026/2027', 'gelombang' => 'Gelombang 1',
    'opens_at' => $defaults['opens_at'], 'closes_at' => $defaults['closes_at'], 'quota' => 0, 'active_slot' => null];
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
try {
    $pdo = getDBConnection();
    ensureTutorialRplPeriodTable($pdo);
    $ready = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach (array_keys($form) as $key) {
            if (isset($_POST[$key]) && !is_string($_POST[$key])) throw new InvalidArgumentException('Format data periode tidak valid.');
        }
        $form = array_replace($form, array_intersect_key($_POST, $form));
        $form['active_slot'] = ($_POST['active_slot'] ?? '') === '1' ? '1' : null;
        if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) {
            http_response_code(403);
            throw new InvalidArgumentException('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.');
        }
        tutorialRplSavePeriod($pdo, $_POST, getCurrentUser());
        $_SESSION['rpl_period_saved'] = true;
        header('Location: ' . BASE_URL . '/admin/tutorial-rpl-periode.php');
        exit;
    }
    $periods = $pdo->query('SELECT * FROM tutorial_rpl_registration_periods ORDER BY opens_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    if (isset($_GET['edit'])) {
        $id = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $found = false;
        foreach ($periods as $period) {
            if ($id && (int)$period['id'] === $id) { $form = $period; $found = true; break; }
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
</style>
<div class="rpl-period-hero"><h2>Periode Pendaftaran Tutorial RPL</h2><p>Atur jadwal dan kuota khusus mahasiswa RPL, terpisah dari tutorial reguler.</p></div>
<div class="rpl-period-note">Halaman ini mengatur periode pendaftaran khusus RPL. Formulir pendaftaran mahasiswa secara mandiri dan melalui admin belum tersedia. Mengaktifkan periode belum membuka formulir tersebut.</div>
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
<div><label for="quota">Kuota pendaftar</label><input class="form-control" type="number" id="quota" name="quota" min="0" max="1000000" required value="<?= $escape($form['quota']) ?>"><small>0 = tanpa batas. Terpisah dari kuota kelas. Kuota ini akan diterapkan ketika fitur pendaftaran khusus RPL tersedia.</small></div>
<div><label><input style="width:auto" type="checkbox" name="active_slot" value="1" <?= (int)$form['active_slot'] === 1 ? 'checked' : '' ?>> Aktifkan periode</label><small>Hanya satu periode aktif. Status buka/tutup mengikuti jadwal WIB. Hilangkan centang untuk menonaktifkan tanpa menghapus riwayat.</small></div>
</div>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:20px"><button type="submit" class="btn btn-primary">Simpan periode</button><a class="btn btn-secondary" href="<?= BASE_URL ?>/admin/tutorial-rpl-periode.php">Buat baru / batal edit</a></div>
</form></div></section>
<?php endif; ?>
<section class="card" style="margin-top:20px"><div class="card-header">Daftar periode RPL</div><div class="card-body" style="overflow-x:auto">
<table class="table"><thead><tr><th>Periode</th><th>Jadwal (WIB)</th><th>Kuota</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($periods as $period): $state = tutorialRplRegistrationWindow(null, tutorialRplPeriodPolicy($period)); $labels = ['inactive'=>'Nonaktif','soon'=>'Terjadwal','open'=>'Dibuka sesuai jadwal','closed'=>'Ditutup sesuai jadwal']; ?>
<tr><td><strong><?= $escape($period['nama']) ?></strong><br><?= $escape($period['gelombang']) ?></td><td><?= $escape(tutorialRplPeriodSchedule($period)) ?></td><td><?= (int)$period['quota'] === 0 ? 'Tanpa batas' : (int)$period['quota'] ?></td><td><span class="rpl-period-state <?= $escape($state) ?>"><?= $escape($labels[$state]) ?></span></td><td><a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/admin/tutorial-rpl-periode.php?edit=<?= (int)$period['id'] ?>">Edit</a></td></tr>
<?php endforeach; ?>
<?php if (!$periods): ?><tr><td colspan="5">Belum ada periode. Buat periode pertama menggunakan formulir di atas.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
