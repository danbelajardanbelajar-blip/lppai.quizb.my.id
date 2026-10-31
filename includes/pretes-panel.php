<?php
require_once __DIR__ . '/auth.php';
define('PAGE_TITLE', 'Penilaian & Absensi Pretes');
require_once __DIR__ . '/pretes-helper.php';
$user = getCurrentUser();
if (!in_array($user['role'] ?? '', ['admin', 'dosen'], true)) {
    http_response_code(403);
    exit('Akses ditolak.');
}
$pdo = getDBConnection();
ensurePretesFeatures($pdo);
$message = '';
$msgType = 'success';
$periods = $pdo->query("SELECT * FROM master_pretes ORDER BY (status = 'aktif') DESC, created_at DESC")->fetchAll();
$periodId = filter_var($_GET['pretes_id'] ?? ($periods[0]['id'] ?? 0), FILTER_VALIDATE_INT);
$period = null;
foreach ($periods as $item) if ((int)$item['id'] === (int)$periodId) $period = $item;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) throw new InvalidArgumentException('Sesi tidak valid. Muat ulang halaman.');
        if (!$period) throw new InvalidArgumentException('Periode tidak ditemukan.');
        $action = $_POST['action'] ?? '';
        $pdo->beginTransaction();
        if ($action === 'save_scores') {
            $regId = filter_var($_POST['registration_id'] ?? null, FILTER_VALIDATE_INT);
            $stmt = $pdo->prepare('SELECT * FROM pretes_registrations WHERE id = ? AND periode = ? FOR UPDATE');
            $stmt->execute([$regId ?: 0, $period['periode']]);
            $registration = $stmt->fetch();
            if (!$registration || !pretesCanAssess($user, $registration)) throw new InvalidArgumentException('Peserta bukan penugasan Anda atau sudah dipindahkan.');
            $scores = pretesValidateScores($_POST['scores'] ?? null);
            $stmt = $pdo->prepare('INSERT INTO pretes_assessments (registration_id, scores_json, assessed_by) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE scores_json = VALUES(scores_json), assessed_by = VALUES(assessed_by), updated_at = CURRENT_TIMESTAMP');
            $stmt->execute([$regId, json_encode($scores), $user['id']]);
            $message = 'Nilai rinci berhasil disimpan.';
        } elseif ($action === 'open_attendance' || $action === 'close_attendance') {
            $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            $stmt = $pdo->prepare('SELECT * FROM pretes_attendance_sessions WHERE id = ? AND periode = ? FOR UPDATE');
            $stmt->execute([$sessionId ?: 0, $period['periode']]);
            $session = $stmt->fetch();
            if (!$session || !pretesCanAssess($user, ['dosen_penguji' => $session['penguji']])) throw new InvalidArgumentException('Sesi bukan penugasan Anda.');
            // Periksa kembali bahwa penugasan sesi belum berubah.
            $stmt = $pdo->prepare('SELECT * FROM pretes_registrations WHERE periode = ? AND TRIM(dosen_penguji) = ?');
            $stmt->execute([$period['periode'], $session['penguji']]);
            $valid = false;
            foreach ($stmt->fetchAll() as $row) if (pretesScopeHash(pretesScope($row)) === $session['scope_hash']) $valid = true;
            if (!$valid) throw new InvalidArgumentException('Sesi tidak lagi memiliki peserta. Muat ulang halaman.');
            $until = $action === 'open_attendance' ? 'DATE_ADD(NOW(), INTERVAL 2 HOUR)' : 'NULL';
            $pdo->prepare("UPDATE pretes_attendance_sessions SET open_until = $until WHERE id = ?")->execute([$session['id']]);
            $message = $action === 'open_attendance' ? 'Absensi dibuka selama 2 jam. Anda dapat menutupnya lebih awal.' : 'Absensi ditutup.';
        } else {
            throw new InvalidArgumentException('Tindakan tidak valid.');
        }
        $pdo->commit();
        $_SESSION['pretes_flash'] = $message;
        header('Location: ?' . http_build_query(['pretes_id' => $periodId, 'registration_id' => $_POST['registration_id'] ?? '']));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Gagal menyimpan. Silakan coba kembali.';
        if (!($e instanceof InvalidArgumentException)) error_log('Pretes panel: ' . $e->getMessage());
        $msgType = 'danger';
    }
}
if (!$message && isset($_SESSION['pretes_flash'])) { $message = $_SESSION['pretes_flash']; unset($_SESSION['pretes_flash']); }
$participants = [];
if ($period) {
    $sql = 'SELECT pr.*, u.nama_lengkap, u.nim, u.program_studi, pa.scores_json, pa.updated_at AS assessed_at,
        ar.attended_at FROM pretes_registrations pr JOIN users u ON u.id = pr.user_id
        LEFT JOIN pretes_assessments pa ON pa.registration_id = pr.id
        LEFT JOIN pretes_attendance_records ar ON ar.registration_id = pr.id WHERE pr.periode = ?';
    $params = [$period['periode']];
    if ($user['role'] !== 'admin') { $sql .= ' AND TRIM(pr.dosen_penguji) = ?'; $params[] = trim($user['nama_lengkap']); }
    $stmt = $pdo->prepare($sql . ' ORDER BY pr.hari_pilihan, pr.ruangan, u.nama_lengkap, pr.id');
    $stmt->execute($params);
    // Pemeriksaan persis juga diterapkan pada pembacaan, bukan hanya penyimpanan.
    $participants = array_values(array_filter($stmt->fetchAll(), fn($row) => pretesCanAssess($user, $row)));
}
$groups = [];
$selected = null;
$selectedId = filter_var($_GET['registration_id'] ?? null, FILTER_VALIDATE_INT);
foreach ($participants as $row) {
    $scope = pretesScope($row);
    $hash = pretesScopeHash($scope);
    if (!isset($groups[$hash])) $groups[$hash] = ['scope' => $scope, 'count' => 0];
    $groups[$hash]['count']++;
    if ((int)$row['id'] === $selectedId) $selected = $row;
}
$components = pretesComponents();
include __DIR__ . '/header.php';
?>
<?php if ($message): ?><div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div><?php endif; ?>
<div class="card"><div class="card-header">Pretes · Penilaian & Absensi</div><div class="card-body">
    <form method="GET" data-no-spa style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
        <div class="form-group" style="min-width:240px;margin:0;"><label for="pretes-period">Periode pretes</label><select id="pretes-period" name="pretes_id">
        <?php foreach ($periods as $item): ?><option value="<?= (int)$item['id'] ?>" <?= (int)$item['id'] === (int)$periodId ? 'selected' : '' ?>><?= sanitize($item['periode']) ?></option><?php endforeach; ?>
        </select></div><button class="btn btn-primary" style="width:auto;">Tampilkan</button>
    </form>
    <p style="margin-bottom:0;">Jumlah peserta: <strong><?= count($participants) ?></strong> · Waktu: <strong>13.00 WIB</strong>. Nilai rinci disimpan sebagai penilaian; penetapan hasil dan kelulusan tetap dilakukan admin.</p>
