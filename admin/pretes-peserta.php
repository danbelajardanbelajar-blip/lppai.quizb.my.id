<?php
/**
 * LPPAI Corner - Admin: Data Peserta Pretes & Kelola Credentials Tes Tulis
 */
define('PAGE_TITLE', 'Data Peserta & Credentials Tes Tulis');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo     = getDBConnection();
$message = '';
$msgType = '';

// ── Handle actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        // ── Generate credentials otomatis untuk SEMUA peserta ──────────────
        if ($action === 'generate_all') {
            $prefix = trim($_POST['prefix'] ?? 'TES');
            $stmt   = $pdo->query("SELECT id, user_id FROM pretes_registrations");
            $rows   = $stmt->fetchAll();
            $count  = 0;
            foreach ($rows as $row) {
                // Format: NIM mahasiswa sebagai username, random 6-char uppercase sebagai password
                $nimRow = $pdo->prepare("SELECT nim FROM users WHERE id = ?");
                $nimRow->execute([$row['user_id']]);
                $nim = $nimRow->fetchColumn();

                $username = strtoupper($prefix) . $nim;
                $password = strtoupper(substr(md5(uniqid($nim, true)), 0, 6));

                $upd = $pdo->prepare(
                    "UPDATE pretes_registrations
                     SET username_tes = ?, password_tes = ?
                     WHERE id = ? AND (username_tes IS NULL OR username_tes = '')"
                );
                $upd->execute([$username, $password, $row['id']]);
                $count += $upd->rowCount();
            }
            $message = "Credentials berhasil digenerate untuk $count peserta (yang belum punya credentials).";
            $msgType = 'success';

        // ── Reset & regenerate SEMUA (paksa timpa) ────────────────────────
        } elseif ($action === 'regenerate_all') {
            $prefix = trim($_POST['prefix'] ?? 'TES');
            $stmt   = $pdo->query("SELECT id, user_id FROM pretes_registrations");
            $rows   = $stmt->fetchAll();
            foreach ($rows as $row) {
                $nimRow = $pdo->prepare("SELECT nim FROM users WHERE id = ?");
                $nimRow->execute([$row['user_id']]);
                $nim = $nimRow->fetchColumn();

                $username = strtoupper($prefix) . $nim;
                $password = strtoupper(substr(md5(uniqid($nim . rand(), true)), 0, 6));

                $pdo->prepare(
                    "UPDATE pretes_registrations SET username_tes = ?, password_tes = ? WHERE id = ?"
                )->execute([$username, $password, $row['id']]);
            }
            $message = 'Semua credentials berhasil di-regenerate ulang!';
            $msgType = 'success';

        // ── Set / Edit credentials individual ────────────────────────────
        } elseif ($action === 'set_credential') {
            $regId    = (int)($_POST['reg_id'] ?? 0);
            $username = trim($_POST['username_tes'] ?? '');
            $password = trim($_POST['password_tes'] ?? '');

            if ($regId <= 0 || empty($username) || empty($password)) {
                $message = 'Isi semua field credentials.';
                $msgType = 'danger';
            } else {
                $pdo->prepare(
                    "UPDATE pretes_registrations SET username_tes = ?, password_tes = ? WHERE id = ?"
                )->execute([$username, $password, $regId]);
                $message = 'Credentials berhasil diperbarui!';
                $msgType = 'success';
            }

        // ── Hapus credentials individual ──────────────────────────────────
        } elseif ($action === 'clear_credential') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            $pdo->prepare(
                "UPDATE pretes_registrations SET username_tes = NULL, password_tes = NULL WHERE id = ?"
            )->execute([$regId]);
            $message = 'Credentials berhasil dihapus.';
            $msgType = 'success';

        // ── Update status kehadiran ───────────────────────────────────────
        } elseif ($action === 'update_status') {
            $regId  = (int)($_POST['reg_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if (in_array($status, ['terdaftar', 'hadir', 'tidak_hadir'])) {
                $pdo->prepare(
                    "UPDATE pretes_registrations SET status = ? WHERE id = ?"
                )->execute([$status, $regId]);
                $message = 'Status kehadiran diperbarui.';
                $msgType = 'success';
            }
        }
    }
}

