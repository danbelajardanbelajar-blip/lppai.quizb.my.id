<?php
/**
 * LPPAI Corner - Peserta & Jadwal Pretes (Mahasiswa)
 * Menampilkan jadwal pretes, daftar peserta, dan credentials tes tulis pribadi.
 */
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
    "SELECT * FROM pretes_schedules ORDER BY tanggal ASC, waktu_mulai ASC"
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
                        <button onclick="togglePasswordView()"
                                id="btn-toggle-pass"
                                style="background:#e65100;color:#fff;border:none;border-radius:8px;
                                       padding:6px 14px;cursor:pointer;font-size:13px;">
                            👁️ Tampilkan
                        </button>
                        <button onclick="copyText('<?= sanitize($myReg['password_tes']) ?>', 'btn-copy-pass')"
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

<script>
const plainPassword = <?= json_encode($myReg['password_tes'] ?? '') ?>;
let passwordVisible = false;

function togglePasswordView() {
    passwordVisible = !passwordVisible;
    const display = document.getElementById('password-display');
    const btn     = document.getElementById('btn-toggle-pass');
    if (display && btn) {
        display.textContent      = passwordVisible ? plainPassword : '••••••';
        display.style.letterSpacing = passwordVisible ? 'normal' : '6px';
        btn.textContent          = passwordVisible ? '🙈 Sembunyikan' : '👁️ Tampilkan';
    }
}

function copyText(text, btnId) {
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById(btnId);
        if (btn) {
            const original = btn.textContent;
            btn.textContent = '✅ Tersalin!';
            btn.style.background = '#28a745';
            setTimeout(() => {
                btn.textContent = original;
                btn.style.background = '';
            }, 2000);
        }
    }).catch(() => {
        // Fallback untuk browser lama
        const el = document.createElement('textarea');
        el.value = text;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
