<?php
/**
 * LPPAI Corner - Portal Ujian Pretes & Sistem Pengawasan AI (Proctoring)
 * Mengintegrasikan:
 * - Skenario A: Verifikasi Wajah di Awal (Gatekeeper Check-in).
 * - Skenario B: Pengawasan Kamera Berkala Setiap 5 Menit Sekali Selama Ujian Berlangsung.
 */

define('PAGE_TITLE', 'Ujian Pretes & Pengawas AI');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user = getCurrentUser();
$pdo  = getDBConnection();

require_once __DIR__ . '/../includes/pretes-helper.php';
ensurePretesProctoringTables($pdo);

// Ambil pendaftaran pretes mahasiswa aktif
$stmtMyReg = $pdo->prepare("
    SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan as ruangan_tes
    FROM pretes_registrations pr
    LEFT JOIN pretes_schedules ps ON pr.periode = ps.periode AND ps.status = 'aktif'
    WHERE pr.user_id = ?
    ORDER BY pr.tanggal_daftar DESC
    LIMIT 1
");
$stmtMyReg->execute([$user['id']]);
$myReg = $stmtMyReg->fetch(PDO::FETCH_ASSOC);

$regId = $myReg ? (int)$myReg['id'] : 0;
$refPhoto = $myReg ? trim((string)($myReg['foto'] ?? '')) : '';
$isVerified = ($regId > 0 && !empty($_SESSION['pretes_cbt_verified_' . $regId]));

// Portal E-Ujian resmi
$eUjianUrl = 'https://www.e-ujian.com/lppai/gologin';

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Styling Khusus Portal Proctoring Ujian Pretes ── */
.proctoring-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12.5px;
    font-weight: 700;
}
.proctoring-badge.badge-active {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #86efac;
}
.proctoring-badge.badge-waiting {
    background: #fef3c7;
    color: #b45309;
    border: 1px solid #fde68a;
}
.proctoring-badge.badge-danger {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fca5a5;
}

.checkin-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 18px;
}

.checkin-card {
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}

.camera-guide-box {
    position: relative;
    width: 240px;
    height: 320px;
    margin: 0 auto 14px;
    border-radius: 12px;
    overflow: hidden;
    background: #0f172a;
    box-shadow: 0 4px 14px rgba(0,0,0,0.15);
}

.camera-guide-box video, .camera-guide-box canvas {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.camera-oval-overlay {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 160px;
    height: 220px;
    border: 2px dashed rgba(34, 197, 94, 0.85);
    border-radius: 50%;
    pointer-events: none;
    box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.35);
}

