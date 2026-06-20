<?php
/**
 * LPPAI Corner - Peserta & Jadwal Pretes (Mahasiswa)
 * Menampilkan jadwal pretes, daftar peserta, dan credentials tes tulis pribadi.
 */

// ── Handler AJAX: verifikasi password (harus di paling atas, sebelum HTML) ──
if (isset($_POST['action']) && $_POST['action'] === 'verify_password') {
    require_once __DIR__ . '/../includes/auth.php';
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $ok  = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    $tok = $_POST['csrf_token'] ?? '';
    $validTok = isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $tok);

    if (!$ok)       { echo json_encode(['ok'=>false,'message'=>'Sesi habis. Muat ulang halaman.']); exit; }
    if (!$validTok) { echo json_encode(['ok'=>false,'message'=>'Token tidak valid. Muat ulang halaman.']); exit; }

    $pw = $_POST['password'] ?? '';
    if ($pw === '') { echo json_encode(['ok'=>false,'message'=>'Password tidak boleh kosong.']); exit; }

    try {
        $pdo2 = getDBConnection();
        $s = $pdo2->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $s->execute([$_SESSION['user_id']]);
        $u = $s->fetch();
        if ($u && password_verify($pw, $u['password'])) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok'=>false,'message'=>'Password salah. Coba lagi.']);
        }
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'message'=>'Kesalahan server.']);
    }
    exit;
}

define('PAGE_TITLE', 'Peserta & Jadwal Pretes');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user = getCurrentUser();
$pdo  = getDBConnection();

