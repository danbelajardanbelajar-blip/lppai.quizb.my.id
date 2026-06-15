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

// Auto-migrate: add tahun_ajaran if it doesn't exist
try {
    $pdo->query("SELECT tahun_ajaran FROM tutorial_registrations LIMIT 1");
} catch(PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN tahun_ajaran VARCHAR(50) DEFAULT NULL AFTER status");
    } catch(PDOException $ex) {
        // ignore
    }
}


// Proses Simpan Nilai
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_nilai') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
        $regId = (int)($_POST['reg_id'] ?? 0);
        
        $ta = $_POST['tahun_ajaran'] ?? null;
        if ($ta === '') $ta = null;

        $thaharah = ($_POST['nilai_thaharah'] !== '') ? (float)$_POST['nilai_thaharah'] : null;
        $shalat = ($_POST['nilai_shalat'] !== '') ? (float)$_POST['nilai_shalat'] : null;
        $srt = ($_POST['nilai_surat_pendek'] !== '') ? (float)$_POST['nilai_surat_pendek'] : null;
        $amaliyah = ($_POST['nilai_amaliyah'] !== '') ? (float)$_POST['nilai_amaliyah'] : null;
        $jenazah = ($_POST['nilai_jenazah'] !== '') ? (float)$_POST['nilai_jenazah'] : null;
        $ut = ($_POST['nilai_ujian_tulis'] !== '') ? (float)$_POST['nilai_ujian_tulis'] : null;

        if ($userId > 0) {
            if ($regId > 0) {
                // UPDATE yang sudah ada
                $stmt = $pdo->prepare("
                    UPDATE tutorial_registrations 
                    SET tahun_ajaran=?, nilai_thaharah=?, nilai_shalat=?, nilai_surat_pendek=?, 
                        nilai_amaliyah=?, nilai_jenazah=?, nilai_ujian_tulis=? 
                    WHERE id=?
                ");
                $stmt->execute([$ta, $thaharah, $shalat, $srt, $amaliyah, $jenazah, $ut, $regId]);
                $message = "Nilai mahasiswa berhasil diperbarui.";
                $msgType = "success";
            } else {
                // INSERT baru untuk mahasiswa lawas (belum pernah mendaftar kelas)
                $stmt = $pdo->prepare("
                    INSERT INTO tutorial_registrations 
                    (user_id, status, gelombang, tahun_ajaran, nilai_thaharah, nilai_shalat, nilai_surat_pendek, nilai_amaliyah, nilai_jenazah, nilai_ujian_tulis)
                    VALUES (?, 'lulus', 'lawas', ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $ta, $thaharah, $shalat, $srt, $amaliyah, $jenazah, $ut]);
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
    <div class="card-header" style="background-color: #3b82f6; color: white; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <span>📊 Data Nilai Keseluruhan (Master)</span>
        <div>
            <a href="<?= BASE_URL ?>/admin/download-template-nilai.php" class="btn btn-sm" style="background-color: white; color: #3b82f6; font-weight: 600; border: none; padding: 5px 12px; border-radius: 4px; text-decoration: none;" data-no-spa="true">📄 Download Template</a>
            <button type="button" class="btn btn-sm btn-warning" style="font-weight: 600;" onclick="document.getElementById('importModal').style.display='block'">📥 Import Nilai Excel</button>
        </div>
    </div>
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
                        <th>Tempat Lahir</th>
                        <th>Tanggal Lahir</th>
                        <th>Tahun Ajaran</th>
                        <th>Thaharah</th>
                        <th>Shalat</th>
                        <th>Srt Pendek</th>
                        <th>Amaliyah</th>
                        <th>Jenazah</th>
                        <th>UT</th>
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
            
            <div style="margin-bottom: 16px;">
                <label><strong>Tahun Ajaran</strong></label>
                <select name="tahun_ajaran" id="editTa" class="form-control">
                    <option value="">-- Kosong --</option>
                    <?php
                    $startYear = 2017;
                    $endYear = 2050;
                    for ($y = $startYear; $y < $endYear; $y++) {
                        $label = $y . '-' . ($y + 1);
                        echo "<option value=\"$label\">$label</option>";
                    }
                    ?>
                </select>
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
                    <label>Ujian Tulis</label>
                    <input type="number" step="0.01" name="nilai_ujian_tulis" id="editUt" class="form-control">
                </div>
                <div style="grid-column: span 2;">
                    <label><strong>Nilai Akhir (Otomatis)</strong></label>
                    <input type="number" step="0.01" id="editAkhir" class="form-control" style="background-color:#eff6ff; font-weight:bold;" readonly>
                </div>
            </div>

            <div style="display:flex; gap:12px; justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalEditNilai').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary" style="background-color:#3b82f6;">Simpan Nilai</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Import -->
<div id="importModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;">
    <div style="background:#fff; margin:10% auto; padding:24px; border-radius:8px; width:90%; max-width:400px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0;">Import Nilai via Excel</h3>
        <p style="font-size:14px; color:#64748b; margin-bottom: 20px;">Pastikan Anda menggunakan template Excel terbaru yang diunduh dari halaman ini agar formatnya sesuai.</p>
        
        <form id="formImport">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div style="margin-bottom: 16px;">
                <label><strong>Pilih File (.xls / .xlsx)</strong></label>
                <input type="file" name="csv_file" class="form-control" accept=".xls,.xlsx" required>
            </div>
            
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('importModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-success" id="btnProsesImport">Proses Import</button>
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
            { "orderable": false, "targets": [0, 14] }, // Disable sorting pada No dan Aksi
            { "className": "text-center", "targets": [4,5,6,7,8,9,10,11,12,13,14] }
        ]
    });

    // Event listener untuk tombol Edit (karena data digenerate dinamis, gunakan event delegation)
    $('#tableKelolaNilai tbody').on('click', '.btn-edit-nilai', function() {
        var btn = $(this);
        
        $('#editUserId').val(btn.data('user-id'));
        $('#editRegId').val(btn.data('reg-id'));
        $('#displayNama').text(btn.data('nama'));
        $('#displayNim').text(btn.data('nim'));
        $('#editTa').val(btn.data('ta'));
        
        $('#editThaharah').val(btn.data('thaharah'));
        $('#editShalat').val(btn.data('shalat'));
        $('#editSrt').val(btn.data('srt'));
        $('#editAmaliyah').val(btn.data('amaliyah'));
        $('#editJenazah').val(btn.data('jenazah'));
        $('#editUt').val(btn.data('ut'));
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
        let ut = parseFloat($('#editUt').val()) || 0;
        
        let components = [t, s, p, a, j, ut].filter(v => v > 0);
        if(components.length > 0) {
            let avg = components.reduce((a, b) => a + b, 0) / components.length;
            $('#editAkhir').val(avg.toFixed(2));
        }
    }

    // Bisa diaktifkan jika ingin otomatis terhitung saat diedit
    /*
    $('#editThaharah, #editShalat, #editSrt, #editAmaliyah, #editJenazah, #editUt').on('input', function() {
        hitungNilaiAkhir();
    });
    */

    // Menutup modal jika klik di luar
    window.onclick = function(event) {
        if (event.target == document.getElementById('modalEditNilai')) {
            document.getElementById('modalEditNilai').style.display = "none";
        }
    }
    
    // Form import AJAX handler
    $('#formImport').on('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const btn = $('#btnProsesImport');
        btn.prop('disabled', true).text('Memproses...');
        
        $.ajax({
            url: '<?= BASE_URL ?>/admin/ajax-import-nilai.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                btn.prop('disabled', false).text('Proses Import');
                if (res.success) {
                    alert('Import Berhasil!\n' + res.message);
                    document.getElementById('importModal').style.display='none';
                    $('#tableKelolaNilai').DataTable().ajax.reload(null, false);
                } else {
                    alert('Gagal: ' + res.message);
                }
            },
            error: function(err) {
                btn.prop('disabled', false).text('Proses Import');
                alert('Terjadi kesalahan koneksi saat import.');
                console.error(err);
            }
        });
    });

});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
