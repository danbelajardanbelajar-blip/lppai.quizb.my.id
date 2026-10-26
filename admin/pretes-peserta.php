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
            $stmt   = $pdo->query("SELECT id, user_id FROM pretes_registrations WHERE (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%')");
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
            $stmt   = $pdo->query("SELECT id, user_id FROM pretes_registrations WHERE (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%')");
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

        // ── Set / Edit credentials & hari individual ─────────────────────
        } elseif ($action === 'set_credential') {
            $regId    = (int)($_POST['reg_id'] ?? 0);
            $username = trim($_POST['username_tes'] ?? '');
            $password = trim($_POST['password_tes'] ?? '');
            $hari     = trim($_POST['hari_pilihan'] ?? '');
            $validDays = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];

            if ($regId <= 0) {
                $message = 'Peserta tidak valid.';
                $msgType = 'danger';
            } else {
                $updates = [];
                $params = [];
                if (in_array($hari, $validDays)) {
                    $updates[] = "hari_pilihan = ?";
                    $params[] = $hari;
                }
                if ($username !== '') {
                    $updates[] = "username_tes = ?";
                    $params[] = $username;
                }
                if ($password !== '') {
                    $updates[] = "password_tes = ?";
                    $params[] = $password;
                }

                if (!empty($updates)) {
                    $params[] = $regId;
                    $pdo->prepare("UPDATE pretes_registrations SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
                    $message = 'Data peserta & credentials berhasil disimpan.';
                    $msgType = 'success';
                } else {
                    $message = 'Tidak ada perubahan data yang disimpan.';
                    $msgType = 'warning';
                }
            }

        // ── Update hari pelaksanaan (cepat langsung dari tabel) ───────────
        } elseif ($action === 'update_hari') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            $hari  = trim($_POST['hari_pilihan'] ?? '');
            $validDays = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
            if ($regId > 0 && in_array($hari, $validDays)) {
                $stmtNama = $pdo->prepare("SELECT u.nama_lengkap FROM pretes_registrations pr JOIN users u ON pr.user_id = u.id WHERE pr.id = ?");
                $stmtNama->execute([$regId]);
                $namaMhs = $stmtNama->fetchColumn() ?: 'Peserta';

                $pdo->prepare("UPDATE pretes_registrations SET hari_pilihan = ? WHERE id = ?")->execute([$hari, $regId]);
                $message = "Hari pelaksanaan untuk <strong>" . sanitize($namaMhs) . "</strong> berhasil diubah menjadi <strong>$hari</strong>.";
                $msgType = 'success';
            } else {
                $message = 'Pilihan hari pelaksanaan tidak valid.';
                $msgType = 'danger';
            }

        // ── Hapus credentials individual ──────────────────────────────────
        } elseif ($action === 'clear_credential') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            $pdo->prepare(
                "UPDATE pretes_registrations SET username_tes = NULL, password_tes = NULL WHERE id = ?"
            )->execute([$regId]);
            $message = 'Credentials berhasil dihapus.';
            $msgType = 'success';

        // ── Hapus data pendaftaran peserta pretes ─────────────────────────
        } elseif ($action === 'delete_peserta') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            if ($regId > 0) {
                $pdo->prepare("DELETE FROM pretes_registrations WHERE id = ?")->execute([$regId]);
                $message = 'Peserta pretes berhasil dihapus.';
                $msgType = 'success';
            }

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
    SELECT pr.id as reg_id, pr.user_id, pr.periode, pr.hari_pilihan, pr.jam, pr.dosen_penguji, pr.ruangan, pr.tanggal_daftar,
           pr.status, pr.username_tes, pr.password_tes,
           u.nama_lengkap, u.nim, u.program_studi, u.fakultas, u.email, u.no_hp
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    ORDER BY u.nama_lengkap ASC
")->fetchAll();

$totalPeserta    = count($participants);
$totalBerCredentials = count(array_filter($participants, fn($p) => !empty($p['username_tes'])));

