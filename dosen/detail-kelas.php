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
    ],
    'ujian_tulis' => [
        'ut1' => 'Nilai Ujian Tulis'
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
            $avg_ut = calcAvg($detail, array_keys($materi_list['ujian_tulis']));

            try {
                $json_detail = json_encode($detail);
                $updateStmt = $pdo->prepare("UPDATE tutorial_registrations SET nilai_detail=?, nilai_thaharah=?, nilai_shalat=?, nilai_surat_pendek=?, nilai_amaliyah=?, nilai_jenazah=?, nilai_ujian_tulis=? WHERE id=? AND tutorial_class_id=?");
                $updateStmt->execute([$json_detail, $avg_th, $avg_sh, $avg_sp, $avg_am, $avg_jz, $avg_ut, $reg_id, $class_id]);
                
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
                    $deleteStmt = $pdo->prepare("DELETE FROM tutorial_attendance WHERE tutorial_class_id = ? AND user_id = ? AND pertemuan_ke = ?");
                    foreach ($status_data as $user_id => $status) {
                        if ($status === 'belum') {
                            $deleteStmt->execute([$class_id, $user_id, $pertemuan_ke]);
                        } else {
                            $insertStmt->execute([$class_id, $user_id, $pertemuan_ke, $tanggal, $status, $status, $tanggal]);
                        }
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
    SELECT tr.id as reg_id, tr.user_id, tr.status, 
           tr.nilai_thaharah, tr.nilai_shalat, tr.nilai_surat_pendek, tr.nilai_amaliyah, tr.nilai_jenazah, tr.nilai_ujian_tulis, tr.nilai_detail,
           u.nama_lengkap, u.nim, u.program_studi, u.jenis_program
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
                                <th>UT</th>
                                <th>Rata-Rata</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no=1; foreach($students as $s): ?>
                            <tr>
                                <td align="center"><?= $no++ ?></td>
                                <td>
                                    <strong><?= adminUserProfileLink($s['nama_lengkap'], $s['user_id'] ?? null) ?></strong><br>
                                    <small style="color:#64748b;"><?= sanitize($s['nim']) ?> - <?= sanitize($s['program_studi']) ?> <?= renderJenisProgramBadge($s['jenis_program'] ?? 'Reguler', true) ?></small>
                                </td>
                                <td align="center"><?= $s['nilai_thaharah'] !== null ? number_format($s['nilai_thaharah'], 1) : '-' ?></td>
                                <td align="center"><?= $s['nilai_shalat'] !== null ? number_format($s['nilai_shalat'], 1) : '-' ?></td>
                                <td align="center"><?= $s['nilai_surat_pendek'] !== null ? number_format($s['nilai_surat_pendek'], 1) : '-' ?></td>
                                <td align="center"><?= $s['nilai_amaliyah'] !== null ? number_format($s['nilai_amaliyah'], 1) : '-' ?></td>
                                <td align="center"><?= $s['nilai_jenazah'] !== null ? number_format($s['nilai_jenazah'], 1) : '-' ?></td>
                                <td align="center"><?= $s['nilai_ujian_tulis'] !== null ? number_format($s['nilai_ujian_tulis'], 1) : '-' ?></td>
                                <td align="center">
                                    <strong>
                                        <?php
                                        $sum = 0; $count = 0;
                                        if ($s['nilai_thaharah'] !== null) { $sum += (float)$s['nilai_thaharah']; $count++; }
                                        if ($s['nilai_shalat'] !== null) { $sum += (float)$s['nilai_shalat']; $count++; }
                                        if ($s['nilai_surat_pendek'] !== null) { $sum += (float)$s['nilai_surat_pendek']; $count++; }
                                        if ($s['nilai_amaliyah'] !== null) { $sum += (float)$s['nilai_amaliyah']; $count++; }
                                        if ($s['nilai_jenazah'] !== null) { $sum += (float)$s['nilai_jenazah']; $count++; }
                                        if ($s['nilai_ujian_tulis'] !== null) { $sum += (float)$s['nilai_ujian_tulis']; $count++; }
                                        echo $count > 0 ? number_format(round($sum / $count, 2), 2) : '-';
                                        ?>
                                    </strong>
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
                        'jenazah' => 'Ketuntasan Praktek Merawat Jenazah',
                        'ujian_tulis' => 'Nilai Ujian Tulis'
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
    <!-- TAB ABSENSI: PERTEMUAN SELECTOR (MODERN HORIZONTAL SCROLL) -->
    <div style="margin-bottom: 20px;">
        <div style="font-weight: 700; font-size: 13px; color: #475569; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.04em;">
            Pilih Pertemuan Tutorial
        </div>
        <div class="pertemuan-nav-scroll">
            <?php
            $max_pertemuan = 8;
            for ($i = 1; $i <= $max_pertemuan; $i++):
                $isFilled = false;
                foreach ($meetings as $m) {
                    if ((int)$m['pertemuan_ke'] === $i) {
                        $isFilled = true;
                        break;
                    }
                }
                $pillClass = $i === $pertemuanSelected ? 'active' : ($isFilled ? 'completed' : '');
            ?>
                <a href="?id=<?= $class_id ?>&tab=absensi&pertemuan=<?= $i ?>" class="pertemuan-pill-tab <?= $pillClass ?>">
                    <span>Pertemuan <?= $i ?></span>
                    <?php if ($i === $pertemuanSelected): ?>
                        <span style="font-size: 10px; background: rgba(255,255,255,0.25); padding: 1px 6px; border-radius: 9999px;">Aktif</span>
                    <?php elseif ($isFilled): ?>
                        <span style="font-size: 11px;">✓</span>
                    <?php endif; ?>
                </a>
            <?php endfor; ?>
        </div>
    </div>

    <!-- TAB ABSENSI: QR CODE CARD & PRESENTATION MODE -->
    <div class="card" style="margin-bottom: 24px; border: 1px solid #bfdbfe; background: linear-gradient(180deg, #f0fdf4 0%, #ffffff 100%);">
        <div class="card-header" style="background: #eff6ff; color: #1e40af; border-bottom: 1px solid #bfdbfe; font-weight: 700; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
            <span>📱 QR Code Presensi Mahasiswa</span>
            <span style="font-size: 12px; font-weight: 600; background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 9999px;">Pertemuan Ke-<?= $pertemuanSelected ?></span>
        </div>
        <div class="card-body" style="text-align: center; padding: 24px 20px;">
            <p style="color: #475569; font-size: 14px; max-width: 560px; margin: 0 auto 16px auto;">
                Tampilkan kode QR di proyektor kelas atau tunjukkan layar HP Anda. Mahasiswa melakukan pemindaian melalui menu <b>Absensi Tutorial</b> di akun mereka.
            </p>

            <div style="display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px;">
                <button type="button" class="btn btn-primary" id="btnGenerateQR" onclick="generateQR(false)" style="font-weight: 600; padding: 9px 20px;">
                    ✨ Tampilkan QR Code (P-<?= $pertemuanSelected ?>)
                </button>
                <button type="button" class="btn btn-outline" id="btnOpenFullscreenQR" onclick="openFullscreenQR()" style="display: none; font-weight: 600; padding: 9px 18px; border-color: #3b82f6; color: #1d4ed8;">
                    🔍 Layar Penuh (Mode Presentasi / HP)
                </button>
            </div>

            <div id="qrContainer" style="display: none; margin-top: 20px;">
                <div id="qrcode" style="display: inline-block; padding: 16px; background: white; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;"></div>
                <div style="margin-top: 14px;">
                    <div style="font-weight: 700; color: #10b981; font-size: 16px; margin-bottom: 4px;" id="qrStatus">● QR Code Aktif</div>
                    <div style="font-size: 13px; color: #64748b; margin-bottom: 12px;">Berlaku hingga: <strong id="qrExpiry" style="color: #0f172a;"></strong></div>
                    <button type="button" class="btn btn-sm btn-outline" onclick="generateQR(true)" style="font-size: 12px;">
                        ↺ Perbarui / Perpanjang Masa Aktif
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- FULLSCREEN MODAL FOR PRESENTATION / HP MODE -->
    <div id="qrFullscreenModal" class="qr-fullscreen-modal" style="display: none;">
        <div style="width: 100%; max-width: 500px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div style="text-align: left;">
                <div style="font-size: 18px; font-weight: 700; color: #34d399;">Pertemuan Ke-<?= $pertemuanSelected ?> · <?= sanitize($kelas['nama_kelas']) ?></div>
                <div style="font-size: 13px; color: #cbd5e1;">Pindai via Menu Absensi Tutorial Mahasiswa</div>
            </div>
            <button type="button" onclick="closeFullscreenQR()" aria-label="Tutup Layar Penuh" style="background: rgba(255,255,255,0.15); border: none; color: #ffffff; font-size: 20px; width: 42px; height: 42px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                ✕
            </button>
        </div>

        <div class="qr-fullscreen-box">
            <div id="fullscreenQrcode" style="display: flex; justify-content: center;"></div>
        </div>

        <div style="margin-top: 8px; text-align: center;">
            <div style="font-size: 14px; color: #e2e8f0; margin-bottom: 4px;">Arahkan kamera ke layar ini</div>
            <div style="font-size: 13px; color: #94a3b8;">Berlaku hingga: <strong id="fullscreenQrExpiry" style="color: #fde047;"></strong></div>
        </div>

        <div style="margin-top: 24px;">
            <button type="button" class="btn btn-outline" onclick="closeFullscreenQR()" style="color: #ffffff; border-color: rgba(255,255,255,0.4); padding: 8px 24px; border-radius: 9999px; font-weight: 600;">
                Tutup Mode Layar Penuh (ESC)
            </button>
        </div>
    </div>

    <!-- FORM INPUT ABSENSI MAHASISWA -->
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
            <span style="font-weight: 700;">📅 Form Presensi Manual - Pertemuan Ke-<?= $pertemuanSelected ?></span>
            <span style="font-size: 13px; color: #64748b; font-weight: 500;">Total: <?= count($students) ?> Mahasiswa</span>
        </div>
        <div class="card-body" style="padding: 20px;">
            <?php if (empty($students)): ?>
                <div class="empty-state" style="padding: 40px 20px; text-align: center;">
                    <div style="font-size: 40px; margin-bottom: 8px;">👥</div>
                    <h4 style="color: #1e293b; margin-bottom: 4px;">Belum Ada Mahasiswa</h4>
                    <p style="color: #64748b; font-size: 14px;">Belum ada mahasiswa yang terdaftar di kelas tutorial ini.</p>
                </div>
            <?php else: ?>
                <form method="POST" id="formAttendance">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="save_absensi">
                    <input type="hidden" name="pertemuan_ke" value="<?= $pertemuanSelected ?>">

                    <!-- Baris Pengaturan Tanggal & Tombol Batch Aksi Cepat -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0;">
                        <div>
                            <label style="display: block; margin-bottom: 6px; font-weight: 700; font-size: 13px; color: #334155;">
                                Tanggal Pertemuan *
                            </label>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <input type="date" name="tanggal" id="inputTanggalAbsensi" value="<?= sanitize($tanggalSelected) ?>" required style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px;">
                                <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('inputTanggalAbsensi').value = '<?= date('Y-m-d') ?>'" style="padding: 7px 12px; font-size: 12px; font-weight: 600;">
                                    Hari Ini
                                </button>
                            </div>
                        </div>

                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <button type="button" class="btn btn-sm" onclick="markAllHadir()" style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; font-weight: 600; padding: 7px 14px;">
                                ✨ Hadir Semua
                            </button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="resetAllBelum()" style="font-weight: 600; padding: 7px 12px; font-size: 12px;">
                                ↺ Reset Belum
                            </button>
                            <button type="submit" class="btn btn-primary" style="font-weight: 600; padding: 7px 18px;">
                                💾 Simpan Absensi
                            </button>
                        </div>
                    </div>

                    <!-- Live Summary Badges -->
                    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; font-size: 12px; font-weight: 600;">
                        <span style="background: #ecfdf5; color: #065f46; padding: 4px 10px; border-radius: 9999px; border: 1px solid #a7f3d0;">
                            Hadir: <span id="countHadir">0</span>
                        </span>
                        <span style="background: #f0f9ff; color: #075985; padding: 4px 10px; border-radius: 9999px; border: 1px solid #bae6fd;">
                            Izin: <span id="countIzin">0</span>
                        </span>
                        <span style="background: #fffbeb; color: #92400e; padding: 4px 10px; border-radius: 9999px; border: 1px solid #fde68a;">
                            Sakit: <span id="countSakit">0</span>
                        </span>
                        <span style="background: #fef2f2; color: #991b1b; padding: 4px 10px; border-radius: 9999px; border: 1px solid #fecaca;">
                            Alpa: <span id="countAbsen">0</span>
                        </span>
                        <span style="background: #f1f5f9; color: #475569; padding: 4px 10px; border-radius: 9999px; border: 1px solid #cbd5e1;">
                            Belum: <span id="countBelum">0</span>
                        </span>
                    </div>

                    <!-- Desktop Table View (>= 768px) -->
                    <div class="desktop-attendance-table table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th width="40" style="text-align: center;">No</th>
                                    <th width="120">NIM</th>
                                    <th>Nama Mahasiswa</th>
                                    <th style="min-width: 320px;">Status Kehadiran</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($students as $s):
                                    $userId = $s['user_id'];
                                    $status = $attendanceData[$userId] ?? 'belum';
                                ?>
                                <tr data-student-row="<?= $userId ?>">
                                    <td align="center" style="color: #64748b;"><?= $no++ ?></td>
                                    <td><span style="font-family: monospace; font-size: 13px; font-weight: 600; color: #334155;"><?= sanitize($s['nim']) ?></span></td>
                                    <td>
                                        <div style="font-weight: 600; color: #0f172a;"><?= adminUserProfileLink($s['nama_lengkap'], $userId) ?></div>
                                        <small style="color: #64748b;"><?= sanitize($s['program_studi']) ?> <?= renderJenisProgramBadge($s['jenis_program'] ?? 'Reguler', true) ?></small>
                                    </td>
                                    <td>
                                        <div class="status-pill-group">
                                            <label class="status-pill-btn <?= $status === 'belum' ? 'active-belum' : '' ?>" style="margin: 0; display: inline-flex; align-items: center; justify-content: center;">
                                                <input type="radio" name="status_hadir[<?= $userId ?>]" value="belum" <?= $status === 'belum' ? 'checked' : '' ?> style="position: absolute; opacity: 0; pointer-events: none;" onchange="syncAttendanceStatus(<?= $userId ?>, 'belum')">
                                                Belum
                                            </label>
                                            <label class="status-pill-btn <?= $status === 'hadir' ? 'active-hadir' : '' ?>" style="margin: 0; display: inline-flex; align-items: center; justify-content: center;">
                                                <input type="radio" name="status_hadir[<?= $userId ?>]" value="hadir" <?= $status === 'hadir' ? 'checked' : '' ?> style="position: absolute; opacity: 0; pointer-events: none;" onchange="syncAttendanceStatus(<?= $userId ?>, 'hadir')">
                                                ✓ Hadir
                                            </label>
                                            <label class="status-pill-btn <?= $status === 'absen' ? 'active-absen' : '' ?>" style="margin: 0; display: inline-flex; align-items: center; justify-content: center;">
                                                <input type="radio" name="status_hadir[<?= $userId ?>]" value="absen" <?= $status === 'absen' ? 'checked' : '' ?> style="position: absolute; opacity: 0; pointer-events: none;" onchange="syncAttendanceStatus(<?= $userId ?>, 'absen')">
                                                ✗ Alpa
                                            </label>
                                            <label class="status-pill-btn <?= $status === 'izin' ? 'active-izin' : '' ?>" style="margin: 0; display: inline-flex; align-items: center; justify-content: center;">
                                                <input type="radio" name="status_hadir[<?= $userId ?>]" value="izin" <?= $status === 'izin' ? 'checked' : '' ?> style="position: absolute; opacity: 0; pointer-events: none;" onchange="syncAttendanceStatus(<?= $userId ?>, 'izin')">
                                                ℹ Izin
                                            </label>
                                            <label class="status-pill-btn <?= $status === 'sakit' ? 'active-sakit' : '' ?>" style="margin: 0; display: inline-flex; align-items: center; justify-content: center;">
                                                <input type="radio" name="status_hadir[<?= $userId ?>]" value="sakit" <?= $status === 'sakit' ? 'checked' : '' ?> style="position: absolute; opacity: 0; pointer-events: none;" onchange="syncAttendanceStatus(<?= $userId ?>, 'sakit')">
                                                ✚ Sakit
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Cards View (< 768px) -->
                    <div class="mobile-attendance-card-list">
                        <?php foreach ($students as $s):
                            $userId = $s['user_id'];
                            $status = $attendanceData[$userId] ?? 'belum';
                            $initial = strtoupper(mb_substr($s['nama_lengkap'], 0, 1, 'UTF-8'));
                        ?>
                        <div class="student-attendance-card" id="student-card-<?= $userId ?>">
                            <div class="student-card-header">
                                <div class="student-avatar-initial"><?= $initial ?></div>
                                <div class="student-card-meta">
                                    <div class="student-card-name"><?= sanitize($s['nama_lengkap']) ?></div>
                                    <div class="student-card-sub">
                                        <span style="font-family: monospace; font-weight: 600;"><?= sanitize($s['nim']) ?></span>
                                        <span>•</span>
                                        <span><?= sanitize($s['program_studi']) ?></span>
                                        <?= renderJenisProgramBadge($s['jenis_program'] ?? 'Reguler', true) ?>
                                    </div>
                                </div>
                            </div>
                            <div class="status-pill-group">
                                <button type="button" class="status-pill-btn btn-mob-belum <?= $status === 'belum' ? 'active-belum' : '' ?>" onclick="syncAttendanceStatus(<?= $userId ?>, 'belum')">Belum</button>
                                <button type="button" class="status-pill-btn btn-mob-hadir <?= $status === 'hadir' ? 'active-hadir' : '' ?>" onclick="syncAttendanceStatus(<?= $userId ?>, 'hadir')">✓ Hadir</button>
                                <button type="button" class="status-pill-btn btn-mob-absen <?= $status === 'absen' ? 'active-absen' : '' ?>" onclick="syncAttendanceStatus(<?= $userId ?>, 'absen')">✗ Alpa</button>
                                <button type="button" class="status-pill-btn btn-mob-izin <?= $status === 'izin' ? 'active-izin' : '' ?>" onclick="syncAttendanceStatus(<?= $userId ?>, 'izin')">ℹ Izin</button>
                                <button type="button" class="status-pill-btn btn-mob-sakit <?= $status === 'sakit' ? 'active-sakit' : '' ?>" onclick="syncAttendanceStatus(<?= $userId ?>, 'sakit')">✚ Sakit</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Floating Sticky Bottom Bar (Mobile & Desktop) -->
                    <div class="floating-attendance-bar">
                        <div class="floating-stats-text">
                            Presensi: <strong id="floatingHadirCount">0</strong> dari <?= count($students) ?> Hadir
                        </div>
                        <div>
                            <button type="submit" class="floating-save-btn">
                                💾 Simpan Presensi (P-<?= $pertemuanSelected ?>)
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    var qrcodeObj = null;
    var fullscreenQrcodeObj = null;
    var currentToken = null;

    function generateQR(regenerate) {
        var btn = document.getElementById('btnGenerateQR');
        if (btn && !regenerate) btn.disabled = true;

        $.ajax({
            url: '<?= BASE_URL ?>/dosen/ajax-qr-generate.php',
            type: 'POST',
            data: {
                class_id: <?= $class_id ?>,
                pertemuan_ke: <?= $pertemuanSelected ?>,
                regenerate: regenerate ? 1 : 0
            },
            dataType: 'json',
            success: function(res) {
                if (btn && !regenerate) btn.style.display = 'none';

                if (res.status === 'success') {
                    currentToken = res.token;
                    document.getElementById('qrContainer').style.display = 'block';
                    document.getElementById('qrExpiry').textContent = res.expires_at;
                    var fsExp = document.getElementById('fullscreenQrExpiry');
                    if (fsExp) fsExp.textContent = res.expires_at;

                    var btnFullscreen = document.getElementById('btnOpenFullscreenQR');
                    if (btnFullscreen) btnFullscreen.style.display = 'inline-block';

                    var qrTarget = document.getElementById("qrcode");
                    qrTarget.innerHTML = '';
                    qrcodeObj = new QRCode(qrTarget, {
                        text: res.token,
                        width: 240,
                        height: 240,
                        colorDark : "#000000",
                        colorLight : "#ffffff",
                        correctLevel : QRCode.CorrectLevel.H
                    });

                    Swal.fire({
                        icon: 'success',
                        title: 'QR Code Siap!',
                        text: res.message,
                        confirmButtonColor: '#10b981'
                    });
                } else {
                    Swal.fire('Gagal', res.message, 'error');
                    if (btn && !regenerate) {
                        btn.style.display = 'inline-block';
                        btn.disabled = false;
                    }
                }
            },
            error: function() {
                Swal.fire('Error', 'Gagal menghubungi server.', 'error');
                if (btn && !regenerate) {
                    btn.style.display = 'inline-block';
                    btn.disabled = false;
                }
            }
        });
    }

    function openFullscreenQR() {
        if (!currentToken) return;
        var modal = document.getElementById('qrFullscreenModal');
        var container = document.getElementById('fullscreenQrcode');
        container.innerHTML = '';

        var qrSize = Math.min(window.innerWidth - 60, window.innerHeight - 200, 320);
        if (qrSize < 200) qrSize = 200;

        fullscreenQrcodeObj = new QRCode(container, {
            text: currentToken,
            width: qrSize,
            height: qrSize,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.H
        });

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeFullscreenQR() {
        var modal = document.getElementById('qrFullscreenModal');
        if (modal) modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    // Close on ESC key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeFullscreenQR();
    });

    // Attendance Sync & Live Counters
    function syncAttendanceStatus(userId, status) {
        // 1. Sync Radio Input in Desktop Form
        var radio = document.querySelector('input[name="status_hadir[' + userId + ']"][value="' + status + '"]');
        if (radio) {
            radio.checked = true;
        }

        // 2. Sync Desktop Label Styling
        var row = document.querySelector('tr[data-student-row="' + userId + '"]');
        if (row) {
            var labels = row.querySelectorAll('.status-pill-btn');
            labels.forEach(function(lbl) {
                lbl.classList.remove('active-belum', 'active-hadir', 'active-absen', 'active-izin', 'active-sakit');
            });
            var targetInput = row.querySelector('input[value="' + status + '"]');
            if (targetInput) {
                var targetLabel = targetInput.closest('.status-pill-btn');
                if (targetLabel) targetLabel.classList.add('active-' + status);
            }
        }

        // 3. Sync Mobile Card Button Styling
        var mobCard = document.getElementById('student-card-' + userId);
        if (mobCard) {
            var btns = mobCard.querySelectorAll('.status-pill-btn');
            btns.forEach(function(b) {
                b.classList.remove('active-belum', 'active-hadir', 'active-absen', 'active-izin', 'active-sakit');
            });
            var targetBtn = mobCard.querySelector('.btn-mob-' + status);
            if (targetBtn) {
                targetBtn.classList.add('active-' + status);
            }
        }

        // 4. Update Live Counters
        updateLiveCounters();
    }

    function markAllHadir() {
        var radios = document.querySelectorAll('input[type="radio"][value="hadir"]');
        radios.forEach(function(r) {
            var match = r.name.match(/status_hadir\[(\d+)\]/);
            if (match && match[1]) {
                syncAttendanceStatus(match[1], 'hadir');
            }
        });
    }

    function resetAllBelum() {
        var radios = document.querySelectorAll('input[type="radio"][value="belum"]');
        radios.forEach(function(r) {
            var match = r.name.match(/status_hadir\[(\d+)\]/);
            if (match && match[1]) {
                syncAttendanceStatus(match[1], 'belum');
            }
        });
    }

    function updateLiveCounters() {
        var counts = { belum: 0, hadir: 0, absen: 0, izin: 0, sakit: 0 };
        var checkedRadios = document.querySelectorAll('input[name^="status_hadir["]:checked');
        checkedRadios.forEach(function(r) {
            var val = r.value;
            if (counts[val] !== undefined) counts[val]++;
        });

        var elHadir = document.getElementById('countHadir');
        if (elHadir) elHadir.textContent = counts.hadir;
        var elIzin = document.getElementById('countIzin');
        if (elIzin) elIzin.textContent = counts.izin;
        var elSakit = document.getElementById('countSakit');
        if (elSakit) elSakit.textContent = counts.sakit;
        var elAbsen = document.getElementById('countAbsen');
        if (elAbsen) elAbsen.textContent = counts.absen;
        var elBelum = document.getElementById('countBelum');
        if (elBelum) elBelum.textContent = counts.belum;

        var elFloat = document.getElementById('floatingHadirCount');
        if (elFloat) elFloat.textContent = counts.hadir;
    }

    // Initialize counters on page load
    document.addEventListener('DOMContentLoaded', function() {
        updateLiveCounters();
    });
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        updateLiveCounters();
    }
    </script>
