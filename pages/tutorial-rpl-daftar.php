<?php
define('PAGE_TITLE', 'Daftar Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/tutorial-rpl-registration.php';
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$error = ''; $period = null; $existing = null; $decision = null; $history = []; $count = 0;
$actor = getCurrentUser();
try {
    $pdo = getDBConnection();
    ensureTutorialRplRegistrationTable($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
        if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) {
            http_response_code(403); throw new DomainException('Sesi formulir kedaluwarsa. Muat ulang halaman.');
        }
        $periodId = filter_var($_POST['period_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$periodId) throw new DomainException('Periode tidak valid. Muat ulang halaman.');
        $result = tutorialRplRegister($pdo, $periodId, (int)$actor['id'], $actor, 'mandiri');
        $_SESSION['rpl_registration_notice'] = $result['created'] ? 'Pendaftaran Tutorial RPL berhasil disimpan. Anda menunggu pembagian kelas.' : 'Anda sudah terdaftar pada periode ini. Tidak dibuat pendaftaran ganda.';
        header('Location: ' . BASE_URL . '/tutorial-rpl-daftar.php'); exit;
        } catch (DomainException $e) { $error = $e->getMessage(); }
    }
    $period = tutorialRplActivePeriod($pdo);
    $stmt = $pdo->prepare('SELECT id, role, jenis_program FROM users WHERE id=?');
    $stmt->execute([(int)$actor['id']]); $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (($student['role'] ?? '') !== 'mahasiswa' || strtoupper(trim((string)($student['jenis_program'] ?? ''))) !== 'RPL') {
        http_response_code(403); throw new DomainException('Halaman pendaftaran ini khusus mahasiswa RPL.');
    }
    $stmt = $pdo->prepare('SELECT r.*, p.nama, p.gelombang FROM tutorial_rpl_registrations r JOIN tutorial_rpl_registration_periods p ON p.id=r.period_id WHERE r.user_id=? ORDER BY r.id DESC LIMIT 10');
    $stmt->execute([(int)$actor['id']]); $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($period) {
        $existing = tutorialRplRegistrationFor($pdo, (int)$period['id'], (int)$actor['id']);
        $count = tutorialRplRegistrationCount($pdo, (int)$period['id']);
        $decision = tutorialRplRegistrationDecision($student, $student, 'mandiri', tutorialRplPretesEvidence($pdo, (int)$student['id']), null, tutorialRplPeriodPolicy($period));
    }
} catch (DomainException $e) { $error = $e->getMessage(); }
catch (Throwable $e) { error_log('RPL self registration: ' . $e->getMessage()); $error = 'Data pendaftaran atau hasil pretes belum dapat diperiksa. Coba kembali atau hubungi admin.'; }
$notice = $_SESSION['rpl_registration_notice'] ?? ''; unset($_SESSION['rpl_registration_notice']);
include __DIR__ . '/../includes/header.php';
?>
<div style="max-width:820px;margin:auto">
<div style="background:linear-gradient(120deg,#073d2b,#15715a);color:#fff;border-radius:18px;padding:26px;margin-bottom:20px"><h2 style="color:#fff;margin:0 0 8px">Daftar Tutorial RPL</h2><p style="margin:0">Pendaftaran mandiri untuk mahasiswa RPL yang belum pretes atau belum lulus.</p></div>
<?php if ($notice): ?><div class="alert alert-success" role="status"><?= $escape($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<section class="card"><div class="card-header">Periode pendaftaran</div><div class="card-body">
<?php if ($period): ?>
<h3><?= $escape($period['nama']) ?> · <?= $escape($period['gelombang']) ?></h3><p><?= $escape(tutorialRplPeriodSchedule($period)) ?></p>
<p>Terdaftar: <strong><?= $count ?></strong> · Kuota: <strong><?= (int)$period['quota'] === 0 ? 'Tanpa batas' : (int)$period['quota'] ?></strong></p>
<?php if ($existing): ?>
<div class="alert alert-success">Anda sudah terdaftar sejak <?= $escape($existing['registered_at']) ?> WIB. Pendaftaran tersimpan; pembagian kelas diinformasikan terpisah.</div>
<?php elseif (!$error && $decision): ?>
<?php if (!$decision['allowed']): ?><div class="alert alert-info"><?= $escape(tutorialRplRegistrationReason($decision['reason'])) ?></div>
<?php elseif ((int)$period['quota'] > 0 && $count >= (int)$period['quota']): ?><div class="alert alert-warning">Kuota pendaftaran periode ini sudah penuh.</div>
<?php else: ?><p>Dengan mendaftar, akun Anda dicatat sebagai pendaftar periode ini. Penempatan kelas dilakukan terpisah.</p>
<form method="post" action="<?= BASE_URL ?>/tutorial-rpl-daftar.php"><input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>"><input type="hidden" name="period_id" value="<?= (int)$period['id'] ?>"><button class="btn btn-primary" type="submit">Daftar Tutorial RPL</button></form>
<?php endif; endif; ?>
<?php elseif (!$error): ?><p>Belum ada periode aktif. Silakan hubungi admin.</p><?php endif; ?>
</div></section>
<section class="card"><div class="card-header">Riwayat pendaftaran Anda</div><div class="card-body" style="overflow-x:auto"><table class="table"><thead><tr><th>Periode</th><th>Waktu (WIB)</th><th>Jalur</th></tr></thead><tbody>
<?php foreach ($history as $row): ?><tr><td><?= $escape($row['nama']) ?><br><?= $escape($row['gelombang']) ?></td><td><?= $escape($row['registered_at']) ?></td><td><?= $escape($row['channel']) ?></td></tr><?php endforeach; ?>
<?php if (!$history): ?><tr><td colspan="3">Belum ada pendaftaran tersimpan.</td></tr><?php endif; ?>
</tbody></table></div></section>
<a class="btn btn-secondary" href="<?= BASE_URL ?>/tutorial-rpl.php">Kembali ke informasi RPL</a>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
