<?php
/**
 * LPPAI Corner - Pendaftaran Tutorial (Terpadu)
 */
define('PAGE_TITLE', 'Pendaftaran Tutorial');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$user = getCurrentUser();
$pdo = getDBConnection();
$message = '';
$msgType = '';

$gelombangs = [
    'gel1'    => ['label' => 'Tutorial Gelombang 1 (Ganjil)', 'annType' => 'pendaftaran_gel1'],
    'gel2'    => ['label' => 'Tutorial Gelombang 2 (Genap)',  'annType' => 'pendaftaran_gel2'],
    'mandiri' => ['label' => 'Tutorial Mandiri',              'annType' => 'pendaftaran_mandiri']
];

// Handle Form Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['daftar_tutorial'])) {
    $token     = $_POST['csrf_token'] ?? '';
    $classId   = (int)($_POST['class_id'] ?? 0);
    $gelombang = $_POST['gelombang'] ?? '';

    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid. Silakan muat ulang halaman.';
        $msgType = 'danger';
    } elseif ($classId <= 0 || !isset($gelombangs[$gelombang])) {
        $message = 'Pilih kelas tutorial yang valid.';
        $msgType = 'danger';
    } else {
        // Cek pengumuman aktif
        $stmtAnn = $pdo->prepare("SELECT id FROM announcements WHERE tipe = ? AND is_active = 1 LIMIT 1");
        $stmtAnn->execute([$gelombangs[$gelombang]['annType']]);
        if (!$stmtAnn->fetch()) {
            $message = 'Pendaftaran untuk gelombang ini sedang ditutup.';
            $msgType = 'danger';
        } else {
            // Cek apakah sudah daftar di gelombang ini
            $stmtReg = $pdo->prepare("
                SELECT tr.id FROM tutorial_registrations tr 
                JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id 
                WHERE tr.user_id = ? AND tc.gelombang = ?
            ");
            $stmtReg->execute([$user['id'], $gelombang]);
            if ($stmtReg->fetch()) {
                $message = 'Anda sudah terdaftar di gelombang ini.';
                $msgType = 'warning';
            } else {
                // Pastikan kelas tersebut ada dan sesuai gelombang
                $stmtCek = $pdo->prepare("SELECT id FROM tutorial_classes WHERE id = ? AND gelombang = ?");
                $stmtCek->execute([$classId, $gelombang]);
                if (!$stmtCek->fetch()) {
                    $message = 'Kelas tidak valid.';
                    $msgType = 'danger';
                } else {
                    $stmtInsert = $pdo->prepare("INSERT INTO tutorial_registrations (user_id, tutorial_class_id, status) VALUES (?, ?, 'terdaftar')");
                    $stmtInsert->execute([$user['id'], $classId]);
                    $message = 'Pendaftaran tutorial berhasil! Tunggu konfirmasi jadwal dari TU.';
                    $msgType = 'success';
                }
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
    <h2 class="page-title"><?= PAGE_TITLE ?></h2>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<?php
$adaBuka = false;
foreach ($gelombangs as $gelKey => $g):
    // Cek pengumuman aktif
    $stmtAnn = $pdo->prepare("SELECT * FROM announcements WHERE tipe = ? AND is_active = 1 ORDER BY created_at DESC LIMIT 1");
    $stmtAnn->execute([$g['annType']]);
    $announcement = $stmtAnn->fetch();

    if ($announcement) {
        $adaBuka = true;
    }

    // Cek registrasi user di gelombang ini
    $stmtReg = $pdo->prepare("
        SELECT tr.*, tc.nama_kelas, tc.mata_kuliah, tc.dosen_pengampu, tc.hari, tc.jam, tc.ruangan 
        FROM tutorial_registrations tr
        JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
        WHERE tr.user_id = ? AND tc.gelombang = ?
        ORDER BY tr.created_at DESC LIMIT 1
    ");
    $stmtReg->execute([$user['id'], $gelKey]);
    $sudahDaftar = $stmtReg->fetch();

    // Ambil kelas tersedia
    $stmtKelas = $pdo->prepare("SELECT * FROM tutorial_classes WHERE gelombang = ? ORDER BY nama_kelas");
    $stmtKelas->execute([$gelKey]);
    $kelasTersedia = $stmtKelas->fetchAll();

    if ($announcement || $sudahDaftar): // Tampilkan hanya jika ada pengumuman buka ATAU sudah pernah daftar
?>
<div class="card" style="margin-bottom: 30px; border-top: 4px solid var(--primary);">
    <div class="card-header" style="background:#f8fafc;">
        <h3 style="margin:0; font-size:18px;"><?= $g['label'] ?></h3>
    </div>
    <div class="card-body">
        
        <?php if ($announcement && !$sudahDaftar): ?>
            <!-- Info Pengumuman -->
            <div class="announcement-card" style="margin-bottom:20px; background:#f0f9ff; border-left:4px solid #0ea5e9;">
                <div class="ann-title" style="color:#0ea5e9;"><?= sanitize($announcement['judul']) ?></div>
                <div class="ann-date">🕐 <?= date('d M Y, H:i', strtotime($announcement['created_at'])) ?></div>
                <div class="ann-content"><?= nl2br(sanitize($announcement['konten'])) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($sudahDaftar): ?>
            <!-- Status Terdaftar -->
            <div style="padding:16px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px;">
                <h4 style="margin-top:0; color:#166534;">✅ Status Pendaftaran: Terdaftar</h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px; margin-top:16px;">
                    <div><strong style="font-size:12px;color:#166534;">KELAS</strong><p style="margin:4px 0 0;font-weight:bold;"><?= sanitize($sudahDaftar['nama_kelas']) ?></p></div>
                    <div><strong style="font-size:12px;color:#166534;">MATA KULIAH</strong><p style="margin:4px 0 0;"><?= sanitize($sudahDaftar['mata_kuliah']) ?></p></div>
                    <div><strong style="font-size:12px;color:#166534;">DOSEN</strong><p style="margin:4px 0 0;"><?= sanitize($sudahDaftar['dosen_pengampu'] ?: '-') ?></p></div>
                    <div><strong style="font-size:12px;color:#166534;">JADWAL</strong><p style="margin:4px 0 0;"><?= sanitize($sudahDaftar['hari']) ?>, <?= sanitize($sudahDaftar['jam']) ?></p></div>
                    <div><strong style="font-size:12px;color:#166534;">RUANGAN</strong><p style="margin:4px 0 0;"><?= sanitize($sudahDaftar['ruangan'] ?: '-') ?></p></div>
                    <div>
                        <strong style="font-size:12px;color:#166534;">STATUS</strong>
                        <p style="margin:4px 0 0;">
                            <?php
                            $statusBadge = ['terdaftar'=>'badge-info','aktif'=>'badge-primary','lulus'=>'badge-success','tidak_lulus'=>'badge-danger','mengundurkan_diri'=>'badge-warning'];
                            ?>
                            <span class="badge <?= $statusBadge[$sudahDaftar['status']] ?? 'badge-info' ?>"><?= ucfirst(str_replace('_', ' ', $sudahDaftar['status'])) ?></span>
                        </p>
                    </div>
                </div>
            </div>
        
        <?php elseif ($announcement): ?>
            <!-- Form Pendaftaran -->
            <?php if (empty($kelasTersedia)): ?>
                <div class="alert alert-warning">Belum ada kelas yang ditambahkan oleh TU untuk gelombang ini.</div>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="gelombang" value="<?= $gelKey ?>">
                    <label style="font-weight:600;margin-bottom:12px;display:block;">Pilih Kelas <span style="color:red">*</span></label>
                    <div style="display:grid;gap:12px;margin-bottom:16px;">
                        <?php foreach ($kelasTersedia as $k): ?>
                        <label style="display:flex;align-items:flex-start;gap:14px;padding:16px;border:2px solid #e0e0e0;border-radius:12px;cursor:pointer;transition:all .2s;" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='#e0e0e0'">
                            <input type="radio" name="class_id" value="<?= $k['id'] ?>" required style="margin-top:3px;width:18px;height:18px;">
                            <div style="flex:1;">
                                <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                                    <strong style="font-size:16px;"><?= sanitize($k['nama_kelas']) ?></strong>
                                    <span class="badge badge-primary"><?= sanitize($k['mata_kuliah']) ?></span>
                                </div>
                                <div style="display:flex;gap:20px;flex-wrap:wrap;color:var(--text-muted);font-size:13px;">
                                    <span>👨‍🏫 <?= sanitize($k['dosen_pengampu'] ?: '-') ?></span>
                                    <span>📅 <?= sanitize($k['hari'] ?: '-') ?>, <?= sanitize($k['jam'] ?: '-') ?></span>
                                    <span>🏫 <?= sanitize($k['ruangan'] ?: '-') ?></span>
                                    <span>👥 Kuota: <?= $k['kuota'] ?></span>
                                </div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="daftar_tutorial" class="btn btn-primary">📝 Daftar di <?= $g['label'] ?></button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; endforeach; ?>

<?php if (!$adaBuka && !isset($sudahDaftar)): ?>
    <!-- Fallback if nothing is open -->
    <div class="empty-state card">
        <div class="icon">🔒</div>
        <h3>Pendaftaran Belum Dibuka</h3>
        <p>Saat ini belum ada gelombang tutorial yang membuka pendaftaran.</p>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