/* Floating Proctoring Bar */
.proctoring-monitor-bar {
    background: linear-gradient(135deg, #0f766e 0%, #115e59 100%);
    color: #ffffff;
    border-radius: 14px;
    padding: 14px 18px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    box-shadow: 0 4px 16px rgba(15,118,110,0.2);
}

@media (max-width: 768px) {
    .checkin-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="container-fluid" style="max-width:1100px; margin:0 auto; padding:10px 14px 40px;">

<?php if (!$myReg): ?>
    <!-- ── State: Mahasiswa Belum Terdaftar Pretes ── -->
    <div class="card" style="border:2px solid #0f766e; border-radius:16px; padding:32px 20px; text-align:center; margin-top:20px;">
        <span style="font-size:48px; display:block; margin-bottom:12px;">⚠️</span>
        <h3 style="margin:0 0 8px; font-weight:800; color:#0f172a;">Anda Belum Terdaftar pada Sesi Pretes</h3>
        <p style="color:#64748b; font-size:14px; max-width:500px; margin:0 auto 20px; line-height:1.5;">
            Untuk dapat mengikuti ujian pretes dengan pengawasan AI, Anda wajib mendaftar dan mengunggah pasfoto resmi terlebih dahulu.
        </p>
        <a href="<?= BASE_URL ?>/pretes-daftar.php" class="btn btn-primary" style="padding:10px 24px; font-weight:700; border-radius:10px;">
            👉 Daftar Pretes Sekarang
        </a>
    </div>

<?php elseif (empty($refPhoto)): ?>
    <!-- ── State: Pasfoto Pendaftaran Belum Lengkap ── -->
    <div class="card" style="border:2px solid #eab308; border-radius:16px; padding:32px 20px; text-align:center; margin-top:20px;">
        <span style="font-size:48px; display:block; margin-bottom:12px;">📸</span>
        <h3 style="margin:0 0 8px; font-weight:800; color:#854d0e;">Pasfoto Resmi Belum Terdaftar</h3>
        <p style="color:#64748b; font-size:14px; max-width:500px; margin:0 auto 20px; line-height:1.5;">
            Sistem pengawasan AI memerlukan pasfoto resmi acuan Anda untuk memverifikasi keaslian peserta saat ujian berlangsung.
        </p>
        <a href="<?= BASE_URL ?>/pretes-daftar.php" class="btn btn-primary" style="padding:10px 24px; font-weight:700; border-radius:10px;">
            Perbarui Pasfoto Resmi ➔
        </a>
    </div>

<?php else: ?>

    <!-- ═════════════════════════════════════════════════════════════════════
         BAGIAN 1: SKENARIO A — CHECK-IN VERIFIKASI WAJAH DI AWAL UJIAN
         ═════════════════════════════════════════════════════════════════════ -->
    <div id="sectionCheckin" style="<?= $isVerified ? 'display:none;' : 'display:block;' ?>">
        
        <div style="margin-bottom:18px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <div>
                    <h2 style="margin:0; font-size:22px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                        <span>🛡️</span> <span>Verifikasi Identitas Peserta Ujian Pretes</span>
                    </h2>
                    <p style="margin:4px 0 0; color:#64748b; font-size:13.5px;">
                        Tahap 1 (Skenario A): Pengecekan wajah live mahasiswa terhadap pasfoto pendaftaran resmi sebelum membuka soal.
                    </p>
                </div>
                <div class="proctoring-badge badge-waiting" id="badgeCheckinStatus">
                    ⏳ Menunggu Verifikasi Awal
                </div>
            </div>
        </div>

        <div class="card" style="border:1.5px solid #cbd5e1; border-radius:16px; overflow:hidden; box-shadow:0 4px 18px rgba(0,0,0,0.04);">
            <div style="background:#f8fafc; border-bottom:1.5px solid #e2e8f0; padding:14px 18px; font-size:13px; color:#334155;">
                <strong>Petunjuk:</strong> Posisikan wajah Anda tepat di dalam bingkai oval kamera dengan kedua mata terbuka dan pencahayaan terang. Klik tombol <strong>Verifikasi Wajah & Buka Ujian</strong> untuk melanjutkan.
            </div>

            <div class="card-body" style="padding:20px;">
                <div class="checkin-grid">
                    <!-- Sisi Kiri: Pasfoto Referensi Pendaftaran -->
                    <div class="checkin-card">
                        <div style="font-size:12px; font-weight:800; color:#0f766e; text-transform:uppercase; margin-bottom:8px;">
                            Pasfoto Resmi Pendaftaran Anda
                        </div>
                        <div style="width:180px; height:240px; margin:0 auto 12px; border-radius:12px; overflow:hidden; border:2.5px solid #0f766e; box-shadow:0 4px 12px rgba(15,118,110,0.15);">
                            <img src="<?= BASE_URL ?>/<?= htmlspecialchars(ltrim(str_replace('\\', '/', $refPhoto), '/')) ?>" 
                                 alt="Pasfoto Referensi" 
                                 style="width:100%; height:100%; object-fit:cover; display:block;">
                        </div>
                        <div style="font-size:13px; font-weight:700; color:#0f172a;">
                            <?= sanitize($user['nama_lengkap'] ?? $user['name'] ?? 'Peserta Pretes') ?>
                        </div>
                        <div style="font-size:12px; color:#64748b;">NIM: <?= sanitize($user['nim'] ?? '-') ?></div>
                    </div>

                    <!-- Sisi Kanan: Kamera Live Mahasiswa -->
                    <div class="checkin-card">
                        <div style="font-size:12px; font-weight:800; color:#0f766e; text-transform:uppercase; margin-bottom:8px;">
                            Kamera Langsung Peserta (Live Webcam)
                        </div>
                        
                        <div class="camera-guide-box" id="checkinCameraBox">
                            <video id="checkinVideo" playsinline autoplay muted></video>
                            <canvas id="checkinCanvas" style="display:none;"></canvas>
                            <div class="camera-oval-overlay"></div>
                        </div>

                        <div id="checkinAlert" style="display:none; margin:10px auto; padding:10px 14px; border-radius:10px; font-size:12.5px; text-align:left; line-height:1.5;"></div>

                        <div style="display:flex; justify-content:center; gap:10px; flex-wrap:wrap; margin-top:10px;">
                            <button type="button" class="btn btn-secondary" onclick="toggleCameraFace('checkinVideo')" style="font-size:12px; padding:8px 14px; border-radius:8px;">
                                🔄 Balik Kamera
                            </button>
                            <button type="button" id="btnRunCheckin" class="btn btn-success" onclick="runInitialCheckin()" 
                                style="font-size:14px; font-weight:800; padding:10px 22px; border-radius:10px; box-shadow:0 4px 12px rgba(22,163,74,0.3); display:inline-flex; align-items:center; gap:8px;">
                                <span>🔍</span> <span>Verifikasi Wajah & Buka Ujian</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- ═════════════════════════════════════════════════════════════════════
         BAGIAN 2: SKENARIO B — RUANG PENGERJAAN UJIAN DENGAN PENGAWAS AI
         Pengecekan Wajah Berkala Setiap 5 Menit Sekali Selama Ujian Aktif
         ═════════════════════════════════════════════════════════════════════ -->
    <div id="sectionExamRoom" style="<?= $isVerified ? 'display:block;' : 'display:none;' ?>">
        
        <!-- Proctoring Floating Status Bar -->
        <div class="proctoring-monitor-bar">
            <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                <span style="font-size:28px;">🟢</span>
                <div>
                    <div style="font-size:14px; font-weight:800; display:flex; align-items:center; gap:8px;">
                        <span>Pengawas AI Aktif (Skenario B)</span>
                        <span style="background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:12px; font-size:11px;">Pengecekan Tiap 5 Menit</span>
                    </div>
                    <div style="font-size:12px; color:#ccfbf1; margin-top:2px;" id="lastCheckInfo">
                        Sesi diverifikasi. Pemeriksaan wajah berkala aktif di latar belakang.
                    </div>
                </div>
            </div>

            <!-- Countdown Timer 5 Menit -->
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="text-align:right;">
                    <div style="font-size:11px; color:#ccfbf1; text-transform:uppercase; font-weight:700;">Pengecekan Berikutnya:</div>
                    <div style="font-size:18px; font-weight:800; font-family:monospace;" id="proctorTimerDisplay">05:00</div>
                </div>
                <!-- Mini Video Stream Hidden/Background -->
                <div style="width:48px; height:64px; border-radius:6px; overflow:hidden; border:1.5px solid rgba(255,255,255,0.5); background:#000;">
                    <video id="proctorVideo" playsinline autoplay muted style="width:100%; height:100%; object-fit:cover;"></video>
                </div>
            </div>
        </div>

        <!-- Alert Peringatan Pelanggaran Pengawas AI -->
        <div id="proctorViolationAlert" style="display:none; background:#fef2f2; border:2px solid #ef4444; color:#991b1b; padding:16px 20px; border-radius:14px; margin-bottom:20px; box-shadow:0 4px 14px rgba(239,68,68,0.15);">
            <div style="font-size:15px; font-weight:800; display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <span>⚠️</span> <span>PERINGATAN PENGAWAS AI PRETES:</span>
            </div>
            <div id="proctorViolationMsg" style="font-size:13.5px; line-height:1.5;"></div>
            <div style="margin-top:8px; font-size:12px; color:#b91c1c; font-weight:600;">
                Perhatian: Pelanggaran dicatat pada sistem log audit proctoring pengawas ujian. Harap tetap berada di depan kamera.
            </div>
        </div>

        <!-- Lembar Akses Soal Ujian CBT -->
        <div class="card" style="border:1.5px solid #0f766e; border-radius:16px; overflow:hidden; box-shadow:0 4px 18px rgba(15,118,110,0.08); margin-bottom:24px;">
            <div style="background:linear-gradient(135deg, #0f766e 0%, #115e59 100%); color:#ffffff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div>
                    <h3 style="margin:0; font-size:16px; font-weight:800; color:#fff;">Kredensial Login CBT E-Ujian Mahasiswa</h3>
                    <div style="font-size:12.5px; color:#ccfbf1; margin-top:2px;">
                        Gunakan data login berikut untuk masuk ke portal lembar soal CBT E-Ujian.
                    </div>
                </div>
                <span class="badge" style="background:#22c55e; color:#fff; font-size:12px; padding:6px 14px; border-radius:20px;">
                    ✓ Akses Terbuka
                </span>
            </div>

            <div class="card-body" style="padding:22px;">
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:20px;">
                    <!-- ID Lembaga -->
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px;">
                        <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Kode Lembaga / ID</div>
                        <div style="font-size:18px; font-weight:800; color:#0f172a; margin-top:2px;">22205</div>
                    </div>
                    <!-- Username -->
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px;">
                        <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Username CBT</div>
                        <div style="font-size:17px; font-weight:800; color:#0f766e; margin-top:2px;">
                            <?= sanitize($myReg['username_tes'] ?: ($user['nim'] . '.22205')) ?>
                        </div>
                    </div>
                    <!-- Password -->
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px;">
                        <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Password / Kode Akses</div>
                        <div style="font-size:17px; font-weight:800; color:#0f766e; margin-top:2px;">
                            <?= sanitize($myReg['password_tes'] ?: 'Menunggu TU') ?>
                        </div>
                    </div>
                </div>

                <!-- Tombol Akses CBT E-Ujian -->
                <div style="background:#f0fdf4; border:1.5px solid #86efac; border-radius:12px; padding:18px; text-align:center;">
                    <h4 style="margin:0 0 6px; font-size:15px; font-weight:800; color:#15803d;">
                        Portal CBT Ujian Pretes Siap Dikerjakan
                    </h4>
                    <p style="margin:0 auto 14px; font-size:13px; color:#166534; max-width:540px; line-height:1.5;">
                        Klik tombol di bawah untuk membuka portal E-Ujian di tab baru. <strong>Jangan tutup tab halaman ini</strong> agar sistem kamera pengawas AI tetap dapat memverifikasi kehadiran Anda berkala setiap 5 menit.
                    </p>
                    <a href="<?= htmlspecialchars($eUjianUrl) ?>" target="_blank" rel="noopener" class="btn btn-success"
                       style="padding:12px 32px; font-size:15px; font-weight:800; border-radius:10px; box-shadow:0 4px 14px rgba(22,163,74,0.3); display:inline-flex; align-items:center; gap:8px; text-decoration:none;">
                        <span>🚀</span> <span>Buka Portal Soal CBT E-Ujian Sekarang ➔</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- 4 Tata Tertib Pengawasan -->
        <div class="card" style="border:1px solid #e2e8f0; border-radius:14px; padding:18px; background:#f8fafc;">
            <div style="font-size:13.5px; font-weight:800; color:#0f172a; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                <span>📋</span> <span>Tata Tertib Ujian Selama Proctoring Berlangsung:</span>
            </div>
            <ul style="margin:0; padding-left:20px; font-size:12.5px; color:#475569; display:flex; flex-direction:column; gap:4px; line-height:1.55;">
                <li>1. Wajib dikerjakan secara mandiri dan jujur tanpa bantuan orang lain (tidak memakai joki).</li>
                <li>2. Pastikan wajah selalu menghadap ke arah layar dan tidak meninggalkan kamera selama ujian berlangsung.</li>
                <li>3. Dilarang membuka website lain atau menggunakan HP tambahan saat mengerjakan soal.</li>
                <li>4. Sistem AI akan mengambil snapshot otomatis tiap 5 menit untuk memastikan kontinuitas peserta asli.</li>
            </ul>
        </div>

    </div>

<?php endif; ?>

</div>

<!-- ═══════════════════════════════════════════════════════════════════
     CLIENT SCRIPT SKENARIO A & B PROCTORING ENGINE
     ═══════════════════════════════════════════════════════════════════ -->
<script src="<?= BASE_URL ?>/assets/js/pretes-face-verifier.js?v=<?= time() ?>"></script>
<script>
const CSRF_TOKEN = <?= json_encode(generateCsrf()) ?>;
const REGISTRATION_ID = <?= (int)$regId ?>;
const API_URL = <?= json_encode(BASE_URL . '/api/pretes-proctoring-check.php') ?>;

let proctorInterval = null;
let countdownTimer = null;
let secondsRemaining = 300; // 5 menit = 300 detik
let activeStream = null;

// Mulai kamera saat halaman dimuat
document.addEventListener('DOMContentLoaded', function() {
    const isAlreadyVerified = <?= $isVerified ? 'true' : 'false' ?>;
    if (!isAlreadyVerified) {
        initCheckinCamera();
    } else {
        initProctoringExamRoom();
    }
});

/**
 * ── Inisialisasi Kamera Skenario A (Check-in Awal) ──
 */
function initCheckinCamera() {
    const video = document.getElementById('checkinVideo');
    if (!video) return;

    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        navigator.mediaDevices.getUserMedia({
            audio: false,
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }
        }).then(function(stream) {
            activeStream = stream;
            video.srcObject = stream;
            video.play();
        }).catch(function(err) {
            const alertBox = document.getElementById('checkinAlert');
            if (alertBox) {
                alertBox.style.display = 'block';
                alertBox.style.background = '#fef2f2';
                alertBox.style.border = '1px solid #fca5a5';
                alertBox.style.color = '#991b1b';
                alertBox.innerHTML = '<strong>Gagal Mengakses Kamera:</strong> ' + (err.message || err) + '<br>Pastikan peramban Anda diizinkan mengakses kamera.';
            }
        });
    }
}

