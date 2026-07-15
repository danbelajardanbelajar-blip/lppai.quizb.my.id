<?php
/**
 * Admin - Absensi Al Khidmah
 */
define('PAGE_TITLE', 'Absensi Al Khidmah');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();

// Ambil data absensi
$stmt = $pdo->query("
    SELECT a.*, u.nama_lengkap, u.program_studi, u.fakultas 
    FROM absensi_alkhidmah a 
    JOIN users u ON a.nim = u.nim 
    ORDER BY a.tanggal DESC, a.created_at DESC
");
$absensiData = $stmt->fetchAll();

// Payload untuk QR Code hari ini
$today = date('Y-m-d');
$qrPayload = json_encode([
    'type' => 'alkhidmah',
    'date' => $today
]);

define('EXTRA_HEAD', '
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
    @media print {
        body * { visibility: hidden; }
        #print-area, #print-area * { visibility: visible; }
        #print-area { position: absolute; left: 0; top: 0; width: 100%; text-align: center; }
        .no-print { display: none !important; }
    }
    .qr-container { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; background: #fff; border-radius: 8px; border: 1px solid #e5e7eb; }
    #qrcode img { margin: 0 auto; }
</style>
');

include __DIR__ . '/../includes/header.php';
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>🕌 QR Code Absensi Al Khidmah Hari Ini (<?= htmlspecialchars(date('d F Y', strtotime($today))) ?>)</span>
        <button class="btn btn-primary no-print" id="btnPrint" onclick="window.print()" style="display:none;">🖨️ Print QR Code</button>
    </div>
    <div class="card-body">
        <div class="text-center no-print" style="margin-bottom: 20px;">
            <button class="btn btn-primary" id="btnGenerate" onclick="generateQRCode()">Generate QR Code</button>
        </div>
        <div id="print-area" class="qr-container" style="display:none;">
            <h2 style="margin-bottom: 20px;">Absensi Al Khidmah</h2>
            <div id="qrcode"></div>
            <p style="margin-top: 20px; color: #6b7280; font-size: 14px;">Silakan scan QR Code ini menggunakan menu Absensi Al Khidmah di akun Mahasiswa Anda.</p>
        </div>
    </div>
</div>

<div class="card no-print">
    <div class="card-header">📋 Data Absensi</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="absensiTable">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Tanggal</th>
                        <th>NIM</th>
                        <th>Nama Mahasiswa</th>
                        <th>Prodi</th>
                        <th>Waktu Hadir</th>
                        <th>Foto Hadir</th>
                        <th>Waktu Pulang</th>
                        <th>Foto Pulang</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($absensiData as $row): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><?= htmlspecialchars($row['tanggal']) ?></td>
                            <td><?= htmlspecialchars($row['nim']) ?></td>
                            <td><?= htmlspecialchars($row['nama_lengkap']) ?></td>
                            <td><?= htmlspecialchars($row['program_studi']) ?></td>
                            <td>
                                <?php if ($row['waktu_hadir']): ?>
                                    <span class="badge" style="background: #10b981; color: white; padding: 4px 8px; border-radius: 4px;"><?= htmlspecialchars($row['waktu_hadir']) ?></span>
                                <?php else: ?>
                                    <span style="color: #ef4444;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['foto_hadir']): ?>
                                    <a href="<?= BASE_URL ?>/<?= htmlspecialchars($row['foto_hadir']) ?>" target="_blank" class="btn btn-sm" style="background:#3b82f6;color:white;padding:2px 8px;font-size:12px;border-radius:4px;text-decoration:none;">Lihat Foto</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['waktu_pulang']): ?>
                                    <span class="badge" style="background: #f59e0b; color: white; padding: 4px 8px; border-radius: 4px;"><?= htmlspecialchars($row['waktu_pulang']) ?></span>
                                <?php else: ?>
                                    <span style="color: #ef4444;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['foto_pulang']): ?>
                                    <a href="<?= BASE_URL ?>/<?= htmlspecialchars($row['foto_pulang']) ?>" target="_blank" class="btn btn-sm" style="background:#3b82f6;color:white;padding:2px 8px;font-size:12px;border-radius:4px;text-decoration:none;">Lihat Foto</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
    $(document).ready(function() {
        // DataTable sudah diinisialisasi secara global oleh app.js
    });

    function generateQRCode() {
        var qrPayload = <?= json_encode($qrPayload) ?>;
        var qrcodeElement = document.getElementById("qrcode");
        
        // Cek jika belum ada gambar di dalamnya
        if (qrcodeElement.innerHTML === "") {
            new QRCode(qrcodeElement, {
                text: qrPayload,
                width: 256,
                height: 256,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.H
            });
        }
        
        document.getElementById('btnGenerate').style.display = 'none';
        document.getElementById('print-area').style.display = 'flex';
        document.getElementById('btnPrint').style.display = 'inline-block';
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
