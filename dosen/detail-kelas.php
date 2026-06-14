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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        // Simpan Nilai
        if ($action === 'save_nilai') {
            $nilai_data = $_POST['nilai'] ?? [];
            try {
                $pdo->beginTransaction();
                $updateStmt = $pdo->prepare("UPDATE tutorial_registrations SET nilai_akhir = ? WHERE id = ? AND tutorial_class_id = ?");
                foreach ($nilai_data as $reg_id => $nilai_akhir) {
                    $val = $nilai_akhir === '' ? null : (float)$nilai_akhir;
                    $updateStmt->execute([$val, $reg_id, $class_id]);
                }
                $pdo->commit();
                $message = 'Nilai berhasil disimpan.';
                $msgType = 'success';
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = 'Gagal menyimpan nilai. Error: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
        
        // Simpan Absensi
        elseif ($action === 'save_absensi') {
            $pertemuan_ke = (int)($_POST['pertemuan_ke'] ?? 0);
            $tanggal = $_POST['tanggal'] ?? '';
            $status_data = $_POST['status_hadir'] ?? []; // format: array[user_id] => status

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
    SELECT tr.id as reg_id, tr.user_id, tr.status, tr.nilai_akhir, u.nama_lengkap, u.nim, u.program_studi
    FROM tutorial_registrations tr
    JOIN users u ON tr.user_id = u.id
    WHERE tr.tutorial_class_id = ?
    ORDER BY u.nama_lengkap ASC
");
$stmt->execute([$class_id]);
$students = $stmt->fetchAll();

// Active tab
$activeTab = $_GET['tab'] ?? 'nilai';

// If absensi tab, fetch specific meeting or last meeting
$pertemuanSelected = 1;
$tanggalSelected = date('Y-m-d');
$attendanceData = [];

if ($activeTab === 'absensi') {
    // Determine which meeting to show
    $pertemuanSelected = (int)($_GET['pertemuan'] ?? 1);
    
    // Fetch distinct meetings for this class
    $stmt = $pdo->prepare("SELECT DISTINCT pertemuan_ke, tanggal FROM tutorial_attendance WHERE tutorial_class_id = ? ORDER BY pertemuan_ke ASC");
    $stmt->execute([$class_id]);
    $meetings = $stmt->fetchAll();
    
    // Check if the selected meeting exists to prepopulate date and attendance
    $existingDate = '';
    $stmt = $pdo->prepare("SELECT user_id, status_hadir FROM tutorial_attendance WHERE tutorial_class_id = ? AND pertemuan_ke = ?");
    $stmt->execute([$class_id, $pertemuanSelected]);
    while ($row = $stmt->fetch()) {
        $attendanceData[$row['user_id']] = $row['status_hadir'];
    }
    
    // If we have an existing date for this meeting, use it
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
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="save_nilai">
                    
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th width="50">No</th>
                                    <th>NIM</th>
                                    <th>Nama Mahasiswa</th>
                                    <th>Program Studi</th>
                                    <th>Status</th>
                                    <th width="150">Nilai Akhir (0-100)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no=1; foreach($students as $s): ?>
                                <tr>
                                    <td align="center"><?= $no++ ?></td>
                                    <td><?= sanitize($s['nim']) ?></td>
                                    <td><strong><?= sanitize($s['nama_lengkap']) ?></strong></td>
                                    <td><?= sanitize($s['program_studi']) ?></td>
                                    <td>
                                        <?php
                                        $bg = '#e2e8f0'; $col = '#475569';
                                        if($s['status']=='aktif' || $s['status']=='terdaftar') { $bg='#dcfce7'; $col='#166534'; }
                                        elseif($s['status']=='lulus') { $bg='#dbeafe'; $col='#1e40af'; }
                                        elseif($s['status']=='tidak_lulus' || $s['status']=='mengundurkan_diri') { $bg='#fee2e2'; $col='#991b1b'; }
                                        ?>
                                        <span class="badge" style="background:<?= $bg ?>;color:<?= $col ?>;">
                                            <?= str_replace('_', ' ', strtoupper($s['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" max="100" name="nilai[<?= $s['reg_id'] ?>]" value="<?= htmlspecialchars($s['nilai_akhir'] ?? '') ?>" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:4px;">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-top:20px; text-align:right;">
                        <button type="submit" class="btn btn-primary">💾 Simpan Semua Nilai</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($activeTab === 'absensi'): ?>
    <!-- TAB ABSENSI -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-body" style="background:#f8fafc; display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
            <div style="font-weight:bold; margin-right:10px;">Pilih Pertemuan:</div>
            <?php
            $max_pertemuan = 16;
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
