<?php
/**
 * LPPAI Corner - Tryout CBT Pretes (Mahasiswa)
 *
 * - tryout.php                 : daftar tryout aktif + riwayat nilai
 * - tryout.php?sesi=ID         : ruang pengerjaan tryout (mesin CBT yang sama dengan ujian resmi)
 * - tryout.php?api=1 (POST)    : API JSON tryout (state/start/save/submit/interrupt/resume/retake)
 *
 * Attempt tryout terpisah dari ujian resmi (lihat includes/pretes-tryout.php), tanpa syarat
 * pendaftaran pretes dan tanpa verifikasi wajah.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pretes-tryout.php';

// ── API JSON ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $reply = static function (int $status, array $data): void {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (!isLoggedIn()) $reply(401, ['success' => false, 'message' => 'Sesi login berakhir. Silakan login kembali.']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $reply(405, ['success' => false, 'message' => 'Gunakan metode POST.']);
    $raw = file_get_contents('php://input', false, null, 0, 32769);
    if (strlen((string)$raw) > 32768) $reply(413, ['success' => false, 'message' => 'Permintaan terlalu besar.']);
    $input = json_decode((string)$raw, true);
    if (!is_array($input) || !is_string($input['csrf_token'] ?? null) || !verifyCsrf($input['csrf_token'])) {
        $reply(403, ['success' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman.']);
    }
    $action = $input['action'] ?? '';
    if (!in_array($action, ['state', 'start', 'save', 'submit', 'interrupt', 'resume'], true)) {
        $reply(400, ['success' => false, 'message' => 'Aksi tryout tidak valid.']);
    }
    $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT);
    $pdo = getDBConnection();
    $session = $sessionId ? pretesTryoutGetSession($pdo, (int)$sessionId) : null;
    if (!$session) $reply(404, ['success' => false, 'message' => 'Tryout tidak ditemukan.']);
    $userId = (int)getCurrentUser()['id'];

    try {
        $window = pretesTryoutWindow($session);
        if ($action === 'start' && !$window['open']) throw new RuntimeException($window['notice']);
        // fresh = halaman "Ulangi Tryout" (?baru=1): arahkan state/start ke percobaan berikutnya.
        // Attempt yang belum dikumpulkan selalu dilanjutkan lebih dulu.
        $fresh = !empty($input['fresh']) && in_array($action, ['state', 'start'], true);
        if ($fresh && !$window['open']) $fresh = false;
        $regId = pretesTryoutResolveRegId($pdo, $userId, $session, $fresh);
        $token = isset($input['token']) && is_string($input['token']) ? trim($input['token']) : null;
        if ($action === 'state') {
            $data = pretesCbtState($pdo, $userId, $regId);
        } elseif ($action === 'start') {
            $data = pretesCbtStart($pdo, $userId, $regId, null, $token, $session);
        } else {
            $data = pretesCbtChange($pdo, $userId, $regId, $action, $input);
        }
        $attempts = pretesTryoutAttempts($pdo, $userId, (int)$session['id']);
        $data['tryout'] = [
            'attempt_no'  => pretesTryoutDecodeRegId($regId)['attempt_no'],
            'used'        => count($attempts),
            'max'         => (int)$session['max_attempts'],
            'can_retake'  => $window['open'] && pretesTryoutCanRetake($session, $attempts),
        ];
        $reply(200, ['success' => true] + $data);
    } catch (InvalidArgumentException $e) {
        $reply(422, ['success' => false, 'message' => $e->getMessage()]);
    } catch (PDOException $e) {
        error_log('Tryout database error: ' . $e->getMessage());
        $reply(503, ['success' => false, 'message' => 'Penyimpanan tryout belum tersedia. Coba lagi; jawaban yang sudah tersimpan tetap aman.']);
    } catch (Throwable $e) {
        error_log('Tryout error: ' . $e->getMessage());
        $reply(409, ['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Tryout belum dapat diproses. Hubungi admin.']);
    }
}

// ── Halaman ─────────────────────────────────────────────────────────────────
define('PAGE_TITLE', 'Tryout CBT Pretes');
requireLogin();
$user = getCurrentUser();
$pdo  = getDBConnection();
$userId = (int)$user['id'];

$selectedId = filter_var($_GET['sesi'] ?? null, FILTER_VALIDATE_INT);
$isFresh = !empty($_GET['baru']);
$session = $selectedId ? pretesTryoutGetSession($pdo, (int)$selectedId) : null;
$sessions = $session ? [] : pretesTryoutActiveSessions($pdo);

$fmtTime = static fn($ts) => $ts ? date('d/m/Y H:i', (int)$ts) : '-';
$scoreText = static fn($a) => $a['submitted_at'] !== null ? number_format((float)$a['score'], 1) : 'Berlangsung';

include __DIR__ . '/../includes/header.php';
?>
<style>
.to-hero{background:linear-gradient(135deg,#1e3a8a 0%,#2563eb 100%);color:#fff;border-radius:18px;padding:22px 26px;margin-bottom:20px;box-shadow:0 12px 30px rgba(30,58,138,.18)}
.to-hero h2{margin:0 0 6px;font-size:22px;font-weight:800;color:#fff}
.to-hero p{margin:0;opacity:.88;font-size:13.5px;line-height:1.6}
.to-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.to-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px;box-shadow:0 4px 14px rgba(15,23,42,.04);display:flex;flex-direction:column;gap:10px}
.to-card h3{margin:0;font-size:16.5px;font-weight:800;color:#0f172a}
.to-meta{display:flex;flex-wrap:wrap;gap:6px}
.to-chip{font-size:11.5px;font-weight:700;padding:4px 9px;border-radius:999px;background:#eff6ff;color:#1e40af}
.to-chip.warn{background:#fef3c7;color:#92400e}
.to-chip.ok{background:#dcfce7;color:#166534}
.to-desc{font-size:13px;color:#475569;margin:0;line-height:1.55}
.to-empty{text-align:center;padding:42px 20px;background:#fff;border:1px dashed #cbd5e1;border-radius:16px;color:#475569}
.to-history{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;margin-top:18px}
.to-history h4{margin:0 0 10px;font-size:15px;font-weight:800;color:#0f172a}
.to-history table{width:100%;border-collapse:collapse;font-size:13px}
.to-history th,.to-history td{padding:8px 10px;border-bottom:1px solid #eef2f7;text-align:left}
.to-history th{font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b}
.to-retake{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px}
</style>

<?php if (!$session): ?>
    <div class="to-hero">
        <h2>📝 Tryout CBT Pretes</h2>
        <p>Latihan ujian dengan sistem yang sama seperti CBT pretes resmi: soal dan pilihan acak, waktu dihitung server, nilai keluar otomatis. Nilai tryout <strong>tidak memengaruhi</strong> nilai atau kelulusan pretes resmi.</p>
    </div>
    <?php if (!$sessions): ?>
        <div class="to-empty">
            <div style="font-size:40px">🗓️</div>
            <h3 style="margin:8px 0 6px">Belum Ada Tryout yang Dibuka</h3>
            <p style="margin:0">Tryout akan muncul di sini saat dibuka oleh admin LPPAI.</p>
        </div>
    <?php else: ?>
        <div class="to-grid">
        <?php foreach ($sessions as $ses):
            $window = pretesTryoutWindow($ses);
            $attempts = pretesTryoutAttempts($pdo, $userId, (int)$ses['id']);
            $latest = $attempts ? end($attempts) : null;
            $best = null;
            foreach ($attempts as $a) if ($a['submitted_at'] !== null && ($best === null || (float)$a['score'] > $best)) $best = (float)$a['score'];
            $max = (int)$ses['max_attempts'];
        ?>
            <div class="to-card">
                <h3><?= sanitize($ses['title']) ?></h3>
                <div class="to-meta">
                    <span class="to-chip">⏱️ <?= (int)$ses['duration_minutes'] ?> menit</span>
                    <span class="to-chip">🔁 <?= $max === 0 ? 'Bisa diulang' : ('Maks. ' . $max . '× pengerjaan') ?></span>
                    <?php if (!empty($ses['token_required'])): ?><span class="to-chip warn">🔑 Perlu token</span><?php endif; ?>
                    <?php if ($best !== null): ?><span class="to-chip ok">🏆 Terbaik <?= number_format($best, 1) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($ses['description'])): ?><p class="to-desc"><?= sanitize($ses['description']) ?></p><?php endif; ?>
                <?php if (!empty($ses['start_time']) || !empty($ses['end_time'])): ?>
                    <p class="to-desc">🟢 <?= !empty($ses['start_time']) ? sanitize(date('d/m/Y H:i', strtotime($ses['start_time']))) : 'Langsung' ?> &nbsp;·&nbsp; 🔴 <?= !empty($ses['end_time']) ? sanitize(date('d/m/Y H:i', strtotime($ses['end_time']))) : 'Tanpa batas' ?></p>
                <?php endif; ?>
                <p class="to-desc">Sudah dikerjakan: <strong><?= count($attempts) ?>×</strong><?= $latest && $latest['submitted_at'] === null ? ' · <span style="color:#b45309;font-weight:700">ada pengerjaan yang belum dikumpulkan</span>' : '' ?></p>
                <?php if ($window['open']): ?>
                    <a class="btn btn-primary" style="text-align:center" href="<?= BASE_URL ?>/tryout.php?sesi=<?= (int)$ses['id'] ?>">
                        <?= !$attempts ? '▶ Mulai Tryout' : ($latest['submitted_at'] === null ? '▶ Lanjutkan Tryout' : '📊 Lihat Hasil / Ulangi') ?>
                    </a>
                <?php else: ?>
                    <div class="to-chip warn" style="text-align:center"><?= sanitize($window['notice']) ?></div>
                    <?php if ($attempts): ?><a class="btn btn-secondary" style="text-align:center" href="<?= BASE_URL ?>/tryout.php?sesi=<?= (int)$ses['id'] ?>">📊 Lihat Hasil</a><?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else:
    $window = pretesTryoutWindow($session);
    $attempts = pretesTryoutAttempts($pdo, $userId, (int)$session['id']);
    $max = (int)$session['max_attempts'];
?>
    <div style="margin-bottom:14px"><a href="<?= BASE_URL ?>/tryout.php" class="btn btn-secondary btn-sm">← Daftar Tryout</a></div>
    <div class="to-hero">
        <h2>📝 <?= sanitize($session['title']) ?></h2>
        <p><?= (int)$session['duration_minutes'] ?> menit · <?= $max === 0 ? 'boleh diulang tanpa batas' : ('maksimal ' . $max . '× pengerjaan') ?> · nilai tryout tidak memengaruhi pretes resmi.<?= !$window['open'] ? ' <strong>' . sanitize($window['notice']) . '</strong>' : '' ?></p>
    </div>

    <?php if (!$window['open'] && !$attempts): ?>
        <div class="to-empty"><div style="font-size:40px">⏳</div><h3 style="margin:8px 0 6px">Tryout Belum Bisa Dikerjakan</h3><p style="margin:0"><?= sanitize($window['notice']) ?></p></div>
    <?php else: ?>
        <?php require __DIR__ . '/../includes/pretes-cbt-view.php'; ?>

        <div class="to-history" id="tryoutHistory">
            <h4>📈 Riwayat Tryout Anda</h4>
            <?php if (!$attempts): ?>
                <p style="margin:0;color:#64748b;font-size:13px">Belum ada pengerjaan. Klik <strong>Mulai Ujian</strong> di atas untuk memulai tryout.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Ke-</th><th>Mulai</th><th>Selesai</th><th>Benar</th><th>Nilai</th></tr></thead>
                    <tbody>
                    <?php foreach ($attempts as $a): ?>
                        <tr>
                            <td><?= (int)$a['attempt_no'] ?></td>
                            <td><?= $fmtTime($a['started_at']) ?></td>
                            <td><?= $fmtTime($a['submitted_at']) ?></td>
                            <td><?= $a['submitted_at'] !== null ? (int)$a['correct_count'] : '-' ?></td>
                            <td><strong><?= $scoreText($a) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <div class="to-retake" id="tryoutRetakeBox" style="<?= $window['open'] && pretesTryoutCanRetake($session, $attempts) ? '' : 'display:none' ?>">
                <a class="btn btn-primary" id="tryoutRetake" href="<?= BASE_URL ?>/tryout.php?sesi=<?= (int)$session['id'] ?>&amp;baru=1" onclick="return confirm('Mulai pengerjaan tryout baru? Soal dan pilihan jawaban akan diacak ulang.')">🔁 Ulangi Tryout (soal diacak ulang)</a>
                <span style="font-size:12.5px;color:#64748b"><?= $max === 0 ? 'Tanpa batas pengulangan.' : ('Sisa kesempatan: ' . max(0, $max - count($attempts)) . '×') ?></span>
            </div>
        </div>

        <script>window.TOKEN_REQUIRED = <?= !empty($session['token_required']) ? 'true' : 'false' ?>;</script>
        <script src="<?= BASE_URL ?>/pretes-ujian.php?cbt_asset=1&amp;v=<?= filemtime(__DIR__ . '/../assets/js/pretes-cbt.js') ?>"></script>
        <script>
        (function () {
            var API = <?= json_encode(BASE_URL . '/tryout.php?api=1') ?>;
            var CSRF = <?= json_encode(csrfToken()) ?>;
            var SESSION_ID = <?= (int)$session['id'] ?>;
            var FRESH = <?= $isFresh ? 'true' : 'false' ?>;
            var startedHere = false;
            // Sesuaikan judul ruang ujian untuk tryout
            var h3 = document.querySelector('#pretesCbt .cbt-heading h3');
            if (h3) h3.textContent = 'Tryout: ' + <?= json_encode($session['title'], JSON_UNESCAPED_UNICODE) ?>;
            var eyebrow = document.querySelector('#pretesCbt .cbt-eyebrow');
            if (eyebrow) eyebrow.textContent = 'LPPAI / Tryout CBT Pretes';
            var intro = document.querySelectorAll('#cbtIntro p');
            if (intro[0]) intro[0].textContent = 'Anda mendapat soal dan pilihan jawaban acak. Jika Anda meninggalkan halaman, ujian dijeda dan soal yang sedang terbuka dipindahkan serta pilihannya diacak ulang; jawaban soal lain tetap tersimpan.';
            if (intro[1]) intro[1].textContent = 'Gunakan nomor soal untuk berpindah dan tandai soal yang ingin diperiksa kembali. Setelah dikumpulkan, nilai langsung tampil. Tryout tidak memengaruhi nilai pretes resmi.';

            function request(payload) {
                return fetch(API, {
                    method: 'POST', credentials: 'same-origin', keepalive: payload.action === 'interrupt',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(Object.assign({ csrf_token: CSRF, session_id: SESSION_ID, fresh: FRESH }, payload))
                }).then(function (res) {
                    return res.json().catch(function () { throw new Error('Respons server tidak valid (HTTP ' + res.status + ').'); })
                        .then(function (data) {
                            if (!res.ok || !data.success) throw new Error(data.message || ('Permintaan gagal (HTTP ' + res.status + ').'));
                            var box = document.getElementById('tryoutRetakeBox');
                            if (box && data.tryout) box.style.display = (data.tryout.can_retake && !FRESH) ? '' : 'none';
                            return data;
                        });
                });
            }
            if (window.PretesCbt) {
                window.PretesCbt.init({
                    request: request,
                    readyMessage: 'Tekan Mulai Ujian ketika Anda siap mengerjakan tryout.',
                    onStarted: function () { startedHere = true; },
                    // Muat ulang hanya bila pengerjaan berlangsung di halaman ini (hindari loop saat melihat hasil lama)
                    onSubmitted: function () {
                        if (!startedHere) return;
                        setTimeout(function () { location.href = <?= json_encode(BASE_URL . '/tryout.php?sesi=' . (int)$session['id']) ?>; }, 1800);
                    }
                });
            }
        })();
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
