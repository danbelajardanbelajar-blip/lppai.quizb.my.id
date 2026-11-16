<?php
/**
 * LPPAI Corner - Admin: Data Peserta & Plotting Tutorial RPL Online
 */
define('PAGE_TITLE', 'Data Peserta & Plotting RPL Online');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
requireAdmin();

$pdo = getDBConnection();
ensureTutorialRplTables($pdo);

$message = '';
$msgType = '';

$selectedClassId = (int)($_GET['class_id'] ?? 0);

// Proses Plotting / Kelola Peserta
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'plot_student') {
        $classId = (int)($_POST['class_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($classId > 0 && $userId > 0) {
            try {
                // Hapus plotting lama di periode ini jika ada (1 mahasiswa 1 kelompok)
                $pdo->prepare("DELETE FROM tutorial_rpl_members WHERE user_id = ?")->execute([$userId]);
                $stmt = $pdo->prepare("INSERT INTO tutorial_rpl_members (class_id, user_id) VALUES (?, ?)");
                $stmt->execute([$classId, $userId]);
                $message = 'Mahasiswa berhasil diplot ke kelompok kelas.';
                $msgType = 'success';
            } catch (Throwable $e) {
                $message = 'Gagal memplotting mahasiswa: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'remove_member') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        if ($memberId > 0) {
            try {
                $pdo->prepare("DELETE FROM tutorial_rpl_members WHERE id = ?")->execute([$memberId]);
                $message = 'Mahasiswa berhasil dikeluarkan dari kelompok kelas.';
                $msgType = 'success';
            } catch (Throwable $e) {
                $message = 'Gagal menghapus mahasiswa: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

$classes = tutorialRplGetClasses($pdo);

// Ambil daftar mahasiswa RPL yang terdaftar atau layak mengikuti Tutorial RPL
// Kriteria: role = 'mahasiswa' dan (jenis_program = 'RPL' atau username diawali 'RPL' atau pernah daftar pretes RPL)
$rplStudentsQuery = "
    SELECT u.id, u.nama_lengkap, u.nim, u.program_studi, u.fakultas, u.whatsapp,
           m.id AS member_id, m.class_id, m.drive_verified, m.exam_submitted,
           c.nama_kelompok, c.dosen_nama
    FROM users u
    LEFT JOIN tutorial_rpl_members m ON u.id = m.user_id
    LEFT JOIN tutorial_rpl_classes c ON m.class_id = c.id
    WHERE u.role = 'mahasiswa'
      AND (
          UPPER(u.jenis_program) = 'RPL'
          OR u.id IN (SELECT user_id FROM pretes_registrations WHERE periode LIKE '%RPL%')
      )
";
if ($selectedClassId > 0) {
    $rplStudentsQuery .= " AND m.class_id = " . (int)$selectedClassId;
}
$rplStudentsQuery .= " ORDER BY (m.class_id IS NULL) DESC, u.nama_lengkap ASC";

$students = [];
try {
    $students = $pdo->query($rplStudentsQuery)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $students = [];
}

include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0; font-size:22px; font-weight:800; color:var(--text-dark);">
            👥 Data Peserta & Plotting Tutorial RPL Online
        </h2>
        <p style="margin:0; font-size:13.5px; color:#64748b;">
            Pemetaan dan pembagian kelompok belajar mandiri online bagi mahasiswa program RPL.
        </p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
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

<!-- Filter & Ringkasan -->
<div class="card" style="margin-bottom:20px; border-radius:12px;">
    <div class="card-body" style="padding:16px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <label style="font-size:13.5px; font-weight:600; color:#334155;">Filter Kelompok:</label>
            <select class="form-control" onchange="location.href='?class_id=' + this.value;" style="min-width:220px;">
                <option value="0">-- Semua Peserta RPL (<?= count($students) ?>) --</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $selectedClassId === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= sanitize($c['nama_kelompok']) ?> (<?= (int)$c['terisi'] ?>/<?= (int)$c['kuota'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <span class="badge badge-emerald" style="font-size:12.5px; padding:6px 12px;">
                Total Terdata: <?= count($students) ?> Mahasiswa RPL
            </span>
        </div>
    </div>
</div>

<!-- Tabel Peserta RPL -->
<div class="card" style="box-shadow:var(--shadow); border-radius:12px; overflow:hidden;">
    <div class="card-header" style="background:#f8fafc; font-weight:700; color:var(--text-dark);">
        📋 Daftar Mahasiswa Jalur RPL
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($students)): ?>
            <div style="text-align:center; padding:40px 20px; color:#64748b;">
                <div style="font-size:36px; margin-bottom:8px;">👥</div>
                <h4 style="margin:0 0 4px 0; color:#334155;">Tidak Ada Mahasiswa RPL Ditemukan</h4>
                <p style="margin:0; font-size:13.5px;">Belum ada data mahasiswa dengan penanda program RPL.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" style="margin:0; width:100%;">
                    <thead>
                        <tr style="background:#f1f5f9; text-align:left; font-size:12.5px; color:#475569;">
                            <th style="padding:12px 16px; width:40px;">No</th>
                            <th style="padding:12px 16px;">Mahasiswa</th>
                            <th style="padding:12px 16px;">NIM / Prodi</th>
                            <th style="padding:12px 16px;">Kelompok Kelas</th>
                            <th style="padding:12px 16px;">Tutor Pembimbing</th>
                            <th style="padding:12px 16px; text-align:center;">Status Plotting</th>
                            <th style="padding:12px 16px; text-align:center; width:140px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $idx => $st): ?>
                            <tr style="border-bottom:1px solid #e2e8f0; font-size:13.5px;">
                                <td style="padding:14px 16px; color:#64748b; vertical-align:middle;"><?= $idx + 1 ?></td>
                                <td style="padding:14px 16px; vertical-align:middle;">
                                    <div style="font-weight:700; color:#0f172a;">
                                        <?= sanitize($st['nama_lengkap']) ?>
                                    </div>
                                    <?php if (!empty($st['whatsapp'])): ?>
                                        <a href="https://wa.me/<?= preg_replace('/\D/', '', $st['whatsapp']) ?>" target="_blank" rel="noopener noreferrer" style="font-size:11.5px; color:#15803d; text-decoration:none; display:inline-flex; align-items:center; gap:3px; margin-top:2px;">
                                            <span>💬</span> <?= sanitize($st['whatsapp']) ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 16px; vertical-align:middle;">
                                    <strong><?= sanitize($st['nim'] ?: '-') ?></strong>
                                    <small style="color:#64748b; display:block; font-size:12px;">
                                        <?= sanitize($st['program_studi'] ?: ($st['fakultas'] ?: 'RPL')) ?>
                                    </small>
                                </td>
                                <td style="padding:14px 16px; vertical-align:middle;">
                                    <?php if (!empty($st['nama_kelompok'])): ?>
                                        <span style="font-weight:600; color:#0369a1;">
                                            <?= sanitize($st['nama_kelompok']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#ef4444; font-weight:600; font-size:12px;">Belum Diplot</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 16px; vertical-align:middle;">
                                    <?= !empty($st['dosen_nama']) ? sanitize($st['dosen_nama']) : '<span style="color:#94a3b8;">-</span>' ?>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                    <?php if (!empty($st['class_id'])): ?>
                                        <span class="badge badge-emerald" style="font-size:11px;">Sudah Terplot</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning" style="font-size:11px;">Belum Masuk Kelas</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                    <form method="POST" style="display:flex; gap:6px; justify-content:center; align-items:center;">
                                        <input type="hidden" name="user_id" value="<?= (int)$st['id'] ?>">
                                        <?php if (empty($st['class_id'])): ?>
                                            <input type="hidden" name="action" value="plot_student">
                                            <select name="class_id" class="form-control" style="font-size:11.5px; padding:3px 6px; max-width:130px;" required>
                                                <option value="">Pilih Kelompok</option>
                                                <?php foreach ($classes as $c): ?>
                                                    <option value="<?= (int)$c['id'] ?>"><?= sanitize($c['nama_kelompok']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-primary" style="padding:3px 8px; font-size:11px;">
                                                Plot
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="action" value="remove_member">
                                            <input type="hidden" name="member_id" value="<?= (int)$st['member_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('Keluarkan mahasiswa dari kelompok ini?');" style="padding:3px 8px; font-size:11px;" title="Keluarkan dari kelas">
                                                ✕ Lepas
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
