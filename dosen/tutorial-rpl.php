<?php
/**
 * LPPAI Corner - Dosen: Bimbingan & Penilaian Tutorial RPL Online
 */
define('PAGE_TITLE', 'Tutorial RPL Online (Bimbingan Dosen)');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
requireDosen();

$user = getCurrentUser();
$pdo = getDBConnection();
ensureTutorialRplTables($pdo);

$userId = (int)($user['id'] ?? 0);
$nim = trim((string)($user['nim'] ?? ''));
$nama = trim((string)($user['nama_lengkap'] ?? ''));

// Ambil kelompok yang diampu dosen login (atau semua jika tester 12345)
$isTester = ($nim === '12345' || (string)($user['username'] ?? '') === '12345' || $userId === 12345);

$sql = "
    SELECT c.*,
           (SELECT COUNT(*) FROM tutorial_rpl_members m WHERE m.class_id = c.id) AS terisi
    FROM tutorial_rpl_classes c
    WHERE c.is_active = 1
";
$params = [];
if (!$isTester) {
    $sql .= " AND (c.dosen_id = ? OR (c.dosen_nim IS NOT NULL AND c.dosen_nim = ?) OR c.dosen_nama = ?)";
    $params = [$userId, $nim, $nama];
}
$sql .= " ORDER BY c.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$myClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selectedClassId = (int)($_GET['class_id'] ?? ($myClasses[0]['id'] ?? 0));
$currentClass = null;
foreach ($myClasses as $c) {
    if ((int)$c['id'] === $selectedClassId) {
        $currentClass = $c;
        break;
    }
}

$members = [];
if ($currentClass) {
    $members = tutorialRplGetMembers($pdo, (int)$currentClass['id']);
}

