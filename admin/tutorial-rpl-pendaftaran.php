<?php
define('PAGE_TITLE', 'Pendaftaran Peserta Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/tutorial-rpl-registration.php';
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$error = ''; $period = null; $periods = []; $students = []; $registrations = []; $count = 0; $canRegister = false;
$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']),0,80) : '';
try {
    $pdo = getDBConnection(); ensureTutorialRplRegistrationTable($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
        if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) {
            http_response_code(403); throw new DomainException('Sesi formulir kedaluwarsa. Muat ulang halaman.');
        }
        $periodId = filter_var($_POST['period_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $studentId = filter_var($_POST['user_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$periodId || !$studentId) throw new DomainException('Mahasiswa atau periode tidak valid.');
        $result = tutorialRplRegister($pdo,$periodId,$studentId,getCurrentUser(),'admin');
        $_SESSION['rpl_admin_registration_notice'] = $result['created'] ? 'Mahasiswa berhasil didaftarkan. Pendaftaran tersimpan dan menunggu pembagian kelas.' : 'Mahasiswa sudah terdaftar; tidak dibuat pendaftaran ganda.';
        header('Location: ' . BASE_URL . '/admin/tutorial-rpl-pendaftaran.php?period_id=' . $periodId); exit;
        } catch (DomainException $e) { $error = $e->getMessage(); }
    }
    $periods = $pdo->query('SELECT * FROM tutorial_rpl_registration_periods ORDER BY opens_at DESC,id DESC')->fetchAll(PDO::FETCH_ASSOC);
    $requested = filter_var($_POST['period_id'] ?? $_GET['period_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    foreach ($periods as $item) if (($requested && (int)$item['id'] === $requested) || (!$requested && (int)$item['active_slot'] === 1)) { $period = $item; break; }
    if ($requested && !$period) throw new DomainException('Periode yang dipilih tidak ditemukan.');
    if ($period) {
        $count = tutorialRplRegistrationCount($pdo,(int)$period['id']);
        $window = tutorialRplRegistrationWindow(null,tutorialRplPeriodPolicy($period));
        $canRegister = $window === 'open' && ((int)$period['quota'] === 0 || $count < (int)$period['quota']);
        $windowLabels = ['inactive'=>'Periode nonaktif.', 'soon'=>'Pendaftaran belum dibuka.', 'closed'=>'Pendaftaran sudah ditutup.', 'open'=>$canRegister ? 'Pendaftaran dibuka.' : 'Kuota pendaftaran penuh.'];
        $stmt = $pdo->prepare('SELECT r.*,u.nama_lengkap,u.nim,a.nama_lengkap AS actor_name FROM tutorial_rpl_registrations r JOIN users u ON u.id=r.user_id LEFT JOIN users a ON a.id=r.registered_by WHERE r.period_id=? ORDER BY r.id DESC LIMIT 50');
        $stmt->execute([(int)$period['id']]); $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (mb_strlen($query) >= 2) {
            $stmt = $pdo->prepare("SELECT u.id,u.nama_lengkap,u.nim,r.id AS registration_id FROM users u LEFT JOIN tutorial_rpl_registrations r ON r.user_id=u.id AND r.period_id=? WHERE u.role='mahasiswa' AND UPPER(TRIM(u.jenis_program))='RPL' AND (u.nama_lengkap LIKE ? OR u.nim LIKE ?) ORDER BY u.nama_lengkap,u.id LIMIT 20");
            $stmt->execute([(int)$period['id'],'%'.$query.'%','%'.$query.'%']); $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (DomainException $e) { $error = $e->getMessage(); }
catch (Throwable $e) { error_log('RPL admin registration: '.$e->getMessage()); $error = 'Pendaftaran atau hasil pretes belum dapat diperiksa. Coba kembali; jika berulang hubungi pengelola server.'; }
$notice = $_SESSION['rpl_admin_registration_notice'] ?? ''; unset($_SESSION['rpl_admin_registration_notice']);
include __DIR__ . '/../includes/header.php';
?>
<div style="background:linear-gradient(120deg,#073d2b,#15715a);color:white;padding:26px;border-radius:18px;margin-bottom:20px"><h2 style="color:white;margin:0 0 8px">Pendaftaran Peserta Tutorial RPL</h2><p style="margin:0">Daftarkan mahasiswa RPL dengan syarat, jadwal, dan kuota yang sama seperti pendaftaran mandiri.</p></div>
<?php if ($notice): ?><div class="alert alert-success" role="status"><?= $escape($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<section class="card"><div class="card-header">Pilih periode</div><div class="card-body">
<form method="get"><label for="period_id">Periode RPL</label><select class="form-control" id="period_id" name="period_id" required><option value="">Pilih periode</option><?php foreach ($periods as $item): ?><option value="<?= (int)$item['id'] ?>" <?= $period && (int)$period['id']===(int)$item['id'] ? 'selected' : '' ?>><?= $escape($item['nama'].' · '.$item['gelombang']) ?></option><?php endforeach; ?></select><button class="btn btn-secondary" type="submit" style="margin-top:10px">Tampilkan periode</button></form>
<?php if ($period): ?><p style="margin-top:16px"><?= $escape(tutorialRplPeriodSchedule($period)) ?><br>Terdaftar: <strong><?= $count ?></strong> / <?= (int)$period['quota'] === 0 ? 'Tanpa batas' : (int)$period['quota'] ?>. <?= $escape($windowLabels[$window]) ?></p><?php elseif (!$error): ?><p>Belum ada periode aktif. <a href="<?= BASE_URL ?>/admin/tutorial-rpl-periode.php">Kelola periode RPL</a>.</p><?php endif; ?>
</div></section>
<?php if ($period): ?>
<section class="card"><div class="card-header">Cari mahasiswa untuk didaftarkan</div><div class="card-body">
<form method="get"><input type="hidden" name="period_id" value="<?= (int)$period['id'] ?>"><label for="q">Nama atau NIM mahasiswa RPL</label><input class="form-control" id="q" name="q" minlength="2" maxlength="80" value="<?= $escape($query) ?>" placeholder="Ketik minimal 2 karakter"><button class="btn btn-primary" style="margin-top:10px" type="submit">Cari mahasiswa</button></form>
<p>Kelulusan pretes diperiksa ulang saat tombol Daftarkan ditekan. Pendaftaran belum menempatkan mahasiswa ke kelas.</p>
<div style="overflow-x:auto"><table class="table"><thead><tr><th>Mahasiswa</th><th>NIM</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($students as $row): ?><tr><td><?= $escape($row['nama_lengkap']) ?></td><td><?= $escape($row['nim']) ?></td><td><?php if ($row['registration_id']): ?>Sudah terdaftar<?php elseif ($canRegister && !$error): ?><form method="post" action="<?= BASE_URL ?>/admin/tutorial-rpl-pendaftaran.php?period_id=<?= (int)$period['id'] ?>&amp;q=<?= $escape(rawurlencode($query)) ?>"><input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>"><input type="hidden" name="period_id" value="<?= (int)$period['id'] ?>"><input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">Daftarkan</button></form><?php else: ?>Pendaftaran tidak tersedia<?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$students): ?><tr><td colspan="3"><?= mb_strlen($query) >= 2 ? 'Mahasiswa RPL tidak ditemukan.' : 'Masukkan nama atau NIM untuk mencari, maksimal 20 hasil.' ?></td></tr><?php endif; ?>
</tbody></table></div></div></section>
<section class="card"><div class="card-header">Pendaftaran tersimpan · 50 terbaru</div><div class="card-body" style="overflow-x:auto"><table class="table"><thead><tr><th>Mahasiswa / NIM</th><th>Waktu (WIB)</th><th>Jalur</th><th>Didaftarkan oleh</th></tr></thead><tbody>
<?php foreach ($registrations as $row): ?><tr><td><?= $escape($row['nama_lengkap']) ?><br><?= $escape($row['nim']) ?></td><td><?= $escape($row['registered_at']) ?></td><td><?= $escape($row['channel']) ?></td><td><?= $escape($row['actor_name'] ?? '#'.$row['registered_by']) ?></td></tr><?php endforeach; ?>
<?php if (!$registrations): ?><tr><td colspan="4">Belum ada pendaftaran tersimpan pada periode ini.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
