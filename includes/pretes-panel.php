<?php
/**
 * LPPAI Corner - Panel Penilaian & Absensi Pretes (Dosen & Admin)
 * Antarmuka modern, responsif, dan elegan untuk penilaian 12 komponen pretes serta QR absensi mandiri.
 */
require_once __DIR__ . '/auth.php';
define('PAGE_TITLE', 'Penilaian & Absensi Pretes');
require_once __DIR__ . '/pretes-helper.php';

$user = getCurrentUser();
if (!in_array($user['role'] ?? '', ['admin', 'dosen'], true)) {
    http_response_code(403);
    exit('Akses ditolak.');
}

$pdo = getDBConnection();
ensurePretesFeatures($pdo);

$message = '';
$msgType = 'success';
$periods = $pdo->query("SELECT * FROM master_pretes ORDER BY (status = 'aktif') DESC, created_at DESC")->fetchAll();
$periodId = filter_var($_GET['pretes_id'] ?? ($periods[0]['id'] ?? 0), FILTER_VALIDATE_INT);
$period = null;
foreach ($periods as $item) {
    if ((int)$item['id'] === (int)$periodId) $period = $item;
}

// ── Proses POST: Simpan Nilai / Buka & Tutup Absensi ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
              || (isset($_POST['is_ajax']) && (string)$_POST['is_ajax'] === '1');
    try {
        if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) {
            throw new InvalidArgumentException('Sesi tidak valid. Muat ulang halaman.');
        }
        if (!$period) {
            throw new InvalidArgumentException('Periode pretes tidak ditemukan.');
        }
        $action = $_POST['action'] ?? '';
        $pdo->beginTransaction();

        if ($action === 'save_scores') {
            $regId = filter_var($_POST['registration_id'] ?? null, FILTER_VALIDATE_INT);
            $stmt = $pdo->prepare('SELECT * FROM pretes_registrations WHERE id = ? AND periode = ? FOR UPDATE');
            $stmt->execute([$regId ?: 0, $period['periode']]);
            $registration = $stmt->fetch();
            if (!$registration || !pretesCanAssess($user, $registration)) {
                throw new InvalidArgumentException('Peserta bukan penugasan Anda atau sudah dipindahkan.');
            }
            $scores = pretesValidateScores($_POST['scores'] ?? null);
            $stmt = $pdo->prepare('INSERT INTO pretes_assessments (registration_id, scores_json, assessed_by) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE scores_json = VALUES(scores_json), assessed_by = VALUES(assessed_by), updated_at = CURRENT_TIMESTAMP');
            $stmt->execute([$regId, json_encode($scores), $user['id']]);
            $message = 'Nilai 12 komponen berhasil disimpan dengan sukses.';

            $pdo->commit();

            if ($isAjax) {
                $count = count($scores);
                $sum = array_sum($scores);
                $avg = $count > 0 ? round($sum / $count, 2) : 0;
                $statusLulus = getPretesStatusLulus($scores);
                $predikat = getPretesPredikat($avg);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'registration_id' => $regId,
                    'scores' => $scores,
                    'count' => $count,
                    'average' => number_format($avg, 2, '.', ''),
                    'predikat' => $predikat,
                    'status_lulus' => $statusLulus,
                ]);
                exit;
            }
        } elseif ($action === 'open_attendance' || $action === 'close_attendance') {
            $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            $stmt = $pdo->prepare('SELECT * FROM pretes_attendance_sessions WHERE id = ? AND periode = ? FOR UPDATE');
            $stmt->execute([$sessionId ?: 0, $period['periode']]);
            $session = $stmt->fetch();
            if (!$session || !pretesCanAssess($user, ['dosen_penguji' => $session['penguji']])) {
                throw new InvalidArgumentException('Sesi bukan penugasan Anda.');
            }
            // Periksa bahwa penugasan sesi masih valid
            $stmt = $pdo->prepare('SELECT * FROM pretes_registrations WHERE periode = ? AND TRIM(dosen_penguji) = ?');
            $stmt->execute([$period['periode'], $session['penguji']]);
            $valid = false;
            foreach ($stmt->fetchAll() as $row) {
                if (pretesScopeHash(pretesScope($row)) === $session['scope_hash']) $valid = true;
            }
            if (!$valid) {
                throw new InvalidArgumentException('Sesi tidak lagi memiliki peserta. Muat ulang halaman.');
            }
            $until = $action === 'open_attendance' ? 'DATE_ADD(NOW(), INTERVAL 2 HOUR)' : 'NULL';
            $pdo->prepare("UPDATE pretes_attendance_sessions SET open_until = $until WHERE id = ?")->execute([$session['id']]);
            $message = $action === 'open_attendance' ? 'Sesi absensi berhasil dibuka selama 2 jam. Mahasiswa dapat scan QR sekarang.' : 'Sesi absensi berhasil ditutup.';

            $pdo->commit();

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'session_id' => $sessionId,
                    'is_open' => ($action === 'open_attendance'),
                ]);
                exit;
            }
        } elseif ($action === 'import_cbt_scores') {
            if ($user['role'] !== 'admin') {
                throw new InvalidArgumentException('Hanya Administrator yang memiliki wewenang untuk mengimpor nilai CBT.');
            }
            require_once __DIR__ . '/pretes-cbt-importer.php';
            require_once __DIR__ . '/import_eujian_excel.php';

            $rows = [];
            if (!empty($_POST['cbt_text'])) {
                $rows = parsePretesCbtText($_POST['cbt_text']);
            } elseif (isset($_FILES['cbt_file']) && $_FILES['cbt_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['cbt_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext === 'csv') {
                    $rows = parsePretesCbtText(file_get_contents($file['tmp_name']));
                } else {
                    $rows = parseSpreadsheetRows($file['tmp_name'], $file['name']);
                }
            }

            if (empty($rows)) {
                throw new InvalidArgumentException('Tidak ada data baris yang ditemukan untuk diimpor.');
            }

            $targetPeriode = trim($_POST['target_periode'] ?? ($period['periode'] ?? ''));
            $res = importPretesCbtData($pdo, $rows, $targetPeriode, (int)$user['id'], [
                'overwrite' => !empty($_POST['overwrite']),
                'auto_register' => !empty($_POST['auto_register']),
            ]);

            $pdo->commit();
            $message = $res['message'];
            $msgType = $res['success'] ? 'success' : 'danger';

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($res);
                exit;
            }
        } else {
            throw new InvalidArgumentException('Tindakan tidak valid.');
        }

        $_SESSION['pretes_flash'] = $message;
        header('Location: ?' . http_build_query(['pretes_id' => $periodId, 'registration_id' => $_POST['registration_id'] ?? '', 'session_id' => $_POST['session_id'] ?? '']) . (isset($sessionId) ? '#qr-session-' . (int)$sessionId : '#peserta-pretes'));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Gagal menyimpan. Silakan coba kembali.';
        if (!($e instanceof InvalidArgumentException)) error_log('Pretes panel: ' . $e->getMessage());
        $msgType = 'danger';

        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => $message,
            ]);
            exit;
        }
    }
}

if (!$message && isset($_SESSION['pretes_flash'])) {
    $message = $_SESSION['pretes_flash'];
    unset($_SESSION['pretes_flash']);
}

// ── Ambil Data Mahasiswa Ditugaskan ─────────────────────────────────────────
$participants = [];
if ($period) {
    $sql = 'SELECT pr.*, u.nama_lengkap, u.nim, u.program_studi, pa.scores_json, pa.updated_at AS assessed_at,
        ar.attended_at FROM pretes_registrations pr 
        JOIN users u ON u.id = pr.user_id
        LEFT JOIN pretes_assessments pa ON pa.registration_id = pr.id
        LEFT JOIN pretes_attendance_records ar ON ar.registration_id = pr.id 
        WHERE pr.periode = ?';
    $params = [$period['periode']];
    if ($user['role'] !== 'admin') {
        $sql .= ' AND TRIM(pr.dosen_penguji) = ?';
        $params[] = trim($user['nama_lengkap']);
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY pr.hari_pilihan, pr.ruangan, u.nama_lengkap, pr.id');
    $stmt->execute($params);
    $participants = array_values(array_filter($stmt->fetchAll(), fn($row) => pretesCanAssess($user, $row)));
}

// ── Pengelompokan Sesi & Peserta Terpilih ────────────────────────────────────
$groups = [];
$selected = null;
$selectedId = filter_var($_GET['registration_id'] ?? null, FILTER_VALIDATE_INT);
foreach ($participants as $row) {
    $scope = pretesScope($row);
    $hash = pretesScopeHash($scope);
    if (!isset($groups[$hash])) $groups[$hash] = ['scope' => $scope, 'count' => 0];
    $groups[$hash]['count']++;
    if ((int)$row['id'] === $selectedId) $selected = $row;
}

$components = pretesComponents();
$progress = pretesProgress($participants);

// Pengelompokan 12 Komponen untuk Antarmuka yang Rapih & Terstruktur
$componentCategories = [
    'Ujian CBT' => [
        'ujian_tulis' => 'Ujian tulis (CBT)'
    ],
    'Praktik Ibadah & Thaharah' => [
        'praktik_wudlu' => 'Praktik wudlu',
        'doa_setelah_wudlu' => 'Doa setelah wudlu',
        'praktik_tayammum' => 'Praktik tayammum',
        'niat_mandi_besar' => 'Niat mandi besar',
        'niat_jamak_qashar' => 'Niat jamak qashar',
        'bacaan_shalat_subuh' => 'Bacaan shalat Subuh',
        'shalat_jenazah' => 'Shalat jenazah'
    ],
    'Al-Qur\'an & Doa' => [
        'al_bayyinah' => 'Surat Al-Bayyinah',
        'al_alaq' => "Surat Al-'Alaq",
        'al_dluha' => 'Surat Ad-Dhuha',
        'istighasah' => 'Doa Istighotsah'
    ]
];

include __DIR__ . '/header.php';
?>

<style>
/* ── Styling Modern Panel Penilaian Pretes ── */
.pretes-hero {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 50%, #3b82f6 100%);
    color: #ffffff;
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 4px 20px rgba(37,99,235,0.18);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 18px;
    box-sizing: border-box;
}

.pretes-hero-title h2 {
    margin: 0;
    font-size: 21px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: -0.3px;
}

.pretes-hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.18);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 700;
    margin-bottom: 6px;
    backdrop-filter: blur(4px);
}

