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
    // Cek pendaftaran tutorial RPL mahasiswa
    try {
        $stmtRegCheck = $pdo->prepare("SELECT r.*, p.nama AS period_nama, p.gelombang AS period_gelombang FROM tutorial_rpl_registrations r LEFT JOIN tutorial_rpl_registration_periods p ON p.id = r.period_id WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 1");
        $stmtRegCheck->execute([(int)($actor['id'] ?? 0)]);
        $existingRegistration = $stmtRegCheck->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        try {
            $stmtRegCheck = $pdo->prepare("SELECT * FROM tutorial_rpl_registrations WHERE user_id = ? ORDER BY id DESC LIMIT 1");
            $stmtRegCheck->execute([(int)($actor['id'] ?? 0)]);
            $existingRegistration = $stmtRegCheck->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e2) {
            $existingRegistration = null;
        }
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
        $statusLulusVal = strtolower(trim((string)($membership['status_lulus'] ?? 'proses')));
        $cbtScore = (!empty($membership['exam_submitted']) && $membership['nilai_ujian_tulis'] !== null)
            ? (float)$membership['nilai_ujian_tulis']
            : null;
        $isCbtUnder70 = ($cbtScore !== null && $cbtScore < 70.0);
        $isCbtPass70  = ($cbtScore !== null && $cbtScore >= 70.0);
        $hasVideoVerified = !empty($membership['drive_verified']);
        $hasPraktikScore = ($membership['nilai_praktik'] !== null && (float)$membership['nilai_praktik'] >= 70.0);
        $isTugasTerpenuhi = ($statusLulusVal === 'lulus') || $isCbtPass70 || $hasVideoVerified || $hasPraktikScore;
        $isHadirSah = $isTugasTerpenuhi;
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
                            Selamat! Anda telah menyelesaikan pemenuhan kelulusan Tutorial RPL Online dengan predikat <strong>LULUS</strong> (Nilai Akhir: <strong><?= $membership['nilai_akhir'] !== null ? (float)$membership['nilai_akhir'] : '-' ?></strong>). Anda telah memenuhi seluruh syarat kelulusan dan tidak perlu melakukan pendaftaran ulang.
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
                <span class="badge <?= $isTugasTerpenuhi ? 'badge-success' : 'badge-warning' ?>" style="font-size:12px; padding:4px 10px;">
                    <?= $isTugasTerpenuhi ? '✅ Hadir Sah &amp; Lengkap' : '⏳ Tugas Belum Lengkap' ?>
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

                <?php if ($statusLulusVal === 'lulus'): ?>
                    <!-- Rincian Nilai Resmi yang Telah Memenuhi Kelulusan -->
                    <div style="border-top:1px solid #e2e8f0; padding-top:18px; margin-top:10px;">
                        <h4 style="margin:0 0 12px; font-size:15px; font-weight:700; color:var(--text-dark);">
                            📊 Rincian Komponen Nilai Kelulusan Tutorial RPL:
                        </h4>
                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                                <div style="font-size:12px; color:#64748b;">Evaluasi CBT:</div>
                                <div style="font-size:18px; font-weight:800; color:#0f172a; margin-top:2px;">
                                    <?= $membership['nilai_ujian_tulis'] !== null ? (float)$membership['nilai_ujian_tulis'] : '-' ?>
                                </div>
                            </div>
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                                <div style="font-size:12px; color:#64748b;">Ujian Praktik (Tatap Muka / Drive):</div>
                                <div style="font-size:18px; font-weight:800; color:#0f172a; margin-top:2px;">
                                    <?= $membership['nilai_praktik'] !== null ? (float)$membership['nilai_praktik'] : '-' ?>
                                </div>
                            </div>
                            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px;">
                                <div style="font-size:12px; color:#166534; font-weight:700;">Nilai Akhir:</div>
                                <div style="font-size:18px; font-weight:800; color:#15803d; margin-top:2px;">
                                    <?= $membership['nilai_akhir'] !== null ? (float)$membership['nilai_akhir'] : '-' ?> (LULUS)
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- ═══════════════════════════════════════════════════════════════
                         PANEL 3 PILIHAN TUGAS PEMENUHAN KELULUSAN TUTORIAL RPL
                         (TAMPIL BAGI YANG BELUM LULUS / PROSES / TIDAK LULUS)
                         ═══════════════════════════════════════════════════════════════ -->
                    <div style="border-top:1px solid #e2e8f0; padding-top:18px; margin-top:10px;">
                        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                            <h4 style="margin:0; font-size:16.5px; font-weight:800; color:var(--text-dark); display:flex; align-items:center; gap:8px;">
                                <span>📋</span>
                                <span>Pilihan Tugas Pemenuhan Kelulusan Tutorial RPL</span>
                            </h4>
                            <span class="badge badge-info" style="font-size:12px; padding:5px 12px; font-weight:700;">
                                3 Opsi Pilihan Bebas
                            </span>
                        </div>

                        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:12px 16px; margin-bottom:18px;">
                            <p style="margin:0; font-size:13.5px; color:#166534; line-height:1.6;">
                                <strong>📢 Keterangan Kebijakan:</strong> 3 (tiga) tugas di bawah ini <strong>bersifat pilihan untuk mempermudah mahasiswa RPL</strong>. Anda boleh memilih salah satu opsi tugas yang paling sesuai guna memenuhi kelulusan Tutorial RPL:
                            </p>
                        </div>

                        <!-- 3 KARTU OPSI PILIHAN TUGAS -->
                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:16px; margin-bottom:20px;">

                            <!-- OPSI A: MENGERJAKAN SOAL CBT MINIMAL 70 -->
                            <div style="background:<?= $isCbtUnder70 ? '#fff7ed' : '#f8fafc' ?>; border:2px solid <?= $isCbtUnder70 ? '#f97316' : ($isCbtPass70 ? '#86efac' : '#cbd5e1') ?>; border-radius:12px; padding:18px; display:flex; flex-direction:column; justify-content:space-between;">
                                <div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                        <span style="font-size:11.5px; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:0.5px;">Opsi A</span>
                                        <?php if ($isCbtPass70): ?>
                                            <span class="badge badge-success" style="font-size:11px;">✅ Memenuhi (≥70)</span>
                                        <?php elseif ($isCbtUnder70): ?>
                                            <span class="badge badge-danger" style="font-size:11px;">❌ Di Bawah 70</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary" style="font-size:11px;">Pilihan Mandiri</span>
                                        <?php endif; ?>
                                    </div>
                                    <h4 style="margin:0 0 8px; font-size:15.5px; font-weight:800; color:#0f172a;">
                                        🧩 Mengerjakan Soal CBT (Min. 70)
                                    </h4>
                                    <p style="margin:0 0 12px; font-size:13px; color:#475569; line-height:1.55;">
                                        Mengerjakan evaluasi tertulis CBT Tutorial RPL secara daring dengan capaian nilai minimal <strong>70.0</strong>.
                                    </p>

                                    <?php if ($isCbtUnder70): ?>
                                        <div style="background:#fee2e2; border:1px solid #fca5a5; border-radius:8px; padding:10px 12px; font-size:12px; color:#991b1b; line-height:1.5; margin-bottom:12px;">
                                            <strong>⚠️ Nilai CBT Anda: <?= $cbtScore ?></strong><br>
                                            Jika nilai CBT masih di bawah 70, maka <strong>tidak ada pilihan lain selain ujian praktik</strong> (silakan pilih Opsi B tatap muka atau Opsi C rekaman video Google Drive).
                                        </div>
                                    <?php elseif ($isCbtPass70): ?>
                                        <div style="background:#dcfce7; border:1px solid #86efac; border-radius:8px; padding:10px 12px; font-size:12px; color:#166534; line-height:1.5; margin-bottom:12px;">
                                            <strong>✅ Nilai CBT Anda: <?= $cbtScore ?></strong><br>
                                            Nilai ujian tulis CBT Anda telah memenuhi standar minimal (≥ 70.0).
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:12px;">
                                            Durasi: <?= (int)($sessionCbt['duration_minutes'] ?? 30) ?> Menit · Syarat Kelulusan: Nilai ≥ 70.0
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div style="margin-top:12px;">
                                    <?php if (!empty($membership['exam_submitted'])): ?>
                                        <a href="<?= BASE_URL ?>/tutorial-rpl-cbt.php" class="btn btn-secondary btn-sm" style="width:100%; text-align:center; padding:9px; font-weight:700; font-size:13px; display:inline-block; text-decoration:none;">
                                            📊 Lihat Lembar Nilai CBT
                                        </a>
                                    <?php elseif (!empty($sessionCbt['exam_open'])): ?>
                                        <a href="<?= BASE_URL ?>/tutorial-rpl-cbt.php" class="btn btn-primary" style="width:100%; text-align:center; padding:9px; font-weight:700; font-size:13px; display:inline-flex; align-items:center; justify-content:center; gap:6px; text-decoration:none;">
                                            <span>🚀</span> Masuk Pengerjaan CBT ↗
                                        </a>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" style="display:block; text-align:center; padding:8px; font-size:12px;">🔒 Gerbang CBT Sedang Ditutup</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- OPSI B: UJIAN 6 POIN TUGAS DI DEPAN DOSEN (OFFLINE) -->
                            <div style="background:<?= $isCbtUnder70 ? '#f0fdf4' : '#f8fafc' ?>; border:2px solid <?= $isCbtUnder70 ? '#16a34a' : '#cbd5e1' ?>; border-radius:12px; padding:18px; display:flex; flex-direction:column; justify-content:space-between;">
                                <div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                        <span style="font-size:11.5px; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:0.5px;">Opsi B</span>
                                        <span class="badge badge-info" style="font-size:11px;">Tatap Muka (Offline)</span>
                                    </div>
                                    <h4 style="margin:0 0 8px; font-size:15.5px; font-weight:800; color:#0f172a;">
                                        👨‍🏫 Ujian Praktik di Depan Dosen
                                    </h4>
                                    <p style="margin:0 0 12px; font-size:13px; color:#475569; line-height:1.55;">
                                        Menemui dosen tutor bimbingan untuk melaksanakan evaluasi <strong>6 Poin Tugas Ibadah</strong> langsung di depan dosen pengampu.
                                    </p>
                                    <div style="font-size:12.5px; color:#334155; margin-bottom:10px;">
                                        Dosen Pengampu: <strong><?= $escape($membership['dosen_nama'] ?: 'Menunggu penugasan') ?></strong><br>
                                        Nilai Praktik: <strong><?= $membership['nilai_praktik'] !== null ? (float)$membership['nilai_praktik'] : 'Belum dinilai' ?></strong>
                                    </div>
                                </div>

                                <div style="margin-top:12px;">
                                    <?php if (!empty($membership['dosen_wa'])): ?>
                                        <a href="https://wa.me/<?= preg_replace('/\D/', '', $membership['dosen_wa']) ?>?text=<?= rawurlencode('Assalamu\'alaikum Bapak/Ibu Dosen ' . ($membership['dosen_nama'] ?? '') . ', saya mahasiswa RPL ' . ($actor['nama_lengkap'] ?? '') . ' (NIM: ' . ($actor['nim'] ?? '') . ') ingin berkonsultasi mengenai jadwal ujian tatap muka 6 poin tugas ibadah Tutorial RPL.') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-success" style="width:100%; text-align:center; padding:9px; font-weight:700; font-size:13px; display:inline-flex; align-items:center; justify-content:center; gap:6px; text-decoration:none;">
                                            <span>💬</span> Chat WhatsApp Dosen
                                        </a>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-secondary" disabled style="width:100%; font-size:13px; padding:9px;">
                                            Kontak Dosen Belum Tersedia
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- OPSI C: UJIAN 6 POIN TUGAS REKAMAN VIDEO GOOGLE DRIVE (ONLINE) -->
                            <div style="background:<?= $isCbtUnder70 ? '#f0fdf4' : '#f8fafc' ?>; border:2px solid <?= $isCbtUnder70 ? '#16a34a' : '#cbd5e1' ?>; border-radius:12px; padding:18px; display:flex; flex-direction:column; justify-content:space-between;">
                                <div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                        <span style="font-size:11.5px; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:0.5px;">Opsi C</span>
                                        <span class="badge badge-info" style="font-size:11px;">Daring (Online)</span>
                                    </div>
                                    <h4 style="margin:0 0 8px; font-size:15.5px; font-weight:800; color:#0f172a;">
                                        📹 Rekaman Video di Google Drive
                                    </h4>
                                    <p style="margin:0 0 12px; font-size:13px; color:#475569; line-height:1.55;">
                                        Melakukan ujian praktik <strong>6 Poin Tugas Ibadah</strong> dengan cara direkam dan diupload ke folder Google Drive kelompok.
                                    </p>
                                    <div style="font-size:12.5px; color:#334155; margin-bottom:10px;">
                                        Status Video: <strong><?= !empty($membership['drive_verified']) ? '✅ Terverifikasi Sah oleh Dosen' : (!empty($membership['drive_submitted']) ? '⏳ Sudah Diunggah (Menunggu Verifikasi)' : 'Belum Dikonfirmasi Unggah') ?></strong>
                                    </div>
                                    <div style="font-size:12px; color:#15803d; margin-bottom:10px;">
                                        Format file: <code>NIM_NamaLengkap_Hafalan.mp4</code>
                                    </div>
                                </div>

                                <div style="margin-top:12px; display:flex; flex-direction:column; gap:8px;">
                                    <?php if (!empty($membership['drive_url'])): ?>
                                        <a href="<?= $escape($membership['drive_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="width:100%; text-align:center; padding:9px; font-weight:700; font-size:13px; display:inline-flex; align-items:center; justify-content:center; gap:6px; text-decoration:none;">
                                            <span>📁</span> Buka Folder Drive Kelas ↗
                                        </a>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-secondary" disabled style="width:100%; font-size:13px; padding:9px;">
                                            Folder Drive Sedang Disiapkan
                                        </button>
                                    <?php endif; ?>

                                    <?php if (empty($membership['drive_verified']) && empty($membership['drive_submitted'])): ?>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="confirm_drive">
                                            <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Pastikan Anda telah mengunggah video rekaman 6 poin tugas ke folder Google Drive kelompok sebelum konfirmasi. Lanjutkan?');" style="width:100%; font-weight:700; padding:8px;">
                                                ✓ Konfirmasi Saya Sudah Mengunggah Tugas ke Drive
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>

                        <!-- RINCIAN 6 POIN TUGAS PRAKTIK -->
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:18px 20px;">
                            <h4 style="margin:0 0 10px; font-size:14.5px; font-weight:800; color:#1e293b; display:flex; align-items:center; gap:8px;">
                                <span>📌</span>
                                <span>Rincian 6 Poin Tugas Ujian Praktik Ibadah (Opsi B &amp; C):</span>
                            </h4>
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:10px; margin-top:12px;">
                                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; font-size:13px; color:#334155;">
                                    <strong>1. Praktik Thaharah (Wudhu):</strong> Gerakan wudhu tertib &amp; doa setelah wudhu.
                                </div>
                                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; font-size:13px; color:#334155;">
                                    <strong>2. Praktik Tayammum:</strong> Syarat, niat, rukun mengusap muka/tangan, &amp; doa tayammum.
                                </div>
                                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; font-size:13px; color:#334155;">
                                    <strong>3. Niat Mandi Wajib &amp; Jamak Qashar:</strong> Niat mandi besar dan niat shalat jamak taqdim/takhir qashar.
                                </div>
                                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; font-size:13px; color:#334155;">
                                    <strong>4. Bacaan Shalat Subuh:</strong> Niat, bacaan shalat fardhu Subuh lengkap dengan doa Qunut.
                                </div>
                                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; font-size:13px; color:#334155;">
                                    <strong>5. Shalat Jenazah:</strong> Tata cara shalat jenazah (niat, 4 takbir, doa shalat jenazah, salam).
                                </div>
                                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; font-size:13px; color:#334155;">
                                    <strong>6. Amaliyah &amp; Hafalan:</strong> Surat pendek pilihan (Ad-Dhuha/Al-Alaq/Al-Bayyinah) &amp; bacaan Istighatsah.
                                </div>
                            </div>
                            <div style="margin-top:12px; font-size:12.5px; color:#64748b; line-height:1.5;">
                                <em>Catatan Tambahan:</em> Jika memilih <strong>Opsi B</strong>, ujian dilakukan langsung secara tatap muka di depan dosen tutor. Jika memilih <strong>Opsi C</strong>, mahasiswa merekam video praktik 6 poin tersebut dan mengunggahnya ke Google Drive kelompok bimbingan.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php
    // Mahasiswa dianggap sudah daftar jika sudah terplot ke kelompok bimbingan atau sudah melakukan pendaftaran
    $isSudahDaftarRpl = ($membership !== null) || (!empty($existingRegistration));
    ?>
    <?php if (!$isSudahDaftarRpl): ?>
        <!-- ═══════════════════════════════════════════════════════════════
             PANDUAN & STATUS PERIODE PENDAFTARAN (TAMPIL HANYA JIKA BELUM DAFTAR)
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
    <?php elseif (!$membership && !empty($existingRegistration)): ?>
        <!-- STATUS TERDAFTAR (MENUNGGU PEMBAGIAN KELAS PLOTTING OLEH ADMIN) -->
        <div class="card" style="margin-top:20px; border-left:4px solid var(--primary);">
            <div class="card-header">
                <span>Status Pendaftaran Tutorial RPL</span>
            </div>
            <div class="card-body">
                <h3 style="margin-top:0;font-size:17.5px;font-weight:700;color:var(--text-dark);">
                    Terdaftar di <?= $escape($existingRegistration['period_nama'] ?? $existingRegistration['nama'] ?? 'Tutorial RPL') ?>
                </h3>
                <p style="color:#15803d; font-weight:600; font-size:14px; margin-bottom:8px;">
                    ✅ Anda sudah terdaftar sejak <?= $escape($existingRegistration['registered_at'] ?? '-') ?> WIB. Hari pilihan: <strong><?= $escape($existingRegistration['hari_pilihan'] ?? 'Belum dipilih') ?></strong>.
                </p>
                <p style="color:#64748b; font-size:13.5px; line-height:1.6; margin-bottom:16px;">
                    Pendaftaran Anda telah tercatat pada sistem LPPAI. Saat ini Anda sedang menunggu proses plotting / penempatan kelompok bimbingan dan penugasan dosen tutor oleh administrator. Informasi ruang kelas dan dosen pembimbing akan otomatis muncul di halaman ini setelah proses plotting selesai.
                </p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
                    <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-secondary">📋 Halaman Pretes</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- MAHASISWA SUDAH MEMILIKI KELAS (BERSIHKAN DARI CARD PENDAFTARAN) -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;">
            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary">🏠 Kembali ke Dashboard</a>
            <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-secondary">📋 Halaman Pretes</a>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
