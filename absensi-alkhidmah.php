<?php
/**
 * LPPAI Corner - Absensi Al Khidmah
 */
define('PAGE_TITLE', 'Absensi Al Khidmah');
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

// Tambahkan library HTML5-QRCode
define('EXTRA_HEAD', '
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<style>
    .scanner-container { max-width: 500px; margin: 0 auto; display: none; }
    #reader { width: 100%; border-radius: 8px; overflow: hidden; }
    .status-box { padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 20px; }
    .status-loading { background-color: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
    .status-error { background-color: #fee2e2; color: #b91c1c; border: 1px solid #f87171; }
    .status-success { background-color: #d1fae5; color: #047857; border: 1px solid #34d399; }
    
    #selfie-container { max-width: 500px; margin: 0 auto; display: none; flex-direction: column; align-items: center; }
    #video-selfie { width: 100%; max-width: 400px; border-radius: 8px; background: #000; transform: scaleX(-1); }
    #canvas-selfie { display: none; }
    .btn-capture { margin-top: 15px; background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; width: 100%; max-width: 400px; }
    .btn-capture:hover { background: #2563eb; }
</style>
');

include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header">🕌 Absensi Al Khidmah</div>
    <div class="card-body">
        
        <div id="status-message" class="status-box status-loading">
            📍 Memeriksa lokasi Anda...
        </div>

        <!-- QR Scanner Container -->
        <div id="scanner-wrapper" class="scanner-container">
            <h4 class="text-center mb-3">1. Scan QR Code</h4>
            <p class="text-center text-muted" style="font-size:14px;">Silakan scan QR Code yang disediakan oleh Admin. Pastikan Anda berada di area absensi.</p>
            <div id="reader"></div>
        </div>

        <!-- Selfie Container -->
        <div id="selfie-container">
            <h4 class="text-center mb-3">2. Ambil Foto Selfie</h4>
            <p class="text-center text-muted" style="font-size:14px;">Posisikan wajah Anda di kamera depan untuk bukti absensi.</p>
            <video id="video-selfie" autoplay playsinline></video>
            <canvas id="canvas-selfie"></canvas>
            <button id="btn-capture" class="btn-capture">📸 Ambil Foto & Kirim Absensi</button>
        </div>

    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const TARGET_LAT = -7.095662;
    const TARGET_LNG = 112.330615;
    const MAX_DISTANCE_METERS = 20;

    const statusMsg = document.getElementById('status-message');
    const scannerWrapper = document.getElementById('scanner-wrapper');
    const selfieContainer = document.getElementById('selfie-container');
    const videoSelfie = document.getElementById('video-selfie');
    const canvasSelfie = document.getElementById('canvas-selfie');
    const btnCapture = document.getElementById('btn-capture');

    let html5QrcodeScanner = null;
    let scannedQrData = null;
    let streamMedia = null;

    // Haversine formula to calculate distance
    function calculateDistance(lat1, lon1, lat2, lon2) {
        var R = 6371e3; // metres
        var φ1 = lat1 * Math.PI/180; // φ, λ in radians
        var φ2 = lat2 * Math.PI/180;
        var Δφ = (lat2-lat1) * Math.PI/180;
        var Δλ = (lon2-lon1) * Math.PI/180;

        var a = Math.sin(Δφ/2) * Math.sin(Δφ/2) +
                Math.cos(φ1) * Math.cos(φ2) *
                Math.sin(Δλ/2) * Math.sin(Δλ/2);
        var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));

        return R * c; // in metres
    }

    function initGeolocation() {
        if (!navigator.geolocation) {
            showError("Browser Anda tidak mendukung Geolocation.");
            return;
        }

        navigator.geolocation.getCurrentPosition(function(position) {
            let lat = position.coords.latitude;
            let lng = position.coords.longitude;
            let distance = calculateDistance(lat, lng, TARGET_LAT, TARGET_LNG);

            if (distance <= MAX_DISTANCE_METERS) {
                showSuccess("Lokasi sesuai! Jarak: " + Math.round(distance) + " meter. Membuka kamera...");
                startScanner();
            } else {
                showError("Anda berada di luar area absensi. Jarak Anda: " + Math.round(distance) + " meter (Maks " + MAX_DISTANCE_METERS + "m).");
            }
        }, function(error) {
            let msg = "";
            switch(error.code) {
                case error.PERMISSION_DENIED: msg = "Akses lokasi ditolak."; break;
                case error.POSITION_UNAVAILABLE: msg = "Informasi lokasi tidak tersedia."; break;
                case error.TIMEOUT: msg = "Waktu pencarian lokasi habis."; break;
                default: msg = "Terjadi kesalahan tidak dikenal saat mengambil lokasi."; break;
            }
            showError("Gagal mendapatkan lokasi: " + msg);
        }, {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 0
        });
    }

    function showSuccess(msg) {
        statusMsg.className = "status-box status-success";
        statusMsg.innerHTML = "✅ " + msg;
    }

    function showError(msg) {
        statusMsg.className = "status-box status-error";
        statusMsg.innerHTML = "❌ " + msg;
    }

    function startScanner() {
        scannerWrapper.style.display = "block";
        html5QrcodeScanner = new Html5Qrcode("reader");
        const config = { fps: 10, qrbox: { width: 250, height: 250 } };
        
        // Use environment facing camera
        html5QrcodeScanner.start({ facingMode: "environment" }, config, onScanSuccess)
        .catch(err => {
            showError("Gagal mengakses kamera belakang: " + err);
        });
    }

    function onScanSuccess(decodedText, decodedResult) {
        // Hentikan scanner setelah berhasil
        if (html5QrcodeScanner) {
            html5QrcodeScanner.stop().then(() => {
                scannerWrapper.style.display = "none";
                scannedQrData = decodedText;
                showSuccess("QR Code berhasil discan! Silakan ambil foto selfie.");
                startSelfieCamera();
            }).catch(err => {
                console.error("Gagal menghentikan scanner", err);
            });
        }
    }

    function startSelfieCamera() {
        selfieContainer.style.display = "flex";
        navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" } })
        .then(function(stream) {
            streamMedia = stream;
            videoSelfie.srcObject = stream;
        })
        .catch(function(err) {
            showError("Gagal mengakses kamera depan untuk selfie: " + err);
        });
    }

    btnCapture.addEventListener('click', function() {
        if (!scannedQrData) {
            alert("Harap scan QR Code terlebih dahulu!");
            return;
        }

        // Disable button to prevent double submit
        btnCapture.disabled = true;
        btnCapture.innerHTML = "⏳ Mengirim Data...";

        // Draw image to canvas
        canvasSelfie.width = videoSelfie.videoWidth;
        canvasSelfie.height = videoSelfie.videoHeight;
        let ctx = canvasSelfie.getContext('2d');
        // If the video is mirrored (transform: scaleX(-1)), we don't necessarily need to mirror the saved image, 
        // but it's often better to save what the user sees or just the raw frame.
        ctx.drawImage(videoSelfie, 0, 0, canvasSelfie.width, canvasSelfie.height);
        
        let base64Foto = canvasSelfie.toDataURL('image/jpeg', 0.8);

        // Send via AJAX
        let formData = new FormData();
        formData.append('qr_data', scannedQrData);
        formData.append('foto', base64Foto);

        fetch('<?= BASE_URL ?>/ajax-alkhidmah.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                showSuccess(data.message);
                selfieContainer.innerHTML = "<div class='alert alert-success' style='text-align:center;'><h4>Selesai!</h4><p>" + data.message + "</p><a href='<?= BASE_URL ?>/dashboard.php' class='btn btn-primary mt-3'>Kembali ke Dashboard</a></div>";
                // Stop camera stream
                if (streamMedia) {
                    streamMedia.getTracks().forEach(track => track.stop());
                }
            } else {
                alert("Error: " + data.message);
                btnCapture.disabled = false;
                btnCapture.innerHTML = "📸 Coba Ambil Foto Lagi";
            }
        })
        .catch(err => {
            alert("Terjadi kesalahan jaringan.");
            console.error(err);
            btnCapture.disabled = false;
            btnCapture.innerHTML = "📸 Coba Ambil Foto Lagi";
        });
    });

    // Start geolocation check
    initGeolocation();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
