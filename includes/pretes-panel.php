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
        } else {
            throw new InvalidArgumentException('Tindakan tidak valid.');
        }

        $pdo->commit();
        $_SESSION['pretes_flash'] = $message;
        header('Location: ?' . http_build_query(['pretes_id' => $periodId, 'registration_id' => $_POST['registration_id'] ?? '', 'session_id' => $_POST['session_id'] ?? '']) . (isset($sessionId) ? '#qr-session-' . (int)$sessionId : '#nilai-pretes'));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Gagal menyimpan. Silakan coba kembali.';
        if (!($e instanceof InvalidArgumentException)) error_log('Pretes panel: ' . $e->getMessage());
        $msgType = 'danger';
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

.filter-tab-btn.active {
    background: #0284c7 !important;
    color: #ffffff !important;
    border-color: #0284c7 !important;
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
    .desktop-student-table {
        display: none !important;
    }
    .mobile-student-list {
        display: flex !important;
    }
}

@media (min-width: 769px) {
    .desktop-student-table {
        display: block !important;
    }
    .mobile-student-list {
        display: none !important;
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

    <!-- Periode Selector Form -->
    <form method="GET" data-no-spa style="display:flex;align-items:center;gap:10px;background:rgba(255,255,255,0.15);padding:8px 12px;border-radius:12px;backdrop-filter:blur(6px);">
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
            <div style="font-size:24px;font-weight:800;color:#0f766e;line-height:1.1;">
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
            <div style="font-size:24px;font-weight:800;color:#ea580c;line-height:1.1;">
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

<!-- ── 1. SESI QR CODE ABSENSI PRETES ── -->
<?php if (!empty($groups)): ?>
<div class="card" id="absensi-pretes" style="border-radius:16px;border:1px solid #e2e8f0;margin-bottom:24px;overflow:hidden;">
    <div class="card-header" style="background:#f8fafc;padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="width:38px;height:38px;border-radius:10px;background:#e0f2fe;color:#0284c7;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;">
                📱
            </div>
            <div>
                <h3 style="margin:0;font-size:15px;font-weight:800;color:#0f172a;">Sesi QR Code Absensi Mahasiswa</h3>
                <span style="font-size:12px;color:#64748b;">Buka absensi untuk mengizinkan mahasiswa scan kehadiran mandiri lewat kamera HP</span>
            </div>
        </div>
    </div>
    <div class="card-body" style="padding:20px;">
        <div class="pretes-qr-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(min(100%, 320px), 1fr));gap:16px;">
        <?php foreach ($groups as $group): 
            $scope = $group['scope'];
            $session = pretesSession($pdo, $scope);
            $url = pretesAttendanceUrl($session);
            $isOpen = (bool)$session['is_open'];
        ?>
            <div class="pretes-qr-session-card" id="qr-session-<?= (int)$session['id'] ?>" 
                style="background:#ffffff;border:1.5px solid <?= $isOpen ? '#86efac' : '#e2e8f0' ?>;border-radius:14px;padding:18px;display:flex;flex-direction:column;justify-content:space-between;box-shadow:0 2px 8px rgba(0,0,0,0.03);position:relative;">
                
                <div>
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:12px;">
                        <div>
                            <span style="font-size:11px;font-weight:700;color:#0284c7;text-transform:uppercase;letter-spacing:0.5px;">Sesi Ujian Pretes</span>
                            <h4 style="margin:2px 0 0;font-size:16px;font-weight:800;color:#0f172a;">
                                📅 <?= sanitize($scope['hari']) ?> · 13.00 WIB
                            </h4>
                        </div>
                        <?php if ($isOpen): ?>
                            <span style="background:#dcfce7;color:#15803d;padding:5px 12px;border-radius:20px;font-size:11.5px;font-weight:800;display:inline-flex;align-items:center;gap:6px;box-shadow:0 0 0 3px rgba(34,197,94,0.15);">
                                <span style="width:7px;height:7px;border-radius:50%;background:#16a34a;animation:pulseGlow 1.5s infinite;"></span> ABSENSI BUKA
                            </span>
                        <?php else: ?>
                            <span style="background:#f1f5f9;color:#64748b;padding:5px 12px;border-radius:20px;font-size:11.5px;font-weight:700;">
                                ○ TERTUTUP
                            </span>
                        <?php endif; ?>
                    </div>

                    <div style="font-size:12.5px;color:#475569;background:#f8fafc;padding:10px 12px;border-radius:10px;margin-bottom:14px;line-height:1.5;">
                        <div>🏢 Ruangan: <strong><?= sanitize($scope['ruangan'] ?: 'Ruangan belum ditentukan') ?></strong></div>
                        <div>👥 Peserta Terdaftar: <strong><?= $group['count'] ?> Mahasiswa</strong></div>
                        <div>👨‍🏫 Penguji: <strong><?= sanitize($scope['penguji'] ?: 'Belum ditentukan') ?></strong></div>
                    </div>

                    <!-- QR Box Area -->
                    <div style="text-align:center;padding:12px;background:#f8fafc;border:1px dashed <?= $isOpen ? '#86efac' : '#cbd5e1' ?>;border-radius:12px;margin-bottom:14px;">
                        <div class="pretes-qr" data-url="<?= sanitize($url) ?>" style="display:inline-block;padding:10px;background:#ffffff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.06);min-width:180px;min-height:180px;"></div>
                        
                        <div style="margin-top:10px;">
                            <?php if ($isOpen): ?>
                                <p style="margin:0 0 8px;font-size:12px;color:#15803d;font-weight:700;">
                                    🟢 Mahasiswa dapat langsung scan QR di atas
                                </p>
                            <?php else: ?>
                                <p style="margin:0 0 8px;font-size:12px;color:#64748b;font-weight:600;">
                                    🔒 Absensi ditutup. Buka sesi agar mahasiswa dapat scan.
                                </p>
                            <?php endif; ?>
                            <button type="button" onclick="copyAttendanceLink('<?= sanitize($url) ?>', this)" class="btn btn-sm btn-secondary" style="font-size:11.5px;padding:5px 12px;">
                                📋 Salin Link Absensi
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Form Buka/Tutup Absensi -->
                <form method="POST" data-no-spa style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                    <?php if ($isOpen): ?>
                        <button name="action" value="close_attendance" class="btn btn-danger" style="width:100%;font-weight:700;padding:10px 14px;font-size:13px;display:flex;align-items:center;justify-content:center;gap:6px;">
                            🔒 Tutup Sesi Absensi
                        </button>
                    <?php else: ?>
                        <button name="action" value="open_attendance" class="btn btn-success" style="width:100%;font-weight:700;padding:10px 14px;font-size:13px;display:flex;align-items:center;justify-content:center;gap:6px;">
                            🟢 Buka Sesi Absensi (2 Jam)
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── 2. FORM INPUT NILAI MAHASISWA TERPILIH ── -->
<?php if ($selected):
    $scores = json_decode($selected['scores_json'] ?? '{}', true) ?: [];
    if ($msgType === 'danger' && ($_POST['action'] ?? '') === 'save_scores' && (int)($_POST['registration_id'] ?? 0) === (int)$selected['id'] && is_array($_POST['scores'] ?? null)) {
        $scores = array_filter($_POST['scores'], 'is_scalar');
    }
    $isAttended = !empty($selected['attended_at']) || ($selected['status'] ?? '') === 'hadir';
?>
<div class="card" id="nilai-pretes" style="border:2.5px solid #0284c7;border-radius:16px;box-shadow:0 8px 30px rgba(2,132,199,0.12);margin-bottom:24px;overflow:hidden;background:#ffffff;">
    <!-- Student Header Banner -->
    <div style="background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%);color:#ffffff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,0.2);color:#ffffff;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:800;border:1px solid rgba(255,255,255,0.3);">
                <?= strtoupper(substr($selected['nama_lengkap'], 0, 1)) ?>
            </div>
            <div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <h3 style="margin:0;font-size:17px;font-weight:800;color:#ffffff;line-height:1.2;">
                        <?= sanitize($selected['nama_lengkap']) ?>
                    </h3>
                    <?php if ($isAttended): ?>
                        <span style="background:#22c55e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;">✓ Hadir</span>
                    <?php else: ?>
                        <span style="background:rgba(255,255,255,0.25);color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;">Belum Absen</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:12.5px;color:#e0f2fe;margin-top:2px;">
                    NIM: <strong><?= sanitize($selected['nim']) ?></strong> · Prodi: <strong><?= sanitize($selected['program_studi']) ?></strong> · Hari: <strong><?= sanitize($selected['hari_pilihan'] ?: 'Senin') ?></strong>
                </div>
            </div>
        </div>

        <a href="?pretes_id=<?= (int)$periodId ?>#peserta-pretes" class="btn btn-sm" style="background:rgba(255,255,255,0.18);color:#ffffff;border:1px solid rgba(255,255,255,0.3);border-radius:8px;font-weight:700;text-decoration:none;padding:7px 14px;display:inline-flex;align-items:center;gap:6px;">
            ✕ Tutup Form
        </a>
    </div>

    <div class="card-body" style="padding:20px;">
        <!-- Live Calculation Bar -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
            <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
                <div>
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Rata-rata Nilai</div>
                    <div style="font-size:26px;font-weight:900;color:#0f172a;line-height:1;" id="live-avg">—</div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Predikat</div>
                    <div id="live-grade"><span class="badge" style="background:#e2e8f0;color:#64748b;font-size:12px;font-weight:700;">Belum Lengkap</span></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Kelengkapan</div>
                    <div style="font-size:13.5px;font-weight:800;color:#0f172a;">
                        <span id="live-filled">0</span> / <?= count($components) ?> Komponen
                    </div>
                </div>
            </div>

            <!-- Quick fill toolbar -->
            <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
                <span style="font-size:12px;font-weight:700;color:#64748b;margin-right:4px;">Isi Cepat Semua:</span>
                <?php foreach ([60, 70, 75, 80, 85, 90, 100] as $preset): ?>
                <button type="button" class="btn btn-sm btn-secondary quick-fill-all" data-score="<?= $preset ?>" style="padding:5px 9px;font-size:11.5px;font-weight:700;border-radius:6px;">
                    <?= $preset ?>
                </button>
                <?php endforeach; ?>
                <button type="button" class="btn btn-sm btn-secondary reset-all-scores" style="padding:5px 9px;font-size:11.5px;font-weight:700;color:#dc2626;border-radius:6px;">
                    Reset
                </button>
            </div>
        </div>

        <form method="POST" id="pretes-score-form" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_scores">
            <input type="hidden" name="registration_id" value="<?= (int)$selected['id'] ?>">

            <!-- 3 Component Categories -->
            <?php 
            $compNum = 0;
            foreach ($componentCategories as $catTitle => $catItems): 
            ?>
                <div style="margin-bottom:24px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;padding-bottom:6px;border-bottom:1.5px solid #e2e8f0;">
                        <strong style="font-size:14px;color:#0f172a;text-transform:uppercase;letter-spacing:0.5px;">
                            <?= $catTitle ?>
                        </strong>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(min(100%, 340px), 1fr));gap:12px;">
                    <?php foreach ($catItems as $key => $label): $compNum++; ?>
                        <div class="pretes-score-box">
                            <label for="nilai-<?= $key ?>" style="display:block;font-size:13px;font-weight:700;color:#1e293b;margin-bottom:8px;">
                                <?= $compNum ?>. <?= sanitize($label) ?>
                            </label>
                            <div class="pretes-score-row" style="display:flex;align-items:center;gap:6px;">
                                <input id="nilai-<?= $key ?>" name="scores[<?= $key ?>]" type="number" min="0" max="100" step="0.01" 
                                    value="<?= sanitize((string)($scores[$key] ?? '')) ?>" placeholder="0–100">
                                
                                <div style="display:flex;gap:4px;flex:1;">
                                    <?php foreach ([60, 70, 80, 90, 100] as $preset): ?>
                                    <button type="button" class="score-preset" data-score="<?= $preset ?>" aria-pressed="false"
                                        aria-label="Beri nilai <?= $preset ?> untuk <?= sanitize($label) ?>">
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

            <div style="display:flex;align-items:center;gap:12px;margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0;flex-wrap:wrap;">
                <button type="submit" class="btn btn-success" style="padding:12px 28px;font-size:14px;font-weight:800;display:inline-flex;align-items:center;gap:8px;border-radius:10px;box-shadow:0 4px 14px rgba(22,163,74,0.3);">
                    💾 Simpan Nilai <?= sanitize($selected['nama_lengkap']) ?>
                </button>
                <a href="?pretes_id=<?= (int)$periodId ?>#peserta-pretes" class="btn btn-secondary" style="padding:12px 20px;font-size:14px;font-weight:700;text-decoration:none;border-radius:10px;">
                    ✕ Batal / Kembali ke Daftar
                </a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── 3. DAFTAR MAHASISWA & REKAP PENILAIAN ── -->
<div class="card" id="peserta-pretes" style="border-radius:16px;border:1px solid #e2e8f0;box-shadow:0 2px 12px rgba(0,0,0,0.04);margin-bottom:24px;overflow:hidden;">
    <div class="card-header" style="background:#f8fafc;padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h3 style="margin:0;font-size:16px;font-weight:800;color:#0f172a;">
                👥 Daftar Mahasiswa Pretes (<?= count($participants) ?> Peserta)
            </h3>
            <span style="font-size:12px;color:#64748b;">
                Klik tombol "Input Nilai" pada mahasiswa untuk mengisi atau memperbarui penilaian
            </span>
        </div>
    </div>

    <div class="card-body" style="padding:18px 20px;">
        <!-- Search and Filter Bar -->
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
            <!-- Search Box -->
            <div style="flex:1;min-width:240px;position:relative;">
                <input type="text" id="search-student" oninput="filterStudents()" placeholder="🔍 Cari nama mahasiswa atau NIM..."
                    style="width:100%;padding:10px 14px 10px 38px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:13px;outline:none;box-sizing:border-box;">
                <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:15px;color:#94a3b8;pointer-events:none;">🔍</span>
            </div>

            <!-- Filter Buttons -->
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <button type="button" class="btn btn-sm filter-tab-btn active" data-filter="all" onclick="setStudentFilter('all', this)" style="padding:6px 12px;font-size:12px;font-weight:700;border-radius:8px;">
                    Semua (<?= count($participants) ?>)
                </button>
                <button type="button" class="btn btn-sm btn-secondary filter-tab-btn" data-filter="unscored" onclick="setStudentFilter('unscored', this)" style="padding:6px 12px;font-size:12px;font-weight:700;border-radius:8px;">
                    Belum Lengkap (<?= $progress['sebagian'] + $progress['kosong'] ?>)
                </button>
                <button type="button" class="btn btn-sm btn-secondary filter-tab-btn" data-filter="complete" onclick="setStudentFilter('complete', this)" style="padding:6px 12px;font-size:12px;font-weight:700;border-radius:8px;">
                    Nilai Lengkap (<?= $progress['lengkap'] ?>)
                </button>
                <button type="button" class="btn btn-sm btn-secondary filter-tab-btn" data-filter="attended" onclick="setStudentFilter('attended', this)" style="padding:6px 12px;font-size:12px;font-weight:700;border-radius:8px;">
                    Hadir (<?= $progress['hadir'] ?>)
                </button>
            </div>
        </div>

        <?php if (empty($participants)): ?>
            <div style="text-align:center;padding:40px 10px;color:#64748b;">
                <div style="font-size:40px;margin-bottom:8px;">👥</div>
                <strong style="font-size:15px;color:#0f172a;display:block;">Tidak ada peserta</strong>
                <p style="font-size:13px;margin:4px 0 0;">Belum ada mahasiswa yang ditugaskan pada periode ini.</p>
            </div>
        <?php else: ?>

            <!-- Desktop View Table -->
            <div class="desktop-student-table" style="overflow-x:auto;">
                <table class="no-datatable" style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="border-bottom:2px solid #e2e8f0;background:#f8fafc;">
                            <th style="padding:12px 14px;text-align:center;width:48px;color:#64748b;font-weight:700;">No</th>
                            <th style="padding:12px 14px;text-align:left;color:#64748b;font-weight:700;">Mahasiswa</th>
                            <th style="padding:12px 14px;text-align:left;color:#64748b;font-weight:700;">Jadwal & Ruangan</th>
                            <th style="padding:12px 14px;text-align:center;color:#64748b;font-weight:700;width:120px;">Kehadiran</th>
                            <th style="padding:12px 14px;text-align:center;color:#64748b;font-weight:700;width:160px;">Nilai Rata-rata</th>
                            <th style="padding:12px 14px;text-align:right;color:#64748b;font-weight:700;width:130px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="student-table-body">
                    <?php $no = 0; foreach ($participants as $row): $no++;
                        $scores = json_decode($row['scores_json'] ?? '{}', true) ?: [];
                        $filled = 0;
                        $sum = 0;
                        foreach ($components as $k => $l) {
                            if (isset($scores[$k]) && $scores[$k] !== '' && $scores[$k] !== null && is_numeric($scores[$k])) {
                                $filled++;
                                $sum += (float)$scores[$k];
                            }
                        }
                        $avg = $filled > 0 ? round($sum / $filled, 2) : null;
                        $isAttended = !empty($row['attended_at']) || ($row['status'] ?? '') === 'hadir';
                        $statusCat = ($filled === count($components)) ? 'complete' : ($filled > 0 ? 'partial' : 'unscored');
                        $isSelected = ($selectedId === (int)$row['id']);
                    ?>
                        <tr class="student-item student-row" 
                            data-name="<?= strtolower(sanitize($row['nama_lengkap'])) ?>"
                            data-nim="<?= strtolower(sanitize($row['nim'])) ?>"
                            data-prodi="<?= strtolower(sanitize($row['program_studi'])) ?>"
                            data-status="<?= $statusCat ?>"
                            data-attended="<?= $isAttended ? '1' : '0' ?>"
                            style="border-bottom:1px solid #f1f5f9;transition:background 0.15s;<?= $isSelected ? 'background:#f0f9ff;outline:2px solid #0284c7;outline-offset:-2px;' : '' ?>">
                            
                            <td style="padding:12px 14px;text-align:center;color:#94a3b8;font-weight:600;">
                                <?= $no ?>
                            </td>

                            <td style="padding:12px 14px;">
                                <div style="font-weight:800;color:#0f172a;font-size:13.5px;">
                                    <?= sanitize($row['nama_lengkap']) ?>
                                    <?php if ($isSelected): ?>
                                        <span style="background:#0284c7;color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:10px;margin-left:4px;">Sedang Dinilai</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:12px;color:#64748b;margin-top:2px;">
                                    NIM: <span style="font-family:monospace;font-weight:700;color:#334155;"><?= sanitize($row['nim']) ?></span> · <?= sanitize($row['program_studi']) ?>
                                </div>
                            </td>

                            <td style="padding:12px 14px;font-size:12.5px;color:#475569;">
                                <div>📅 <?= sanitize($row['hari_pilihan'] ?: 'Senin') ?> (13.00 WIB)</div>
                                <div style="color:#64748b;font-size:11.5px;">🏢 <?= sanitize($row['ruangan'] ?: 'Ruangan belum ditentukan') ?></div>
                            </td>

                            <td style="padding:12px 14px;text-align:center;">
                                <?php if ($isAttended): ?>
                                    <span class="badge badge-success" style="font-size:11.5px;padding:4px 10px;font-weight:700;">
                                        ✓ Hadir
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background:#f1f5f9;color:#64748b;font-size:11.5px;padding:4px 10px;font-weight:600;">
                                        Belum Absen
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td style="padding:12px 14px;text-align:center;">
                                <?php if ($filled === count($components)): ?>
                                    <div style="font-size:14px;font-weight:900;color:#15803d;"><?= number_format($avg, 2) ?></div>
                                    <div style="font-size:11px;color:#16a34a;font-weight:700;">Lengkap (12/12)</div>
                                <?php elseif ($filled > 0): ?>
                                    <div style="font-size:14px;font-weight:900;color:#b45309;"><?= number_format($avg, 2) ?></div>
                                    <div style="font-size:11px;color:#d97706;font-weight:700;">Sebagian (<?= $filled ?>/12)</div>
                                <?php else: ?>
                                    <span style="font-size:12px;color:#94a3b8;font-weight:600;">Belum Dinilai</span>
                                <?php endif; ?>
                            </td>

                            <td style="padding:12px 14px;text-align:right;">
                                <a href="?<?= sanitize(http_build_query(['pretes_id' => $periodId, 'registration_id' => $row['id']])) ?>#nilai-pretes"
                                    class="btn <?= ($filled === count($components)) ? 'btn-secondary' : 'btn-primary' ?>"
                                    style="padding:6px 14px;font-size:12px;font-weight:700;border-radius:8px;text-decoration:none;display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">
                                    <?= ($filled === count($components)) ? '✏️ Edit Nilai' : '📝 Input Nilai' ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card View (<= 768px) -->
            <div class="mobile-student-list" style="display:none;flex-direction:column;gap:12px;">
            <?php foreach ($participants as $row): 
                $scores = json_decode($row['scores_json'] ?? '{}', true) ?: [];
                $filled = 0;
                $sum = 0;
                foreach ($components as $k => $l) {
                    if (isset($scores[$k]) && $scores[$k] !== '' && $scores[$k] !== null && is_numeric($scores[$k])) {
                        $filled++;
                        $sum += (float)$scores[$k];
                    }
                }
                $avg = $filled > 0 ? round($sum / $filled, 2) : null;
                $isAttended = !empty($row['attended_at']) || ($row['status'] ?? '') === 'hadir';
                $statusCat = ($filled === count($components)) ? 'complete' : ($filled > 0 ? 'partial' : 'unscored');
                $isSelected = ($selectedId === (int)$row['id']);
            ?>
                <div class="student-item student-mobile-card"
                    data-name="<?= strtolower(sanitize($row['nama_lengkap'])) ?>"
                    data-nim="<?= strtolower(sanitize($row['nim'])) ?>"
                    data-prodi="<?= strtolower(sanitize($row['program_studi'])) ?>"
                    data-status="<?= $statusCat ?>"
                    data-attended="<?= $isAttended ? '1' : '0' ?>"
                    style="background:#ffffff;border:1.5px solid <?= $isSelected ? '#0284c7' : '#e2e8f0' ?>;border-radius:14px;padding:14px;box-shadow:0 1px 4px rgba(0,0,0,0.03);position:relative;<?= $isSelected ? 'background:#f0f9ff;' : '' ?>">
                    
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:8px;">
                        <div>
                            <div style="font-weight:800;font-size:14.5px;color:#0f172a;">
                                <?= sanitize($row['nama_lengkap']) ?>
                            </div>
                            <div style="font-size:12px;color:#64748b;margin-top:2px;">
                                NIM: <strong style="color:#1e293b;"><?= sanitize($row['nim']) ?></strong> · <?= sanitize($row['program_studi']) ?>
                            </div>
                        </div>
                        <?php if ($isAttended): ?>
                            <span class="badge badge-success" style="font-size:10.5px;padding:3px 8px;">✓ Hadir</span>
                        <?php else: ?>
                            <span class="badge" style="background:#f1f5f9;color:#64748b;font-size:10.5px;padding:3px 8px;">Belum Absen</span>
                        <?php endif; ?>
                    </div>

                    <div style="font-size:12px;color:#475569;background:#f8fafc;padding:8px 10px;border-radius:8px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
                        <span>🏢 <?= sanitize($row['ruangan'] ?: 'Ruangan -') ?> (<?= sanitize($row['hari_pilihan'] ?: 'Senin') ?>)</span>
                        <span>
                            <?php if ($filled === count($components)): ?>
                                <strong style="color:#15803d;font-size:13px;"><?= number_format($avg, 2) ?></strong> (12/12)
                            <?php elseif ($filled > 0): ?>
                                <strong style="color:#b45309;font-size:13px;"><?= number_format($avg, 2) ?></strong> (<?= $filled ?>/12)
                            <?php else: ?>
                                <span style="color:#94a3b8;">Belum Dinilai</span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <a href="?<?= sanitize(http_build_query(['pretes_id' => $periodId, 'registration_id' => $row['id']])) ?>#nilai-pretes"
                        class="btn <?= ($filled === count($components)) ? 'btn-secondary' : 'btn-primary' ?>"
                        style="width:100%;padding:9px 12px;font-size:12.5px;font-weight:700;border-radius:8px;text-align:center;text-decoration:none;display:block;">
                        <?= ($filled === count($components)) ? '✏️ Edit Nilai Mahasiswa' : '📝 Input Nilai Mahasiswa ➔' ?>
                    </a>
                </div>
            <?php endforeach; ?>
            </div>

            <div id="no-students-found" style="display:none;text-align:center;padding:30px 10px;color:#64748b;">
                <div style="font-size:36px;margin-bottom:8px;">🔍</div>
                <strong style="font-size:14px;color:#0f172a;display:block;">Mahasiswa tidak ditemukan</strong>
                <p style="font-size:12.5px;margin:4px 0 0;">Coba ganti kata kunci pencarian atau ubah filter di atas.</p>
            </div>

        <?php endif; ?>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/lib/qrcode.min.js"></script>
<script>
// ── 1. Live Scoring Calculation ─────────────────────────────────────────────
function updateLiveScores() {
    var inputs = document.querySelectorAll('#pretes-score-form input[type="number"]');
    var filled = 0;
    var sum = 0;
    var totalCount = inputs.length;

    inputs.forEach(function(inp) {
        var val = inp.value.trim();
        if (val !== '' && !isNaN(val)) {
            var num = parseFloat(val);
            if (num >= 0 && num <= 100) {
                filled++;
                sum += num;
            }
        }
    });

    var avgEl = document.getElementById('live-avg');
    var gradeEl = document.getElementById('live-grade');
    var filledEl = document.getElementById('live-filled');

    if (filledEl) filledEl.textContent = filled;

    if (filled === 0) {
        if (avgEl) avgEl.textContent = '—';
        if (gradeEl) gradeEl.innerHTML = '<span class="badge" style="background:#e2e8f0;color:#64748b;font-size:12px;font-weight:700;">Belum Ada Nilai</span>';
    } else {
        var avg = (sum / filled).toFixed(2);
        if (avgEl) avgEl.textContent = avg;

        var numAvg = parseFloat(avg);
        var gradeHtml = '';
        if (filled < totalCount) {
            gradeHtml = '<span class="badge badge-warning" style="font-size:12px;font-weight:700;">Sebagian (' + filled + '/' + totalCount + ')</span>';
        } else if (numAvg >= 85) {
            gradeHtml = '<span class="badge badge-success" style="font-size:12px;font-weight:700;">A (Sangat Baik)</span>';
        } else if (numAvg >= 75) {
            gradeHtml = '<span class="badge badge-primary" style="font-size:12px;font-weight:700;">B (Baik)</span>';
        } else if (numAvg >= 65) {
            gradeHtml = '<span class="badge badge-warning" style="font-size:12px;font-weight:700;">C (Cukup)</span>';
        } else if (numAvg >= 55) {
            gradeHtml = '<span class="badge badge-danger" style="font-size:12px;font-weight:700;">D (Kurang)</span>';
        } else {
            gradeHtml = '<span class="badge badge-danger" style="font-size:12px;font-weight:700;">E (Gagal)</span>';
        }
        if (gradeEl) gradeEl.innerHTML = gradeHtml;
    }
}

// ── 2. Preset Score Buttons & Quick Fill ────────────────────────────────────
document.querySelectorAll('.pretes-score-row').forEach(function(row) {
    var input = row.querySelector('input');
    if (!input) return;

    function updatePresets() {
        row.querySelectorAll('.score-preset').forEach(function(button) {
            var isPressed = input.value !== '' && Number(input.value) === Number(button.dataset.score);
            button.setAttribute('aria-pressed', isPressed ? 'true' : 'false');
        });
        updateLiveScores();
    }

    row.querySelectorAll('.score-preset').forEach(function(button) {
        button.addEventListener('click', function() {
            input.value = button.dataset.score;
            input.dispatchEvent(new Event('input', {bubbles:true}));
        });
    });

    input.addEventListener('input', updatePresets);
    updatePresets();
});

// Quick fill all inputs
document.querySelectorAll('.quick-fill-all').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var val = this.dataset.score;
        document.querySelectorAll('#pretes-score-form input[type="number"]').forEach(function(inp) {
            inp.value = val;
            inp.dispatchEvent(new Event('input', {bubbles:true}));
        });
    });
});

