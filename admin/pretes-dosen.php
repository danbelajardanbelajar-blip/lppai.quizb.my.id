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

        // 1. UPDATE PENGUJI & KUOTA PERIODE AKTIF
        if ($action === 'update_penguji_kuota') {
            $pretes_id = (int)($_POST['pretes_id'] ?? 0);
            if ($pretes_id > 0) {
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

                $stmt = $pdo->prepare("
                    UPDATE master_pretes 
                    SET kuota_senin=?, kuota_selasa=?, kuota_rabu=?, kuota_kamis=?, kuota_jumat=?,
                        penguji_senin=?, penguji_selasa=?, penguji_rabu=?, penguji_kamis=?, penguji_jumat=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $kuota_senin, $kuota_selasa, $kuota_rabu, $kuota_kamis, $kuota_jumat,
                    $penguji_senin, $penguji_selasa, $penguji_rabu, $penguji_kamis, $penguji_jumat,
                    $pretes_id
                ]);

                $message = 'Pengaturan Dosen Penguji dan Kuota Pretes berhasil disimpan!';
                $msgType = 'success';
            }

        // 2. TAMBAH PERIODE PRETES BARU
        } elseif ($action === 'create_periode') {
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

        // 3. EDIT PERIODE PRETES
        } elseif ($action === 'edit_periode') {
            $id           = (int)($_POST['id'] ?? 0);
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

            if ($id <= 0 || empty($periode) || empty($tahun_ajaran)) {
                $message = 'Data tidak valid.';
                $msgType = 'danger';
            } else {
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

                $message = 'Periode Pretes berhasil diperbarui!';
                $msgType = 'success';
            }

        // 4. AKTIFKAN PERIODE
        } elseif ($action === 'set_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->exec("UPDATE master_pretes SET status = 'nonaktif' WHERE status = 'aktif'");
                $pdo->prepare("UPDATE master_pretes SET status = 'aktif' WHERE id = ?")->execute([$id]);
                $message = 'Periode pretes berhasil diaktifkan!';
                $msgType = 'success';
            }

        // 5. HAPUS PERIODE
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

// Fungsi bantu parse string penguji (separator |||)
function parsePengujiList($str) {
    if (empty($str)) return [];
    if (strpos($str, '|||') !== false) {
        return array_filter(array_map('trim', explode('|||', $str)));
    }
    return array_filter(array_map('trim', explode(',', $str)));
}

