<?php
/**
 * LPPAI Corner - Halaman Pemindai Kamera Pengambilan Sertifikat (Role Mahasiswa)
 * Mahasiswa memindai QR Code pada sertifikat fisik untuk konfirmasi pengambilan mandiri.
 */
define('PAGE_TITLE', 'Scan Pengambilan Sertifikat');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pdo = getDBConnection();
$currentUser = getCurrentUser();

// Auto-migration aman untuk tabel pengambilan_sertifikat jika belum ada
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pengambilan_sertifikat (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tutorial_registration_id INT NULL,
            nim VARCHAR(50) NOT NULL,
            nama_mahasiswa VARCHAR(150) NOT NULL,
            program_studi VARCHAR(100) NULL,
            nomor_sertifikat VARCHAR(100) NOT NULL,
            tahun_ajaran VARCHAR(50) NULL,
            status_pengambilan ENUM('Sudah Diambil', 'Belum Diambil') NOT NULL DEFAULT 'Sudah Diambil',
            tanggal_pengambilan DATETIME NOT NULL,
            metode_pengambilan ENUM('Scan QR', 'Manual Admin', 'Barcode/Scanner') NOT NULL DEFAULT 'Scan QR',
            nama_penerima VARCHAR(150) NULL,
            hubungan_penerima VARCHAR(50) NULL DEFAULT 'Sendiri',
            foto_bukti VARCHAR(255) NULL,
            tanda_tangan_digital MEDIUMTEXT NULL,
            catatan TEXT NULL,
            petugas_id INT NULL,
            petugas_nama VARCHAR(150) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_nim (nim),
            INDEX idx_nomor_sertifikat (nomor_sertifikat),
            INDEX idx_tanggal_pengambilan (tanggal_pengambilan),
            INDEX idx_status (status_pengambilan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {}

try {
    $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN is_sertifikat_diambil TINYINT(1) DEFAULT 0 AFTER nomor_sertifikat");
} catch (Exception $e) {}

try {
    $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN tgl_sertifikat_diambil DATETIME NULL AFTER is_sertifikat_diambil");
} catch (Exception $e) {}

// ── AJAX HANDLER UNTUK SCAN KAMERA MAHASISWA ──────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json; charset=utf-8');

    $rawCode = trim($_POST['code'] ?? $_GET['code'] ?? '');
    if (empty($rawCode)) {
        echo json_encode(['success' => false, 'message' => 'Kode QR kosong atau tidak terbaca.']);
        exit;
    }

    // Ekstraksi jika kode berbentuk URL
    $cleanCode = $rawCode;
    if (filter_var($rawCode, FILTER_VALIDATE_URL)) {
        $parsedUrl = parse_url($rawCode);
        if (!empty($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
            if (!empty($queryParams['no'])) {
                $cleanCode = $queryParams['no'];
            } elseif (!empty($queryParams['code'])) {
                $cleanCode = $queryParams['code'];
            } elseif (!empty($queryParams['nim'])) {
                $cleanCode = $queryParams['nim'];
            }
        }
    }

    // 1. Cari data sertifikat umum terlebih dahulu
    $stmtFind = $pdo->prepare("
        SELECT tr.*, u.nim, u.nama_lengkap, u.program_studi
        FROM tutorial_registrations tr
        JOIN users u ON tr.user_id = u.id
        WHERE (tr.nomor_sertifikat = ? OR u.nim = ?)
          AND tr.nomor_sertifikat IS NOT NULL AND tr.nomor_sertifikat != ''
        ORDER BY tr.id DESC
        LIMIT 1
    ");
    $stmtFind->execute([$cleanCode, $cleanCode]);
    $scannedCert = $stmtFind->fetch();

    if (!$scannedCert) {
        echo json_encode([
            'success' => false,
            'message' => 'Sertifikat dengan kode "' . htmlspecialchars($cleanCode) . '" tidak ditemukan dalam database atau belum diterbitkan.'
        ]);
        exit;
    }

    // 2. Verifikasi Kepemilikan (Wajib akun mahasiswa yang bersangkutan)
    $isOwner = ($currentUser['id'] == $scannedCert['user_id'] || $currentUser['nim'] === $scannedCert['nim']);
    
    // Jika admin sedang mengetes di halaman ini, izinkan admin sebagai bypass khusus
    if (!$isOwner && $currentUser['role'] !== 'admin') {
        echo json_encode([
            'success' => false,
            'is_wrong_owner' => true,
            'owner_name' => $scannedCert['nama_lengkap'],
            'owner_nim' => $scannedCert['nim'],
            'message' => 'Sertifikat ini bukan milik Anda! Sertifikat terdaftar atas nama ' . $scannedCert['nama_lengkap'] . ' (NIM: ' . $scannedCert['nim'] . '). Anda hanya berhak memindai sertifikat atas nama Anda sendiri.'
        ]);
        exit;
    }

    // 3. Cek apakah sudah pernah diambil
    $isTaken = (!empty($scannedCert['is_sertifikat_diambil']) && $scannedCert['is_sertifikat_diambil'] == 1);
    $pickupData = null;

    try {
        $stmtP = $pdo->prepare("
            SELECT * FROM pengambilan_sertifikat 
            WHERE nomor_sertifikat = ? OR tutorial_registration_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmtP->execute([$scannedCert['nomor_sertifikat'], $scannedCert['id']]);
        $pickupData = $stmtP->fetch();
        if ($pickupData) {
            $isTaken = true;
        }
    } catch (Exception $e) {}

    if ($isTaken) {
        echo json_encode([
            'success' => true,
            'already_taken' => true,
            'message' => 'Sertifikat Anda sudah tercatat diambil sebelumnya pada tanggal ' . (!empty($pickupData['tanggal_pengambilan']) ? date('d/m/Y H:i', strtotime($pickupData['tanggal_pengambilan'])) : date('d/m/Y')),
            'cert' => [
                'nomor_sertifikat' => $scannedCert['nomor_sertifikat'],
                'nama_lengkap'     => $scannedCert['nama_lengkap'],
                'nim'              => $scannedCert['nim'],
                'program_studi'    => $scannedCert['program_studi'],
                'tahun_ajaran'     => $scannedCert['tahun_ajaran'] ?? '-'
            ],
            'pickup_info' => $pickupData,
            'pickup_id'   => $pickupData['id'] ?? 0
        ]);
        exit;
    }

    // 4. Catat Pengambilan Baru
    try {
        $pdo->beginTransaction();

        $namaPenerima = $currentUser['nama_lengkap'];
        $hubungan = 'Sendiri (Pemilik Sertifikat)';
        $catatan = 'Dikonfirmasi mandiri via pemindai kamera akun mahasiswa';
        $metode = 'Scan QR';
        $petugasNama = 'Mahasiswa Mandiri (Kamera)';

        $sqlIns = "
            INSERT INTO pengambilan_sertifikat (
                tutorial_registration_id, nim, nama_mahasiswa, program_studi, nomor_sertifikat,
                tahun_ajaran, status_pengambilan, tanggal_pengambilan, metode_pengambilan,
                nama_penerima, hubungan_penerima, catatan, petugas_id, petugas_nama
            ) VALUES (?, ?, ?, ?, ?, ?, 'Sudah Diambil', NOW(), ?, ?, ?, ?, NULL, ?)
        ";
        $stmtIns = $pdo->prepare($sqlIns);
        $stmtIns->execute([
            $scannedCert['id'],
            $scannedCert['nim'],
            $scannedCert['nama_lengkap'],
            $scannedCert['program_studi'],
            $scannedCert['nomor_sertifikat'],
            $scannedCert['tahun_ajaran'] ?? date('Y'),
            $metode,
            $namaPenerima,
            $hubungan,
            $catatan,
            $petugasNama
        ]);
        $newPickupId = (int)$pdo->lastInsertId();

        // Update status di tutorial_registrations
        try {
            $stmtUp = $pdo->prepare("
                UPDATE tutorial_registrations 
                SET is_sertifikat_diambil = 1, tgl_sertifikat_diambil = NOW()
                WHERE id = ?
            ");
            $stmtUp->execute([$scannedCert['id']]);
        } catch (Exception $e) {}

        $pdo->commit();

        echo json_encode([
            'success'     => true,
            'newly_taken' => true,
            'message'     => 'Selamat! Pengambilan sertifikat fisik Anda berhasil dikonfirmasi dan resmi tercatat.',
            'cert' => [
                'nomor_sertifikat' => $scannedCert['nomor_sertifikat'],
                'nama_lengkap'     => $scannedCert['nama_lengkap'],
                'nim'              => $scannedCert['nim'],
                'program_studi'    => $scannedCert['program_studi'],
                'tahun_ajaran'     => $scannedCert['tahun_ajaran'] ?? '-'
            ],
            'pickup_id'   => $newPickupId,
            'tanggal'     => date('d F Y, H:i') . ' WIB'
        ]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Gagal mencatat pengambilan: ' . $e->getMessage()
        ]);
        exit;
    }
}

// ── QUERY DATA SERTIFIKAT MILIK MAHASISWA YANG SEDANG LOGIN ───────
$myCert = null;
$myPickup = null;
try {
    $stmtMy = $pdo->prepare("
        SELECT tr.*, u.nama_lengkap, u.nim, u.program_studi
        FROM tutorial_registrations tr
        JOIN users u ON tr.user_id = u.id
        WHERE tr.user_id = ?
          AND tr.nomor_sertifikat IS NOT NULL AND tr.nomor_sertifikat != ''
        ORDER BY tr.id DESC
        LIMIT 1
    ");
    $stmtMy->execute([$currentUser['id']]);
    $myCert = $stmtMy->fetch();

    if ($myCert) {
        $stmtP = $pdo->prepare("
            SELECT * FROM pengambilan_sertifikat 
            WHERE nomor_sertifikat = ? OR tutorial_registration_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmtP->execute([$myCert['nomor_sertifikat'], $myCert['id']]);
        $myPickup = $stmtP->fetch();
    }
} catch (Exception $e) {}

include __DIR__ . '/../includes/header.php';
?>

<!-- Style khusus Scanner Mahasiswa (Ditempatkan di dalam .content-area untuk kompatibilitas SPA) -->
<style>
.scanner-mhs-wrapper {
    max-width: 850px;
    margin: 0 auto;
}
.mhs-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 16px rgba(0,0,0,0.04);
    padding: 24px;
    margin-bottom: 24px;
}
.camera-box-mhs {
    background: #0f172a;
    border-radius: 16px;
    overflow: hidden;
    position: relative;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    border: 3px solid #1e3a8a;
    margin-bottom: 20px;
}
#reader_mahasiswa {
    width: 100%;
    min-height: 280px;
    background: #0f172a;
}
#reader_mahasiswa video {
    width: 100% !important;
    height: auto !important;
    border-radius: 12px;
}
.camera-control-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 10px;
}
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12.5px;
    font-weight: 700;
}
.status-pill.success { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.status-pill.warning { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }

/* Result Card */
.result-card {
    display: none;
    background: #ffffff;
    border-radius: 14px;
    padding: 24px;
    border: 2px solid #10b981;
    box-shadow: 0 8px 24px rgba(16, 185, 129, 0.15);
    margin-bottom: 24px;
    animation: fadeInSlide 0.3s ease;
}
.result-card.already-taken {
    border-color: #3b82f6;
    box-shadow: 0 8px 24px rgba(59, 130, 246, 0.15);
}
@keyframes fadeInSlide {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.result-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 16px;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 14px;
}
.result-icon {
    font-size: 40px;
    line-height: 1;
}
.result-detail-table {
    width: 100%;
    border-collapse: collapse;
    margin: 14px 0;
    font-size: 14px;
}
.result-detail-table td {
    padding: 8px 12px;
    border-bottom: 1px solid #f1f5f9;
}
.result-detail-table td:first-child {
    width: 38%;
    color: #64748b;
    font-weight: 600;
}
.result-detail-table td:last-child {
    color: #0f172a;
    font-weight: 700;
}
</style>

<div class="scanner-mhs-wrapper">

    <!-- Header Instruksi -->
    <div class="mhs-card" style="border-left: 5px solid #1e3a8a;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
            <div>
                <h2 style="font-size:20px; font-weight:800; color:#1e293b; margin-bottom:4px;">
                    📷 Scan Pengambilan Sertifikat Anda
                </h2>
                <p style="font-size:14px; color:#64748b; margin:0;">
                    Arahkan kamera smartphone atau webcam Anda ke <strong>QR Code yang tercetak di sertifikat fisik</strong> yang telah Anda terima.
                </p>
            </div>
            <div>
                <span class="status-pill <?= ($myPickup || (!empty($myCert['is_sertifikat_diambil']) && $myCert['is_sertifikat_diambil'] == 1)) ? 'success' : 'warning' ?>">
                    <?= ($myPickup || (!empty($myCert['is_sertifikat_diambil']) && $myCert['is_sertifikat_diambil'] == 1)) ? '✅ SUDAH DIAMBIL' : '📦 BELUM DIAMBIL' ?>
                </span>
            </div>
        </div>

        <!-- Data Pemilik Akun -->
        <div style="margin-top:16px; padding:12px 16px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0; font-size:13.5px; display:flex; gap:20px; flex-wrap:wrap;">
            <div>Mahasiswa: <strong><?= htmlspecialchars($currentUser['nama_lengkap']) ?></strong></div>
            <div>NIM: <strong><?= htmlspecialchars($currentUser['nim'] ?: '-') ?></strong></div>
            <div>Program Studi: <strong><?= htmlspecialchars($currentUser['program_studi'] ?: '-') ?></strong></div>
            <?php if ($myCert): ?>
                <div>No. Sertifikat Anda: <strong style="color:#1e3a8a;"><?= htmlspecialchars($myCert['nomor_sertifikat']) ?></strong></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert / Kartu Hasil Scan (Tampil saat Scan Berhasil / Terdeteksi) -->
    <div id="scanResultCard" class="result-card">
        <div class="result-header">
            <div id="resultIcon" class="result-icon">🎉</div>
            <div>
                <h3 id="resultTitle" style="font-size:18px; font-weight:800; margin:0; color:#1e293b;">Pengambilan Berhasil Dikonfirmasi!</h3>
                <p id="resultSubtitle" style="font-size:13.5px; color:#475569; margin:3px 0 0;"></p>
            </div>
        </div>

        <table class="result-detail-table">
            <tr>
                <td>Nomor Sertifikat</td>
                <td><span id="resNoSert" style="font-family:monospace; color:#1e3a8a;"></span></td>
            </tr>
            <tr>
                <td>Nama Mahasiswa</td>
                <td><span id="resNama"></span></td>
            </tr>
            <tr>
                <td>NIM Mahasiswa</td>
                <td><span id="resNim"></span></td>
            </tr>
            <tr>
                <td>Program Studi</td>
                <td><span id="resProdi"></span></td>
            </tr>
            <tr>
                <td>Waktu Pengambilan</td>
                <td><span id="resWaktu" style="color:#15803d;"></span></td>
            </tr>
        </table>

        <div style="margin-top:18px; display:flex; gap:10px; flex-wrap:wrap;">
            <a id="btnCetakTandaTerima" href="#" target="_blank" class="btn btn-success" style="padding:10px 18px; font-size:14px; text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                🖨️ Cetak / Unduh Tanda Terima Pengambilan
            </a>
            <button type="button" class="btn btn-secondary" onclick="resetScannerView()" style="padding:10px 16px; font-size:14px;">
                🔄 Scan Sertifikat Lain
            </button>
        </div>
    </div>

    <!-- Kotak Pemindai Kamera -->
    <div class="mhs-card" id="scannerContainerCard">
        <div class="camera-control-bar">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:18px;">📷</span>
                <span style="font-weight:700; font-size:14px; color:#1e293b;">Kamera Pemindai Aktif</span>
            </div>
            <div style="display:flex; gap:8px;">
                <button type="button" class="btn btn-sm btn-secondary" onclick="toggleMhsCamera()" id="btnSwitchCam">
                    🔄 Ganti Kamera
                </button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="restartMhsScanner()">
                    ⚡ Refresh Kamera
                </button>
            </div>
        </div>

        <div class="camera-box-mhs">
            <div id="reader_mahasiswa"></div>
        </div>

        <div style="text-align:center; color:#64748b; font-size:13px; margin-bottom:16px;">
            Arahkan barcode / QR Code sertifikat tepat ke dalam kotak panduan kamera di atas.
        </div>

        <!-- Input Manual / Cadangan jika kamera bermasalah -->
        <div style="border-top:1px solid #e2e8f0; padding-top:16px;">
            <label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">
                ⌨️ Atau Masukkan Nomor Sertifikat / Link Secara Manual:
            </label>
            <div style="display:flex; gap:10px;">
                <input type="text" id="manualInputMhs" class="form-control" placeholder="Contoh: 5618/U/L.3.11/A.2/IX/2026 atau tempel URL" value="<?= $myCert['nomor_sertifikat'] ?? '' ?>">
                <button type="button" class="btn btn-primary" onclick="submitManualCode()" style="white-space:nowrap; padding:10px 20px;">
                    🔍 Konfirmasi
                </button>
            </div>
        </div>
    </div>

</div>

<!-- Library Html5Qrcode -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<script>
var html5QrCodeMhs = null;
var isScanningMhs = false;
var currentFacingMode = "environment"; // default kamera belakang HP

// Audio Feedback Synthesizer (Web Audio API)
function playBeep(type) {
    try {
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);

        if (type === 'success') {
            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
            osc.frequency.setValueAtTime(880, ctx.currentTime + 0.1); // A5
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
            osc.start();
            osc.stop(ctx.currentTime + 0.35);
        } else if (type === 'error') {
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(220, ctx.currentTime);
            osc.frequency.setValueAtTime(146.83, ctx.currentTime + 0.12);
            gain.gain.setValueAtTime(0.3, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
            osc.start();
            osc.stop(ctx.currentTime + 0.35);
        }
    } catch(e) {}
}

function startMhsScanner() {
    var readerEl = document.getElementById("reader_mahasiswa");
    if (!readerEl) return;

    if (html5QrCodeMhs) {
        try { html5QrCodeMhs.stop(); } catch(e) {}
    }

    html5QrCodeMhs = new Html5Qrcode("reader_mahasiswa");
    var config = {
        fps: 15,
        qrbox: { width: 250, height: 250 },
        aspectRatio: 1.0
    };

    html5QrCodeMhs.start(
        { facingMode: currentFacingMode },
        config,
        onMhsScanSuccess,
        function(error) {
            // scan failure callback (abaikan agar tidak spam console)
        }
    ).then(function() {
        isScanningMhs = true;
    }).catch(function(err) {
        console.warn("Gagal memulai kamera:", err);
        // Fallback jika environment camera tidak ada (misal webcam laptop)
        if (currentFacingMode === "environment") {
            currentFacingMode = "user";
            startMhsScanner();
        }
    });
}

function toggleMhsCamera() {
    currentFacingMode = (currentFacingMode === "environment") ? "user" : "environment";
    startMhsScanner();
}

function restartMhsScanner() {
    startMhsScanner();
}

function onMhsScanSuccess(decodedText, decodedResult) {
    if (!isScanningMhs) return;
    isScanningMhs = false; // pause scanning sementara
    processCertificateCode(decodedText);
}

function submitManualCode() {
    var code = document.getElementById('manualInputMhs').value.trim();
    if (!code) {
        alert("Silakan masukkan nomor sertifikat atau scan QR Code terlebih dahulu.");
        return;
    }
    processCertificateCode(code);
}

function processCertificateCode(rawCode) {
    // Tampilkan loading kecil jika diperlukan
    var url = "<?= BASE_URL ?>/ambil-sertifikat.php?ajax=1";

    var formData = new FormData();
    formData.append('code', rawCode);

    fetch(url, {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (!data.success) {
            playBeep('error');
            alert(data.message || "Terjadi kesalahan saat memverifikasi sertifikat.");
            // Lanjutkan scanner setelah 2 detik
            setTimeout(function() { isScanningMhs = true; }, 2000);
            return;
        }

        // Sukses verifikasi
        playBeep('success');

        var card = document.getElementById('scanResultCard');
        card.style.display = 'block';

        if (data.already_taken) {
            card.className = "result-card already-taken";
            document.getElementById('resultIcon').innerText = "ℹ️";
            document.getElementById('resultTitle').innerText = "Sertifikat Sudah Diambil";
            document.getElementById('resultTitle').style.color = "#1d4ed8";
            document.getElementById('resultSubtitle').innerText = data.message;
        } else {
            card.className = "result-card";
            document.getElementById('resultIcon').innerText = "🎉";
            document.getElementById('resultTitle').innerText = "Pengambilan Berhasil Dikonfirmasi!";
            document.getElementById('resultTitle').style.color = "#15803d";
            document.getElementById('resultSubtitle').innerText = data.message;
        }

        document.getElementById('resNoSert').innerText = data.cert.nomor_sertifikat;
        document.getElementById('resNama').innerText = data.cert.nama_lengkap;
        document.getElementById('resNim').innerText = data.cert.nim;
        document.getElementById('resProdi').innerText = data.cert.program_studi || '-';
        document.getElementById('resWaktu').innerText = data.tanggal || (data.pickup_info ? data.pickup_info.tanggal_pengambilan : 'Hari ini');

        var pickupId = data.pickup_id || 0;
        var btnCetak = document.getElementById('btnCetakTandaTerima');
        if (pickupId > 0) {
            btnCetak.href = "<?= BASE_URL ?>/admin/cetak-tanda-terima-sertifikat.php?id=" + pickupId;
            btnCetak.style.display = 'inline-flex';
        } else {
            btnCetak.href = "<?= BASE_URL ?>/admin/cetak-tanda-terima-sertifikat.php?no=" + encodeURIComponent(data.cert.nomor_sertifikat);
            btnCetak.style.display = 'inline-flex';
        }

        // Scroll halus ke kartu hasil
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // Hentikan sementara kamera agar tidak terus memindai sertifikat yang sama
        try {
            if (html5QrCodeMhs) html5QrCodeMhs.pause();
        } catch(e) {}
    })
    .catch(function(err) {
        console.error(err);
        alert("Gagal memproses kode sertifikat: " + err.message);
        setTimeout(function() { isScanningMhs = true; }, 2000);
    });
}

function resetScannerView() {
    document.getElementById('scanResultCard').style.display = 'none';
    isScanningMhs = true;
    try {
        if (html5QrCodeMhs) html5QrCodeMhs.resume();
    } catch(e) {
        startMhsScanner();
    }
}

// Inisialisasi Scanner saat dokumen siap (Mendukung baik akses langsung maupun navigasi SPA)
document.addEventListener('DOMContentLoaded', function() {
    startMhsScanner();
});
if (document.readyState === 'complete' || document.readyState === 'interactive') {
    startMhsScanner();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