.pretes-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.pretes-stat-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    transition: transform 0.2s, box-shadow 0.2s;
}

.pretes-stat-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(0,0,0,0.06);
}

.pretes-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}

/* Outer Card Accordion QR Code Absensi */
.pretes-qr-card-accordion {
    transition: box-shadow 0.2s, border-color 0.2s;
}
.pretes-qr-card-accordion:hover {
    border-color: #cbd5e1 !important;
}
.pretes-qr-card-accordion[open] {
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
}
.pretes-qr-card-accordion summary::-webkit-details-marker {
    display: none;
}
.pretes-qr-card-accordion summary {
    list-style: none;
    outline: none;
    cursor: pointer;
    user-select: none;
    transition: background 0.15s ease;
}
.pretes-qr-card-accordion summary:hover {
    background: #f1f5f9 !important;
}
.pretes-qr-card-accordion[open] > summary {
    border-bottom: 1.5px solid #e2e8f0;
}
.pretes-qr-card-accordion:not([open]) > summary {
    border-bottom: none !important;
}
.pretes-qr-card-accordion .summary-chevron {
    transition: transform 0.25s ease;
    display: inline-block;
}
.pretes-qr-card-accordion[open] > summary .summary-chevron {
    transform: rotate(180deg);
}

/* Accordion QR Sessions */
.pretes-qr-sessions {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.pretes-qr-session {
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    transition: border-color 0.2s, box-shadow 0.2s;
}

.pretes-qr-session[open] {
    border-color: #3b82f6;
    box-shadow: 0 4px 16px rgba(59,130,246,0.08);
}

.pretes-qr-session summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    cursor: pointer;
    list-style: none;
    background: #f8fafc;
    user-select: none;
    transition: background 0.15s;
    outline: none;
}

.pretes-qr-session summary::-webkit-details-marker {
    display: none;
}

.pretes-qr-session summary:hover {
    background: #f1f5f9;
}

.pretes-qr-session[open] summary {
    background: #f0f9ff;
    border-bottom: 1.5px solid #bae6fd;
}

.pretes-qr-session .summary-chevron {
    font-size: 13px;
    color: #64748b;
    transition: transform 0.25s ease;
    margin-left: 12px;
    display: inline-block;
}

.pretes-qr-session[open] .summary-chevron {
    transform: rotate(180deg);
    color: #0284c7;
}

/* Score Input Components */
.pretes-score-box {
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px;
    box-sizing: border-box;
    transition: border-color 0.2s, background 0.2s;
}

.pretes-score-box:focus-within {
    border-color: #3b82f6;
    background: #ffffff;
    box-shadow: 0 2px 10px rgba(59,130,246,0.08);
}

.pretes-score-row input {
    width: 72px;
    height: 40px;
    padding: 6px;
    text-align: center;
    font-size: 15px;
    font-weight: 800;
    border: 1.5px solid #cbd5e1;
    border-radius: 8px;
    background: #ffffff;
    outline: none;
    transition: border-color 0.2s;
}

.pretes-score-row input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
}

.score-preset {
    flex: 1;
    height: 40px;
    padding: 0;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #ffffff;
    color: #334155;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s;
}

.score-preset:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}

.score-preset[aria-pressed="true"] {
    background: #16a34a !important;
    color: #ffffff !important;
    border-color: #16a34a !important;
    font-weight: 800 !important;
    box-shadow: 0 2px 6px rgba(22,163,74,0.3) !important;
}

@keyframes pulseGlow {
    0% { transform: scale(0.92); opacity: 0.7; }
    50% { transform: scale(1.15); opacity: 1; }
    100% { transform: scale(0.92); opacity: 0.7; }
}

@media (max-width: 992px) {
    .pretes-stat-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .pretes-hero {
        padding: 18px 16px;
    }
}

