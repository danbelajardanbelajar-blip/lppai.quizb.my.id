<?php
/**
 * Admin - Absensi Al Khidmah
 */
define('PAGE_TITLE', 'Absensi Al Khidmah');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();

// Proses Delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // Ambil path foto untuk dihapus dari server
    $stmt = $pdo->prepare("SELECT foto_hadir, foto_pulang FROM absensi_alkhidmah WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    
    if ($row) {
        if (!empty($row['foto_hadir']) && file_exists(__DIR__ . '/../' . $row['foto_hadir'])) {
            unlink(__DIR__ . '/../' . $row['foto_hadir']);
        }
        if (!empty($row['foto_pulang']) && file_exists(__DIR__ . '/../' . $row['foto_pulang'])) {
            unlink(__DIR__ . '/../' . $row['foto_pulang']);
        }
    }
    
    // Hapus record dari database
    $pdo->prepare("DELETE FROM absensi_alkhidmah WHERE id = ?")->execute([$id]);
    header('Location: ' . BASE_URL . '/admin/absensi-alkhidmah.php');
    exit;
}

// Proses Tambah Manual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_manual') {
    $nim = $_POST['nim'] ?? '';
    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
    $waktu_hadir = !empty($_POST['waktu_hadir']) ? $_POST['waktu_hadir'] : null;
    $waktu_pulang = !empty($_POST['waktu_pulang']) ? $_POST['waktu_pulang'] : null;

    if ($nim && $tanggal) {
        $check = $pdo->prepare("SELECT id FROM absensi_alkhidmah WHERE nim = ? AND tanggal = ?");
        $check->execute([$nim, $tanggal]);
        $existing = $check->fetch();

        if ($existing) {
            $pdo->prepare("UPDATE absensi_alkhidmah SET waktu_hadir = COALESCE(?, waktu_hadir), waktu_pulang = COALESCE(?, waktu_pulang) WHERE id = ?")
                ->execute([$waktu_hadir, $waktu_pulang, $existing['id']]);
        } else {
            $pdo->prepare("INSERT INTO absensi_alkhidmah (nim, tanggal, waktu_hadir, waktu_pulang) VALUES (?, ?, ?, ?)")
                ->execute([$nim, $tanggal, $waktu_hadir, $waktu_pulang]);
        }
    }
    header('Location: ' . BASE_URL . '/admin/absensi-alkhidmah.php?success=1');
    exit;
}

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

// Ambil daftar mahasiswa untuk dropdown manual absen
$stmt = $pdo->query("SELECT nim, nama_lengkap, program_studi FROM users WHERE role = 'mahasiswa' ORDER BY nama_lengkap ASC");
$mahasiswaList = $stmt->fetchAll();

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
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <span>📋 Data Absensi (Tampilan Thumbnail)</span>
        <div style="display:flex; gap:10px; align-items:center;">
            <button type="button" class="btn btn-sm btn-success" onclick="openManualAbsenModal()" style="border-radius:20px;">➕ Tambah Manual</button>
            <input type="text" id="searchAbsensi" class="form-control form-control-sm" style="width: 250px; border-radius:20px; padding: 4px 12px;" placeholder="Cari Nama / NIM...">
        </div>
    </div>
    <div class="card-body">
        <div class="row" id="absensiGrid" style="display:flex; flex-wrap:wrap; margin-right:-10px; margin-left:-10px;">
            <?php if(empty($absensiData)): ?>
                <div style="width:100%; text-align:center; padding: 20px; color:#6b7280;">Belum ada data absensi.</div>
            <?php else: ?>
                <?php foreach ($absensiData as $row): ?>
                    <div class="absensi-item" style="width:100%; max-width:33.333%; padding:10px; box-sizing:border-box; min-width:300px;">
                        <div class="card h-100 shadow-sm" style="border: 1px solid #e2e8f0; border-radius:12px; overflow:hidden;">
                            <div class="card-body" style="padding:16px;">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                                    <div>
                                        <h5 class="card-title mb-1 absensi-nama" style="font-size:16px; font-weight:bold; margin:0;"><?= htmlspecialchars($row['nama_lengkap']) ?></h5>
                                        <h6 class="card-subtitle text-muted absensi-nim" style="font-size:13px; margin-top:4px;"><?= htmlspecialchars($row['nim']) ?> - <?= htmlspecialchars($row['program_studi']) ?></h6>
                                    </div>
                                    <span class="badge" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-size:11px; padding:4px 8px; border-radius:6px;"><?= htmlspecialchars($row['tanggal']) ?></span>
                                </div>
                                
                                <div style="display:flex; justify-content:space-between; margin-top:16px;">
                                    <div style="text-align:center; flex:1;">
                                        <div style="font-size:12px; font-weight:600; color:#475569; margin-bottom:8px;">Hadir</div>
                                        <?php if ($row['foto_hadir']): ?>
                                            <a href="<?= BASE_URL ?>/<?= htmlspecialchars($row['foto_hadir']) ?>" target="_blank">
                                                <img src="<?= BASE_URL ?>/<?= htmlspecialchars($row['foto_hadir']) ?>" alt="Hadir" style="width: 90px; height: 90px; object-fit: cover; border-radius: 8px; border: 1px solid #cbd5e1; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                                            </a>
                                        <?php else: ?>
                                            <div style="width:90px; height:90px; margin:0 auto; background:#f8fafc; border-radius:8px; border: 1px dashed #cbd5e1; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:12px;">Kosong</div>
                                        <?php endif; ?>
                                        <div style="margin-top:8px;">
                                            <span style="background: #10b981; color: white; padding: 4px 10px; border-radius: 12px; font-size:11px; font-weight:bold;"><?= $row['waktu_hadir'] ? htmlspecialchars($row['waktu_hadir']) : '-' ?></span>
                                        </div>
                                    </div>
                                    
                                    <div style="text-align:center; flex:1;">
                                        <div style="font-size:12px; font-weight:600; color:#475569; margin-bottom:8px;">Pulang</div>
                                        <?php if ($row['foto_pulang']): ?>
                                            <a href="<?= BASE_URL ?>/<?= htmlspecialchars($row['foto_pulang']) ?>" target="_blank">
                                                <img src="<?= BASE_URL ?>/<?= htmlspecialchars($row['foto_pulang']) ?>" alt="Pulang" style="width: 90px; height: 90px; object-fit: cover; border-radius: 8px; border: 1px solid #cbd5e1; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                                            </a>
                                        <?php else: ?>
                                            <div style="width:90px; height:90px; margin:0 auto; background:#f8fafc; border-radius:8px; border: 1px dashed #cbd5e1; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:12px;">Kosong</div>
                                        <?php endif; ?>
                                        <div style="margin-top:8px;">
                                            <span style="background: #f59e0b; color: white; padding: 4px 10px; border-radius: 12px; font-size:11px; font-weight:bold;"><?= $row['waktu_pulang'] ? htmlspecialchars($row['waktu_pulang']) : '-' ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer" style="background:#fff; border-top:1px solid #f1f5f9; padding:12px; text-align:center;">
                                <a href="<?= BASE_URL ?>/admin/absensi-alkhidmah.php?action=delete&id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-danger" style="font-size:12px; font-weight:500; border-radius:6px; padding:6px 12px; width:100%; display:inline-block;" onclick="return confirm('Apakah Anda yakin ingin menghapus data absen ini? (Foto juga akan terhapus dari server)');">Hapus Data</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Tambah Manual -->
