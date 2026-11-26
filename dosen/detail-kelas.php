<?php
/**
 * LPPAI Corner - Detail Kelas Dosen
 */
if (!defined('PAGE_TITLE')) define('PAGE_TITLE', 'Detail & Kelola Kelas');
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

function calcMateriAvg(array $detail, array $keys): ?float {
    $sum = 0.0;
    $has_val = false;
    foreach ($keys as $k) {
        if (isset($detail[$k]) && $detail[$k] !== '') {
            $sum += (float)$detail[$k];
            $has_val = true;
        }
    }
    return $has_val ? ($sum / count($keys)) : null;
}

function sendJsonResponse(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

// Handle form submission (standard POST & AJAX auto-save)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        if ($isAjax) {
            sendJsonResponse(['success' => false, 'message' => 'Sesi tidak valid. Silakan muat ulang halaman.'], 403);
        }
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        // Simpan Nilai Detail
        if ($action === 'save_nilai_detail') {
            $reg_id = (int)($_POST['reg_id'] ?? 0);
            $rawDetail = $_POST['detail'] ?? [];
            $detail = [];
            if (is_array($rawDetail)) {
                foreach ($rawDetail as $k => $v) {
                    $vStr = trim((string)$v);
                    if ($vStr !== '') {
                        $numVal = (float)$vStr;
                        if ($numVal < 0) $numVal = 0;
                        if ($numVal > 100) $numVal = 100;
                        $detail[$k] = (string)$numVal;
                    }
                }
            }

            $avg_th = calcMateriAvg($detail, array_keys($materi_list['thaharah']));
            $avg_sh = calcMateriAvg($detail, array_keys($materi_list['shalat']));
            $avg_sp = calcMateriAvg($detail, array_keys($materi_list['surat_pendek']));
            $avg_am = calcMateriAvg($detail, array_keys($materi_list['amaliyah']));
            $avg_jz = calcMateriAvg($detail, array_keys($materi_list['jenazah']));
            $avg_ut = calcMateriAvg($detail, array_keys($materi_list['ujian_tulis']));

            if ($reg_id <= 0) {
                if ($isAjax) {
                    sendJsonResponse(['success' => false, 'message' => 'ID pendaftaran tidak valid.'], 400);
                }
                $message = 'ID pendaftaran tidak valid.';
                $msgType = 'danger';
            } else {
                try {
                    $json_detail = json_encode($detail);
                    $updateStmt = $pdo->prepare("UPDATE tutorial_registrations SET nilai_detail=?, nilai_thaharah=?, nilai_shalat=?, nilai_surat_pendek=?, nilai_amaliyah=?, nilai_jenazah=?, nilai_ujian_tulis=? WHERE id=? AND tutorial_class_id=?");
                    $updateStmt->execute([$json_detail, $avg_th, $avg_sh, $avg_sp, $avg_am, $avg_jz, $avg_ut, $reg_id, $class_id]);

                    if ($isAjax) {
                        $catAvgs = [$avg_th, $avg_sh, $avg_sp, $avg_am, $avg_jz, $avg_ut];
                        $sumAll = 0.0;
                        $cntAll = 0;
                        foreach ($catAvgs as $val) {
                            if ($val !== null) {
                                $sumAll += $val;
                                $cntAll++;
                            }
                        }
                        sendJsonResponse([
                            'success' => true,
                            'message' => 'Nilai tersimpan otomatis.',
                            'reg_id' => $reg_id,
                            'detail' => $detail,
                            'averages' => [
                                'thaharah' => $avg_th !== null ? number_format($avg_th, 1) : '-',
                                'shalat' => $avg_sh !== null ? number_format($avg_sh, 1) : '-',
                                'surat_pendek' => $avg_sp !== null ? number_format($avg_sp, 1) : '-',
                                'amaliyah' => $avg_am !== null ? number_format($avg_am, 1) : '-',
                                'jenazah' => $avg_jz !== null ? number_format($avg_jz, 1) : '-',
                                'ujian_tulis' => $avg_ut !== null ? number_format($avg_ut, 1) : '-',
                                'overall' => $cntAll > 0 ? number_format(round($sumAll / $cntAll, 2), 2) : '-'
                            ]
                        ]);
                    }

                    $message = 'Nilai rincian berhasil disimpan.';
                    $msgType = 'success';
                } catch (Exception $e) {
                    if ($isAjax) {
                        sendJsonResponse(['success' => false, 'message' => 'Gagal menyimpan nilai: ' . $e->getMessage()], 500);
                    }
                    $message = 'Gagal menyimpan nilai. Error: ' . $e->getMessage();
                    $msgType = 'danger';
                }
            }
        }

        // Simpan Absensi
        elseif ($action === 'save_absensi') {
            $pertemuan_ke = (int)($_POST['pertemuan_ke'] ?? 0);
            $tanggal = trim((string)($_POST['tanggal'] ?? ''));
            $status_data = $_POST['status_hadir'] ?? [];
            $allowedStatuses = ['belum', 'hadir', 'izin', 'sakit', 'absen'];

            if ($pertemuan_ke <= 0 || empty($tanggal)) {
                if ($isAjax) {
                    sendJsonResponse(['success' => false, 'message' => 'Pertemuan Ke dan Tanggal harus diisi.'], 400);
                }
                $message = 'Pertemuan Ke dan Tanggal harus diisi.';
                $msgType = 'danger';
            } else {
                try {
                    $pdo->beginTransaction();
                    $insertStmt = $pdo->prepare("INSERT INTO tutorial_attendance (tutorial_class_id, user_id, pertemuan_ke, tanggal, status_hadir) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status_hadir = ?, tanggal = ?");
                    $deleteStmt = $pdo->prepare("DELETE FROM tutorial_attendance WHERE tutorial_class_id = ? AND user_id = ? AND pertemuan_ke = ?");
                    foreach ($status_data as $user_id => $status) {
                        $uid = (int)$user_id;
                        $stVal = strtolower(trim((string)$status));
                        if ($uid <= 0 || !in_array($stVal, $allowedStatuses, true)) {
                            continue;
                        }
                        if ($stVal === 'belum') {
                            $deleteStmt->execute([$class_id, $uid, $pertemuan_ke]);
                        } else {
                            $insertStmt->execute([$class_id, $uid, $pertemuan_ke, $tanggal, $stVal, $stVal, $tanggal]);
                        }
                    }
                    $pdo->commit();
                    if ($isAjax) {
                        sendJsonResponse(['success' => true, 'message' => 'Absensi tersimpan otomatis.']);
                    }
                    $message = 'Data absensi pertemuan ke-' . $pertemuan_ke . ' berhasil disimpan.';
                    $msgType = 'success';
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($isAjax) {
                        sendJsonResponse(['success' => false, 'message' => 'Gagal menyimpan absensi: ' . $e->getMessage()], 500);
                    }
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
} elseif ($activeTab === 'rekap_absensi') {
    $max_pertemuan = 8;
    // 1. Ambil seluruh catatan absensi kelas ini
    $stmtAtt = $pdo->prepare("SELECT user_id, pertemuan_ke, status_hadir, tanggal FROM tutorial_attendance WHERE tutorial_class_id = ? ORDER BY pertemuan_ke ASC");
    $stmtAtt->execute([$class_id]);
    $attendanceRows = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Ambil sesi QR yang pernah dibuat
    $stmtQr = $pdo->prepare("SELECT pertemuan_ke, created_at, expires_at FROM tutorial_qr_sessions WHERE tutorial_class_id = ? ORDER BY pertemuan_ke ASC");
    $stmtQr->execute([$class_id]);
    $qrRows = $stmtQr->fetchAll(PDO::FETCH_ASSOC);

    $qrByMeeting = [];
    foreach ($qrRows as $qr) {
        $p = (int)$qr['pertemuan_ke'];
        if (!isset($qrByMeeting[$p])) {
            $qrByMeeting[$p] = $qr;
        }
    }

    // 3. Susun matriks kehadiran mahasiswa dan statistik pertemuan
    $matrixAbsensi = [];
    $meetingDates = array_fill(1, $max_pertemuan, null);
    $meetingHadirStats = array_fill(1, $max_pertemuan, 0);
    $meetingTotalStats = array_fill(1, $max_pertemuan, 0);

    foreach ($attendanceRows as $row) {
        $uid = (int)$row['user_id'];
        $p = (int)$row['pertemuan_ke'];
        $st = strtolower(trim((string)$row['status_hadir']));
        $matrixAbsensi[$uid][$p] = $st;

        if (!empty($row['tanggal']) && empty($meetingDates[$p])) {
            $meetingDates[$p] = $row['tanggal'];
        }
        if (in_array($st, ['hadir', 'izin', 'sakit', 'absen'], true)) {
            $meetingTotalStats[$p]++;
            if ($st === 'hadir') {
                $meetingHadirStats[$p]++;
            }
        }
    }

    // Isi tanggal dari sesi QR jika belum tercatat di data absensi
    for ($i = 1; $i <= $max_pertemuan; $i++) {
        if (empty($meetingDates[$i]) && isset($qrByMeeting[$i]['created_at'])) {
            $meetingDates[$i] = substr($qrByMeeting[$i]['created_at'], 0, 10);
        }
    }

    // 4. Hitung kehadiran mengajar dosen
    $dosenMeetingsHeld = 0;
    $meetingProgressDetails = [];
    for ($i = 1; $i <= $max_pertemuan; $i++) {
        $isHeld = ($meetingTotalStats[$i] > 0 || isset($qrByMeeting[$i]));
        if ($isHeld) {
            $dosenMeetingsHeld++;
        }
        $meetingProgressDetails[$i] = [
            'pertemuan_ke' => $i,
            'is_held' => $isHeld,
            'tanggal' => $meetingDates[$i],
            'hadir' => $meetingHadirStats[$i],
            'total_diisi' => $meetingTotalStats[$i]
        ];
    }
    $dosenProgressPct = round(($dosenMeetingsHeld / $max_pertemuan) * 100);

    // 5. Hitung ringkasan kumulatif per mahasiswa
    $studentSummaries = [];
    $totalHadirAll = 0;
    $eligibleCount = 0;
    $warningCount = 0;

    foreach ($students as $st) {
        $uid = (int)$st['user_id'];
        $h = 0; $iz = 0; $sk = 0; $ab = 0;
        for ($p = 1; $p <= $max_pertemuan; $p++) {
            $status = $matrixAbsensi[$uid][$p] ?? 'belum';
            if ($status === 'hadir') $h++;
            elseif ($status === 'izin') $iz++;
            elseif ($status === 'sakit') $sk++;
            elseif ($status === 'absen') $ab++;
        }
        $pct = round(($h / $max_pertemuan) * 100, 1);
        $isEligible = ($h >= 6); // Syarat minimal 75% kehadiran (>= 6 dari 8 pertemuan)
        if ($isEligible) {
            $eligibleCount++;
        } else {
            $warningCount++;
        }
        $totalHadirAll += $pct;

        $studentSummaries[$uid] = [
            'hadir' => $h,
            'izin' => $iz,
            'sakit' => $sk,
            'absen' => $ab,
            'persen' => $pct,
            'is_eligible' => $isEligible
        ];
    }

    $avgStudentPct = count($students) > 0 ? round($totalHadirAll / count($students), 1) : 0;
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
<div class="tabs no-print dosen-kelas-tabs" style="display:flex; gap:10px; margin-bottom:20px; border-bottom:1px solid #e2e8f0; flex-wrap:wrap;">
    <a href="?id=<?= $class_id ?>&tab=nilai" style="padding:10px 20px; text-decoration:none; color:<?= $activeTab==='nilai' ? 'var(--primary)' : '#64748b' ?>; border-bottom:2px solid <?= $activeTab==='nilai' ? 'var(--primary)' : 'transparent' ?>; font-weight:<?= $activeTab==='nilai' ? 'bold' : 'normal' ?>;">📝 Penilaian</a>
    <a href="?id=<?= $class_id ?>&tab=absensi" style="padding:10px 20px; text-decoration:none; color:<?= $activeTab==='absensi' ? 'var(--primary)' : '#64748b' ?>; border-bottom:2px solid <?= $activeTab==='absensi' ? 'var(--primary)' : 'transparent' ?>; font-weight:<?= $activeTab==='absensi' ? 'bold' : 'normal' ?>;">📱 Input Absensi & QR</a>
    <a href="?id=<?= $class_id ?>&tab=rekap_absensi" style="padding:10px 20px; text-decoration:none; color:<?= $activeTab==='rekap_absensi' ? 'var(--primary)' : '#64748b' ?>; border-bottom:2px solid <?= $activeTab==='rekap_absensi' ? 'var(--primary)' : 'transparent' ?>; font-weight:<?= $activeTab==='rekap_absensi' ? 'bold' : 'normal' ?>;">📊 Rekap Kehadiran</a>
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
                <div class="table-responsive dosen-table-wrap">
                    <table class="dosen-stack-table dosen-nilai-table">
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
                            <tr data-reg-id="<?= $s['reg_id'] ?>">
                                <td align="center" class="dosen-cell-no" data-label="No"><?= $no++ ?></td>
                                <td class="dosen-cell-title" data-label="Mahasiswa">
                                    <strong><?= adminUserProfileLink($s['nama_lengkap'], $s['user_id'] ?? null) ?></strong><br>
                                    <small style="color:#64748b;"><?= sanitize($s['nim']) ?> - <?= sanitize($s['program_studi']) ?> <?= renderJenisProgramBadge($s['jenis_program'] ?? 'Reguler', true) ?></small>
                                </td>
                                <td align="center" class="dosen-cell-score" data-label="Thaharah" data-score-col="thaharah"><?= $s['nilai_thaharah'] !== null ? number_format($s['nilai_thaharah'], 1) : '-' ?></td>
                                <td align="center" class="dosen-cell-score" data-label="Shalat" data-score-col="shalat"><?= $s['nilai_shalat'] !== null ? number_format($s['nilai_shalat'], 1) : '-' ?></td>
                                <td align="center" class="dosen-cell-score" data-label="Srt Pdk" data-score-col="surat_pendek"><?= $s['nilai_surat_pendek'] !== null ? number_format($s['nilai_surat_pendek'], 1) : '-' ?></td>
                                <td align="center" class="dosen-cell-score" data-label="Amaliyah" data-score-col="amaliyah"><?= $s['nilai_amaliyah'] !== null ? number_format($s['nilai_amaliyah'], 1) : '-' ?></td>
                                <td align="center" class="dosen-cell-score" data-label="Jenazah" data-score-col="jenazah"><?= $s['nilai_jenazah'] !== null ? number_format($s['nilai_jenazah'], 1) : '-' ?></td>
                                <td align="center" class="dosen-cell-score" data-label="UT" data-score-col="ujian_tulis"><?= $s['nilai_ujian_tulis'] !== null ? number_format($s['nilai_ujian_tulis'], 1) : '-' ?></td>
                                <td align="center" class="dosen-cell-average" data-label="Rata-Rata">
                                    <strong data-score-col="overall">
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
                                <td class="dosen-cell-actions" data-label="Aksi">
                                    <button type="button"
                                            class="btn btn-sm btn-primary btn-input-nilai"
                                            data-reg-id="<?= $s['reg_id'] ?>"
                                            data-student-name="<?= htmlspecialchars($s['nama_lengkap'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-nilai-detail="<?= htmlspecialchars($s['nilai_detail'] ?? '{}', ENT_QUOTES, 'UTF-8') ?>"
                                            onclick="openNilaiModalFromBtn(this)">✏️ Input Rinci</button>
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
    <div id="nilaiModal" class="nilai-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; overflow-y:auto;">
        <div class="nilai-modal-dialog" style="background:#fff; width:90%; max-width:800px; margin:40px auto; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
            <form method="POST" id="formNilaiRinci">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="save_nilai_detail">
                <input type="hidden" name="reg_id" id="modal_reg_id" value="">

                <div class="nilai-modal-head" style="padding:20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; gap:12px; position:sticky; top:0; background:#fff; z-index:10; border-radius:8px 8px 0 0;">
                    <h3 style="margin:0;">Input Nilai Rinci: <span id="modal_student_name"></span></h3>
                    <button type="button" onclick="closeNilaiModal()" aria-label="Tutup" class="nilai-modal-close" style="background:none; border:none; font-size:24px; cursor:pointer;">&times;</button>
                </div>

                <div class="nilai-modal-body" style="padding:20px;">
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
                        <table class="nilai-rinci-table" style="width:100%; border-collapse:collapse;">
                            <tbody>
                                <?php foreach($materi_list[$cat_key] as $key => $materi): ?>
                                <tr style="border-bottom:1px solid #e2e8f0;">
                                    <td class="nilai-rinci-materi" style="padding:10px 5px; width:40%;"><?= sanitize($materi) ?></td>
                                    <td class="nilai-rinci-input" style="padding:10px 5px; width:15%;">
                                        <input type="number" step="0.01" min="0" max="100" name="detail[<?= $key ?>]" id="input_<?= $key ?>" inputmode="decimal" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:4px;" placeholder="0-100">
                                    </td>
                                    <td class="nilai-rinci-quick" style="padding:10px 5px;">
                                        <div class="nilai-quick-group" style="display:flex; gap:5px; flex-wrap:wrap;">
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

                <div class="nilai-modal-foot" style="padding:16px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; position:sticky; bottom:0; background:#fff; border-radius:0 0 8px 8px;">
                    <span id="nilaiAutoSaveStatus" style="font-size:13px; font-weight:600; color:#64748b;">
                        Perubahan nilai tersimpan otomatis
                    </span>
                    <button type="button" class="btn btn-outline" onclick="closeNilaiModal()">Tutup</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    var nilaiSaveTimer = null;
    var nilaiDirty = false;
    var nilaiActiveSavePromise = null;

    function setNilaiStatus(text, color) {
        var el = document.getElementById('nilaiAutoSaveStatus');
        if (!el) return;
        el.textContent = text;
        el.style.color = color || '#64748b';
    }

    function openNilaiModalFromBtn(btn) {
        var regId = btn.getAttribute('data-reg-id');
        var studentName = btn.getAttribute('data-student-name') || '';
        var detailJson = btn.getAttribute('data-nilai-detail') || '{}';
        openNilaiModal(regId, studentName, detailJson);
    }

    function openNilaiModal(regId, studentName, detailJson) {
        if (nilaiSaveTimer) {
            clearTimeout(nilaiSaveTimer);
            nilaiSaveTimer = null;
        }
        nilaiDirty = false;
        setNilaiStatus('Perubahan nilai tersimpan otomatis', '#64748b');

        document.getElementById('modal_reg_id').value = regId;
        document.getElementById('modal_student_name').innerText = studentName;

        // Clear all inputs first
        var inputs = document.querySelectorAll('#formNilaiRinci input[type="number"]');
        inputs.forEach(function(inp) { inp.value = ''; });

        // Parse and fill existing data
        if (detailJson && detailJson.trim() !== '') {
            try {
                var detail = JSON.parse(detailJson);
                for (var key in detail) {
                    var el = document.getElementById('input_' + key);
                    if (el) {
                        el.value = detail[key];
                    }
                }
            } catch (e) {
                console.error("Invalid JSON:", e);
            }
        }

        document.getElementById('nilaiModal').style.display = 'block';
    }

    function closeNilaiModal() {
        if (nilaiSaveTimer) {
            clearTimeout(nilaiSaveTimer);
            nilaiSaveTimer = null;
        }
        if (nilaiDirty) {
            autoSaveNilaiNow();
        }
        document.getElementById('nilaiModal').style.display = 'none';
    }

    function setVal(key, val) {
        var inp = document.getElementById('input_' + key);
        if (!inp) return;
        inp.value = val;
        nilaiDirty = true;
        autoSaveNilaiNow();
    }

    function scheduleAutoSaveNilai() {
        nilaiDirty = true;
        setNilaiStatus('Menunggu selesai mengetik...', '#64748b');
        if (nilaiSaveTimer) {
            clearTimeout(nilaiSaveTimer);
        }
        nilaiSaveTimer = setTimeout(function() {
            nilaiSaveTimer = null;
            autoSaveNilaiNow();
        }, 550);
    }

    function autoSaveNilaiNow() {
        var form = document.getElementById('formNilaiRinci');
        if (!form) return Promise.resolve();
        if (nilaiSaveTimer) {
            clearTimeout(nilaiSaveTimer);
            nilaiSaveTimer = null;
        }

        var regId = document.getElementById('modal_reg_id').value;
        if (!regId) return Promise.resolve();

        nilaiDirty = false;
        setNilaiStatus('⏳ Menyimpan nilai...', '#0369a1');

        var formData = new FormData(form);
        formData.append('ajax', '1');

        nilaiActiveSavePromise = fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(res) {
            return res.json().then(function(data) {
                return { ok: res.ok, data: data };
            });
        })
        .then(function(result) {
            if (!result.ok || !result.data || !result.data.success) {
                var errMsg = (result.data && result.data.message) ? result.data.message : 'Gagal menyimpan nilai.';
                setNilaiStatus('⚠ ' + errMsg, '#be123c');
                return;
            }
            var data = result.data;
            setNilaiStatus('✓ Tersimpan otomatis', '#059669');

            var row = document.querySelector('tr[data-reg-id="' + data.reg_id + '"]');
            if (row && data.averages) {
                Object.keys(data.averages).forEach(function(col) {
                    var cell = row.querySelector('[data-score-col="' + col + '"]');
                    if (cell) {
                        cell.textContent = data.averages[col];
                    }
                });
                var btn = row.querySelector('.btn-input-nilai');
                if (btn && data.detail !== undefined) {
                    btn.setAttribute('data-nilai-detail', JSON.stringify(data.detail));
                }
            }
        })
        .catch(function() {
            setNilaiStatus('⚠ Gagal terhubung ke server. Periksa koneksi.', '#be123c');
        });

        return nilaiActiveSavePromise;
    }

    (function initNilaiAutoSave() {
        var form = document.getElementById('formNilaiRinci');
        if (!form) return;
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            autoSaveNilaiNow();
        });
        var inputs = form.querySelectorAll('input[type="number"]');
        inputs.forEach(function(inp) {
            inp.addEventListener('input', scheduleAutoSaveNilai);
            inp.addEventListener('change', function() {
                if (nilaiDirty || nilaiSaveTimer) {
                    autoSaveNilaiNow();
                }
            });
            inp.addEventListener('blur', function() {
                if (nilaiDirty || nilaiSaveTimer) {
                    autoSaveNilaiNow();
                }
            });
        });
    })();
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
                    <div class="attendance-toolbar" style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0;">
                        <div>
                            <label style="display: block; margin-bottom: 6px; font-weight: 700; font-size: 13px; color: #334155;">
                                Tanggal Pertemuan *
                            </label>
                            <div class="attendance-date-row" style="display: flex; gap: 8px; align-items: center;">
                                <input type="date" name="tanggal" id="inputTanggalAbsensi" value="<?= sanitize($tanggalSelected) ?>" required onchange="autoSaveAbsensiBatch()" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px;">
                                <button type="button" class="btn btn-sm btn-outline" onclick="setTodayAbsensi()" style="padding: 7px 12px; font-size: 12px; font-weight: 600;">
                                    Hari Ini
                                </button>
                            </div>
                        </div>

                        <div class="attendance-batch-actions" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                            <span id="absensiAutoSaveStatus" style="font-size: 12px; font-weight: 600; color: #059669; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 6px 12px; border-radius: 9999px;">
                                ✓ Tersimpan otomatis
                            </span>
                            <button type="button" class="btn btn-sm" onclick="markAllHadir()" style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; font-weight: 600; padding: 7px 14px;">
                                ✨ Hadir Semua
                            </button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="resetAllBelum()" style="font-weight: 600; padding: 7px 12px; font-size: 12px;">
                                ↺ Reset Belum
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
                    <div class="desktop-attendance-table table-responsive dosen-table-wrap">
                        <table class="no-datatable dosen-attendance-table">
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
                            Presensi (P-<?= $pertemuanSelected ?>): <strong id="floatingHadirCount">0</strong> dari <?= count($students) ?> Hadir
                        </div>
                        <div>
                            <span id="floatingAbsensiStatus" style="font-size: 12px; font-weight: 700; color: #059669; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 6px 14px; border-radius: 9999px; display: inline-block;">
                                ✓ Tersimpan otomatis
                            </span>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($activeTab === 'rekap_absensi'): ?>
    <!-- TAB REKAP KEHADIRAN (DOSEN & MAHASISWA) -->
    <style>
    @media print {
        .no-print, .topbar, .sidebar, .sidebar-overlay, .btn, .tabs, .user-menu-container, footer, .app-logo, .sidebar-header {
            display: none !important;
        }
        .main-content {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }
        .card {
            box-shadow: none !important;
            border: 1px solid #94a3b8 !important;
            margin-bottom: 20px !important;
            page-break-inside: avoid;
        }
        .print-only {
            display: block !important;
        }
        .badge {
            border: 1px solid #334155 !important;
            color: #0f172a !important;
            background: transparent !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        table {
            font-size: 11px !important;
            width: 100% !important;
            border-collapse: collapse !important;
        }
        th, td {
            padding: 6px 4px !important;
            border: 1px solid #cbd5e1 !important;
        }
    }
    .print-only {
        display: none;
    }
    .rekap-badge-status {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 6px;
        font-weight: 700;
        font-size: 11px;
    }
    .rekap-badge-hadir { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .rekap-badge-izin { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
    .rekap-badge-sakit { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
    .rekap-badge-absen { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    .rekap-badge-belum { background: #f1f5f9; color: #94a3b8; border: 1px solid #e2e8f0; }
    </style>

    <!-- KOP RESMI KHUSUS CETAK -->
    <div class="print-only" style="margin-bottom: 24px; text-align: center; border-bottom: 2px solid #0f172a; padding-bottom: 12px;">
        <h2 style="margin: 0; font-size: 18px; text-transform: uppercase; color: #0f172a; font-weight: 800;">LEMBAGA PENGEMBANGAN PENDIDIKAN AGAMA ISLAM (LPPAI)</h2>
        <h3 style="margin: 4px 0 0 0; font-size: 14px; font-weight: 700; color: #334155;">REKAPITULASI KEHADIRAN MAHASISWA & KEHADIRAN MENGAJAR DOSEN</h3>
        <div style="font-size: 12px; margin-top: 8px; color: #475569; display: flex; justify-content: center; gap: 16px; flex-wrap: wrap;">
            <span><strong>Kelas:</strong> <?= sanitize($kelas['nama_kelas']) ?></span>
            <span><strong>Dosen Pengampu:</strong> <?= sanitize($kelas['dosen_pengampu']) ?></span>
            <span><strong>Jadwal:</strong> <?= sanitize($kelas['hari']) ?>, <?= sanitize($kelas['jam']) ?> (Ruang: <?= sanitize($kelas['ruangan']) ?>)</span>
            <span><strong>Semester:</strong> <?= sanitize($kelas['semester']) ?> (<?= sanitize($kelas['gelombang']) ?>)</span>
        </div>
    </div>

    <!-- CARD 1: KEHADIRAN MENGAJAR DOSEN -->
    <div class="card" style="margin-bottom: 24px; border-top: 4px solid #10b981;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; background: #f8fafc;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 18px;">👨‍🏫</span>
                <span style="font-weight: 700; color: #0f172a;">Status Kehadiran Mengajar Dosen</span>
            </div>
            <div>
                <?php if ($dosenMeetingsHeld >= 8): ?>
                    <span class="badge" style="background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-weight:700;padding:5px 12px;border-radius:9999px;">✓ Tuntas (8/8 Pertemuan)</span>
                <?php elseif ($dosenMeetingsHeld > 0): ?>
                    <span class="badge" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;font-weight:700;padding:5px 12px;border-radius:9999px;">● Sedang Berjalan (<?= $dosenMeetingsHeld ?>/8 Pertemuan)</span>
                <?php else: ?>
                    <span class="badge" style="background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;font-weight:700;padding:5px 12px;border-radius:9999px;">○ Belum Dimulai (0/8)</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <!-- Progress Bar -->
            <div style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #334155;">
                    <span>Progres Perkuliahan Tutorial: <strong><?= $dosenMeetingsHeld ?> dari 8 Pertemuan</strong></span>
                    <span><strong><?= $dosenProgressPct ?>%</strong></span>
                </div>
                <div style="background: #e2e8f0; border-radius: 9999px; height: 10px; overflow: hidden;">
                    <div style="background: linear-gradient(90deg, #10b981, #059669); width: <?= $dosenProgressPct ?>%; height: 100%; border-radius: 9999px; transition: width 0.3s ease;"></div>
                </div>
            </div>

            <!-- Mini Grid 8 Pertemuan -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px;">
                <?php for ($p = 1; $p <= 8; $p++):
                    $det = $meetingProgressDetails[$p];
                    $isHeld = $det['is_held'];
                    $tglFormatted = $det['tanggal'] ? date('d/m/Y', strtotime($det['tanggal'])) : '-';
                ?>
                    <div style="padding: 12px 10px; border-radius: 10px; border: 1px solid <?= $isHeld ? '#86efac' : '#e2e8f0' ?>; background: <?= $isHeld ? '#f0fdf4' : '#f8fafc' ?>; text-align: center;">
                        <div style="font-size: 11px; font-weight: 700; color: <?= $isHeld ? '#166534' : '#64748b' ?>; text-transform: uppercase;">Pertemuan <?= $p ?></div>
                        <div style="font-size: 18px; font-weight: 800; margin: 4px 0; color: <?= $isHeld ? '#047857' : '#94a3b8' ?>;">
                            <?= $isHeld ? '✓' : '—' ?>
                        </div>
                        <div style="font-size: 11px; font-weight: 600; color: #475569;"><?= $tglFormatted ?></div>
                        <div style="font-size: 11px; color: <?= $isHeld ? '#065f46' : '#94a3b8' ?>; margin-top: 2px;">
                            <?= $isHeld ? ($det['hadir'] . ' Hadir') : 'Belum Ada' ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <!-- CARD 2: MATRIKS REKAPITULASI KEHADIRAN MAHASISWA -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 18px;">📋</span>
                <span style="font-weight: 700; color: #0f172a;">Matriks Rekapitulasi Presensi Mahasiswa</span>
            </div>
            <div class="no-print" style="display: flex; gap: 8px; align-items: center;">
                <button type="button" class="btn btn-sm btn-outline" onclick="window.print()" style="font-weight: 600; display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px;">
                    🖨️ Cetak Rekapitulasi
                </button>
            </div>
        </div>
        <div class="card-body">
            <!-- Stat KPI Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 20px;">
                <div style="padding: 14px; border-radius: 12px; background: #f8fafc; border: 1px solid #e2e8f0;">
                    <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Total Mahasiswa</div>
                    <div style="font-size: 24px; font-weight: 800; color: #0f172a; margin-top: 4px;"><?= count($students) ?> <span style="font-size: 13px; font-weight: 500; color: #64748b;">Orang</span></div>
                </div>
                <div style="padding: 14px; border-radius: 12px; background: #eff6ff; border: 1px solid #bfdbfe;">
                    <div style="font-size: 12px; color: #1e40af; font-weight: 600; text-transform: uppercase;">Rata-rata Kehadiran</div>
                    <div style="font-size: 24px; font-weight: 800; color: #1d4ed8; margin-top: 4px;"><?= $avgStudentPct ?>%</div>
                </div>
                <div style="padding: 14px; border-radius: 12px; background: #ecfdf5; border: 1px solid #a7f3d0;">
                    <div style="font-size: 12px; color: #065f46; font-weight: 600; text-transform: uppercase;">Memenuhi Syarat (≥ 75%)</div>
                    <div style="font-size: 24px; font-weight: 800; color: #047857; margin-top: 4px;"><?= $eligibleCount ?> <span style="font-size: 13px; font-weight: 500; color: #065f46;">Mahasiswa</span></div>
                </div>
                <div style="padding: 14px; border-radius: 12px; background: #fff1f2; border: 1px solid #fecdd3;">
                    <div style="font-size: 12px; color: #9f1239; font-weight: 600; text-transform: uppercase;">Perlu Perhatian (&lt; 75%)</div>
                    <div style="font-size: 24px; font-weight: 800; color: #be123c; margin-top: 4px;"><?= $warningCount ?> <span style="font-size: 13px; font-weight: 500; color: #9f1239;">Mahasiswa</span></div>
                </div>
            </div>

            <!-- Toolbar & Legend (No Print) -->
            <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; padding: 12px 14px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0;">
                <div style="flex: 1; min-width: 220px; max-width: 360px;">
                    <input type="text" id="rekapSearchInput" placeholder="🔍 Cari nama atau NIM mahasiswa..." style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px;" onkeyup="filterRekapTable()">
                </div>
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 12px;">
                    <span style="font-weight: 700; color: #475569;">Keterangan:</span>
                    <span style="display: inline-flex; align-items: center; gap: 4px;"><span class="rekap-badge-status rekap-badge-hadir" style="width:20px;height:20px;font-size:10px;">H</span> Hadir</span>
                    <span style="display: inline-flex; align-items: center; gap: 4px;"><span class="rekap-badge-status rekap-badge-izin" style="width:20px;height:20px;font-size:10px;">I</span> Izin</span>
                    <span style="display: inline-flex; align-items: center; gap: 4px;"><span class="rekap-badge-status rekap-badge-sakit" style="width:20px;height:20px;font-size:10px;">S</span> Sakit</span>
                    <span style="display: inline-flex; align-items: center; gap: 4px;"><span class="rekap-badge-status rekap-badge-absen" style="width:20px;height:20px;font-size:10px;">A</span> Alpa</span>
                    <span style="display: inline-flex; align-items: center; gap: 4px;"><span class="rekap-badge-status rekap-badge-belum" style="width:20px;height:20px;font-size:10px;">-</span> Belum</span>
                </div>
            </div>

            <?php if (empty($students)): ?>
                <div class="empty-state" style="padding:40px 20px; text-align:center;">
                    <p style="color:#64748b;">Belum ada mahasiswa yang terdaftar di kelas tutorial ini.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive dosen-table-wrap dosen-rekap-wrap">
                    <table id="rekapAttendanceTable" class="no-datatable dosen-rekap-table">
                        <thead>
                            <tr style="background: #f1f5f9;">
                                <th width="35" style="text-align: center;">No</th>
                                <th width="110">NIM</th>
                                <th class="dosen-sticky-name">Nama Mahasiswa</th>
                                <th width="35" style="text-align: center;">P1</th>
                                <th width="35" style="text-align: center;">P2</th>
                                <th width="35" style="text-align: center;">P3</th>
                                <th width="35" style="text-align: center;">P4</th>
                                <th width="35" style="text-align: center;">P5</th>
                                <th width="35" style="text-align: center;">P6</th>
                                <th width="35" style="text-align: center;">P7</th>
                                <th width="35" style="text-align: center;">P8</th>
                                <th width="45" style="text-align: center; color: #166534;" title="Total Hadir">H</th>
                                <th width="45" style="text-align: center; color: #0369a1;" title="Total Izin">I</th>
                                <th width="45" style="text-align: center; color: #92400e;" title="Total Sakit">S</th>
                                <th width="45" style="text-align: center; color: #991b1b;" title="Total Alpa">A</th>
                                <th width="65" style="text-align: center;">% Hadir</th>
                                <th width="130" style="text-align: center;">Status Kelayakan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no = 1; foreach ($students as $st):
                                $uid = (int)$st['user_id'];
                                $sum = $studentSummaries[$uid];
                            ?>
                            <tr class="rekap-student-row" data-name="<?= htmlspecialchars(strtolower($st['nama_lengkap'])) ?>" data-nim="<?= htmlspecialchars(strtolower($st['nim'])) ?>">
                                <td align="center" style="color: #64748b;"><?= $no++ ?></td>
                                <td><span style="font-family: monospace; font-size: 13px; font-weight: 600; color: #334155;"><?= sanitize($st['nim']) ?></span></td>
                                <td class="dosen-sticky-name">
                                    <strong><?= adminUserProfileLink($st['nama_lengkap'], $uid) ?></strong><br>
                                    <small style="color: #64748b;"><?= sanitize($st['program_studi']) ?> <?= renderJenisProgramBadge($st['jenis_program'] ?? 'Reguler', true) ?></small>
                                </td>
                                <?php for ($p = 1; $p <= 8; $p++):
                                    $stVal = $matrixAbsensi[$uid][$p] ?? 'belum';
                                    $badgeClass = 'rekap-badge-belum';
                                    $label = '-';
                                    if ($stVal === 'hadir') { $badgeClass = 'rekap-badge-hadir'; $label = 'H'; }
                                    elseif ($stVal === 'izin') { $badgeClass = 'rekap-badge-izin'; $label = 'I'; }
                                    elseif ($stVal === 'sakit') { $badgeClass = 'rekap-badge-sakit'; $label = 'S'; }
                                    elseif ($stVal === 'absen') { $badgeClass = 'rekap-badge-absen'; $label = 'A'; }
                                ?>
                                    <td align="center">
                                        <span class="rekap-badge-status <?= $badgeClass ?>" title="Pertemuan <?= $p ?>: <?= strtoupper($stVal) ?>">
                                            <?= $label ?>
                                        </span>
                                    </td>
                                <?php endfor; ?>
                                <td align="center"><strong style="color: #166534;"><?= $sum['hadir'] ?></strong></td>
                                <td align="center" style="color: #0369a1;"><?= $sum['izin'] ?></td>
                                <td align="center" style="color: #92400e;"><?= $sum['sakit'] ?></td>
                                <td align="center" style="color: #991b1b;"><?= $sum['absen'] ?></td>
                                <td align="center">
                                    <strong style="color: <?= $sum['is_eligible'] ? '#059669' : '#e11d48' ?>; font-size: 13px;">
                                        <?= $sum['persen'] ?>%
                                    </strong>
                                </td>
                                <td align="center">
                                    <?php if ($sum['is_eligible']): ?>
                                        <span class="badge" style="background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-weight:700;font-size:11px;">
                                            ✓ Memenuhi
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#fff1f2;color:#be123c;border:1px solid #fecdd3;font-weight:700;font-size:11px;">
                                            ⚠ Perlu Perhatian
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- TANDA TANGAN KHUSUS CETAK -->
    <div class="print-only" style="margin-top: 36px; page-break-inside: avoid;">
        <div style="display: flex; justify-content: space-between; font-size: 12px;">
            <div style="text-align: center; width: 220px;">
                <p>Mengetahui,<br>Koordinator Tutorial LPPAI</p>
                <br><br><br><br>
                <p><strong>( _______________________ )</strong></p>
            </div>
            <div style="text-align: center; width: 220px;">
                <p><?= date('d F Y') ?><br>Dosen Pengampu Tutorial</p>
                <br><br><br><br>
                <p><strong><?= sanitize($kelas['dosen_pengampu']) ?></strong></p>
            </div>
        </div>
    </div>

    <script>
    function filterRekapTable() {
        var input = document.getElementById('rekapSearchInput');
        var filter = input.value.toLowerCase().trim();
        var rows = document.querySelectorAll('.rekap-student-row');
        rows.forEach(function(r) {
            var name = r.getAttribute('data-name') || '';
            var nim = r.getAttribute('data-nim') || '';
            if (name.indexOf(filter) > -1 || nim.indexOf(filter) > -1) {
                r.style.display = '';
            } else {
                r.style.display = 'none';
            }
        });
    }
    </script>
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

    function setAbsensiStatusBadge(text, state) {
        var styles = {
            saving: { color: '#0369a1', bg: '#e0f2fe', border: '#7dd3fc' },
            saved: { color: '#059669', bg: '#ecfdf5', border: '#a7f3d0' },
            error: { color: '#be123c', bg: '#fff1f2', border: '#fecdd3' }
        };
        var s = styles[state] || styles.saved;
        ['absensiAutoSaveStatus', 'floatingAbsensiStatus'].forEach(function(id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.textContent = text;
            el.style.color = s.color;
            el.style.background = s.bg;
            el.style.borderColor = s.border;
        });
    }

    function sendAbsensiAutoSave(formData) {
        formData.append('ajax', '1');
        setAbsensiStatusBadge('⏳ Menyimpan...', 'saving');
        return fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(res) {
            return res.json().then(function(data) {
                return { ok: res.ok, data: data };
            });
        })
        .then(function(result) {
            if (!result.ok || !result.data || !result.data.success) {
                var errMsg = (result.data && result.data.message) ? result.data.message : 'Gagal menyimpan absensi.';
                setAbsensiStatusBadge('⚠ ' + errMsg, 'error');
                return;
            }
            setAbsensiStatusBadge('✓ Tersimpan otomatis', 'saved');
        })
        .catch(function() {
            setAbsensiStatusBadge('⚠ Gagal terhubung ke server', 'error');
        });
    }

    function autoSaveAbsensiSingle(userId, status) {
        var form = document.getElementById('formAttendance');
        var tanggalEl = document.getElementById('inputTanggalAbsensi');
        if (!form || !tanggalEl) return;
        if (!tanggalEl.value) {
            setAbsensiStatusBadge('⚠ Tanggal pertemuan wajib diisi', 'error');
            return;
        }
        var formData = new FormData();
        formData.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
        formData.append('action', 'save_absensi');
        formData.append('pertemuan_ke', form.querySelector('input[name="pertemuan_ke"]').value);
        formData.append('tanggal', tanggalEl.value);
        formData.append('status_hadir[' + userId + ']', status);
        sendAbsensiAutoSave(formData);
    }

    function autoSaveAbsensiBatch() {
        var form = document.getElementById('formAttendance');
        var tanggalEl = document.getElementById('inputTanggalAbsensi');
        if (!form || !tanggalEl) return;
        if (!tanggalEl.value) {
            setAbsensiStatusBadge('⚠ Tanggal pertemuan wajib diisi', 'error');
            return;
        }
        sendAbsensiAutoSave(new FormData(form));
    }

    function setTodayAbsensi() {
        var tanggalEl = document.getElementById('inputTanggalAbsensi');
        if (!tanggalEl) return;
        tanggalEl.value = '<?= date('Y-m-d') ?>';
        autoSaveAbsensiBatch();
    }

    // Attendance Sync & Live Counters
    function syncAttendanceStatus(userId, status, skipSave) {
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

        // 5. Auto-save single student change
        if (!skipSave) {
            autoSaveAbsensiSingle(userId, status);
        }
    }

    function markAllHadir() {
        var radios = document.querySelectorAll('input[type="radio"][value="hadir"]');
        radios.forEach(function(r) {
            var match = r.name.match(/status_hadir\[(\d+)\]/);
            if (match && match[1]) {
                syncAttendanceStatus(match[1], 'hadir', true);
            }
        });
        autoSaveAbsensiBatch();
    }

    function resetAllBelum() {
        var radios = document.querySelectorAll('input[type="radio"][value="belum"]');
        radios.forEach(function(r) {
            var match = r.name.match(/status_hadir\[(\d+)\]/);
            if (match && match[1]) {
                syncAttendanceStatus(match[1], 'belum', true);
            }
        });
        autoSaveAbsensiBatch();
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

    function initAttendanceAutoSaveForm() {
        updateLiveCounters();
        var form = document.getElementById('formAttendance');
        if (form && !form.dataset.autoSaveBound) {
            form.dataset.autoSaveBound = '1';
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                autoSaveAbsensiBatch();
            });
        }
    }

    // Initialize counters on page load
    document.addEventListener('DOMContentLoaded', initAttendanceAutoSaveForm);
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        initAttendanceAutoSaveForm();
    }
    </script>