</div></div>
<?php if (!$participants): ?><div class="alert alert-info">Belum ada peserta yang ditugaskan pada periode ini.</div><?php endif; ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;">
<?php foreach ($groups as $group): $scope = $group['scope']; $session = pretesSession($pdo, $scope); $url = pretesAttendanceUrl($session); ?>
<div class="card"><div class="card-header">QR Absensi · <?= sanitize($scope['hari']) ?></div><div class="card-body" style="text-align:center;">
    <p><strong><?= sanitize($scope['penguji'] ?: 'Belum ditentukan') ?></strong><br><?= sanitize($scope['ruangan'] ?: 'Ruangan belum ditentukan') ?> · <?= $group['count'] ?> peserta</p>
    <div class="pretes-qr" data-url="<?= sanitize($url) ?>" style="display:inline-block;padding:12px;background:white;"></div>
    <p><strong><?= $session['is_open'] ? 'Absensi dibuka (otomatis ditutup 2 jam setelah dibuka)' : 'Absensi ditutup' ?></strong></p>
    <p>Mahasiswa scan dengan kamera ponsel, login, lalu konfirmasi kehadiran. QR yang sama tersedia pada lembar cetak admin.</p>
    <form method="POST" data-no-spa>
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
        <button name="action" value="<?= $session['is_open'] ? 'close_attendance' : 'open_attendance' ?>" class="btn <?= $session['is_open'] ? 'btn-danger' : 'btn-success' ?>" style="width:auto;"><?= $session['is_open'] ? 'Tutup Absensi' : 'Buka Absensi (2 Jam)' ?></button>
    </form>
</div></div>
<?php endforeach; ?>
</div>
<?php if ($selected):
    $scores = json_decode($selected['scores_json'] ?? '{}', true) ?: [];
    if ($msgType === 'danger' && ($_POST['action'] ?? '') === 'save_scores' && (int)($_POST['registration_id'] ?? 0) === (int)$selected['id'] && is_array($_POST['scores'] ?? null)) $scores = array_filter($_POST['scores'], 'is_scalar');