// Reset all inputs
document.querySelector('.reset-all-scores')?.addEventListener('click', function() {
    if (confirm('Apakah Anda yakin ingin mengosongkan semua isian nilai peserta ini?')) {
        document.querySelectorAll('#pretes-score-form input[type="number"]').forEach(function(inp) {
            inp.value = '';
            inp.dispatchEvent(new Event('input', {bubbles:true}));
        });
    }
});

// ── 3. Render QR Codes Otomatis ─────────────────────────────────────────────
document.querySelectorAll('.pretes-qr').forEach(function(el) {
    if (!el.dataset.rendered && el.dataset.url) {
        new QRCode(el, {
            text: el.dataset.url,
            width: 180,
            height: 180,
            correctLevel: QRCode.CorrectLevel.M
        });
        el.dataset.rendered = 'true';
    }
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

// ── 4. Instant Student Search & Filter Tabs ─────────────────────────────────
var currentStudentFilter = 'all';

function setStudentFilter(filter, btn) {
    currentStudentFilter = filter;
    document.querySelectorAll('.filter-tab-btn').forEach(function(b) {
        b.classList.remove('active');
        b.classList.add('btn-secondary');
    });
    btn.classList.add('active');
    btn.classList.remove('btn-secondary');
    filterStudents();
}

function filterStudents() {
    var query = (document.getElementById('search-student')?.value || '').toLowerCase().trim();
    var visibleCount = 0;

    document.querySelectorAll('.student-item').forEach(function(item) {
        var name = item.dataset.name || '';
        var nim = item.dataset.nim || '';
        var prodi = item.dataset.prodi || '';
        var status = item.dataset.status;
        var attended = item.dataset.attended;

        var matchesQuery = !query || name.indexOf(query) !== -1 || nim.indexOf(query) !== -1 || prodi.indexOf(query) !== -1;
        var matchesFilter = true;

        if (currentStudentFilter === 'unscored') {
            matchesFilter = (status === 'unscored' || status === 'partial');
        } else if (currentStudentFilter === 'complete') {
            matchesFilter = (status === 'complete');
        } else if (currentStudentFilter === 'attended') {
            matchesFilter = (attended === '1');
        }

        if (matchesQuery && matchesFilter) {
            item.style.display = '';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });

    var noFound = document.getElementById('no-students-found');
    if (noFound) {
        noFound.style.display = (visibleCount === 0) ? 'block' : 'none';
    }
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