// Hitung sebaran hari peserta
$dayCounts = ['Senin' => 0, 'Selasa' => 0, 'Rabu' => 0, 'Kamis' => 0, 'Jumat' => 0];
foreach ($participants as $p) {
    $dh = ucfirst(strtolower(trim($p['hari_pilihan'] ?? '')));
    if (isset($dayCounts[$dh])) {
        $dayCounts[$dh]++;
    }
}

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
        <!-- Filter & Sebaran Hari Peserta Pretes -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center;background:#f8fafc;padding:10px 14px;border-radius:10px;border:1px solid #e2e8f0;">
            <span style="font-size:13px;font-weight:600;color:#475569;">📅 Distribusi Hari:</span>
            <button type="button" class="btn-filter-day" data-day="" style="padding:4px 12px;border-radius:20px;border:1px solid #cbd5e1;background:#e2e8f0;font-size:12px;cursor:pointer;font-weight:600;color:#334155;">
                Semua (<?= $totalPeserta ?>)
            </button>
            <?php foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'] as $day): ?>
                <button type="button" class="btn-filter-day" data-day="<?= $day ?>" style="padding:4px 12px;border-radius:20px;border:1px solid #cbd5e1;background:#fff;font-size:12px;cursor:pointer;font-weight:600;color:#334155;">
                    <?= $day ?>: <span style="color:#2563eb;font-weight:bold;"><?= $dayCounts[$day] ?? 0 ?></span>
                </button>
            <?php endforeach; ?>
            <span style="margin-left:auto;font-size:12px;color:#64748b;">
                💡 <em>Admin dapat merubah hari langsung pada dropdown tabel atau klik tombol Edit.</em>
            </span>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>NIM</th>
                        <th>Prodi</th>
                        <th>Hari / Periode</th>
                        <th>Status Hadir</th>
                        <th style="min-width:140px;">Username Tes</th>
                        <th style="min-width:120px;">Password Tes</th>
                        <th style="min-width:160px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($participants as $i => $p): ?>
                    <tr id="row-<?= $p['reg_id'] ?>">
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= sanitize($p['nama_lengkap']) ?></strong></td>
                        <td><?= sanitize($p['nim']) ?></td>
                        <td><?= sanitize($p['program_studi']) ?></td>
                        <?php 
                        $validDays = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
                        $curHari = ucfirst(strtolower(trim($p['hari_pilihan'] ?? '')));
                        ?>
                        <td data-order="<?= sanitize($curHari) ?>" data-search="<?= sanitize($curHari) ?> <?= sanitize($p['jam'] ?? '') ?>">
                            <!-- Dropdown ubah hari pelaksanaan langsung -->
                            <form method="POST" style="display:inline-flex;align-items:center;gap:6px;margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action"     value="update_hari">
                                <input type="hidden" name="reg_id"     value="<?= $p['reg_id'] ?>">
                                <select name="hari_pilihan" onchange="this.form.submit()"
                                    title="Klik untuk langsung merubah hari pelaksanaan"
                                    style="padding:5px 8px;border-radius:6px;border:1.5px solid #cbd5e1;font-size:12px;font-weight:600;color:#1e293b;background-color:#fff;cursor:pointer;">
                                    <?php foreach ($validDays as $day): ?>
                                        <option value="<?= $day ?>" <?= ($curHari === $day) ? 'selected' : '' ?>>
                                            <?= $day ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if (!in_array($curHari, $validDays) && !empty($p['hari_pilihan'])): ?>
                                        <option value="<?= sanitize($p['hari_pilihan']) ?>" selected><?= sanitize($p['hari_pilihan']) ?></option>
                                    <?php endif; ?>
                                </select>
                                <span style="font-size:11px;color:#64748b;white-space:nowrap;">
                                    (<?= sanitize($p['jam'] ?? '13.00 WIB') ?>)
                                </span>
                            </form>
                        </td>
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
                                    <!-- Hidden encoded password -->
                                    <input type="hidden"
                                        class="enc-pass-data"
                                        data-reg-id="<?= $p['reg_id'] ?>"
                                        value="<?= base64_encode($p['password_tes']) ?>">
                                    <code id="pass-display-<?= $p['reg_id'] ?>"
                                        style="background:#fff3e0;padding:3px 8px;border-radius:6px;font-size:13px;color:#e65100;letter-spacing:2px;">
                                        ••••••
                                    </code>
                                    <button type="button"
                                        id="pass-btn-<?= $p['reg_id'] ?>"
                                        style="background:none;border:none;cursor:pointer;font-size:14px;padding:0;"
                                        title="Tampilkan password"
                                        onclick="openPasswordModal(<?= $p['reg_id'] ?>)">
                                        👁️
                                    </button>
                                </span>
                            <?php else: ?>
                                <span style="color:#ccc;font-size:12px;font-style:italic;">— belum ada —</span>
                            <?php endif; ?>
                        </td>
                        <!-- Aksi -->
                        <td style="white-space:nowrap;">
                            <div style="display:flex;gap:6px;align-items:center;">
                                <!-- Tombol Edit Data & Credentials -->
                                <button type="button"
                                    class="btn btn-sm btn-warning btn-edit-cred"
                                    data-reg-id="<?= $p['reg_id'] ?>"
                                    data-nama="<?= htmlspecialchars($p['nama_lengkap'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-hari="<?= htmlspecialchars($curHari, ENT_QUOTES, 'UTF-8') ?>"
                                    data-username="<?= htmlspecialchars($p['username_tes'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-password="<?= htmlspecialchars($p['password_tes'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    title="Edit Hari Pelaksanaan & Credentials">
                                    ✏️ Edit
                                </button>
                                <!-- Tombol Hapus Peserta -->
                                <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus peserta <?= htmlspecialchars(addslashes($p['nama_lengkap']), ENT_QUOTES, 'UTF-8') ?> dari pendaftaran pretes? Tindakan ini tidak dapat dibatalkan.');">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="delete_peserta">
                                    <input type="hidden" name="reg_id" value="<?= $p['reg_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Hapus Peserta Pretes">
                                        🗑️ Hapus
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Modal Edit Data Peserta & Credentials ───────────────────── -->
<div id="modal-edit-cred" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:32px;width:100%;max-width:460px;box-shadow:0 8px 40px rgba(0,0,0,0.25);">
        <h3 style="margin-bottom:4px;">✏️ Edit Data & Credentials Peserta</h3>
        <p id="modal-name" style="color:var(--text-muted);font-size:13px;margin-bottom:20px;"></p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action"  value="set_credential">
            <input type="hidden" name="reg_id"  id="modal-reg-id">
            
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;display:block;">Hari Pelaksanaan Pretes *</label>
                <select name="hari_pilihan" id="modal-hari" required
                    style="width:100%;padding:10px 14px;border:2px solid #e0e0e0;border-radius:10px;font-size:14px;background:#fff;color:#1e293b;outline:none;">
                    <option value="Senin">Senin</option>
                    <option value="Selasa">Selasa</option>
                    <option value="Rabu">Rabu</option>
                    <option value="Kamis">Kamis</option>
                    <option value="Jumat">Jumat</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;display:block;">Username Tes</label>
                <input type="text" name="username_tes" id="modal-username" placeholder="contoh: TES2024010001"
                    style="width:100%;padding:10px 14px;border:2px solid #e0e0e0;border-radius:10px;font-size:14px;">
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;display:block;">Password Tes</label>
                <div style="position:relative;">
                    <input type="text" name="password_tes" id="modal-password" placeholder="contoh: AB12CD"
                        style="width:100%;padding:10px 14px;padding-right:70px;border:2px solid #e0e0e0;border-radius:10px;font-size:14px;">
                    <button type="button" onclick="generatePassword()"
                        style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:var(--primary);color:#fff;border:none;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;">
                        🎲 Acak
                    </button>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:20px;">
                <button type="button" class="btn btn-sm btn-outline-danger" id="btn-modal-clear" style="display:none;" onclick="clearCredFromModal()">
                    🗑️ Kosongkan Credentials
                </button>
                <div style="display:flex;gap:10px;margin-left:auto;">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="closeEditModal()">
                        Batal
                    </button>
                    <button type="submit" class="btn btn-primary" style="width:auto;">💾 Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>


<script>
function openPasswordModal(regId) {
    const code = document.getElementById('pass-display-' + regId);
    const btn  = document.getElementById('pass-btn-' + regId);

    // Jika sudah tampil → sembunyikan
    if (btn && btn.textContent.trim() === '🙈') {
        code.textContent = '••••••';
        code.style.letterSpacing = '2px';
        btn.textContent = '👁️';
        btn.title = 'Tampilkan password';
        return;
    }

    const encInput = document.querySelector('.enc-pass-data[data-reg-id="' + regId + '"]');
    if (encInput) {
        const plain = atob(encInput.value);
        code.textContent = plain;
        code.style.letterSpacing = 'normal';
        btn.textContent = '🙈';
        btn.title = 'Sembunyikan password';
    }
}

// Buka modal edit data peserta & credentials
function openEditModal(regId, nama, hari, username, password) {
    document.getElementById('modal-reg-id').value  = regId;
    document.getElementById('modal-name').textContent = 'Peserta: ' + (nama || '');
    if (document.getElementById('modal-hari')) {
        document.getElementById('modal-hari').value = hari || 'Senin';
    }
    document.getElementById('modal-username').value  = username || '';
    document.getElementById('modal-password').value  = password || '';
    
    const clearBtn = document.getElementById('btn-modal-clear');
    if (clearBtn) {
        clearBtn.style.display = (username || password) ? 'inline-block' : 'none';
    }
    document.getElementById('modal-edit-cred').style.display = 'flex';
}

function closeEditModal() {
    const m = document.getElementById('modal-edit-cred');
    if (m) m.style.display = 'none';
}

function clearCredFromModal() {
    if (confirm('Apakah Anda yakin ingin mengosongkan username dan password tes untuk peserta ini?')) {
        const form = document.querySelector('#modal-edit-cred form');
        const actionInput = form.querySelector('input[name="action"]');
        if (actionInput) actionInput.value = 'clear_credential';
        form.submit();
    }
}

// Event delegation untuk tombol Edit Credentials agar selalu dapat diklik
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-edit-cred');
    if (btn) {
        const d = btn.dataset;
        openEditModal(d.regId, d.nama, d.hari, d.username, d.password);
        return;
    }
    const modal = document.getElementById('modal-edit-cred');
    if (e.target === modal) {
        closeEditModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
    }
});

// Filter DataTables berdasarkan Hari Pelaksanaan
document.querySelectorAll('.btn-filter-day').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var day = this.dataset.day || '';
        if (window.jQuery && $.fn.DataTable && $.fn.DataTable.isDataTable('table')) {
            $('table').DataTable().column(4).search(day).draw();
        }
        document.querySelectorAll('.btn-filter-day').forEach(function(b) {
            b.style.background = '#fff';
            b.style.color = '#334155';
        });
        this.style.background = '#e2e8f0';
    });
});

// Generate password acak 6 karakter
function generatePassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let pass = '';
    for (let i = 0; i < 6; i++) pass += chars[Math.floor(Math.random() * chars.length)];
    document.getElementById('modal-password').value = pass;
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
