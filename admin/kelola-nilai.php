<?php
/**
 * LPPAI Corner - Kelola Nilai Master (Semua Angkatan)
 */
define('PAGE_TITLE', 'Kelola Nilai (Master)');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user = getCurrentUser();
if ($user['role'] !== 'admin') {
    die("Akses ditolak.");
}

$pdo = getDBConnection();
$message = '';
$msgType = '';

// Proses Simpan Nilai
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_nilai') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
        $regId = (int)($_POST['reg_id'] ?? 0);
        
        $thaharah = ($_POST['nilai_thaharah'] !== '') ? (float)$_POST['nilai_thaharah'] : null;
        $shalat = ($_POST['nilai_shalat'] !== '') ? (float)$_POST['nilai_shalat'] : null;
        $srt = ($_POST['nilai_surat_pendek'] !== '') ? (float)$_POST['nilai_surat_pendek'] : null;
        $amaliyah = ($_POST['nilai_amaliyah'] !== '') ? (float)$_POST['nilai_amaliyah'] : null;
        $jenazah = ($_POST['nilai_jenazah'] !== '') ? (float)$_POST['nilai_jenazah'] : null;
        $akhir = ($_POST['nilai_akhir'] !== '') ? (float)$_POST['nilai_akhir'] : null;

        if ($userId > 0) {
            if ($regId > 0) {
                // UPDATE yang sudah ada
                $stmt = $pdo->prepare("
                    UPDATE tutorial_registrations 
                    SET nilai_thaharah=?, nilai_shalat=?, nilai_surat_pendek=?, 
                        nilai_amaliyah=?, nilai_jenazah=?, nilai_akhir=? 
                    WHERE id=?
                ");
                $stmt->execute([$thaharah, $shalat, $srt, $amaliyah, $jenazah, $akhir, $regId]);
                $message = "Nilai mahasiswa berhasil diperbarui.";
                $msgType = "success";
            } else {
                // INSERT baru untuk mahasiswa lawas (belum pernah mendaftar kelas)
                $stmt = $pdo->prepare("
                    INSERT INTO tutorial_registrations 
                    (user_id, status, gelombang, nilai_thaharah, nilai_shalat, nilai_surat_pendek, nilai_amaliyah, nilai_jenazah, nilai_akhir)
                    VALUES (?, 'lulus', 'lawas', ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $thaharah, $shalat, $srt, $amaliyah, $jenazah, $akhir]);
                $message = "Nilai mahasiswa berhasil disimpan (Riwayat baru telah dibuat).";
                $msgType = "success";
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="margin-bottom:20px; padding:15px; border-radius:6px; background-color: <?= $msgType === 'success' ? '#d1fae5' : '#fee2e2' ?>; color: <?= $msgType === 'success' ? '#065f46' : '#991b1b' ?>;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom: 24px;">
    <div class="card-header" style="background-color: #3b82f6; color: white;">📊 Data Nilai Keseluruhan (Master)</div>
    <div class="card-body">
        <p style="margin-top: 0; color: #64748b; font-size: 14px; margin-bottom: 20px;">
            Halaman ini menampilkan <strong>seluruh mahasiswa dari semua angkatan</strong>. Data diproses menggunakan *Server-Side Processing* sehingga aman untuk memuat puluhan ribu data tanpa membebani memori.
        </p>

        <div class="table-responsive">
            <!-- Tambahkan width:100% dan class display no-datatable agar DataTables merender dengan benar tanpa konflik -->
            <table id="tableKelolaNilai" class="display no-datatable" style="width:100%">
                <thead>
                    <tr>
                        <th style="width: 40px;">No</th>
                        <th>NIM</th>
                        <th>Nama Mahasiswa</th>
                        <th>Jurusan</th>
                        <th>Thaharah</th>
                        <th>Shalat</th>
                        <th>Srt Pendek</th>
                        <th>Amaliyah</th>
                        <th>Jenazah</th>
                        <th>Akhir</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<!-- Modal Edit Nilai -->
<div id="modalEditNilai" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.5);">
    <div class="modal-content" style="background-color:#fff; margin:5% auto; padding:24px; border-radius:10px; width:90%; max-width:600px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:#1e293b; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">✏️ Edit Nilai Mahasiswa</h3>
        <form id="formEditNilai" method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_nilai">
            <input type="hidden" name="user_id" id="editUserId">
            <input type="hidden" name="reg_id" id="editRegId">
            
            <div style="background-color:#f1f5f9; padding:12px; border-radius:6px; margin-bottom:16px;">
                <strong>Nama:</strong> <span id="displayNama"></span><br>
                <strong>NIM:</strong> <span id="displayNim"></span>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                <div>
                    <label>Nilai Thaharah</label>
                    <input type="number" step="0.01" name="nilai_thaharah" id="editThaharah" class="form-control">
                </div>
                <div>
                    <label>Nilai Shalat</label>
                    <input type="number" step="0.01" name="nilai_shalat" id="editShalat" class="form-control">
                </div>
                <div>
                    <label>Nilai Surat Pendek</label>
                    <input type="number" step="0.01" name="nilai_surat_pendek" id="editSrt" class="form-control">
                </div>
                <div>
                    <label>Nilai Amaliyah</label>
                    <input type="number" step="0.01" name="nilai_amaliyah" id="editAmaliyah" class="form-control">
                </div>
                <div>
                    <label>Nilai Jenazah</label>
                    <input type="number" step="0.01" name="nilai_jenazah" id="editJenazah" class="form-control">
                </div>
                <div>
                    <label><strong>Nilai Akhir</strong></label>
                    <input type="number" step="0.01" name="nilai_akhir" id="editAkhir" class="form-control" style="background-color:#eff6ff; font-weight:bold;">
                </div>
            </div>

            <div style="display:flex; gap:12px; justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalEditNilai').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary" style="background-color:#3b82f6;">Simpan Nilai</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<script>
$(document).ready(function() {
    // Inisialisasi DataTables Server-Side Processing
    var table = $('#tableKelolaNilai').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax": {
            "url": "<?= BASE_URL ?>/admin/ajax-kelola-nilai.php",
            "type": "POST"
        },
        "pageLength": 25,
        "lengthMenu": [[10, 25, 50, 100, 500], [10, 25, 50, 100, 500]],
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.8/i18n/id.json",
            "processing": "Sedang memuat data..."
        },
        "columnDefs": [
            { "orderable": false, "targets": [0, 10] }, // Disable sorting pada No dan Aksi
            { "className": "text-center", "targets": [4,5,6,7,8,9] }
        ]
    });

    // Event listener untuk tombol Edit (karena data digenerate dinamis, gunakan event delegation)
    $('#tableKelolaNilai tbody').on('click', '.btn-edit-nilai', function() {
        var btn = $(this);
        
        $('#editUserId').val(btn.data('user-id'));
        $('#editRegId').val(btn.data('reg-id'));
        $('#displayNama').text(btn.data('nama'));
        $('#displayNim').text(btn.data('nim'));
        
        $('#editThaharah').val(btn.data('thaharah'));
        $('#editShalat').val(btn.data('shalat'));
        $('#editSrt').val(btn.data('srt'));
        $('#editAmaliyah').val(btn.data('amaliyah'));
        $('#editJenazah').val(btn.data('jenazah'));
        $('#editAkhir').val(btn.data('akhir'));
        
        $('#modalEditNilai').css('display', 'block');
    });

    // Kalkulasi nilai akhir otomatis jika ingin
    function hitungNilaiAkhir() {
        let t = parseFloat($('#editThaharah').val()) || 0;
        let s = parseFloat($('#editShalat').val()) || 0;
        let p = parseFloat($('#editSrt').val()) || 0;
        let a = parseFloat($('#editAmaliyah').val()) || 0;
        let j = parseFloat($('#editJenazah').val()) || 0;
        
        let components = [t, s, p, a, j].filter(v => v > 0);
        if(components.length > 0) {
            let avg = components.reduce((a, b) => a + b, 0) / components.length;
            $('#editAkhir').val(avg.toFixed(2));
        }
    }

    // Bisa diaktifkan jika ingin otomatis terhitung saat diedit
    /*
    $('#editThaharah, #editShalat, #editSrt, #editAmaliyah, #editJenazah').on('input', function() {
        hitungNilaiAkhir();
    });
    */
});

// Menutup modal jika klik di luar
window.onclick = function(event) {
    if (event.target == document.getElementById('modalEditNilai')) {
        document.getElementById('modalEditNilai').style.display = "none";
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
