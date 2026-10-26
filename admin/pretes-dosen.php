<?php
/**
 * LPPAI Corner - Admin: Kelola Dosen Penguji & Kuota Pretes
 */
define('PAGE_TITLE', 'Dosen Penguji & Kuota Pretes');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();

// Auto-migration aman untuk tabel master_pretes dan kolom pretes_registrations
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS master_pretes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            periode VARCHAR(100) NOT NULL,
            tahun_ajaran VARCHAR(50) NOT NULL,
            status ENUM('aktif','selesai','nonaktif') NOT NULL DEFAULT 'aktif',
            jam VARCHAR(50) DEFAULT '13.00 WIB (Jam 1)',
            kuota_senin INT DEFAULT 0,
            kuota_selasa INT DEFAULT 0,
            kuota_rabu INT DEFAULT 0,
            kuota_kamis INT DEFAULT 0,
            kuota_jumat INT DEFAULT 0,
            penguji_senin VARCHAR(1000) DEFAULT NULL,
            penguji_selasa VARCHAR(1000) DEFAULT NULL,
            penguji_rabu VARCHAR(1000) DEFAULT NULL,
            penguji_kamis VARCHAR(1000) DEFAULT NULL,
            penguji_jumat VARCHAR(1000) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {}

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("ALTER TABLE pretes_registrations MODIFY pretes_schedule_id INT NULL");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN hari_pilihan VARCHAR(20) DEFAULT NULL");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN jam VARCHAR(50) DEFAULT '13.00 WIB (Jam 1)'");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN dosen_penguji VARCHAR(150) DEFAULT NULL");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN ruangan VARCHAR(100) DEFAULT NULL");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
} catch (Exception $e) {}