/**
 * ── Eksekusi Skenario A: Check-in Verifikasi Wajah Di Awal ──
 */
function runInitialCheckin() {
    const video = document.getElementById('checkinVideo');
    const alertBox = document.getElementById('checkinAlert');
    const btn = document.getElementById('btnRunCheckin');

    if (!video || !video.videoWidth) {
        alert("Kamera belum aktif. Mohon izinkan akses kamera pada peramban Anda.");
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span>⏳</span> <span>Memeriksa Wajah...</span>';
    }

    if (alertBox) {
        alertBox.style.display = 'block';
        alertBox.style.background = '#eff6ff';
        alertBox.style.border = '1px solid #bfdbfe';
        alertBox.style.color = '#1e40af';
        alertBox.innerHTML = '<strong>⏳ Sedang Mencocokkan...</strong><br>Sistem AI sedang membandingkan wajah di kamera dengan pasfoto pendaftaran resmi Anda.';
    }

    // Ambil snapshot 3:4 portrait
    const snapCanvas = document.createElement('canvas');
    snapCanvas.width = 360;
    snapCanvas.height = 480;
    const ctx = snapCanvas.getContext('2d');
    ctx.drawImage(video, 0, 0, snapCanvas.width, snapCanvas.height);
    const livePhotoData = snapCanvas.toDataURL('image/jpeg', 0.88);

    // Kirim ke backend API
    fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            csrf_token: CSRF_TOKEN,
            action: 'initial_checkin',
            registration_id: REGISTRATION_ID,
            live_photo: livePhotoData
        })
    })
    .then(res => res.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<span>🔍</span> <span>Verifikasi Wajah & Buka Ujian</span>';
        }

        if (data && data.verified) {
            // Skenario A Berhasil
            if (alertBox) {
                alertBox.style.background = '#f0fdf4';
                alertBox.style.border = '1px solid #86efac';
                alertBox.style.color = '#15803d';
                alertBox.innerHTML = '<strong>✅ Verifikasi Berhasil! (' + data.score + '% Cocok)</strong><br>' + data.message;
            }

            const badge = document.getElementById('badgeCheckinStatus');
            if (badge) {
                badge.className = 'proctoring-badge badge-active';
                badge.innerHTML = '✓ Terverifikasi Identik';
            }

            // Transisi ke Skenario B (Ruang Ujian) dalam 1.2 detik
            setTimeout(function() {
                const secCheckin = document.getElementById('sectionCheckin');
                const secExam = document.getElementById('sectionExamRoom');
                if (secCheckin) secCheckin.style.display = 'none';
                if (secExam) secExam.style.display = 'block';
                initProctoringExamRoom();
            }, 1200);

        } else {
            // Skenario A Gagal / Mismatch
            if (alertBox) {
                alertBox.style.background = '#fef2f2';
                alertBox.style.border = '1px solid #fca5a5';
                alertBox.style.color = '#991b1b';
                alertBox.innerHTML = '<strong>❌ Verifikasi Gagal (' + (data.score || 0) + '% Kemiripan):</strong><br>' + 
                    (data.message || 'Wajah tidak cocok dengan pasfoto pendaftaran resmi.');
            }
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<span>🔍</span> <span>Verifikasi Wajah & Buka Ujian</span>';
        }
        if (alertBox) {
            alertBox.style.background = '#fef2f2';
            alertBox.style.border = '1px solid #fca5a5';
            alertBox.style.color = '#991b1b';
            alertBox.innerHTML = '<strong>Error Koneksi Server:</strong> ' + err.message;
        }
    });
}

