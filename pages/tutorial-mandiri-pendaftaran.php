<?php
/**
 * LPPAI Corner - Pendaftaran Tutorial Mandiri oleh Mahasiswa
 * Hanya bisa diakses oleh mahasiswa yang:
 *   1. Punya status 'tidak_lulus' di tutorial gel2, ATAU
 *   2. Belum pernah terdaftar di tutorial gel1 maupun gel2
 *      (namun pendaftaran mandiri dibuka oleh TU)
 * Dan TU sudah mengaktifkan pengumuman bertipe 'pendaftaran_mandiri'.
 */
define('PAGE_TITLE', 'Pendaftaran Tutorial Mandiri');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$user    = getCurrentUser();
$pdo     = getDBConnection();
$message = '';
$msgType = '';

// ── 1. Cek apakah TU sudah membuka pendaftaran mandiri ──────────────────────
$stmtAnn = $pdo->prepare(
    "SELECT * FROM announcements
     WHERE tipe = 'pendaftaran_mandiri' AND is_active = 1
     ORDER BY created_at DESC LIMIT 1"
);
$stmtAnn->execute();
$annMandiri = $stmtAnn->fetch();

// ── 2. Cek status tutorial mahasiswa (gel1 & gel2) ──────────────────────────
$stmtGel1 = $pdo->prepare(
    "SELECT tr.status FROM tutorial_registrations tr
     JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
     WHERE tr.user_id = ? AND tc.gelombang = 'gel1'
     ORDER BY tr.created_at DESC LIMIT 1"
);
$stmtGel1->execute([$user['id']]);
$statusGel1 = $stmtGel1->fetchColumn(); // false | 'lulus' | 'tidak_lulus' | dll

$stmtGel2 = $pdo->prepare(
    "SELECT tr.status FROM tutorial_registrations tr
     JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
     WHERE tr.user_id = ? AND tc.gelombang = 'gel2'
     ORDER BY tr.created_at DESC LIMIT 1"
);
$stmtGel2->execute([$user['id']]);
$statusGel2 = $stmtGel2->fetchColumn(); // false | 'lulus' | 'tidak_lulus' | dll

// ── 3. Cek apakah sudah terdaftar di mandiri ────────────────────────────────
$stmtMandiri = $pdo->prepare(
    "SELECT tr.*, tc.nama_kelas, tc.mata_kuliah, tc.dosen_pengampu, tc.hari, tc.jam, tc.ruangan
     FROM tutorial_registrations tr
     JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
     WHERE tr.user_id = ? AND tc.gelombang = 'mandiri'
     ORDER BY tr.created_at DESC LIMIT 1"
);
$stmtMandiri->execute([$user['id']]);
$sudahDaftar = $stmtMandiri->fetch();

// ── 4. Tentukan apakah mahasiswa BERHAK mendaftar mandiri ───────────────────
// Berhak jika: tidak lulus gel2, ATAU tidak lulus gel1 (dan tidak punya gel2),
// ATAU belum pernah ikut tutorial sama sekali (kebijakan terbuka)
$berhakDaftar = false;
$alasanTidakBerhak = '';

if ($statusGel2 === 'tidak_lulus') {
    $berhakDaftar = true;
} elseif ($statusGel2 === false && $statusGel1 === 'tidak_lulus') {
    $berhakDaftar = true;
} elseif ($statusGel2 === false && $statusGel1 === false) {
    // Belum ikut tutorial apapun — tetap bisa daftar mandiri jika dibuka
    $berhakDaftar = true;
} elseif ($statusGel1 === 'lulus' || $statusGel2 === 'lulus') {
    $alasanTidakBerhak = 'Anda sudah lulus tutorial. Tutorial mandiri tidak diperlukan.';
} else {
    // Status masih aktif/terdaftar — belum final
    $alasanTidakBerhak = 'Status tutorial Anda belum final. Silakan tunggu pengumuman kelulusan.';
}

