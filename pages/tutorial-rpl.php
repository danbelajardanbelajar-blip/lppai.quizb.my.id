<?php
define('PAGE_TITLE', 'Tutorial RPL Online');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/tutorial-rpl-periods.php';
if (file_exists(__DIR__ . '/../includes/tutorial-rpl-helper.php')) {
    require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
}

$actor = getCurrentUser();
$pdo = getDBConnection();
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$membership = null;
$sessionCbt = null;
try {
    if (function_exists('ensureTutorialRplTables')) {
        ensureTutorialRplTables($pdo);
    }
    if (function_exists('ensureTutorialRplCbtTables')) {
        ensureTutorialRplCbtTables($pdo);
    }
    if (function_exists('tutorialRplGetStudentMembership')) {
        $membership = tutorialRplGetStudentMembership($pdo, (int)($actor['id'] ?? 0));
    }
    if (function_exists('tutorialRplCbtGetSession')) {
        $sessionCbt = tutorialRplCbtGetSession($pdo);
    }
} catch (Throwable $e) {
    // Abaikan error skema bila dijalankan pada fixture uji terisolasi
}

$notice = $_SESSION['rpl_student_hub_notice'] ?? '';
unset($_SESSION['rpl_student_hub_notice']);

// Handle Student Actions (Konfirmasi Unggah Tugas ke Drive)
$message = '';
$msgType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi formulir kedaluwarsa. Silakan muat ulang halaman.';
        $msgType = 'danger';
    } else {
        $action = trim($_POST['action'] ?? '');
        if ($action === 'confirm_drive' && $membership) {
            try {
                tutorialRplConfirmDriveSubmission($pdo, (int)$membership['id'], (int)$actor['id'], 1);
                $membership['drive_submitted'] = 1;
                $message = 'Konfirmasi pengunggahan tugas berhasil disimpan! Status tugas Anda kini menunggu verifikasi dosen tutor.';
                $msgType = 'success';
            } catch (Throwable $e) {
                $message = 'Gagal menyimpan konfirmasi: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

// Data Kebijakan & Periode Pendaftaran
$rplRegistrationPolicy = tutorialRplPeriodPolicy(null);
$unavailable = false;
try {
    $rplRegistrationPolicy = tutorialRplPeriodPolicy(tutorialRplActivePeriod($pdo));
} catch (Throwable $e) {
    error_log('RPL registration schedule: ' . $e->getMessage());
    $unavailable = true;
}
$status = tutorialRplRegistrationWindow(null, $rplRegistrationPolicy);
$labels = [
    'inactive' => 'Belum ada periode aktif',
    'soon' => 'Pendaftaran belum dibuka',
    'open' => 'Jadwal pendaftaran sedang berlangsung',
    'closed' => 'Periode pendaftaran telah berakhir'
];

include __DIR__ . '/../includes/header.php';
?>

<div class="tutorial-rpl-container" style="max-width:960px;margin:0 auto;">
    <!-- Hero Banner Utama -->
    <div style="background:linear-gradient(135deg,var(--primary,#0b3b22),var(--primary-light,#156138));color:#fff;border-radius:18px;padding:30px 26px;text-align:center;box-shadow:0 12px 30px rgba(11,59,34,.16);margin-bottom:20px;">
        <div style="font-size:38px;margin-bottom:6px;" aria-hidden="true">🌐</div>
        <h2 style="color:#fff;margin:0 0 8px;font-size:22px;font-weight:800;">Tutorial RPL Online</h2>
        <p id="tutorial-rpl-period" style="margin:0;line-height:1.7;font-size:14px;opacity:.95;">
            <?php if ($status !== 'inactive'): ?>
                <strong><?= $escape($rplRegistrationPolicy['nama']) ?> · <?= $escape($rplRegistrationPolicy['gelombang']) ?></strong><br>
                <?= $escape(tutorialRplPeriodSchedule($rplRegistrationPolicy)) ?>
            <?php else: ?>Jadwal akan ditampilkan setelah admin menetapkan periode aktif.<?php endif; ?>
        </p>
    </div>

    <?php if ($notice): ?>
        <div class="alert alert-success" role="status" style="margin-bottom:20px;"><?= $escape($notice) ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-<?= $msgType ?>" role="alert" style="margin-bottom:20px;"><?= $escape($message) ?></div>
    <?php endif; ?>

    <?php if ($membership): ?>
        <!-- ═══════════════════════════════════════════════════════════════
             RUANG KELAS & STATUS BIMBINGAN MAHASISWA RPL YANG TERPLOT
             ═══════════════════════════════════════════════════════════════ -->
        <?php
        $isHadirSah = (!empty($membership['drive_verified']) && !empty($membership['exam_submitted']));
        $statusLulusVal = strtolower(trim((string)($membership['status_lulus'] ?? 'proses')));
        ?>

        <?php if ($statusLulusVal === 'lulus'): ?>
            <!-- Banner Selebrasi Kelulusan Resmi -->
            <div class="card" style="margin-bottom:20px; background:linear-gradient(135deg,#f0fdf4 0%,#dcfce7 100%); border:2px solid #86efac; border-radius:14px; padding:22px; box-shadow:0 6px 20px rgba(22,163,74,0.12);">
                <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                    <div style="font-size:46px; line-height:1; flex-shrink:0;">🎉</div>
                    <div style="flex:1; min-width:240px;">
                        <h3 style="margin:0 0 6px; font-size:19px; font-weight:800; color:#14532d;">
                            Alhamdulillah! Anda Dinyatakan LULUS Tutorial RPL Online
                        </h3>
                        <p style="margin:0; font-size:14px; color:#166534; line-height:1.6;">
                            Selamat! Anda telah menyelesaikan seluruh rangkaian tugas praktik video dan ujian tulis CBT Tutorial RPL Online dengan predikat <strong>LULUS</strong> (Nilai Akhir: <strong><?= $membership['nilai_akhir'] !== null ? (float)$membership['nilai_akhir'] : '-' ?></strong>). Anda telah memenuhi seluruh syarat kelulusan dan tidak perlu melakukan pendaftaran ulang.
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-bottom:24px; border-left:4px solid var(--primary);">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <span style="font-weight:700; color:var(--text-dark);">
                    🏫 Kelompok Bimbingan: <?= $escape($membership['nama_kelompok']) ?>
                </span>
                <span class="badge <?= $isHadirSah ? 'badge-success' : 'badge-warning' ?>" style="font-size:12px; padding:4px 10px;">
                    <?= $isHadirSah ? '✅ Hadir Sah &amp; Lengkap' : '⏳ Presensi Belum Lengkap' ?>
                </span>
            </div>
            <div class="card-body" style="padding:22px;">
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:18px; margin-bottom:20px;">
                    <div>
                        <div style="font-size:12px; font-weight:700; color:var(--text-muted, #64748b); text-transform:uppercase;">Periode &amp; Gelombang</div>
                        <div style="font-size:15px; font-weight:700; color:var(--text-dark); margin-top:2px;">
                            <?= $escape($membership['periode']) ?> · <?= $escape($membership['gelombang'] ?? 'Gelombang 1') ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size:12px; font-weight:700; color:var(--text-muted, #64748b); text-transform:uppercase;">Dosen Tutor Pengampu</div>
                        <div style="font-size:15px; font-weight:700; color:var(--text-dark); margin-top:2px;">
                            <?= $escape($membership['dosen_nama'] ?: 'Menunggu penugasan') ?>
                        </div>
                        <?php if (!empty($membership['dosen_wa'])): ?>
                            <a href="https://wa.me/<?= preg_replace('/\D/', '', $membership['dosen_wa']) ?>" target="_blank" rel="noopener noreferrer" style="font-size:12px; color:#15803d; text-decoration:none; display:inline-flex; align-items:center; gap:4px; margin-top:4px;">
                                <span>💬</span> Chat WhatsApp Tutor
                            </a>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div style="font-size:12px; font-weight:700; color:var(--text-muted, #64748b); text-transform:uppercase;">Nilai Akhir Tutorial</div>
                        <div style="font-size:15px; font-weight:700; color:var(--text-dark); margin-top:2px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <span><?= $membership['nilai_akhir'] !== null ? (float)$membership['nilai_akhir'] : '-' ?></span>
                            <?php if ($statusLulusVal === 'lulus'): ?>
                                <span class="badge badge-success" style="font-size:12px; padding:3px 10px; font-weight:700;">✅ LULUS</span>
                            <?php elseif ($statusLulusVal === 'tidak_lulus'): ?>
                                <span class="badge badge-danger" style="font-size:12px; padding:3px 10px; font-weight:700;">❌ TIDAK LULUS</span>
                            <?php else: ?>
                                <span class="badge badge-warning" style="font-size:12px; padding:3px 10px; font-weight:700;">⏳ PROSES</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($membership['class_catatan'])): ?>
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:20px; font-size:13px; color:#334155;">
                        <strong>📢 Informasi Tutor:</strong> <?= nl2br($escape($membership['class_catatan'])) ?>
                    </div>
                <?php endif; ?>

                <!-- Bagian 1: Tugas Video Hafalan di Google Drive -->
                <div style="border-top:1px solid #e2e8f0; padding-top:18px; margin-top:10px;">
                    <h4 style="margin:0 0 8px; font-size:16px; font-weight:700; color:var(--text-dark);">
                        📁 1. Tugas Rekaman Video Hafalan (Google Drive)
                    </h4>
                    <p style="font-size:13.5px; color:#475569; margin:0 0 14px; line-height:1.5;">
                        Unggah file rekaman video hafalan materi wajib (thaharah, shalat, dan amaliyah) ke dalam folder Google Drive kelompok di bawah ini.
                    </p>

                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:16px 20px; margin-bottom:14px;">
                        <div>
                            <div style="font-size:14px; font-weight:700; color:#166534;">Folder Pengumpulan Tugas Daring:</div>
                            <div style="font-size:12.5px; color:#15803d; margin-top:2px;">Gunakan format nama file: <code>NIM_NamaLengkap_Hafalan.mp4</code></div>
                        </div>
                        <div>
                            <?php if (!empty($membership['drive_url'])): ?>
                                <a href="<?= $escape($membership['drive_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px; font-weight:700;">
                                    <span>📂</span> Buka Google Drive Kelas ↗
                                </a>
                            <?php else: ?>
                                <span style="font-size:13px; color:#94a3b8; font-style:italic;">Folder Google Drive sedang disiapkan oleh admin</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Status Verifikasi & Umpan Balik -->
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                        <div>
                            <span style="font-size:13px; font-weight:600; color:#334155;">Status Verifikasi Tugas: </span>
                            <?php if (!empty($membership['drive_verified'])): ?>
                                <span class="badge badge-success" style="font-size:12px; padding:4px 10px;">✅ Terverifikasi Sah oleh Dosen</span>
                            <?php elseif (!empty($membership['drive_submitted'])): ?>
                                <span class="badge badge-info" style="font-size:12px; padding:4px 10px;">⏳ Menunggu Verifikasi Dosen</span>
                            <?php else: ?>
                                <span class="badge badge-warning" style="font-size:12px; padding:4px 10px;">Belum Dikonfirmasi Unggah</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <span style="font-size:13px; font-weight:600; color:#334155;">Nilai Praktik: </span>
                            <strong><?= $membership['nilai_praktik'] !== null ? (float)$membership['nilai_praktik'] : 'Belum dinilai' ?></strong>
                            <small style="color:#64748b;">(Passing grade: 80.0)</small>
                        </div>
                    </div>

                    <?php if (empty($membership['drive_verified']) && empty($membership['drive_submitted'])): ?>
                        <form method="POST" style="margin-top:10px;">
                            <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                            <input type="hidden" name="action" value="confirm_drive">
                            <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Pastikan Anda telah mengunggah video rekaman hafalan ke folder Google Drive kelompok sebelum konfirmasi. Lanjutkan?');" style="font-weight:700;">
                                ✓ Konfirmasi Saya Sudah Mengunggah Tugas ke Drive
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if (!empty($membership['drive_notes'])): ?>
                        <div style="margin-top:12px; padding:10px 14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; font-size:13px; color:#1e40af;">
                            📝 <strong>Catatan dari Tutor:</strong> <?= $escape($membership['drive_notes']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Bagian 2: Ujian Tulis Tutorial RPL (CBT Online) -->
                <div style="border-top:1px solid #e2e8f0; padding-top:18px; margin-top:20px;">
                    <h4 style="margin:0 0 8px; font-size:16px; font-weight:700; color:var(--text-dark);">
                        🧩 2. Ujian Tulis Tutorial RPL Online (CBT)
                    </h4>
                    <p style="font-size:13.5px; color:#475569; margin:0 0 14px; line-height:1.5;">
                        Ujian tertulis daring berbasis komputer dengan materi ubudiyah praktis (bobot 50% nilai akhir).
                    </p>

                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px 20px;">
                        <div>
                            <div style="font-size:14px; font-weight:700; color:var(--text-dark);">
                                Status Pengerjaan CBT:
                            </div>
                            <div style="font-size:12.5px; color:#64748b; margin-top:3px;">
                                <?php if (!empty($membership['exam_submitted'])): ?>
                                    ✅ Nilai CBT Anda: <strong><?= (float)$membership['nilai_ujian_tulis'] ?></strong> (Standar: <?= (float)($sessionCbt['passing_grade'] ?? 80.0) ?>)
                                <?php else: ?>
                                    Durasi: <?= (int)($sessionCbt['duration_minutes'] ?? 30) ?> Menit · Passing Grade: <?= (float)($sessionCbt['passing_grade'] ?? 80.0) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <?php if (!empty($membership['exam_submitted'])): ?>
                                <a href="<?= BASE_URL ?>/tutorial-rpl-cbt.php" class="btn btn-secondary btn-sm" style="font-weight:700;">
                                    📊 Lihat Lembar Nilai CBT
                                </a>
                            <?php elseif (!empty($sessionCbt['exam_open'])): ?>
                                <a href="<?= BASE_URL ?>/tutorial-rpl-cbt.php" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px; font-weight:700;">
                                    <span>🧩</span> Masuk Pengerjaan CBT ↗
                                </a>
                            <?php else: ?>
                                <span class="badge badge-secondary" style="font-size:12px; padding:6px 12px;">🔒 Gerbang CBT Sedang Ditutup</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Bagian 3: Kesimpulan Presensi Sah -->
                <div style="border-top:1px solid #e2e8f0; padding-top:18px; margin-top:20px;">
                    <?php if ($isHadirSah): ?>
                        <?php if ($statusLulusVal === 'tidak_lulus'): ?>
                            <div class="alert alert-warning" style="margin:0; font-size:13.5px; line-height:1.6;">
                                ⚠️ <strong>Presensi Sah &amp; Lengkap:</strong> Seluruh kewajiban tugas video Google Drive dan Ujian Tulis CBT telah Anda serahkan. Namun akumulasi Nilai Akhir Anda belum mencapai standar kelulusan (passing grade 80.0). Silakan hubungi admin LPPAI atau tutor bimbingan Anda terkait informasi pembukaan periode pendaftaran berikutnya.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success" style="margin:0; font-size:14px; line-height:1.6;">
                                🎉 <strong>Presensi Sah &amp; Lengkap:</strong> Seluruh kewajiban Tutorial RPL Online (Verifikasi Video Drive &amp; Ujian Tulis CBT) telah berhasil Anda penuhi.
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-warning" style="margin:0; font-size:13.5px; line-height:1.6;">
                            ⏳ <strong>Presensi Belum Lengkap:</strong> Untuk dinyatakan <em>Hadir Sah</em> pada Tutorial RPL Online, Anda wajib menyelesaikan kedua komponen: (1) Tugas video Google Drive terverifikasi sah oleh dosen, dan (2) Menyelesaikan Ujian Tulis CBT.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php $isLulusTutorialRpl = ($membership && ($statusLulusVal ?? '') === 'lulus'); ?>
    <?php if (!$isLulusTutorialRpl): ?>
        <!-- ═══════════════════════════════════════════════════════════════
             PANDUAN & STATUS PERIODE PENDAFTARAN (KOMPATIBILITAS PENUH)
             ═══════════════════════════════════════════════════════════════ -->
        <?php include __DIR__ . '/../includes/tutorial-rpl-registration-rules-view.php'; ?>

        <div class="card" style="margin-top:20px;">
            <div class="card-header">
                <span>Status Pendaftaran Tutorial RPL</span>
            </div>
            <div class="card-body">
                <h3 style="margin-top:0;font-size:17.5px;font-weight:700;color:var(--text-dark);"><?= $unavailable ? 'Jadwal belum dapat dimuat' : $escape($labels[$status]) ?></h3>
                <?php if ($unavailable): ?>
                    <p>Silakan coba kembali atau hubungi admin LPPAI.</p>
                <?php elseif ($status === 'open'): ?>
                    <p>Mahasiswa RPL yang belum pretes atau belum lulus dapat mendaftar. Kelulusan dan kuota diperiksa saat pendaftaran.</p>
                    <a class="btn btn-primary" href="<?= BASE_URL ?>/tutorial-rpl-daftar.php">Daftar Tutorial RPL</a>
                <?php elseif ($status === 'soon'): ?>
                    <p>Silakan kembali sesuai waktu buka yang tercantum di atas.</p>
                <?php else: ?>
                    <p>Silakan hubungi admin LPPAI untuk informasi pendaftaran berikutnya.</p>
                <?php endif; ?>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;">
                    <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-secondary">← Kembali ke Halaman Pretes</a>
                    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary">Dashboard</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;">
            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary">🏠 Kembali ke Dashboard</a>
            <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-secondary">📋 Halaman Pretes</a>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