@media (max-width: 480px) {
    .pretes-stat-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="border-radius:12px;margin-bottom:20px;font-weight:600;">
        <?= $msgType === 'success' ? '✅' : '⚠️' ?> <?= sanitize($message) ?>
    </div>
<?php endif; ?>

<!-- ── HERO / BANNER IDENTITAS DOSEN & FILTER PERIODE ── -->
<div class="pretes-hero">
    <div class="pretes-hero-title">
        <div class="pretes-hero-badge">
            <span>🎓</span>
            <span>Portal Penguji Pretes</span>
        </div>
        <h2>Penilaian & Absensi Pretes</h2>
        <div style="font-size:13px;color:#dbeafe;margin-top:4px;">
            Penguji: <strong><?= sanitize($user['nama_lengkap']) ?></strong>
            <?php if ($user['role'] === 'admin'): ?>
                <span class="badge badge-warning" style="margin-left:6px;font-size:10px;">Mode Admin</span>
            <?php endif; ?>
            · Waktu Ujian: <strong>13.00 WIB</strong>
        </div>
    </div>

    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <!-- Periode Selector Form -->
        <form method="GET" data-no-spa style="display:flex;align-items:center;gap:10px;background:rgba(255,255,255,0.15);padding:8px 12px;border-radius:12px;backdrop-filter:blur(6px);margin:0;">
            <label for="pretes-period" style="font-size:12.5px;font-weight:700;color:#ffffff;margin:0;white-space:nowrap;">
                📅 Periode:
            </label>
            <select id="pretes-period" name="pretes_id" onchange="this.form.submit()" 
                style="background:#ffffff;color:#1e293b;border:none;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;outline:none;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
                <?php foreach ($periods as $item): ?>
                <option value="<?= (int)$item['id'] ?>" <?= (int)$item['id'] === (int)$periodId ? 'selected' : '' ?>>
                    <?= sanitize($item['periode']) ?> <?= $item['status'] === 'aktif' ? '(Aktif)' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm btn-light">Pilih</button></noscript>
        </form>

        <?php if ($user['role'] === 'admin'): ?>
        <button type="button" class="btn btn-warning" onclick="openImportCbtModal()"
            style="background:#f59e0b;color:#ffffff;border:none;padding:8px 16px;border-radius:12px;font-size:13px;font-weight:800;cursor:pointer;box-shadow:0 3px 10px rgba(0,0,0,0.15);display:inline-flex;align-items:center;gap:6px;transition:all 0.15s ease;">
            📥 Import Nilai CBT
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ── STAT CARDS (OVERVIEW PROGRES PENILAIAN) ── -->
<div class="pretes-stat-grid">
    <!-- Card 1: Total Mahasiswa -->
    <div class="pretes-stat-box">
        <div class="pretes-stat-icon" style="background:#eff6ff;color:#2563eb;">👥</div>
        <div>
            <div style="font-size:24px;font-weight:800;color:#0f172a;line-height:1.1;">
                <?= $progress['total'] ?>
            </div>
            <div style="font-size:12px;color:#64748b;font-weight:600;margin-top:2px;">
                Total Mahasiswa
            </div>
        </div>
    </div>

    <!-- Card 2: Hadir Absensi -->
    <div class="pretes-stat-box">
        <div class="pretes-stat-icon" style="background:#f0fdf4;color:#16a34a;">📱</div>
        <div>
            <div style="font-size:24px;font-weight:800;color:#16a34a;line-height:1.1;">
                <?= $progress['hadir'] ?> 
                <span style="font-size:13px;color:#64748b;font-weight:600;">/ <?= $progress['total'] ?></span>
            </div>
            <div style="font-size:12px;color:#64748b;font-weight:600;margin-top:2px;">
                Telah Hadir (<?= $progress['total'] > 0 ? round(($progress['hadir']/$progress['total'])*100) : 0 ?>%)
            </div>
        </div>
    </div>

    <!-- Card 3: Nilai Lengkap -->
    <div class="pretes-stat-box">
        <div class="pretes-stat-icon" style="background:#f0fdfa;color:#0f766e;">✅</div>
        <div>
            <div style="font-size:24px;font-weight:800;color:#0f766e;line-height:1.1;" id="stat-lengkap-count">
                <?= $progress['lengkap'] ?>
            </div>
            <div style="font-size:12px;color:#64748b;font-weight:600;margin-top:2px;">
                Nilai Lengkap (12/12)
            </div>
        </div>
    </div>

    <!-- Card 4: Belum Lengkap -->
    <div class="pretes-stat-box">
        <div class="pretes-stat-icon" style="background:#fff7ed;color:#ea580c;">⏳</div>
        <div>
            <div style="font-size:24px;font-weight:800;color:#ea580c;line-height:1.1;" id="stat-belum-count">
                <?= $progress['sebagian'] + $progress['kosong'] ?>
            </div>
            <div style="font-size:12px;color:#64748b;font-weight:600;margin-top:2px;">
                Belum Selesai Dinilai
            </div>
        </div>
    </div>
</div>

<?php if (!$participants): ?>
    <div class="alert alert-info" style="border-radius:12px;margin-bottom:24px;font-weight:600;">
        ℹ️ Belum ada peserta pretes yang ditugaskan kepada Anda pada periode <strong><?= sanitize($period['periode'] ?? '') ?></strong>.
    </div>
<?php endif; ?>

<!-- ── 1. SESI QR CODE ABSENSI PRETES (ACCORDION) ── -->
<?php if (!empty($groups)): 
    $anySessionOpen = false;
    foreach ($groups as $g) {
        $checkSess = pretesSession($pdo, $g['scope']);
        if (!empty($checkSess['is_open'])) {
            $anySessionOpen = true;
            break;
        }
    }
    // Terbuka otomatis hanya jika ada parameter session_id dari redirect atau open_qr
    $isCardOpen = isset($_GET['session_id']) || (isset($_GET['open_qr']) && $_GET['open_qr'] == '1');
?>
<details class="card pretes-qr-card-accordion" id="absensi-pretes" <?= $isCardOpen ? 'open' : '' ?> style="border-radius:16px;border:1px solid #e2e8f0;margin-bottom:24px;overflow:hidden;background:#ffffff;">
    <summary class="card-header pretes-qr-card-header" style="background:#f8fafc;padding:14px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;cursor:pointer;list-style:none;user-select:none;">
        <div style="display:flex;align-items:center;gap:12px;min-width:0;">
            <div style="width:38px;height:38px;border-radius:10px;background:#e0f2fe;color:#0284c7;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;">
                📱
            </div>
            <div style="min-width:0;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <h3 style="margin:0;font-size:15px;font-weight:800;color:#0f172a;">Sesi QR Code Absensi Mahasiswa</h3>
                    <span class="badge" style="background:#e2e8f0;color:#475569;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;">
                        <?= count($groups) ?> Sesi
                    </span>
                    <?php if ($anySessionOpen): ?>
                        <span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:800;display:inline-flex;align-items:center;gap:5px;box-shadow:0 0 0 2px rgba(34,197,94,0.15);">
                            <span style="width:6px;height:6px;border-radius:50%;background:#16a34a;animation:pulseGlow 1.5s infinite;"></span> ABSENSI BUKA
                        </span>
                    <?php endif; ?>
                </div>
                <span class="qr-card-subtitle" style="font-size:12px;color:#64748b;display:block;margin-top:2px;">
                    Klik untuk <span class="action-text"><?= $isCardOpen ? 'menutup' : 'membuka' ?></span> QR code presensi mahasiswa
                </span>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span class="btn btn-sm btn-outline-primary pretes-qr-toggle-btn" style="font-size:12px;font-weight:700;padding:6px 14px;border-radius:8px;pointer-events:none;display:inline-flex;align-items:center;gap:6px;">
                <span class="qr-btn-text"><?= $isCardOpen ? 'Tutup QR Code' : 'Buka QR Code' ?></span>
                <span class="summary-chevron" style="display:inline-block;font-size:11px;">▼</span>
            </span>
        </div>
    </summary>
    <div class="card-body" style="padding:18px 20px;">
        <div class="pretes-qr-sessions">
        <?php foreach ($groups as $group): 
            $scope = $group['scope'];
            $session = pretesSession($pdo, $scope);
            $url = pretesAttendanceUrl($session);
            $isOpen = (bool)$session['is_open'];
            $isTarget = ((int)($_GET['session_id'] ?? 0) === (int)$session['id']);
        ?>
            <details class="pretes-qr-session" id="qr-session-<?= (int)$session['id'] ?>" <?= $isTarget ? 'open' : '' ?>>
                <summary>
                    <div style="display:flex;align-items:center;gap:12px;min-width:0;">
                        <span style="font-size:20px;flex-shrink:0;">📅</span>
                        <div style="min-width:0;">
                            <strong style="font-size:14.5px;color:#0f172a;display:block;">
                                Sesi <?= sanitize($scope['hari']) ?> · 13.00 WIB
                            </strong>
                            <small style="color:#64748b;font-size:12px;display:block;margin-top:2px;">
                                🏢 <?= sanitize($scope['ruangan'] ?: 'Ruangan belum ditentukan') ?> · 👥 <?= $group['count'] ?> Mahasiswa
                            </small>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <?php if ($isOpen): ?>
                            <span style="background:#dcfce7;color:#15803d;padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:800;display:inline-flex;align-items:center;gap:6px;box-shadow:0 0 0 3px rgba(34,197,94,0.15);">
                                <span style="width:7px;height:7px;border-radius:50%;background:#16a34a;animation:pulseGlow 1.5s infinite;"></span> ABSENSI BUKA
                            </span>
                        <?php else: ?>
                            <span style="background:#f1f5f9;color:#64748b;padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:700;">
                                ○ TERTUTUP
                            </span>
                        <?php endif; ?>
                        <span class="summary-chevron">▼</span>
                    </div>
                </summary>

                <div style="padding:22px 20px;text-align:center;border-top:1px solid #e2e8f0;background:#ffffff;">
                    <div style="max-width:440px;margin:0 auto;">
                        <div style="font-size:13px;color:#475569;margin-bottom:14px;line-height:1.5;">
                            Penguji: <strong><?= sanitize($scope['penguji'] ?: 'Belum ditentukan') ?></strong><br>
                            Ruangan: <strong><?= sanitize($scope['ruangan'] ?: 'Ruangan belum ditentukan') ?></strong> · Peserta: <strong><?= $group['count'] ?> Mahasiswa</strong>
                        </div>

                        <!-- QR Code Container -->
                        <div style="display:inline-block;padding:14px;background:#f8fafc;border:1.5px dashed <?= $isOpen ? '#86efac' : '#cbd5e1' ?>;border-radius:16px;box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:16px;">
                            <div class="pretes-qr" data-url="<?= sanitize($url) ?>" style="display:inline-block;padding:10px;background:#ffffff;border-radius:10px;min-width:180px;min-height:180px;"></div>
                            
                            <div style="margin-top:12px;">
                                <?php if ($isOpen): ?>
                                    <p style="margin:0 0 6px;font-size:12.5px;color:#15803d;font-weight:700;">
                                        🟢 Absensi dibuka (otomatis ditutup 2 jam setelah dibuka)
                                    </p>
                                    <p style="margin:0 0 10px;font-size:11.5px;color:#64748b;">
                                        Mahasiswa scan dengan kamera HP, login, lalu konfirmasi kehadiran.
                                    </p>
                                <?php else: ?>
                                    <p style="margin:0 0 10px;font-size:12.5px;color:#64748b;font-weight:700;">
                                        🔒 Absensi ditutup. Klik tombol di bawah untuk membuka sesi absensi.
                                    </p>
                                <?php endif; ?>

                                <button type="button" onclick="copyAttendanceLink('<?= sanitize($url) ?>', this)" class="btn btn-sm btn-secondary" style="font-size:12px;padding:6px 14px;border-radius:8px;">
                                    📋 Salin Link Absensi
                                </button>
                            </div>
                        </div>

                        <!-- Form Buka/Tutup Absensi -->
                        <form method="POST" data-no-spa style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                            <?php if ($isOpen): ?>
                                <button name="action" value="close_attendance" class="btn btn-danger" style="width:100%;font-weight:700;padding:10px 16px;font-size:13.5px;display:flex;align-items:center;justify-content:center;gap:6px;border-radius:10px;">
                                    🔒 Tutup Sesi Absensi
                                </button>
                            <?php else: ?>
                                <button name="action" value="open_attendance" class="btn btn-success" style="width:100%;font-weight:700;padding:10px 16px;font-size:13.5px;display:flex;align-items:center;justify-content:center;gap:6px;border-radius:10px;">
                                    🟢 Buka Sesi Absensi (2 Jam)
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </details>
        <?php endforeach; ?>
        </div>
    </div>
</details>
<?php endif; ?>

<!-- Catatan: Form input nilai 12 komponen kini berada langsung di dalam baris accordion setiap mahasiswa pada tabel di bawah. -->

<!-- ── 2. DAFTAR MAHASISWA & ACCORDION PENILAIAN 12 KOMPONEN (MOBILE FIRST) ── -->
<style>
/* ── Layout & Responsive Table/Card Styles ── */
#peserta-pretes .pretes-roster-body {
    padding: 20px;
}
#table-peserta-pretes {
    width: 100%;
    border-collapse: collapse;
}
#table-peserta-pretes th {
    padding: 14px 16px;
    font-size: 13px;
    font-weight: 800;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
}
#table-peserta-pretes td {
    padding: 14px 16px;
    vertical-align: top;
    white-space: normal;
    overflow-wrap: anywhere;
}
#table-peserta-pretes .student-action {
    width: 155px;
    text-align: right;
}

/* Student Header in Row */
.student-header-toggle {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 8px;
    border-radius: 12px;
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
}
.student-header-toggle:hover {
    background-color: #f1f5f9;
}
.student-header-toggle:hover .student-name {
    color: #0284c7;
}
.student-name {
    font-weight: 800;
    color: #0f172a;
    font-size: 15px;
    line-height: 1.35;
}
.student-meta {
    margin-top: 5px;
    font-size: 12.5px;
    line-height: 1.5;
    color: #64748b;
}