// ── Ambil credentials & registrasi mahasiswa ini ──────────────────────────────
$stmtMyReg = $pdo->prepare("
    SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan as ruangan_tes
    FROM pretes_registrations pr
    LEFT JOIN pretes_schedules ps ON pr.periode = ps.periode AND ps.status = 'aktif'
    WHERE pr.user_id = ?
    ORDER BY pr.tanggal_daftar DESC
    LIMIT 1
");
$stmtMyReg->execute([$user['id']]);
$myReg = $stmtMyReg->fetch();

// ── Ambil semua jadwal pretes ──────────────────────────────────────────────────
$schedules = $pdo->query(
    "SELECT * FROM pretes_schedules WHERE (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%') ORDER BY tanggal ASC, waktu_mulai ASC"
)->fetchAll();

// ── Ambil daftar semua peserta (nama + nim saja, untuk informasi publik) ────────
$participants = $pdo->query("
    SELECT pr.id, pr.periode, pr.tanggal_daftar, pr.status,
           u.nama_lengkap, u.nim, u.program_studi
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    ORDER BY u.nama_lengkap ASC
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- ═══════════════════════════════════════════════════════════════════
     PANEL CREDENTIALS TES TULIS — ditampilkan paling atas & menonjol
═══════════════════════════════════════════════════════════════════ -->
<?php if ($myReg): ?>

    <?php if (!empty($myReg['username_tes']) && !empty($myReg['password_tes'])): ?>
    <!-- Credentials sudah tersedia -->
    <div class="card" style="border:2px solid #1a73e8;margin-bottom:28px;position:relative;overflow:hidden;">
        <!-- Dekorasi background -->
        <div style="position:absolute;top:-30px;right:-30px;width:120px;height:120px;
                    background:rgba(26,115,232,0.08);border-radius:50%;pointer-events:none;"></div>
        <div style="position:absolute;bottom:-20px;right:60px;width:80px;height:80px;
                    background:rgba(26,115,232,0.05);border-radius:50%;pointer-events:none;"></div>

        <div class="card-header" style="background:#1a73e8;color:#fff;font-weight:700;font-size:15px;">
            🔑 Credentials Tes Tulis Pretes Anda
        </div>
        <div class="card-body">
            <!-- Hidden: password ter-encode base64, hanya ditampilkan setelah verifikasi -->
            <input type="hidden" id="__enc_pass" value="<?= base64_encode($myReg['password_tes'] ?? '') ?>">

            <div class="alert alert-info" style="margin-bottom:20px;">
                ⚠️ Gunakan username dan password di bawah ini untuk mengakses soal tes tulis pretes. Jangan bagikan ke orang lain.
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;margin-bottom:8px;">
                <!-- Username -->
                <div style="background:#e8f0fe;border-radius:14px;padding:20px 24px;text-align:center;">
                    <div style="color:#1a73e8;font-size:12px;font-weight:700;letter-spacing:1px;margin-bottom:8px;">USERNAME TES TULIS</div>
                    <div id="username-display"
                         style="font-family:monospace;font-size:22px;font-weight:800;color:#1565c0;
                                letter-spacing:2px;word-break:break-all;">
                        <?= sanitize($myReg['username_tes']) ?>
                    </div>
                    <button onclick="copyText('<?= sanitize($myReg['username_tes']) ?>', 'btn-copy-user')"
                            id="btn-copy-user"
                            style="margin-top:12px;background:#1a73e8;color:#fff;border:none;border-radius:8px;
                                   padding:6px 18px;cursor:pointer;font-size:13px;transition:all .2s;">
                        📋 Salin Username
                    </button>
                </div>

                <!-- Password -->
                <div style="background:#fff3e0;border-radius:14px;padding:20px 24px;text-align:center;">
                    <div style="color:#e65100;font-size:12px;font-weight:700;letter-spacing:1px;margin-bottom:8px;">PASSWORD TES TULIS</div>
                    <div id="password-display"
                         style="font-family:monospace;font-size:22px;font-weight:800;color:#bf360c;
                                letter-spacing:6px;">
                        ••••••
                    </div>
                    <div style="display:flex;gap:8px;justify-content:center;margin-top:12px;flex-wrap:wrap;">
                        <button onclick="askAndShowPassword()"
                                id="btn-toggle-pass"
                                style="background:#e65100;color:#fff;border:none;border-radius:8px;
                                       padding:6px 14px;cursor:pointer;font-size:13px;">
                            👁️ Tampilkan
                        </button>
                        <button onclick="copyPasswordIfVisible('btn-copy-pass')"
                                id="btn-copy-pass"
                                style="background:#bf360c;color:#fff;border:none;border-radius:8px;
                                       padding:6px 14px;cursor:pointer;font-size:13px;">
                            📋 Salin
                        </button>
                    </div>
                </div>
            </div>

            <!-- Info jadwal tes jika ada -->
            <?php if ($myReg['tanggal']): ?>
            <div style="margin-top:16px;padding:14px 18px;background:#f8f9fa;border-radius:10px;
                        display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <span style="font-size:28px;">📅</span>
                <div>
                    <strong>Jadwal Tes Anda:</strong>
                    <?= date('l, d M Y', strtotime($myReg['tanggal'])) ?>
                    | <?= date('H:i', strtotime($myReg['waktu_mulai'])) ?> -
                      <?= date('H:i', strtotime($myReg['waktu_selesai'])) ?>
                    | <?= sanitize($myReg['ruangan_tes'] ?? '-') ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php else: ?>
    <!-- Terdaftar tapi credentials belum dikeluarkan -->
    <div class="card" style="border:2px dashed #e0e0e0;margin-bottom:28px;">
        <div class="card-header" style="background:#f5f5f5;color:#555;">
            🔑 Credentials Tes Tulis Pretes
        </div>
        <div class="card-body">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <div style="font-size:40px;">⏳</div>
                <div>
                    <h3 style="margin:0 0 6px;color:#555;">Credentials Belum Tersedia</h3>
                    <p style="color:var(--text-muted);margin:0;">
                        Anda sudah terdaftar pretes (periode: <strong><?= sanitize($myReg['periode']) ?></strong>).
                        Username dan password untuk tes tulis akan diberikan oleh TU menjelang hari pelaksanaan pretes.
                        Silakan cek kembali halaman ini secara berkala.
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

<!-- ── Jadwal Pretes ─────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">📅 Jadwal Pretes</div>
    <div class="card-body">
        <?php if (empty($schedules)): ?>
            <div class="empty-state">
                <div class="icon">📅</div>
                <h3>Jadwal belum tersedia</h3>
                <p>Jadwal pretes akan ditampilkan ketika TU sudah menginputkan jadwal.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Periode</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Ruangan</th>
                        <th>Kuota</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $i => $s): ?>
                    <tr <?= ($myReg && $myReg['periode'] === $s['periode']) ? 'style="background:#e8f0fe;"' : '' ?>>
                        <td><?= $i + 1 ?></td>
                        <td><?= sanitize($s['periode']) ?></td>
                        <td><?= date('d M Y', strtotime($s['tanggal'])) ?></td>
                        <td><?= date('H:i', strtotime($s['waktu_mulai'])) ?> - <?= date('H:i', strtotime($s['waktu_selesai'])) ?></td>
                        <td><?= sanitize($s['ruangan']) ?></td>
                        <td><?= $s['terisi'] ?>/<?= $s['kuota'] ?></td>
                        <td>
                            <?php
                            $statusMap = ['aktif' => 'badge-success', 'selesai' => 'badge-info', 'dibatalkan' => 'badge-danger'];
                            ?>
                            <span class="badge <?= $statusMap[$s['status']] ?? 'badge-info' ?>">
                                <?= ucfirst($s['status']) ?>
                            </span>
                            <?php if ($myReg && $myReg['periode'] === $s['periode']): ?>
                                <span class="badge badge-primary" style="margin-left:4px;">← Jadwal Anda</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Daftar Peserta (Publik) ───────────────────────────────────── -->
<div class="card">
    <div class="card-header">👥 Daftar Peserta Pretes (<?= count($participants) ?> peserta)</div>
    <div class="card-body">
        <?php if (empty($participants)): ?>
            <div class="empty-state">
                <div class="icon">👥</div>
                <h3>Belum ada peserta terdaftar</h3>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>NIM</th>
                        <th>Program Studi</th>
                        <th>Periode</th>
                        <th>Tgl Daftar</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($participants as $i => $p): ?>
                    <tr <?= ($p['nim'] === $user['nim']) ? 'style="background:#e8f0fe;font-weight:600;"' : '' ?>>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <?= sanitize($p['nama_lengkap']) ?>
                            <?php if ($p['nim'] === $user['nim']): ?>
                                <span class="badge badge-primary" style="font-size:10px;margin-left:4px;">Anda</span>
                            <?php endif; ?>
                        </td>
                        <td><?= sanitize($p['nim']) ?></td>
                        <td><?= sanitize($p['program_studi']) ?></td>
                        <td><?= sanitize($p['periode']) ?></td>
                        <td><?= date('d M Y', strtotime($p['tanggal_daftar'])) ?></td>
                        <td>
                            <?php
                            $statusBadge = ['terdaftar' => 'badge-info', 'hadir' => 'badge-success', 'tidak_hadir' => 'badge-danger'];
                            ?>
                            <span class="badge <?= $statusBadge[$p['status']] ?? 'badge-info' ?>">
                                <?= ucfirst(str_replace('_', ' ', $p['status'])) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Modal Konfirmasi Password ─────────────────────────── -->
<div id="modal-verify-pass"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;
            align-items:center;justify-content:center;backdrop-filter:blur(3px);">
    <div style="background:#fff;border-radius:16px;width:min(420px,92vw);box-shadow:0 20px 60px rgba(0,0,0,.25);
                animation:modalIn .2s ease;overflow:hidden;">
        <div style="background:#e65100;color:#fff;padding:18px 24px;
                    font-weight:700;font-size:16px;">🔐 Konfirmasi Password</div>
        <div style="padding:24px;">
            <p style="margin:0 0 16px;color:#555;font-size:14px;">
                Masukkan <strong>password akun</strong> Anda untuk melihat password tes tulis.
            </p>
            <div style="margin-bottom:12px;">
                <input type="password" id="verify-pass-input"
                    placeholder="Password akun Anda..."
                    style="width:100%;box-sizing:border-box;padding:11px 14px;
                           border:2px solid #e0e0e0;border-radius:10px;font-size:14px;
                           font-family:inherit;"
                    onkeydown="if(event.key==='Enter'){submitVerify();}"
                    autocomplete="current-password">
            </div>
            <div id="verify-pass-error"
                 style="display:none;color:#ef4444;font-size:13px;margin-bottom:12px;"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button onclick="closeVerifyModal()"
                    style="background:#f3f4f6;color:#555;border:none;border-radius:8px;
                           padding:8px 18px;cursor:pointer;font-size:13px;font-family:inherit;">
                    Batal
                </button>
                <button onclick="submitVerify()" id="verify-pass-btn"
                    style="background:#e65100;color:#fff;border:none;border-radius:8px;
                           padding:8px 20px;cursor:pointer;font-size:13px;font-weight:600;
                           font-family:inherit;">
                    🔓 Konfirmasi
                </button>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes modalIn {
    from { opacity:0; transform:translateY(-14px) scale(.97); }
    to   { opacity:1; transform:translateY(0) scale(1); }
}
</style>

<script>
var _revealedPassword = null; // disimpan setelah verifikasi berhasil

function askAndShowPassword() {
    const display = document.getElementById('password-display');
    // Jika sudah terlihat, sembunyikan kembali
    if (_revealedPassword !== null && display.textContent.trim() !== '••••••') {
        display.textContent = '••••••';
        display.style.letterSpacing = '6px';
        document.getElementById('btn-toggle-pass').textContent = '👁️ Tampilkan';
        return;
    }
    // Buka modal
    document.getElementById('verify-pass-input').value = '';
    document.getElementById('verify-pass-error').style.display = 'none';
    document.getElementById('modal-verify-pass').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('verify-pass-input').focus(), 100);
}