/**
 * ── Inisialisasi Skenario B: Pengawasan Berkala Tiap 5 Menit ──
 */
function initProctoringExamRoom() {
    const video = document.getElementById('proctorVideo');
    if (video) {
        if (activeStream) {
            video.srcObject = activeStream;
            video.play();
        } else if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ audio: false, video: true })
                .then(function(s) {
                    activeStream = s;
                    video.srcObject = s;
                    video.play();
                }).catch(e => console.warn("Proctor camera stream:", e));
        }
    }

    startProctoringTimer();
}

/**
 * ── Timer Hitung Mundur 5 Menit & Pemicu Snapshot Berkala ──
 */
function startProctoringTimer() {
    if (countdownTimer) clearInterval(countdownTimer);
    if (proctorInterval) clearInterval(proctorInterval);

    secondsRemaining = 300; // 5 menit

    countdownTimer = setInterval(function() {
        secondsRemaining--;
        if (secondsRemaining <= 0) {
            secondsRemaining = 300;
            runPeriodicProctoringCheck();
        }
        updateTimerDisplay();
    }, 1000);

    updateTimerDisplay();
}

function updateTimerDisplay() {
    const disp = document.getElementById('proctorTimerDisplay');
    if (!disp) return;
    const m = Math.floor(secondsRemaining / 60);
    const s = secondsRemaining % 60;
    disp.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
}