// ── Fetch data ─────────────────────────────────────────────────────────────────
$participants = $pdo->query("
    SELECT pr.id as reg_id, pr.user_id, pr.periode, pr.tanggal_daftar,
           pr.status, pr.username_tes, pr.password_tes,
           u.nama_lengkap, u.nim, u.program_studi, u.fakultas, u.email, u.no_hp
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    ORDER BY u.nama_lengkap ASC
")->fetchAll();

$totalPeserta    = count($participants);
$totalBerCredentials = count(array_filter($participants, fn($p) => !empty($p['username_tes'])));

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= $message ?></div>
<?php endif; ?>

<!-- ── Statistik Ringkas ─────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon blue">👥</div>
        <div class="stat-info">
            <h3><?= $totalPeserta ?></h3>
            <p>Total Peserta</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">🔑</div>
        <div class="stat-info">
            <h3><?= $totalBerCredentials ?></h3>
            <p>Sudah Dapat Credentials</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">⏳</div>
        <div class="stat-info">
            <h3><?= $totalPeserta - $totalBerCredentials ?></h3>
            <p>Belum Ada Credentials</p>
        </div>
    </div>
</div>

<!-- ── Generate Credentials Massal ──────────────────────────────── -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">⚡ Generate Credentials Tes Tulis (Massal)</div>
    <div class="card-body">
        <p style="color:var(--text-muted);font-size:14px;margin-bottom:16px;">
            Sistem akan membuat <strong>username</strong> = Prefix + NIM, dan <strong>password</strong> = kode acak 6 karakter.<br>
            Credentials yang sudah ada <strong>tidak akan ditimpa</strong> oleh "Generate Baru". Gunakan "Regenerate Semua" untuk memaksa timpa.
        </p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div class="form-group" style="margin:0;flex:0 0 200px;">
                <label style="font-size:13px;margin-bottom:6px;display:block;">Prefix Username</label>
                <input type="text" id="prefix-input" value="TES" placeholder="contoh: TES"
                    style="padding:10px 14px;border:2px solid #e0e0e0;border-radius:10px;font-size:14px;width:100%;">
            </div>
            <form method="POST" style="display:inline;" onsubmit="document.getElementById('prefix-gen').value=document.getElementById('prefix-input').value">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action"     value="generate_all">
                <input type="hidden" name="prefix"     id="prefix-gen" value="TES">
                <button type="submit" class="btn btn-primary" style="width:auto;">
                    🔑 Generate Credentials Baru
                </button>
            </form>
            <form method="POST" style="display:inline;"
                  onsubmit="return confirm('Yakin? Ini akan MENIMPA semua credentials yang sudah ada!')"
                  onsubmit2="document.getElementById('prefix-regen').value=document.getElementById('prefix-input').value">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action"     value="regenerate_all">
                <input type="hidden" name="prefix"     id="prefix-regen" value="TES">
                <button type="submit" class="btn btn-sm btn-danger" style="width:auto;padding:10px 20px;">
                    🔄 Regenerate Semua (Timpa)
                </button>
            </form>
        </div>
        <div style="margin-top:12px;padding:12px;background:#e8f5e9;border-radius:10px;font-size:13px;color:#2e7d32;">
            💡 <strong>Contoh hasil:</strong> Prefix <code>TES</code> + NIM <code>2024010001</code> → Username: <code>TES2024010001</code>, Password: <code>AB12CD</code>
        </div>
    </div>
</div>

<!-- ── Tabel Peserta & Credentials ──────────────────────────────── -->
<div class="card">
    <div class="card-header">📋 Data Peserta & Credentials Tes Tulis (<?= $totalPeserta ?> peserta)</div>
    <div class="card-body">
        <?php if (empty($participants)): ?>
            <div class="empty-state">
                <div class="icon">👥</div>
                <h3>Belum ada peserta</h3>
                <p>Belum ada mahasiswa yang mendaftar pretes.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>NIM</th>
                        <th>Prodi</th>
                        <th>Periode</th>
                        <th>Status Hadir</th>
                        <th style="min-width:140px;">Username Tes</th>
                        <th style="min-width:120px;">Password Tes</th>
                        <th>Aksi Credentials</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($participants as $i => $p): ?>
                    <tr id="row-<?= $p['reg_id'] ?>">
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= sanitize($p['nama_lengkap']) ?></strong></td>
                        <td><?= sanitize($p['nim']) ?></td>
                        <td><?= sanitize($p['program_studi']) ?></td>
                        <td><?= sanitize($p['periode']) ?></td>
                        <td>
                            <!-- Dropdown status hadir -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action"  value="update_status">
                                <input type="hidden" name="reg_id"  value="<?= $p['reg_id'] ?>">
                                <select name="status" onchange="this.form.submit()"
                                    style="padding:4px 8px;border-radius:6px;border:1px solid #ddd;font-size:12px;">
                                    <?php foreach (['terdaftar', 'hadir', 'tidak_hadir'] as $st): ?>
                                        <option value="<?= $st ?>" <?= $p['status'] === $st ? 'selected' : '' ?>>
                                            <?= ucfirst(str_replace('_', ' ', $st)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <!-- Username -->
                        <td>
                            <?php if (!empty($p['username_tes'])): ?>
                                <code style="background:#f0f7ff;padding:3px 8px;border-radius:6px;font-size:13px;color:#1a73e8;">
                                    <?= sanitize($p['username_tes']) ?>
                                </code>
                            <?php else: ?>
                                <span style="color:#ccc;font-size:12px;font-style:italic;">— belum ada —</span>
                            <?php endif; ?>
                        </td>
                        <!-- Password -->
                        <td>
                            <?php if (!empty($p['password_tes'])): ?>
                                <span class="password-cell" style="position:relative;display:inline-flex;align-items:center;gap:6px;">
                                    <code style="background:#fff3e0;padding:3px 8px;border-radius:6px;font-size:13px;color:#e65100;letter-spacing:2px;">
                                        ••••••
                                    </code>
                                    <button type="button"
                                        style="background:none;border:none;cursor:pointer;font-size:14px;padding:0;"
                                        title="Tampilkan password"
                                        onclick="togglePassword(this, '<?= sanitize($p['password_tes']) ?>')">
                                        👁️
                                    </button>
                                </span>
                            <?php else: ?>
                                <span style="color:#ccc;font-size:12px;font-style:italic;">— belum ada —</span>
                            <?php endif; ?>
                        </td>
                        <!-- Aksi Credentials -->
                        <td style="display:flex;gap:4px;flex-wrap:wrap;">
                            <!-- Tombol Edit -->
                            <button type="button"
                                class="btn btn-sm btn-warning"
                                onclick="openEditModal(<?= $p['reg_id'] ?>, '<?= sanitize($p['nama_lengkap']) ?>', '<?= sanitize($p['username_tes'] ?? '') ?>', '<?= sanitize($p['password_tes'] ?? '') ?>')">
                                ✏️ Edit
                            </button>
                            <!-- Tombol Hapus -->
                            <?php if (!empty($p['username_tes'])): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action"  value="clear_credential">
                                <input type="hidden" name="reg_id"  value="<?= $p['reg_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    onclick="return confirm('Hapus credentials <?= sanitize($p['nama_lengkap']) ?>?')">
                                    🗑️
                                </button>
                            </form>
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

<!-- ── Modal Edit Credentials ────────────────────────────────────── -->
<div id="modal-edit-cred" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:32px;width:100%;max-width:440px;box-shadow:0 8px 40px rgba(0,0,0,0.25);">
        <h3 style="margin-bottom:4px;">✏️ Edit Credentials Tes Tulis</h3>
        <p id="modal-name" style="color:var(--text-muted);font-size:13px;margin-bottom:20px;"></p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action"  value="set_credential">
            <input type="hidden" name="reg_id"  id="modal-reg-id">
            <div class="form-group">
                <label>Username Tes *</label>
                <input type="text" name="username_tes" id="modal-username" required placeholder="contoh: TES2024010001">
            </div>
            <div class="form-group">
                <label>Password Tes *</label>
                <div style="position:relative;">
                    <input type="text" name="password_tes" id="modal-password" required placeholder="contoh: AB12CD"
                        style="padding-right:44px;">
                    <button type="button" onclick="generatePassword()"
                        style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:var(--primary);color:#fff;border:none;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:12px;">
                        🎲 Acak
                    </button>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn btn-sm btn-warning"
                    onclick="document.getElementById('modal-edit-cred').style.display='none'">
                    Batal
                </button>
                <button type="submit" class="btn btn-primary" style="width:auto;">💾 Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
// Buka modal edit
function openEditModal(regId, nama, username, password) {
    document.getElementById('modal-reg-id').value  = regId;
    document.getElementById('modal-name').textContent = 'Peserta: ' + nama;
    document.getElementById('modal-username').value  = username;
    document.getElementById('modal-password').value  = password;
    document.getElementById('modal-edit-cred').style.display = 'flex';
}

// Tutup modal jika klik luar
document.getElementById('modal-edit-cred').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

// Generate password acak 6 karakter
function generatePassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let pass = '';
    for (let i = 0; i < 6; i++) pass += chars[Math.floor(Math.random() * chars.length)];
    document.getElementById('modal-password').value = pass;
}

// Toggle tampil/sembunyikan password di tabel
function togglePassword(btn, plainPass) {
    const code = btn.previousElementSibling;
    if (code.textContent.trim() === '••••••') {
        code.textContent = plainPass;
        code.style.letterSpacing = 'normal';
        btn.textContent = '🙈';
        btn.title = 'Sembunyikan password';
    } else {
        code.textContent = '••••••';
        code.style.letterSpacing = '2px';
        btn.textContent = '👁️';
        btn.title = 'Tampilkan password';
    }
}

// Sinkron prefix saat submit
document.querySelectorAll('form[method="POST"]').forEach(form => {
    form.addEventListener('submit', function() {
        const prefixInput = document.getElementById('prefix-input');
        const prefixField = this.querySelector('input[name="prefix"]');
        if (prefixInput && prefixField) prefixField.value = prefixInput.value;
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
