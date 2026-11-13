<?php
/**
 * LPPAI Corner - Hasil Pretes
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pretes-helper.php';
requireLogin();

$user = getCurrentUser();
$pdo = getDBConnection();

// Pastikan fitur pretes siap
ensurePretesFeatures($pdo);

// Periode pretes aktif adalah sumber status, terlepas dari tutorial yang juga aktif.
ensurePretesPeriodTargetColumns($pdo);
$activePretes = null;
try {
    $activePeriods = $pdo->query("SELECT * FROM master_pretes WHERE status = 'aktif' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($activePeriods as $period) {
        if (isUserEligibleForPretesPeriod($user, $period)) { $activePretes = $period; break; }
    }
} catch (Exception $e) {}
$registrationOrder = $activePretes ? 'CASE WHEN pr.periode = ? THEN 0 ELSE 1 END, ' : '';

// Ambil data pendaftaran & asesmen nilai rinci terbaru mahasiswa
$stmtReg = $pdo->prepare("
    SELECT pr.*, pa.scores_json, pa.updated_at as assessed_at,
           ps.tanggal as jadwal_tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan as jadwal_ruangan
    FROM pretes_registrations pr
    LEFT JOIN pretes_assessments pa ON pa.registration_id = pr.id
    LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
    WHERE pr.user_id = ?
    ORDER BY {$registrationOrder}pr.id DESC
    LIMIT 1
");
$stmtReg->execute($activePretes ? [$user['id'], $activePretes['periode']] : [$user['id']]);
$myReg = $stmtReg->fetch();

// Ambil hasil pretes resmi jika ada di database
$stmtRes = $pdo->prepare("
    SELECT pr.*, ps.tanggal, ps.ruangan, ps.periode as schedule_periode
    FROM pretes_results pr
    LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
    WHERE pr.user_id = ?
    ORDER BY pr.created_at DESC
    LIMIT 1
");
$stmtRes->execute([$user['id']]);
$myResult = $stmtRes->fetch();

// Deteksi status aktif periode pendaftaran mahasiswa ($isRegPeriodeAktif):
$isRegPeriodeAktif = false;
$regPeriodeStatus = 'tidak_aktif';
if ($myReg) {
    if (!empty($myReg['periode'])) {
        try {
            $stmtPer = $pdo->prepare("SELECT status FROM master_pretes WHERE periode = ? ORDER BY id DESC LIMIT 1");
            $stmtPer->execute([$myReg['periode']]);
            $perStatus = $stmtPer->fetchColumn();
            if ($perStatus !== false) {
                $regPeriodeStatus = strtolower(trim((string)$perStatus));
            }
        } catch (Exception $e) {}
    } elseif ($activePretes) {
        $regPeriodeStatus = strtolower(trim((string)($activePretes['status'] ?? 'aktif')));
    }
    $isRegPeriodeAktif = ($regPeriodeStatus === 'aktif');
}

// Jangan menyimpulkan pretes selesai hanya karena periode tutorial sudah aktif.
$isEligibleActivePretes = isUserEligibleForPretesPeriod($user, $activePretes);
$isPretesSelesai = !$activePretes || !$isEligibleActivePretes;
$showPretesPreparation = $activePretes && $isEligibleActivePretes;
$registeredForActivePretes = $showPretesPreparation && $myReg
    && trim((string)($myReg['periode'] ?? '')) === trim((string)$activePretes['periode']);

// Cek apakah mahasiswa memiliki riwayat pretes (pendaftaran, hasil nilai, atau asesmen terisi)
$hasPretesRecord = (!empty($myResult) || !empty($myReg));

// Parsing nilai rinci 12 komponen
$scores = [];
if (!empty($myReg['scores_json'])) {
    $scores = json_decode($myReg['scores_json'], true) ?: [];
}

$components = pretesComponents();
$componentDetails = [];
$sum = 0;
$filledCount = 0;
$totalComponents = count($components); // Tepat 12 komponen wajib

foreach ($components as $key => $label) {
    $val = (isset($scores[$key]) && $scores[$key] !== '' && is_numeric($scores[$key])) ? (float)$scores[$key] : null;
    $isGood = ($val !== null && $val >= 80);
    $isLow = ($val !== null && $val < 80);
    
    if ($val !== null) {
        $sum += $val;
        $filledCount++;
    }
    
    $componentDetails[$key] = [
        'label' => $label,
        'value' => $val,
        'effective_score' => ($val !== null) ? $val : 0.0,
        'is_good' => $isGood,
        'is_low' => $isLow,
        'is_cbt' => ($key === 'ujian_tulis')
    ];
}

// ── Perhitungan Rata-Rata & Status Kelulusan Berdasarkan Status Periode ─────────
// Aturan Akademik:
// 1. Pada PERIODE AKTIF ($isRegPeriodeAktif === true):
//    - Jika penilaian belum lengkap ($filledCount < $totalComponents), status adalah 'menunggu'.
//      Sistem tidak boleh langsung memvonis 'tidak_lulus' karena ujian masih/akan berlangsung.
//    - Jika seluruh 12 komponen terisi lengkap ($filledCount === $totalComponents):
//      Lulus jika seluruhnya >= 80, Tidak Lulus jika ada nilai < 80.
// 2. Pada PERIODE TIDAK AKTIF / SELESAI ($isRegPeriodeAktif === false):
//    - Seluruh komponen kosong dihitung 0, sehingga jika belum lengkap atau < 80, status 'tidak_lulus'.

if ($isRegPeriodeAktif && $filledCount < $totalComponents) {
    $statusLulus = 'menunggu';
    $avgScore = ($filledCount > 0) ? round($sum / $filledCount, 2) : 0.00;
} else {
    if ($filledCount > 0) {
        $avgScore = round($sum / $totalComponents, 2);
    } elseif ($myResult && $myResult['nilai'] !== null) {
        $avgScore = round((float)$myResult['nilai'], 2);
    } else {
        $avgScore = 0.00;
    }

    $isLulus = ($filledCount === $totalComponents);
    if ($isLulus) {
        foreach ($componentDetails as $ck => $detail) {
            if ($detail['value'] === null || $detail['value'] < 80) {
                $isLulus = false;
                break;
            }
        }
    }
    $statusLulus = $isLulus ? 'lulus' : 'tidak_lulus';
}

$predikat = getPretesPredikat($avgScore);
$hasPretesRecord = (!empty($myResult) || !empty($myReg) || $filledCount > 0);

$periodeDisplay = !empty($myReg['periode']) ? $myReg['periode'] : (!empty($myResult['schedule_periode']) ? $myResult['schedule_periode'] : 'Pretes 2026/2027');

// Get all published results (public announcement) - hanya dimuat untuk Admin dan Dosen dengan Filter Periode
$allResults = [];
$allPeriods = [];
$filterPeriode = trim((string)($_GET['periode'] ?? ''));
if (isAdmin() || isDosen()) {
    try {
        $allPeriods = $pdo->query("SELECT id, nama_pretes, periode, status FROM master_pretes ORDER BY id DESC")->fetchAll();
    } catch (Exception $e) {}

    // Default ke periode aktif jika filter kosong dan periode aktif ada
    if ($filterPeriode === '' && $activePretes && !empty($activePretes['periode'])) {
        $filterPeriode = $activePretes['periode'];
    }

    $sqlResults = "
        SELECT pr.*, u.nama_lengkap, u.nim, u.program_studi, u.jenis_program,
               ps.periode as schedule_periode,
               COALESCE(mp.status, 'selesai') as periode_status,
               COALESCE(mp.nama_pretes, ps.periode, 'Pretes') as nama_pretes
        FROM pretes_results pr
        JOIN users u ON pr.user_id = u.id
        LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id
        LEFT JOIN master_pretes mp ON COALESCE(ps.periode, '') = mp.periode
        WHERE pr.status_lulus != 'belum_diumumkan'
    ";
    $paramsResults = [];
    if ($filterPeriode !== '' && $filterPeriode !== 'semua') {
        $sqlResults .= " AND (ps.periode = ? OR mp.periode = ?)";
        $paramsResults[] = $filterPeriode;
        $paramsResults[] = $filterPeriode;
    }
    $sqlResults .= " ORDER BY pr.nilai DESC";
    $stmtAllRes = $pdo->prepare($sqlResults);
    $stmtAllRes->execute($paramsResults);
    $allResults = $stmtAllRes->fetchAll();
}

define('PAGE_TITLE', $showPretesPreparation ? 'Informasi & Persiapan Pretes' : 'Pengumuman Hasil Pretes');
include __DIR__ . '/../includes/header.php';
?>

<style>
.pretes-ann-box {
    margin-top: 22px;
    background: #ffffff;
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
    border: 1px solid #e2e8f0;
}
.pretes-ann-box.lulus {
    border-color: #a7f3d0;
    box-shadow: 0 4px 20px rgba(16, 185, 129, 0.08);
}
.pretes-ann-box.tidak-lulus {
    border-color: #fecdd3;
    box-shadow: 0 4px 20px rgba(225, 29, 72, 0.07);
}
.pretes-ann-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 16px;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 14px;
}
.pretes-ann-icon-bubble {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    font-size: 24px;
    flex-shrink: 0;
}
.pretes-ann-title {
    margin: 0;
    font-size: 17px;
    font-weight: 800;
}
.pretes-ann-subtitle {
    margin: 3px 0 0 0;
    font-size: 13.5px;
    color: var(--text-muted);
    line-height: 1.55;
}
.pretes-step-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 14px;
    margin-top: 14px;
    margin-bottom: 16px;
}
.pretes-step-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    display: flex;
    flex-direction: column;
}
.pretes-step-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
    border-color: #cbd5e1;
}
.pretes-step-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.pretes-step-icon {
    font-size: 26px;
    line-height: 1;
}
.pretes-step-badge {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.pretes-step-title {
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 6px 0;
}
.pretes-step-desc {
    font-size: 12.5px;
    color: #475569;
    line-height: 1.55;
    margin: 0;
    flex: 1;
}
.pretes-step-footer {
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px dashed #e2e8f0;
    font-size: 11.5px;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 6px;
}
.pretes-ann-callout {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 12.5px;
    color: #166534;
    display: flex;
    align-items: center;
    gap: 10px;
    line-height: 1.5;
}
</style>

<?php if ($showPretesPreparation): ?>
    <?php include __DIR__ . '/../includes/pretes-preparation-view.php'; ?>
<?php else: ?>
<!-- Hasil & Nilai Keseluruhan Pretes Saya -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <span style="font-weight:700; font-size:16px;">📊 Pengumuman Hasil Kelulusan Pretes</span>
        <?php if ($hasPretesRecord): ?>
            <?php if ($statusLulus === 'lulus'): ?>
                <span class="badge badge-success" style="font-size:12.5px; padding:5px 12px; font-weight:700;">
                    Status Akhir: LULUS
                </span>
            <?php elseif ($statusLulus === 'menunggu'): ?>
                <span class="badge" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-size:12.5px; padding:5px 12px; font-weight:700;">
                    Status: SEDANG BERLANGSUNG / MENUNGGU PENILAIAN
                </span>
            <?php else: ?>
                <span class="badge badge-danger" style="font-size:12.5px; padding:5px 12px; font-weight:700;">
                    Status Akhir: TIDAK LULUS <?= !$isRegPeriodeAktif ? '(Periode Selesai)' : '' ?>
                </span>
            <?php endif; ?>
        <?php else: ?>
            <span class="badge" style="background:<?= $isPretesSelesai ? '#fee2e2' : '#f1f5f9' ?>; color:<?= $isPretesSelesai ? '#991b1b' : '#475569' ?>; border:1px solid <?= $isPretesSelesai ? '#fecaca' : '#cbd5e1' ?>; font-size:12.5px; padding:5px 12px; font-weight:700;">
                Status: <?= $isPretesSelesai ? 'BELUM MENGIKUTI PRETES' : 'BELUM TERDAFTAR' ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (!$hasPretesRecord): ?>
            <?php if ($isPretesSelesai): ?>
                <!-- Informasi Periode Pretes Telah Berakhir & Rekomendasi Wajib Tutorial -->
                <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:18px 20px; margin-bottom:22px; display:flex; gap:16px; align-items:flex-start;">
                    <div style="width:48px; height:48px; border-radius:12px; background:#fef3c7; color:#b45309; display:flex; align-items:center; justify-content:center; font-size:24px; flex-shrink:0;">
                        ⏳
                    </div>
                    <div style="flex:1;">
                        <h3 style="margin:0 0 6px 0; font-size:16px; font-weight:700; color:#92400e;">
                            Periode Pretes Telah Berakhir
                        </h3>
                        <p style="margin:0 0 14px 0; color:#78350f; font-size:13.5px; line-height:1.6;">
                            Pendaftaran pretes untuk periode ini telah ditutup oleh pengelola. Karena Anda belum memiliki riwayat kelulusan pretes, Anda <strong>diwajibkan untuk mendaftarkan diri pada program Tutorial LPPAI Gelombang 1</strong>.
                        </p>
                        <div style="display:flex; flex-wrap:wrap; gap:10px;">
                            <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="btn btn-success" style="display:inline-flex; align-items:center; gap:8px; width:auto; text-decoration:none; padding:9px 18px; font-size:13.5px; font-weight:700; border-radius:8px;">
                                📝 Daftar Program Tutorial Gelombang 1 &rarr;
                            </a>
                            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:8px; width:auto; text-decoration:none; padding:9px 18px; font-size:13.5px; font-weight:600; border-radius:8px;">
                                🏠 Kembali ke Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Informasi Pendaftaran Pretes Sedang Dibuka -->
                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:18px 20px; margin-bottom:22px; display:flex; gap:16px; align-items:flex-start;">
                    <div style="width:48px; height:48px; border-radius:12px; background:#dbeafe; color:#1d4ed8; display:flex; align-items:center; justify-content:center; font-size:24px; flex-shrink:0;">
                        ℹ️
                    </div>
                    <div style="flex:1;">
                        <h3 style="margin:0 0 6px 0; font-size:16px; font-weight:700; color:#1e40af;">
                            Pendaftaran Pretes Sedang Dibuka
                        </h3>
                        <p style="margin:0 0 14px 0; color:#1e3a8a; font-size:13.5px; line-height:1.6;">
                            Anda belum memiliki riwayat pretes. Silakan pilih hari dan jadwal pelaksanaan pretes yang tersedia sebelum kuota penuh.
                        </p>
                        <div style="display:flex; flex-wrap:wrap; gap:10px;">
                            <a href="<?= BASE_URL ?>/pretes-daftar.php" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px; width:auto; text-decoration:none; padding:9px 18px; font-size:13.5px; font-weight:700; border-radius:8px;">
                                📝 Daftar Pretes Sekarang &rarr;
                            </a>
                            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:8px; width:auto; text-decoration:none; padding:9px 18px; font-size:13.5px; font-weight:600; border-radius:8px;">
                                🏠 Kembali ke Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Teks Panduan Resmi Pengumuman Hasil Pretes -->
            <div class="pretes-ann-box" style="margin-top:16px;">
                <div class="pretes-ann-header">
                    <span class="pretes-ann-icon-bubble" style="background:#e0e7ff; color:#3730a3;">📢</span>
                    <div style="flex:1;">
                        <h3 class="pretes-ann-title" style="color:#1e1b4b;">
                            Pengumuman Hasil Pretes 2026/2027
                        </h3>
                        <p class="pretes-ann-subtitle">
                            Informasi petunjuk kelulusan dan tindak lanjut bagi seluruh mahasiswa peserta Pretes LPPAI.
                        </p>
                    </div>
                </div>

                <!-- Bagian 1: Lulus -->
                <div style="margin-bottom:18px;">
                    <h4 style="margin:0 0 10px 0; font-size:14.5px; font-weight:700; color:#065f46; display:flex; align-items:center; gap:6px;">
                        <span>🎉</span>
                        <span>Bagi Mahasiswa yang Dinyatakan LULUS Pretes:</span>
                    </h4>
                    <div class="pretes-step-grid">
                        <div class="pretes-step-card">
                            <div class="pretes-step-card-header">
                                <span class="pretes-step-icon">🕌</span>
                                <span class="pretes-step-badge" style="background:#e0f2fe; color:#0369a1;">Langkah 1</span>
                            </div>
                            <h4 class="pretes-step-title">Majlis Dzikir Al Khidmah</h4>
                            <p class="pretes-step-desc">
                                Menghadiri majlis dzikir rutin di <strong>Masjid KH. Soefyan AW</strong> yang diselenggarakan pada <strong>setiap awal bulan</strong>.
                            </p>
                        </div>
                        <div class="pretes-step-card">
                            <div class="pretes-step-card-header">
                                <span class="pretes-step-icon">📱</span>
                                <span class="pretes-step-badge" style="background:#fef3c7; color:#92400e;">Langkah 2</span>
                            </div>
                            <h4 class="pretes-step-title">Presensi Digital & Swafoto</h4>
                            <p class="pretes-step-desc">
                                Melakukan absensi kehadiran & kepulangan online via sistem LPPAI dengan mengunggah <strong>swafoto (selfie) yang jelas</strong>.
                            </p>
                        </div>
                        <div class="pretes-step-card">
                            <div class="pretes-step-card-header">
                                <span class="pretes-step-icon">💳</span>
                                <span class="pretes-step-badge" style="background:#dcfce7; color:#15803d;">Langkah 3</span>
                            </div>
                            <h4 class="pretes-step-title">Administrasi Pretes</h4>
                            <p class="pretes-step-desc">
                                Menyelesaikan pembayaran administrasi kelulusan pretes sebesar <strong style="color:#16a34a;">Rp 50.000,-</strong> melalui kanal resmi.
                            </p>
                        </div>
                        <div class="pretes-step-card">
                            <div class="pretes-step-card-header">
                                <span class="pretes-step-icon">🎓</span>
                                <span class="pretes-step-badge" style="background:#fae8ff; color:#86198f;">Langkah 4</span>
                            </div>
                            <h4 class="pretes-step-title">Pengambilan Sertifikat</h4>
                            <p class="pretes-step-desc">
                                Mengambil sertifikat di <strong>Kantor LPPAI (Lt. 2 Gedung Fakultas Hukum, Timur Kantin)</strong> dengan membawa <strong>Nota Pembayaran</strong> & <strong>Pasfoto 3×4</strong>.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Bagian 2: Tidak Lulus -->
                <div style="background:#fff1f2; border:1px solid #fecdd3; border-radius:12px; padding:16px;">
                    <h4 style="margin:0 0 6px 0; font-size:14.5px; font-weight:700; color:#9f1239; display:flex; align-items:center; gap:6px;">
                        <span>⚠️</span>
                        <span>Bagi Mahasiswa yang Dinyatakan TIDAK LULUS Pretes:</span>
                    </h4>
                    <p style="margin:0 0 10px 0; font-size:13px; color:#4c0519; line-height:1.5;">
                        Bagi mahasiswa yang belum memenuhi standar kelulusan pretes, Anda <strong>diwajibkan untuk mendaftarkan diri pada program Tutorial LPPAI Gelombang 1</strong>.
                    </p>
                    <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="btn btn-sm btn-danger" style="text-decoration:none; font-weight:700;">
                        📝 Daftar Tutorial Gelombang 1 ➔
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Box Ringkasan Nilai Keseluruhan -->
            <div style="background:var(--bg-light); border-radius:12px; padding:24px; margin-bottom:20px; border:1px solid #e2e8f0;">
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:20px; align-items:center;">
                    <div>
                        <strong style="color:var(--text-muted); font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">Periode Pretes</strong>
                        <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
                            <p style="font-size:18px; font-weight:700; color:#1e293b; margin:0;"><?= sanitize($periodeDisplay) ?></p>
                            <?php if ($isRegPeriodeAktif): ?>
                                <span class="badge badge-success" style="font-size:11px; padding:2px 8px;">🟢 Aktif</span>
                            <?php else: ?>
                                <span class="badge" style="background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; font-size:11px; padding:2px 8px;">⚪ Selesai</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($myReg['hari_pilihan']) || !empty($myReg['ruangan'])): ?>
                            <small style="color:var(--text-muted); display:block; margin-top:4px;">
                                <?= sanitize($myReg['hari_pilihan'] ?: 'Reguler') ?> <?= !empty($myReg['ruangan']) ? ' - Ruang ' . sanitize($myReg['ruangan']) : '' ?>
                            </small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong style="color:var(--text-muted); font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">Nilai Rata-rata Keseluruhan</strong>
                        <?php if ($statusLulus === 'menunggu'): ?>
                            <p style="font-size:26px; font-weight:800; color:#0284c7; margin:2px 0 0 0; line-height:1.2;">
                                <?= $filledCount > 0 ? number_format($avgScore, 2) : '—' ?>
                            </p>
                            <small style="color:var(--text-muted); display:block; margin-top:2px;">
                                Terisi <strong><?= $filledCount ?> dari <?= $totalComponents ?></strong> komponen wajib (Proses Penilaian)
                            </small>
                        <?php else: ?>
                            <p style="font-size:32px; font-weight:800; color:<?= $statusLulus === 'lulus' ? '#16a34a' : '#dc2626' ?>; margin:2px 0 0 0; line-height:1.2;">
                                <?= number_format($avgScore, 2) ?>
                            </p>
                            <small style="color:var(--text-muted); display:block; margin-top:2px;">
                                Predikat: <strong><?= $predikat ?></strong> | Total: <strong><?= number_format($sum, 1) ?> ÷ <?= $totalComponents ?> komponen wajib</strong>
                            </small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong style="color:var(--text-muted); font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">Status Kelulusan</strong>
                        <p style="margin:6px 0 0 0;">
                            <?php if ($statusLulus === 'lulus'): ?>
                                <span class="badge badge-success" style="font-size:15px; padding:8px 18px; font-weight:700;">✅ LULUS</span>
                            <?php elseif ($statusLulus === 'menunggu'): ?>
                                <span class="badge" style="background:#e0f2fe; color:#0369a1; border:1px solid #7dd3fc; font-size:13.5px; padding:8px 16px; font-weight:700;">⏳ MENUNGGU PENILAIAN</span>
                            <?php else: ?>
                                <span class="badge badge-danger" style="font-size:15px; padding:8px 18px; font-weight:700;">❌ TIDAK LULUS</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- ================================================================ -->
            <!-- PENGUMUMAN & PANDUAN TINDAK LANJUT HASIL PRETES (MODERN UI)      -->
            <!-- ================================================================ -->
            <?php if ($statusLulus === 'lulus'): ?>
                <div class="pretes-ann-box lulus">
                    <div class="pretes-ann-header">
                        <span class="pretes-ann-icon-bubble" style="background:#dcfce7; color:#15803d;">🎉</span>
                        <div style="flex:1;">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                                <h3 class="pretes-ann-title" style="color:#065f46;">
                                    Selamat, Anda Dinyatakan LULUS Pretes!
                                </h3>
                                <span class="badge badge-success" style="font-size:11.5px; padding:4px 10px;">
                                    Bebas Tutorial LPPAI
                                </span>
                            </div>
                            <p class="pretes-ann-subtitle" style="color:#047857;">
                                Seluruh 12 komponen penilaian pretes Anda telah terpenuhi dengan nilai masing-masing minimal <strong>80</strong> (Rata-rata Keseluruhan: <strong><?= number_format($avgScore, 2) ?></strong> | Predikat: <strong><?= $predikat ?></strong>). Anda secara resmi dibebaskan dari kewajiban mengikuti program Tutorial LPPAI.
                            </p>
                        </div>
                    </div>

                    <!-- Header Panduan Langkah -->
                    <div style="margin-top:14px; margin-bottom:12px;">
                        <div style="display:inline-flex; align-items:center; gap:6px; background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; font-size:12px; font-weight:700; padding:4px 12px; border-radius:20px; text-transform:uppercase; letter-spacing:0.5px;">
                            📢 4 Tahapan Tindak Lanjut & Pengambilan Sertifikat
                        </div>
                        <p style="margin:8px 0 0 0; font-size:13.5px; color:#374151;">
                            Untuk keperluan pencetakan dan legalisasi <strong>Sertifikat Kelulusan Pretes</strong>, silakan selesaikan tahapan berikut secara berurutan:
                        </p>
                    </div>

                    <!-- 4 Card Grid Responsive -->
                    <div class="pretes-step-grid">
                        <!-- Langkah 1 -->
                        <div class="pretes-step-card">
                            <div class="pretes-step-card-header">
                                <span class="pretes-step-icon">🕌</span>
                                <span class="pretes-step-badge" style="background:#e0f2fe; color:#0369a1;">Langkah 1</span>
                            </div>
                            <h4 class="pretes-step-title">Majlis Dzikir Al Khidmah</h4>
                            <p class="pretes-step-desc">
                                Menghadiri majlis dzikir rutin di <strong>Masjid KH. Soefyan AW</strong> yang diselenggarakan pada <strong>setiap awal bulan</strong>.
                            </p>
                            <div class="pretes-step-footer">
                                <span>📅</span>
                                <span>Waktu: Jumat awal bulan</span>
                            </div>
                        </div>

                        <!-- Langkah 2 -->
                        <div class="pretes-step-card">
                            <div class="pretes-step-card-header">
                                <span class="pretes-step-icon">📱</span>
                                <span class="pretes-step-badge" style="background:#fef3c7; color:#92400e;">Langkah 2</span>
                            </div>
                            <h4 class="pretes-step-title">Presensi Digital & Swafoto</h4>
                            <p class="pretes-step-desc">
                                Melakukan absensi kehadiran & kepulangan secara online melalui sistem dengan mengunggah <strong>swafoto (selfie) yang jelas</strong>.
                            </p>
                            <div class="pretes-step-footer">
                                <span>📸</span>
                                <span>Unggah swafoto datang & pulang</span>
                            </div>
                        </div>

                        <!-- Langkah 3 -->
                        <div class="pretes-step-card">
                            <div class="pretes-step-card-header">
                                <span class="pretes-step-icon">💳</span>
                                <span class="pretes-step-badge" style="background:#dcfce7; color:#15803d;">Langkah 3</span>
                            </div>
                            <h4 class="pretes-step-title">Administrasi Pretes</h4>
                            <p class="pretes-step-desc">
                                Menyelesaikan pembayaran biaya administrasi kelulusan pretes sebesar <strong style="color:#16a34a;">Rp 50.000,-</strong> melalui kanal resmi.
                            </p>
                            <div class="pretes-step-footer">
                                <span>🧾</span>
                                <span>Simpan nota/bukti pembayaran</span>
                            </div>
                        </div>

                        <!-- Langkah 4 -->
                        <div class="pretes-step-card">
                            <div class="pretes-step-card-header">
                                <span class="pretes-step-icon">🎓</span>
                                <span class="pretes-step-badge" style="background:#fae8ff; color:#86198f;">Langkah 4</span>
                            </div>
                            <h4 class="pretes-step-title">Pengambilan Sertifikat</h4>
                            <p class="pretes-step-desc">
                                Mengambil sertifikat fisik di <strong>Kantor LPPAI (Lt. 2 Gedung Fakultas Hukum, Timur Kantin)</strong> dengan membawa <strong>Nota Pembayaran</strong> & <strong>Pasfoto 3×4</strong>.
                            </p>
                            <div class="pretes-step-footer">
                                <span>📍</span>
                                <span>Kantor LPPAI Lt. 2 Fak. Hukum</span>
                            </div>
                        </div>
                    </div>

                    <!-- Footer Callout Note -->
                    <div class="pretes-ann-callout">
                        <span style="font-size:20px; flex-shrink:0;">💡</span>
                        <div>
                            <strong>Catatan Penting:</strong> Pastikan Anda telah menyelesaikan presensi Al Khidmah serta membawa dokumen fisik (nota pembayaran sah dan pasfoto 3×4) saat mendatangi kantor LPPAI agar penyerahan sertifikat dapat langsung diproses oleh petugas.
                        </div>
                    </div>
                </div>

            <?php elseif ($statusLulus === 'menunggu'): ?>
                <div class="pretes-ann-box" style="border-color:#bae6fd; background:#f0f9ff;">
                    <div class="pretes-ann-header" style="border-bottom-color:#e0f2fe;">
                        <span class="pretes-ann-icon-bubble" style="background:#e0f2fe; color:#0284c7;">⏳</span>
                        <div style="flex:1;">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                                <h3 class="pretes-ann-title" style="color:#0369a1;">
                                    Pretes Periode Aktif Sedang Berlangsung
                                </h3>
                                <span class="badge" style="background:#0284c7; color:#ffffff; font-size:11.5px; padding:4px 10px;">
                                    Penilaian: <?= $filledCount ?>/<?= $totalComponents ?> Terisi
                                </span>
                            </div>
                            <p class="pretes-ann-subtitle" style="color:#075985;">
                                Anda terdaftar pada periode pretes aktif <strong><?= sanitize($periodeDisplay) ?></strong>. Pelaksanaan ujian sedang berlangsung dan dosen penguji sedang melakukan proses penginputan nilai. Hasil akhir kelulusan resmi akan diumumkan setelah seluruh 12 komponen ujian selesai dinilai.
                            </p>
                        </div>
                    </div>

                    <!-- Kotak Informasi Jadwal & Halaman Ujian -->
                    <div style="background:#ffffff; border:1px solid #bae6fd; border-radius:12px; padding:18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                        <div style="max-width:640px;">
                            <div style="display:inline-flex; align-items:center; gap:6px; background:#e0f2fe; color:#0284c7; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:20px; margin-bottom:8px; text-transform:uppercase;">
                                📅 Informasi Pelaksanaan Ujian Anda
                            </div>
                            <h4 style="margin:0 0 6px 0; font-size:15px; font-weight:800; color:#0f172a;">
                                <?= sanitize($myReg['hari_pilihan'] ?: 'Jadwal Reguler') ?> <?= !empty($myReg['jam']) ? ' - Pukul ' . sanitize($myReg['jam']) : '' ?>
                            </h4>
                            <p style="margin:0; font-size:13.5px; color:#475569; line-height:1.55;">
                                Ruangan: <strong><?= sanitize($myReg['ruangan'] ?: 'Menunggu pembagian') ?></strong> | 
                                Dosen Penguji: <strong><?= sanitize($myReg['dosen_penguji'] ?: 'Menunggu pembagian') ?></strong>.
                                Pastikan Anda membaca instruksi resmi ujian dan mempersiapkan diri dengan baik.
                            </p>
                        </div>
                        <div>
                            <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px; text-decoration:none; padding:12px 20px; font-weight:700; font-size:13.5px; border-radius:10px; white-space:nowrap;">
                                <span>🚀</span>
                                <span>Buka Halaman Jadwal & Ujian Pretes &rarr;</span>
                            </a>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="pretes-ann-box tidak-lulus">
                    <div class="pretes-ann-header" style="border-bottom-color:#fee2e2;">
                        <span class="pretes-ann-icon-bubble" style="background:#ffe4e6; color:#e11d48;">⚠️</span>
                        <div style="flex:1;">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                                <h3 class="pretes-ann-title" style="color:#9f1239;">
                                    Pemberitahuan: Anda Belum Memenuhi Standar Kelulusan Pretes
                                </h3>
                                <span class="badge badge-danger" style="font-size:11.5px; padding:4px 10px;">
                                    Wajib Ikut Tutorial
                                </span>
                            </div>
                            <p class="pretes-ann-subtitle" style="color:#881337;">
                                Berdasarkan evaluasi akhir, Anda memperoleh nilai rata-rata keseluruhan <strong><?= number_format($avgScore, 2) ?></strong> (terpenuhi <strong><?= $filledCount ?> dari <?= $totalComponents ?></strong> komponen wajib). Sesuai standar akademik LPPAI, kelulusan mensyaratkan seluruh 12 komponen bernilai minimal <strong>80</strong>.
                            </p>
                        </div>
                    </div>

                    <!-- Kotak Tindak Lanjut Pendaftaran Tutorial -->
                    <div style="background:#fff1f2; border:1.5px solid #fecdd3; border-radius:12px; padding:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                        <div style="max-width:640px;">
                            <div style="display:inline-flex; align-items:center; gap:6px; background:#fee2e2; color:#b91c1c; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:20px; margin-bottom:8px; text-transform:uppercase;">
                                📌 Tindak Lanjut Wajib Mahasiswa
                            </div>
                            <h4 style="margin:0 0 6px 0; font-size:16px; font-weight:800; color:#881337;">
                                Pendaftaran Program Pembinaan Tutorial LPPAI Gelombang 1
                            </h4>
                            <p style="margin:0; font-size:13.5px; color:#4c0519; line-height:1.55;">
                                Bagi mahasiswa yang dinyatakan tidak lulus pretes, Anda <strong>diwajibkan untuk mendaftarkan diri pada program Tutorial Gelombang 1</strong> guna mengikuti pembinaan materi agama Islam dan memenuhi syarat kelulusan sertifikasi LPPAI.
                            </p>
                        </div>
                        <div>
                            <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="btn btn-danger" style="display:inline-flex; align-items:center; gap:8px; text-decoration:none; padding:12px 22px; font-weight:700; font-size:14px; border-radius:10px; box-shadow:0 4px 14px rgba(225,29,72,0.25); white-space:nowrap;">
                                <span>📝</span>
                                <span>Daftar Tutorial Gelombang 1 Sekarang ➔</span>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tabel Rincian Nilai Keseluruhan (12 Komponen) -->
            <div style="margin-top:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                    <h3 style="margin:0; font-size:16px; font-weight:700; color:#1e293b;">
                        📋 Rincian Nilai Keseluruhan (12 Komponen Pretes Wajib)
                    </h3>
                    <small style="color:var(--text-muted); font-size:12.5px;">
                        📌 Standar Lulus: Seluruh 12 komponen wajib bernilai minimal <strong>80</strong>
                    </small>
                </div>
                
                <div class="table-responsive">
                    <table style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                                <th style="width:45px; text-align:center; padding:10px;">No</th>
                                <th style="padding:10px;">Materi / Komponen Ujian</th>
                                <th style="width:170px; text-align:center; padding:10px;">Bentuk Ujian</th>
                                <th style="width:120px; text-align:center; padding:10px;">Standar Min.</th>
                                <th style="width:120px; text-align:center; padding:10px;">Nilai Anda</th>
                                <th style="width:170px; text-align:center; padding:10px;">Status Komponen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = 1;
                            foreach ($componentDetails as $ck => $detail): 
                            ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td align="center" style="padding:10px; font-weight:600; color:#64748b;"><?= $no++ ?></td>
                                <td style="padding:10px; font-weight:600; color:#1e293b;">
                                    <?= sanitize($detail['label']) ?>
                                </td>
                                <td align="center" style="padding:10px;">
                                    <?php if ($detail['is_cbt']): ?>
                                        <span class="badge" style="background:#e0e7ff; color:#3730a3; font-size:11.5px; font-weight:600;">💻 CBT (Ujian Tulis)</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#f1f5f9; color:#475569; font-size:11.5px; font-weight:600;">🗣️ Lisan / Praktik</span>
                                    <?php endif; ?>
                                </td>
                                <td align="center" style="padding:10px; font-weight:600; color:#475569;">
                                    80
                                </td>
                                <td align="center" style="padding:10px;">
                                    <?php if ($detail['value'] !== null): ?>
                                        <strong style="font-size:15px; color: <?= $detail['is_good'] ? '#16a34a' : '#dc2626' ?>;">
                                            <?= number_format($detail['value'], 1) ?>
                                        </strong>
                                    <?php else: ?>
                                        <?php if ($statusLulus === 'menunggu'): ?>
                                            <span style="color:#94a3b8; font-size:13px; font-weight:600;">—</span>
                                            <small style="color:#94a3b8; display:block; font-size:11px;">(Belum Diuji)</small>
                                        <?php else: ?>
                                            <strong style="font-size:14px; color:#dc2626;">0.0</strong>
                                            <small style="color:#94a3b8; display:block; font-size:11px;">(Kosong)</small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td align="center" style="padding:10px;">
                                    <?php if ($detail['is_good']): ?>
                                        <span class="badge badge-success" style="font-size:11.5px;">✅ Memenuhi (≥80)</span>
                                    <?php elseif ($detail['value'] !== null): ?>
                                        <span class="badge badge-danger" style="font-size:11.5px;">❌ Tidak Memenuhi (&lt;80)</span>
                                    <?php else: ?>
                                        <?php if ($statusLulus === 'menunggu'): ?>
                                            <span class="badge" style="background:#e0f2fe; color:#0369a1; font-size:11.5px;">⏳ Menunggu Pengujian</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger" style="font-size:11.5px;">❌ Tidak Terpenuhi (0)</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:#f8fafc; border-top:2px solid #e2e8f0; font-weight:700;">
                                <td colspan="4" style="text-align:right; padding:14px; font-size:14px; color:#1e293b;">
                                    RATA-RATA NILAI KESELURUHAN (Total Nilai: <?= number_format($sum, 1) ?> ÷ <?= $totalComponents ?> Komponen):
                                </td>
                                <td align="center" style="padding:14px; font-size:18px; color:<?= $statusLulus === 'lulus' ? '#16a34a' : ($statusLulus === 'menunggu' ? '#0284c7' : '#dc2626') ?>; font-weight:800;">
                                    <?= ($statusLulus === 'menunggu' && $filledCount === 0) ? '—' : number_format($avgScore, 2) ?>
                                </td>
                                <td align="center" style="padding:14px;">
                                    <?php if ($statusLulus === 'lulus'): ?>
                                        <span class="badge badge-success" style="font-size:13px; padding:6px 14px;">✅ LULUS</span>
                                    <?php elseif ($statusLulus === 'menunggu'): ?>
                                        <span class="badge" style="background:#e0f2fe; color:#0369a1; border:1px solid #7dd3fc; font-size:12.5px; padding:6px 14px;">⏳ MENUNGGU PENILAIAN</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger" style="font-size:13px; padding:6px 14px;">❌ TIDAK LULUS</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Tombol Navigasi -->
            <div style="margin-top:24px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary" style="text-decoration:none;">🏠 Kembali ke Dashboard</a>
                <?php if ($statusLulus === 'lulus'): ?>
                    <a href="<?= BASE_URL ?>/absensi-alkhidmah.php" class="btn btn-outline-success" style="text-decoration:none;">🕌 Presensi Al Khidmah</a>
                    <a href="<?= BASE_URL ?>/ambil-sertifikat.php" class="btn btn-success" style="text-decoration:none;">📷 Ambil Sertifikat</a>
                <?php elseif ($statusLulus === 'menunggu'): ?>
                    <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-primary" style="text-decoration:none;">📅 Buka Halaman Jadwal & Ujian Pretes</a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="btn btn-danger" style="text-decoration:none;">📝 Daftar Program Tutorial Gelombang 1</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php if (isAdmin() || isDosen()): ?>
<!-- All Results (Hanya untuk Admin / Dosen) -->
<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <span style="font-weight:700;">📋 Daftar Hasil Pretes (Semua Peserta)</span>
        <?php if (!empty($allPeriods)): ?>
        <form method="GET" action="" style="display:inline-flex; align-items:center; gap:8px; margin:0;">
            <label for="filter_periode" style="font-size:12.5px; font-weight:600; color:#475569; margin:0;">Filter Periode:</label>
            <select name="periode" id="filter_periode" onchange="this.form.submit()" style="padding:6px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:12.5px; background:#fff; font-weight:600; cursor:pointer;">
                <option value="semua" <?= $filterPeriode === 'semua' ? 'selected' : '' ?>>Semua Periode</option>
                <?php foreach ($allPeriods as $p): ?>
                    <option value="<?= htmlspecialchars($p['periode']) ?>" <?= $filterPeriode === $p['periode'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['nama_pretes'] ?: $p['periode']) ?> (<?= $p['status'] === 'aktif' ? '🟢 Aktif' : '⚪ Selesai' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($allResults)): ?>
            <div class="empty-state">
                <div class="icon">📋</div>
                <h3>Hasil pretes belum diumumkan</h3>
                <p>Silakan pilih periode lain atau cek kembali nanti.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>NIM</th>
                        <th>Program Studi</th>
                        <th>Periode Pretes</th>
                        <th>Nilai</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allResults as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= sanitize($r['nama_lengkap']) ?></td>
                        <td><?= sanitize($r['nim']) ?></td>
                        <td><?= formatProdiDisplay($r['program_studi'] ?? '', $r['jenis_program'] ?? 'Reguler', true) ?></td>
                        <td>
                            <span style="font-weight:600; font-size:12.5px;"><?= sanitize($r['schedule_periode'] ?: $r['nama_pretes']) ?></span>
                            <?php if (($r['periode_status'] ?? '') === 'aktif'): ?>
                                <span class="badge badge-success" style="font-size:10px; padding:2px 6px; margin-left:4px;">🟢 Aktif</span>
                            <?php else: ?>
                                <span class="badge" style="background:#f1f5f9; color:#64748b; font-size:10px; padding:2px 6px; margin-left:4px;">⚪ Selesai</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= $r['nilai'] !== null ? number_format($r['nilai'], 2) : '-' ?></strong></td>
                        <td>
                            <?php if ($r['status_lulus'] === 'lulus'): ?>
                                <span class="badge badge-success">Lulus</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Tidak Lulus</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
