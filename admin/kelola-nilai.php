<?php
/**
 * LPPAI Corner - Kelola Nilai Lama (Semua Angkatan)
 */
define('PAGE_TITLE', 'Kelola Nilai (Lama)');
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

// Auto-migrate: add tipe_nilai if it doesn't exist
try {
    $pdo->query("SELECT tipe_nilai FROM tutorial_registrations LIMIT 1");
} catch(PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN tipe_nilai VARCHAR(50) DEFAULT NULL AFTER tahun_ajaran");
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
        
        $tipe = $_POST['tipe_nilai'] ?? null;
        if ($tipe === '') $tipe = null;

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
                    SET tahun_ajaran=?, tipe_nilai=?, nilai_thaharah=?, nilai_shalat=?, nilai_surat_pendek=?, 
                        nilai_amaliyah=?, nilai_jenazah=?, nilai_ujian_tulis=? 
                    WHERE id=?
                ");
                $stmt->execute([$ta, $tipe, $thaharah, $shalat, $srt, $amaliyah, $jenazah, $ut, $regId]);
                $message = "Nilai mahasiswa berhasil diperbarui.";
                $msgType = "success";
            } else {
                // INSERT baru untuk mahasiswa lawas (belum pernah mendaftar kelas)
                $stmt = $pdo->prepare("
                    INSERT INTO tutorial_registrations 
                    (user_id, status, gelombang, tahun_ajaran, tipe_nilai, nilai_thaharah, nilai_shalat, nilai_surat_pendek, nilai_amaliyah, nilai_jenazah, nilai_ujian_tulis)
                    VALUES (?, 'lulus', 'lawas', ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $ta, $tipe, $thaharah, $shalat, $srt, $amaliyah, $jenazah, $ut]);
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
        <span>📊 Data Nilai Lama (Di bawah 2026)</span>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <button type="button" class="btn btn-sm btn-danger" id="btnHapusTerpilih" style="display:none; font-weight: 600;">🗑️ Hapus Terpilih</button>
            <a href="<?= BASE_URL ?>/admin/download-template-nilai.php" class="btn btn-sm" style="background-color: white; color: #3b82f6; font-weight: 600; border: none; padding: 5px 12px; border-radius: 4px; text-decoration: none;" data-no-spa="true">📄 Download Template</a>
            <button type="button" class="btn btn-sm btn-warning" style="font-weight: 600;" onclick="document.getElementById('importModal').style.display='block'">📥 Import Nilai Excel</button>
        </div>
    </div>
    <div class="card-body">
        <p style="margin-top: 0; color: #64748b; font-size: 14px; margin-bottom: 20px;">
            Halaman ini khusus menampilkan <strong>mahasiswa dengan periode lama (di bawah 2026/2027)</strong>.
        </p>

        <style>
            .premium-filter {
                width: 100%;
                padding: 8px 12px;
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                background-color: #f8fafc;
                color: #334155;
                font-size: 14px;
                transition: all 0.2s ease-in-out;
                appearance: none;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-position: right 10px center;
                background-size: 16px;
                cursor: pointer;
            }
            .premium-filter:focus {
                outline: none;
                border-color: #3b82f6;
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
                background-color: #ffffff;
            }
            .filter-label {
                display: block;
                font-size: 13px;
                font-weight: 600;
                color: #475569;
                margin-bottom: 6px;
            }
        </style>

        <div style="background-color: #f1f5f9; padding: 16px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #e2e8f0;">
            <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 180px;">
                    <label class="filter-label">📅 Tahun Ajaran</label>
                    <select id="filterTahunAjaran" class="premium-filter">
                        <option value="">Semua Tahun</option>
                        <?php
                        $startYear = 2017;
                        $endYear = 2025;
                        for ($y = $startYear; $y <= $endYear; $y++) {
                            $label = $y . '-' . ($y + 1);
                            echo "<option value=\"$label\">$label</option>";
                        }
                        ?>
                    </select>
                </div>
                <div style="flex: 1; min-width: 180px;">
                    <label class="filter-label">📋 Type Nilai</label>
                    <select id="filterTipeNilai" class="premium-filter">
                        <option value="">Semua Type</option>
                        <option value="pretest">Pretest</option>
                        <option value="gel 1">Gel 1</option>
                        <option value="gel 2">Gel 2</option>
                        <option value="mandiri">Mandiri</option>
                    </select>
                </div>
                <div style="flex: 1; min-width: 180px;">
                    <label class="filter-label">🎓 Jurusan</label>
                    <select id="filterJurusan" class="premium-filter">
                        <option value="">Semua Jurusan</option>
                        <?php
                        // Ambil daftar jurusan unik dari database
                        $stmtJurusan = $pdo->query("SELECT DISTINCT program_studi FROM users WHERE role = 'mahasiswa' AND program_studi IS NOT NULL AND program_studi != '' ORDER BY program_studi");
                        while ($jur = $stmtJurusan->fetch(PDO::FETCH_ASSOC)) {
                            echo "<option value=\"" . htmlspecialchars($jur['program_studi']) . "\">" . htmlspecialchars($jur['program_studi']) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div style="flex: 1; min-width: 180px;">
                    <label class="filter-label">🎓 Status Kelulusan</label>
                    <select id="filterLulus" class="premium-filter">
                        <option value="">Semua Status</option>
                        <option value="lulus">Lulus</option>
                        <option value="tidak_lulus">Tidak Lulus</option>
                        <option value="belum_lengkap">Belum Lengkap</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <!-- Tambahkan width:100% dan class display no-datatable agar DataTables merender dengan benar tanpa konflik -->
            <table id="tableKelolaNilai" class="display no-datatable" style="width:100%">
                <thead>
                    <tr>
                        <th style="width: 30px; text-align: center;"><input type="checkbox" id="checkAll"></th>
                        <th style="width: 40px;">No</th>
                        <th>NIM</th>
                        <th>Nama Mahasiswa</th>
                        <th>Jurusan</th>
                        <th>Tahun Ajaran</th>
                        <th>Tipe</th>
                        <th title="Nilai Thaharah">Thah</th>
                        <th title="Nilai Shalat">Shlt</th>
                        <th title="Nilai Surat Pendek">SP</th>
                        <th title="Nilai Amaliyah">Amal</th>
                        <th title="Nilai Jenazah">Jnz</th>
                        <th title="Ujian Tulis">UT</th>
                        <th title="Nilai Akhir">NA</th>
                        <th>Kelulusan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<!-- Modal Edit Nilai -->
<div id="modalEditNilai" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.5); backdrop-filter: blur(4px); transition: all 0.3s ease;">
    <div class="modal-content" style="background-color:#ffffff; margin:5% auto; padding:0; border-radius:12px; width:90%; max-width:650px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); overflow: hidden; border: 1px solid #e2e8f0; animation: modalFadeIn 0.3s ease-out;">
        
        <style>
            @keyframes modalFadeIn {
                from { opacity: 0; transform: translateY(-20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .premium-input {
                width: 100%;
                padding: 10px 12px;
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                background-color: #f8fafc;
                color: #334155;
                font-size: 14px;
                font-weight: 500;
                transition: all 0.2s ease-in-out;
            }
            .premium-input:focus {
                outline: none;
                border-color: #3b82f6;
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
                background-color: #ffffff;
            }
        </style>

        <!-- Modal Header -->
        <div style="background-color: #3b82f6; padding: 18px 24px; color: white; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 18px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                Edit Nilai Mahasiswa
            </h3>
            <button type="button" onclick="document.getElementById('modalEditNilai').style.display='none'" style="background: transparent; border: none; color: white; font-size: 24px; cursor: pointer; line-height: 1; padding: 0; opacity: 0.8; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.8'">&times;</button>
        </div>

        <form id="formEditNilai" method="POST" style="padding: 24px;">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_nilai">
            <input type="hidden" name="user_id" id="editUserId">
            <input type="hidden" name="reg_id" id="editRegId">
            
            <div style="background-color:#f8fafc; padding:16px; border-radius:8px; margin-bottom:24px; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 16px;">
                <div style="background-color: #eff6ff; width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #3b82f6;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                </div>
                <div>
                    <div style="font-size: 16px; font-weight: 700; color: #1e293b;" id="displayNama"></div>
                    <div style="font-size: 14px; color: #64748b; font-weight: 500;" id="displayNim"></div>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">📅 Tahun Ajaran</label>
                    <select name="tahun_ajaran" id="editTa" class="premium-filter" style="width: 100%;">
                        <option value="">-- Kosong --</option>
                        <?php
                        $startYear = 2017;
                        $endYear = 2025;
                        for ($y = $startYear; $y <= $endYear; $y++) {
                            $label = $y . '-' . ($y + 1);
                            echo "<option value=\"$label\">$label</option>";
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">📋 Type Nilai</label>
                    <select name="tipe_nilai" id="editTipe" class="premium-filter" style="width: 100%;">
                        <option value="">-- Kosong --</option>
                        <option value="pretest">Pretest</option>
                        <option value="gel 1">Gel 1</option>
                        <option value="gel 2">Gel 2</option>
                        <option value="mandiri">Mandiri</option>
                    </select>
                </div>
            </div>

            <div style="border-top: 1px dashed #cbd5e1; margin: 24px 0;"></div>
            <h4 style="margin-top: 0; margin-bottom: 20px; color: #1e293b; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20v-6M6 20V10M18 20V4"></path></svg>
                Komponen Nilai
            </h4>

            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 28px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 6px;">Thaharah</label>
                    <input type="number" step="0.01" name="nilai_thaharah" id="editThaharah" class="premium-input" style="text-align: center; padding: 12px 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 6px;">Shalat</label>
                    <input type="number" step="0.01" name="nilai_shalat" id="editShalat" class="premium-input" style="text-align: center; padding: 12px 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 6px;">Surat Pendek</label>
                    <input type="number" step="0.01" name="nilai_surat_pendek" id="editSrt" class="premium-input" style="text-align: center; padding: 12px 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 6px;">Amaliyah</label>
                    <input type="number" step="0.01" name="nilai_amaliyah" id="editAmaliyah" class="premium-input" style="text-align: center; padding: 12px 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 6px;">Jenazah</label>
                    <input type="number" step="0.01" name="nilai_jenazah" id="editJenazah" class="premium-input" style="text-align: center; padding: 12px 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 6px;">Ujian Tulis</label>
                    <input type="number" step="0.01" name="nilai_ujian_tulis" id="editUt" class="premium-input" style="text-align: center; padding: 12px 8px;">
                </div>
            </div>

            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 16px 20px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px;">
                <span style="font-weight: 600; color: #475569; font-size: 15px;">Nilai Akhir (Otomatis)</span>
                <input type="number" step="0.01" id="editAkhir" class="premium-input" style="width: 120px; text-align: center; background-color:#eff6ff; font-weight:bold; color: #1d4ed8; border-color: #bfdbfe; font-size: 18px; padding: 8px;" readonly>
            </div>

            <div style="display:flex; gap:12px; justify-content:flex-end;">
                <button type="button" class="btn" style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-weight: 600; padding: 10px 20px; border-radius: 6px; transition: background 0.2s;" onclick="document.getElementById('modalEditNilai').style.display='none'" onmouseover="this.style.backgroundColor='#e2e8f0'" onmouseout="this.style.backgroundColor='#f1f5f9'">Batal</button>
                <button type="submit" class="btn" style="background-color: #3b82f6; color: white; border: none; font-weight: 600; padding: 10px 24px; border-radius: 6px; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3); transition: background 0.2s;" onmouseover="this.style.backgroundColor='#2563eb'" onmouseout="this.style.backgroundColor='#3b82f6'">💾 Simpan Nilai</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Import -->
<div id="importModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1050; overflow-y:auto;">
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

<!-- Loading Overlay -->
<div id="loadingOverlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:99999; flex-direction:column; align-items:center; justify-content:center; color:#fff;">
    <div style="border: 4px solid rgba(255,255,255,0.3); border-radius: 50%; border-top: 4px solid #fff; width: 50px; height: 50px; animation: spin 1s linear infinite;"></div>
    <h3 style="margin-top:20px; font-weight:600; color:#fff;">Sedang Memproses Data...</h3>
    <p>Mohon jangan menutup halaman ini.</p>
    <style>
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Inisialisasi DataTables Server-Side Processing
    var table = $('#tableKelolaNilai').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax": {
            "url": "<?= BASE_URL ?>/admin/ajax-kelola-nilai.php",
            "type": "POST",
            "data": function(d) {
                d.filterTahunAjaran = $('#filterTahunAjaran').val();
                d.filterTipeNilai = $('#filterTipeNilai').val();
                d.filterJurusan = $('#filterJurusan').val();
                d.filterLulus = $('#filterLulus').val();
            }
        },
        "pageLength": 10,
        "lengthMenu": [[10, 25, 50, 100, 500], [10, 25, 50, 100, 500]],
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.8/i18n/id.json",
            "processing": "Sedang memuat data..."
        },
        "columnDefs": [
            { "orderable": false, "targets": [0, 1, 15] }, // Disable sorting pada Checkbox, No dan Aksi
            { "className": "text-center", "targets": [0, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15] }
        ]
    });

    // Event listener untuk filter dropdown
    $('#filterTahunAjaran, #filterTipeNilai, #filterJurusan, #filterLulus').on('change', function() {
        table.ajax.reload();
    });

    // Check All handler
    $('#checkAll').on('change', function() {
        $('.check-item').prop('checked', this.checked);
        toggleHapusTerpilih();
    });

    // Individual Checkbox handler
    $('#tableKelolaNilai tbody').on('change', '.check-item', function() {
        var totalCheckboxes = $('.check-item').length;
        var totalChecked = $('.check-item:checked').length;
        $('#checkAll').prop('checked', totalCheckboxes === totalChecked && totalCheckboxes > 0);
        toggleHapusTerpilih();
    });

    function toggleHapusTerpilih() {
        if ($('.check-item:checked').length > 0) {
            $('#btnHapusTerpilih').css('display', 'inline-block');
        } else {
            $('#btnHapusTerpilih').css('display', 'none');
        }
    }

    // Event listener saat ganti halaman pagination, unchecked "Check All"
    table.on('draw', function() {
        $('#checkAll').prop('checked', false);
        toggleHapusTerpilih();
    });

    // Delete Individual
    $('#tableKelolaNilai tbody').on('click', '.btn-delete-nilai', function() {
        var regId = $(this).data('reg-id');
        if (!regId || regId === 0) {
            Swal.fire('Info', 'Data belum memiliki riwayat nilai/registrasi.', 'info');
            return;
        }

        Swal.fire({
            title: 'Hapus Data Nilai?',
            text: "Data yang dihapus tidak dapat dikembalikan!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '<?= BASE_URL ?>/admin/ajax-delete-nilai.php',
                    type: 'POST',
                    data: {
                        action: 'delete',
                        reg_id: regId,
                        csrf_token: '<?= csrfToken() ?>'
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire('Terhapus!', res.message, 'success');
                            table.ajax.reload(null, false);
                        } else {
                            Swal.fire('Gagal!', res.message, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error!', 'Terjadi kesalahan koneksi.', 'error');
                    }
                });
            }
        });
    });

    // Delete Bulk
    $('#btnHapusTerpilih').on('click', function() {
        var selectedIds = [];
        $('.check-item:checked').each(function() {
            var val = $(this).val();
            if (val && val != 0) {
                selectedIds.push(val);
            }
        });

        if (selectedIds.length === 0) {
            Swal.fire('Info', 'Pilih minimal satu data yang memiliki nilai untuk dihapus.', 'info');
            return;
        }

        Swal.fire({
            title: 'Hapus ' + selectedIds.length + ' Data?',
            text: "Data yang dipilih akan dihapus permanen!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus Semua!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '<?= BASE_URL ?>/admin/ajax-delete-nilai.php',
                    type: 'POST',
                    data: {
                        action: 'delete_bulk',
                        reg_ids: selectedIds,
                        csrf_token: '<?= csrfToken() ?>'
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire('Terhapus!', res.message, 'success');
                            table.ajax.reload(null, false);
                            $('#checkAll').prop('checked', false);
                            toggleHapusTerpilih();
                        } else {
                            Swal.fire('Gagal!', res.message, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error!', 'Terjadi kesalahan koneksi.', 'error');
                    }
                });
            }
        });
    });

    // Event listener untuk tombol Edit (karena data digenerate dinamis, gunakan event delegation)
    $('#tableKelolaNilai tbody').on('click', '.btn-edit-nilai', function() {
        var btn = $(this);
        
        $('#editUserId').val(btn.data('user-id'));
        $('#editRegId').val(btn.data('reg-id'));
        $('#displayNama').text(btn.data('nama'));
        $('#displayNim').text(btn.data('nim'));
        $('#editTa').val(btn.data('ta'));
        $('#editTipe').val(btn.data('tipe'));
        
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
        $('#loadingOverlay').css('display', 'flex');
        
        $.ajax({
            url: '<?= BASE_URL ?>/admin/ajax-import-nilai.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                $('#loadingOverlay').css('display', 'none');
                btn.prop('disabled', false).text('Proses Import');
                
                // Cek jika response berupa string JSON (misal tidak di-parse otomatis)
                if (typeof res === 'string') {
                    try {
                        res = JSON.parse(res);
                    } catch(e) {
                        console.error('Invalid JSON response:', res);
                    }
                }

                if (res.success) {
                    document.getElementById('importModal').style.display='none';
                    $('#formImport')[0].reset();
                    $('#tableKelolaNilai').DataTable().ajax.reload(null, false);
                    Swal.fire({
                        title: 'Import Selesai!',
                        html: res.message.replace(/\n/g, '<br>'),
                        icon: 'success',
                        confirmButtonText: 'Tutup'
                    });
                } else {
                    document.getElementById('importModal').style.display='none';
                    Swal.fire({
                        title: 'Gagal!',
                        html: (res.message || 'Terjadi kesalahan tidak diketahui.').replace(/\n/g, '<br>'),
                        icon: 'error',
                        confirmButtonText: 'Tutup'
                    });
                }
            },
            error: function(xhr, status, error) {
                $('#loadingOverlay').css('display', 'none');
                btn.prop('disabled', false).text('Proses Import');
                document.getElementById('importModal').style.display='none';
                
                let errText = 'Error: ' + status + ' - ' + error;
                if (xhr.responseText) {
                    errText += '<br><br>Response:<br><div style="text-align:left;max-height:200px;overflow:auto;background:#f8f9fa;padding:10px;font-size:12px;">' + xhr.responseText.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
                }
                
                Swal.fire({
                    title: 'Kesalahan Server',
                    html: errText,
                    icon: 'error',
                    confirmButtonText: 'Tutup'
                });
                console.error(xhr.responseText);
            }
        });
    });

});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
