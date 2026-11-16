<?php
/**
 * LPPAI Corner - Absensi Tutorial (Modern Mobile Responsive View)
 */
define('PAGE_TITLE', 'Absensi Tutorial');
require_once __DIR__ . '/includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
} elseif (isDosen()) {
    header('Location: ' . BASE_URL . '/dosen/dashboard.php');
    exit;
}

$user = getCurrentUser();
$pdo = getDBConnection();

require_once __DIR__ . '/includes/pretes-helper.php';
require_once __DIR__ . '/includes/pretes-stage.php';
pretesStageGate($pdo, $user, 'Absensi tutorial hanya berlaku untuk mahasiswa yang sedang mengikuti kelas tutorial.');

// Ambil data pendaftaran tutorial dan kelas yang diikuti
$stmtReg = $pdo->prepare("
    SELECT tr.id as reg_id, tr.tutorial_class_id, tr.status, tr.hari_pilihan, tr.gelombang,
           tc.nama_kelas, tc.dosen_pengampu, tc.hari, tc.jam, tc.ruangan, tc.semester
    FROM tutorial_registrations tr
    LEFT JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
    WHERE tr.user_id = ?
    ORDER BY tr.id DESC
    LIMIT 1
");
$stmtReg->execute([$user['id']]);
$userTutorial = $stmtReg->fetch(PDO::FETCH_ASSOC);

// Ambil riwayat kehadiran 8 pertemuan
$attendanceHistory = [];
$hadirCount = 0;
if ($userTutorial && !empty($userTutorial['tutorial_class_id'])) {
    $stmtAtt = $pdo->prepare("
        SELECT pertemuan_ke, tanggal, status_hadir
        FROM tutorial_attendance
        WHERE tutorial_class_id = ? AND user_id = ?
        ORDER BY pertemuan_ke ASC
    ");
    $stmtAtt->execute([$userTutorial['tutorial_class_id'], $user['id']]);
    while ($row = $stmtAtt->fetch(PDO::FETCH_ASSOC)) {
        $attendanceHistory[(int)$row['pertemuan_ke']] = $row;
        if ($row['status_hadir'] === 'hadir') {
            $hadirCount++;
        }
    }
}
$persenHadir = round(($hadirCount / 8) * 100);

include __DIR__ . '/includes/header.php';
?>

<div style="max-width: 900px; margin: 0 auto;">
    <?php if (!$userTutorial || empty($userTutorial['tutorial_class_id'])): ?>
        <div class="card" style="text-align: center; padding: 48px 24px; border-radius: 16px;">
            <div style="font-size: 54px; margin-bottom: 16px;">📚</div>
            <h3 style="color: #0f172a; margin-bottom: 10px; font-size: 20px;">Kelas Tutorial Belum Ditetapkan</h3>
            <p style="color: #64748b; max-width: 500px; margin: 0 auto 24px auto; font-size: 14px; line-height: 1.6;">
                Anda saat ini belum terdaftar di rombongan belajar (rombel) kelas tutorial aktif. Presensi QR code dapat dilakukan setelah penempatan kelas oleh Dosen / Admin.
            </p>
            <div>
                <a href="<?= BASE_URL ?>/tutorial.php" class="btn btn-primary" style="padding: 10px 22px; font-weight: 600;">Lihat Data Tutorial Saya</a>
            </div>
        </div>
    <?php else: ?>
        <!-- Hero Informasi Kelas & Progress Kehadiran Mahasiswa -->
        <div class="attendance-hero-card">
            <div class="attendance-hero-title">
                <span>📖 <?= sanitize($userTutorial['nama_kelas'] ?? 'Kelas Tutorial') ?></span>
                <span class="attendance-class-badge">Peserta Aktif</span>
            </div>
            <div class="attendance-info-grid">
                <div class="attendance-info-item">
                    <span class="attendance-info-label">Dosen Pembimbing</span>
                    <strong><?= sanitize($userTutorial['dosen_pengampu'] ?? 'Belum ditentukan') ?></strong>
                </div>
                <div class="attendance-info-item">
                    <span class="attendance-info-label">Jadwal Tutorial</span>
                    <strong><?= sanitize($userTutorial['hari'] ?? $userTutorial['hari_pilihan']) ?>, <?= sanitize($userTutorial['jam'] ?? '-') ?></strong>
                </div>
                <div class="attendance-info-item">
                    <span class="attendance-info-label">Ruangan</span>
                    <strong><?= sanitize($userTutorial['ruangan'] ?? 'Ruang Kelas') ?></strong>
                </div>
                <div class="attendance-info-item">
                    <span class="attendance-info-label">Periode</span>
                    <strong><?= sanitize($userTutorial['semester'] ?? 'Semester Aktif') ?> (<?= sanitize(ucfirst($userTutorial['gelombang'] ?? 'Gel 1')) ?>)</strong>
                </div>
            </div>

            <div class="attendance-progress-box">
                <div class="attendance-progress-header">
                    <span>Progres Kehadiran Anda</span>
                    <span><strong><?= $hadirCount ?></strong> / 8 Pertemuan (<?= $persenHadir ?>%)</span>
                </div>
                <div class="attendance-progress-bar">
                    <div class="attendance-progress-fill" style="width: <?= $persenHadir ?>%;"></div>
                </div>
            </div>
        </div>

        <!-- 8-Pertemuan Status Tracker -->
        <div class="meetings-tracker-card">
            <div class="meetings-tracker-title">
                <span>📅 Jejak Presensi 8 Pertemuan</span>
                <span style="font-size: 12px; font-weight: 500; color: #64748b;">Target lulus: min. 75% (6x Hadir)</span>
            </div>
            <div class="meetings-badge-grid">
                <?php
                for ($p = 1; $p <= 8; $p++):
                    $item = $attendanceHistory[$p] ?? null;
                    $st = $item['status_hadir'] ?? 'belum';
                    $stClass = 'status-' . $st;
                    $stText = ucfirst($st);
                    if ($st === 'hadir') $stText = '✓ Hadir';
                    elseif ($st === 'absen') $stText = '✗ Alpa';
                    elseif ($st === 'izin') $stText = 'ℹ Izin';
                    elseif ($st === 'sakit') $stText = '✚ Sakit';
                    elseif ($st === 'belum') $stText = '○ Belum';
                ?>
                    <div class="meeting-badge-item <?= $stClass ?>">
                        <div class="p-label">P-<?= $p ?></div>
                        <div class="p-status"><?= $stText ?></div>
                        <?php if (!empty($item['tanggal'])): ?>
                            <div style="font-size: 10px; color: #64748b; margin-top: 3px;"><?= date('d/m', strtotime($item['tanggal'])) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Pemindai QR Scanner Modern Viewfinder -->
        <div class="scanner-card">
            <div class="scanner-card-header">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span>📷</span>
                    <span>Pemindai QR Presensi</span>
                </div>
                <div class="scanner-live-badge">Scanner Siap</div>
            </div>
            <div class="card-body" style="padding: 24px 20px;">
                <p style="text-align: center; color: #475569; font-size: 14px; margin: 0 0 16px 0;">
                    Arahkan kamera ke QR Code yang ditampilkan oleh dosen di proyektor atau layar HP dosen.
                </p>

                <div id="reader-container" class="scanner-viewfinder-wrapper">
                    <div id="reader"></div>
                    <div class="scanner-overlay-guide">
                        <div class="scanner-target-box">
                            <div class="corner-bracket tl"></div>
                            <div class="corner-bracket tr"></div>
                            <div class="corner-bracket bl"></div>
                            <div class="corner-bracket br"></div>
                            <div class="scanner-laser-sweep"></div>
                        </div>
                    </div>
                </div>

                <div id="result-container" style="display: none; text-align: center; margin-top: 20px; padding: 24px 20px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 14px;">
                    <div style="font-size: 44px; margin-bottom: 8px;">🎉</div>
                    <h3 style="color: #065f46; margin: 0 0 6px 0; font-size: 18px; font-weight: 700;">Presensi Berhasil Dicatat!</h3>
                    <div id="result-message" style="color: #047857; font-size: 14px; margin-bottom: 16px; font-weight: 500;"></div>
                    <button class="btn btn-primary" onclick="window.location.reload()" style="padding: 10px 24px; font-weight: 600;">Muat Ulang Halaman</button>
                </div>

                <div style="max-width: 480px; margin: 20px auto 0 auto; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px;">
                    <div style="font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 6px;">💡 Tips Pemindaian Cepat:</div>
                    <ul style="margin: 0; padding-left: 20px; font-size: 12px; color: #64748b; line-height: 1.6;">
                        <li>Pastikan browser telah diberi izin mengakses kamera ponsel.</li>
                        <li>Posisikan QR Code berada di dalam kotak garis pandu hijau.</li>
                        <li>Sistem otomatis membaca seketika dan memberikan notifikasi getar/sukses.</li>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    var isScanningActive = true;
    var html5QrcodeScanner = null;

    if (document.getElementById('reader')) {
        html5QrcodeScanner = new Html5QrcodeScanner(
            "reader",
            {
                fps: 10,
                qrbox: { width: 240, height: 240 },
                aspectRatio: 1.0,
                showTorchButtonIfSupported: true,
                showZoomSliderIfSupported: true
            },
            /* verbose= */ false
        );

        function onScanSuccess(decodedText, decodedResult) {
            if (!isScanningActive) return;
            isScanningActive = false;

            // Haptic vibration feedback for mobile devices
            if (navigator.vibrate) {
                try { navigator.vibrate([80, 40, 80]); } catch(e) {}
            }

            // Stop scanner
            try { html5QrcodeScanner.clear(); } catch(e) {}

            var container = document.getElementById('reader-container');
            if (container) container.style.display = 'none';

            Swal.fire({
                title: 'Merekam Kehadiran...',
                text: 'Sedang memverifikasi QR Code presensi Anda.',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            // Send token to server
            $.ajax({
                url: '<?= BASE_URL ?>/ajax-absensi-scan.php',
                type: 'POST',
                data: { token: decodedText },
                dataType: 'json',
                success: function(res) {
                    if (res.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Presensi Sukses!',
                            text: res.message,
                            confirmButtonColor: '#10b981',
                            confirmButtonText: 'Sip, Mantap!'
                        }).then(() => {
                            window.location.reload();
                        });
                        var resCont = document.getElementById('result-container');
                        if (resCont) {
                            resCont.style.display = 'block';
                            document.getElementById('result-message').innerText = res.message;
                        }
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Presensi Gagal',
                            text: res.message,
                            confirmButtonColor: '#ef4444',
                            confirmButtonText: 'Coba Lagi'
                        }).then(() => {
                            window.location.reload();
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Koneksi Terganggu',
                        text: 'Terjadi kendala saat menghubungi server.',
                        confirmButtonColor: '#ef4444',
                        confirmButtonText: 'Coba Lagi'
                    }).then(() => {
                        window.location.reload();
                    });
                }
            });
        }

        function onScanFailure(error) {
            // Handled silently while looking for QR code
        }

        html5QrcodeScanner.render(onScanSuccess, onScanFailure);
    }
</script>