/* Accordion Box */
.pretes-inline-scoring-box {
    margin-top: 14px;
    padding: 18px 20px;
    background: #f8fafc;
    border: 1.5px solid #cbd5e1;
    border-radius: 14px;
    box-shadow: inset 0 1px 3px rgba(0,0,0,0.03);
    animation: inlineFade 0.22s ease-out;
}
@keyframes inlineFade {
    from { opacity: 0; transform: translateY(-5px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Save Success Glow Animation */
.save-success-glow {
    border-color: #22c55e !important;
    box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.25) !important;
    transition: all 0.3s ease;
}

/* Live Calc Bar */
.pretes-live-calc-bar {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
}
.pretes-live-stats {
    display: flex;
    align-items: center;
    gap: 18px;
    flex-wrap: wrap;
}
.pretes-stat-tile-label {
    font-size: 10.5px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 2px;
}
.pretes-stat-tile-val {
    font-size: 22px;
    font-weight: 900;
    color: #0f172a;
    line-height: 1.1;
}

/* Quick Fill Toolbar */
.pretes-quick-fill-bar {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.pretes-quick-fill-label {
    font-size: 11.5px;
    font-weight: 700;
    color: #64748b;
    margin-right: 2px;
}
.pretes-quick-fill-chips {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}

/* Categories & Scoring Cards */
.pretes-category-heading {
    font-size: 12.5px;
    font-weight: 800;
    color: #1e293b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
    padding: 6px 10px;
    background: #eff6ff;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.pretes-score-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
    gap: 10px;
}
.pretes-score-card {
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 14px;
    transition: border-color 0.15s, box-shadow 0.15s;
    box-sizing: border-box;
}
.pretes-score-card:focus-within {
    border-color: #3b82f6;
    box-shadow: 0 3px 12px rgba(59,130,246,0.1);
}
.pretes-score-card-title {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 8px;
    line-height: 1.4;
}
.pretes-score-input-row {
    display: flex;
    align-items: center;
    gap: 6px;
}
.pretes-score-input-num {
    width: 66px;
    height: 44px;
    padding: 6px;
    text-align: center;
    font-size: 16px;
    font-weight: 800;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    background: #ffffff;
    outline: none;
    transition: border-color 0.15s;
    box-sizing: border-box;
}
.pretes-score-input-num:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.18);
}
.pretes-preset-chips {
    display: flex;
    gap: 4px;
    flex: 1;
}
.pretes-preset-chip {
    flex: 1;
    height: 44px;
    padding: 0;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    background: #ffffff;
    color: #334155;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.12s ease;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.pretes-preset-chip:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}
.pretes-preset-chip:active {
    transform: scale(0.94);
}
.pretes-preset-chip[aria-pressed="true"] {
    background: #16a34a !important;
    color: #ffffff !important;
    border-color: #16a34a !important;
    font-weight: 800 !important;
    box-shadow: 0 3px 10px rgba(22,163,74,0.35) !important;
}

/* Footer Actions */
.pretes-inline-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px solid #e2e8f0;
    flex-wrap: wrap;
}

/* ══════════════════════════════════════════════════════════════════════════
   MOBILE OPTIMIZATIONS (@media max-width: 768px)
   Transform table into beautiful, touch-friendly cards for mobile phones
   ══════════════════════════════════════════════════════════════════════════ */
@media (max-width: 768px) {
    #peserta-pretes .pretes-roster-body {
        padding: 12px 10px !important;
    }
    .table-responsive {
        overflow-x: visible !important;
    }
    #table-peserta-pretes,
    #table-peserta-pretes thead,
    #table-peserta-pretes tbody,
    #table-peserta-pretes tr,
    #table-peserta-pretes td {
        display: block !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    #table-peserta-pretes thead {
        display: none !important;
    }
    #table-peserta-pretes tr.pretes-student-row {
        background: #ffffff;
        border: 1.5px solid #e2e8f0;
        border-radius: 16px;
        margin-bottom: 14px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        overflow: hidden;
        transition: all 0.2s ease;
    }
    #table-peserta-pretes tr.pretes-student-row.row-active {
        border-color: #3b82f6 !important;
        box-shadow: 0 4px 18px rgba(59,130,246,0.12) !important;
    }
    #table-peserta-pretes td {
        padding: 14px 14px 8px 14px !important;
        border: none !important;
    }
    #table-peserta-pretes td.student-action {
        padding: 0 14px 14px 14px !important;
    }
    .btn-toggle-inline-score {
        width: 100% !important;
        min-height: 46px !important;
        font-size: 14px !important;
        font-weight: 800 !important;
        border-radius: 10px !important;
        justify-content: center !important;
    }

    /* Accordion Box on Mobile */
    .pretes-inline-scoring-box {
        margin-top: 12px;
        padding: 14px 10px;
        border-radius: 12px;
    }

    /* Live Calc on Mobile: 3-column clean cards */
    .pretes-live-calc-bar {
        padding: 12px 10px;
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    .pretes-live-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 6px;
        width: 100%;
        text-align: center;
    }
    .pretes-stat-tile {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 8px 4px;
        min-width: 0;
    }
    .pretes-stat-tile-label {
        font-size: 9.5px;
        margin-bottom: 2px;
    }
    .pretes-stat-tile-val {
        font-size: 18px;
    }

    /* Quick Fill on Mobile: 4x2 thumb-friendly keypad */
    .pretes-quick-fill-bar {
        width: 100%;
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    .pretes-quick-fill-chips {
        width: 100%;
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 5px;
    }
    .pretes-quick-fill-chips .quick-fill-row,
    .pretes-quick-fill-chips .reset-row-scores {
        width: 100%;
        min-height: 40px;
        font-size: 13px;
        font-weight: 800;
        padding: 0;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* Scoring cards on Mobile */
    .pretes-score-grid {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    .pretes-score-card {
        padding: 10px 10px;
        border-radius: 10px;
    }
    .pretes-score-card-title {
        font-size: 12.5px;
        margin-bottom: 6px;
    }
    .pretes-score-input-num {
        width: 58px;
        height: 42px;
        font-size: 16px;
        border-radius: 8px;
    }
    .pretes-preset-chip {
        height: 42px;
        font-size: 12px;
        border-radius: 8px;
    }

    /* Footer buttons on Mobile */
    .pretes-inline-actions {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
    }
    .pretes-inline-actions .btn-save-inline {
        width: 100% !important;
        min-height: 48px !important;
        font-size: 15px !important;
        font-weight: 800 !important;
        border-radius: 10px !important;
        justify-content: center !important;
    }
    .pretes-inline-actions .btn-close-inline {
        width: 100% !important;
        min-height: 44px !important;
        font-size: 14px !important;
        font-weight: 700 !important;
        border-radius: 10px !important;
        justify-content: center !important;
    }
    .pretes-inline-actions .inline-save-feedback {
        margin-left: 0;
        margin-top: 4px;
        text-align: center;
        display: block;
        width: 100%;
    }

    /* DataTables Mobile Controls */
    .dataTables_wrapper .dataTables_filter {
        float: none !important;
        text-align: left !important;
        margin-bottom: 12px !important;
    }
    .dataTables_wrapper .dataTables_filter input {
        width: 100% !important;
        margin-left: 0 !important;
        margin-top: 4px !important;
        height: 42px !important;
        font-size: 15px !important;
        border-radius: 10px !important;
        box-sizing: border-box !important;
    }
    .dataTables_wrapper .dataTables_length {
        float: none !important;
        margin-bottom: 10px !important;
        font-size: 12.5px !important;
    }
    .dataTables_wrapper .dataTables_info {
        float: none !important;
        text-align: center !important;
        margin-top: 14px !important;
        font-size: 12px !important;
    }
    .dataTables_wrapper .dataTables_paginate {
        float: none !important;
        text-align: center !important;
        margin-top: 10px !important;
        display: flex !important;
        justify-content: center !important;
        flex-wrap: wrap !important;
        gap: 4px !important;
    }
}
</style>

<div class="card" id="peserta-pretes" style="border-radius:16px;border:1px solid #e2e8f0;box-shadow:0 2px 12px rgba(0,0,0,0.04);margin-bottom:24px;overflow:hidden;background:#ffffff;">
    <div class="card-header" style="background:#f8fafc;padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:36px;height:36px;border-radius:10px;background:#e0f2fe;color:#0284c7;display:flex;align-items:center;justify-content:center;font-size:18px;">
                👥
            </div>
            <div>
                <h3 style="margin:0;font-size:16px;font-weight:800;color:#0f172a;">
                    Daftar Mahasiswa & Input Nilai Pretes (<?= count($participants) ?> Peserta)
                </h3>
                <span style="font-size:12px;color:#64748b;">
                    Sentuh nama mahasiswa atau tombol <strong>Input Nilai</strong> untuk mengisi 12 komponen langsung tanpa reload
                </span>
            </div>
        </div>
        <?php if ($user['role'] === 'admin'): ?>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <button type="button" class="btn btn-primary" onclick="openImportCbtModal()"
                style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;padding:8px 16px;border-radius:10px;background:#2563eb;color:#ffffff;border:none;cursor:pointer;box-shadow:0 2px 6px rgba(37,99,235,0.25);">
                📥 Import Nilai CBT
            </button>
        </div>
        <?php endif; ?>
    </div>

    <div class="card-body pretes-roster-body">
        <?php if (empty($participants)): ?>
            <div style="text-align:center;padding:40px 10px;color:#64748b;">
                <div style="font-size:40px;margin-bottom:8px;">👥</div>
                <strong style="font-size:15px;color:#0f172a;display:block;">Tidak ada peserta</strong>
                <p style="font-size:13px;margin:4px 0 0;">Belum ada mahasiswa yang ditugaskan pada periode ini.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive" style="width:100%;">
                <table id="table-peserta-pretes" class="table" style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="border-bottom:2px solid #e2e8f0;background:#f8fafc;">
                            <th>Nama & Status Mahasiswa</th>
                            <th class="student-action">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($participants as $row):
                        $rId = (int)$row['id'];
                        $rowScores = json_decode($row['scores_json'] ?? '{}', true) ?: [];
                        $isAttended = !empty($row['attended_at']) || ($row['status'] ?? '') === 'hadir';
                        $rCount = count($rowScores);
                        $rSum = array_sum($rowScores);
                        $rAvg = $rCount > 0 ? round($rSum / $rCount, 2) : 0;
                        $rStatusLulus = getPretesStatusLulus($rowScores);
                        $rPredikat = getPretesPredikat($rAvg);
                        $isOpen = ($selectedId === $rId);
                    ?>
                        <tr id="row-student-<?= $rId ?>" class="pretes-student-row <?= $isOpen ? 'row-active' : '' ?>" data-registration-id="<?= $rId ?>">
                            <td style="vertical-align:top;">
                                <!-- Student Summary Header (Touch friendly toggle) -->
                                <div class="student-header-toggle" data-target="inline-scoring-<?= $rId ?>" role="button" tabindex="0" title="Sentuh untuk membuka/menutup isian nilai">
                                    <div style="width:44px;height:44px;border-radius:12px;background:#e0f2fe;color:#0284c7;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:800;flex-shrink:0;">
                                        <?= strtoupper(substr($row['nama_lengkap'], 0, 1)) ?>
                                    </div>
                                    <div style="flex:1;min-width:0;">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <span class="student-name">
                                                <?= sanitize($row['nama_lengkap']) ?>
                                            </span>
                                            <?php if ($isAttended): ?>
                                                <span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:800;display:inline-flex;align-items:center;gap:4px;">
                                                    <span style="width:5px;height:5px;border-radius:50%;background:#16a34a;"></span>✓ Hadir
                                                </span>
                                            <?php else: ?>
                                                <span style="background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;">
                                                    Belum Absen
                                                </span>
                                            <?php endif; ?>
                                            <span id="badge-status-<?= $rId ?>" class="student-badge-wrap">
                                                <?php if ($rCount === 0): ?>
                                                    <span class="badge" style="background:#f1f5f9;color:#64748b;font-size:11px;font-weight:700;border:1px solid #cbd5e1;">⚪ Belum Dinilai (0/12)</span>
                                                <?php elseif ($rCount < 12): ?>
                                                    <?php if ($rStatusLulus === 'tidak_lulus'): ?>
                                                        <span class="badge" style="background:#fef2f2;color:#dc2626;font-size:11px;font-weight:700;border:1px solid #fecaca;">❌ Ada Nilai &lt; 80 (Sebagian <?= $rCount ?>/12 · <?= number_format($rAvg, 1) ?>)</span>
                                                    <?php else: ?>
                                                        <span class="badge" style="background:#fffbeb;color:#b45309;font-size:11px;font-weight:700;border:1px solid #fde68a;">⏳ Sebagian (<?= $rCount ?>/12 · <?= number_format($rAvg, 1) ?>)</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if ($rStatusLulus === 'lulus'): ?>
                                                        <span class="badge" style="background:#f0fdf4;color:#16a34a;font-size:11px;font-weight:800;border:1px solid #bbf7d0;">✅ LULUS (12/12 · <?= number_format($rAvg, 1) ?> · <?= $rPredikat ?>)</span>
                                                    <?php else: ?>
                                                        <span class="badge" style="background:#fef2f2;color:#dc2626;font-size:11px;font-weight:800;border:1px solid #fecaca;">❌ TIDAK LULUS (12/12 · <?= number_format($rAvg, 1) ?> · <?= $rPredikat ?>)</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="student-meta" style="margin-top:5px;font-size:12.5px;color:#64748b;display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
                                            <span><strong>NIM:</strong> <?= sanitize($row['nim']) ?></span>
                                            <span><strong>Prodi:</strong> <?= sanitize($row['program_studi'] ?: '-') ?></span>
                                            <span><strong>Sesi:</strong> <?= sanitize($row['hari_pilihan'] ?: 'Senin') ?><?= !empty($row['ruangan']) ? ' · ' . sanitize($row['ruangan']) : '' ?></span>
                                            <?php 
                                            $cbtVal = $rowScores['ujian_tulis'] ?? null;
                                            if ($cbtVal !== null && $cbtVal !== '' && is_numeric($cbtVal)): 
                                                $numCbt = (float)$cbtVal;
                                                $cbtColor = $numCbt >= 80 ? '#15803d' : '#b91c1c';
                                                $cbtBg = $numCbt >= 80 ? '#dcfce7' : '#fee2e2';
                                                $cbtBorder = $numCbt >= 80 ? '#86efac' : '#fca5a5';
                                            ?>
                                                <span style="background:<?= $cbtBg ?>;color:<?= $cbtColor ?>;padding:1px 8px;border-radius:6px;font-size:11px;font-weight:800;border:1px solid <?= $cbtBorder ?>;display:inline-flex;align-items:center;gap:4px;" title="Nilai Komponen Ujian Tulis (CBT)">
                                                    📝 CBT: <?= number_format($numCbt, 1) ?> (<?= $numCbt >= 80 ? 'Lulus' : 'Remidi' ?>)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Inline Scoring Accordion Box -->
                                <div class="pretes-inline-scoring-box" id="inline-scoring-<?= $rId ?>" style="<?= $isOpen ? 'display:block;' : 'display:none;' ?>">
                                    <!-- Live Calculation & Quick Fill Toolbar -->
                                    <div class="pretes-live-calc-bar">
                                        <div class="pretes-live-stats">
                                            <div class="pretes-stat-tile">
                                                <div class="pretes-stat-tile-label">Rata-rata</div>
                                                <div class="pretes-stat-tile-val live-avg-val">
                                                    <?= $rCount > 0 ? number_format($rAvg, 2) : '—' ?>
                                                </div>
                                            </div>
                                            <div class="pretes-stat-tile">
                                                <div class="pretes-stat-tile-label">Predikat</div>
                                                <div class="live-grade-val">
                                                    <?php if ($rCount === 0): ?>
                                                        <span class="badge" style="background:#e2e8f0;color:#64748b;font-size:11px;font-weight:700;">Belum Lengkap</span>
                                                    <?php elseif ($rCount < 12): ?>
                                                        <span class="badge badge-warning" style="font-size:11px;font-weight:700;">Sebagian (<?= $rCount ?>/12)</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-<?= $rStatusLulus === 'lulus' ? 'success' : 'danger' ?>" style="font-size:11px;font-weight:800;"><?= $rPredikat ?> (<?= $rStatusLulus === 'lulus' ? 'Lulus' : 'Tidak Lulus' ?>)</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="pretes-stat-tile">
                                                <div class="pretes-stat-tile-label">Kelengkapan</div>
                                                <div style="font-size:13px;font-weight:800;color:#0f172a;line-height:1.2;">
                                                    <span class="live-filled-val"><?= $rCount ?></span> / 12
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Quick Fill Toolbar -->
                                        <div class="pretes-quick-fill-bar">
                                            <span class="pretes-quick-fill-label">⚡ Isi Cepat:</span>
                                            <div class="pretes-quick-fill-chips">
                                                <?php foreach ([60, 70, 75, 80, 85, 90, 100] as $preset): ?>
                                                <button type="button" class="btn btn-sm btn-secondary quick-fill-row" data-score="<?= $preset ?>" title="Isi semua dengan nilai <?= $preset ?>">
                                                    <?= $preset ?>
                                                </button>
                                                <?php endforeach; ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger reset-row-scores" title="Kosongkan isian nilai">
                                                    Reset
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Scoring Form -->
                                    <form method="POST" class="form-inline-score" data-no-spa>
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="action" value="save_scores">
                                        <input type="hidden" name="registration_id" value="<?= $rId ?>">
                                        <input type="hidden" name="is_ajax" value="1">

                                        <?php 
                                        $compNum = 0;
                                        foreach ($componentCategories as $catTitle => $catItems): 
                                        ?>
                                            <div style="margin-bottom:18px;">
                                                <div class="pretes-category-heading">
                                                    <?= $catTitle === 'Ujian CBT' ? '📝' : ($catTitle === 'Praktik Ibadah & Thaharah' ? '🕌' : '📖') ?>
                                                    <?= $catTitle ?>
                                                </div>
                                                <div class="pretes-score-grid">
                                                <?php foreach ($catItems as $key => $label): $compNum++; 
                                                    $cVal = isset($rowScores[$key]) && is_numeric($rowScores[$key]) ? (string)$rowScores[$key] : '';
                                                ?>
                                                    <div class="pretes-score-card">
                                                        <label for="score-<?= $rId ?>-<?= $key ?>" class="pretes-score-card-title">
                                                            <?= $compNum ?>. <?= sanitize($label) ?>
                                                        </label>
                                                        <div class="pretes-score-input-row">
                                                            <input id="score-<?= $rId ?>-<?= $key ?>" name="scores[<?= $key ?>]" type="number" min="0" max="100" step="0.01" inputmode="decimal"
                                                                value="<?= sanitize($cVal) ?>" placeholder="0–100" class="pretes-score-input-num" aria-label="Nilai <?= sanitize($label) ?>">
                                                            <div class="pretes-preset-chips">
                                                                <?php foreach ([60, 70, 80, 90, 100] as $preset): 
                                                                    $isBtnPressed = ($cVal !== '' && (float)$cVal === (float)$preset);
                                                                ?>
                                                                <button type="button" class="pretes-preset-chip score-preset" data-score="<?= $preset ?>" aria-pressed="<?= $isBtnPressed ? 'true' : 'false' ?>"
                                                                    aria-label="Pilih nilai <?= $preset ?> untuk <?= sanitize($label) ?>">
                                                                    <?= $preset ?>
                                                                </button>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>

                                        <!-- Footer Action Buttons inside Accordion -->
                                        <div class="pretes-inline-actions">
                                            <button type="submit" class="btn btn-success btn-save-inline" style="padding:10px 24px;font-size:14px;font-weight:800;border-radius:10px;display:inline-flex;align-items:center;gap:6px;box-shadow:0 3px 10px rgba(22,163,74,0.3);">
                                                💾 Simpan Nilai <?= sanitize(explode(' ', trim($row['nama_lengkap']))[0]) ?>
                                            </button>
                                            <button type="button" class="btn btn-secondary btn-close-inline" data-target="inline-scoring-<?= $rId ?>" style="padding:10px 18px;font-size:13.5px;font-weight:700;border-radius:10px;">
                                                ✕ Tutup
                                            </button>
                                            <span class="inline-save-feedback" style="font-size:13px;font-weight:700;margin-left:6px;"></span>
                                        </div>
                                    </form>
                                </div>
                            </td>
                            <td class="student-action">
                                <button type="button" class="btn <?= $isOpen ? 'btn-secondary' : 'btn-primary' ?> btn-toggle-inline-score" data-target="inline-scoring-<?= $rId ?>"
                                    aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" aria-label="Input nilai <?= sanitize($row['nama_lengkap']) ?>"
                                    style="min-height:42px;padding:8px 14px;font-size:13px;font-weight:800;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;gap:6px;width:100%;">
                                    <span class="btn-toggle-text"><?= $isOpen ? '✕ Tutup' : '✏️ Input Nilai' ?></span>
                                    <span class="btn-toggle-arrow"><?= $isOpen ? '▲' : '▼' ?></span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/lib/qrcode.min.js"></script>
<script>
// ── 1. Accordion Toggle Logic ────────────────────────────────────────────────
function toggleStudentAccordion(boxId) {
    var box = document.getElementById(boxId);
    if (!box) return;
    var row = box.closest('tr');
    var isCurrentlyOpen = (box.style.display !== 'none');
    var buttons = document.querySelectorAll('.btn-toggle-inline-score[data-target="' + boxId + '"]');

    if (isCurrentlyOpen) {
        box.style.display = 'none';
        if (row) row.classList.remove('row-active');
        buttons.forEach(function(b) {
            b.setAttribute('aria-expanded', 'false');
            b.classList.remove('btn-secondary');
            b.classList.add('btn-primary');
            var text = b.querySelector('.btn-toggle-text');
            var arrow = b.querySelector('.btn-toggle-arrow');
            if (text) text.textContent = '✏️ Input Nilai';
            if (arrow) arrow.textContent = '▼';
        });
    } else {
        box.style.display = 'block';
        if (row) row.classList.add('row-active');
        buttons.forEach(function(b) {
            b.setAttribute('aria-expanded', 'true');
            b.classList.remove('btn-primary');
            b.classList.add('btn-secondary');
            var text = b.querySelector('.btn-toggle-text');
            var arrow = b.querySelector('.btn-toggle-arrow');
            if (text) text.textContent = '✕ Tutup';
            if (arrow) arrow.textContent = '▲';
        });
        updateRowLiveScores(box);

        // Smooth scroll ke posisi baris bila di mobile agar nyaman di mata
        if (window.innerWidth <= 768 && row) {
            setTimeout(function() {
                row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 100);
        }
    }
}

// ── 2. Live Calculation inside Accordion ─────────────────────────────────────
function updateRowLiveScores(container) {
    if (!container) return;
    var inputs = container.querySelectorAll('input[type="number"]');
    var filled = 0;
    var sum = 0;
    var totalCount = inputs.length;
    var hasBelow80 = false;

    inputs.forEach(function(inp) {
        var val = inp.value.trim();
        if (val !== '' && !isNaN(val)) {
            var num = parseFloat(val);
            if (num >= 0 && num <= 100) {
                filled++;
                sum += num;
                if (num < 80) hasBelow80 = true;
            }
        }
    });

    var avgEl = container.querySelector('.live-avg-val');
    var gradeEl = container.querySelector('.live-grade-val');
    var filledEl = container.querySelector('.live-filled-val');

    if (filledEl) filledEl.textContent = filled;

    if (filled === 0) {
        if (avgEl) avgEl.textContent = '—';
        if (gradeEl) gradeEl.innerHTML = '<span class="badge" style="background:#e2e8f0;color:#64748b;font-size:11px;font-weight:700;">Belum Lengkap</span>';
    } else {
        var avg = (sum / filled).toFixed(2);
        if (avgEl) avgEl.textContent = avg;
        var numAvg = parseFloat(avg);
        var predikat = '-';
        if (numAvg >= 85) predikat = 'A';
        else if (numAvg >= 75) predikat = 'B';
        else if (numAvg >= 65) predikat = 'C';
        else if (numAvg >= 55) predikat = 'D';
        else predikat = 'E';

        var gradeHtml = '';
        if (filled < totalCount) {
            gradeHtml = '<span class="badge badge-warning" style="font-size:11px;font-weight:700;">Sebagian (' + filled + '/' + totalCount + ')</span>';
        } else {
            if (hasBelow80) {
                gradeHtml = '<span class="badge badge-danger" style="font-size:11px;font-weight:800;">' + predikat + ' (Tidak Lulus)</span>';
            } else {
                gradeHtml = '<span class="badge badge-success" style="font-size:11px;font-weight:800;">' + predikat + ' (Lulus)</span>';
            }
        }
        if (gradeEl) gradeEl.innerHTML = gradeHtml;
    }
}

function updateInputPresetHighlight(inp) {
    if (!inp) return;
    var row = inp.closest('.pretes-score-input-row');
    if (!row) return;
    var val = inp.value.trim();
    row.querySelectorAll('.pretes-preset-chip').forEach(function(btn) {
        var isPressed = (val !== '' && Number(val) === Number(btn.dataset.score));
        btn.setAttribute('aria-pressed', isPressed ? 'true' : 'false');
    });
}

// ── 3. Global Event Delegation on Table ──────────────────────────────────────
document.addEventListener('click', function(e) {
    // 3a. Click header toggle to open/close accordion
    var headerToggle = e.target.closest('.student-header-toggle');
    if (headerToggle) {
        var targetId = headerToggle.dataset.target;
        if (targetId) toggleStudentAccordion(targetId);
        return;
    }

    // 3b. Click button input nilai to open/close accordion
    var btnToggle = e.target.closest('.btn-toggle-inline-score');
    if (btnToggle) {
        var targetId = btnToggle.dataset.target;
        if (targetId) toggleStudentAccordion(targetId);
        return;
    }

    // 3c. Click button tutup inside accordion
    var btnClose = e.target.closest('.btn-close-inline');
    if (btnClose) {
        var targetId = btnClose.dataset.target;
        if (targetId) toggleStudentAccordion(targetId);
        return;
    }

    // 3d. Individual score preset button clicked
    var presetBtn = e.target.closest('.pretes-preset-chip');
    if (presetBtn) {
        var scoreRow = presetBtn.closest('.pretes-score-input-row');
        if (scoreRow) {
            var inp = scoreRow.querySelector('input');
            if (inp) {
                inp.value = presetBtn.dataset.score;
                inp.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
        return;
    }

    // 3e. Quick fill all scores in this row
    var quickFill = e.target.closest('.quick-fill-row');
    if (quickFill) {
        var box = quickFill.closest('.pretes-inline-scoring-box');
        if (box) {
            var val = quickFill.dataset.score;
            box.querySelectorAll('input[type="number"]').forEach(function(inp) {
                inp.value = val;
                inp.dispatchEvent(new Event('input', { bubbles: true }));
            });
        }
        return;
    }

    // 3f. Reset all scores in this row
    var resetBtn = e.target.closest('.reset-row-scores');
    if (resetBtn) {
        var box = resetBtn.closest('.pretes-inline-scoring-box');
        if (box && confirm('Apakah Anda yakin ingin mengosongkan semua isian nilai mahasiswa ini?')) {
            box.querySelectorAll('input[type="number"]').forEach(function(inp) {
                inp.value = '';
                inp.dispatchEvent(new Event('input', { bubbles: true }));
            });
        }
        return;
    }
});

// 3g. Input change listener (live update)
document.addEventListener('input', function(e) {
    if (e.target && e.target.matches('.pretes-score-input-num')) {
        updateInputPresetHighlight(e.target);
        var box = e.target.closest('.pretes-inline-scoring-box');
        if (box) updateRowLiveScores(box);
    }
});

// 3h. Form submit listener (AJAX save without reload)
document.addEventListener('submit', function(e) {
    var form = e.target.closest('.form-inline-score');
    if (!form) return;

    e.preventDefault();
    var saveBtn = form.querySelector('.btn-save-inline');
    var feedbackEl = form.querySelector('.inline-save-feedback');
    var originalBtnHtml = saveBtn ? saveBtn.innerHTML : '💾 Simpan Nilai';
    var row = form.closest('tr');

    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '⏳ Menyimpan...';
    }
    if (feedbackEl) {
        feedbackEl.innerHTML = '<span style="color:#2563eb;">Menyimpan nilai ke server...</span>';
    }

    var formData = new FormData(form);
    formData.set('is_ajax', '1');

    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(res) {
        return res.json();
    })
    .then(function(data) {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalBtnHtml;
        }

        if (data.success) {
            if (feedbackEl) {
                feedbackEl.innerHTML = '<span style="color:#16a34a;">✅ ' + data.message + '</span>';
                setTimeout(function() { feedbackEl.innerHTML = ''; }, 4500);
            }

            // Glow animation on row
            if (row) {
                row.classList.add('save-success-glow');
                setTimeout(function() { row.classList.remove('save-success-glow'); }, 1800);
            }

            // Update row status badge
            var badgeWrap = document.getElementById('badge-status-' + data.registration_id);
            if (badgeWrap) {
                var badgeHtml = '';
                if (data.count === 0) {
                    badgeHtml = '<span class="badge" style="background:#f1f5f9;color:#64748b;font-size:11px;font-weight:700;border:1px solid #cbd5e1;">⚪ Belum Dinilai (0/12)</span>';
                } else if (data.count < 12) {
                    if (data.status_lulus === 'tidak_lulus') {
                        badgeHtml = '<span class="badge" style="background:#fef2f2;color:#dc2626;font-size:11px;font-weight:700;border:1px solid #fecaca;">❌ Ada Nilai &lt; 80 (Sebagian ' + data.count + '/12 · ' + data.average + ')</span>';
                    } else {
                        badgeHtml = '<span class="badge" style="background:#fffbeb;color:#b45309;font-size:11px;font-weight:700;border:1px solid #fde68a;">⏳ Sebagian (' + data.count + '/12 · ' + data.average + ')</span>';
                    }
                } else {
                    if (data.status_lulus === 'lulus') {
                        badgeHtml = '<span class="badge" style="background:#f0fdf4;color:#16a34a;font-size:11px;font-weight:800;border:1px solid #bbf7d0;">✅ LULUS (12/12 · ' + data.average + ' · ' + data.predikat + ')</span>';
                    } else {
                        badgeHtml = '<span class="badge" style="background:#fef2f2;color:#dc2626;font-size:11px;font-weight:800;border:1px solid #fecaca;">❌ TIDAK LULUS (12/12 · ' + data.average + ' · ' + data.predikat + ')</span>';
                    }
                }
                badgeWrap.innerHTML = badgeHtml;
            }

            var box = form.closest('.pretes-inline-scoring-box');
            if (box) updateRowLiveScores(box);
        } else {
            if (feedbackEl) {
                feedbackEl.innerHTML = '<span style="color:#dc2626;">⚠️ ' + (data.message || 'Gagal menyimpan nilai.') + '</span>';
            }
        }
    })
    .catch(function(err) {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalBtnHtml;
        }
        if (feedbackEl) {
            feedbackEl.innerHTML = '<span style="color:#dc2626;">⚠️ Terjadi kesalahan koneksi. Silakan periksa jaringan dan coba lagi.</span>';
        }
    });
});

// ── 4. Accordion Sesi QR Code ───────────────────────────────────────────────
var qrCard = document.querySelector('.pretes-qr-card-accordion');
if (qrCard) {
    function updateQrCardUI() {
        var isOpen = qrCard.open;
        var btnText = qrCard.querySelector('.qr-btn-text');
        var actionText = qrCard.querySelector('.action-text');
        if (btnText) btnText.textContent = isOpen ? 'Tutup QR Code' : 'Buka QR Code';
        if (actionText) actionText.textContent = isOpen ? 'menutup' : 'membuka';
        if (isOpen) {
            qrCard.querySelectorAll('.pretes-qr-session[open]').forEach(function(sess) {
                var el = sess.querySelector('.pretes-qr');
                if (el && !el.dataset.rendered && el.dataset.url) {
                    new QRCode(el, {
                        text: el.dataset.url,
                        width: 180,
                        height: 180,
                        correctLevel: QRCode.CorrectLevel.M
                    });
                    el.dataset.rendered = 'true';
                }
            });
        }
    }
    qrCard.addEventListener('toggle', updateQrCardUI);

    if (window.location.hash && (window.location.hash === '#absensi-pretes' || window.location.hash.indexOf('#qr-session') === 0)) {
        qrCard.open = true;
        updateQrCardUI();
    }
}

document.querySelectorAll('.pretes-qr-session').forEach(function(details) {
    function renderSessionQr() {
        if (!details.open) return;
        document.querySelectorAll('.pretes-qr-session').forEach(function(other) {
            if (other !== details) other.open = false;
        });
        var el = details.querySelector('.pretes-qr');
        if (el && !el.dataset.rendered && el.dataset.url) {
            new QRCode(el, {
                text: el.dataset.url,
                width: 180,
                height: 180,
                correctLevel: QRCode.CorrectLevel.M
            });
            el.dataset.rendered = 'true';
        }
    }
    details.addEventListener('toggle', renderSessionQr);
    renderSessionQr();
});

function copyAttendanceLink(url, btn) {
    if (!url) return;
    navigator.clipboard.writeText(url).then(function() {
        var old = btn.textContent;
        btn.textContent = '✅ Link Tersalin!';
        btn.style.background = '#16a34a';
        btn.style.color = '#ffffff';
        setTimeout(function() {
            btn.textContent = old;
            btn.style.background = '';
            btn.style.color = '';
        }, 2000);
    }).catch(function() {
        prompt('Salin link absensi berikut:', url);
    });
}

// ── 5. Inisialisasi DataTables pada Tabel Peserta ───────────────────────────
function initPretesDataTable() {
    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable) {
        var table = document.getElementById('table-peserta-pretes');
        if (table && !window.jQuery.fn.DataTable.isDataTable(table)) {
            window.jQuery(table).DataTable({
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                order: [],
                autoWidth: false,
                columnDefs: [{ orderable: false, searchable: false, targets: [1] }],
                language: {
                    search: 'Cari Mahasiswa:',
                    lengthMenu: 'Tampilkan _MENU_ data',
                    info: 'Menampilkan _START_ - _END_ dari _TOTAL_ mahasiswa',
                    infoEmpty: 'Tidak ada data',
                    infoFiltered: '(difilter dari _MAX_ total data)',
                    zeroRecords: 'Mahasiswa tidak ditemukan',
                    paginate: {
                        first: 'Pertama',
                        last: 'Terakhir',
                        next: 'Selanjutnya',
                        previous: 'Sebelumnya'
                    }
                }
            });
        }
    }
}