// ── 5. Ambil kelas mandiri yang tersedia ────────────────────────────────────
$stmtKelas = $pdo->query(
    "SELECT * FROM tutorial_classes WHERE gelombang = 'mandiri' ORDER BY nama_kelas"
);
$kelasMandiri = $stmtKelas->fetchAll();

// ── 6. Handle form submission ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['daftar_mandiri'])) {
    $token   = $_POST['csrf_token'] ?? '';
    $classId = (int)($_POST['class_id'] ?? 0);

    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid. Silakan muat ulang halaman.';
        $msgType = 'danger';
    } elseif (!$annMandiri) {
        $message = 'Pendaftaran tutorial mandiri belum dibuka oleh TU.';
        $msgType = 'danger';
    } elseif (!$berhakDaftar) {
        $message = $alasanTidakBerhak ?: 'Anda tidak memenuhi syarat pendaftaran tutorial mandiri.';
        $msgType = 'danger';
    } elseif ($sudahDaftar) {
        $message = 'Anda sudah terdaftar di tutorial mandiri.';
        $msgType = 'warning';
    } elseif ($classId <= 0) {
        $message = 'Pilih kelas tutorial mandiri terlebih dahulu.';
        $msgType = 'danger';
    } else {
        // Validasi kelas ada dan gelombang-nya mandiri
        $stmtCek = $pdo->prepare(
            "SELECT * FROM tutorial_classes WHERE id = ? AND gelombang = 'mandiri'"
        );
        $stmtCek->execute([$classId]);
        $kelasTarget = $stmtCek->fetch();

        if (!$kelasTarget) {
            $message = 'Kelas yang dipilih tidak ditemukan.';
            $msgType = 'danger';
        } else {
            // Cek apakah sudah pernah daftar di kelas ini
            $stmtDuplikat = $pdo->prepare(
                "SELECT id FROM tutorial_registrations WHERE user_id = ? AND tutorial_class_id = ?"
            );
            $stmtDuplikat->execute([$user['id'], $classId]);
            if ($stmtDuplikat->fetch()) {
                $message = 'Anda sudah terdaftar di kelas ini sebelumnya.';
                $msgType = 'warning';
            } else {
                $stmtInsert = $pdo->prepare(
                    "INSERT INTO tutorial_registrations (user_id, tutorial_class_id, status)
                     VALUES (?, ?, 'terdaftar')"
                );
                $stmtInsert->execute([$user['id'], $classId]);
                $message = 'Pendaftaran tutorial mandiri berhasil! Silakan tunggu konfirmasi jadwal dari TU.';
                $msgType = 'success';

                // Refresh data
                $stmtMandiri->execute([$user['id']]);
                $sudahDaftar = $stmtMandiri->fetch();
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= $message ?></div>
<?php endif; ?>

<!-- ── Pengumuman TU ──────────────────────────────────────────── -->
<?php if ($annMandiri): ?>
<div class="announcement-card" style="margin-bottom:24px;">
    <div class="ann-title"><?= sanitize($annMandiri['judul']) ?></div>
    <div class="ann-date">🕐 <?= date('d M Y, H:i', strtotime($annMandiri['created_at'])) ?></div>
    <div class="ann-content"><?= nl2br(sanitize($annMandiri['konten'])) ?></div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <div class="icon">🔒</div>
            <h3>Pendaftaran Belum Dibuka</h3>
            <p>TU belum membuka pendaftaran tutorial mandiri. Silakan cek kembali nanti.</p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Status Kelulusan Mahasiswa ────────────────────────────── -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">📊 Status Tutorial Anda</div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
            <div style="background:var(--bg-light);border-radius:var(--radius);padding:16px;text-align:center;">
                <strong style="color:var(--text-muted);font-size:12px;display:block;margin-bottom:8px;">TUTORIAL GEL. 1</strong>
                <?php if ($statusGel1 === 'lulus'): ?>
                    <span class="badge badge-success" style="font-size:14px;padding:6px 16px;">✅ Lulus</span>
                <?php elseif ($statusGel1 === 'tidak_lulus'): ?>
                    <span class="badge badge-danger" style="font-size:14px;padding:6px 16px;">❌ Belum Lulus</span>
                <?php elseif ($statusGel1): ?>
                    <span class="badge badge-info" style="font-size:14px;padding:6px 16px;"><?= ucfirst(str_replace('_', ' ', $statusGel1)) ?></span>
                <?php else: ?>
                    <span class="badge badge-warning" style="font-size:14px;padding:6px 16px;">— Belum Ikut</span>
                <?php endif; ?>
            </div>
            <div style="background:var(--bg-light);border-radius:var(--radius);padding:16px;text-align:center;">
                <strong style="color:var(--text-muted);font-size:12px;display:block;margin-bottom:8px;">TUTORIAL GEL. 2</strong>
                <?php if ($statusGel2 === 'lulus'): ?>
                    <span class="badge badge-success" style="font-size:14px;padding:6px 16px;">✅ Lulus</span>
                <?php elseif ($statusGel2 === 'tidak_lulus'): ?>
                    <span class="badge badge-danger" style="font-size:14px;padding:6px 16px;">❌ Belum Lulus</span>
                <?php elseif ($statusGel2): ?>
                    <span class="badge badge-info" style="font-size:14px;padding:6px 16px;"><?= ucfirst(str_replace('_', ' ', $statusGel2)) ?></span>
                <?php else: ?>
                    <span class="badge badge-warning" style="font-size:14px;padding:6px 16px;">— Belum Ikut</span>
                <?php endif; ?>
            </div>
            <div style="background:var(--bg-light);border-radius:var(--radius);padding:16px;text-align:center;">
                <strong style="color:var(--text-muted);font-size:12px;display:block;margin-bottom:8px;">ELIGIBILITAS MANDIRI</strong>
                <?php if ($berhakDaftar): ?>
                    <span class="badge badge-success" style="font-size:14px;padding:6px 16px;">✅ Berhak Daftar</span>
                <?php else: ?>
                    <span class="badge badge-danger" style="font-size:14px;padding:6px 16px;">🚫 Tidak Berhak</span>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$berhakDaftar && $alasanTidakBerhak): ?>
        <div class="alert alert-warning" style="margin-top:16px;margin-bottom:0;">
            ⚠️ <?= sanitize($alasanTidakBerhak) ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Sudah Terdaftar ────────────────────────────────────────── -->
<?php if ($sudahDaftar): ?>
<div class="card" style="border-left:4px solid var(--primary);margin-bottom:24px;">
    <div class="card-header">✅ Pendaftaran Tutorial Mandiri Anda</div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;">
            <div>
                <strong style="color:var(--text-muted);font-size:12px;">KELAS</strong>
                <p style="font-size:18px;font-weight:700;margin-top:4px;"><?= sanitize($sudahDaftar['nama_kelas']) ?></p>
            </div>
            <div>
                <strong style="color:var(--text-muted);font-size:12px;">MATA KULIAH</strong>
                <p style="font-size:15px;margin-top:4px;"><?= sanitize($sudahDaftar['mata_kuliah']) ?></p>
            </div>
            <div>
                <strong style="color:var(--text-muted);font-size:12px;">DOSEN</strong>
                <p style="font-size:15px;margin-top:4px;"><?= sanitize($sudahDaftar['dosen_pengampu']) ?></p>
            </div>
            <div>
                <strong style="color:var(--text-muted);font-size:12px;">JADWAL</strong>
                <p style="font-size:15px;margin-top:4px;"><?= sanitize($sudahDaftar['hari']) ?>, <?= sanitize($sudahDaftar['jam']) ?></p>
            </div>
            <div>
                <strong style="color:var(--text-muted);font-size:12px;">RUANGAN</strong>
                <p style="font-size:15px;margin-top:4px;"><?= sanitize($sudahDaftar['ruangan']) ?></p>
            </div>
            <div>
                <strong style="color:var(--text-muted);font-size:12px;">STATUS</strong>
                <p style="margin-top:4px;">
                    <?php
                    $statusBadge = [
                        'terdaftar'         => 'badge-info',
                        'aktif'             => 'badge-primary',
                        'lulus'             => 'badge-success',
                        'tidak_lulus'       => 'badge-danger',
                        'mengundurkan_diri' => 'badge-warning',
                    ];
                    $badge = $statusBadge[$sudahDaftar['status']] ?? 'badge-info';
                    ?>
                    <span class="badge <?= $badge ?>" style="font-size:13px;padding:5px 14px;">
                        <?= ucfirst(str_replace('_', ' ', $sudahDaftar['status'])) ?>
                    </span>
                </p>
            </div>
        </div>
        <div class="alert alert-success" style="margin-top:16px;margin-bottom:0;">
            🎉 Anda sudah terdaftar di tutorial mandiri. Tunggu jadwal resmi dari TU.
        </div>
    </div>
</div>

<!-- ── Form Pendaftaran ────────────────────────────────────────── -->
<?php elseif ($annMandiri && $berhakDaftar): ?>
<div class="card">
    <div class="card-header">📝 Form Pendaftaran Tutorial Mandiri</div>
    <div class="card-body">
        <div class="alert alert-info" style="margin-bottom:20px;">
            ℹ️ Pilih kelas yang tersedia sesuai jadwal yang bisa Anda ikuti. Setelah mendaftar, TU akan mengkonfirmasi penempatan Anda.
        </div>

        <?php if (empty($kelasMandiri)): ?>
            <div class="empty-state">
                <div class="icon">📋</div>
                <h3>Belum ada kelas mandiri</h3>
                <p>TU belum menambahkan kelas tutorial mandiri. Silakan cek kembali nanti.</p>
            </div>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

            <div class="form-group">
                <label style="font-weight:600;margin-bottom:12px;display:block;">
                    Pilih Kelas Tutorial Mandiri <span style="color:#dc3545;">*</span>
                </label>
                <div style="display:grid;gap:12px;">
                    <?php foreach ($kelasMandiri as $k): ?>
                    <label style="
                        display:flex;align-items:flex-start;gap:14px;
                        padding:16px 20px;
                        border:2px solid #e0e0e0;
                        border-radius:12px;
                        cursor:pointer;
                        transition:all .2s;
                        background:#fff;
                    " onmouseover="this.style.borderColor='var(--primary)';this.style.background='#f0f7ff'"
                       onmouseout="this.style.borderColor='#e0e0e0';this.style.background='#fff'">
                        <input type="radio" name="class_id" value="<?= $k['id'] ?>" required
                               style="margin-top:3px;accent-color:var(--primary);width:18px;height:18px;">
                        <div style="flex:1;">
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
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
            </div>

            <div style="margin-top:8px;padding:14px;background:#fff3cd;border-radius:10px;font-size:13px;color:#856404;">
                ⚠️ Pastikan jadwal yang dipilih tidak berbenturan dengan jadwal kuliah Anda. Pilihan tidak dapat diubah setelah dikirim.
            </div>

            <div style="margin-top:20px;">
                <button type="submit" name="daftar_mandiri" class="btn btn-primary" style="width:auto;padding:12px 32px;">
                    📝 Kirim Pendaftaran Tutorial Mandiri
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php elseif (!$annMandiri): ?>
<!-- Sudah ditangani di atas (pesan belum dibuka) -->

<?php else: ?>
<!-- Tidak berhak daftar -->
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <div class="icon">🚫</div>
            <h3>Tidak Memenuhi Syarat</h3>
            <p><?= sanitize($alasanTidakBerhak) ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
