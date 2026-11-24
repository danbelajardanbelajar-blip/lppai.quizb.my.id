<?php
/**
 * LPPAI Corner - Admin: Pengaturan & Generate Tutorial RPL Online
 *
 * Halaman pengaturan dosen & kuota per hari untuk periode RPL aktif serta
 * sarana generate pembagian kelompok kelas dan plotting otomatis mahasiswa program RPL.
 * Antarmuka dan alur kerja selaras dengan admin/tutorial-pengaturan-generate.php.
 */
define('PAGE_TITLE', 'Pengaturan & Generate RPL');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
require_once __DIR__ . '/../includes/tutorial-rpl-periods.php';
require_once __DIR__ . '/../includes/tutorial-rpl-registration.php';

if (!function_exists('sanitize')) {
    function sanitize($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$pdo = getDBConnection();
ensureTutorialRplTables($pdo);
if (function_exists('ensureTutorialRplPeriodTable')) {
    ensureTutorialRplPeriodTable($pdo);
}
if (function_exists('ensureTutorialRplRegistrationTable')) {
    ensureTutorialRplRegistrationTable($pdo);
}

$message = '';
$msgType = '';
$isMessageHtml = false;

// Ambil hasil session jika ada
$generateResult = $_SESSION['tutorial_rpl_generate_result'] ?? null;
unset($_SESSION['tutorial_rpl_generate_result']);

$saveResult = $_SESSION['tutorial_rpl_save_result'] ?? null;
unset($_SESSION['tutorial_rpl_save_result']);
if ($saveResult) {
    $message = $saveResult;
    $msgType = 'success';
}

// Cari periode RPL aktif
$activePeriod = null;
if (function_exists('tutorialRplActivePeriod')) {
    $activePeriod = tutorialRplActivePeriod($pdo);
}

// Fallback periode terbaru jika belum ada yang diset aktif
$currentPeriod = $activePeriod;
if (!$currentPeriod) {
    try {
        $stmtLatest = $pdo->query("SELECT * FROM tutorial_rpl_registration_periods ORDER BY opens_at DESC, id DESC LIMIT 1");
        $currentPeriod = $stmtLatest ? $stmtLatest->fetch(PDO::FETCH_ASSOC) : null;
    } catch (Throwable $e) {
        $currentPeriod = null;
    }
}

// Daftar Dosen/Tutor
$tutorsList = [];
try {
    $tutorsList = $pdo->query("
        SELECT id, nim, nama_lengkap as nama, no_hp, no_hp AS whatsapp
        FROM users
        WHERE role = 'dosen'
        ORDER BY nama_lengkap ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $tutorsList = [];
}

/* =============================================================
   POST HANDLERS
   ============================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi formulir tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
        $msgType = 'danger';
    } else {
        $action = trim($_POST['action']);

        /* ---- 1. SIMPAN PENGATURAN DOSEN & KUOTA ---- */
        if ($action === 'update_tutors_kuota') {
            $periodId = filter_var($_POST['period_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$periodId) {
                $message = 'Periode pendaftaran RPL tidak valid.';
                $msgType = 'danger';
            } else {
                $daysList = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'];
                $dayMapping = [
                    'senin' => 'Senin',
                    'selasa' => 'Selasa',
                    'rabu' => 'Rabu',
                    'kamis' => 'Kamis',
                    'jumat' => 'Jumat',
                    'sabtu' => 'Sabtu',
                    'minggu' => 'Minggu'
                ];

                // Peta nama dosen ke ID dosen untuk resolusi cepat
                $nameToIdMap = [];
                $idSet = [];
                foreach ($tutorsList as $t) {
                    $nameToIdMap[trim(mb_strtolower($t['nama']))] = (int)$t['id'];
                    $idSet[(int)$t['id']] = true;
                }

                $schedules = [];
                foreach ($daysList as $dayKey) {
                    $capitalDay = $dayMapping[$dayKey];
                    $submittedTutors = $_POST['tutors_' . $dayKey] ?? [];
                    if (!is_array($submittedTutors)) {
                        $submittedTutors = [];
                    }

                    $resolvedDosenIds = [];
                    foreach ($submittedTutors as $rawVal) {
                        $rawVal = trim((string)$rawVal);
                        if ($rawVal === '') continue;

                        $did = filter_var($rawVal, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($did && isset($idSet[$did])) {
                            $resolvedDosenIds[] = $did;
                        } elseif (isset($nameToIdMap[mb_strtolower($rawVal)])) {
                            $resolvedDosenIds[] = $nameToIdMap[mb_strtolower($rawVal)];
                        }
                    }
                    $resolvedDosenIds = array_unique($resolvedDosenIds);

                    $totalDayQuota = max(0, (int)($_POST['kuota_' . $dayKey] ?? 0));
                    $countTutors = count($resolvedDosenIds);

                    if ($countTutors > 0) {
                        $baseQuota = ($totalDayQuota > 0) ? (int)floor($totalDayQuota / $countTutors) : 30;
                        if ($baseQuota < 1) $baseQuota = 1;
                        $remainder = ($totalDayQuota > 0) ? ($totalDayQuota - ($baseQuota * $countTutors)) : 0;

                        foreach ($resolvedDosenIds as $idx => $tId) {
                            $q = $baseQuota + ($idx === 0 ? max(0, $remainder) : 0);
                            $schedules[] = [
                                'dosen_id' => $tId,
                                'hari' => $capitalDay,
                                'quota' => $q
                            ];
                        }
                    }
                }

                try {
                    $isActive = ($activePeriod && (int)$activePeriod['id'] === $periodId);
                    tutorialRplSavePeriodTutors($pdo, $periodId, $schedules, $isActive);

                    // Sinkronkan total kuota pada tabel tutorial_rpl_registration_periods
                    $stmtSum = $pdo->prepare("SELECT COALESCE(SUM(quota), 0) FROM tutorial_rpl_period_tutors WHERE period_id = ?");
                    $stmtSum->execute([$periodId]);
                    $newTotalQuota = (int)$stmtSum->fetchColumn();

                    $nowStr = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
                    $currentUserId = (int)($_SESSION['user_id'] ?? (getCurrentUser()['id'] ?? 1));
                    $stmtUpd = $pdo->prepare("
                        UPDATE tutorial_rpl_registration_periods
                        SET quota = ?, updated_by = ?, updated_at = ?
                        WHERE id = ?
                    ");
                    $stmtUpd->execute([$newTotalQuota, $currentUserId, $nowStr, $periodId]);

                    $_SESSION['tutorial_rpl_save_result'] = 'Pengaturan Dosen dan Kuota Tutorial RPL berhasil disimpan.';
                    header('Location: ' . BASE_URL . '/admin/tutorial-rpl-pengaturan-generate.php');
                    exit;
                } catch (Throwable $e) {
                    $message = 'Gagal menyimpan pengaturan: ' . $e->getMessage();
                    $msgType = 'danger';
                }
            }

        /* ---- 2. GENERATE JADWAL & PLOTTING KELOMPOK RPL ---- */
        } elseif ($action === 'generate_plotting') {
            $generateMode = trim($_POST['generate_mode'] ?? 'distribute_evenly');
            $minPerClass = max(1, (int)($_POST['min_per_class'] ?? 30));
            $fillExisting = ($_POST['fill_existing_classes'] ?? '') === '1';
            $existingTutorQuota = max(1, (int)($_POST['existing_tutor_quota'] ?? 30));
            $overwritePlotting = ($_POST['overwrite_plotting'] ?? '') === '1';
            $matchHariPilihan = ($_POST['match_hari_pilihan'] ?? '') === '1';
            $autoCreateClasses = ($_POST['auto_create_classes'] ?? '') === '1';

            $periodName = $currentPeriod['nama'] ?? TUTORIAL_RPL_DEFAULT_PERIODE;
            $periodGelombang = $currentPeriod['gelombang'] ?? TUTORIAL_RPL_DEFAULT_GELOMBANG;
            $periodId = $currentPeriod['id'] ?? null;

            try {
                $options = [
                    'periode' => $periodName,
                    'gelombang' => $periodGelombang,
                    'period_id' => $periodId,
                    'generate_mode' => $generateMode,
                    'min_per_class' => $minPerClass,
                    'fill_existing' => $fillExisting,
                    'existing_tutor_quota' => $existingTutorQuota,
                    'overwrite_plotting' => $overwritePlotting,
                    'match_hari_pilihan' => $matchHariPilihan,
                    'auto_create_classes' => $autoCreateClasses
                ];

                $res = tutorialRplGenerateSchedule($pdo, $options);
                $_SESSION['tutorial_rpl_generate_result'] = $res;
                header('Location: ' . BASE_URL . '/admin/tutorial-rpl-pengaturan-generate.php#hasil-generate');
                exit;
            } catch (Throwable $e) {
                $message = 'Gagal melakukan generate plotting: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

/* =============================================================
   DATA DISPLAY & STATISTIK
   ============================================================= */
$daysDisplay = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$periodTutorsMap = [];
$periodDayQuotas = [];
$registeredCounts = [];

foreach ($daysDisplay as $d) {
    $periodTutorsMap[$d] = [];
    $periodDayQuotas[$d] = 0;
    $registeredCounts[$d] = 0;
}

if ($currentPeriod) {
    $pId = (int)$currentPeriod['id'];

    // Ambil data tutor yang terjadwal di periode ini
    if (function_exists('tutorialRplPeriodTutors')) {
        $rawTutors = tutorialRplPeriodTutors($pdo, $pId);
        foreach ($rawTutors as $rt) {
            $h = ucfirst(strtolower(trim((string)($rt['hari'] ?? ''))));
            if (isset($periodTutorsMap[$h])) {
                $periodTutorsMap[$h][] = [
                    'dosen_id' => (int)$rt['dosen_id'],
                    'nama' => $rt['dosen_nama'] ?? ('Dosen ' . $rt['dosen_id']),
                    'quota' => (int)$rt['quota']
                ];
                $periodDayQuotas[$h] += (int)$rt['quota'];
            }
        }
    }

    // Ambil jumlah pendaftar per hari pilihan
    try {
        $hasRegTable = function_exists('tutorialRplTableExists') ? tutorialRplTableExists($pdo, 'tutorial_rpl_registrations') : false;
        if ($hasRegTable) {
            $stmtC = $pdo->prepare("
                SELECT hari_pilihan, COUNT(*) as cnt
                FROM tutorial_rpl_registrations
                WHERE period_id = ? AND hari_pilihan IS NOT NULL AND TRIM(hari_pilihan) != ''
                GROUP BY hari_pilihan
            ");
            $stmtC->execute([$pId]);
            foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $h = ucfirst(strtolower(trim((string)$row['hari_pilihan'])));
                if (isset($registeredCounts[$h])) {
                    $registeredCounts[$h] = (int)$row['cnt'];
                }
            }
        }
    } catch (Throwable $e) {
        // Abaikan jika tabel pendaftaran belum ada data
    }
}

// Statistik Keseluruhan Peserta RPL
$totalRplCandidates = 0;
$totalPlotted = 0;
$totalUnplotted = 0;
$rplClasses = [];
$totalClassCapacity = 0;
$totalClassFilled = 0;

try {
    $hasRegTable = function_exists('tutorialRplTableExists') ? tutorialRplTableExists($pdo, 'tutorial_rpl_registrations') : false;
    $pId = $currentPeriod['id'] ?? null;

    if ($hasRegTable && $pId) {
        $stmtTot = $pdo->prepare("SELECT COUNT(*) FROM tutorial_rpl_registrations WHERE period_id = ?");
        $stmtTot->execute([$pId]);
        $totalRplCandidates = (int)$stmtTot->fetchColumn();

        $stmtPlot = $pdo->prepare("
            SELECT COUNT(DISTINCT r.user_id)
            FROM tutorial_rpl_registrations r
            JOIN tutorial_rpl_members m ON r.user_id = m.user_id
            WHERE r.period_id = ?
        ");
        $stmtPlot->execute([$pId]);
        $totalPlotted = (int)$stmtPlot->fetchColumn();
    }

    if ($totalRplCandidates === 0) {
        // Fallback hitung mahasiswa bertipe RPL
        $stmtFallback = $pdo->query("
            SELECT COUNT(*)
            FROM users
            WHERE role = 'mahasiswa' AND (UPPER(jenis_program) = 'RPL' OR id IN (SELECT user_id FROM pretes_registrations WHERE periode LIKE '%RPL%'))
        ");
        $totalRplCandidates = (int)$stmtFallback->fetchColumn();

        $stmtFallbackPlot = $pdo->query("
            SELECT COUNT(DISTINCT m.user_id)
            FROM tutorial_rpl_members m
            JOIN users u ON m.user_id = u.id
            WHERE u.role = 'mahasiswa' AND (UPPER(u.jenis_program) = 'RPL' OR u.id IN (SELECT user_id FROM pretes_registrations WHERE periode LIKE '%RPL%'))
        ");
        $totalPlotted = (int)$stmtFallbackPlot->fetchColumn();
    }

    $totalUnplotted = max(0, $totalRplCandidates - $totalPlotted);

    // Ambil kelompok kelas aktif
    $periodeName = $currentPeriod['nama'] ?? null;
    $rplClasses = tutorialRplGetClasses($pdo, $periodeName);
    foreach ($rplClasses as $rc) {
        if (!empty($rc['is_active'])) {
            $totalClassCapacity += (int)($rc['kuota'] ?? 0);
            $totalClassFilled += (int)($rc['terisi'] ?? 0);
        }
    }
} catch (Throwable $e) {
    error_log('tutorial-rpl-pengaturan-generate stats: ' . $e->getMessage());
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="margin-bottom: 20px; border-radius: 8px;">
        <?= $isMessageHtml ? $message : sanitize($message) ?>
    </div>
<?php endif; ?>

<!-- Header Halaman -->
<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0; font-size:22px; font-weight:800; color:var(--text-dark);">
            ⚡ Pengaturan &amp; Generate Tutorial RPL Online
        </h2>
        <p style="margin:0; font-size:13.5px; color:#64748b;">
            Konfigurasi kuota dosen pembimbing dan generate pembagian kelompok kelas daring otomatis untuk mahasiswa program RPL.
        </p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a href="<?= BASE_URL ?>/admin/tutorial-rpl-kelas.php" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px; font-weight:600;">
            <span>🏫</span> Kelola Kelompok RPL
        </a>
        <a href="<?= BASE_URL ?>/admin/tutorial-rpl-pendaftaran.php" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:6px;">
            <span>📝</span> Data Pendaftar RPL
        </a>
        <a href="<?= BASE_URL ?>/admin/tutorial-rpl-periode.php" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:6px;">
            <span>📅</span> Periode RPL
        </a>
    </div>
</div>

<!-- ===================================================
     HASIL GENERATE REPORT (JIKA ADA)
     =================================================== -->
<?php if ($generateResult): ?>
<div id="hasil-generate" class="card" style="margin-bottom: 24px; border: 2px solid <?= empty($generateResult['unassigned']) ? '#10b981' : '#f59e0b' ?>; background: <?= empty($generateResult['unassigned']) ? '#f0fdf4' : '#fffbeb' ?>;">
    <div class="card-header" style="background: transparent; color: <?= empty($generateResult['unassigned']) ? '#166534' : '#b45309' ?>; font-weight: 700; font-size: 16px;">
        <?= empty($generateResult['unassigned']) ? '🎉 Plotting Kelompok RPL Berhasil Disusun' : '⚠️ Plotting Kelompok RPL Selesai dengan Catatan' ?>
    </div>
    <div class="card-body">
        <p style="margin: 0 0 12px; color: #334155; font-size: 14px; line-height: 1.6;">
            <strong><?= sanitize($generateResult['message']) ?></strong>
        </p>
        <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 14px;">
            <span style="background: #e2e8f0; padding: 4px 10px; border-radius: 6px; font-size: 13px; font-weight: 600;">
                Kandidat Diproses: <strong><?= (int)($generateResult['total_candidates'] ?? 0) ?></strong>
            </span>
            <span style="background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 6px; font-size: 13px; font-weight: 600;">
                Berhasil Diplot: <strong><?= (int)($generateResult['plotted'] ?? 0) ?></strong>
            </span>
            <span style="background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 6px; font-size: 13px; font-weight: 600;">
                Kelompok Digunakan: <strong><?= (int)($generateResult['classes_used'] ?? 0) ?></strong>
            </span>
            <?php if (!empty($generateResult['unassigned'])): ?>
            <span style="background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 6px; font-size: 13px; font-weight: 600;">
                Belum Terplot: <strong><?= count($generateResult['unassigned']) ?></strong>
            </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($generateResult['class_details'])): ?>
        <div style="margin-top: 12px;">
            <div style="font-weight: 600; font-size: 13.5px; color: #1e293b; margin-bottom: 8px;">Rincian Penempatan Kelompok:</div>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px;">
                <?php foreach ($generateResult['class_details'] as $cd): ?>
                <div style="background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; font-size: 13px;">
                    <div style="font-weight: 700; color: #0f172a;"><?= sanitize($cd['nama_kelompok']) ?></div>
                    <div style="color: #64748b; font-size: 12px; margin-top: 2px;">Tutor: <?= sanitize($cd['dosen_nama']) ?></div>
                    <div style="margin-top: 6px; font-size: 12.5px; color: #334155;">
                        Ditambahkan: <strong style="color: #2563eb;">+<?= (int)$cd['added'] ?></strong> mahasiswa
                        (Total: <?= (int)$cd['total'] ?> / <?= (int)$cd['kuota'] ?>)
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($generateResult['unassigned'])): ?>
        <div style="margin-top: 16px; padding: 12px; background: #fff; border-radius: 8px; border: 1px solid #fed7aa;">
            <div style="font-weight: 700; font-size: 13.5px; color: #9a3412;">
                Daftar Mahasiswa yang Belum Terplot (Kuota Kelompok Penuh):
            </div>
            <p style="font-size: 12.5px; color: #64748b; margin: 4px 0 8px;">
                Tingkatkan kuota dosen pada kartu pengaturan di bawah atau tambah kelompok kelas baru, lalu jalankan generate kembali.
            </p>
            <ol style="margin: 0; padding-left: 20px; font-size: 12.5px; max-height: 160px; overflow-y: auto; color: #334155;">
                <?php foreach ($generateResult['unassigned'] as $u): ?>
                <li>
                    <strong><?= sanitize($u['nama_lengkap']) ?></strong> (<?= sanitize($u['nim'] ?: '-') ?>) — <?= sanitize($u['program_studi'] ?: 'RPL') ?>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php endif; ?>

        <div style="margin-top: 14px;">
            <a href="<?= BASE_URL ?>/admin/tutorial-rpl-pendaftaran.php" class="btn btn-sm btn-primary" style="font-weight: 600;">
                Lihat Data Pendaftar &amp; Penempatan Kelompok &rarr;
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===================================================
     KPI SUMMARY STATS
     =================================================== -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
    <div class="card" style="margin-bottom:0; padding:16px; border-left:4px solid #3b82f6;">
        <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase;">Total Pendaftar RPL</div>
        <div style="font-size:26px; font-weight:800; color:#1e293b; margin:4px 0;"><?= $totalRplCandidates ?></div>
        <div style="font-size:12px; color:#64748b;">Periode: <?= sanitize($currentPeriod['nama'] ?? 'Semua') ?></div>
    </div>
    <div class="card" style="margin-bottom:0; padding:16px; border-left:4px solid #10b981;">
        <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase;">Sudah Terplot Kelompok</div>
        <div style="font-size:26px; font-weight:800; color:#10b981; margin:4px 0;"><?= $totalPlotted ?></div>
        <div style="font-size:12px; color:#64748b;"><?= $totalRplCandidates > 0 ? round(($totalPlotted / $totalRplCandidates) * 100) : 0 ?>% dari total pendaftar</div>
    </div>
    <div class="card" style="margin-bottom:0; padding:16px; border-left:4px solid #f59e0b;">
        <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase;">Belum Terplot (Menunggu)</div>
        <div style="font-size:26px; font-weight:800; color:#f59e0b; margin:4px 0;"><?= $totalUnplotted ?></div>
        <div style="font-size:12px; color:#64748b;">Siap dialokasikan ke kelompok</div>
    </div>
    <div class="card" style="margin-bottom:0; padding:16px; border-left:4px solid #8b5cf6;">
        <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase;">Kapasitas Kelompok Aktif</div>
        <div style="font-size:26px; font-weight:800; color:#8b5cf6; margin:4px 0;"><?= $totalClassFilled ?> / <?= $totalClassCapacity ?></div>
        <div style="font-size:12px; color:#64748b;">Sisa Kuota: <?= max(0, $totalClassCapacity - $totalClassFilled) ?> kursi (<?= count($rplClasses) ?> kelompok)</div>
    </div>
</div>

<!-- ===================================================
     CARD: PENGATURAN DOSEN DAN KUOTA (PERIODE AKTIF)
     =================================================== -->
<?php if ($currentPeriod): ?>
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <div style="font-weight:700;">
            👨‍🏫 Pengaturan Dosen &amp; Kuota (Periode RPL: <?= sanitize($currentPeriod['nama']) ?> - <?= sanitize($currentPeriod['gelombang']) ?>)
        </div>
        <?php if (!empty($currentPeriod['active_slot'])): ?>
            <span class="badge" style="background:#dcfce7; color:#166534; font-weight:700; padding:4px 8px; border-radius:6px; font-size:12px;">
                ● Periode Aktif
            </span>
        <?php else: ?>
            <span class="badge" style="background:#fef3c7; color:#92400e; font-weight:700; padding:4px 8px; border-radius:6px; font-size:12px;">
                Non-Aktif
            </span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p style="color:#64748b; font-size:13.5px; margin-top:0; margin-bottom:14px;">
            Tentukan dosen pembimbing dan kapasitas kuota harian untuk bimbingan daring mahasiswa RPL. Setiap tutor dapat ditugaskan pada satu atau lebih hari bimbingan.
        </p>

        <form method="POST" data-no-spa id="form-tutors-kuota">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_tutors_kuota">
            <input type="hidden" name="period_id" value="<?= (int)$currentPeriod['id'] ?>">

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; background:#f8fafc; padding:16px; border-radius:10px; border:1px solid #e2e8f0;">
                <?php
                foreach ($daysDisplay as $day):
                    $dayLower = strtolower($day);
                    $assignedTutors = $periodTutorsMap[$day] ?? [];
                    $totalKuota = $periodDayQuotas[$day] ?? 0;
                    $terisi = $registeredCounts[$day] ?? 0;
                    $sisaKuota = $totalKuota - $terisi;
                    if (empty($assignedTutors)) {
                        $assignedTutors = [['dosen_id' => '', 'nama' => '', 'quota' => 30]];
                    }
                ?>
                <div class="form-group" style="margin-bottom:0; display:flex; flex-direction:column; gap:8px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <label style="font-weight:700; font-size:14px; color:#1e293b;"><?= $day ?></label>
                        <span style="font-size:11px; background:#e2e8f0; color:#475569; padding:1px 6px; border-radius:999px;">
                            <?= count(array_filter($assignedTutors, fn($t) => !empty($t['dosen_id']))) ?> Tutor
                        </span>
                    </div>

                    <div id="qe_tutors_<?= $dayLower ?>_container" class="tutor-container" data-day="<?= $dayLower ?>">
                        <?php foreach ($assignedTutors as $idx => $tInfo): $selId = (int)($tInfo['dosen_id'] ?? 0); ?>
                        <div class="tutor-row" style="display:flex; gap:4px; margin-bottom:4px;">
                            <select name="tutors_<?= $dayLower ?>[]" class="tutor-select" style="flex:1; padding:6px 8px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:13px; background:#fff;" onchange="calculateQEKuota('<?= $dayLower ?>')">
                                <option value="">-- Pilih Tutor --</option>
                                <?php foreach ($tutorsList as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= ($selId === (int)$t['id']) ? 'selected' : '' ?>>
                                    <?= sanitize($t['nama']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($idx === 0): ?>
                            <button type="button" class="btn btn-sm btn-success add-tutor-btn" onclick="addQETutorRow('<?= $dayLower ?>')" style="padding:0 8px; font-weight:700;" title="Tambah Baris Tutor">+</button>
                            <?php else: ?>
                            <button type="button" class="btn btn-sm btn-danger remove-tutor-btn" onclick="this.parentElement.remove(); calculateQEKuota('<?= $dayLower ?>');" style="padding:0 8px;" title="Hapus Baris">×</button>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="font-size:12px; color:#64748b; margin-top:-2px; font-weight:600;">Kuota Hari <?= $day ?>:</div>
                    <input type="number" name="kuota_<?= $dayLower ?>" id="qe_kuota_<?= $dayLower ?>" value="<?= $totalKuota ?>" min="0" style="width:100%; padding:6px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:14px; background:#fff; font-weight:600; box-sizing:border-box;">

                    <div id="qe_sisa_kuota_<?= $dayLower ?>" data-terisi="<?= $terisi ?>" style="font-size:12px; margin-top:2px; font-weight:600; color: <?= $sisaKuota <= 0 ? '#ef4444' : '#10b981' ?>;">
                        Sisa Kuota: <?= max(0, $sisaKuota) ?><?= $sisaKuota <= 0 ? ' (Penuh)' : '' ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px; font-weight:600;">
                    <span>💾</span> Simpan Pengaturan Dosen &amp; Kuota
                </button>
                <div style="font-size:12.5px; color:#64748b;">
                    Total Kuota Periode Ini: <strong id="periodTotalQuotaDisplay"><?= (int)array_sum($periodDayQuotas) ?></strong> kursi
                </div>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning" style="margin-bottom:24px;">
    <strong>Belum ada periode pendaftaran tutorial RPL aktif.</strong>
    Silakan buat atau aktifkan periode terlebih dahulu pada menu <a href="<?= BASE_URL ?>/admin/tutorial-rpl-periode.php" style="font-weight:700; text-decoration:underline;">Periode Pendaftaran RPL</a>.
</div>
<?php endif; ?>

<!-- ===================================================
     CARD: GENERATE JADWAL & PLOTTING KELOMPOK RPL
     =================================================== -->
<div class="card" style="border: 2px dashed #3b82f6; background-color: #eff6ff; margin-bottom: 24px;">
    <div class="card-header" style="background-color: transparent; color: #1e40af; font-weight: 700; font-size: 16px;">
        ⚡ Generate Jadwal &amp; Plotting Kelompok RPL
    </div>
    <div class="card-body">
        <p style="color: #1e3a8a; font-size: 14px; margin-top: 0; margin-bottom: 16px; line-height: 1.6;">
            Sistem akan membagi mahasiswa program RPL yang belum memiliki kelompok kelas ke dalam kelompok daring yang tersedia, menugaskan dosen secara otomatis, dan mempersiapkan kelas untuk bimbingan video serta CBT.
        </p>

        <form method="POST" data-no-spa id="form-generate-rpl" onsubmit="return validateGenerateRpl(this);">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="generate_plotting">

            <div style="background: #fff; padding: 16px; border-radius: 8px; border: 1px solid #bfdbfe; margin-bottom: 16px;">
                <label style="display: block; font-weight: 700; color: #1e3a8a; margin-bottom: 12px; font-size: 14.5px;">
                    Mode Pembagian Mahasiswa:
                </label>

                <label style="display: flex; align-items: flex-start; gap: 8px; margin-bottom: 12px; cursor: pointer;">
                    <input type="radio" name="generate_mode" value="fill_first" id="modeFillFirst" style="margin-top: 4px;">
                    <div>
                        <span style="font-weight: 600; color: #334155;">Utamakan memenuhi kuota per kelompok kelas</span>
                        <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                            Jumlah mahasiswa per kelompok minimal
                            <input type="number" name="min_per_class" id="minPerClass" value="30" min="1" max="1000" style="width: 60px; padding: 4px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: center; font-weight: 600;">
                            lalu isi kelompok berikutnya atau buat kelompok baru.
                        </div>
                    </div>
                </label>

                <label style="display: flex; align-items: flex-start; gap: 8px; margin-bottom: 12px; cursor: pointer;">
                    <input type="radio" name="generate_mode" value="distribute_evenly" id="modeDistribute" checked style="margin-top: 4px;">
                    <div>
                        <span style="font-weight: 600; color: #334155;">Utamakan meratakan jumlah mahasiswa</span>
                        <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                            Membagi rata jumlah mahasiswa kepada seluruh kelompok kelas / dosen yang aktif pada periode ini (load balancing merata).
                        </div>
                    </div>
                </label>

                <label style="display: flex; align-items: flex-start; gap: 8px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #e2e8f0; cursor: pointer;">
                    <input type="checkbox" name="fill_existing_classes" id="fillExistingClasses" value="1" style="margin-top: 4px;">
                    <span style="color: #1e3a8a;">
                        <strong>Masukkan ke kelompok kelas yang sudah ada selama belum mencapai kuota per dosen</strong>
                        <small id="fillExistingClassesHelp" style="display: block; margin-top: 4px; line-height: 1.6; color: #64748b;">
                            Berlaku untuk kedua mode, sesuai periode RPL aktif. Kuota kelompok dan total mahasiswa per dosen dibatasi. Tidak membuat kelompok baru; sisa mahasiswa tetap menunggu dan daftar namanya ditampilkan setelah proses selesai. Dosen pembimbing lama dipertahankan.
                        </small>
                        <span style="display: block; font-size: 13px; margin-top: 6px; color: #334155;">
                            Batas mahasiswa per kelompok / dosen:
                            <input type="number" name="existing_tutor_quota" id="existingTutorQuota" value="30" min="1" max="1000" disabled style="width: 65px; padding: 3px 6px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: center; font-weight: 600;">
                        </span>
                    </span>
                </label>
            </div>

            <div style="display: grid; gap: 10px; margin-bottom: 18px;">
                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; color: #1e3a8a;">
                    <input type="checkbox" name="match_hari_pilihan" id="matchHariPilihan" value="1" checked style="margin-top: 4px;">
                    <span>
                        <strong>Prioritaskan penempatan sesuai Hari Pilihan pendaftaran mahasiswa</strong>
                        <small style="display: block; margin-top: 2px; color: #64748b;">
                            Mahasiswa yang memilih hari bimbingan tertentu (mis. Senin) saat mendaftar akan diprioritaskan ke kelompok dosen yang bertugas di hari tersebut.
                        </small>
                    </span>
                </label>

                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; color: #1e3a8a;">
                    <input type="checkbox" name="auto_create_classes" id="autoCreateClasses" value="1" checked style="margin-top: 4px;">
                    <span>
                        <strong>Otomatis buat kelompok kelas jika belum ada kelompok untuk dosen yang bertugas</strong>
                        <small style="display: block; margin-top: 2px; color: #64748b;">
                            Sistem akan otomatis mengenerate kelompok kelas tutorial RPL daring dari konfigurasi dosen aktif periode ini.
                        </small>
                    </span>
                </label>

                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; color: #1e3a8a;">
                    <input type="checkbox" name="overwrite_plotting" id="overwritePlotting" value="1" style="margin-top: 4px;">
                    <span>
                        <strong style="color: #b91c1c;">Tindih plotting jadwal yang telah ada</strong>
                        <small style="display: block; margin-top: 2px; color: #64748b;">
                            Centang untuk menyusun ulang / reset plotting seluruh anggota kelompok kelas periode ini. Jika tidak dicentang, mahasiswa yang sudah memiliki kelompok dipertahankan posisinya.
                        </small>
                    </span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary" style="background-color: #2563eb; width: auto; font-weight: 700; padding: 10px 20px; font-size: 14.5px;">
                🚀 Generate Jadwal &amp; Plotting secara otomatis
            </button>
        </form>
    </div>
</div>

<!-- ===================================================
     CARD: DAFTAR KELOMPOK KELAS & KUOTA TERKINI
     =================================================== -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
        <div style="font-weight: 700;">
            📋 Status Kelompok Kelas &amp; Kapasitas Terkini (<?= count($rplClasses) ?> Kelompok)
        </div>
        <a href="<?= BASE_URL ?>/admin/tutorial-rpl-kelas.php" class="btn btn-sm btn-secondary" style="font-weight: 600;">
            + Kelola Kelompok Baru
        </a>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($rplClasses)): ?>
        <div style="padding: 24px; text-align: center; color: #64748b;">
            Belum ada kelompok kelas RPL pada periode ini. Klik <strong>Generate Jadwal</strong> dengan opsi <em>Otomatis buat kelompok</em> untuk membuat kelompok secara instan dari jadwal dosen.
        </div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 13.5px; margin: 0;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 1.5px solid #e2e8f0; text-align: left;">
                        <th style="padding: 10px 14px;">No</th>
                        <th style="padding: 10px 14px;">Nama Kelompok</th>
                        <th style="padding: 10px 14px;">Dosen Pembimbing</th>
                        <th style="padding: 10px 14px;">Hari / Catatan</th>
                        <th style="padding: 10px 14px; text-align: center;">Kuota</th>
                        <th style="padding: 10px 14px; text-align: center;">Terisi</th>
                        <th style="padding: 10px 14px; text-align: center;">Sisa</th>
                        <th style="padding: 10px 14px; min-width: 120px;">Keterisian</th>
                        <th style="padding: 10px 14px; text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $no = 1;
                    foreach ($rplClasses as $c):
                        $kuota = (int)($c['kuota'] ?? 0);
                        $terisi = (int)($c['terisi'] ?? 0);
                        $sisa = max(0, $kuota - $terisi);
                        $pct = ($kuota > 0) ? min(100, round(($terisi / $kuota) * 100)) : 0;
                    ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 10px 14px; color: #64748b;"><?= $no++ ?></td>
                        <td style="padding: 10px 14px;">
                            <strong><?= sanitize($c['nama_kelompok']) ?></strong>
                            <?php if (empty($c['is_active'])): ?>
                                <span class="badge" style="background:#fee2e2; color:#991b1b; font-size:10.5px; padding:2px 6px;">Non-Aktif</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 10px 14px;">
                            <?= sanitize($c['dosen_nama'] ?: '-') ?>
                        </td>
                        <td style="padding: 10px 14px; color: #475569;">
                            <?= sanitize($c['catatan'] ?: '-') ?>
                        </td>
                        <td style="padding: 10px 14px; text-align: center; font-weight: 600;"><?= $kuota ?></td>
                        <td style="padding: 10px 14px; text-align: center; font-weight: 700; color: #2563eb;"><?= $terisi ?></td>
                        <td style="padding: 10px 14px; text-align: center; font-weight: 700; color: <?= $sisa <= 0 ? '#ef4444' : '#10b981' ?>;">
                            <?= $sisa ?>
                        </td>
                        <td style="padding: 10px 14px;">
                            <div style="background: #e2e8f0; border-radius: 999px; height: 8px; overflow: hidden; margin-bottom: 3px;">
                                <div style="background: <?= $pct >= 100 ? '#ef4444' : ($pct >= 70 ? '#f59e0b' : '#3b82f6') ?>; width: <?= $pct ?>%; height: 100%;"></div>
                            </div>
                            <span style="font-size: 11px; color: #64748b;"><?= $pct ?>% terisi</span>
                        </td>
                        <td style="padding: 10px 14px; text-align: center; white-space: nowrap;">
                            <a href="<?= BASE_URL ?>/admin/tutorial-rpl-pendaftaran.php" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 4px 8px;" title="Lihat Data Pendaftar">
                                👥 Anggota (<?= $terisi ?>)
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===================================================
     CLIENT-SIDE JAVASCRIPT
     =================================================== -->
<script>
function updateQESisaKuota(day) {
    var sisaEl = document.getElementById('qe_sisa_kuota_' + day);
    var kuotaInput = document.getElementById('qe_kuota_' + day);
    if (!sisaEl || !kuotaInput) return;

    var terisi = parseInt(sisaEl.getAttribute('data-terisi') || '0', 10);
    var kuota = parseInt(kuotaInput.value || '0', 10);
    var sisa = kuota - terisi;

    if (sisa <= 0) {
        sisaEl.style.color = '#ef4444';
        sisaEl.textContent = 'Sisa Kuota: ' + Math.max(0, sisa) + ' (Penuh)';
    } else {
        sisaEl.style.color = '#10b981';
        sisaEl.textContent = 'Sisa Kuota: ' + sisa;
    }
    recalculatePeriodTotalQuota();
}

function calculateQEKuota(day) {
    var container = document.getElementById('qe_tutors_' + day + '_container');
    if (!container) return;
    var selects = container.querySelectorAll('select');
    var validTutors = 0;
    selects.forEach(function(sel) {
        if (sel.value.trim() !== '') validTutors++;
    });

    var kuotaInput = document.getElementById('qe_kuota_' + day);
    if (kuotaInput) {
        kuotaInput.value = validTutors * 30;
        updateQESisaKuota(day);
    }
}

function recalculatePeriodTotalQuota() {
    var days = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'];
    var total = 0;
    days.forEach(function(day) {
        var inp = document.getElementById('qe_kuota_' + day);
        if (inp) {
            total += parseInt(inp.value || '0', 10);
        }
    });
    var display = document.getElementById('periodTotalQuotaDisplay');
    if (display) {
        display.textContent = total;
    }
}

function addQETutorRow(day) {
    var container = document.getElementById('qe_tutors_' + day + '_container');
    if (!container) return;

    var firstSelect = container.querySelector('.tutor-select');
    if (!firstSelect) return;

    var newSelect = firstSelect.cloneNode(true);
    newSelect.value = '';

    var div = document.createElement('div');
    div.className = 'tutor-row';
    div.style.display = 'flex';
    div.style.gap = '4px';
    div.style.marginBottom = '4px';

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-sm btn-danger remove-tutor-btn';
    removeBtn.style.padding = '0 8px';
    removeBtn.innerHTML = '×';
    removeBtn.title = 'Hapus Baris';
    removeBtn.onclick = function() {
        div.remove();
        calculateQEKuota(day);
    };

    div.appendChild(newSelect);
    div.appendChild(removeBtn);
    container.appendChild(div);
}

function validateGenerateRpl(form) {
    var overwrite = form.querySelector('[name="overwrite_plotting"]');
    if (overwrite && overwrite.checked) {
        return confirm('⚠️ Perhatian: Anda mencentang opsi "Tindih plotting jadwal yang telah ada".\n\nSeluruh mahasiswa pada kelompok kelas periode ini akan direset dan disusun ulang plottingnya. Apakah Anda yakin ingin melanjutkan?');
    }
    return true;
}

document.addEventListener('DOMContentLoaded', function() {
    var days = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'];
    days.forEach(function(day) {
        var inp = document.getElementById('qe_kuota_' + day);
        if (inp) {
            inp.addEventListener('input', function() {
                updateQESisaKuota(day);
            });
        }
    });

    var fillExisting = document.getElementById('fillExistingClasses');
    var existingQuota = document.getElementById('existingTutorQuota');
    var overwrite = document.getElementById('overwritePlotting');

    if (fillExisting && existingQuota) {
        fillExisting.addEventListener('change', function() {
            existingQuota.disabled = !this.checked;
            if (this.checked && overwrite) {
                overwrite.checked = false;
                overwrite.disabled = true;
            } else if (overwrite) {
                overwrite.disabled = false;
            }
        });
    }

    var modeFillFirst = document.getElementById('modeFillFirst');
    var minPerClass = document.getElementById('minPerClass');
    if (modeFillFirst && minPerClass) {
        modeFillFirst.addEventListener('change', function() {
            minPerClass.focus();
        });
        minPerClass.addEventListener('click', function() {
            modeFillFirst.checked = true;
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
