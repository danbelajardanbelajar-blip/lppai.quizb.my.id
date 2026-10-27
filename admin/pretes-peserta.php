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

// Hitung sebaran hari, prodi, dan status peserta
$dayCounts = ['Senin' => 0, 'Selasa' => 0, 'Rabu' => 0, 'Kamis' => 0, 'Jumat' => 0];
$prodiCounts = [];
$statusCounts = ['terdaftar' => 0, 'hadir' => 0, 'tidak_hadir' => 0];

foreach ($participants as $p) {
    $dh = ucfirst(strtolower(trim($p['hari_pilihan'] ?? '')));
    if (isset($dayCounts[$dh])) {
        $dayCounts[$dh]++;
    }
    $prd = trim($p['program_studi'] ?? '');
    if ($prd !== '') {
        $prodiCounts[$prd] = ($prodiCounts[$prd] ?? 0) + 1;
    }
    $st = strtolower(trim($p['status'] ?? 'terdaftar'));
    if (isset($statusCounts[$st])) {
        $statusCounts[$st]++;
    }
}
ksort($prodiCounts);

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
        <!-- ── Filter Multi-Kolom di Atas Tabel Peserta Pretes ───────────── -->
        <div class="filter-panel" style="background:#ffffff;border:1.5px solid #e2e8f0;border-radius:12px;padding:18px 20px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;background:#eff6ff;color:#2563eb;font-size:16px;">
                        🔍
                    </span>
                    <div>
                        <strong style="font-size:14px;color:#0f172a;display:block;">Filter Data Peserta (Sesuai Kolom Tabel)</strong>
                        <span style="font-size:12px;color:#64748b;">Filter instan berdasarkan Nama, NIM, Prodi, Hari Pelaksanaan, Status Kehadiran, atau Status Credentials</span>
                    </div>
                    <span id="filter-active-badge" style="display:none;background:#dbeafe;color:#1e40af;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;border:1px solid #bfdbfe;margin-left:6px;">
                        0 Filter Aktif
                    </span>
                </div>
                <button type="button" id="btn-reset-filters" class="btn btn-sm" style="background:#f1f5f9;border:1px solid #cbd5e1;color:#334155;font-weight:600;font-size:12px;padding:6px 14px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;">
                    🔄 Reset Semua Filter
                </button>
            </div>

            <!-- Grid Input Filter Sesuai Nama Kolom -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px;">
                <!-- Kolom 1: Nama -->
                <div>
                    <label for="filter-nama" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px;">
                        👤 Nama Mahasiswa
                    </label>
                    <input type="text" id="filter-nama" placeholder="Cari nama..."
                        style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#f8fafc;outline:none;box-sizing:border-box;transition:border-color 0.2s;"
                        onfocus="this.style.borderColor='#3b82f6';this.style.background='#fff'"
                        onblur="this.style.borderColor='#cbd5e1';if(!this.value)this.style.background='#f8fafc'">
                </div>

                <!-- Kolom 2: NIM -->
                <div>
                    <label for="filter-nim" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px;">
                        🔢 NIM
                    </label>
                    <input type="text" id="filter-nim" placeholder="Cari NIM..."
                        style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#f8fafc;outline:none;box-sizing:border-box;transition:border-color 0.2s;"
                        onfocus="this.style.borderColor='#3b82f6';this.style.background='#fff'"
                        onblur="this.style.borderColor='#cbd5e1';if(!this.value)this.style.background='#f8fafc'">
                </div>

                <!-- Kolom 3: Prodi -->
                <div>
                    <label for="filter-prodi" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px;">
                        🎓 Program Studi
                    </label>
                    <select id="filter-prodi"
                        style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#f8fafc;color:#1e293b;outline:none;box-sizing:border-box;cursor:pointer;transition:border-color 0.2s;">
                        <option value="">Semua Prodi (<?= count($prodiCounts) ?>)</option>
                        <?php foreach ($prodiCounts as $prd => $cnt): ?>
                            <option value="<?= sanitize($prd) ?>"><?= sanitize($prd) ?> (<?= $cnt ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Kolom 4: Hari / Periode -->
                <div>
                    <label for="filter-hari" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px;">
                        📅 Hari Pelaksanaan
                    </label>
                    <select id="filter-hari"
                        style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#f8fafc;color:#1e293b;outline:none;box-sizing:border-box;cursor:pointer;transition:border-color 0.2s;">
                        <option value="">Semua Hari (<?= $totalPeserta ?>)</option>
                        <?php foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'] as $day): ?>
                            <option value="<?= $day ?>"><?= $day ?> (<?= $dayCounts[$day] ?? 0 ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Kolom 5: Status Hadir -->
                <div>
                    <label for="filter-status" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px;">
                        📋 Status Hadir
                    </label>
                    <select id="filter-status"
                        style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#f8fafc;color:#1e293b;outline:none;box-sizing:border-box;cursor:pointer;transition:border-color 0.2s;">
                        <option value="">Semua Status Kehadiran</option>
                        <option value="terdaftar">Terdaftar (<?= $statusCounts['terdaftar'] ?? 0 ?>)</option>
                        <option value="hadir">Hadir (<?= $statusCounts['hadir'] ?? 0 ?>)</option>
                        <option value="tidak_hadir">Tidak Hadir (<?= $statusCounts['tidak_hadir'] ?? 0 ?>)</option>
                    </select>
                </div>

                <!-- Kolom 6: Username Tes / Credentials -->
                <div>
                    <label for="filter-cred" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px;">
                        🔑 Status Credentials
                    </label>
                    <select id="filter-cred"
                        style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#f8fafc;color:#1e293b;outline:none;box-sizing:border-box;cursor:pointer;transition:border-color 0.2s;">
                        <option value="">Semua Status Credentials</option>
                        <option value="has_cred">Sudah Ada (<?= $totalBerCredentials ?>)</option>
                        <option value="no_cred">Belum Ada (<?= $totalPeserta - $totalBerCredentials ?>)</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Filter & Sebaran Hari Peserta Pretes (Shortcut Badges) -->
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
            <table id="table-peserta-pretes">
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
                        <td data-search="<?= sanitize($p['nama_lengkap']) ?>"><strong><?= sanitize($p['nama_lengkap']) ?></strong></td>
                        <td data-search="<?= sanitize($p['nim']) ?>"><?= sanitize($p['nim']) ?></td>
                        <td data-search="<?= sanitize($p['program_studi']) ?>"><?= sanitize($p['program_studi']) ?></td>
                        <?php 
                        $validDays = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
                        $curHari = ucfirst(strtolower(trim($p['hari_pilihan'] ?? '')));
                        ?>
                        <td data-order="<?= sanitize($curHari) ?>" data-search="<?= sanitize($curHari) ?>">
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
                        <td data-order="<?= sanitize($p['status']) ?>" data-search="<?= sanitize($p['status']) ?>">
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
                        <td data-order="<?= !empty($p['username_tes']) ? 1 : 0 ?>" data-search="<?= !empty($p['username_tes']) ? ('has_cred ' . sanitize($p['username_tes'])) : 'no_cred' ?>">
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

// ── Filter Multi-Kolom DataTables Peserta Pretes ──────────────────
function getPretesTable() {
    var tbl = $('#table-peserta-pretes');
    if (window.jQuery && $.fn.DataTable && $.fn.DataTable.isDataTable(tbl)) {
        return tbl.DataTable();
    }
    return null;
}

function updateDayBadges(activeDay) {
    document.querySelectorAll('.btn-filter-day').forEach(function(b) {
        var day = b.dataset.day || '';
        if (day === activeDay) {
            b.style.background = '#2563eb';
            b.style.color = '#ffffff';
            b.style.borderColor = '#2563eb';
            var sp = b.querySelector('span');
            if (sp) sp.style.color = '#ffffff';
        } else {
            b.style.background = '#fff';
            b.style.color = '#334155';
            b.style.borderColor = '#cbd5e1';
            var sp = b.querySelector('span');
            if (sp) sp.style.color = '#2563eb';
        }
    });
}

function applyPretesFilters() {
    var dt = getPretesTable();
    if (!dt) return;

    var namaVal   = ($('#filter-nama').val() || '').trim();
    var nimVal    = ($('#filter-nim').val() || '').trim();
    var prodiVal  = $('#filter-prodi').val() || '';
    var hariVal   = $('#filter-hari').val() || '';
    var statusVal = $('#filter-status').val() || '';
    var credVal   = $('#filter-cred').val() || '';

    // Kolom 1: Nama
    dt.column(1).search(namaVal);

    // Kolom 2: NIM
    dt.column(2).search(nimVal);

    // Kolom 3: Prodi (Exact match jika dipilih)
    if (prodiVal) {
        dt.column(3).search('^' + $.fn.dataTable.util.escapeRegex(prodiVal) + '$', true, false);
    } else {
        dt.column(3).search('');
    }

    // Kolom 4: Hari (Exact match jika dipilih)
    if (hariVal) {
        dt.column(4).search('^' + $.fn.dataTable.util.escapeRegex(hariVal) + '$', true, false);
    } else {
        dt.column(4).search('');
    }

    // Kolom 5: Status Hadir (Exact match jika dipilih)
    if (statusVal) {
        dt.column(5).search('^' + $.fn.dataTable.util.escapeRegex(statusVal) + '$', true, false);
    } else {
        dt.column(5).search('');
    }

    // Kolom 6: Credentials (has_cred / no_cred)
    if (credVal === 'has_cred') {
        dt.column(6).search('has_cred');
    } else if (credVal === 'no_cred') {
        dt.column(6).search('no_cred');
    } else {
        dt.column(6).search('');
    }

    dt.draw();

    // Sinkronkan badge shortcut distribusi hari
    updateDayBadges(hariVal);

    // Update indikator filter aktif
    var activeCount = 0;
    if (namaVal) activeCount++;
    if (nimVal) activeCount++;
    if (prodiVal) activeCount++;
    if (hariVal) activeCount++;
    if (statusVal) activeCount++;
    if (credVal) activeCount++;

    var badge = document.getElementById('filter-active-badge');
    if (badge) {
        if (activeCount > 0) {
            badge.style.display = 'inline-block';
            badge.textContent = activeCount + ' Filter Aktif';
        } else {
            badge.style.display = 'none';
        }
    }

    try {
        sessionStorage.setItem('pretes_peserta_filter', JSON.stringify({
            nama: namaVal,
            nim: nimVal,
            prodi: prodiVal,
            hari: hariVal,
            status: statusVal,
            cred: credVal
        }));
    } catch(e) {}
}

function initPretesFilters() {
    var checkTimer = setInterval(function() {
        var dt = getPretesTable();
        if (dt) {
            clearInterval(checkTimer);
            setupPretesFilters();
        }
    }, 100);
    setTimeout(function() { clearInterval(checkTimer); }, 5000);
}

function setupPretesFilters() {
    // Pulihkan filter dari sessionStorage bila ada
    try {
        var saved = JSON.parse(sessionStorage.getItem('pretes_peserta_filter') || '{}');
        if (saved.nama)   $('#filter-nama').val(saved.nama);
        if (saved.nim)    $('#filter-nim').val(saved.nim);
        if (saved.prodi)  $('#filter-prodi').val(saved.prodi);
        if (saved.hari)   $('#filter-hari').val(saved.hari);
        if (saved.status) $('#filter-status').val(saved.status);
        if (saved.cred)   $('#filter-cred').val(saved.cred);
    } catch(e) {}

    // Event input debounced untuk Nama dan NIM
    var debounceTimer;
    function debouncedApply() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(applyPretesFilters, 200);
    }

    $('#filter-nama').on('input', debouncedApply);
    $('#filter-nim').on('input', debouncedApply);

    // Event change untuk dropdown filter
    $('#filter-prodi, #filter-hari, #filter-status, #filter-cred').on('change', applyPretesFilters);

    // Tombol pill shortcut Distribusi Hari
    document.querySelectorAll('.btn-filter-day').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var day = this.dataset.day || '';
            $('#filter-hari').val(day);
            applyPretesFilters();
        });
    });

    // Tombol Reset Semua Filter
    var resetBtn = document.getElementById('btn-reset-filters');
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            $('#filter-nama').val('');
            $('#filter-nim').val('');
            $('#filter-prodi').val('');
            $('#filter-hari').val('');
            $('#filter-status').val('');
            $('#filter-cred').val('');
            try { sessionStorage.removeItem('pretes_peserta_filter'); } catch(e) {}
            applyPretesFilters();
        });
    }

    // Jalankan filter awal (misal jika ada state tersimpan)
    applyPretesFilters();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPretesFilters);
} else {
    initPretesFilters();
}

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