?>
<div class="card" id="nilai-pretes"><div class="card-header">Input Nilai · <?= sanitize($selected['nama_lengkap']) ?> (<?= sanitize($selected['nim']) ?>)</div><div class="card-body">
    <p>Rentang 0–100, maksimal dua angka desimal. Kosongkan komponen yang belum dinilai. Nilai 0 tetap dihitung sebagai nilai yang sudah diisi.</p>
    <style>
        .pretes-score-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr)); gap:16px 24px; }
        .pretes-score-row { display:flex; align-items:center; gap:5px; }
        .pretes-score-row input { flex:0 0 62px; width:62px; min-width:0; height:44px; padding:8px 4px; text-align:center; }
        .pretes-score-row .score-preset { flex:1; min-width:0; height:44px; padding:0 2px; border:1px solid #c5dfcf; border-radius:8px; background:#edf6f0; color:#215d3b; font-size:13px; font-weight:700; cursor:pointer; }
        .pretes-score-row .score-preset:hover { background:#d8ecdf; }
        .pretes-score-row .score-preset[aria-pressed="true"] { background:#246b43; color:white; border-color:#246b43; }
        .pretes-score-row .score-preset:focus-visible { outline:2px solid #246b43; outline-offset:2px; }
        @media (max-width:360px) { .pretes-score-row { gap:4px; } .pretes-score-row input { flex-basis:54px; width:54px; } .pretes-score-row .score-preset { font-size:12px; } }
    </style>
    <form method="POST" id="pretes-score-form" data-no-spa>
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="save_scores"><input type="hidden" name="registration_id" value="<?= (int)$selected['id'] ?>">
        <div class="pretes-score-grid">
        <?php $no = 0; foreach ($components as $key => $label): ?>
            <div class="form-group"><label for="nilai-<?= $key ?>"><?= ++$no ?>. <?= sanitize($label) ?></label>
                <div class="pretes-score-row">
                    <input id="nilai-<?= $key ?>" name="scores[<?= $key ?>]" type="number" min="0" max="100" step="0.01" value="<?= sanitize((string)($scores[$key] ?? '')) ?>" placeholder="—">
                    <?php foreach ([60, 70, 80, 90, 100] as $preset): ?>
                    <button type="button" class="score-preset" data-score="<?= $preset ?>" aria-pressed="false" aria-label="Beri nilai <?= $preset ?> untuk <?= sanitize($label) ?>"><?= $preset ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?></div>
        <button class="btn btn-primary" style="width:auto;">Simpan 12 Komponen Nilai</button>
    </form>
</div></div>
<?php endif; ?>
<?php if ($participants): ?>
<style>
    .pretes-participants { width:100%; table-layout:fixed; }
    .pretes-participants .participant-name { font-weight:600; white-space:normal; overflow-wrap:anywhere; }
    .pretes-participants .participant-action { width:132px; text-align:right; }
    .pretes-participants .input-score { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:10px 16px; width:auto; white-space:nowrap; border-radius:10px; }
    @media (max-width:600px) {
        .pretes-participants th, .pretes-participants td { padding:12px 8px; }
        .pretes-participants .participant-action { width:112px; }
        .pretes-participants .input-score { padding:10px 12px; font-size:13px; }
        .pretes-participants .participant-name { font-size:14px; line-height:1.5; }
    }
</style>
<div class="card"><div class="card-header">Peserta & Nilai Pretes</div><div class="card-body"><table class="pretes-participants">
    <thead><tr><th>Nama Mahasiswa</th><th class="participant-action">Input Nilai</th></tr></thead><tbody>
    <?php foreach ($participants as $row): ?>
    <tr><td class="participant-name"><?= sanitize($row['nama_lengkap']) ?></td><td class="participant-action"><a class="btn btn-primary input-score" aria-label="Input nilai <?= sanitize($row['nama_lengkap']) ?>" href="?<?= sanitize(http_build_query(['pretes_id' => $periodId, 'registration_id' => $row['id']])) ?>#nilai-pretes">Input Nilai</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
<?php endif; ?>
<script src="<?= BASE_URL ?>/assets/js/lib/qrcode.min.js"></script>
<script>
document.querySelectorAll('.pretes-score-row').forEach(function(row) {
    var input = row.querySelector('input');
    function updatePresets() {
        row.querySelectorAll('.score-preset').forEach(function(button) {
            button.setAttribute('aria-pressed', input.value !== '' && Number(input.value) === Number(button.dataset.score) ? 'true' : 'false');
        });
    }
    row.querySelectorAll('.score-preset').forEach(function(button) {
        button.addEventListener('click', function() {
            input.value = button.dataset.score;
            input.dispatchEvent(new Event('input', {bubbles:true}));
        });
    });
    input.addEventListener('input', updatePresets);
    updatePresets();
});
document.querySelectorAll('.pretes-qr').forEach(function(el) {
    new QRCode(el, {text:el.dataset.url,width:200,height:200,correctLevel:QRCode.CorrectLevel.M});
});
</script>
<?php include __DIR__ . '/footer.php'; ?>
