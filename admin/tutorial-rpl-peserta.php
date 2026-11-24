<?php
/**
 * LPPAI Corner - Admin: Data Peserta & Plotting Kolektif Tutorial RPL Online (Tahap 2)
 */
define('PAGE_TITLE', 'Data Peserta & Plotting RPL Online');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
requireAdmin();
require_once __DIR__ . '/../includes/tutorial-rpl-periods.php';

$pdo = getDBConnection();
ensureTutorialRplTables($pdo);
$rplRegistrationPolicy = tutorialRplPeriodPolicy(null);
try {
    $rplRegistrationPolicy = tutorialRplPeriodPolicy(tutorialRplActivePeriod($pdo));
} catch (Throwable $e) {
    error_log('RPL period unavailable: ' . $e->getMessage());
}

$message = '';
$msgType = '';

// Filter parameter dari GET
$selectedClassId = (int)($_GET['class_id'] ?? 0);
$statusPlot = trim($_GET['status_plot'] ?? 'all');
$searchTerm = trim($_GET['q'] ?? '');

// Proses Action Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'plot_single') {
        $classId = (int)($_POST['class_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($classId > 0 && $userId > 0) {
            try {
                $res = tutorialRplBatchPlot($pdo, [$userId], $classId);
                if ($res['success'] > 0) {
                    $message = 'Mahasiswa berhasil diplot ke kelompok kelas.';
                    $msgType = 'success';
                } else {
                    $message = 'Gagal memplot mahasiswa: Kuota kelompok kelas sudah penuh.';
                    $msgType = 'danger';
                }
            } catch (Throwable $e) {
                $message = 'Gagal memplot mahasiswa: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'batch_plot') {
        $targetClassId = (int)($_POST['target_class_id'] ?? 0);
        $selectedUserIds = $_POST['user_ids'] ?? [];

        if (!is_array($selectedUserIds) || empty($selectedUserIds)) {
            $message = 'Silakan pilih setidaknya satu mahasiswa untuk diplot.';
            $msgType = 'warning';
        } elseif ($targetClassId <= 0) {
            $message = 'Pilih kelompok kelas tujuan plotting.';
            $msgType = 'warning';
        } else {
            try {
                $res = tutorialRplBatchPlot($pdo, $selectedUserIds, $targetClassId);
                $msg = "Berhasil memplot {$res['success']} dari {$res['total']} mahasiswa terpilih.";
                if ($res['skipped_quota'] > 0) {
                    $msg .= " ({$res['skipped_quota']} mahasiswa dilewati karena kuota kelas penuh).";
                    $msgType = 'warning';
                } else {
                    $msgType = 'success';
                }
                $message = $msg;
            } catch (Throwable $e) {
                $message = 'Gagal plotting kolektif: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'batch_unplot') {
        $selectedMemberIds = $_POST['member_ids'] ?? [];
        if (!is_array($selectedMemberIds) || empty($selectedMemberIds)) {
            $message = 'Pilih mahasiswa yang ingin dilepas dari kelompok kelas.';
            $msgType = 'warning';
        } else {
            try {
                $count = tutorialRplBatchUnplot($pdo, $selectedMemberIds);
                $message = "Berhasil melepas plotting $count mahasiswa terpilih.";
                $msgType = 'success';
            } catch (Throwable $e) {
                $message = 'Gagal melepas plotting: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'unplot_single') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        if ($memberId > 0) {
            try {
                tutorialRplBatchUnplot($pdo, [$memberId]);
                $message = 'Mahasiswa berhasil dilepas dari kelompok kelas.';
                $msgType = 'success';
            } catch (Throwable $e) {
                $message = 'Gagal melepas mahasiswa: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'auto_distribute') {
        try {
            $res = tutorialRplAutoDistribute($pdo);
            $message = $res['message'];
            $msgType = ($res['plotted'] > 0) ? 'success' : 'info';
        } catch (Throwable $e) {
            $message = 'Gagal pembagian otomatis: ' . $e->getMessage();
            $msgType = 'danger';
        }
    }
}

// Ambil data kelompok kelas aktif
$classes = tutorialRplGetClasses($pdo);

// Ambil daftar mahasiswa RPL dengan status CBT Pretes dan plotting
$filterParams = [
    'class_id' => $selectedClassId,
    'status_plot' => $statusPlot,
    'search' => $searchTerm
];

$students = [];
$totalAllStudents = 0;
$totalPlotted = 0;
$totalUnplotted = 0;

try {
    $students = tutorialRplGetStudentsWithPretes($pdo, $filterParams);
    $allRplStudents = tutorialRplGetStudentsWithPretes($pdo, []);
    $totalAllStudents = count($allRplStudents);
    $totalPlotted = count(array_filter($allRplStudents, fn($s) => !empty($s['class_id'])));
    $totalUnplotted = $totalAllStudents - $totalPlotted;
} catch (Throwable $e) {
    error_log('admin/tutorial-rpl-peserta.php: ' . $e->getMessage());
    $message = 'Gagal memuat data peserta RPL: ' . $e->getMessage();
    $msgType = 'danger';
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/tutorial-rpl-registration-rules-view.php';
?>

<!-- Header Halaman -->
<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0; font-size:22px; font-weight:800; color:var(--text-dark);">
            👥 Data Peserta &amp; Plotting Tutorial RPL Online
        </h2>
        <p style="margin:0; font-size:13.5px; color:#64748b;">
            Manajemen penempatan mahasiswa program RPL daring ke kelompok tutor, dilengkapi fitur plotting massal dan auto-distribusi merata.
        </p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <form method="POST" onsubmit="return confirm('Distribusikan semua mahasiswa RPL yang belum terplot secara merata ke kelompok aktif?');" style="display:inline;">
            <input type="hidden" name="action" value="auto_distribute">
            <button type="submit" class="btn btn-warning" style="display:inline-flex; align-items:center; gap:6px; font-weight:700;">
                <span>⚡</span> Auto-Distribusi Merata
            </button>
        </form>
        <a href="<?= BASE_URL ?>/admin/tutorial-rpl-pengaturan-generate.php" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:6px;">
            <span>⚡</span> Pengaturan &amp; Generate
        </a>
        <a href="<?= BASE_URL ?>/admin/tutorial-rpl-kelas.php" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:6px;">
            <span>🏫</span> Kelola Kelompok RPL
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="margin-bottom:20px;">
        <?= sanitize($message) ?>
    </div>
<?php endif; ?>

<!-- Metrik Cards -->
<div class="stat-grid" style="margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-icon blue">👥</div>
        <div class="stat-info">
            <h3><?= $totalAllStudents ?></h3>
            <p>Total Mahasiswa RPL</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">✅</div>
        <div class="stat-info">
            <h3 style="color:#15803d;"><?= $totalPlotted ?></h3>
            <p>Sudah Terplot</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">⏳</div>
        <div class="stat-info">
            <h3 style="color:#d97706;"><?= $totalUnplotted ?></h3>
            <p>Belum Masuk Kelompok</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">🏫</div>
        <div class="stat-info">
            <h3><?= count($classes) ?></h3>
            <p>Kelompok Kelas RPL</p>
        </div>
    </div>
</div>

<!-- Panel Filter & Pencarian -->
<div class="card" style="margin-bottom:20px; border-radius:12px;">
    <div class="card-body" style="padding:16px 20px;">
        <form method="GET" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
            <div style="flex:1; min-width:200px;">
                <input type="text" name="q" value="<?= sanitize($searchTerm) ?>" class="form-control" placeholder="Cari nama, NIM, atau prodi..." style="width:100%;">
            </div>
            <div style="min-width:180px;">
                <select name="class_id" class="form-control" style="width:100%;">
                    <option value="0">-- Semua Kelompok --</option>
                    <?php foreach ($classes as $c): ?>
                        <?php $sisa = max(0, $c['kuota'] - $c['terisi']); ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $selectedClassId === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= sanitize($c['nama_kelompok']) ?> (Sisa: <?= $sisa ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:160px;">
                <select name="status_plot" class="form-control" style="width:100%;">
                    <option value="all" <?= $statusPlot === 'all' ? 'selected' : '' ?>>Semua Status Plot</option>
                    <option value="unplotted" <?= $statusPlot === 'unplotted' ? 'selected' : '' ?>>⏳ Belum Terplot (<?= $totalUnplotted ?>)</option>
                    <option value="plotted" <?= $statusPlot === 'plotted' ? 'selected' : '' ?>>✅ Sudah Terplot (<?= $totalPlotted ?>)</option>
                </select>
            </div>
            <div style="display:flex; gap:6px;">
                <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:4px;">
                    <span>🔍</span> Filter
                </button>
                <?php if ($searchTerm !== '' || $selectedClassId > 0 || $statusPlot !== 'all'): ?>
                    <a href="?" class="btn btn-secondary" title="Reset Filter">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Form Batch Plotting & Tabel Peserta -->
<form method="POST" id="form-batch-action">
    <!-- Bar Aksi Massal (Floating saat ada mahasiswa dicentang) -->
    <div id="batch-action-bar" style="display:none; background:#0f172a; color:#fff; border-radius:12px; padding:12px 18px; margin-bottom:16px; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; box-shadow:0 10px 25px rgba(15,23,42,0.35);">
        <div style="display:flex; align-items:center; gap:10px;">
            <span style="font-size:18px;">🔘</span>
            <span id="selected-count-label" style="font-weight:700; font-size:14px;">0 Mahasiswa Terpilih</span>
        </div>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <select name="target_class_id" class="form-control" style="font-size:12.5px; padding:6px 10px; max-width:240px; background:#fff; color:#0f172a;">
                <option value="0">-- Pilih Kelompok Tujuan --</option>
                <?php foreach ($classes as $c): ?>
                    <?php $sisa = max(0, $c['kuota'] - $c['terisi']); ?>
                    <option value="<?= (int)$c['id'] ?>">
                        <?= sanitize($c['nama_kelompok']) ?> (Sisa: <?= $sisa ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="action" value="batch_plot" class="btn btn-success btn-sm" style="font-weight:700; display:inline-flex; align-items:center; gap:4px;">
                <span>📥</span> Plotkan Terpilih
            </button>
            <button type="submit" name="action" value="batch_unplot" class="btn btn-danger btn-sm" onclick="return confirm('Lepas plotting untuk mahasiswa terpilih?');" style="font-weight:700; display:inline-flex; align-items:center; gap:4px;">
                <span>✕</span> Lepas Plotting Terpilih
            </button>
        </div>
    </div>

    <!-- Tabel Daftar Peserta RPL -->
    <div class="card" style="box-shadow:var(--shadow); border-radius:12px; overflow:hidden;">
        <div class="card-header" style="background:#f8fafc; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <span style="font-weight:700; color:var(--text-dark);">
                📋 Daftar Mahasiswa RPL (<?= count($students) ?> data ditampilkan)
            </span>
            <span class="badge badge-primary" style="font-size:11.5px;">
                Program: Rekognisi Pembelajaran Lampau (RPL)
            </span>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($students)): ?>
                <div style="text-align:center; padding:48px 20px; color:#64748b;">
                    <div style="font-size:40px; margin-bottom:8px;">🔍</div>
                    <h4 style="margin:0 0 4px 0; color:#334155;">Tidak Ada Mahasiswa Ditemukan</h4>
                    <p style="margin:0; font-size:13.5px;">Coba sesuaikan kata kunci pencarian atau filter status plotting.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" style="margin:0; width:100%;">
                        <thead>
                            <tr style="background:#f1f5f9; text-align:left; font-size:12.5px; color:#475569;">
                                <th style="padding:12px 14px; width:36px; text-align:center;">
                                    <input type="checkbox" id="check-all" onclick="toggleSelectAll(this)" style="cursor:pointer; transform:scale(1.15);">
                                </th>
                                <th style="padding:12px 14px; width:40px;">No</th>
                                <th style="padding:12px 14px;">Nama Mahasiswa</th>
                                <th style="padding:12px 14px;">NIM &amp; Program Studi</th>
                                <th style="padding:12px 14px; text-align:center;">Status CBT Pretes</th>
                                <th style="padding:12px 14px;">Kelompok RPL Saat Ini</th>
                                <th style="padding:12px 14px;">Tutor Pembimbing</th>
                                <th style="padding:12px 14px; text-align:center; width:150px;">Aksi Plotting</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $idx => $st): ?>
                                <tr style="border-bottom:1px solid #e2e8f0; font-size:13px; background:<?= empty($st['class_id']) ? '#fffbeb' : '#ffffff' ?>;">
                                    <td style="padding:12px 14px; text-align:center; vertical-align:middle;">
                                        <input type="checkbox" name="user_ids[]" value="<?= (int)$st['id'] ?>" class="user-checkbox" data-member-id="<?= (int)($st['member_id'] ?? 0) ?>" onchange="updateSelectedCount()" style="cursor:pointer; transform:scale(1.15);">
                                        <?php if (!empty($st['member_id'])): ?>
                                            <input type="checkbox" name="member_ids[]" value="<?= (int)$st['member_id'] ?>" id="member-chk-<?= (int)$st['id'] ?>" style="display:none;">
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:12px 14px; color:#64748b; vertical-align:middle;"><?= $idx + 1 ?></td>
                                    <td style="padding:12px 14px; vertical-align:middle;">
                                        <div style="font-weight:700; color:#0f172a;">
                                            <?= sanitize($st['nama_lengkap']) ?>
                                        </div>
                                        <?php $wa = !empty($st['no_hp']) ? $st['no_hp'] : (!empty($st['whatsapp']) ? $st['whatsapp'] : ''); ?>
                                        <?php if (!empty($wa)): ?>
                                            <a href="https://wa.me/<?= preg_replace('/\D/', '', $wa) ?>" target="_blank" rel="noopener noreferrer" style="font-size:11.5px; color:#15803d; text-decoration:none; display:inline-flex; align-items:center; gap:3px; margin-top:2px;">
                                                <span>💬</span> <?= sanitize($wa) ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:12px 14px; vertical-align:middle;">
                                        <strong><?= sanitize($st['nim'] ?: '-') ?></strong>
                                        <small style="color:#64748b; display:block; font-size:11.5px;">
                                            <?= sanitize($st['program_studi'] ?: ($st['fakultas'] ?: 'Program RPL')) ?>
                                        </small>
                                    </td>
                                    <td style="padding:12px 14px; text-align:center; vertical-align:middle;">
                                        <?php if ($st['cbt_score'] !== null): ?>
                                            <?php if ($st['cbt_score'] >= 80.0): ?>
                                                <span class="badge badge-emerald" style="font-size:11px; padding:3px 8px;">
                                                    ✅ <?= sanitize($st['cbt_status']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-warning" style="font-size:11px; padding:3px 8px; font-weight:700;">
                                                    ⚠️ <?= sanitize($st['cbt_status']) ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-secondary" style="font-size:11px; padding:3px 8px;">
                                                <?= sanitize($st['cbt_status']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:12px 14px; vertical-align:middle;">
                                        <?php if (!empty($st['class_id'])): ?>
                                            <div style="font-weight:700; color:#0369a1;">
                                                <?= sanitize($st['nama_kelompok']) ?>
                                            </div>
                                            <span class="badge badge-emerald" style="font-size:10.5px; padding:2px 6px;">
                                                Terdaftar
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-warning" style="font-size:11px; font-weight:700; padding:3px 8px;">
                                                ⏳ Belum Memiliki Kelompok
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:12px 14px; vertical-align:middle;">
                                        <?php if (!empty($st['dosen_nama'])): ?>
                                            <div style="font-weight:600; color:#1e293b;">
                                                <?= sanitize($st['dosen_nama']) ?>
                                            </div>
                                            <?php if (!empty($st['dosen_wa'])): ?>
                                                <small style="color:#15803d; font-size:11px;">WA: <?= sanitize($st['dosen_wa']) ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#94a3b8; font-size:12px;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:12px 14px; text-align:center; vertical-align:middle;">
                                        <div style="display:flex; gap:4px; justify-content:center; align-items:center;">
                                            <button type="button" class="btn btn-sm btn-primary" onclick="openSinglePlotModal(<?= (int)$st['id'] ?>, '<?= htmlspecialchars(addslashes($st['nama_lengkap']), ENT_QUOTES) ?>', <?= (int)($st['class_id'] ?? 0) ?>)" style="padding:3px 8px; font-size:11.5px;" title="Atur Kelompok">
                                                <?= empty($st['class_id']) ? '➕ Plot' : '🔄 Pindah' ?>
                                            </button>
                                            <?php if (!empty($st['member_id'])): ?>
                                                <button type="button" class="btn btn-sm btn-secondary" onclick="unplotSingle(<?= (int)$st['member_id'] ?>, '<?= htmlspecialchars(addslashes($st['nama_lengkap']), ENT_QUOTES) ?>')" style="padding:3px 8px; font-size:11.5px;" title="Lepas dari kelompok">
                                                    ✕
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- Modal Plotting Individu -->
<div id="modal-single-plot" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center; padding:16px;">
    <div style="background:#fff; border-radius:14px; width:100%; max-width:440px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); overflow:hidden;">
        <div style="padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin:0; font-size:15px; font-weight:700; color:#1e293b;">Plotting Kelompok Mahasiswa</h4>
            <button type="button" onclick="document.getElementById('modal-single-plot').style.display='none';" style="background:none; border:none; font-size:18px; cursor:pointer; color:#64748b;">✕</button>
        </div>
        <form method="POST" style="padding:20px;">
            <input type="hidden" name="action" value="plot_single">
            <input type="hidden" name="user_id" id="single-user-id" value="">
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:12.5px; color:#64748b; margin-bottom:2px;">Mahasiswa:</label>
                <div id="single-user-name" style="font-size:15px; font-weight:700; color:#0f172a;"></div>
            </div>
            <div style="margin-bottom:18px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:5px; color:#334155;">Pilih Kelompok Kelas Tujuan <span style="color:#ef4444;">*</span></label>
                <select name="class_id" id="single-class-id" class="form-control" style="width:100%;" required>
                    <option value="">-- Pilih Kelompok RPL --</option>
                    <?php foreach ($classes as $c): ?>
                        <?php $sisa = max(0, $c['kuota'] - $c['terisi']); ?>
                        <option value="<?= (int)$c['id'] ?>">
                            <?= sanitize($c['nama_kelompok']) ?> (Tutor: <?= sanitize($c['dosen_nama'] ?: '-') ?> | Sisa: <?= $sisa ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:14px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('modal-single-plot').style.display='none';">Batal</button>
                <button type="submit" class="btn btn-primary btn-sm">Simpan Plotting</button>
            </div>
        </form>
    </div>
</div>

<!-- Form tersembunyi untuk Lepas Plotting Satuan -->
<form method="POST" id="form-unplot-single" style="display:none;">
    <input type="hidden" name="action" value="unplot_single">
    <input type="hidden" name="member_id" id="unplot-single-member-id" value="">
</form>

<script>
function toggleSelectAll(master) {
    const boxes = document.querySelectorAll('.user-checkbox');
    boxes.forEach(cb => {
        cb.checked = master.checked;
        const memberChk = document.getElementById('member-chk-' + cb.value);
        if (memberChk) memberChk.checked = master.checked;
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const checked = document.querySelectorAll('.user-checkbox:checked');
    const bar = document.getElementById('batch-action-bar');
    const label = document.getElementById('selected-count-label');

    // Sinkronkan hidden member_ids checkboxes
    document.querySelectorAll('.user-checkbox').forEach(cb => {
        const memberChk = document.getElementById('member-chk-' + cb.value);
        if (memberChk) memberChk.checked = cb.checked;
    });

    if (checked.length > 0) {
        bar.style.display = 'flex';
        label.textContent = checked.length + ' Mahasiswa Terpilih';
    } else {
        bar.style.display = 'none';
        const master = document.getElementById('check-all');
        if (master) master.checked = false;
    }
}

function openSinglePlotModal(userId, userName, currentClassId) {
    document.getElementById('single-user-id').value = userId;
    document.getElementById('single-user-name').textContent = userName;
    document.getElementById('single-class-id').value = currentClassId || '';
    document.getElementById('modal-single-plot').style.display = 'flex';
}

function unplotSingle(memberId, userName) {
    if (confirm('Lepas mahasiswa ' + userName + ' dari kelompok kelas saat ini?')) {
        document.getElementById('unplot-single-member-id').value = memberId;
        document.getElementById('form-unplot-single').submit();
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