// ── 6. Auto-Open Baris Mahasiswa jika Ada Parameter registration_id ─────────
function checkAutoOpenRegistration() {
    var urlParams = new URLSearchParams(window.location.search);
    var regId = urlParams.get('registration_id');
    if (regId) {
        var boxId = 'inline-scoring-' + regId;
        var box = document.getElementById(boxId);
        if (box) {
            if (box.style.display === 'none') {
                toggleStudentAccordion(boxId);
            }
            setTimeout(function() {
                var row = document.getElementById('row-student-' + regId);
                if (row) row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 300);
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        initPretesDataTable();
        checkAutoOpenRegistration();
    });
} else {
    initPretesDataTable();
    checkAutoOpenRegistration();
}

// ── 7. Fitur Modal Import Nilai CBT Pretes ──────────────────────────────────
var currentCbtTab = 'file';

function openImportCbtModal() {
    var modal = document.getElementById('modal-import-cbt');
    if (modal) {
        modal.style.display = 'flex';
        var resultBox = document.getElementById('cbt-result-box');
        if (resultBox) resultBox.style.display = 'none';
        var reloadBtn = document.getElementById('btn-reload-cbt-after');
        if (reloadBtn) reloadBtn.style.display = 'none';
        var submitBtn = document.getElementById('btn-submit-cbt-import');
        if (submitBtn) {
            submitBtn.style.display = 'inline-flex';
            submitBtn.disabled = false;
        }
    }
}

function closeImportCbtModal() {
    var modal = document.getElementById('modal-import-cbt');
    if (modal) modal.style.display = 'none';
}

function switchCbtInputTab(tab) {
    currentCbtTab = tab;
    var tabFile = document.getElementById('tab-cbt-file');
    var tabPaste = document.getElementById('tab-cbt-paste');
    var paneFile = document.getElementById('cbt-pane-file');
    var panePaste = document.getElementById('cbt-pane-paste');

    if (tab === 'file') {
        if (tabFile) {
            tabFile.style.color = '#2563eb';
            tabFile.style.borderBottomColor = '#2563eb';
            tabFile.style.fontWeight = '800';
        }
        if (tabPaste) {
            tabPaste.style.color = '#64748b';
            tabPaste.style.borderBottomColor = 'transparent';
            tabPaste.style.fontWeight = '700';
        }
        if (paneFile) paneFile.style.display = 'block';
        if (panePaste) panePaste.style.display = 'none';
    } else {
        if (tabPaste) {
            tabPaste.style.color = '#2563eb';
            tabPaste.style.borderBottomColor = '#2563eb';
            tabPaste.style.fontWeight = '800';
        }
        if (tabFile) {
            tabFile.style.color = '#64748b';
            tabFile.style.borderBottomColor = 'transparent';
            tabFile.style.fontWeight = '700';
        }
        if (paneFile) paneFile.style.display = 'none';
        if (panePaste) panePaste.style.display = 'block';
    }
}

function handleCbtFileSelected(input) {
    var fnEl = document.getElementById('cbt-file-name');
    if (input.files && input.files[0]) {
        var file = input.files[0];
        fnEl.textContent = '📄 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
        fnEl.style.display = 'block';
    } else {
        fnEl.style.display = 'none';
    }
}

// Drag & drop support on dropzone
var dropzone = document.getElementById('cbt-dropzone');
if (dropzone) {
    ['dragenter', 'dragover'].forEach(function(eventName) {
        dropzone.addEventListener(eventName, function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.style.borderColor = '#2563eb';
            dropzone.style.background = '#eff6ff';
        });
    });
    ['dragleave', 'drop'].forEach(function(eventName) {
        dropzone.addEventListener(eventName, function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.style.borderColor = '#94a3b8';
            dropzone.style.background = '#f8fafc';
        });
    });
    dropzone.addEventListener('drop', function(e) {
        var dt = e.dataTransfer;
        var files = dt.files;
        if (files && files.length > 0) {
            var fileInput = document.getElementById('cbt-file-input');
            fileInput.files = files;
            handleCbtFileSelected(fileInput);
        }
    });
}

