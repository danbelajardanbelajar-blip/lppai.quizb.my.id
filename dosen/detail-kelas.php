<?php
/**
 * LPPAI Corner - Detail Kelas Dosen
 */
define('PAGE_TITLE', 'Detail & Kelola Kelas');
require_once __DIR__ . '/../includes/auth.php';
requireDosen();

$user = getCurrentUser();
$pdo = getDBConnection();

$class_id = (int)($_GET['id'] ?? 0);
if ($class_id <= 0) {
    header('Location: ' . BASE_URL . '/dosen/kelas.php');
    exit;
}

// Get class info and verify ownership
$stmt = $pdo->prepare("SELECT * FROM tutorial_classes WHERE id = ? AND dosen_pengampu = ?");
$stmt->execute([$class_id, $user['nama_lengkap']]);
$kelas = $stmt->fetch();

if (!$kelas) {
    die("Akses ditolak atau kelas tidak ditemukan.");
}

$message = '';
$msgType = '';

$materi_list = [
    'thaharah' => [
        't1' => 'Wudhu (Tes Lisan)', 't2' => 'Tayamum (Tes Lisan)', 't3' => 'Mandi Besar (Tes Lisan)',
        't4' => 'Tata Cara Wudhu (Praktek)', 't5' => "Do'a Setelah Wudhu (Tes Lisan)", 't6' => 'Tata Cara Tayamum (Praktek)',
        't7' => 'Niat Mandi Besar (Tes Lisan)'
    ],
    'shalat' => [
        's1' => 'Shalat Fardhu', 's2' => "Niat Shalat Jama' dan Qashar", 's3' => 'Tata Cara Shalat Subuh dan Qunut'
    ],
    'surat_pendek' => [
        'sp1' => 'Surat Ad-Dhuha', 'sp2' => 'Surat As-Syarh', 'sp3' => 'Surat At-Tiin', 'sp4' => "Surat Al-'Alaq", 'sp5' => 'Surat Al-Qadr',
        'sp6' => 'Surat Al-Bayyinah', 'sp7' => 'Surat Al-Zalzalah', 'sp8' => "Surat Al-'Adiyat", 'sp9' => "Surat Al-Qori'ah", 'sp10' => 'Surat At-Takaatsur',
        'sp11' => "Surat Al-'Ashr", 'sp12' => 'Surat Al-Humazah', 'sp13' => 'Surat Al-Fiil', 'sp14' => 'Surat Al-Quraisy', 'sp15' => "Surat Al-Maa'uun",
        'sp16' => 'Surat Al-Kautsar', 'sp17' => 'Surat Al-Kaafirun', 'sp18' => 'Surat An-Nashr', 'sp19' => 'Surat Al-Lahab', 'sp20' => 'Surat Al-Ikhlas',
        'sp21' => 'Surat Al-Falaq', 'sp22' => 'Surat An-Naas'
    ],
    'amaliyah' => [
        'a1' => 'Tahlil', 'a2' => 'Istighotsah'
    ],
    'jenazah' => [
        'j1' => 'Merawat Jenazah', 'j2' => 'Tata Cara Shalat Jenazah', 'j3' => 'Tata Cara Mengkafani Jenazah', 'j4' => 'Tata Cara Memandikan Jenazah'
    ]
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        // Simpan Nilai Detail
        if ($action === 'save_nilai_detail') {
            $reg_id = (int)($_POST['reg_id'] ?? 0);
            $detail = $_POST['detail'] ?? [];
            
            function calcAvg($detail, $keys) {
                $sum = 0; $has_val = false;
                foreach($keys as $k) {
                    if(isset($detail[$k]) && $detail[$k] !== '') {
                        $sum += (float)$detail[$k];
                        $has_val = true;
                    }
                }
                return $has_val ? ($sum / count($keys)) : null;
            }

            $avg_th = calcAvg($detail, array_keys($materi_list['thaharah']));
            $avg_sh = calcAvg($detail, array_keys($materi_list['shalat']));
            $avg_sp = calcAvg($detail, array_keys($materi_list['surat_pendek']));
            $avg_am = calcAvg($detail, array_keys($materi_list['amaliyah']));
            $avg_jz = calcAvg($detail, array_keys($materi_list['jenazah']));

            // Calculate akhir
            $sum_akhir = 0; $count_akhir = 0;
            if($avg_th !== null) { $sum_akhir += $avg_th; $count_akhir++; }
            if($avg_sh !== null) { $sum_akhir += $avg_sh; $count_akhir++; }
            if($avg_sp !== null) { $sum_akhir += $avg_sp; $count_akhir++; }
            if($avg_am !== null) { $sum_akhir += $avg_am; $count_akhir++; }
            if($avg_jz !== null) { $sum_akhir += $avg_jz; $count_akhir++; }
            $avg_akhir = $count_akhir > 0 ? ($sum_akhir / $count_akhir) : null;

            try {
                $json_detail = json_encode($detail);
                $updateStmt = $pdo->prepare("UPDATE tutorial_registrations SET nilai_detail=?, nilai_thaharah=?, nilai_shalat=?, nilai_surat_pendek=?, nilai_amaliyah=?, nilai_jenazah=?, nilai_akhir=? WHERE id=? AND tutorial_class_id=?");
                $updateStmt->execute([$json_detail, $avg_th, $avg_sh, $avg_sp, $avg_am, $avg_jz, $avg_akhir, $reg_id, $class_id]);
                
                $message = 'Nilai rincian berhasil disimpan.';
                $msgType = 'success';
            } catch (Exception $e) {
                $message = 'Gagal menyimpan nilai. Error: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
        
        // Simpan Absensi
        elseif ($action === 'save_absensi') {
            $pertemuan_ke = (int)($_POST['pertemuan_ke'] ?? 0);
            $tanggal = $_POST['tanggal'] ?? '';
            $status_data = $_POST['status_hadir'] ?? []; 

            if ($pertemuan_ke <= 0 || empty($tanggal)) {
                $message = 'Pertemuan Ke dan Tanggal harus diisi.';
                $msgType = 'danger';
            } else {
                try {
                    $pdo->beginTransaction();
                    $insertStmt = $pdo->prepare("INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status_hadir = ?, tanggal = ?");
                    foreach ($status_data as $user_id => $status) {
                        $insertStmt->execute([$class_id, $user_id, $pertemuan_ke, $tanggal, $status, $status, $tanggal]);
                    }
                    $pdo->commit();
                    $message = 'Data absensi pertemuan ke-' . $pertemuan_ke . ' berhasil disimpan.';
                    $msgType = 'success';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = 'Gagal menyimpan absensi. Error: ' . $e->getMessage();
                    $msgType = 'danger';
                }
            }
        }
    }
}

// Get registered students
$stmt = $pdo->prepare("
    SELECT tr.id as reg_id, tr.user_id, tr.status, tr.nilai_akhir, 
           tr.nilai_thaharah, tr.nilai_shalat, tr.nilai_surat_pendek, tr.nilai_amaliyah, tr.nilai_jenazah, tr.nilai_detail,
           u.nama_lengkap, u.nim, u.program_studi
    FROM tutorial_registrations tr
    JOIN users u ON tr.user_id = u.id
    WHERE tr.tutorial_class_id = ?
    ORDER BY u.nama_lengkap ASC
");
$stmt->execute([$class_id]);
$students = $stmt->fetchAll();

// Active tab
$activeTab = $_GET['tab'] ?? 'nilai';

// Absensi logic
$pertemuanSelected = 1;
$tanggalSelected = date('Y-m-d');
$attendanceData = [];
$meetings = [];

if ($activeTab === 'absensi') {
    $pertemuanSelected = (int)($_GET['pertemuan'] ?? 1);
    $stmt = $pdo->prepare("SELECT DISTINCT pertemuan_ke, tanggal FROM tutorial_attendance WHERE tutorial_class_id = ? ORDER BY pertemuan_ke ASC");
    $stmt->execute([$class_id]);
    $meetings = $stmt->fetchAll();
    
    $existingDate = '';
    $stmt = $pdo->prepare("SELECT user_id, status_hadir FROM tutorial_attendance WHERE tutorial_class_id = ? AND pertemuan_ke = ?");
    $stmt->execute([$class_id, $pertemuanSelected]);
    while ($row = $stmt->fetch()) {
        $attendanceData[$row['user_id']] = $row['status_hadir'];
    }
    
    foreach ($meetings as $m) {
        if ($m['pertemuan_ke'] == $pertemuanSelected) {
            $tanggalSelected = $m['tanggal'];
            break;
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div style="margin-bottom:20px;">
    <a href="<?= BASE_URL ?>/dosen/kelas.php" class="btn btn-sm btn-outline">&larr; Kembali ke Daftar Kelas</a>
</div>

<div class="card" style="margin-bottom:20px; border-top:4px solid var(--primary);">
    <div class="card-body">
        <h2 style="margin-bottom:10px;"><?= sanitize($kelas['nama_kelas']) ?></h2>
        <div style="display:flex; flex-wrap:wrap; gap:20px; color:#475569; font-size:14px;">
            <div><strong>Mata Kuliah:</strong> <?= sanitize($kelas['mata_kuliah']) ?></div>
            <div><strong>Jadwal:</strong> <?= sanitize($kelas['hari']) ?>, <?= sanitize($kelas['jam']) ?></div>
            <div><strong>Ruangan:</strong> <?= sanitize($kelas['ruangan']) ?></div>
            <div><strong>Semester:</strong> <?= sanitize($kelas['semester']) ?></div>
            <div><strong>Total Mahasiswa:</strong> <?= count($students) ?></div>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs" style="display:flex; gap:10px; margin-bottom:20px; border-bottom:1px solid #e2e8f0;">
    <a href="?id=<?= $class_id ?>&tab=nilai" style="padding:10px 20px; text-decoration:none; color:<?= $activeTab==='nilai' ? 'var(--primary)' : '#64748b' ?>; border-bottom:2px solid <?= $activeTab==='nilai' ? 'var(--primary)' : 'transparent' ?>; font-weight:<?= $activeTab==='nilai' ? 'bold' : 'normal' ?>;">Penilaian</a>
    <a href="?id=<?= $class_id ?>&tab=absensi" style="padding:10px 20px; text-decoration:none; color:<?= $activeTab==='absensi' ? 'var(--primary)' : '#64748b' ?>; border-bottom:2px solid <?= $activeTab==='absensi' ? 'var(--primary)' : 'transparent' ?>; font-weight:<?= $activeTab==='absensi' ? 'bold' : 'normal' ?>;">Absensi</a>
</div>

<?php if ($activeTab === 'nilai'): ?>
    <!-- TAB NILAI -->
    <div class="card">
        <div class="card-header">📝 Daftar Mahasiswa & Penilaian</div>
        <div class="card-body">
            <?php if (empty($students)): ?>
                <div class="empty-state">
                    <p>Belum ada mahasiswa yang terdaftar di kelas ini.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th width="40">No</th>
                                <th>Mahasiswa</th>
                                <th>Thaharah</th>
                                <th>Shalat</th>
                                <th>Srt Pdk</th>
                                <th>Amaliyah</th>
                                <th>Jenazah</th>
                                <th>Rata-Rata</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no=1; foreach($students as $s): ?>
                            <tr>
                                <td align="center"><?= $no++ ?></td>
                                <td>
                                    <strong><?= sanitize($s['nama_lengkap']) ?></strong><br>
                                    <small style="color:#64748b;"><?= sanitize($s['nim']) ?> - <?= sanitize($s['program_studi']) ?></small>
                                </td>
                                <td align="center"><?= $s['nilai_thaharah'] !== null ? number_format($s['nilai_thaharah'], 1) : '-' ?></td>
                                <td align="center"><?= $s['nilai_shalat'] !== null ? number_format($s['nilai_shalat'], 1) : '-' ?></td>
                                <td align="center"><?= $s['nilai_surat_pendek'] !== null ? number_format($s['nilai_surat_pendek'], 1) : '-' ?></td>
                                <td align="center"><?= $s['nilai_amaliyah'] !== null ? number_format($s['nilai_amaliyah'], 1) : '-' ?></td>
                                <td align="center"><?= $s['nilai_jenazah'] !== null ? number_format($s['nilai_jenazah'], 1) : '-' ?></td>
                                <td align="center">
                                    <strong><?= $s['nilai_akhir'] !== null ? number_format($s['nilai_akhir'], 2) : '-' ?></strong>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="openNilaiModal(<?= $s['reg_id'] ?>, '<?= htmlspecialchars(addslashes($s['nama_lengkap'])) ?>', '<?= htmlspecialchars(addslashes($s['nilai_detail'] ?? '{}')) ?>')">✏️ Input Rinci</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Nilai Rinci -->
    <div id="nilaiModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; overflow-y:auto;">
        <div style="background:#fff; width:90%; max-width:800px; margin:40px auto; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
            <form method="POST" id="formNilaiRinci">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="save_nilai_detail">
                <input type="hidden" name="reg_id" id="modal_reg_id" value="">
                
                <div style="padding:20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; background:#fff; z-index:10; border-radius:8px 8px 0 0;">
                    <h3 style="margin:0;">Input Nilai Rinci: <span id="modal_student_name"></span></h3>
                    <button type="button" onclick="closeNilaiModal()" style="background:none; border:none; font-size:24px; cursor:pointer;">&times;</button>
                </div>
                
                <div style="padding:20px;">
                    <?php 
                    $categories = [
                        'thaharah' => 'Ketuntasan Hafalan Thaharah',
                        'shalat' => 'Ketuntasan Hafalan Bacaan Shalat',
                        'surat_pendek' => 'Ketuntasan Hafalan Surat Pendek',
                        'amaliyah' => 'Ketuntasan Hafalan Amaliyah',
                        'jenazah' => 'Ketuntasan Praktek Merawat Jenazah'
                    ];
                    foreach($categories as $cat_key => $cat_name):
                    ?>
                    <div style="margin-bottom:30px;">
                        <h4 style="background:#f1f5f9; padding:10px; border-radius:4px; margin-bottom:10px;"><?= $cat_name ?></h4>
                        <table style="width:100%; border-collapse:collapse;">
                            <tbody>
                                <?php foreach($materi_list[$cat_key] as $key => $materi): ?>
                                <tr style="border-bottom:1px solid #e2e8f0;">
                                    <td style="padding:10px 5px; width:40%;"><?= sanitize($materi) ?></td>
                                    <td style="padding:10px 5px; width:15%;">
                                        <input type="number" step="0.01" min="0" max="100" name="detail[<?= $key ?>]" id="input_<?= $key ?>" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:4px;" placeholder="0-100">
                                    </td>
                                    <td style="padding:10px 5px;">
                                        <div style="display:flex; gap:5px; flex-wrap:wrap;">
                                            <button type="button" class="btn btn-sm btn-outline" style="padding:4px 8px;" onclick="setVal('<?= $key ?>', 60)">60</button>
                                            <button type="button" class="btn btn-sm btn-outline" style="padding:4px 8px;" onclick="setVal('<?= $key ?>', 70)">70</button>
                                            <button type="button" class="btn btn-sm btn-outline" style="padding:4px 8px;" onclick="setVal('<?= $key ?>', 80)">80</button>
                                            <button type="button" class="btn btn-sm btn-outline" style="padding:4px 8px;" onclick="setVal('<?= $key ?>', 90)">90</button>
                                            <button type="button" class="btn btn-sm btn-outline" style="padding:4px 8px; background:#dcfce7; color:#166534; border-color:#86efac;" onclick="setVal('<?= $key ?>', 100)">100</button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div style="padding:20px; border-top:1px solid #e2e8f0; text-align:right; position:sticky; bottom:0; background:#fff; border-radius:0 0 8px 8px;">
                    <button type="button" class="btn btn-outline" onclick="closeNilaiModal()" style="margin-right:10px;">Batal</button>
                    <button type="submit" class="btn btn-primary">💾 Simpan Nilai</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    function openNilaiModal(regId, studentName, detailJson) {
        document.getElementById('modal_reg_id').value = regId;
        document.getElementById('modal_student_name').innerText = studentName;
        
        // Clear all inputs first
        let inputs = document.querySelectorAll('#formNilaiRinci input[type="number"]');
        inputs.forEach(inp => inp.value = '');
        
        // Parse and fill existing data
        if(detailJson && detailJson.trim() !== '') {
            try {
                let detail = JSON.parse(detailJson);
                for(let key in detail) {
                    let el = document.getElementById('input_' + key);
                    if(el) {
                        el.value = detail[key];
                    }
                }
            } catch(e) {
                console.error("Invalid JSON:", e);
            }
        }
        
        document.getElementById('nilaiModal').style.display = 'block';
    }
    
    function closeNilaiModal() {
        document.getElementById('nilaiModal').style.display = 'none';
    }
    
    function setVal(key, val) {
        document.getElementById('input_' + key).value = val;
    }
    </script>

<?php elseif ($activeTab === 'absensi'): ?>
    <!-- TAB ABSENSI -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-body" style="background:#f8fafc; display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
            <div style="font-weight:bold; margin-right:10px;">Pilih Pertemuan:</div>
            <?php
            $max_pertemuan = 8;
            for($i=1; $i<=$max_pertemuan; $i++): 
                $isFilled = false;
                foreach($meetings as $m) {
                    if($m['pertemuan_ke'] == $i) { $isFilled = true; break; }
                }
                $btnClass = $i == $pertemuanSelected ? 'btn-primary' : ($isFilled ? 'btn-success' : 'btn-outline');
            ?>
                <a href="?id=<?= $class_id ?>&tab=absensi&pertemuan=<?= $i ?>" class="btn btn-sm <?= $btnClass ?>" style="padding:4px 10px; <?= $isFilled && $i != $pertemuanSelected ? 'background:#dcfce7; color:#166534; border-color:#86efac;' : '' ?>">P-<?= $i ?></a>
            <?php endfor; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <span>📅 Absensi - Pertemuan Ke-<?= $pertemuanSelected ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($students)): ?>
                <div class="empty-state">
                    <p>Belum ada mahasiswa yang terdaftar di kelas ini.</p>
                </div>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="save_absensi">
                    <input type="hidden" name="pertemuan_ke" value="<?= $pertemuanSelected ?>">
                    
                    <div style="margin-bottom:20px; max-width:300px;">
                        <label style="display:block; margin-bottom:8px; font-weight:bold;">Tanggal Pertemuan *</label>
                        <input type="date" name="tanggal" value="<?= sanitize($tanggalSelected) ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px;">
                    </div>
                    
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th width="50">No</th>
                                    <th>NIM</th>
                                    <th>Nama Mahasiswa</th>
                                    <th>Status Kehadiran</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no=1; foreach($students as $s): ?>
                                <?php 
                                    $status = $attendanceData[$s['user_id']] ?? 'hadir'; 
                                ?>
                                <tr>
                                    <td align="center"><?= $no++ ?></td>
                                    <td><?= sanitize($s['nim']) ?></td>
                                    <td><strong><?= sanitize($s['nama_lengkap']) ?></strong></td>
                                    <td>
                                        <div style="display:flex; gap:15px; align-items:center;">
                                            <label style="display:flex; align-items:center; gap:5px; cursor:pointer; color:#166534;">
                                                <input type="radio" name="status_hadir[<?= $s['user_id'] ?>]" value="hadir" <?= $status === 'hadir' ? 'checked' : '' ?>> Hadir
                                            </label>
                                            <label style="display:flex; align-items:center; gap:5px; cursor:pointer; color:#991b1b;">
                                                <input type="radio" name="status_hadir[<?= $s['user_id'] ?>]" value="absen" <?= $status === 'absen' ? 'checked' : '' ?>> Absen (Alpa)
                                            </label>
                                            <label style="display:flex; align-items:center; gap:5px; cursor:pointer; color:#0284c7;">
                                                <input type="radio" name="status_hadir[<?= $s['user_id'] ?>]" value="izin" <?= $status === 'izin' ? 'checked' : '' ?>> Izin
                                            </label>
                                            <label style="display:flex; align-items:center; gap:5px; cursor:pointer; color:#ca8a04;">
                                                <input type="radio" name="status_hadir[<?= $s['user_id'] ?>]" value="sakit" <?= $status === 'sakit' ? 'checked' : '' ?>> Sakit
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-top:20px; text-align:right;">
                        <button type="submit" class="btn btn-primary">💾 Simpan Absensi P-<?= $pertemuanSelected ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
