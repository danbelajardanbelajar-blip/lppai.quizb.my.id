<?php
define('PAGE_TITLE', 'Daftar Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/tutorial-rpl-registration.php';
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$error = ''; $period = null; $existing = null; $decision = null; $history = []; $count = 0; $days = [];
$actor = getCurrentUser();
try {
    $pdo = getDBConnection();
    ensureTutorialRplRegistrationTable($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) {
                http_response_code(403);
                throw new DomainException('Sesi formulir kedaluwarsa. Muat ulang halaman.');
            }
            $periodId = filter_var($_POST['period_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if (!$periodId) throw new DomainException('Periode tidak valid. Muat ulang halaman.');
            $day = $_POST['hari_pilihan'] ?? null;
            if (!is_string($day) || !in_array($day, TUTORIAL_RPL_DAYS, true)) {
                throw new DomainException('Pilih hari tutorial yang tersedia terlebih dahulu.');
            }
            $result = tutorialRplRegister($pdo, $periodId, (int)$actor['id'], $actor, 'mandiri', null, $day);
            $_SESSION['rpl_registration_notice'] = !empty($result['day_selected'])
                ? 'Pendaftaran dan pilihan hari Tutorial RPL berhasil disimpan. Anda menunggu pembagian kelas.'
                : 'Anda sudah terdaftar pada periode ini. Tidak dibuat pendaftaran ganda.';
            header('Location: ' . BASE_URL . '/tutorial-rpl-daftar.php');
            exit;
        } catch (DomainException $e) {
            $error = $e->getMessage();
        }
    }
    $period = tutorialRplActivePeriod($pdo);
    $stmt = $pdo->prepare('SELECT id, role, jenis_program FROM users WHERE id=?');
    $stmt->execute([(int)$actor['id']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (($student['role'] ?? '') !== 'mahasiswa' || strtoupper(trim((string)($student['jenis_program'] ?? ''))) !== 'RPL') {
        http_response_code(403);
        throw new DomainException('Halaman pendaftaran ini khusus mahasiswa RPL.');
    }
    $stmt = $pdo->prepare('SELECT r.*, p.nama, p.gelombang FROM tutorial_rpl_registrations r JOIN tutorial_rpl_registration_periods p ON p.id=r.period_id WHERE r.user_id=? ORDER BY r.id DESC LIMIT 10');
    $stmt->execute([(int)$actor['id']]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($period) {
        $existing = tutorialRplRegistrationFor($pdo, (int)$period['id'], (int)$actor['id']);
        $count = tutorialRplRegistrationCount($pdo, (int)$period['id']);
        $days = tutorialRplDayAvailability($pdo, (int)$period['id']);
        if (!$existing && (int)$period['quota'] > 0) {
            foreach ($days as &$capacity) {
                $capacity['remaining'] = min($capacity['remaining'], max(0, (int)$period['quota'] - $count));
            }
            unset($capacity);
        }
        $decision = tutorialRplRegistrationDecision($student, $student, 'mandiri', tutorialRplPretesEvidence($pdo, (int)$student['id']), null, tutorialRplPeriodPolicy($period));
    }
} catch (DomainException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('RPL self registration: ' . $e->getMessage());
    $error = 'Data pendaftaran atau hasil pretes belum dapat diperiksa. Coba kembali atau hubungi admin.';
}
$notice = $_SESSION['rpl_registration_notice'] ?? '';
unset($_SESSION['rpl_registration_notice']);
include __DIR__ . '/../includes/header.php';
?>
<div class="tutorial-rpl-container" style="max-width:960px;margin:0 auto;">
    <div style="background:linear-gradient(135deg,var(--primary,#0b3b22),var(--primary-light,#156138));color:#fff;border-radius:18px;padding:26px;margin-bottom:20px;box-shadow:0 12px 30px rgba(11,59,34,.16);">
        <h2 style="color:#fff;margin:0 0 8px;font-size:22px;font-weight:800;">Daftar Tutorial RPL</h2>
        <p style="margin:0;font-size:14px;opacity:.95;">Pendaftaran mandiri untuk mahasiswa RPL yang belum pretes atau belum lulus.</p>
    </div>

    <?php if ($notice): ?>
        <div class="alert alert-success" role="status"><?= $escape($notice) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><?= $escape($error) ?></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:20px;">
        <div class="card-header">
            <span>Periode Pendaftaran</span>
        </div>
        <div class="card-body">
            <?php if ($period): ?>
                <h3 style="margin-top:0;font-size:18px;font-weight:700;color:var(--text-dark);"><?= $escape($period['nama']) ?> · <?= $escape($period['gelombang']) ?></h3>
                <p><?= $escape(tutorialRplPeriodSchedule($period)) ?></p>
                <p>Terdaftar: <strong><?= $count ?></strong> · Kuota: <strong><?= (int)$period['quota'] === 0 ? 'Mengikuti kuota dosen' : (int)$period['quota'] ?></strong></p>

                <?php if ($existing): ?>
                    <div class="alert alert-success">Anda sudah terdaftar sejak <?= $escape($existing['registered_at']) ?> WIB. Hari pilihan: <strong><?= $escape($existing['hari_pilihan'] ?? 'Belum dipilih (pendaftaran lama)') ?></strong>. Pembagian kelas diinformasikan terpisah.</div>
                <?php endif; ?>

                <?php if ((!$existing || $existing['schedule_id'] === null) && !$error && $decision): ?>
                    <?php if (!$decision['allowed']): ?>
                        <div class="alert alert-info"><?= $escape(tutorialRplRegistrationReason($decision['reason'])) ?></div>
                    <?php elseif (!$existing && (int)$period['quota'] > 0 && $count >= (int)$period['quota']): ?>
                        <div class="alert alert-warning">Kuota pendaftaran periode ini sudah penuh.</div>
                    <?php elseif (!array_filter($days, static fn($d) => $d['remaining'] > 0)): ?>
                        <div class="alert alert-warning">Pilihan hari belum tersedia atau seluruh kuota dosen sudah penuh. Silakan hubungi admin.</div>
                    <?php else: ?>
                        <p>Pilih hari yang sesuai, lalu klik Daftar. Penempatan kelas dilakukan terpisah.</p>
                        <form method="post" action="<?= BASE_URL ?>/tutorial-rpl-daftar.php">
                            <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                            <input type="hidden" name="period_id" value="<?= (int)$period['id'] ?>">
                            <div class="form-group" style="margin-bottom:14px;">
                                <label for="hari_pilihan" style="display:block;margin-bottom:6px;font-weight:600;">Hari tutorial</label>
                                <select class="form-control" id="hari_pilihan" name="hari_pilihan" required style="max-width:400px;">
                                    <option value="">Pilih hari</option>
                                    <?php foreach ($days as $day => $capacity): ?>
                                        <option value="<?= $escape($day) ?>" <?= $capacity['remaining'] < 1 ? 'disabled' : '' ?>>
                                            <?= $escape($day) ?> · <?= $capacity['remaining'] > 0 ? 'Sisa ' . $capacity['remaining'] . ' tempat' : 'Penuh' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button class="btn btn-primary" type="submit" style="margin-top:10px;"><?= $existing ? 'Simpan pilihan hari' : 'Daftar Tutorial RPL' ?></button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            <?php elseif (!$error): ?>
                <p>Belum ada periode aktif. Silakan hubungi admin.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" style="margin-bottom:20px;">
        <div class="card-header">
            <span>Riwayat Pendaftaran Anda</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table" style="margin:0;width:100%;">
                    <thead>
                        <tr>
                            <th>Periode</th>
                            <th>Waktu (WIB)</th>
                            <th>Hari</th>
                            <th>Jalur</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $row): ?>
                            <tr>
                                <td><?= $escape($row['nama']) ?><br><small style="color:var(--text-muted, #64748b);"><?= $escape($row['gelombang']) ?></small></td>
                                <td><?= $escape($row['registered_at']) ?></td>
                                <td><?= $escape($row['hari_pilihan'] ?? 'Belum dipilih') ?></td>
                                <td><span class="badge badge-info" style="font-size:11.5px;"><?= $escape($row['channel']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$history): ?>
                            <tr>
                                <td colspan="4" style="text-align:center;padding:24px;color:var(--text-muted, #64748b);">Belum ada pendaftaran tersimpan.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div style="margin-top:16px;">
        <a class="btn btn-secondary" href="<?= BASE_URL ?>/tutorial-rpl.php">Kembali ke informasi RPL</a>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
