<?php
/**
 * LPPAI Corner - Admin: Periode Pendaftaran Tutorial RPL
 * Mengelola periode pendaftaran, jadwal dosen tutor, dan alokasi kuota khusus program RPL.
 */
define('PAGE_TITLE', 'Periode Pendaftaran Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/tutorial-rpl-periods.php';

$error = '';
$periods = [];
$ready = false;
$lecturers = [];
$scheduleRows = [];
$defaults = tutorialRplRegistrationPolicy();
$form = [
    'id' => 0,
    'version' => 1,
    'nama' => 'Tutorial RPL 2026/2027',
    'gelombang' => 'Gelombang 1',
    'opens_at' => $defaults['opens_at'],
    'closes_at' => $defaults['closes_at'],
    'quota' => 0,
    'active_slot' => null
];
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

try {
    $pdo = getDBConnection();
    ensureTutorialRplPeriodTable($pdo);
    $lecturers = $pdo->query("SELECT id, nama_lengkap FROM users WHERE role='dosen' ORDER BY nama_lengkap, id")->fetchAll(PDO::FETCH_ASSOC);
    $ready = true;

    // Handle Form POST Actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) {
            http_response_code(403);
            throw new InvalidArgumentException('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.');
        }

        $action = $_POST['action'] ?? 'save_period';

        // 1. Jadikan Periode Aktif Langsung dari Tabel
        if ($action === 'set_active') {
            $setActiveId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$setActiveId) {
                throw new InvalidArgumentException('ID periode tidak valid.');
            }
            $tutors = tutorialRplPeriodTutors($pdo, $setActiveId);
            if (empty($tutors)) {
                throw new InvalidArgumentException('Periode belum memiliki jadwal dosen dan kuota. Tambahkan minimal satu dosen terlebih dahulu.');
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec("UPDATE tutorial_rpl_registration_periods SET active_slot = NULL WHERE active_slot = 1");
                $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
                $stmt = $pdo->prepare("UPDATE tutorial_rpl_registration_periods SET active_slot = 1, updated_by = ?, updated_at = ?, version = version + 1 WHERE id = ?");
                $stmt->execute([getCurrentUser()['id'], $now, $setActiveId]);
                $pdo->commit();
                $_SESSION['rpl_period_saved'] = 'Periode berhasil diaktifkan!';
                header('Location: ' . BASE_URL . '/admin/tutorial-rpl-periode.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

        // 2. Hapus Periode
        } elseif ($action === 'delete') {
            $deleteId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($deleteId) {
                $hasRegistrations = tutorialRplTableExists($pdo, 'tutorial_rpl_registrations');
                if ($hasRegistrations) {
                    $checkReg = $pdo->prepare("SELECT COUNT(*) FROM tutorial_rpl_registrations WHERE period_id = ?");
                    $checkReg->execute([$deleteId]);
                    if ((int)$checkReg->fetchColumn() > 0) {
                        throw new InvalidArgumentException('Periode tidak dapat dihapus karena sudah memiliki mahasiswa terdaftar.');
                    }
                }
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM tutorial_rpl_period_tutors WHERE period_id = ?")->execute([$deleteId]);
                    $pdo->prepare("DELETE FROM tutorial_rpl_registration_periods WHERE id = ?")->execute([$deleteId]);
                    $pdo->commit();
                    $_SESSION['rpl_period_saved'] = 'Periode berhasil dihapus.';
                    header('Location: ' . BASE_URL . '/admin/tutorial-rpl-periode.php');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }

        // 3. Simpan Periode (Create atau Edit)
        } else {
            $scheduleRows = is_array($_POST['schedules'] ?? null) ? $_POST['schedules'] : [];
            foreach (array_keys($form) as $key) {
                if (isset($_POST[$key]) && !is_string($_POST[$key])) {
                    throw new InvalidArgumentException('Format data periode tidak valid.');
                }
            }
            $form = array_replace($form, array_intersect_key($_POST, $form));
            $form['active_slot'] = ($_POST['active_slot'] ?? '') === '1' ? '1' : null;
            if (($_POST['schedules_complete'] ?? '') !== '1') {
                throw new InvalidArgumentException('Formulir dosen belum terkirim lengkap. Muat ulang halaman dan coba kembali dengan jumlah baris lebih sedikit.');
            }
            tutorialRplSavePeriod($pdo, array_replace($_POST, ['schedules' => $_POST['schedules'] ?? []]), getCurrentUser());
            $_SESSION['rpl_period_saved'] = true;
            header('Location: ' . BASE_URL . '/admin/tutorial-rpl-periode.php');
            exit;
        }
    }

    $periods = $pdo->query('SELECT * FROM tutorial_rpl_registration_periods ORDER BY opens_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);

    // Mode Edit Periode Tertentu
    if (isset($_GET['edit'])) {
        $id = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $found = false;
        foreach ($periods as $period) {
            if ($id && (int)$period['id'] === $id) {
                $form = $period;
                $scheduleRows = tutorialRplPeriodTutors($pdo, $id);
                $found = true;
                break;
            }
        }
        if (!$found) {
            http_response_code(404);
            $error = 'Periode tidak ditemukan.';
        }
    }
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('RPL registration period: ' . $e->getMessage());
    $error = 'Periode belum dapat disimpan atau dimuat. Muat ulang halaman; pastikan tidak ada periode aktif lain.';
}

// Keep the history visible when a submitted form fails validation
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '') {
    try {
        $periods = $pdo->query('SELECT * FROM tutorial_rpl_registration_periods ORDER BY opens_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('RPL period list: ' . $e->getMessage());
    }
}

$saved = $_SESSION['rpl_period_saved'] ?? false;
unset($_SESSION['rpl_period_saved']);

// Identifikasi Periode Aktif
$activePeriod = null;
if (!empty($periods)) {
    foreach ($periods as $p) {
        if ((int)$p['active_slot'] === 1) {
            $activePeriod = $p;
            break;
        }
    }
}

// Rekap Kuota Harian & Dosen per Periode
$allPeriodTutors = [];
if (!empty($periods) && $ready) {
    try {
        $stmtTutors = $pdo->query("SELECT period_id, hari, SUM(quota) as total_quota, COUNT(*) as tutor_count FROM tutorial_rpl_period_tutors GROUP BY period_id, hari");
        foreach ($stmtTutors->fetchAll(PDO::FETCH_ASSOC) as $tRow) {
            $allPeriodTutors[(int)$tRow['period_id']][$tRow['hari']] = [
                'quota' => (int)$tRow['total_quota'],
                'count' => (int)$tRow['tutor_count']
            ];
        }
    } catch (Throwable $e) {}
}

// Hitung Pendaftar per Hari untuk Periode Aktif
$registeredCounts = [];
if ($activePeriod && $ready && tutorialRplTableExists($pdo, 'tutorial_rpl_registrations')) {
    try {
        $stmtCount = $pdo->prepare("SELECT hari_pilihan, COUNT(*) AS cnt FROM tutorial_rpl_registrations WHERE period_id = ? GROUP BY hari_pilihan");
        $stmtCount->execute([$activePeriod['id']]);
        foreach ($stmtCount->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
            if (!empty($cRow['hari_pilihan'])) {
                $registeredCounts[$cRow['hari_pilihan']] = (int)$cRow['cnt'];
            }
        }
    } catch (Throwable $e) {}
}

$dayShorts = [
    'Senin' => 'Sn',
    'Selasa' => 'Sl',
    'Rabu' => 'Rb',
    'Kamis' => 'Km',
    'Jumat' => 'Jm',
    'Sabtu' => 'Sb',
    'Minggu' => 'Mg'
];

$renderSchedule = static function($index, $row) use ($lecturers, $escape) {
    $row = is_array($row) ? array_filter($row, 'is_scalar') : [];
    ?>
    <div class="rpl-tutor-row" style="display:grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 10px; align-items: end; margin-bottom: 12px; background: #fff; padding: 10px 12px; border-radius: 8px; border: 1px solid #e2e8f0;">
        <div>
            <label style="font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Dosen Tutor *</label>
            <select class="form-control" name="schedules[<?= $escape($index) ?>][dosen_id]" required style="width: 100%; padding: 8px 10px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;">
                <option value="">- Pilih Dosen Tutor -</option>
                <?php if (!empty($row['dosen_id']) && !in_array((int)$row['dosen_id'], array_map('intval', array_column($lecturers, 'id')), true)): ?>
                    <option value="<?= (int)$row['dosen_id'] ?>" selected>Dosen #<?= (int)$row['dosen_id'] ?> (akun tidak tersedia)</option>
                <?php endif; ?>
                <?php foreach ($lecturers as $lecturer): ?>
                    <option value="<?= (int)$lecturer['id'] ?>" <?= (int)($row['dosen_id'] ?? 0) === (int)$lecturer['id'] ? 'selected' : '' ?>>
                        <?= $escape($lecturer['nama_lengkap']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Hari Bimbingan *</label>
            <select class="form-control" name="schedules[<?= $escape($index) ?>][hari]" required style="width: 100%; padding: 8px 10px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;">
                <option value="">- Pilih Hari -</option>
                <?php foreach (TUTORIAL_RPL_DAYS as $day): ?>
                    <option value="<?= $escape($day) ?>" <?= ($row['hari'] ?? '') === $day ? 'selected' : '' ?>><?= $escape($day) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Kuota Peserta *</label>
            <input class="form-control" type="number" min="1" max="1000000" name="schedules[<?= $escape($index) ?>][quota]" required value="<?= $escape($row['quota'] ?? 30) ?>" style="width: 100%; padding: 8px 10px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 14px; font-weight: 600;">
        </div>
        <div>
            <button class="btn btn-sm btn-danger" type="button" data-remove-rpl-tutor aria-label="Hapus baris dosen" style="padding: 8px 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;" title="Hapus baris dosen ini">
                🗑️ Hapus
            </button>
        </div>
    </div>
    <?php
};

include __DIR__ . '/../includes/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger" role="alert"><?= $escape($error) ?></div>
<?php endif; ?>

<?php if ($saved): ?>
    <div class="alert alert-success" role="status">
        <?= is_string($saved) ? $escape($saved) : 'Periode berhasil disimpan.' ?>
    </div>
<?php endif; ?>

<!-- Informasi Panduan Singkat -->
<div class="alert alert-info" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px; background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; border-radius:10px;">
    <div style="font-size:14px;">
        ℹ️ Periode aktif mengatur jadwal buka/tutup pendaftaran (WIB), dosen tutor, dan ketersediaan kuota harian untuk mahasiswa program RPL.
    </div>
    <div>
        <a href="<?= BASE_URL ?>/admin/tutorial-rpl-pendaftaran.php" class="btn btn-sm btn-primary" style="padding:6px 14px; font-weight:600; display:inline-flex; align-items:center; gap:6px;">
            📝 Buka Pendaftaran Peserta RPL
        </a>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     CARD 1: STATUS PERIODE TUTORIAL RPL AKTIF & RINGKASAN KUOTA
     ═══════════════════════════════════════════════════════════════ -->
<?php if ($activePeriod): ?>
<?php
$activeState = tutorialRplRegistrationWindow(null, tutorialRplPeriodPolicy($activePeriod));
$activeLabels = [
    'inactive' => 'Nonaktif',
    'soon' => 'Terjadwal (Belum Buka)',
    'open' => 'Dibuka Sesuai Jadwal',
    'closed' => 'Ditutup Sesuai Jadwal'
];
$activeTutors = tutorialRplPeriodTutors($pdo, (int)$activePeriod['id']);
$activeDayTotals = [];
foreach ($activeTutors as $tutor) {
    $h = $tutor['hari'];
    if (!isset($activeDayTotals[$h])) {
        $activeDayTotals[$h] = ['kuota' => 0, 'count' => 0];
    }
    $activeDayTotals[$h]['kuota'] += (int)$tutor['quota'];
    $activeDayTotals[$h]['count']++;
}
?>
<div class="card" style="margin-bottom: 24px; border-left: 5px solid var(--primary); background: #f8fafc;">
    <div class="card-body" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:4px; flex-wrap:wrap;">
                <h3 style="margin:0; font-size:18px; color:#1e293b;">
                    🌟 Periode Tutorial RPL Aktif: <strong><?= $escape($activePeriod['nama']) ?></strong>
                </h3>
                <span class="badge badge-success" style="font-size:12px; padding:3px 10px;">✅ Aktif Menerima Pendaftaran</span>
                <span class="badge badge-info" style="font-size:12px; padding:3px 10px;"><?= $escape($activePeriod['gelombang']) ?></span>
                <span class="badge" style="background:#e0f2fe; color:#0369a1; font-size:12px; padding:3px 10px; border:1px solid #bae6fd;">Khusus Program RPL</span>
            </div>
            <p style="margin:0 0 12px; color:#64748b; font-size:14px;">
                Jadwal Pendaftaran: <strong><?= $escape(tutorialRplPeriodSchedule($activePeriod)) ?></strong> |
                Status: <strong><?= $escape($activeLabels[$activeState] ?? $activeState) ?></strong> |
                Batas Total Kuota: <strong><?= (int)$activePeriod['quota'] === 0 ? 'Mengikuti total kuota dosen' : (int)$activePeriod['quota'] . ' mahasiswa' ?></strong>
            </p>

            <!-- Ringkasan Kuota Harian -->
            <div style="display:flex; gap:10px; flex-wrap:wrap; font-size:13px;">
                <?php
                $hasAnyDay = false;
                foreach (TUTORIAL_RPL_DAYS as $day):
                    $kuota = $activeDayTotals[$day]['kuota'] ?? 0;
                    $dosenCnt = $activeDayTotals[$day]['count'] ?? 0;
                    $terisi = $registeredCounts[$day] ?? 0;
                    $sisa = $kuota - $terisi;
                    if ($kuota === 0 && $terisi === 0) continue;
                    $hasAnyDay = true;
                ?>
                <div style="background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:6px 12px; min-width:140px; box-shadow:0 1px 2px rgba(0,0,0,0.03);">
                    <div style="font-weight:700; color:#0f172a; margin-bottom:2px; display:flex; justify-content:space-between; align-items:center;">
                        <span><?= $escape($day) ?></span>
                        <span style="font-size:11px; background:#e2e8f0; color:#475569; padding:1px 5px; border-radius:4px; font-weight:600;"><?= $dosenCnt ?> Tutor</span>
                    </div>
                    <div style="font-size:12px; color:#475569;">
                        Kuota: <b><?= $kuota ?></b> | Terisi: <b><?= $terisi ?></b>
                    </div>
                    <div style="font-size:12px; font-weight:bold; color:<?= $sisa < 0 ? '#ef4444' : '#10b981' ?>; margin-top:2px;">
                        Sisa Kursi: <?= $sisa ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (!$hasAnyDay): ?>
                <div style="color:#64748b; font-style:italic;">Belum ada kuota dosen terdaftar untuk periode aktif ini.</div>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <!-- Tombol Cepat Membuka Edit untuk Periode Aktif -->
            <a href="<?= BASE_URL ?>/admin/tutorial-rpl-periode.php?edit=<?= (int)$activePeriod['id'] ?>" class="btn btn-warning" style="padding:12px 22px; font-size:14px; font-weight:700; display:inline-flex; align-items:center; gap:8px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                ✏️ Edit Penguji & Kuota Periode Ini
            </a>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning" style="margin-bottom:24px;">
    ⚠️ Saat ini belum ada Periode Tutorial RPL yang berstatus <strong>Aktif</strong>. Mahasiswa RPL belum dapat mendaftar sampai Anda mengaktifkan salah satu periode.
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     CARD 2: DAFTAR SEMUA PERIODE TUTORIAL RPL
     ═══════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <h3 style="margin:0; font-size:16px;">📋 Daftar Semua Periode Tutorial RPL (<?= count($periods) ?>)</h3>
        <button type="button" class="btn btn-sm btn-primary" onclick="toggleFormTambah()" id="btn-toggle-tambah" style="padding:6px 14px; font-weight:600;">
            ➕ Tambah Periode Baru
        </button>
    </div>
    <div class="card-body">
        <?php if (empty($periods)): ?>
            <div class="empty-state" style="text-align:center; padding:40px 20px;">
                <div class="icon" style="font-size:42px; margin-bottom:10px;">📅</div>
                <h3 style="margin:0 0 8px; font-size:18px; color:#334155;">Belum ada periode tutorial RPL</h3>
                <p style="margin:0; color:#64748b;">Klik tombol <strong>Tambah Periode Baru</strong> di atas untuk memulai.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th>Gelombang</th>
                        <th>Target Jalur</th>
                        <th>Kuota Hari (Sn-Mg)</th>
                        <th>Batas Kuota</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $labels = [
                        'inactive' => 'Nonaktif',
                        'soon' => 'Terjadwal',
                        'open' => 'Dibuka sesuai jadwal',
                        'closed' => 'Ditutup sesuai jadwal'
                    ];
                    foreach ($periods as $period):
                        $state = tutorialRplRegistrationWindow(null, tutorialRplPeriodPolicy($period));
                        $isActive = (int)$period['active_slot'] === 1;
                        $pDayData = $allPeriodTutors[(int)$period['id']] ?? [];
                    ?>
                    <tr <?= $isActive ? 'style="background:#f0fdf4;"' : '' ?>>
                        <td>
                            <strong><?= $escape($period['nama']) ?></strong>
                            <?php if ($isActive): ?>
                                <span class="badge badge-success" style="font-size:10px; margin-left:4px;">Aktif</span>
                            <?php endif; ?>
                            <div style="font-size:11.5px; color:#64748b; margin-top:2px;">
                                📅 <?= $escape(tutorialRplPeriodSchedule($period)) ?>
                            </div>
                        </td>
                        <td><?= $escape($period['gelombang']) ?></td>
                        <td><span class="badge" style="background:#e0f2fe; color:#0369a1; font-size:11px;">Khusus RPL</span></td>
                        <td>
                            <div style="font-size:12px; color:#475569; display:flex; flex-wrap:wrap; gap:5px;">
                                <?php if (empty($pDayData)): ?>
                                    <span style="color:#94a3b8; font-size:11.5px; font-style:italic;">Belum ada jadwal dosen</span>
                                <?php else: ?>
                                    <?php foreach ($dayShorts as $fullDay => $shortDay): ?>
                                        <?php if (isset($pDayData[$fullDay])): ?>
                                            <span style="background:#f1f5f9; padding:2px 6px; border-radius:4px; border:1px solid #e2e8f0;" title="<?= $fullDay ?>: <?= $pDayData[$fullDay]['quota'] ?> mahasiswa (<?= $pDayData[$fullDay]['count'] ?> dosen)">
                                                <?= $shortDay ?>: <b><?= $pDayData[$fullDay]['quota'] ?></b>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-info" style="font-size:11.5px;">
                                <?= (int)$period['quota'] === 0 ? 'Ikuti dosen' : (int)$period['quota'] . ' mhs' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($isActive): ?>
                                <span class="badge badge-success">Aktif</span>
                                <div style="margin-top:3px;"><span class="badge badge-primary" style="font-size:10.5px;"><?= $escape($labels[$state] ?? $state) ?></span></div>
                            <?php else: ?>
                                <span class="badge badge-secondary">Nonaktif</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                <?php if (!$isActive): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="set_active">
                                    <input type="hidden" name="id" value="<?= (int)$period['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success" title="Jadikan Periode Aktif">✅ Aktifkan</button>
                                </form>
                                <?php endif; ?>

                                <a class="btn btn-sm btn-warning" href="<?= BASE_URL ?>/admin/tutorial-rpl-periode.php?edit=<?= (int)$period['id'] ?>" title="Edit Periode">
                                    ✏️ Edit
                                </a>

                                <form method="POST" onsubmit="return confirm('Hapus periode tutorial RPL ini? Periode yang sudah memiliki pendaftar tidak dapat dihapus.');" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$period['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Hapus Periode">🗑️</button>
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
     CARD 3: FORM TAMBAH / EDIT PERIODE TUTORIAL RPL
     ═══════════════════════════════════════════════════════════════ -->
<?php if ($ready): ?>
<?php
$isEditMode = (int)$form['id'] > 0;
?>
<div class="card" id="card-form-periode" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0; font-size:16px;">
            <?= $isEditMode ? '✏️ Edit Periode Tutorial RPL: ' . $escape($form['nama']) : '➕ Buat Periode Tutorial RPL Baru' ?>
        </h3>
        <?php if ($isEditMode): ?>
            <a href="<?= BASE_URL ?>/admin/tutorial-rpl-periode.php" class="btn btn-sm btn-secondary" style="font-weight:600;">✖ Batal Edit</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="POST" action="<?= BASE_URL ?>/admin/tutorial-rpl-periode.php">
            <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_period">
            <input type="hidden" name="id" value="<?= $escape($form['id']) ?>">
            <input type="hidden" name="version" value="<?= $escape($form['version']) ?>">

            <!-- Grid Metadata Periode -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 20px;">
                <div class="form-group" style="margin:0;">
                    <label for="nama" style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Nama Periode Tutorial RPL *</label>
                    <input class="form-control" id="nama" name="nama" maxlength="100" required value="<?= $escape($form['nama']) ?>" placeholder="Contoh: Tutorial RPL 2026/2027" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:14px; box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label for="gelombang" style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Gelombang / Semester *</label>
                    <input class="form-control" id="gelombang" name="gelombang" maxlength="50" required value="<?= $escape($form['gelombang']) ?>" placeholder="Contoh: Gelombang 1" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:14px; box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label for="opens_at" style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Buka Pendaftaran (WIB) *</label>
                    <input class="form-control" type="datetime-local" step="1" id="opens_at" name="opens_at" required value="<?= $escape(str_replace(' ', 'T', $form['opens_at'])) ?>" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:14px; box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label for="closes_at" style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Tutup Pendaftaran (WIB) *</label>
                    <input class="form-control" type="datetime-local" step="1" id="closes_at" name="closes_at" required value="<?= $escape(str_replace(' ', 'T', $form['closes_at'])) ?>" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:14px; box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label for="quota" style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Batas Total Pendaftar</label>
                    <input class="form-control" type="number" id="quota" name="quota" min="0" max="1000000" required value="<?= $escape($form['quota']) ?>" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    <small style="color:#64748b; font-size:11.5px;">0 = mengikuti total kuota dosen harian.</small>
                </div>
                <div class="form-group" style="margin:0; display:flex; flex-direction:column; justify-content:center;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Status Periode</label>
                    <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; margin-top:4px;">
                        <input type="checkbox" name="active_slot" value="1" <?= (int)$form['active_slot'] === 1 ? 'checked' : '' ?> style="width:18px; height:18px; cursor:pointer;">
                        <span style="font-size:14px; font-weight:600; color:#0f172a;">Jadikan Periode Aktif</span>
                    </label>
                    <small style="color:#64748b; font-size:11.5px;">Hanya satu periode aktif yang menerima pendaftaran.</small>
                </div>
            </div>

            <!-- Kontainer Dosen Tutor & Kuota Harian -->
            <fieldset style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:18px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
                    <div>
                        <legend style="font-weight:700; font-size:15px; color:#0f172a; padding:0; margin:0; float:none;">
                            👨‍🏫 Dosen Tutor, Pilihan Hari & Alokasi Kuota
                        </legend>
                        <small style="color:#64748b; font-size:12.5px;">
                            💡 Tentukan dosen tutor yang tersedia beserta pilihan hari dan kapasitas kuota bimbingannya. Mahasiswa RPL akan memilih hari saat pendaftaran.
                        </small>
                    </div>
                    <button class="btn btn-sm btn-success" type="button" id="add-rpl-tutor" style="padding:7px 14px; font-weight:600; display:inline-flex; align-items:center; gap:6px;">
                        ➕ Tambah Dosen & Hari
                    </button>
                </div>

                <?php if (!$lecturers): ?>
                    <div class="alert alert-warning" style="margin-bottom:12px;">
                        ⚠️ Belum ada akun dosen. Tambahkan akun dengan peran dosen melalui menu Kelola Pengguna terlebih dahulu.
                    </div>
                <?php endif; ?>

                <div id="rpl-tutor-rows">
                    <?php foreach (array_values($scheduleRows) as $i => $row) $renderSchedule($i, $row); ?>
                </div>

                <template id="rpl-tutor-template">
                    <?php $renderSchedule('__INDEX__', []); ?>
                </template>
            </fieldset>

            <input type="hidden" name="schedules_complete" value="1">

            <!-- Tombol Aksi Formulir -->
            <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                <button type="submit" class="btn btn-primary" style="padding:10px 24px; font-weight:600; display:inline-flex; align-items:center; gap:8px;">
                    💾 <?= $isEditMode ? 'Simpan Perubahan Periode' : 'Simpan Periode Baru' ?>
                </button>
                <a class="btn btn-secondary" href="<?= BASE_URL ?>/admin/tutorial-rpl-periode.php" style="padding:10px 18px; font-weight:600;">
                    Buat Baru / Batal Edit
                </a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function toggleFormTambah() {
    const card = document.getElementById('card-form-periode');
    if (!card) return;
    if (card.style.display === 'none') {
        card.style.display = 'block';
    }
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

(()=>{
    const rows = document.getElementById('rpl-tutor-rows');
    const add = document.getElementById('add-rpl-tutor');
    const template = document.getElementById('rpl-tutor-template');
    if (!rows || !add || !template) return;
    let index = rows.children.length;
    add.addEventListener('click', () => {
        rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index++)));
    });
    rows.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-rpl-tutor]');
        if (button) button.closest('.rpl-tutor-row').remove();
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
