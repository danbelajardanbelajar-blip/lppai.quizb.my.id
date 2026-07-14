<?php
/**
 * LPPAI Corner - Absensi Tutorial
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

include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header" style="background:#10b981; color:white;">📷 Scan QR Code Absensi</div>
    <div class="card-body">
        <p style="text-align: center; color: #4b5563;">Arahkan kamera Anda ke QR Code yang ditampilkan oleh dosen di depan kelas.</p>
        
        <div id="reader-container" style="max-width: 500px; margin: 0 auto;">
            <div id="reader" style="width: 100%;"></div>
        </div>

        <div id="result-container" style="display: none; text-align: center; margin-top: 20px;">
            <div class="alert alert-success" id="result-message"></div>
            <button class="btn btn-primary" onclick="window.location.reload()">Scan Ulang</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    var html5QrcodeScanner = new Html5QrcodeScanner(
        "reader", { fps: 10, qrbox: {width: 250, height: 250}, aspectRatio: 1.0 }, false);

    function onScanSuccess(decodedText, decodedResult) {
        // Stop scanning after success
        html5QrcodeScanner.clear();
        
        document.getElementById('reader-container').style.display = 'none';
        
        Swal.fire({
            title: 'Memproses...',
            text: 'Sedang merekam absensi Anda.',
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
                        title: 'Berhasil!',
                        text: res.message
                    });
                    document.getElementById('result-container').style.display = 'block';
                    document.getElementById('result-message').innerHTML = '<strong>Sukses:</strong> ' + res.message;
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal!',
                        text: res.message
                    }).then(() => {
                        window.location.reload();
                    });
                }
            },
            error: function() {
                Swal.fire('Error', 'Terjadi kesalahan saat menghubungi server.', 'error').then(() => {
                    window.location.reload();
                });
            }
        });
    }

    function onScanFailure(error) {
        // handle scan failure, usually better to ignore and keep scanning.
    }

    html5QrcodeScanner.render(onScanSuccess, onScanFailure);
</script>