// Helper render input Dosen Penguji per hari
function renderPengujiSelect($day, $tutorsList, $isEdit = false, $defaultPenguji = []) {
    $idPrefix = $isEdit ? 'edit_' : '';
    if (empty($defaultPenguji)) {
        $defaultPenguji = ['']; // Minimal 1 baris
    }
    ?>
    <div class="form-group" style="margin-bottom:0; display:flex; flex-direction:column; gap:8px;">
        <label style="font-weight:bold; font-size:14px; text-transform:capitalize;"><?= $day ?></label>
        <div id="<?= $idPrefix ?>penguji_<?= $day ?>_container" class="tutor-container" data-day="<?= $day ?>" data-edit="<?= $isEdit ? '1' : '0' ?>">
            <?php foreach($defaultPenguji as $idx => $pengujiName): ?>
            <div class="tutor-row" style="display:flex; gap:4px; margin-bottom:4px;">
                <select name="penguji_<?= $day ?>[]" class="tutor-select" style="flex:1; padding:6px; border:1.5px solid #e5e7eb; border-radius:6px; font-size:13px;" onchange="calculateQuota('<?= $day ?>', <?= $isEdit ? 'true' : 'false' ?>)">
                    <option value="">- Pilih Penguji -</option>
                    <?php foreach($tutorsList as $t): ?>
                    <option value="<?= sanitize($t['nama']) ?>" <?= ($t['nama'] === $pengujiName) ? 'selected' : '' ?>><?= sanitize($t['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($idx === 0): ?>
                <button type="button" class="btn btn-sm btn-success add-tutor-btn" onclick="addPengujiRow('<?= $day ?>', <?= $isEdit ? 'true' : 'false' ?>)" style="padding:0 8px;">+</button>
                <?php else: ?>
                <button type="button" class="btn btn-sm btn-danger remove-tutor-btn" onclick="this.parentElement.remove(); calculateQuota('<?= $day ?>', <?= $isEdit ? 'true' : 'false' ?>);" style="padding:0 8px;">×</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="font-size:12px; color:#64748b; margin-top:-4px;">Kuota Peserta:</div>
        <input type="number" name="kuota_<?= $day ?>" id="<?= $idPrefix ?>kuota_<?= $day ?>" class="kuota-input" data-day="<?= $day ?>" min="0" value="0" style="width:100%;padding:6px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:14px;background:#fff;">
    </div>
    <?php
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= $message ?></div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     CARD 1: PENGATURAN DOSEN PENGUJI & KUOTA (PERIODE AKTIF)
     ═══════════════════════════════════════════════════════════════ -->
<?php if ($active_pretes): ?>
<div class="card" style="margin-bottom: 24px; border-top: 4px solid var(--primary);">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div>
            <h3 style="margin:0; font-size:17px;">👨‍🏫 Pengaturan Dosen Penguji & Kuota Pretes (Periode Aktif)</h3>
            <small style="color:#64748b;">Periode: <strong><?= sanitize($active_pretes['periode']) ?></strong> (<?= sanitize($active_pretes['tahun_ajaran']) ?>) | Jam Ujian: <strong><?= sanitize($active_pretes['jam'] ?? '13.00 WIB (Jam 1)') ?></strong></small>
        </div>
        <span class="badge badge-success" style="font-size:13px; padding:6px 12px;">✅ Status: Aktif Menerima Pendaftaran</span>
    </div>
    <div class="card-body">
        <div class="alert alert-info" style="margin-bottom:16px;">
            💡 <strong>Sistem Perhitungan Kuota:</strong> Tambahkan dosen penguji yang bersedia menguji pada hari tersebut. Kuota otomatis dihitung <strong>30 peserta per dosen penguji</strong> (Anda juga dapat menyesuaikan angka kuota secara manual bila diperlukan).
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_penguji_kuota">
            <input type="hidden" name="pretes_id" value="<?= $active_pretes['id'] ?>">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px; background:#f8fafc; padding:16px; border-radius:10px; border:1px solid #e2e8f0;">
                <?php
                $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
                foreach ($days as $day):
                    $dayLower = strtolower($day);
                    $pengujiStr = $active_pretes["penguji_$dayLower"] ?? '';
                    $currentPenguji = parsePengujiList($pengujiStr);
                    if (empty($currentPenguji)) $currentPenguji = [''];

                    $totalKuota = (int)($active_pretes["kuota_$dayLower"] ?? 0);
                    $terisi = $registeredCounts[$day] ?? 0;
                    $sisaKuota = $totalKuota - $terisi;
                ?>
                <div class="form-group" style="margin-bottom:0; display:flex; flex-direction:column; gap:8px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <label style="font-weight:bold; font-size:15px; margin:0; text-transform:capitalize; color:#1e293b;"><?= $day ?></label>
                        <span style="font-size:11px; background:#e2e8f0; color:#334155; padding:2px 6px; border-radius:4px;">Jam 1 (13.00)</span>
                    </div>

                    <div id="penguji_<?= $dayLower ?>_container" class="tutor-container" data-day="<?= $dayLower ?>">
                        <?php foreach($currentPenguji as $idx => $pName): ?>
                        <div class="tutor-row" style="display:flex; gap:4px; margin-bottom:4px;">
                            <select name="penguji_<?= $dayLower ?>[]" class="tutor-select" style="flex:1; padding:7px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:13px;" onchange="calculateQuota('<?= $dayLower ?>', false)">
                                <option value="">- Pilih Penguji -</option>
                                <?php foreach($tutorsList as $t): ?>
                                <option value="<?= sanitize($t['nama']) ?>" <?= ($t['nama'] === $pName) ? 'selected' : '' ?>><?= sanitize($t['nama']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($idx === 0): ?>
                            <button type="button" class="btn btn-sm btn-success add-tutor-btn" onclick="addPengujiRow('<?= $dayLower ?>', false)" style="padding:0 10px; font-weight:bold;">+</button>
                            <?php else: ?>
                            <button type="button" class="btn btn-sm btn-danger remove-tutor-btn" onclick="this.parentElement.remove(); calculateQuota('<?= $dayLower ?>', false);" style="padding:0 10px; font-weight:bold;">×</button>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="font-size:12px; color:#64748b; margin-top:2px;">Kapasitas / Kuota:</div>
                    <input type="number" name="kuota_<?= $dayLower ?>" id="kuota_<?= $dayLower ?>" value="<?= $totalKuota ?>" min="0" style="width:100%;padding:7px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;background:#fff;font-weight:600;">

                    <div style="display:flex; justify-content:space-between; font-size:12px; margin-top:4px; padding-top:4px; border-top:1px dashed #e2e8f0;">
                        <span>Terdaftar: <b><?= $terisi ?></b></span>
                        <span style="font-weight:600; color: <?= $sisaKuota < 0 ? '#ef4444' : '#10b981' ?>;">
                            Sisa: <?= $sisaKuota ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:18px; padding:10px 24px; font-size:14px; font-weight:600;">
                💾 Simpan Pengaturan Penguji & Kuota
            </button>
        </form>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning">
    ⚠️ Belum ada Periode Pretes yang berstatus <strong>Aktif</strong>. Silakan tambahkan periode baru atau aktifkan salah satu periode di bawah.
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     CARD 2: TAMBAH PERIODE PRETES BARU
     ═══════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">➕ Tambah Periode Pretes Baru</div>
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

            <label style="font-weight:600; display:block; margin-bottom:8px; font-size:14px;">Tentukan Penguji & Kuota Hari (Senin - Jumat):</label>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px; background:#f8fafc; padding:16px; border-radius:10px; border:1px solid #e2e8f0; margin-bottom:16px;">
                <?php
                renderPengujiSelect('senin', $tutorsList, false);
                renderPengujiSelect('selasa', $tutorsList, false);
                renderPengujiSelect('rabu', $tutorsList, false);
                renderPengujiSelect('kamis', $tutorsList, false);
                renderPengujiSelect('jumat', $tutorsList, false);
                ?>
            </div>

            <button type="submit" class="btn btn-primary" style="width:auto; padding:10px 20px;">
                ➕ Tambah Periode Pretes
            </button>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     CARD 3: DAFTAR SEMUA PERIODE PRETES
     ═══════════════════════════════════════════════════════════════ -->
<div class="card">
    <div class="card-header">📋 Daftar Semua Periode Pretes (<?= count($all_pretes) ?>)</div>
    <div class="card-body">
        <?php if (empty($all_pretes)): ?>
            <div class="empty-state">
                <div class="icon">📅</div>
                <h3>Belum ada periode pretes</h3>
                <p>Tambahkan periode pretes melalui form di atas.</p>
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
                    <tr>
                        <td><strong><?= sanitize($p['periode']) ?></strong></td>
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
                                    <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
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
     MODAL EDIT PERIODE PRETES
     ═══════════════════════════════════════════════════════════════ -->
<div id="editPretesModal" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); overflow-y:auto;">
    <div style="background:#fff; max-width:850px; margin:40px auto; border-radius:12px; padding:24px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; font-size:18px;">✏️ Edit Periode Pretes</h3>
            <button type="button" class="btn-close-modal" onclick="closePretesModal()" style="background:none; border:none; font-size:24px; cursor:pointer;">&times;</button>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="edit_periode">
            <input type="hidden" name="id" id="edit_id">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px; margin-bottom:16px;">
                <div class="form-group">
                    <label>Nama Periode *</label>
                    <input type="text" name="periode" id="edit_periode" required style="width:100%;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;">
                </div>
                <div class="form-group">
                    <label>Tahun Ajaran *</label>
                    <select name="tahun_ajaran" id="edit_tahun_ajaran" required style="width:100%;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;">
                        <?php for($y=2024; $y<=2035; $y++): ?>
                        <option value="<?= $y . ' - ' . ($y+1) ?>"><?= $y . ' - ' . ($y+1) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Jam Pelaksanaan</label>
                    <input type="text" name="jam" id="edit_jam" required style="width:100%;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_status" style="width:100%;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;">
                        <option value="aktif">Aktif</option>
                        <option value="nonaktif">Nonaktif</option>
                        <option value="selesai">Selesai</option>
                    </select>
                </div>
            </div>

            <label style="font-weight:600; display:block; margin-bottom:8px; font-size:14px;">Penguji & Kuota Hari (Senin - Jumat):</label>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px; background:#f8fafc; padding:14px; border-radius:10px; border:1px solid #e2e8f0; margin-bottom:16px;">
                <?php
                renderPengujiSelect('senin', $tutorsList, true);
                renderPengujiSelect('selasa', $tutorsList, true);
                renderPengujiSelect('rabu', $tutorsList, true);
                renderPengujiSelect('kamis', $tutorsList, true);
                renderPengujiSelect('jumat', $tutorsList, true);
                ?>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closePretesModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
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

// Mengisi baris penguji pada modal edit
window.populatePenguji = function(day, pengujiStr, kuotaVal) {
    const prefix = 'edit_';
    const container = document.getElementById(prefix + 'penguji_' + day + '_container');
    if (!container) return;

    const rows = container.querySelectorAll('.tutor-row');
    for (let i = 1; i < rows.length; i++) {
        rows[i].remove();
    }

    const firstSelect = container.querySelector('select');
    firstSelect.value = '';

    let pengujiList = [];
    if (pengujiStr) {
        if (pengujiStr.indexOf('|||') !== -1) {
            pengujiList = pengujiStr.split('|||');
        } else {
            pengujiList = pengujiStr.split(',');
        }
    }

    if (pengujiList.length > 0) {
        firstSelect.value = pengujiList[0].trim();
    }

    for (let i = 1; i < pengujiList.length; i++) {
        const newRow = rows[0].cloneNode(true);
        const sel = newRow.querySelector('select');
        sel.value = pengujiList[i].trim();
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
    document.getElementById('edit_tahun_ajaran').value = d.tahun || '';
    document.getElementById('edit_jam').value = d.jam || '13.00 WIB (Jam 1)';
    document.getElementById('edit_status').value = d.status || 'aktif';

    window.populatePenguji('senin', d.penguji_senin, d.kuota_senin);
    window.populatePenguji('selasa', d.penguji_selasa, d.kuota_selasa);
    window.populatePenguji('rabu', d.penguji_rabu, d.kuota_rabu);
    window.populatePenguji('kamis', d.penguji_kamis, d.kuota_kamis);
    window.populatePenguji('jumat', d.penguji_jumat, d.kuota_jumat);

    const m = document.getElementById('editPretesModal');
    if (m) m.style.display = 'block';
};

window.closePretesModal = function() {
    const m = document.getElementById('editPretesModal');
    if (m) m.style.display = 'none';
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
                kuota_senin: d.kuotaSenin,
                kuota_selasa: d.kuotaSelasa,
                kuota_rabu: d.kuotaRabu,
                kuota_kamis: d.kuotaKamis,
                kuota_jumat: d.kuotaJumat,
                penguji_senin: d.pengujiSenin,
                penguji_selasa: d.pengujiSelasa,
                penguji_rabu: d.pengujiRabu,
                penguji_kamis: d.pengujiKamis,
                penguji_jumat: d.pengujiJumat
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