// Close modal when backdrop clicked
var modalCbt = document.getElementById('modal-import-cbt');
if (modalCbt) {
    modalCbt.addEventListener('click', function(e) {
        if (e.target === modalCbt) closeImportCbtModal();
    });
}

function submitCbtImport() {
    var targetPeriodeEl = document.getElementById('cbt-target-periode');
    var targetPeriode = targetPeriodeEl ? targetPeriodeEl.value : '';
    var overwrite = document.getElementById('cbt-opt-overwrite') && document.getElementById('cbt-opt-overwrite').checked ? '1' : '0';
    var autoRegister = document.getElementById('cbt-opt-autoreg') && document.getElementById('cbt-opt-autoreg').checked ? '1' : '0';

    var formData = new FormData();
    formData.append('csrf_token', '<?= csrfToken() ?>');
    formData.append('periode', targetPeriode);
    formData.append('overwrite', overwrite);
    formData.append('auto_register', autoRegister);

    if (currentCbtTab === 'file') {
        var fileInput = document.getElementById('cbt-file-input');
        if (!fileInput.files || fileInput.files.length === 0) {
            alert('Silakan pilih berkas Excel atau CSV terlebih dahulu.');
            return;
        }
        formData.append('cbt_file', fileInput.files[0]);
    } else {
        var pasteInput = document.getElementById('cbt-paste-input').value.trim();
        if (!pasteInput) {
            alert('Silakan tempelkan (paste) data hasil CBT terlebih dahulu.');
            return;
        }
        formData.append('cbt_text', pasteInput);
    }

    var submitBtn = document.getElementById('btn-submit-cbt-import');
    var resultBox = document.getElementById('cbt-result-box');
    var progressWrap = document.getElementById('cbt-progress-bar-wrap');
    var feedbackAlert = document.getElementById('cbt-feedback-alert');
    var tableWrap = document.getElementById('cbt-details-table-wrap');
    var tbody = document.getElementById('cbt-details-tbody');
    var reloadBtn = document.getElementById('btn-reload-cbt-after');

    submitBtn.disabled = true;
    submitBtn.innerHTML = '⏳ Mengimpor...';
    resultBox.style.display = 'block';
    progressWrap.style.display = 'block';
    feedbackAlert.innerHTML = '';
    tableWrap.style.display = 'none';
    tbody.innerHTML = '';

    fetch('<?= BASE_URL ?>/admin/ajax-import-pretes-cbt.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(res) {
        return res.json();
    })
    .then(function(data) {
        progressWrap.style.display = 'none';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '🚀 Mulai Import Nilai CBT';

        if (data.success) {
            feedbackAlert.innerHTML = '<div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:12px 16px;border-radius:10px;font-size:13px;font-weight:700;">' +
                '✅ ' + data.message + '</div>';
            reloadBtn.style.display = 'inline-flex';
            submitBtn.style.display = 'none';
        } else {
            feedbackAlert.innerHTML = '<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:10px;font-size:13px;font-weight:700;">' +
                '⚠️ ' + (data.message || 'Gagal memproses impor CBT.') + '</div>';
        }

        if (data.details && data.details.length > 0) {
            tableWrap.style.display = 'block';
            var html = '';
            data.details.forEach(function(row) {
                var bg = '#ffffff';
                var badgeStyle = 'background:#f1f5f9;color:#475569;';
                if (row.type === 'updated') {
                    badgeStyle = 'background:#eff6ff;color:#1d4ed8;font-weight:700;';
                } else if (row.type === 'created') {
                    badgeStyle = 'background:#f0fdf4;color:#15803d;font-weight:700;';
                } else if (row.type === 'failed') {
                    badgeStyle = 'background:#fef2f2;color:#b91c1c;font-weight:700;';
                    bg = '#fff5f5';
                }
                html += '<tr style="border-bottom:1px solid #f1f5f9;background:' + bg + ';">' +
                    '<td style="padding:6px 10px;font-family:monospace;font-weight:700;">' + (row.nim || '-') + '</td>' +
                    '<td style="padding:6px 10px;">' + (row.nama || '-') + '</td>' +
                    '<td style="padding:6px 10px;text-align:center;">' + (row.kelas || '-') + '</td>' +
                    '<td style="padding:6px 10px;text-align:center;font-weight:800;color:#0f172a;">' + (row.nilai !== undefined ? row.nilai : '-') + '</td>' +
                    '<td style="padding:6px 10px;"><span style="padding:2px 8px;border-radius:6px;font-size:11px;' + badgeStyle + '">' + (row.status || '') + '</span></td>' +
                '</tr>';
            });
            tbody.innerHTML = html;
        }
    })
    .catch(function(err) {
        progressWrap.style.display = 'none';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '🚀 Mulai Import Nilai CBT';
        feedbackAlert.innerHTML = '<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:10px;font-size:13px;font-weight:700;">' +
            '⚠️ Terjadi kesalahan koneksi saat mengunggah berkas. Silakan coba lagi.</div>';
    });
}
</script>

