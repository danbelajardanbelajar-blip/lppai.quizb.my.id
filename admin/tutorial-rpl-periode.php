<?php
/**
 * LPPAI Corner - Admin: Periode Pendaftaran Tutorial RPL
 * Antarmuka satu pintu (modal pop-up 1200px & grid kuota harian) identik dengan Dosen Penguji Pretes.
 * Khusus untuk keperluan Tutorial RPL Online (terpisah dari Pretes).
 */
define('PAGE_TITLE', 'Periode Pendaftaran Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/tutorial-rpl-periods.php';

if (!function_exists('sanitize')) {
    function sanitize($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

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
                $_SESSION['rpl_period_saved'] = 'Periode tutorial RPL berhasil diaktifkan!';
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

        // 3. Simpan Periode (Create atau Edit Satu Pintu)
        } else {
            foreach (array_keys($form) as $key) {
                if (isset($_POST[$key]) && !is_string($_POST[$key])) {
                    throw new InvalidArgumentException('Format data periode tidak valid.');
                }
            }

            // Parsing schedules: baik dari array direct $_POST['schedules'] maupun dari UI Grid Hari (penguji_senin[])
            $schedules = [];
            if (isset($_POST['schedules']) && is_array($_POST['schedules'])) {
                $schedules = $_POST['schedules'];
            } else {
                $dayMapping = [
                    'senin' => 'Senin',
                    'selasa' => 'Selasa',
                    'rabu' => 'Rabu',
                    'kamis' => 'Kamis',
                    'jumat' => 'Jumat',
                    'sabtu' => 'Sabtu',
                    'minggu' => 'Minggu',
                    'ahad' => 'Minggu'
                ];
                foreach ($dayMapping as $key => $capitalDay) {
                    $tutorInputs = $_POST['penguji_' . $key] ?? ($_POST['tutor_' . $key] ?? []);
                    if (!is_array($tutorInputs)) continue;
                    $cleanTutorIds = [];
                    foreach ($tutorInputs as $tid) {
                        $tVal = filter_var($tid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($tVal) {
                            $cleanTutorIds[] = $tVal;
                        }
                    }
                    $cleanTutorIds = array_unique($cleanTutorIds);
                    $totalDayQuota = (int)($_POST['kuota_' . $key] ?? 0);
                    $count = count($cleanTutorIds);
                    if ($count > 0) {
                        $baseQuota = ($totalDayQuota > 0) ? (int)floor($totalDayQuota / $count) : 30;
                        if ($baseQuota < 1) $baseQuota = 1;
                        $remainder = ($totalDayQuota > 0) ? ($totalDayQuota - ($baseQuota * $count)) : 0;
                        foreach ($cleanTutorIds as $idx => $tId) {
                            $q = $baseQuota + ($idx === 0 ? max(0, $remainder) : 0);
                            $schedules[] = [
                                'dosen_id' => $tId,
                                'hari' => $capitalDay,
                                'quota' => $q
                            ];
                        }
                    }
                }
            }

            $form = array_replace($form, array_intersect_key($_POST, $form));
            $form['active_slot'] = ($_POST['active_slot'] ?? '') === '1' ? '1' : null;
            if (($_POST['schedules_complete'] ?? '') !== '1') {
                throw new InvalidArgumentException('Formulir dosen belum terkirim lengkap. Muat ulang halaman dan coba kembali dengan jumlah baris lebih sedikit.');
            }
            tutorialRplSavePeriod($pdo, array_replace($_POST, ['schedules' => $schedules]), getCurrentUser());
            $_SESSION['rpl_period_saved'] = true;
            header('Location: ' . BASE_URL . '/admin/tutorial-rpl-periode.php');
            exit;
        }
    }

    $periods = $pdo->query('SELECT * FROM tutorial_rpl_registration_periods ORDER BY opens_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);

    // Mode Edit Periode Tertentu (kompatibilitas link direct atau uji)
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

// Ambil data dosen untuk selektor
$tutorsList = [];
foreach ($lecturers as $l) {
    $tutorsList[] = [
        'id' => (int)$l['id'],
        'nama' => $l['nama_lengkap']
    ];
}

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
        $stmtTutors = $pdo->query("SELECT period_id, hari, dosen_id, quota FROM tutorial_rpl_period_tutors ORDER BY id ASC");
        foreach ($stmtTutors->fetchAll(PDO::FETCH_ASSOC) as $tRow) {
            $pid = (int)$tRow['period_id'];
            $hari = $tRow['hari'];
            if (!isset($allPeriodTutors[$pid][$hari])) {
                $allPeriodTutors[$pid][$hari] = [
                    'quota' => 0,
                    'tutor_ids' => []
                ];
            }
            $allPeriodTutors[$pid][$hari]['quota'] += (int)$tRow['quota'];
            $allPeriodTutors[$pid][$hari]['tutor_ids'][] = (int)$tRow['dosen_id'];
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

// Helper render input Dosen Tutor per hari (desain identik renderPengujiSelect pretes-dosen.php)
function renderPengujiSelect($day, $tutorsList, $isEdit = false, $defaultPenguji = []) {
    $idPrefix = $isEdit ? 'edit_' : '';
    if (empty($defaultPenguji)) {
        $defaultPenguji = ['']; // Minimal 1 baris
    }
    $dayKey = strtolower($day);
    $dayLabel = ($dayKey === 'minggu') ? 'Minggu / Ahad' : ucfirst($day);
    ?>
    <div class="form-group" style="margin-bottom:0; display:flex; flex-direction:column; gap:6px;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <label style="font-weight:700; font-size:14px; text-transform:capitalize; color:#0f172a; margin:0;"><?= $dayLabel ?></label>
            <span style="font-size:11px; background:#e2e8f0; color:#475569; padding:2px 6px; border-radius:4px; font-weight:600;">RPL</span>
        </div>
        <div id="<?= $idPrefix ?>penguji_<?= $dayKey ?>_container" class="tutor-container" data-day="<?= $dayKey ?>" data-edit="<?= $isEdit ? '1' : '0' ?>">
            <?php foreach($defaultPenguji as $idx => $pengujiId): ?>
            <div class="tutor-row" style="display:flex; gap:4px; margin-bottom:4px;">
                <select name="penguji_<?= $dayKey ?>[]" class="tutor-select" style="flex:1; padding:6px 8px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:13px;" onchange="calculateQuota('<?= $dayKey ?>', <?= $isEdit ? 'true' : 'false' ?>)">
                    <option value="">- Pilih Tutor -</option>
                    <?php foreach($tutorsList as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id'] === (int)$pengujiId) ? 'selected' : '' ?>><?= sanitize($t['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($idx === 0): ?>
                <button type="button" class="btn btn-sm btn-success add-tutor-btn" onclick="addPengujiRow('<?= $dayKey ?>', <?= $isEdit ? 'true' : 'false' ?>)" style="padding:0 9px; font-weight:bold; font-size:14px;" title="Tambah Tutor">+</button>
                <?php else: ?>
                <button type="button" class="btn btn-sm btn-danger remove-tutor-btn" onclick="this.parentElement.remove(); calculateQuota('<?= $dayKey ?>', <?= $isEdit ? 'true' : 'false' ?>);" style="padding:0 9px; font-weight:bold; font-size:14px;" title="Hapus Tutor">×</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="font-size:11px; color:#64748b; margin-top:2px; font-weight:600;">Kuota Peserta:</div>
        <input type="number" name="kuota_<?= $dayKey ?>" id="<?= $idPrefix ?>kuota_<?= $dayKey ?>" class="kuota-input" data-day="<?= $dayKey ?>" min="0" value="0" style="width:100%;padding:6px 8px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;background:#fff;font-weight:bold;color:#0f172a;">
    </div>
    <?php
}

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

<!-- ═══════════════════════════════════════════════════════════════
     CARD 1: STATUS PERIODE TUTORIAL RPL AKTIF & TOMBOL EDIT SATU PINTU
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
$activeDaysData = $allPeriodTutors[(int)$activePeriod['id']] ?? [];
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
                    $kuota = $activeDaysData[$day]['quota'] ?? 0;
                    $tutors = $activeDaysData[$day]['tutor_ids'] ?? [];
                    $dosenCnt = count($tutors);
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
            <!-- Tombol Cepat Membuka Modal Edit untuk Periode Aktif -->
            <button type="button" class="btn btn-warning btn-edit-rpl" style="padding:12px 22px; font-size:14px; font-weight:700; display:inline-flex; align-items:center; gap:8px; box-shadow:0 2px 4px rgba(0,0,0,0.1);"
                data-id="<?= $activePeriod['id'] ?>"
                data-nama="<?= $escape($activePeriod['nama']) ?>"
                data-gelombang="<?= $escape($activePeriod['gelombang']) ?>"
                data-opens-at="<?= $escape(str_replace(' ', 'T', $activePeriod['opens_at'])) ?>"
                data-closes-at="<?= $escape(str_replace(' ', 'T', $activePeriod['closes_at'])) ?>"
                data-quota="<?= (int)$activePeriod['quota'] ?>"
                data-active="1"
                data-version="<?= (int)$activePeriod['version'] ?>"
                <?php foreach (['senin','selasa','rabu','kamis','jumat','sabtu','minggu'] as $dKey): ?>
                <?php $capDay = ucfirst($dKey); ?>
                data-tutors-<?= $dKey ?>="<?= $escape(json_encode($activeDaysData[$capDay]['tutor_ids'] ?? [])) ?>"
                data-kuota-<?= $dKey ?>="<?= (int)($activeDaysData[$capDay]['quota'] ?? 0) ?>"
                <?php endforeach; ?>>
                ✏️ Edit Penguji & Kuota Periode Ini
            </button>
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
                                    <span style="color:#94a3b8; font-size:11.5px; font-style:italic;">Belum ada jadwal tutor</span>
                                <?php else: ?>
                                    <?php foreach ($dayShorts as $fullDay => $shortDay): ?>
                                        <?php if (isset($pDayData[$fullDay])): ?>
                                            <span style="background:#f1f5f9; padding:2px 6px; border-radius:4px; border:1px solid #e2e8f0;" title="<?= $fullDay ?>: <?= $pDayData[$fullDay]['quota'] ?> mhs (<?= count($pDayData[$fullDay]['tutor_ids']) ?> tutor)">
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

                                <button type="button" class="btn btn-sm btn-warning btn-edit-rpl"
                                    data-id="<?= $period['id'] ?>"
                                    data-nama="<?= $escape($period['nama']) ?>"
                                    data-gelombang="<?= $escape($period['gelombang']) ?>"
                                    data-opens-at="<?= $escape(str_replace(' ', 'T', $period['opens_at'])) ?>"
                                    data-closes-at="<?= $escape(str_replace(' ', 'T', $period['closes_at'])) ?>"
                                    data-quota="<?= (int)$period['quota'] ?>"
                                    data-active="<?= $isActive ? '1' : '0' ?>"
                                    data-version="<?= (int)$period['version'] ?>"
                                    <?php foreach (['senin','selasa','rabu','kamis','jumat','sabtu','minggu'] as $dKey): ?>
                                    <?php $capDay = ucfirst($dKey); ?>
                                    data-tutors-<?= $dKey ?>="<?= $escape(json_encode($pDayData[$capDay]['tutor_ids'] ?? [])) ?>"
                                    data-kuota-<?= $dKey ?>="<?= (int)($pDayData[$capDay]['quota'] ?? 0) ?>"
                                    <?php endforeach; ?>
                                    title="Edit Periode (Satu Pintu)">
                                    ✏️ Edit
                                </button>

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
     CARD 3: TAMBAH PERIODE TUTORIAL RPL BARU (COLLAPSIBLE)
     ═══════════════════════════════════════════════════════════════ -->
<div class="card" id="card-tambah-periode" style="display:none; margin-bottom:24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span>➕ Tambah Periode Tutorial RPL Baru</span>
        <button type="button" onclick="toggleFormTambah()" style="background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_period">
            <input type="hidden" name="id" value="0">
            <input type="hidden" name="version" value="1">
            <input type="hidden" name="schedules_complete" value="1">

            <!-- Baris Metadata 1 Baris Melebar -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:18px;">
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Nama Periode *</label>
                    <input type="text" name="nama" placeholder="Contoh: Tutorial RPL 2026/2027" required style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Gelombang / Semester *</label>
                    <input type="text" name="gelombang" placeholder="Contoh: Gelombang 1" required style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Target Program</label>
                    <input type="text" readonly value="Khusus Mahasiswa RPL" style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;background:#f8fafc;font-weight:600;color:#0369a1;box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Buka Pendaftaran (WIB) *</label>
                    <input type="datetime-local" step="1" name="opens_at" required value="<?= $escape(str_replace(' ', 'T', $defaults['opens_at'])) ?>" style="width:100%;padding:7px 10px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Tutup Pendaftaran (WIB) *</label>
                    <input type="datetime-local" step="1" name="closes_at" required value="<?= $escape(str_replace(' ', 'T', $defaults['closes_at'])) ?>" style="width:100%;padding:7px 10px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Batas Total Peserta</label>
                    <input type="number" min="0" max="1000000" name="quota" value="0" style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;box-sizing:border-box;">
                    <small style="color:#64748b; font-size:11px;">0 = ikut total kuota tutor</small>
                </div>
                <div class="form-group" style="margin:0; display:flex; flex-direction:column; justify-content:center;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Status Pendaftaran</label>
                    <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="active_slot" value="1" style="width:18px;height:18px;">
                        <span style="font-size:13.5px; font-weight:600; color:#0f172a;">Jadikan Aktif</span>
                    </label>
                </div>
            </div>

            <!-- Grid 5 Hari Kerja (Senin - Jumat) -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <strong style="color:#0f172a; font-size:14px;">👨‍🏫 Dosen Tutor & Kuota Harian Tutorial RPL (Senin - Jumat):</strong>
                    <small style="color:#64748b;">💡 Kuota otomatis terhitung 30 per dosen tutor (dapat disesuaikan manual).</small>
                </div>
                <div style="display:grid; grid-template-columns:repeat(5, 1fr); gap:14px; overflow-x:auto;">
                    <?php
                    renderPengujiSelect('senin', $tutorsList, false);
                    renderPengujiSelect('selasa', $tutorsList, false);
                    renderPengujiSelect('rabu', $tutorsList, false);
                    renderPengujiSelect('kamis', $tutorsList, false);
                    renderPengujiSelect('jumat', $tutorsList, false);
                    ?>
                </div>
            </div>

            <!-- Grid Hari Akhir Pekan (Sabtu & Minggu) -->
            <div style="background:#f5f3ff; border:1.5px dashed #a78bfa; border-radius:10px; padding:14px 16px; margin-bottom:20px;">
                <div style="font-weight:700; color:#5b21b6; font-size:13.5px; margin-bottom:10px;">
                    🗓️ Pilihan Hari Akhir Pekan (Sabtu &amp; Minggu / Ahad) — Khusus Mahasiswa RPL
                </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px;">
                    <?php
                    renderPengujiSelect('sabtu', $tutorsList, false);
                    renderPengujiSelect('minggu', $tutorsList, false);
                    ?>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="padding:10px 24px; font-weight:600;">
                ➕ Simpan Periode Baru
            </button>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL EDIT PERIODE TUTORIAL RPL (LEBAR 1200px - SATU PINTU)
     ═══════════════════════════════════════════════════════════════ -->
<div id="editRplModal" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(2px); overflow-y:auto;">
    <div style="background:#fff; max-width:1200px; width:95%; margin:30px auto; border-radius:14px; padding:24px 28px; box-shadow:0 20px 35px rgba(0,0,0,0.25);">

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
            <div>
                <h3 style="margin:0; font-size:19px; color:#0f172a; display:flex; align-items:center; gap:8px;">
                    <span>✏️</span> Pengaturan Periode, Dosen Tutor & Kuota Tutorial RPL
                </h3>
                <small style="color:#64748b;">Kelola informasi periode serta ketersediaan tutor dan kuota harian tutorial RPL dalam satu layar.</small>
            </div>
            <button type="button" class="btn-close-modal" onclick="closeRplModal()" style="background:#f1f5f9; border:none; border-radius:50%; width:32px; height:32px; font-size:20px; line-height:30px; cursor:pointer; color:#475569;">&times;</button>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_period">
            <input type="hidden" name="id" id="edit_id" value="<?= $escape($form['id'] ?? 0) ?>">
            <input type="hidden" name="version" id="edit_version" value="<?= $escape($form['version'] ?? 1) ?>">
            <input type="hidden" name="schedules_complete" value="1">

            <!-- Baris Metadata 1 Baris Melebar -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:18px;">
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Nama Periode *</label>
                    <input type="text" name="nama" id="edit_nama" required value="<?= $escape($form['nama'] ?? '') ?>" style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Gelombang / Semester *</label>
                    <input type="text" name="gelombang" id="edit_gelombang" required value="<?= $escape($form['gelombang'] ?? '') ?>" style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Target Program</label>
                    <input type="text" readonly value="Khusus Mahasiswa RPL" style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;background:#f8fafc;font-weight:600;color:#0369a1;box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Buka Pendaftaran (WIB) *</label>
                    <input type="datetime-local" step="1" name="opens_at" id="edit_opens_at" required value="<?= $escape(str_replace(' ', 'T', $form['opens_at'] ?? '')) ?>" style="width:100%;padding:7px 10px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Tutup Pendaftaran (WIB) *</label>
                    <input type="datetime-local" step="1" name="closes_at" id="edit_closes_at" required value="<?= $escape(str_replace(' ', 'T', $form['closes_at'] ?? '')) ?>" style="width:100%;padding:7px 10px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Batas Total Peserta</label>
                    <input type="number" min="0" max="1000000" name="quota" id="edit_quota" value="<?= (int)($form['quota'] ?? 0) ?>" style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;box-sizing:border-box;">
                    <small style="color:#64748b; font-size:11px;">0 = ikut total kuota tutor</small>
                </div>
                <div class="form-group" style="margin:0; display:flex; flex-direction:column; justify-content:center;">
                    <label style="font-size:13px; font-weight:600; color:#334155; margin-bottom:4px; display:block;">Status Pendaftaran</label>
                    <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="active_slot" id="edit_active_slot" value="1" <?= (int)($form['active_slot'] ?? 0) === 1 ? 'checked' : '' ?> style="width:18px;height:18px;">
                        <span style="font-size:13.5px; font-weight:600; color:#0f172a;">Jadikan Aktif</span>
                    </label>
                </div>
            </div>

            <!-- Grid 5 Hari Kerja (Senin - Jumat) -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <strong style="color:#0f172a; font-size:14px;">👨‍🏫 Dosen Tutor & Kuota Harian Tutorial RPL (Senin - Jumat):</strong>
                    <small style="color:#64748b;">💡 Kuota otomatis terhitung 30 per dosen tutor (dapat disesuaikan manual).</small>
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

            <!-- Grid Hari Akhir Pekan (Sabtu & Minggu) -->
            <div style="background:#f5f3ff; border:1.5px dashed #a78bfa; border-radius:10px; padding:14px 16px; margin-bottom:20px;">
                <div style="font-weight:700; color:#5b21b6; font-size:13.5px; margin-bottom:10px;">
                    🗓️ Pilihan Hari Akhir Pekan (Sabtu &amp; Minggu / Ahad) — Khusus Mahasiswa RPL
                </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px;">
                    <?php
                    renderPengujiSelect('sabtu', $tutorsList, true);
                    renderPengujiSelect('minggu', $tutorsList, true);
                    ?>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px; border-top:1px solid #e2e8f0; padding-top:16px;">
                <button type="button" class="btn btn-secondary" onclick="closeRplModal()" style="padding:9px 20px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding:9px 24px; font-weight:600;">💾 Simpan Pengaturan</button>
            </div>
        </form>
    </div>
</div>

<script>
// Fungsi hitung kuota berdasarkan dosen tutor (1 tutor = 30 peserta)
window.calculateQuota = function(dayKey, isEdit) {
    const prefix = isEdit ? 'edit_' : '';
    const container = document.getElementById(prefix + 'penguji_' + dayKey + '_container');
    if (!container) return;
    const selects = container.querySelectorAll('select');
    let validTutors = 0;
    selects.forEach(sel => {
        if (sel.value.trim() !== '') validTutors++;
    });
    const kuotaInput = document.getElementById(prefix + 'kuota_' + dayKey);
    if (kuotaInput) {
        kuotaInput.value = validTutors * 30;
    }
};

// Tambah baris dosen tutor
window.addPengujiRow = function(dayKey, isEdit) {
    const prefix = isEdit ? 'edit_' : '';
    const container = document.getElementById(prefix + 'penguji_' + dayKey + '_container');
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
        window.calculateQuota(dayKey, isEdit);
    };

    container.appendChild(newRow);
    window.calculateQuota(dayKey, isEdit);
};

// Mengisi baris tutor pada modal edit
window.populatePenguji = function(dayKey, tutorIds, kuotaVal) {
    const prefix = 'edit_';
    const container = document.getElementById(prefix + 'penguji_' + dayKey + '_container');
    if (!container) return;

    const rows = container.querySelectorAll('.tutor-row');
    for (let i = 1; i < rows.length; i++) {
        rows[i].remove();
    }

    const firstSelect = container.querySelector('select');
    firstSelect.value = '';

    if (Array.isArray(tutorIds) && tutorIds.length > 0) {
        firstSelect.value = String(tutorIds[0]);
        for (let i = 1; i < tutorIds.length; i++) {
            const newRow = rows[0].cloneNode(true);
            const sel = newRow.querySelector('select');
            sel.value = String(tutorIds[i]);
            const btn = newRow.querySelector('button');
            btn.textContent = '×';
            btn.classList.remove('btn-success', 'add-tutor-btn');
            btn.classList.add('btn-danger', 'remove-tutor-btn');
            btn.onclick = function() {
                newRow.remove();
                window.calculateQuota(dayKey, true);
            };
            container.appendChild(newRow);
        }
    }

    const kuotaInput = document.getElementById(prefix + 'kuota_' + dayKey);
    if (kuotaInput) {
        kuotaInput.value = (kuotaVal !== undefined && kuotaVal > 0) ? kuotaVal : ((Array.isArray(tutorIds) ? tutorIds.length : 0) * 30);
    }
};

window.openRplModal = function(d) {
    document.getElementById('edit_id').value = d.id;
    document.getElementById('edit_nama').value = d.nama || '';
    document.getElementById('edit_gelombang').value = d.gelombang || '';
    document.getElementById('edit_opens_at').value = d.opensAt || '';
    document.getElementById('edit_closes_at').value = d.closesAt || '';
    document.getElementById('edit_quota').value = d.quota || 0;
    document.getElementById('edit_active_slot').checked = (d.active === '1');
    document.getElementById('edit_version').value = d.version || 1;

    ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'].forEach(function(dayKey) {
        let tutorIds = [];
        try {
            tutorIds = JSON.parse(d['tutors' + dayKey.charAt(0).toUpperCase() + dayKey.slice(1)] || '[]');
        } catch(e) {}
        const kuotaVal = parseInt(d['kuota' + dayKey.charAt(0).toUpperCase() + dayKey.slice(1)] || '0', 10);
        window.populatePenguji(dayKey, tutorIds, kuotaVal);
    });

    const m = document.getElementById('editRplModal');
    if (m) m.style.display = 'block';
};

window.closeRplModal = function() {
    const m = document.getElementById('editRplModal');
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
        const btn = e.target.closest('.btn-edit-rpl');
        if (btn) {
            const d = btn.dataset;
            window.openRplModal({
                id: d.id,
                nama: d.nama,
                gelombang: d.gelombang,
                opensAt: btn.getAttribute('data-opens-at') || d.opensAt,
                closesAt: btn.getAttribute('data-closes-at') || d.closesAt,
                quota: d.quota,
                active: d.active,
                version: d.version,
                tutorsSenin: btn.getAttribute('data-tutors-senin'),
                kuotaSenin: btn.getAttribute('data-kuota-senin'),
                tutorsSelasa: btn.getAttribute('data-tutors-selasa'),
                kuotaSelasa: btn.getAttribute('data-kuota-selasa'),
                tutorsRabu: btn.getAttribute('data-tutors-rabu'),
                kuotaRabu: btn.getAttribute('data-kuota-rabu'),
                tutorsKamis: btn.getAttribute('data-tutors-kamis'),
                kuotaKamis: btn.getAttribute('data-kuota-kamis'),
                tutorsJumat: btn.getAttribute('data-tutors-jumat'),
                kuotaJumat: btn.getAttribute('data-kuota-jumat'),
                tutorsSabtu: btn.getAttribute('data-tutors-sabtu'),
                kuotaSabtu: btn.getAttribute('data-kuota-sabtu'),
                tutorsMinggu: btn.getAttribute('data-tutors-minggu'),
                kuotaMinggu: btn.getAttribute('data-kuota-minggu')
            });
        }
        if (e.target && e.target.id === 'editRplModal') {
            window.closeRplModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') window.closeRplModal();
    });

    <?php if (isset($_GET['edit']) && !empty($form['id'])): ?>
    const autoBtn = document.querySelector('.btn-edit-rpl[data-id="<?= (int)$form['id'] ?>"]');
    if (autoBtn) autoBtn.click();
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