/**
 * ── Eksekusi Skenario B: Pengambilan Snapshot Berkala Tiap 5 Menit ──
 */
function runPeriodicProctoringCheck() {
    const video = document.getElementById('proctorVideo') || document.getElementById('checkinVideo');
    if (!video || !video.videoWidth) {
        recordProctoringViolation("Kamera terputus atau tidak aktif saat pengawasan 5 menit.");
        return;
    }

    const snapCanvas = document.createElement('canvas');
    snapCanvas.width = 320;
    snapCanvas.height = 420;
    const ctx = snapCanvas.getContext('2d');
    ctx.drawImage(video, 0, 0, snapCanvas.width, snapCanvas.height);
    const livePhotoData = snapCanvas.toDataURL('image/jpeg', 0.85);

    fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            csrf_token: CSRF_TOKEN,
            action: 'periodic_5min',
            registration_id: REGISTRATION_ID,
            live_photo: livePhotoData
        })
    })
    .then(res => res.json())
    .then(data => {
        const lastInfo = document.getElementById('lastCheckInfo');
        const alertBox = document.getElementById('proctorViolationAlert');
        const alertMsg = document.getElementById('proctorViolationMsg');

        if (data && data.verified) {
            // Pengecekan 5 Menit Lolos
            if (lastInfo) {
                lastInfo.innerHTML = 'Pemeriksaan terakhir: <strong>' + (data.checked_at || 'Baru saja') + '</strong> &bull; Status: <span style="color:#86efac; font-weight:700;">Peserta Sesuai (' + data.score + '%)</span>';
            }
            if (alertBox) alertBox.style.display = 'none';
        } else {
            // Pengecekan 5 Menit Terdeteksi Pelanggaran (Mismatch atau Wajah Hilang)
            if (lastInfo) {
                lastInfo.innerHTML = 'Pemeriksaan terakhir: <strong>' + (data.checked_at || 'Baru saja') + '</strong> &bull; Status: <span style="color:#fca5a5; font-weight:700;">Pelanggaran Terdeteksi</span>';
            }
            if (alertBox && alertMsg) {
                alertBox.style.display = 'block';
                alertMsg.innerHTML = '<strong>' + (data.message || 'Wajah tidak terdeteksi atau tidak cocok dengan pasfoto resmi terdaftar!') + '</strong><br>Pastikan Anda tetap duduk tegak menghadap kamera dan mengerjakan sendiri secara jujur.';
            }
        }
    })
    .catch(err => {
        console.error("Periodic proctoring error:", err);
    });
}

function recordProctoringViolation(msg) {
    const alertBox = document.getElementById('proctorViolationAlert');
    const alertMsg = document.getElementById('proctorViolationMsg');
    if (alertBox && alertMsg) {
        alertBox.style.display = 'block';
        alertMsg.textContent = msg;
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
