<?php
/**
 * LPPAI Corner - Admin: Data Pendaftar Tutorial RPL
 * Mengelola data pendaftaran, pilihan hari bimbingan, filter prodi/hari, dan pendaftaran manual mahasiswa RPL.
 * Antarmuka selaras dan identik dengan Data Peserta Pretes (admin/pretes-peserta.php).
 */
define('PAGE_TITLE', 'Data Pendaftar Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/tutorial-rpl-registration.php';
require_once __DIR__ . '/../includes/tutorial-rpl-periods.php';

if (!function_exists('sanitize')) {
    function sanitize($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('adminUserProfileLink')) {
    function adminUserProfileLink($name, $userId) {
        return '<a href="' . BASE_URL . '/admin/user-detail.php?id=' . (int)$userId . '" style="color:#0f172a; text-decoration:none; font-weight:700;">' . htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') . '</a>';
    }
}

$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$error = '';
$period = null;
$periods = [];
$students = [];
$registrations = [];
$count = 0;
$canRegister = false;
$days = [];
$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 80) : '';

try {
    $pdo = getDBConnection();
    ensureTutorialRplRegistrationTable($pdo);

    $hasMembers = tutorialRplTableExists($pdo, 'tutorial_rpl_members');
    $hasClasses = tutorialRplTableExists($pdo, 'tutorial_rpl_classes');

    // Deteksi kolom tabel users agar kompatibel dengan lingkungan uji SQLite & produksi
    $userColumns = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name')
        : array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC), 'Field');

    $prodiCol = in_array('program_studi', $userColumns, true) ? 'u.program_studi' : "'' AS program_studi";
    $emailCol = in_array('email', $userColumns, true) ? 'u.email' : "'' AS email";
    $fotoCol  = in_array('foto', $userColumns, true) ? 'u.foto' : "'' AS foto";
    $hpCol    = in_array('no_hp', $userColumns, true) ? 'u.no_hp' : "'' AS no_hp";
    $fakCol   = in_array('fakultas', $userColumns, true) ? 'u.fakultas' : "'' AS fakultas";

    // ── Handle Form POST Actions ────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) {
                http_response_code(403);
                throw new DomainException('Sesi formulir kedaluwarsa. Muat ulang halaman.');
            }

            $action = $_POST['action'] ?? '';

            // 1. Update Hari Pilihan Langsung dari Tabel atau Modal
            if ($action === 'update_hari') {
                $regId = (int)($_POST['reg_id'] ?? 0);
                $day = trim($_POST['hari_pilihan'] ?? '');
                if ($regId > 0 && in_array($day, TUTORIAL_RPL_DAYS, true)) {
                    $stmtNama = $pdo->prepare("SELECT u.nama_lengkap FROM tutorial_rpl_registrations r JOIN users u ON r.user_id = u.id WHERE r.id = ?");
                    $stmtNama->execute([$regId]);
                    $namaMhs = $stmtNama->fetchColumn() ?: 'Mahasiswa';

                    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
                    $pdo->prepare("UPDATE tutorial_rpl_registrations SET hari_pilihan = ?, day_selected_by = ?, day_selected_at = ? WHERE id = ?")
                        ->execute([$day, getCurrentUser()['id'], $now, $regId]);
                    $_SESSION['rpl_admin_registration_notice'] = "Hari bimbingan untuk <strong>" . $escape($namaMhs) . "</strong> berhasil diubah menjadi <strong>" . $escape($day) . "</strong>.";
                    $redirPeriod = (int)($_POST['period_id'] ?? 0);
                    header('Location: ' . BASE_URL . '/admin/tutorial-rpl-pendaftaran.php' . ($redirPeriod ? '?period_id=' . $redirPeriod : ''));
                    exit;
                } else {
                    throw new DomainException('Pilihan hari bimbingan tidak valid.');
                }

            // 2. Hapus Pendaftaran Mahasiswa
            } elseif ($action === 'delete_registration') {
                $regId = (int)($_POST['reg_id'] ?? 0);
                if ($regId > 0) {
                    if ($hasMembers) {
                        $checkMember = $pdo->prepare("SELECT m.id FROM tutorial_rpl_members m JOIN tutorial_rpl_registrations r ON r.user_id = m.user_id WHERE r.id = ?");
                        $checkMember->execute([$regId]);
                        if ($checkMember->fetchColumn()) {
                            throw new DomainException('Mahasiswa sudah diplot ke dalam kelompok kelas. Lepaskan dari kelompok terlebih dahulu di menu Kelola Kelompok & Tutor.');
                        }
                    }
                    $pdo->prepare("DELETE FROM tutorial_rpl_registrations WHERE id = ?")->execute([$regId]);
                    $_SESSION['rpl_admin_registration_notice'] = 'Pendaftaran peserta tutorial RPL berhasil dihapus.';
                    $redirPeriod = (int)($_POST['period_id'] ?? 0);
                    header('Location: ' . BASE_URL . '/admin/tutorial-rpl-pendaftaran.php' . ($redirPeriod ? '?period_id=' . $redirPeriod : ''));
                    exit;
                }

            // 3. Daftarkan Mahasiswa Manual (POST Action Bawaan)
            } else {
                $periodId = filter_var($_POST['period_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $studentId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$periodId || !$studentId) {
                    throw new DomainException('Mahasiswa atau periode tidak valid.');
                }
                $day = $_POST['hari_pilihan'] ?? null;
                if (!is_string($day) || !in_array($day, TUTORIAL_RPL_DAYS, true)) {
                    throw new DomainException('Pilih hari tutorial terlebih dahulu.');
                }
                $result = tutorialRplRegister($pdo, $periodId, $studentId, getCurrentUser(), 'admin', null, $day);
                $_SESSION['rpl_admin_registration_notice'] = !empty($result['day_selected'])
                    ? 'Mahasiswa berhasil didaftarkan beserta pilihan harinya. Pendaftaran tersimpan dan menunggu pembagian kelas.'
                    : 'Mahasiswa sudah terdaftar; tidak dibuat pendaftaran ganda.';
                header('Location: ' . BASE_URL . '/admin/tutorial-rpl-pendaftaran.php?period_id=' . $periodId);
                exit;
            }
        } catch (DomainException $e) {
            $error = $e->getMessage();
        }
    }

    // Ambil Semua Periode Tutorial RPL
    $periods = $pdo->query('SELECT * FROM tutorial_rpl_registration_periods ORDER BY opens_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    $requested = filter_var($_POST['period_id'] ?? $_GET['period_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $requestedAll = (($_GET['period_id'] ?? '') === '__all__');

    if ($requestedAll) {
        $period = null;
    } else {
        foreach ($periods as $item) {
            if (($requested && (int)$item['id'] === $requested) || (!$requested && (int)$item['active_slot'] === 1)) {
                $period = $item;
                break;
            }
        }
        if (!$period && !empty($periods) && !$requested) {
            $period = $periods[0];
        }
    }

    if ($requested && !$period && !$requestedAll) {
        throw new DomainException('Periode yang dipilih tidak ditemukan.');
    }

    if ($period) {
        $count = tutorialRplRegistrationCount($pdo, (int)$period['id']);
        $days = tutorialRplDayAvailability($pdo, (int)$period['id']);
        $window = tutorialRplRegistrationWindow(null, tutorialRplPeriodPolicy($period));
        $dayOpen = (bool)array_filter($days, static fn($d) => $d['remaining'] > 0);
        $canRegister = $window === 'open' && $dayOpen && ((int)$period['quota'] === 0 || $count < (int)$period['quota']);
        $windowLabels = [
            'inactive' => 'Periode nonaktif.',
            'soon'     => 'Pendaftaran belum dibuka.',
            'closed'   => 'Pendaftaran sudah ditutup.',
            'open'     => $canRegister ? 'Pendaftaran dibuka.' : ($dayOpen ? 'Batas total pendaftaran penuh.' : 'Pilihan hari belum tersedia atau kuota dosen penuh.')
        ];
    }

    // ── Kueri Data Pendaftar Lengkap ─────────────────────────────────────────
    $baseJoinMembers = ($hasMembers && $hasClasses) ? "
        LEFT JOIN tutorial_rpl_members m ON m.user_id = r.user_id
        LEFT JOIN tutorial_rpl_classes c ON c.id = m.class_id
        LEFT JOIN users tutor ON tutor.id = c.dosen_id
    " : "
        LEFT JOIN (SELECT NULL as id, NULL as user_id, NULL as class_id, NULL as drive_verified, NULL as exam_score, NULL as nilai_praktik, NULL as nilai_akhir, NULL as status_lulus) m ON 1=0
        LEFT JOIN (SELECT NULL as id, NULL as nama_kelompok, NULL as dosen_id) c ON 1=0
        LEFT JOIN (SELECT NULL as id, NULL as nama_lengkap) tutor ON 1=0
    ";

    $selectCols = "
        r.*,
        u.nama_lengkap, u.nim, u.jenis_program,
        $prodiCol, $emailCol, $fotoCol, $hpCol, $fakCol,
        a.nama_lengkap AS actor_name,
        m.id AS member_id, m.class_id, m.drive_verified, m.exam_score, m.nilai_praktik, m.nilai_akhir, m.status_lulus,
        c.nama_kelompok, c.dosen_id,
        tutor.nama_lengkap AS tutor_name,
        p.nama AS periode_nama, p.gelombang AS periode_gelombang
    ";

    if ($requestedAll) {
        $stmtReg = $pdo->query("
            SELECT $selectCols
            FROM tutorial_rpl_registrations r
            JOIN users u ON u.id = r.user_id
            JOIN tutorial_rpl_registration_periods p ON p.id = r.period_id
            LEFT JOIN users a ON a.id = r.registered_by
            $baseJoinMembers
            ORDER BY r.id DESC
        ");
        $registrations = $stmtReg->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($period) {
        $stmtReg = $pdo->prepare("
            SELECT $selectCols
            FROM tutorial_rpl_registrations r
            JOIN users u ON u.id = r.user_id
            JOIN tutorial_rpl_registration_periods p ON p.id = r.period_id
            LEFT JOIN users a ON a.id = r.registered_by
            $baseJoinMembers
            WHERE r.period_id = ?
            ORDER BY r.id DESC
        ");
        $stmtReg->execute([(int)$period['id']]);
        $registrations = $stmtReg->fetchAll(PDO::FETCH_ASSOC);
    }

    // Pencarian Mahasiswa untuk Didaftarkan Manual
    if ($period && mb_strlen($query) >= 2) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.nama_lengkap, u.nim, r.id AS registration_id, r.schedule_id
            FROM users u
            LEFT JOIN tutorial_rpl_registrations r ON r.user_id = u.id AND r.period_id = ?
            WHERE u.role = 'mahasiswa' AND UPPER(TRIM(u.jenis_program)) = 'RPL'
              AND (u.nama_lengkap LIKE ? OR u.nim LIKE ?)
            ORDER BY u.nama_lengkap, u.id
            LIMIT 20
        ");
        $stmt->execute([(int)$period['id'], '%' . $query . '%', '%' . $query . '%']);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (DomainException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('RPL admin registration: ' . $e->getMessage());
    $error = 'Pendaftaran atau hasil pretes belum dapat diperiksa. Coba kembali; jika berulang hubungi pengelola server.';
}

$notice = $_SESSION['rpl_admin_registration_notice'] ?? '';
unset($_SESSION['rpl_admin_registration_notice']);

// Hitung Statistik & Distribusi Hari/Prodi
$totalPeserta = count($registrations);
$totalSudahKelas = 0;
$totalBelumKelas = 0;
$totalVideoVerified = 0;
$dayCounts = array_fill_keys(TUTORIAL_RPL_DAYS, 0);
$prodiCounts = [];

foreach ($registrations as $reg) {
    if (!empty($reg['class_id'])) {
        $totalSudahKelas++;
    } else {
        $totalBelumKelas++;
    }
    if (!empty($reg['drive_verified'])) {
        $totalVideoVerified++;
    }
    $h = trim($reg['hari_pilihan'] ?? '');
    if ($h !== '' && isset($dayCounts[$h])) {
        $dayCounts[$h]++;
    }
    $prd = trim($reg['program_studi'] ?? '');
    if ($prd !== '') {
        $prodiCounts[$prd] = ($prodiCounts[$prd] ?? 0) + 1;
    }
}
ksort($prodiCounts);

include __DIR__ . '/../includes/header.php';
?>

<?php if ($notice): ?>
    <div class="alert alert-success" role="status"><?= $escape($notice) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger" role="alert"><?= $escape($error) ?></div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     CARD PEMILIH PERIODE TUTORIAL RPL (IDENTIK PRETES-PESERTA)
     ═══════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:20px; border-left:5px solid var(--primary); background:#f8fafc;">
    <div class="card-body" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; padding:16px 20px;">
        <div>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <strong style="font-size:15px; color:#0f172a;">📅 Periode Data Pendaftar:</strong>
                <?php if ($requestedAll): ?>
                    <span class="badge badge-secondary" style="font-size:12px;">Semua Periode (Arsip Gabungan)</span>
                <?php elseif ($period): ?>
                    <span style="font-weight:700; color:var(--primary); font-size:15px;"><?= $escape($period['nama']) ?></span>
                    <span class="badge badge-info" style="font-size:11px;"><?= $escape($period['gelombang']) ?></span>
                    <span class="badge" style="background:#e0f2fe; color:#0369a1; font-size:11px; border:1px solid #bae6fd;">Khusus Program RPL</span>
                    <?php if ((int)$period['active_slot'] === 1): ?>
                        <span class="badge badge-success" style="font-size:11px;">Aktif</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="badge badge-warning" style="font-size:12px;">Belum Ada Periode</span>
                <?php endif; ?>
            </div>
            <small style="color:#64748b; display:block; margin-top:4px;">
                <?php if ($period): ?>
                    Jadwal Pendaftaran: <strong><?= $escape(tutorialRplPeriodSchedule($period)) ?></strong> | Status: <strong><?= $escape($windowLabels[$window] ?? '') ?></strong>
                <?php else: ?>
                    Data pendaftar disaring sesuai periode yang dipilih agar teratur dan tidak tercampur antar periode.
                <?php endif; ?>
            </small>
        </div>
        <form method="GET" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:0;">
            <label for="select-periode-rpl" style="font-size:12.5px; font-weight:700; color:#334155; margin:0;">Ganti Periode:</label>
            <select name="period_id" id="select-periode-rpl" onchange="this.form.submit()"
                style="padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-weight:600; background:#fff; color:#0f172a; min-width:240px; cursor:pointer;">
                <?php foreach ($periods as $pItem): ?>
                    <?php
                    $isAct = ((int)$pItem['active_slot'] === 1);
                    $label = $pItem['nama'] . ' · ' . $pItem['gelombang'] . ($isAct ? ' (Aktif)' : '');
                    ?>
                    <option value="<?= (int)$pItem['id'] ?>" <?= ($period && (int)$period['id'] === (int)$pItem['id']) ? 'selected' : '' ?>>
                        <?= $escape($label) ?>
                    </option>
                <?php endforeach; ?>
                <option value="__all__" <?= $requestedAll ? 'selected' : '' ?>>
                    Semua Periode (Arsip Gabungan)
                </option>
            </select>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     STATISTIK RINGKAS PENDAFTAR (STAT-GRID)
     ═══════════════════════════════════════════════════════════════ -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon blue">👥</div>
        <div class="stat-info">
            <h3><?= $totalPeserta ?></h3>
            <p>Total Pendaftar RPL</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">🏫</div>
        <div class="stat-info">
            <h3><?= $totalSudahKelas ?></h3>
            <p>Sudah Masuk Kelompok</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">⏳</div>
        <div class="stat-info">
            <h3><?= $totalBelumKelas ?></h3>
            <p>Menunggu Plotting Kelas</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">📊</div>
        <div class="stat-info">
            <h3><?= $period ? ((int)$period['quota'] === 0 ? 'Mengikuti Dosen' : (int)$period['quota']) : '-' ?></h3>
            <p>Kapasitas Kuota Periode</p>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     CARD DAFTARKAN MAHASISWA MANUAL (COLLAPSIBLE)
     ═══════════════════════════════════════════════════════════════ -->
<?php if ($period): ?>
<div class="card" id="card-manual-register" style="display: <?= !empty($query) ? 'block' : 'none' ?>; margin-bottom:24px; border:1.5px solid #0284c7; background:#ffffff; box-shadow:0 2px 10px rgba(2,132,199,0.08); border-radius:14px; overflow:hidden;">
    <div class="card-header" style="background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color:#ffffff; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; padding:14px 20px;">
        <div style="display:flex; align-items:center; gap:10px;">
            <span style="font-size:20px;">➕</span>
            <div>
                <strong style="font-size:15px; color:#fff; display:block;">Daftarkan Mahasiswa RPL Manual</strong>
                <span style="font-size:11.5px; color:#e0f2fe;">Admin mendaftarkan mahasiswa langsung ke periode terpilih beserta pilihan hari tutorialnya.</span>
            </div>
        </div>
        <button type="button" onclick="toggleManualRegister()" style="background:rgba(0,0,0,0.25); color:#ffffff; border:1px solid rgba(255,255,255,0.3); padding:6px 12px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;">
            ✕ Sembunyikan
        </button>
    </div>
    <div class="card-body" style="padding:20px 22px;">
        <form method="GET" style="margin-bottom:16px;">
            <input type="hidden" name="period_id" value="<?= (int)$period['id'] ?>">
            <label for="q" style="font-size:13px; font-weight:700; color:#334155; margin-bottom:6px; display:block;">Cari Nama atau NIM Mahasiswa Program RPL:</label>
            <div style="display:flex; gap:8px; max-width:600px;">
                <input class="form-control" id="q" name="q" minlength="2" maxlength="80" value="<?= $escape($query) ?>" placeholder="Ketik minimal 2 karakter (contoh: 24042062 atau nama)..." style="flex:1; padding:9px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13.5px;">
                <button class="btn btn-primary" type="submit" style="padding:9px 20px; font-weight:700; white-space:nowrap;">
                    🔍 Cari Mahasiswa
                </button>
            </div>
            <small style="color:#64748b; font-size:12px; display:block; margin-top:6px;">
                💡 Kelulusan pretes (< 80) diperiksa ulang secara otomatis saat tombol Daftarkan ditekan.
            </small>
        </form>

        <?php if (!empty($query)): ?>
        <div class="table-responsive" style="margin-top:14px;">
            <table class="table" style="background:#fff; border:1px solid #e2e8f0; border-radius:8px;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th>Mahasiswa</th>
                        <th>NIM</th>
                        <th>Aksi Pendaftaran & Pilihan Hari</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $row): ?>
                    <tr>
                        <td><strong><?= $escape($row['nama_lengkap']) ?></strong></td>
                        <td><?= $escape($row['nim']) ?></td>
                        <td>
                            <?php if ($row['registration_id'] && $row['schedule_id'] !== null): ?>
                                <span class="badge badge-success">✅ Sudah Terdaftar</span>
                            <?php elseif (($canRegister || ($row['registration_id'] && $window === 'open')) && !$error && array_filter($days, static fn($d) => $d['remaining'] > 0)): ?>
                                <form method="POST" action="<?= BASE_URL ?>/admin/tutorial-rpl-pendaftaran.php?period_id=<?= (int)$period['id'] ?>&amp;q=<?= $escape(rawurlencode($query)) ?>" style="display:flex; align-items:center; gap:8px; margin:0; flex-wrap:wrap;">
                                    <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                    <input type="hidden" name="period_id" value="<?= (int)$period['id'] ?>">
                                    <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                                    <select class="form-control" name="hari_pilihan" required style="padding:6px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:13px; max-width:240px;">
                                        <option value="">-- Pilih Hari Tutorial --</option>
                                        <?php foreach ($days as $day => $capacity): ?>
                                            <?php
                                            $left = $capacity['remaining'];
                                            if (!$row['registration_id'] && (int)$period['quota'] > 0) {
                                                $left = min($left, max(0, (int)$period['quota'] - $count));
                                            }
                                            ?>
                                            <option value="<?= $escape($day) ?>" <?= $left < 1 ? 'disabled' : '' ?>>
                                                <?= $escape($day) ?> (sisa kuota: <?= $left ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-primary btn-sm" type="submit" style="font-weight:700; padding:6px 14px;">
                                        <?= $row['registration_id'] ? 'Simpan Hari' : '➕ Daftarkan' ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="badge badge-secondary">Pendaftaran Tidak Tersedia</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$students): ?>
                        <tr><td colspan="3" style="text-align:center; padding:16px; color:#64748b;">Mahasiswa RPL dengan kata kunci tersebut tidak ditemukan.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     CARD TABEL UTAMA DATA PENDAFTAR TUTORIAL RPL
     ═══════════════════════════════════════════════════════════════ -->
<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <span style="font-weight:700; font-size:15.5px;">📋 Data Pendaftar Tutorial RPL (<?= $totalPeserta ?> mahasiswa)</span>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <?php if ($period): ?>
            <button type="button" class="btn btn-sm btn-primary" onclick="toggleManualRegister()" style="font-weight:700; padding:7px 14px; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
                ➕ Daftarkan Manual
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-success" onclick="exportTableToExcel('table-pendaftar-rpl', 'Data_Pendaftar_Tutorial_RPL')" style="font-weight:700; padding:7px 14px; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
                📥 Export ke Excel
            </button>
            <button type="button" class="btn btn-sm btn-secondary" onclick="window.print()" style="font-weight:700; padding:7px 14px; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
                📄 Cetak
            </button>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($registrations)): ?>
            <div class="empty-state" style="text-align:center; padding:40px 20px;">
                <div class="icon" style="font-size:42px; margin-bottom:10px;">👥</div>
                <h3 style="margin:0 0 8px; font-size:18px; color:#334155;">Belum ada pendaftar tutorial RPL</h3>
                <p style="margin:0; color:#64748b;">Belum ada mahasiswa program RPL yang mendaftar pada periode ini.</p>
            </div>
        <?php else: ?>
        <!-- ── Filter Multi-Kolom di Atas Tabel Pendaftar ───────────── -->
        <div class="filter-panel" style="background:#ffffff; border:1.5px solid #e2e8f0; border-radius:12px; padding:18px 20px; margin-bottom:18px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:8px; background:#eff6ff; color:#2563eb; font-size:16px;">
                        🔍
                    </span>
                    <div>
                        <strong style="font-size:14px; color:#0f172a; display:block;">Filter Data Pendaftar (Sesuai Kolom Tabel)</strong>
                        <span style="font-size:12px; color:#64748b;">Filter instan berdasarkan Program Studi, Hari Pilihan, Status Kelompok Kelas, atau Verifikasi Tugas Video</span>
                    </div>
                </div>
                <div>
                    <button type="button" id="btn-reset-filters" class="btn btn-sm" style="background:#f1f5f9; border:1.5px solid #cbd5e1; color:#334155; font-weight:600; font-size:12px; padding:6px 14px; border-radius:8px; cursor:pointer;">
                        🔄 Reset Semua Filter
                    </button>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
                <!-- Filter Prodi -->
                <div>
                    <label for="filter-prodi" style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:5px;">
                        🎓 Program Studi
                    </label>
                    <select id="filter-prodi" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; background:#f8fafc; color:#1e293b; outline:none; box-sizing:border-box; cursor:pointer;">
                        <option value="">Semua Prodi (<?= count($prodiCounts) ?>)</option>
                        <?php foreach ($prodiCounts as $prd => $cnt): ?>
                            <option value="<?= $escape($prd) ?>"><?= $escape($prd) ?> (<?= $cnt ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Filter Hari Pilihan -->
                <div>
                    <label for="filter-hari" style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:5px;">
                        📅 Hari Pilihan Tutorial
                    </label>
                    <select id="filter-hari" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; background:#f8fafc; color:#1e293b; outline:none; box-sizing:border-box; cursor:pointer;">
                        <option value="">Semua Hari (<?= $totalPeserta ?>)</option>
                        <?php foreach (TUTORIAL_RPL_DAYS as $day): ?>
                            <option value="<?= $escape($day) ?>"><?= $escape($day) ?> (<?= $dayCounts[$day] ?? 0 ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Filter Status Kelompok Kelas -->
                <div>
                    <label for="filter-kelas" style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:5px;">
                        🏫 Status Kelompok Bimbingan
                    </label>
                    <select id="filter-kelas" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; background:#f8fafc; color:#1e293b; outline:none; box-sizing:border-box; cursor:pointer;">
                        <option value="">Semua Status Kelas (<?= $totalPeserta ?>)</option>
                        <option value="sudah">Sudah Masuk Kelompok (<?= $totalSudahKelas ?>)</option>
                        <option value="belum">Menunggu Plotting Kelas (<?= $totalBelumKelas ?>)</option>
                    </select>
                </div>

                <!-- Filter Status Verifikasi Tugas Video -->
                <div>
                    <label for="filter-video" style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:5px;">
                        📹 Verifikasi Tugas Video Praktik
                    </label>
                    <select id="filter-video" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; background:#f8fafc; color:#1e293b; outline:none; box-sizing:border-box; cursor:pointer;">
                        <option value="">Semua Status Video (<?= $totalPeserta ?>)</option>
                        <option value="verified">Terverifikasi (<?= $totalVideoVerified ?>)</option>
                        <option value="unverified">Belum / Menunggu (<?= $totalPeserta - $totalVideoVerified ?>)</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Sebaran Hari (Shortcut Badges) -->
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; align-items:center; background:#f8fafc; padding:10px 14px; border-radius:10px; border:1px solid #e2e8f0;">
            <span style="font-size:13px; font-weight:600; color:#475569;">📅 Distribusi Hari:</span>
            <button type="button" class="btn-filter-day active" data-day="" style="padding:4px 12px; border-radius:20px; border:1px solid #cbd5e1; background:#2563eb; color:#fff; font-size:12px; cursor:pointer; font-weight:600;">
                Semua (<?= $totalPeserta ?>)
            </button>
            <?php foreach (TUTORIAL_RPL_DAYS as $day): ?>
                <?php if (($dayCounts[$day] ?? 0) > 0 || in_array($day, ['Senin','Selasa','Rabu','Kamis','Jumat'])): ?>
                <button type="button" class="btn-filter-day" data-day="<?= $escape($day) ?>" style="padding:4px 12px; border-radius:20px; border:1px solid #cbd5e1; background:#fff; font-size:12px; cursor:pointer; font-weight:600; color:#334155;">
                    <?= $escape($day) ?>: <span style="color:#2563eb; font-weight:bold;"><?= $dayCounts[$day] ?? 0 ?></span>
                </button>
                <?php endif; ?>
            <?php endforeach; ?>
            <span style="margin-left:auto; font-size:12px; color:#64748b;">
                💡 <em>Admin dapat merubah hari pilihan mahasiswa langsung pada dropdown tabel atau tombol Edit.</em>
            </span>
        </div>

        <div class="table-responsive">
            <table class="table" id="table-pendaftar-rpl">
                <thead>
                    <tr>
                        <th style="width:40px;">No</th>
                        <th>Mahasiswa</th>
                        <th>NIM</th>
                        <th>Program Studi</th>
                        <th>Program</th>
                        <th>Hari Pilihan</th>
                        <th>Kelompok &amp; Tutor</th>
                        <th>Tugas Video</th>
                        <th>Nilai CBT / Akhir</th>
                        <th>Waktu Daftar</th>
                        <th style="min-width:140px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $i => $row): ?>
                    <?php
                    $curHari = trim($row['hari_pilihan'] ?? '');
                    $hasKelas = !empty($row['class_id']);
                    $isVerified = !empty($row['drive_verified']);
                    ?>
                    <tr class="pendaftar-row"
                        data-prodi="<?= $escape($row['program_studi'] ?? '') ?>"
                        data-hari="<?= $escape($curHari) ?>"
                        data-kelas="<?= $hasKelas ? 'sudah' : 'belum' ?>"
                        data-video="<?= $isVerified ? 'verified' : 'unverified' ?>">
                        <td><?= $i + 1 ?></td>
                        <td>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <?php if (!empty($row['foto'])): ?>
                                    <img src="<?= BASE_URL ?>/<?= htmlspecialchars(ltrim(str_replace('\\', '/', $row['foto']), '/')) ?>" alt="Foto" style="width:34px; height:44px; object-fit:cover; border-radius:4px; border:1px solid #cbd5e1; cursor:pointer;" onclick="window.open(this.src, '_blank')" title="Lihat foto">
                                <?php else: ?>
                                    <div style="width:34px; height:44px; border-radius:4px; background:#f1f5f9; border:1px dashed #cbd5e1; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:10px; font-weight:700;">3x4</div>
                                <?php endif; ?>
                                <div>
                                    <?= adminUserProfileLink($row['nama_lengkap'], $row['user_id']) ?>
                                    <?php if (!empty($row['email'])): ?>
                                        <div style="font-size:11.5px; color:#64748b; margin-top:2px;"><?= $escape($row['email']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><?= $escape($row['nim']) ?></td>
                        <td><?= $escape($row['program_studi'] ?: '-') ?></td>
                        <td><span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700;">RPL</span></td>
                        <td>
                            <!-- Dropdown Ubah Hari Cepat -->
                            <form method="POST" style="display:inline-flex; align-items:center; gap:6px; margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                <input type="hidden" name="action" value="update_hari">
                                <input type="hidden" name="reg_id" value="<?= (int)$row['id'] ?>">
                                <input type="hidden" name="period_id" value="<?= (int)$row['period_id'] ?>">
                                <select name="hari_pilihan" onchange="this.form.submit()"
                                    title="Klik untuk langsung mengubah hari bimbingan"
                                    style="padding:5px 8px; border-radius:6px; border:1.5px solid #cbd5e1; font-size:12.5px; font-weight:600; color:#1e293b; background:#fff; cursor:pointer;">
                                    <?php foreach (TUTORIAL_RPL_DAYS as $d): ?>
                                        <option value="<?= $escape($d) ?>" <?= $curHari === $d ? 'selected' : '' ?>>
                                            <?= $escape($d) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if (!in_array($curHari, TUTORIAL_RPL_DAYS, true) && $curHari !== ''): ?>
                                        <option value="<?= $escape($curHari) ?>" selected><?= $escape($curHari) ?></option>
                                    <?php endif; ?>
                                </select>
                            </form>
                        </td>
                        <td>
                            <?php if ($hasKelas): ?>
                                <strong><?= $escape($row['nama_kelompok']) ?></strong>
                                <div style="font-size:11.5px; color:#64748b; margin-top:2px;">👨‍🏫 <?= $escape($row['tutor_name'] ?: 'Tutor') ?></div>
                            <?php else: ?>
                                <span class="badge badge-warning" style="font-size:11px;">⏳ Belum Diplot</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isVerified): ?>
                                <span class="badge badge-success" style="font-size:11px;">✅ Terverifikasi</span>
                            <?php else: ?>
                                <span class="badge badge-secondary" style="font-size:11px;">Belum Diperiksa</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['nilai_akhir'])): ?>
                                <strong style="font-size:13.5px; color:<?= (float)$row['nilai_akhir'] >= 80 ? '#15803d' : '#b91c1c' ?>;">
                                    <?= number_format((float)$row['nilai_akhir'], 1) ?>
                                </strong>
                                <div style="font-size:10.5px; color:#64748b;"><?= (float)$row['nilai_akhir'] >= 80 ? 'Lulus' : 'Belum Lulus' ?></div>
                            <?php elseif (!empty($row['exam_score'])): ?>
                                <span style="font-size:12.5px; font-weight:600; color:#0369a1;">CBT: <?= number_format((float)$row['exam_score'], 1) ?></span>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-size:12px;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-size:12px; color:#334155;"><?= $escape($row['registered_at']) ?></div>
                            <span class="badge" style="background:<?= $row['channel'] === 'admin' ? '#fef3c7; color:#92400e;' : '#dcfce7; color:#166534;' ?> font-size:10px; margin-top:3px; display:inline-block;">
                                <?= $row['channel'] === 'admin' ? '👤 Admin' : '🌐 Mandiri' ?>
                            </span>
                        </td>
                        <td>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <button type="button" class="btn btn-sm btn-warning btn-edit-pendaftar"
                                    data-id="<?= (int)$row['id'] ?>"
                                    data-nama="<?= $escape($row['nama_lengkap']) ?>"
                                    data-nim="<?= $escape($row['nim']) ?>"
                                    data-hari="<?= $escape($curHari) ?>"
                                    data-period-id="<?= (int)$row['period_id'] ?>"
                                    title="Edit Hari Pilihan">
                                    ✏️ Edit
                                </button>
                                <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus pendaftaran mahasiswa <?= htmlspecialchars(addslashes($row['nama_lengkap']), ENT_QUOTES, 'UTF-8') ?>? Tindakan ini tidak dapat dibatalkan.');">
                                    <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_registration">
                                    <input type="hidden" name="reg_id" value="<?= (int)$row['id'] ?>">
                                    <input type="hidden" name="period_id" value="<?= (int)$row['period_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Hapus Pendaftaran">
                                        🗑️
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

<!-- ═══════════════════════════════════════════════════════════════
     MODAL EDIT PENDAFTARAN MAHASISWA
     ═══════════════════════════════════════════════════════════════ -->
<div id="modal-edit-pendaftar" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(2px); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:14px; padding:26px 30px; width:100%; max-width:460px; box-shadow:0 20px 40px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
            <h3 style="margin:0; font-size:18px; color:#0f172a;">✏️ Edit Data Pendaftar</h3>
            <button type="button" onclick="closeEditModal()" style="background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <p id="modal-mhs-info" style="color:#64748b; font-size:13px; margin:0 0 16px;"></p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
            <input type="hidden" name="action" value="update_hari">
            <input type="hidden" name="reg_id" id="modal-reg-id">
            <input type="hidden" name="period_id" id="modal-period-id">

            <div class="form-group" style="margin-bottom:18px;">
                <label style="font-size:13px; font-weight:700; color:#334155; margin-bottom:6px; display:block;">Pilihan Hari Tutorial RPL *</label>
                <select name="hari_pilihan" id="modal-hari-select" required
                    style="width:100%; padding:9px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; color:#1e293b; outline:none; box-sizing:border-box;">
                    <?php foreach (TUTORIAL_RPL_DAYS as $d): ?>
                        <option value="<?= $escape($d) ?>"><?= $escape($d) ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color:#64748b; font-size:11.5px; display:block; margin-top:4px;">Mahasiswa akan dijadwalkan bimbingan dan evaluasi pada hari ini.</small>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:14px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()" style="padding:8px 18px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding:8px 22px; font-weight:700;">💾 Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleManualRegister() {
    const card = document.getElementById('card-manual-register');
    if (!card) return;
    const isHidden = (card.style.display === 'none');
    card.style.display = isHidden ? 'block' : 'none';
    if (isHidden) {
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function closeEditModal() {
    const m = document.getElementById('modal-edit-pendaftar');
    if (m) m.style.display = 'none';
}

function exportTableToExcel(tableID, filename = 'Data_Pendaftar_RPL') {
    const table = document.getElementById(tableID);
    if (!table) return;
    let csv = [];
    const rows = table.querySelectorAll('tr');
    for (let i = 0; i < rows.length; i++) {
        if (rows[i].style.display === 'none') continue;
        let row = [], cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length - 1; j++) { // Lewati kolom Aksi
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/"/g, '""').trim();
            row.push('"' + data + '"');
        }
        csv.push(row.join(','));
    }
    const csvFile = new Blob(["\uFEFF" + csv.join("\n")], { type: 'text/csv;charset=utf-8;' });
    const downloadLink = document.createElement('a');
    downloadLink.download = filename + '.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

document.addEventListener('DOMContentLoaded', function() {
    // Modal Edit Pendaftaran
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-edit-pendaftar');
        if (btn) {
            const d = btn.dataset;
            document.getElementById('modal-reg-id').value = d.id;
            document.getElementById('modal-period-id').value = d.periodId;
            document.getElementById('modal-mhs-info').innerHTML = '<strong>' + (d.nama || '') + '</strong> (' + (d.nim || '') + ')';
            document.getElementById('modal-hari-select').value = d.hari || 'Senin';
            const m = document.getElementById('modal-edit-pendaftar');
            if (m) m.style.display = 'flex';
        }
        if (e.target && e.target.id === 'modal-edit-pendaftar') {
            closeEditModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeEditModal();
    });

    // Multi-Filter Tabel
    const filterProdi = document.getElementById('filter-prodi');
    const filterHari = document.getElementById('filter-hari');
    const filterKelas = document.getElementById('filter-kelas');
    const filterVideo = document.getElementById('filter-video');
    const dayPills = document.querySelectorAll('.btn-filter-day');

    function applyFilters() {
        const valProdi = (filterProdi ? filterProdi.value.toLowerCase() : '');
        const valHari = (filterHari ? filterHari.value.toLowerCase() : '');
        const valKelas = (filterKelas ? filterKelas.value : '');
        const valVideo = (filterVideo ? filterVideo.value : '');

        const rows = document.querySelectorAll('.pendaftar-row');
        rows.forEach(function(row) {
            const rowProdi = (row.dataset.prodi || '').toLowerCase();
            const rowHari = (row.dataset.hari || '').toLowerCase();
            const rowKelas = row.dataset.kelas || '';
            const rowVideo = row.dataset.video || '';

            let match = true;
            if (valProdi && rowProdi !== valProdi) match = false;
            if (valHari && rowHari !== valHari) match = false;
            if (valKelas && rowKelas !== valKelas) match = false;
            if (valVideo && rowVideo !== valVideo) match = false;

            row.style.display = match ? '' : 'none';
        });

        // Update active class on day pills
        dayPills.forEach(function(pill) {
            const dVal = (pill.dataset.day || '').toLowerCase();
            if (dVal === valHari) {
                pill.style.background = '#2563eb';
                pill.style.color = '#fff';
            } else {
                pill.style.background = '#fff';
                pill.style.color = '#334155';
            }
        });
    }

    if (filterProdi) filterProdi.addEventListener('change', applyFilters);
    if (filterHari) filterHari.addEventListener('change', applyFilters);
    if (filterKelas) filterKelas.addEventListener('change', applyFilters);
    if (filterVideo) filterVideo.addEventListener('change', applyFilters);

    dayPills.forEach(function(pill) {
        pill.addEventListener('click', function() {
            if (filterHari) {
                filterHari.value = this.dataset.day || '';
                applyFilters();
            }
        });
    });

    const btnReset = document.getElementById('btn-reset-filters');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            if (filterProdi) filterProdi.value = '';
            if (filterHari) filterHari.value = '';
            if (filterKelas) filterKelas.value = '';
            if (filterVideo) filterVideo.value = '';
            applyFilters();
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>