<div id="manualAbsenModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; border-radius:12px; padding:24px; box-shadow:0 4px 6px rgba(0,0,0,0.1); max-height: 90vh; overflow-y: auto;">
        <h3 style="margin-top:0; margin-bottom:20px; font-size:18px; color:#1e293b;">Tambah Absensi Manual</h3>
        
        <form method="POST">
            <input type="hidden" name="action" value="add_manual">
            
            <div class="form-group mb-3">
                <label style="display:block; margin-bottom:8px; font-size:14px; color:#475569; font-weight:bold;">Mahasiswa</label>
                <select name="nim" class="form-control" required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px;">
                    <option value="">-- Pilih Mahasiswa --</option>
                    <?php foreach($mahasiswaList as $m): ?>
                        <option value="<?= htmlspecialchars($m['nim']) ?>"><?= htmlspecialchars($m['nim']) ?> - <?= htmlspecialchars($m['nama_lengkap']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group mb-3">
                <label style="display:block; margin-bottom:8px; font-size:14px; color:#475569; font-weight:bold;">Tanggal</label>
                <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required class="form-control" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px;">
            </div>
            
            <div style="display:flex; gap:15px; margin-bottom:24px;">
                <div class="form-group" style="flex:1;">
                    <label style="display:block; margin-bottom:8px; font-size:14px; color:#475569; font-weight:bold;">Waktu Hadir</label>
                    <input type="time" name="waktu_hadir" class="form-control" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px;">
                    <small style="color:#94a3b8; font-size:11px;">Kosongkan jika belum hadir</small>
                </div>
                <div class="form-group" style="flex:1;">
                    <label style="display:block; margin-bottom:8px; font-size:14px; color:#475569; font-weight:bold;">Waktu Pulang</label>
                    <input type="time" name="waktu_pulang" class="form-control" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px;">
                    <small style="color:#94a3b8; font-size:11px;">Kosongkan jika belum pulang</small>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" class="btn btn-secondary" onclick="closeManualAbsenModal()" style="background:#f1f5f9; color:#475569; border:none; padding:8px 16px; border-radius:8px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding:8px 16px; border-radius:8px;">Simpan Data</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        var searchInput = document.getElementById('searchAbsensi');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                var query = this.value.toLowerCase();
                var items = document.querySelectorAll('.absensi-item');
                items.forEach(function(item) {
                    var nama = item.querySelector('.absensi-nama').textContent.toLowerCase();
                    var nim = item.querySelector('.absensi-nim').textContent.toLowerCase();
                    if (nama.includes(query) || nim.includes(query)) {
                        item.style.display = '';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        }
    });

    function openManualAbsenModal() {
        document.getElementById('manualAbsenModal').style.display = 'flex';
    }

    function closeManualAbsenModal() {
        document.getElementById('manualAbsenModal').style.display = 'none';
    }

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