// Proses verifikasi tugas oleh tutor
$message = '';
$msgType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    if ($action === 'verify_submission') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $status = (int)($_POST['verify_status'] ?? 1);
        $notes = trim($_POST['notes'] ?? '');

        if ($memberId > 0) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE tutorial_rpl_members 
                    SET drive_verified = ?, drive_notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([$status, $notes ?: null, $memberId]);
                $message = 'Status verifikasi tugas mahasiswa berhasil diperbarui.';
                $msgType = 'success';
                // Refresh members
                if ($currentClass) {
                    $members = tutorialRplGetMembers($pdo, (int)$currentClass['id']);
                }
            } catch (Throwable $e) {
                $message = 'Gagal menyimpan verifikasi: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0; font-size:22px; font-weight:800; color:var(--text-dark);">
            🌐 Tutorial RPL Online — Bimbingan Dosen
        </h2>
        <p style="margin:0; font-size:13.5px; color:#64748b;">
            Tinjau video rekaman hafalan di Google Drive, lakukan verifikasi penugasan, dan pantau penyelesaian Ujian Tulis Tutorial mahasiswa.
        </p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="margin-bottom:20px;">
        <?= sanitize($message) ?>
    </div>
<?php endif; ?>

<?php if (empty($myClasses)): ?>
    <div class="card" style="padding:48px 20px; text-align:center; color:#64748b; border-radius:12px;">
        <div style="font-size:42px; margin-bottom:12px;">🏫</div>
        <h3 style="margin:0 0 6px 0; color:#334155;">Belum Ada Kelompok Tutorial RPL</h3>
        <p style="margin:0; font-size:14px;">
            Anda belum ditugaskan sebagai tutor pada kelompok Tutorial RPL Online yang aktif.<br>
            Silakan hubungi administrator LPPAI jika Anda telah dijadwalkan mengampu kelompok RPL.
        </p>
    </div>
<?php else: ?>
    <!-- Pilihan Tab Kelompok -->
    <div style="display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap;">
        <?php foreach ($myClasses as $cls): ?>
            <?php $isActiveTab = ((int)$cls['id'] === $selectedClassId); ?>
            <a href="?class_id=<?= (int)$cls['id'] ?>" class="btn <?= $isActiveTab ? 'btn-primary' : 'btn-secondary' ?>" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; text-decoration:none;">
                <span>🏫</span> <?= sanitize($cls['nama_kelompok']) ?>
                <span class="badge" style="background:<?= $isActiveTab ? 'rgba(255,255,255,0.25)' : '#e2e8f0' ?>; color:<?= $isActiveTab ? '#fff' : '#475569' ?>; font-size:11px;">
                    <?= (int)$cls['terisi'] ?> Mhs
                </span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Info Kelompok Aktif -->
    <?php if ($currentClass): ?>
        <div class="card" style="margin-bottom:20px; border-left:4px solid #059669; border-radius:12px;">
            <div class="card-body" style="padding:16px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
                <div>
                    <h3 style="margin:0 0 4px 0; font-size:17px; font-weight:700; color:#065f46;">
                        <?= sanitize($currentClass['nama_kelompok']) ?>
                    </h3>
                    <p style="margin:0; font-size:13px; color:#64748b;">
                        <?= sanitize($currentClass['periode']) ?> · <?= sanitize($currentClass['gelombang'] ?? 'Gelombang 1') ?>
                    </p>
                </div>
                <div>
                    <?php if (!empty($currentClass['drive_url'])): ?>
                        <a href="<?= sanitize($currentClass['drive_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
                            <span>📁</span> Buka Folder Tugas Google Drive ↗
                        </a>
                    <?php else: ?>
                        <span style="font-size:12.5px; color:#94a3b8; font-style:italic;">Folder Google Drive belum diatur oleh admin</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Tabel Anggota Mahasiswa Bimbingan -->
        <div class="card" style="box-shadow:var(--shadow); border-radius:12px; overflow:hidden;">
            <div class="card-header" style="background:#f8fafc; font-weight:700; color:var(--text-dark);">
                👥 Mahasiswa Bimbingan (<?= count($members) ?>)
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($members)): ?>
                    <div style="text-align:center; padding:40px 20px; color:#64748b;">
                        <p style="margin:0;">Belum ada mahasiswa yang diplot ke kelompok ini.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table" style="margin:0; width:100%;">
                            <thead>
                                <tr style="background:#f1f5f9; text-align:left; font-size:12.5px; color:#475569;">
                                    <th style="padding:12px 16px; width:40px;">No</th>
                                    <th style="padding:12px 16px;">Mahasiswa</th>
                                    <th style="padding:12px 16px;">NIM / Prodi</th>
                                    <th style="padding:12px 16px; text-align:center;">Verifikasi Tugas Drive</th>
                                    <th style="padding:12px 16px; text-align:center;">Ujian Tulis Tutorial</th>
                                    <th style="padding:12px 16px; text-align:center;">Presensi Sah</th>
                                    <th style="padding:12px 16px; text-align:center; width:160px;">Aksi Verifikasi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($members as $idx => $m): ?>
                                    <?php $isHadir = (!empty($m['drive_verified']) && !empty($m['exam_submitted'])); ?>
                                    <tr style="border-bottom:1px solid #e2e8f0; font-size:13.5px;">
                                        <td style="padding:14px 16px; color:#64748b; vertical-align:middle;"><?= $idx + 1 ?></td>
                                        <td style="padding:14px 16px; vertical-align:middle;">
                                            <div style="font-weight:700; color:#0f172a;"><?= sanitize($m['nama_lengkap']) ?></div>
                                            <?php if (!empty($m['whatsapp'])): ?>
                                                <a href="https://wa.me/<?= preg_replace('/\D/', '', $m['whatsapp']) ?>" target="_blank" rel="noopener noreferrer" style="font-size:11.5px; color:#15803d; text-decoration:none; display:inline-flex; align-items:center; gap:3px;">
                                                    <span>💬</span> <?= sanitize($m['whatsapp']) ?>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding:14px 16px; vertical-align:middle;">
                                            <strong><?= sanitize($m['nim'] ?: '-') ?></strong>
                                            <small style="color:#64748b; display:block; font-size:12px;"><?= sanitize($m['program_studi'] ?: '') ?></small>
                                        </td>
                                        <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                            <?php if ($m['drive_verified']): ?>
                                                <span class="badge badge-emerald" style="font-size:11px; padding:3px 8px;">✅ Diverifikasi</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning" style="font-size:11px; padding:3px 8px;">Belum Diverifikasi</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                            <?php if ($m['exam_submitted']): ?>
                                                <span class="badge badge-emerald" style="font-size:11px; padding:3px 8px;">
                                                    ✅ Selesai (<?= $m['nilai_ujian_tulis'] !== null ? (float)$m['nilai_ujian_tulis'] : '-' ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary" style="font-size:11px; padding:3px 8px;">Belum CBT</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                            <?php if ($isHadir): ?>
                                                <span class="badge badge-emerald" style="font-size:11.5px; font-weight:700; padding:4px 10px;">✅ Hadir Sah</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger" style="font-size:11px; padding:3px 8px;">Belum Lengkap</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding:14px 16px; text-align:center; vertical-align:middle;">
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="verify_submission">
                                                <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                                                <?php if ($m['drive_verified']): ?>
                                                    <input type="hidden" name="verify_status" value="0">
                                                    <button type="submit" class="btn btn-sm btn-secondary" style="padding:4px 8px; font-size:11px;" title="Batalkan verifikasi">
                                                        Batal Verifikasi
                                                    </button>
                                                <?php else: ?>
                                                    <input type="hidden" name="verify_status" value="1">
                                                    <button type="submit" class="btn btn-sm btn-success" style="padding:4px 8px; font-size:11px;" title="Tandai tugas video hafalan sah">
                                                        ✓ Verifikasi Tugas
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
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