function closeVerifyModal() {
    document.getElementById('modal-verify-pass').style.display = 'none';
    document.body.style.overflow = '';
}

function submitVerify() {
    const pw  = document.getElementById('verify-pass-input').value;
    const btn = document.getElementById('verify-pass-btn');
    const err = document.getElementById('verify-pass-error');

    if (!pw) {
        err.textContent = 'Password tidak boleh kosong.';
        err.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.textContent = '⏳ Memverifikasi...';
    err.style.display = 'none';

    const fd = new FormData();
    fd.append('csrf_token', <?= json_encode(csrfToken()) ?>);
    fd.append('password', pw);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.text())
        .then(text => {
            let data;
            try { data = JSON.parse(text); }
            catch(e) {
                err.textContent = 'Respons server tidak valid: ' + text.substring(0, 120);
                err.style.display = 'block';
                btn.disabled = false;
                btn.textContent = '🔓 Konfirmasi';
                return;
            }
            if (data.ok) {
                const encoded = document.getElementById('__enc_pass').value;
                _revealedPassword = atob(encoded);

                const display = document.getElementById('password-display');
                display.textContent = _revealedPassword;
                display.style.letterSpacing = 'normal';
                document.getElementById('btn-toggle-pass').textContent = '🙈 Sembunyikan';
                closeVerifyModal();
            } else {
                err.textContent = data.message || 'Password salah.';
                err.style.display = 'block';
                btn.disabled = false;
                btn.textContent = '🔓 Konfirmasi';
                document.getElementById('verify-pass-input').select();
            }
        })
        .catch(e => {
            err.textContent = 'Koneksi gagal: ' + e.message;
            err.style.display = 'block';
            btn.disabled = false;
            btn.textContent = '🔓 Konfirmasi';
        });
}

function copyPasswordIfVisible(btnId) {
    if (_revealedPassword === null) {
        askAndShowPassword();
        return;
    }
    navigator.clipboard.writeText(_revealedPassword).then(() => {
        const btn = document.getElementById(btnId);
        if (btn) {
            const original = btn.textContent;
            btn.textContent = '✅ Tersalin!';
            btn.style.background = '#28a745';
            setTimeout(() => { btn.textContent = original; btn.style.background = ''; }, 2000);
        }
    }).catch(() => {
        const el = document.createElement('textarea');
        el.value = _revealedPassword;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    });
}

function copyText(text, btnId) {
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById(btnId);
        if (btn) {
            const original = btn.textContent;
            btn.textContent = '✅ Tersalin!';
            btn.style.background = '#28a745';
            setTimeout(() => { btn.textContent = original; btn.style.background = ''; }, 2000);
        }
    }).catch(() => {
        const el = document.createElement('textarea');
        el.value = text;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    });
}

// Tutup modal klik di luar
document.getElementById('modal-verify-pass').addEventListener('click', function(e) {
    if (e.target === this) closeVerifyModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeVerifyModal();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