// Cek apakah tabel master_pretes masih kosong, jika ya buat periode default aktif
try {
    $hasMaster = $pdo->query("SELECT COUNT(*) FROM master_pretes")->fetchColumn();
    if ($hasMaster == 0) {
        $curYear = (int)date('Y');
        $ta = $curYear . ' - ' . ($curYear + 1);
        $per = 'Pretes ' . $curYear . '/' . ($curYear + 1);
        $pdo->prepare("
            INSERT INTO master_pretes 
            (periode, tahun_ajaran, status, jam, kuota_senin, kuota_selasa, kuota_rabu, kuota_kamis, kuota_jumat) 
            VALUES (?, ?, 'aktif', '13.00 WIB (Jam 1)', 30, 30, 30, 30, 30)
        ")->execute([$per, $ta]);
    }
} catch (Exception $e) {}

$message = '';
$msgType = '';

// Ambil dosen penguji (role = dosen)
$tutorsList = $pdo->query("SELECT nim as id, nama_lengkap as nama FROM users WHERE role = 'dosen' ORDER BY nama_lengkap ASC")->fetchAll();

// Handle Form POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        // 1. TAMBAH PERIODE PRETES BARU
        if ($action === 'create_periode') {
            $periode      = trim($_POST['periode'] ?? '');
            $tahun_ajaran = trim($_POST['tahun_ajaran'] ?? '');
            $jam          = trim($_POST['jam'] ?? '13.00 WIB (Jam 1)');
            $status       = $_POST['status'] ?? 'aktif';

            $kuota_senin  = (int)($_POST['kuota_senin'] ?? 0);
            $kuota_selasa = (int)($_POST['kuota_selasa'] ?? 0);
            $kuota_rabu   = (int)($_POST['kuota_rabu'] ?? 0);
            $kuota_kamis  = (int)($_POST['kuota_kamis'] ?? 0);
            $kuota_jumat  = (int)($_POST['kuota_jumat'] ?? 0);

            $penguji_senin  = isset($_POST['penguji_senin']) && is_array($_POST['penguji_senin']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_senin']))) : '';
            $penguji_selasa = isset($_POST['penguji_selasa']) && is_array($_POST['penguji_selasa']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_selasa']))) : '';
            $penguji_rabu   = isset($_POST['penguji_rabu']) && is_array($_POST['penguji_rabu']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_rabu']))) : '';
            $penguji_kamis  = isset($_POST['penguji_kamis']) && is_array($_POST['penguji_kamis']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_kamis']))) : '';
            $penguji_jumat  = isset($_POST['penguji_jumat']) && is_array($_POST['penguji_jumat']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_jumat']))) : '';

            if (empty($periode) || empty($tahun_ajaran)) {
                $message = 'Nama Periode dan Tahun Ajaran wajib diisi.';
                $msgType = 'danger';
            } else {
                if ($status === 'aktif') {
                    $pdo->exec("UPDATE master_pretes SET status = 'nonaktif' WHERE status = 'aktif'");
                }

                $stmt = $pdo->prepare("
                    INSERT INTO master_pretes 
                    (periode, tahun_ajaran, status, jam, kuota_senin, kuota_selasa, kuota_rabu, kuota_kamis, kuota_jumat, penguji_senin, penguji_selasa, penguji_rabu, penguji_kamis, penguji_jumat)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $periode, $tahun_ajaran, $status, $jam,
                    $kuota_senin, $kuota_selasa, $kuota_rabu, $kuota_kamis, $kuota_jumat,
                    $penguji_senin, $penguji_selasa, $penguji_rabu, $penguji_kamis, $penguji_jumat
                ]);

                $message = 'Periode Pretes baru berhasil ditambahkan!';
                $msgType = 'success';
            }

        // 2. EDIT PERIODE PRETES (SATU PINTU UNTUK METADATA, PENGUJI, DAN KUOTA)
        } elseif ($action === 'edit_periode' || $action === 'update_penguji_kuota') {
            $id           = (int)($_POST['id'] ?? ($_POST['pretes_id'] ?? 0));
            $periode      = trim($_POST['periode'] ?? '');
            $tahun_ajaran = trim($_POST['tahun_ajaran'] ?? '');
            $jam          = trim($_POST['jam'] ?? '13.00 WIB (Jam 1)');
            $status       = $_POST['status'] ?? 'aktif';

            $kuota_senin  = (int)($_POST['kuota_senin'] ?? 0);
            $kuota_selasa = (int)($_POST['kuota_selasa'] ?? 0);
            $kuota_rabu   = (int)($_POST['kuota_rabu'] ?? 0);
            $kuota_kamis  = (int)($_POST['kuota_kamis'] ?? 0);
            $kuota_jumat  = (int)($_POST['kuota_jumat'] ?? 0);

            $penguji_senin  = isset($_POST['penguji_senin']) && is_array($_POST['penguji_senin']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_senin']))) : '';
            $penguji_selasa = isset($_POST['penguji_selasa']) && is_array($_POST['penguji_selasa']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_selasa']))) : '';
            $penguji_rabu   = isset($_POST['penguji_rabu']) && is_array($_POST['penguji_rabu']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_rabu']))) : '';
            $penguji_kamis  = isset($_POST['penguji_kamis']) && is_array($_POST['penguji_kamis']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_kamis']))) : '';
            $penguji_jumat  = isset($_POST['penguji_jumat']) && is_array($_POST['penguji_jumat']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_jumat']))) : '';

            if ($id <= 0) {
                $message = 'ID Periode tidak valid.';
                $msgType = 'danger';
            } else {
                // Jika nama periode & tahun ajaran dikosongkan (misal dari quick submit), pertahankan data lama
                if (empty($periode) || empty($tahun_ajaran)) {
                    $old = $pdo->prepare("SELECT periode, tahun_ajaran FROM master_pretes WHERE id = ?");
                    $old->execute([$id]);
                    $oldRow = $old->fetch();
                    if ($oldRow) {
                        if (empty($periode)) $periode = $oldRow['periode'];
                        if (empty($tahun_ajaran)) $tahun_ajaran = $oldRow['tahun_ajaran'];
                    }
                }

                if ($status === 'aktif') {
                    $pdo->prepare("UPDATE master_pretes SET status = 'nonaktif' WHERE id != ? AND status = 'aktif'")->execute([$id]);
                }

                $stmt = $pdo->prepare("
                    UPDATE master_pretes 
                    SET periode=?, tahun_ajaran=?, status=?, jam=?,
                        kuota_senin=?, kuota_selasa=?, kuota_rabu=?, kuota_kamis=?, kuota_jumat=?,
                        penguji_senin=?, penguji_selasa=?, penguji_rabu=?, penguji_kamis=?, penguji_jumat=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $periode, $tahun_ajaran, $status, $jam,
                    $kuota_senin, $kuota_selasa, $kuota_rabu, $kuota_kamis, $kuota_jumat,
                    $penguji_senin, $penguji_selasa, $penguji_rabu, $penguji_kamis, $penguji_jumat,
                    $id
                ]);

                $message = 'Pengaturan Periode Pretes berhasil disimpan!';
                $msgType = 'success';
            }

        // 3. AKTIFKAN PERIODE
        } elseif ($action === 'set_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->exec("UPDATE master_pretes SET status = 'nonaktif' WHERE status = 'aktif'");
                $pdo->prepare("UPDATE master_pretes SET status = 'aktif' WHERE id = ?")->execute([$id]);
                $message = 'Periode pretes berhasil diaktifkan!';
                $msgType = 'success';
            }

        // 4. HAPUS PERIODE
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM master_pretes WHERE id = ?")->execute([$id]);
                $message = 'Periode pretes berhasil dihapus.';
                $msgType = 'success';
            }
        }
    }
}

// Ambil Periode Aktif
$active_pretes = $pdo->query("SELECT * FROM master_pretes WHERE status = 'aktif' ORDER BY created_at DESC LIMIT 1")->fetch();

// Ambil Seluruh Data Master Pretes
$all_pretes = $pdo->query("SELECT * FROM master_pretes ORDER BY created_at DESC")->fetchAll();

// Hitung Pendaftar Pretes per Hari pada periode aktif
$registeredCounts = ['Senin' => 0, 'Selasa' => 0, 'Rabu' => 0, 'Kamis' => 0, 'Jumat' => 0];
if ($active_pretes) {
    $stmtCount = $pdo->prepare("
        SELECT hari_pilihan, COUNT(*) as cnt 
        FROM pretes_registrations 
        WHERE (periode = ? OR periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%')
        GROUP BY hari_pilihan
    ");
    $stmtCount->execute([$active_pretes['periode']]);
    foreach ($stmtCount->fetchAll() as $row) {
        $h = ucfirst(strtolower(trim($row['hari_pilihan'] ?? '')));
        if (isset($registeredCounts[$h])) {
            $registeredCounts[$h] = (int)$row['cnt'];
        }
    }
}

// Helper render input Dosen Penguji per hari
function renderPengujiSelect($day, $tutorsList, $isEdit = false, $defaultPenguji = []) {
    $idPrefix = $isEdit ? 'edit_' : '';
    if (empty($defaultPenguji)) {
        $defaultPenguji = ['']; // Minimal 1 baris
    }
    ?>
    <div class="form-group" style="margin-bottom:0; display:flex; flex-direction:column; gap:6px;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <label style="font-weight:700; font-size:14px; text-transform:capitalize; color:#0f172a; margin:0;"><?= $day ?></label>
            <span style="font-size:11px; background:#e2e8f0; color:#475569; padding:2px 6px; border-radius:4px; font-weight:600;">13.00</span>
        </div>
        <div id="<?= $idPrefix ?>penguji_<?= $day ?>_container" class="tutor-container" data-day="<?= $day ?>" data-edit="<?= $isEdit ? '1' : '0' ?>">
            <?php foreach($defaultPenguji as $idx => $pengujiName): ?>
            <div class="tutor-row" style="display:flex; gap:4px; margin-bottom:4px;">
                <select name="penguji_<?= $day ?>[]" class="tutor-select" style="flex:1; padding:6px 8px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:13px;" onchange="calculateQuota('<?= $day ?>', <?= $isEdit ? 'true' : 'false' ?>)">
                    <option value="">- Pilih Penguji -</option>
                    <?php foreach($tutorsList as $t): ?>
                    <option value="<?= sanitize($t['nama']) ?>" <?= ($t['nama'] === $pengujiName) ? 'selected' : '' ?>><?= sanitize($t['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($idx === 0): ?>
                <button type="button" class="btn btn-sm btn-success add-tutor-btn" onclick="addPengujiRow('<?= $day ?>', <?= $isEdit ? 'true' : 'false' ?>)" style="padding:0 9px; font-weight:bold; font-size:14px;">+</button>
                <?php else: ?>
                <button type="button" class="btn btn-sm btn-danger remove-tutor-btn" onclick="this.parentElement.remove(); calculateQuota('<?= $day ?>', <?= $isEdit ? 'true' : 'false' ?>);" style="padding:0 9px; font-weight:bold; font-size:14px;">×</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="font-size:11px; color:#64748b; margin-top:2px; font-weight:600;">Kuota Peserta:</div>
        <input type="number" name="kuota_<?= $day ?>" id="<?= $idPrefix ?>kuota_<?= $day ?>" class="kuota-input" data-day="<?= $day ?>" min="0" value="0" style="width:100%;padding:6px 8px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;background:#fff;font-weight:bold;color:#0f172a;">
    </div>
    <?php
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= $message ?></div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     CARD 1: STATUS PERIODE PRETES AKTIF & TOMBOL EDIT SATU PINTU
     ═══════════════════════════════════════════════════════════════ -->
<?php if ($active_pretes): ?>
<div class="card" style="margin-bottom: 24px; border-left: 5px solid var(--primary); background: #f8fafc;">
    <div class="card-body" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:4px; flex-wrap:wrap;">
                <h3 style="margin:0; font-size:18px; color:#1e293b;">
                    🌟 Periode Pretes Aktif: <strong><?= sanitize($active_pretes['periode']) ?></strong>
                </h3>
                <span class="badge badge-success" style="font-size:12px; padding:3px 10px;">✅ Aktif Menerima Pendaftaran</span>
            </div>
            <p style="margin:0 0 12px; color:#64748b; font-size:14px;">
                Tahun Ajaran: <strong><?= sanitize($active_pretes['tahun_ajaran']) ?></strong> | 
                Jam Pelaksanaan: <strong><?= sanitize($active_pretes['jam'] ?? '13.00 WIB (Jam 1)') ?></strong>
            </p>

            <!-- Ringkasan Kuota Harian -->
            <div style="display:flex; gap:10px; flex-wrap:wrap; font-size:13px;">
                <?php 
                $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
                foreach ($days as $day): 
                    $dayLower = strtolower($day);
                    $kuota  = (int)($active_pretes["kuota_$dayLower"] ?? 0);
                    $terisi = $registeredCounts[$day] ?? 0;
                    $sisa   = $kuota - $terisi;
                ?>
                <div style="background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:6px 12px; min-width:140px; box-shadow:0 1px 2px rgba(0,0,0,0.03);">
                    <div style="font-weight:700; color:#0f172a; margin-bottom:2px;"><?= $day ?> (Jam 1)</div>
                    <div style="font-size:12px; color:#475569;">
                        Kuota: <b><?= $kuota ?></b> | Terisi: <b><?= $terisi ?></b>
                    </div>
                    <div style="font-size:12px; font-weight:bold; color:<?= $sisa < 0 ? '#ef4444' : '#10b981' ?>; margin-top:2px;">
                        Sisa Kursi: <?= $sisa ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div>
            <!-- Tombol Cepat Membuka Modal Edit untuk Periode Aktif -->
            <button type="button" class="btn btn-warning btn-edit-pretes" style="padding:12px 22px; font-size:14px; font-weight:700; display:inline-flex; align-items:center; gap:8px; box-shadow:0 2px 4px rgba(0,0,0,0.1);"
                data-id="<?= $active_pretes['id'] ?>"
                data-periode="<?= htmlspecialchars($active_pretes['periode'], ENT_QUOTES, 'UTF-8') ?>"
                data-tahun="<?= htmlspecialchars($active_pretes['tahun_ajaran'], ENT_QUOTES, 'UTF-8') ?>"
                data-jam="<?= htmlspecialchars($active_pretes['jam'] ?? '13.00 WIB (Jam 1)', ENT_QUOTES, 'UTF-8') ?>"
                data-status="<?= htmlspecialchars($active_pretes['status'], ENT_QUOTES, 'UTF-8') ?>"
                data-kuota-senin="<?= (int)$active_pretes['kuota_senin'] ?>"
                data-kuota-selasa="<?= (int)$active_pretes['kuota_selasa'] ?>"
                data-kuota-rabu="<?= (int)$active_pretes['kuota_rabu'] ?>"
                data-kuota-kamis="<?= (int)$active_pretes['kuota_kamis'] ?>"
                data-kuota-jumat="<?= (int)$active_pretes['kuota_jumat'] ?>"
                data-penguji-senin="<?= htmlspecialchars($active_pretes['penguji_senin'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                data-penguji-selasa="<?= htmlspecialchars($active_pretes['penguji_selasa'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                data-penguji-rabu="<?= htmlspecialchars($active_pretes['penguji_rabu'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                data-penguji-kamis="<?= htmlspecialchars($active_pretes['penguji_kamis'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                data-penguji-jumat="<?= htmlspecialchars($active_pretes['penguji_jumat'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                ✏️ Edit Penguji & Kuota Periode Ini
            </button>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning" style="margin-bottom:24px;">
    ⚠️ Saat ini belum ada Periode Pretes yang berstatus <strong>Aktif</strong>. Mahasiswa belum bisa mendaftar sampai Anda mengaktifkan salah satu periode.
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     CARD 2: DAFTAR SEMUA PERIODE PRETES
     ═══════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0; font-size:16px;">📋 Daftar Semua Periode Pretes (<?= count($all_pretes) ?>)</h3>
        <button type="button" class="btn btn-sm btn-primary" onclick="toggleFormTambah()" id="btn-toggle-tambah" style="padding:6px 14px; font-weight:600;">
            ➕ Tambah Periode Baru
        </button>
    </div>
    <div class="card-body">
        <?php if (empty($all_pretes)): ?>
            <div class="empty-state">
                <div class="icon">📅</div>
                <h3>Belum ada periode pretes</h3>
                <p>Klik tombol <strong>Tambah Periode Baru</strong> untuk memulai.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th>Tahun Ajaran</th>
                        <th>Jam</th>
                        <th>Kuota Hari (Sn-Jm)</th>
                        <th>Status</th>
                        <th>Dibuat Pada</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_pretes as $p): ?>
                    <tr <?= ($p['status'] === 'aktif') ? 'style="background:#f0fdf4;"' : '' ?>>
                        <td>
                            <strong><?= sanitize($p['periode']) ?></strong>
                            <?php if ($p['status'] === 'aktif'): ?>
                                <span class="badge badge-success" style="font-size:10px; margin-left:4px;">Aktif</span>
                            <?php endif; ?>
                        </td>
                        <td><?= sanitize($p['tahun_ajaran']) ?></td>
                        <td><span class="badge badge-info"><?= sanitize($p['jam'] ?? '13.00 WIB') ?></span></td>
                        <td>
                            <div style="font-size:12px; color:#475569; display:grid; grid-template-columns:repeat(5, auto); gap:6px;">
                                <span>Sn: <b><?= $p['kuota_senin'] ?? 0 ?></b></span>
                                <span>Sl: <b><?= $p['kuota_selasa'] ?? 0 ?></b></span>
                                <span>Rb: <b><?= $p['kuota_rabu'] ?? 0 ?></b></span>
                                <span>Km: <b><?= $p['kuota_kamis'] ?? 0 ?></b></span>
                                <span>Jm: <b><?= $p['kuota_jumat'] ?? 0 ?></b></span>
                            </div>
                        </td>
                        <td>
                            <?php if ($p['status'] === 'aktif'): ?>
                                <span class="badge badge-success">Aktif</span>
                            <?php else: ?>
                                <span class="badge badge-secondary"><?= ucfirst($p['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('d M Y H:i', strtotime($p['created_at'])) ?></td>
                        <td>
                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                <?php if ($p['status'] !== 'aktif'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="set_active">
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success" title="Jadikan Periode Aktif">✅ Aktifkan</button>
                                </form>
                                <?php endif; ?>

                                <button type="button" class="btn btn-sm btn-warning btn-edit-pretes"
                                    data-id="<?= $p['id'] ?>"
                                    data-periode="<?= htmlspecialchars($p['periode'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-tahun="<?= htmlspecialchars($p['tahun_ajaran'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-jam="<?= htmlspecialchars($p['jam'] ?? '13.00 WIB (Jam 1)', ENT_QUOTES, 'UTF-8') ?>"
                                    data-status="<?= htmlspecialchars($p['status'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-kuota-senin="<?= (int)$p['kuota_senin'] ?>"
                                    data-kuota-selasa="<?= (int)$p['kuota_selasa'] ?>"
                                    data-kuota-rabu="<?= (int)$p['kuota_rabu'] ?>"
                                    data-kuota-kamis="<?= (int)$p['kuota_kamis'] ?>"
                                    data-kuota-jumat="<?= (int)$p['kuota_jumat'] ?>"
                                    data-penguji-senin="<?= htmlspecialchars($p['penguji_senin'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-penguji-selasa="<?= htmlspecialchars($p['penguji_selasa'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-penguji-rabu="<?= htmlspecialchars($p['penguji_rabu'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-penguji-kamis="<?= htmlspecialchars($p['penguji_kamis'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-penguji-jumat="<?= htmlspecialchars($p['penguji_jumat'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    ✏️ Edit
                                </button>

                                <form method="POST" onsubmit="return confirm('Hapus periode pretes ini?');" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Hapus">🗑️</button>
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

<!-- ═══════════════════════════════════════════════════════════════
     CARD 3: TAMBAH PERIODE PRETES BARU (COLLAPSIBLE / OPSIONAL)
     ═══════════════════════════════════════════════════════════════ -->
<div class="card" id="card-tambah-periode" style="display:none; margin-bottom:24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span>➕ Tambah Periode Pretes Baru</span>
        <button type="button" onclick="toggleFormTambah()" style="background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create_periode">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px; margin-bottom:16px;">
                <div class="form-group">
                    <label>Nama Periode Pretes *</label>
                    <input type="text" name="periode" placeholder="Contoh: Pretes 2026/2027 Ganjil" required style="width:100%;padding:9px;border:1.5px solid #cbd5e1;border-radius:6px;">
                </div>
                <div class="form-group">
                    <label>Tahun Ajaran *</label>
                    <select name="tahun_ajaran" required style="width:100%;padding:9px;border:1.5px solid #cbd5e1;border-radius:6px;">
                        <option value="">-- Pilih Tahun Ajaran --</option>
                        <?php for($y=2024; $y<=2035; $y++): ?>
                        <option value="<?= $y . ' - ' . ($y+1) ?>"><?= $y . ' - ' . ($y+1) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Jam Pelaksanaan</label>
                    <input type="text" name="jam" value="13.00 WIB (Jam 1)" required style="width:100%;padding:9px;border:1.5px solid #cbd5e1;border-radius:6px;">
                </div>
                <div class="form-group">
                    <label>Status Periode</label>
                    <select name="status" style="width:100%;padding:9px;border:1.5px solid #cbd5e1;border-radius:6px;">
                        <option value="aktif">Aktif (Langsung Buka Pendaftaran)</option>
                        <option value="nonaktif">Nonaktif</option>
                    </select>
                </div>
            </div>

            <label style="font-weight:700; display:block; margin-bottom:8px; font-size:14px; color:#1e293b;">
                Tentukan Penguji & Kuota Hari (Senin - Jumat):
            </label>
            <div style="display:grid;grid-template-columns:repeat(5, 1fr);gap:14px; background:#f8fafc; padding:16px; border-radius:10px; border:1px solid #e2e8f0; margin-bottom:16px; overflow-x:auto;">
                <?php
                renderPengujiSelect('senin', $tutorsList, false);
                renderPengujiSelect('selasa', $tutorsList, false);
                renderPengujiSelect('rabu', $tutorsList, false);
                renderPengujiSelect('kamis', $tutorsList, false);
                renderPengujiSelect('jumat', $tutorsList, false);
                ?>
            </div>

            <button type="submit" class="btn btn-primary" style="width:auto; padding:10px 22px; font-weight:600;">
                ➕ Simpan Periode Baru
            </button>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL EDIT PERIODE PRETES (LEBAR 1200px - SATU PINTU PENGATURAN)
     ═══════════════════════════════════════════════════════════════ -->
<div id="editPretesModal" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(2px); overflow-y:auto;">
    <div style="background:#fff; max-width:1200px; width:95%; margin:30px auto; border-radius:14px; padding:24px 28px; box-shadow:0 20px 35px rgba(0,0,0,0.25);">
        
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
            <div>
                <h3 style="margin:0; font-size:19px; color:#0f172a; display:flex; align-items:center; gap:8px;">
                    <span>✏️</span> Pengaturan Periode, Dosen Penguji & Kuota Pretes
                </h3>
                <small style="color:#64748b;">Kelola informasi periode serta ketersediaan penguji dan kuota harian dalam satu layar.</small>
            </div>
            <button type="button" class="btn-close-modal" onclick="closePretesModal()" style="background:#f1f5f9; border:none; border-radius:50%; width:32px; height:32px; font-size:20px; line-height:30px; cursor:pointer; color:#475569;">&times;</button>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="edit_periode">
            <input type="hidden" name="id" id="edit_id">

            <!-- Baris Metadata 1 Baris Melebar -->
            <div style="display:grid; grid-template-columns: 2fr 1.5fr 1.5fr 1.2fr; gap:16px; margin-bottom:18px;">
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Nama Periode *</label>
                    <input type="text" name="periode" id="edit_periode" required style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Tahun Ajaran *</label>
                    <select name="tahun_ajaran" id="edit_tahun_ajaran" required style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;">
                        <?php for($y=2024; $y<=2035; $y++): ?>
                        <option value="<?= $y . ' - ' . ($y+1) ?>"><?= $y . ' - ' . ($y+1) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Jam Pelaksanaan</label>
                    <input type="text" name="jam" id="edit_jam" required style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Status Pendaftaran</label>
                    <select name="status" id="edit_status" style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;font-weight:600;">
                        <option value="aktif">Aktif</option>
                        <option value="nonaktif">Nonaktif</option>
                        <option value="selesai">Selesai</option>
                    </select>
                </div>
            </div>

            <!-- Baris 5 Hari Melebar (Grid 5 Kolom) -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <strong style="color:#0f172a; font-size:14px;">👨‍🏫 Dosen Penguji & Kuota Harian (Jam 13.00 WIB):</strong>
                    <small style="color:#64748b;">💡 Kuota otomatis terhitung 30 per dosen penguji (dapat diubah manual).</small>
                </div>

                <div style="display:grid; grid-template-columns:repeat(5, 1fr); gap:14px; overflow-x:auto;">
                    <?php
                    renderPengujiSelect('senin', $tutorsList, true);
                    renderPengujiSelect('selasa', $tutorsList, true);
                    renderPengujiSelect('rabu', $tutorsList, true);
                    renderPengujiSelect('kamis', $tutorsList, true);
                    renderPengujiSelect('jumat', $tutorsList, true);
                    ?>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px; border-top:1px solid #e2e8f0; padding-top:16px;">
                <button type="button" class="btn btn-secondary" onclick="closePretesModal()" style="padding:9px 20px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding:9px 24px; font-weight:600;">💾 Simpan Pengaturan</button>
            </div>
        </form>
    </div>
</div>

<script>
// Fungsi hitung kuota berdasarkan dosen penguji (1 penguji = 30 peserta)
window.calculateQuota = function(day, isEdit) {
    const prefix = isEdit ? 'edit_' : '';
    const container = document.getElementById(prefix + 'penguji_' + day + '_container');
    if (!container) return;
    const selects = container.querySelectorAll('select');
    let validPenguji = 0;
    selects.forEach(sel => {
        if (sel.value.trim() !== '') validPenguji++;
    });
    const kuotaInput = document.getElementById(prefix + 'kuota_' + day);
    if (kuotaInput) {
        kuotaInput.value = validPenguji * 30;
    }
};

// Tambah baris dosen penguji
window.addPengujiRow = function(day, isEdit) {
    const prefix = isEdit ? 'edit_' : '';
    const container = document.getElementById(prefix + 'penguji_' + day + '_container');
    if (!container) return;
    const rows = container.querySelectorAll('.tutor-row');
    if (rows.length === 0) return;

    const firstRow = rows[0];
    const newRow = firstRow.cloneNode(true);
    const select = newRow.querySelector('select');
    select.value = '';

    const btn = newRow.querySelector('button');
    btn.textContent = '×';
    btn.classList.remove('btn-success', 'add-tutor-btn');
    btn.classList.add('btn-danger', 'remove-tutor-btn');
    btn.onclick = function() {
        newRow.remove();
        window.calculateQuota(day, isEdit);
    };

    container.appendChild(newRow);
    window.calculateQuota(day, isEdit);
};

// Helper pencocokan opsi Dosen Penguji secara cerdas
window.setPengujiSelectValue = function(selectElem, targetName) {
    if (!selectElem) return;
    if (!targetName || typeof targetName !== 'string' || targetName.trim() === '') {
        selectElem.value = '';
        return;
    }
    targetName = targetName.trim();
    
    // 1. Direct match
    selectElem.value = targetName;
    if (selectElem.value === targetName && selectElem.selectedIndex > 0) return;
    
    // 2. Normalisasi tanda kutip dan spasi
    const norm = function(str) {
        return (str || '').toLowerCase().replace(/[`'’‘]/g, "'").replace(/\s+/g, ' ').trim();
    };
    const targetNorm = norm(targetName);
    for (let opt of selectElem.options) {
        if (opt.value && norm(opt.value) === targetNorm) {
            selectElem.value = opt.value;
            return;
        }
    }
    
    // 3. Substring match (nama dasar sebelum gelar)
    const targetBase = targetNorm.split(',')[0].trim();
    if (targetBase.length >= 4) {
        for (let opt of selectElem.options) {
            if (opt.value) {
                const optBase = norm(opt.value).split(',')[0].trim();
                if (optBase === targetBase || optBase.includes(targetBase) || targetBase.includes(optBase)) {
                    selectElem.value = opt.value;
                    return;
                }
            }
        }
    }
    
    // 4. Fallback: Tambahkan opsi jika belum ada di database user agar nama dosen tidak hilang/kosong
    const newOpt = new Option(targetName, targetName, true, true);
    selectElem.add(newOpt);
    selectElem.value = targetName;
};

// Mengisi baris penguji pada modal edit secara akurat dan bersih
window.populatePenguji = function(day, pengujiStr, kuotaVal) {
    const prefix = 'edit_';
    const container = document.getElementById(prefix + 'penguji_' + day + '_container');
    if (!container) return;

    const rows = container.querySelectorAll('.tutor-row');
    // Sisakan hanya baris pertama
    for (let i = 1; i < rows.length; i++) {
        rows[i].remove();
    }

    const firstSelect = container.querySelector('select');
    firstSelect.value = '';

    let pengujiList = [];
    if (pengujiStr && typeof pengujiStr === 'string' && pengujiStr.trim() !== '') {
        if (pengujiStr.indexOf('|||') !== -1) {
            pengujiList = pengujiStr.split('|||');
        } else {
            // Cek apakah string langsung cocok dengan salah satu dosen (hindari memecah koma pada gelar)
            let directMatch = false;
            for (let opt of firstSelect.options) {
                if (opt.value && opt.value.trim().toLowerCase() === pengujiStr.trim().toLowerCase()) {
                    directMatch = true;
                    break;
                }
            }
            if (directMatch) {
                pengujiList = [pengujiStr.trim()];
            } else {
                pengujiList = pengujiStr.split(',');
            }
        }
    }

    // Bersihkan spasi dan buang item kosong agar tidak membuat baris bertingkat yang kosong
    pengujiList = pengujiList.map(p => p.trim()).filter(p => p !== '');

    if (pengujiList.length > 0) {
        window.setPengujiSelectValue(firstSelect, pengujiList[0]);
    } else {
        firstSelect.value = '';
    }

    // Buat baris baru hanya jika ada lebih dari 1 penguji riil
    for (let i = 1; i < pengujiList.length; i++) {
        const newRow = rows[0].cloneNode(true);
        const sel = newRow.querySelector('select');
        window.setPengujiSelectValue(sel, pengujiList[i]);
        const btn = newRow.querySelector('button');
        btn.textContent = '×';
        btn.classList.remove('btn-success', 'add-tutor-btn');
        btn.classList.add('btn-danger', 'remove-tutor-btn');
        btn.onclick = function() {
            newRow.remove();
            window.calculateQuota(day, true);
        };
        container.appendChild(newRow);
    }

    const kuotaInput = document.getElementById(prefix + 'kuota_' + day);
    if (kuotaInput) {
        kuotaInput.value = kuotaVal !== undefined ? kuotaVal : (pengujiList.length * 30);
    }
};

window.openPretesModal = function(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('edit_periode').value = d.periode || '';
    
    // Pencocokan Tahun Ajaran cerdas (2026 - 2027, 2026/2027, 2026-2027)
    const tahunSelect = document.getElementById('edit_tahun_ajaran');
    if (tahunSelect) {
        const rawTahun = (d.tahun || '').trim();
        const cleanTahun = rawTahun.replace(/[^0-9]/g, '');
        let matched = false;
        
        tahunSelect.value = rawTahun;
        if (tahunSelect.value === rawTahun && tahunSelect.selectedIndex > 0) {
            matched = true;
        } else {
            for (let opt of tahunSelect.options) {
                if (opt.value && opt.value.replace(/[^0-9]/g, '') === cleanTahun) {
                    tahunSelect.value = opt.value;
                    matched = true;
                    break;
                }
            }
        }
        if (!matched && rawTahun !== '') {
            const opt = new Option(rawTahun, rawTahun, true, true);
            tahunSelect.add(opt);
            tahunSelect.value = rawTahun;
        }
    }
    
    document.getElementById('edit_jam').value = d.jam || '13.00 WIB (Jam 1)';
    document.getElementById('edit_status').value = d.status || 'aktif';

    window.populatePenguji('senin', d.penguji_senin || '', d.kuota_senin);
    window.populatePenguji('selasa', d.penguji_selasa || '', d.kuota_selasa);
    window.populatePenguji('rabu', d.penguji_rabu || '', d.kuota_rabu);
    window.populatePenguji('kamis', d.penguji_kamis || '', d.kuota_kamis);
    window.populatePenguji('jumat', d.penguji_jumat || '', d.kuota_jumat);

    const m = document.getElementById('editPretesModal');
    if (m) m.style.display = 'block';
};

window.closePretesModal = function() {
    const m = document.getElementById('editPretesModal');
    if (m) m.style.display = 'none';
};

window.toggleFormTambah = function() {
    const card = document.getElementById('card-tambah-periode');
    if (!card) return;
    const isHidden = card.style.display === 'none';
    card.style.display = isHidden ? 'block' : 'none';
    if (isHidden) {
        card.scrollIntoView({ behavior: 'smooth' });
    }
};

document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-edit-pretes');
        if (btn) {
            const d = btn.dataset;
            window.openPretesModal({
                id: d.id,
                periode: d.periode,
                tahun: d.tahun,
                jam: d.jam,
                status: d.status,
                kuota_senin: btn.getAttribute('data-kuota-senin') || d.kuotaSenin,
                kuota_selasa: btn.getAttribute('data-kuota-selasa') || d.kuotaSelasa,
                kuota_rabu: btn.getAttribute('data-kuota-rabu') || d.kuotaRabu,
                kuota_kamis: btn.getAttribute('data-kuota-kamis') || d.kuotaKamis,
                kuota_jumat: btn.getAttribute('data-kuota-jumat') || d.kuotaJumat,
                penguji_senin: btn.getAttribute('data-penguji-senin') || d.pengujiSenin || '',
                penguji_selasa: btn.getAttribute('data-penguji-selasa') || d.pengujiSelasa || '',
                penguji_rabu: btn.getAttribute('data-penguji-rabu') || d.pengujiRabu || '',
                penguji_kamis: btn.getAttribute('data-penguji-kamis') || d.pengujiKamis || '',
                penguji_jumat: btn.getAttribute('data-penguji-jumat') || d.pengujiJumat || ''
            });
        }
        if (e.target && e.target.id === 'editPretesModal') {
            window.closePretesModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') window.closePretesModal();
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