<?php if ($user['role'] === 'admin'): ?>
<!-- ── MODAL IMPORT HASIL CBT ────────────────────────────────────────────────── -->
<div id="modal-import-cbt" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);backdrop-filter:blur(4px);z-index:99999;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
    <div style="background:#ffffff;border-radius:18px;max-width:760px;width:100%;max-height:92vh;overflow-y:auto;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);border:1px solid #cbd5e1;display:flex;flex-direction:column;">
        
        <!-- Header Modal -->
        <div style="background:linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);color:#ffffff;padding:20px 24px;border-top-left-radius:17px;border-top-right-radius:17px;display:flex;justify-content:space-between;align-items:flex-start;">
            <div>
                <div style="font-size:12px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;color:#93c5fd;margin-bottom:4px;display:flex;align-items:center;gap:6px;">
                    <span>📝</span> Modul Penilaian Pretes
                </div>
                <h3 style="margin:0;font-size:19px;font-weight:800;color:#ffffff;line-height:1.3;">
                    Import Hasil Ujian Tulis CBT
                </h3>
                <p style="margin:4px 0 0;font-size:12.5px;color:#dbeafe;line-height:1.4;">
                    Perbarui komponen <strong>Ujian Tulis (CBT)</strong> mahasiswa secara massal dari berkas Excel/CSV atau tempelkan data.
                </p>
            </div>
            <button type="button" onclick="closeImportCbtModal()" style="background:rgba(255,255,255,0.15);border:none;color:#ffffff;width:34px;height:34px;border-radius:50%;cursor:pointer;font-size:16px;font-weight:700;display:flex;align-items:center;justify-content:center;transition:background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                ✕
            </button>
        </div>

        <!-- Body Modal -->
        <div style="padding:22px 24px;flex:1;">
            
            <!-- Kolom Format Notice -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #2563eb;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:12.5px;color:#334155;line-height:1.5;">
                <div style="font-weight:700;color:#0f172a;margin-bottom:4px;">
                    📋 Format Kolom yang Dikenali:
                </div>
                <div style="background:#ffffff;border:1px solid #cbd5e1;padding:6px 10px;border-radius:6px;font-family:monospace;font-size:12px;color:#0369a1;font-weight:700;display:inline-block;margin-bottom:6px;">
                    No. , Nomor Induk , Nama , Kelas , Nilai , Keterangan
                </div>
                <div style="font-size:11.5px;color:#64748b;">
                    • <strong>Nomor Induk</strong>: NIM mahasiswa untuk pencocokan ke database.<br>
                    • <strong>Kelas</strong>: Hari ujian (Senin / Selasa / Rabu / Kamis / Jumat).<br>
                    • <strong>Nilai</strong>: Angka skor 0 s.d. 100 yang akan dimasukkan ke komponen <strong>Ujian tulis (CBT)</strong>.
                </div>
            </div>

            <!-- Target Periode -->
            <div style="margin-bottom:18px;">
                <label for="cbt-target-periode" style="display:block;font-size:13px;font-weight:700;color:#0f172a;margin-bottom:6px;">
                    📅 Target Periode Pretes:
                </label>
                <select id="cbt-target-periode" style="width:100%;padding:10px 14px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:13px;font-weight:600;color:#1e293b;background:#ffffff;outline:none;">
                    <?php foreach ($periods as $item): ?>
                    <option value="<?= sanitize($item['periode']) ?>" <?= (int)$item['id'] === (int)$periodId ? 'selected' : '' ?>>
                        <?= sanitize($item['periode']) ?> <?= $item['status'] === 'aktif' ? '(Aktif)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Tabs: Unggah File vs Salin & Tempel -->
            <div style="margin-bottom:18px;">
                <div style="display:flex;gap:8px;border-bottom:2px solid #e2e8f0;padding-bottom:2px;margin-bottom:14px;">
                    <button type="button" id="tab-cbt-file" onclick="switchCbtInputTab('file')"
                        style="background:none;border:none;padding:8px 16px;font-size:13px;font-weight:800;color:#2563eb;border-bottom:3px solid #2563eb;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                        📁 Unggah File Excel / CSV
                    </button>
                    <button type="button" id="tab-cbt-paste" onclick="switchCbtInputTab('paste')"
                        style="background:none;border:none;padding:8px 16px;font-size:13px;font-weight:700;color:#64748b;border-bottom:3px solid transparent;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                        📋 Salin &amp; Tempel (Paste) Teks
                    </button>
                </div>

                <!-- Input Method 1: File Upload -->
                <div id="cbt-pane-file">
                    <div id="cbt-dropzone" style="border:2px dashed #94a3b8;border-radius:12px;padding:26px 20px;text-align:center;background:#f8fafc;cursor:pointer;transition:all 0.2s;"
                        onclick="document.getElementById('cbt-file-input').click()"
                        onmouseover="this.style.borderColor='#2563eb';this.style.background='#eff6ff';"
                        onmouseout="this.style.borderColor='#94a3b8';this.style.background='#f8fafc';">
                        <div style="font-size:36px;margin-bottom:6px;">📂</div>
                        <div style="font-size:14px;font-weight:700;color:#0f172a;">
                            Klik untuk memilih berkas atau seret &amp; lepas ke sini
                        </div>
                        <div style="font-size:12px;color:#64748b;margin-top:4px;">
                            Mendukung berkas <strong>.csv</strong>, <strong>.xlsx</strong>, atau <strong>.xls</strong> (Maksimal 10MB)
                        </div>
                        <div id="cbt-file-name" style="margin-top:10px;font-size:12.5px;font-weight:700;color:#2563eb;display:none;"></div>
                    </div>
                    <input type="file" id="cbt-file-input" accept=".csv, .xlsx, .xls" style="display:none;" onchange="handleCbtFileSelected(this)">
                </div>

                <!-- Input Method 2: Paste Textarea -->
                <div id="cbt-pane-paste" style="display:none;">
                    <label for="cbt-paste-input" style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px;">
                        Tempelkan (Ctrl+V) baris data hasil CBT di bawah ini:
                    </label>
                    <textarea id="cbt-paste-input" rows="8" placeholder="No.,Nomor Induk,Nama,Kelas,Nilai,Keterangan&#10;1,24042061,AHMAD NASIK MAULAL HAQQI,Jumat,96,Lulus&#10;1,24081002,ABDUL AZIZ ASY'ARI,kamis,0,Tidak Lulus&#10;2,24032035,AFIYATUS SHOLICHAH,senin,54,Tidak Lulus"
                        style="width:100%;box-sizing:border-box;padding:12px 14px;border:1.5px solid #cbd5e1;border-radius:10px;font-family:monospace;font-size:12px;color:#1e293b;line-height:1.5;resize:vertical;"></textarea>
                    <span style="font-size:11px;color:#64748b;display:block;margin-top:4px;">
                        * Pemisah dapat berupa koma (,), titik koma (;), atau tab dari Microsoft Excel.
                    </span>
                </div>
            </div>

            <!-- Opsi Tambahan -->
            <div style="background:#f1f5f9;border-radius:10px;padding:12px 16px;margin-bottom:20px;display:flex;flex-direction:column;gap:8px;">
                <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;color:#334155;cursor:pointer;margin:0;">
                    <input type="checkbox" id="cbt-opt-overwrite" checked style="width:16px;height:16px;cursor:pointer;">
                    <span>Timpa nilai CBT mahasiswa jika sudah pernah diisi sebelumnya</span>
                </label>
                <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;color:#334155;cursor:pointer;margin:0;">
                    <input type="checkbox" id="cbt-opt-autoreg" checked style="width:16px;height:16px;cursor:pointer;">
                    <span>Otomatis daftarkan mahasiswa ke pretes periode ini jika akun ada di tabel users namun belum terdaftar</span>
                </label>
            </div>

            <!-- Area Hasil & Log Impor (Dinamis) -->
            <div id="cbt-result-box" style="display:none;margin-bottom:18px;">
                <div id="cbt-progress-bar-wrap" style="display:none;margin-bottom:12px;">
                    <div style="font-size:12.5px;font-weight:700;color:#2563eb;margin-bottom:6px;display:flex;align-items:center;gap:6px;">
                        <span class="spinner" style="display:inline-block;width:14px;height:14px;border:2px solid #93c5fd;border-top-color:#2563eb;border-radius:50%;animation:spin 0.8s linear infinite;"></span>
                        Sedang memproses data nilai CBT...
                    </div>
                </div>
                <div id="cbt-feedback-alert"></div>
                <div id="cbt-details-table-wrap" style="max-height:220px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;display:none;margin-top:10px;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead style="background:#f8fafc;position:sticky;top:0;border-bottom:1px solid #e2e8f0;">
                            <tr>
                                <th style="padding:6px 10px;text-align:left;">NIM</th>
                                <th style="padding:6px 10px;text-align:left;">Nama</th>
                                <th style="padding:6px 10px;text-align:center;">Kelas</th>
                                <th style="padding:6px 10px;text-align:center;">Nilai</th>
                                <th style="padding:6px 10px;text-align:left;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="cbt-details-tbody"></tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- Footer Modal -->
        <div style="background:#f8fafc;padding:16px 24px;border-bottom-left-radius:17px;border-bottom-right-radius:17px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <a href="download-template-pretes-cbt.php" class="btn btn-sm btn-outline-secondary" style="font-size:12.5px;font-weight:700;padding:7px 14px;border-radius:8px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;border:1.5px solid #cbd5e1;color:#475569;background:#ffffff;">
                📥 Unduh Template CSV
            </a>
            <div style="display:flex;align-items:center;gap:8px;">
                <button type="button" class="btn btn-sm btn-secondary" onclick="closeImportCbtModal()" style="padding:8px 16px;border-radius:8px;font-weight:700;font-size:13px;border:none;background:#e2e8f0;color:#475569;cursor:pointer;">
                    Batal
                </button>
                <button type="button" id="btn-submit-cbt-import" onclick="submitCbtImport()" class="btn btn-sm btn-primary" style="padding:8px 20px;border-radius:8px;font-weight:800;font-size:13px;border:none;background:#2563eb;color:#ffffff;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 6px rgba(37,99,235,0.3);">
                    🚀 Mulai Import Nilai CBT
                </button>
                <button type="button" id="btn-reload-cbt-after" onclick="window.location.reload()" class="btn btn-sm btn-success" style="display:none;padding:8px 18px;border-radius:8px;font-weight:800;font-size:13px;border:none;background:#16a34a;color:#ffffff;cursor:pointer;">
                    🔄 Muat Ulang Halaman
                </button>
            </div>
        </div>

    </div>
</div>
<?php endif; ?>
</script>

<?php include __DIR__ . '/footer.php'; ?>
