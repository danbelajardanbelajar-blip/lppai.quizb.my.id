<?php
/**
 * LPPAI Corner - Pembagian Kelas Tutorial (Terpadu)
 */
define('PAGE_TITLE', 'Pembagian Kelas Tutorial');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$user = getCurrentUser();
$pdo  = getDBConnection();

$gelombangs = [
    'gel1'    => 'Gelombang 1 (Ganjil)',
    'gel2'    => 'Gelombang 2 (Genap)',
    'mandiri' => 'Mandiri'
];

// Ambil semua registrasi mahasiswa ini
$stmt = $pdo->prepare("
    SELECT tr.*, tc.nama_kelas, tc.mata_kuliah, tc.dosen_pengampu, tc.hari, tc.jam, tc.ruangan, tc.gelombang
    FROM tutorial_registrations tr
    JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
    WHERE tr.user_id = ?
    ORDER BY tr.created_at DESC
");
$stmt->execute([$user['id']]);
$registrations = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
    <h2 class="page-title"><?= PAGE_TITLE ?></h2>
</div>

<?php if (empty($registrations)): ?>
    <div class="empty-state card">
        <div class="icon">🏫</div>
        <h3>Belum Terdaftar di Kelas Manapun</h3>
        <p>Anda belum mendaftar di kelas tutorial gelombang manapun. Silakan lakukan pendaftaran terlebih dahulu.</p>
    </div>
<?php else: ?>
    <div style="display:grid;gap:20px;">
    <?php foreach ($registrations as $reg): ?>
        <div class="card" style="border-left:4px solid var(--primary);">
            <div class="card-header" style="background:#f8fafc; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; font-size:16px;">Tutorial <?= $gelombangs[$reg['gelombang']] ?? $reg['gelombang'] ?></h3>
                <?php
                $badges = [
                    'terdaftar'         => 'badge-info',
                    'aktif'             => 'badge-primary',
                    'lulus'             => 'badge-success',
                    'tidak_lulus'       => 'badge-danger',
                    'mengundurkan_diri' => 'badge-warning',
                ];
                $bg = $badges[$reg['status']] ?? 'badge-info';
                ?>
                <span class="badge <?= $bg ?>"><?= ucfirst(str_replace('_', ' ', $reg['status'])) ?></span>
            </div>
            <div class="card-body">
                <?php if ($reg['status'] === 'terdaftar'): ?>
                    <div class="alert alert-info" style="margin:0;">
                        ⏳ Kelas Anda sedang diproses oleh TU. Silakan tunggu informasi pembagian kelas.
                    </div>
                <?php else: ?>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;">
                        <div>
                            <strong style="color:var(--text-muted);font-size:12px;">KELAS</strong>
                            <p style="font-size:18px;font-weight:700;margin-top:4px;color:var(--primary);"><?= sanitize($reg['nama_kelas']) ?></p>
                        </div>

                        <div>
                            <strong style="color:var(--text-muted);font-size:12px;">DOSEN</strong>
                            <p style="font-size:15px;margin-top:4px;"><?= sanitize($reg['dosen_pengampu'] ?: '-') ?></p>
                        </div>
                        <div>
                            <strong style="color:var(--text-muted);font-size:12px;">JADWAL</strong>
                            <p style="font-size:15px;margin-top:4px;"><?= sanitize($reg['hari'] ?: '-') ?>, <?= sanitize($reg['jam'] ?: '-') ?></p>
                        </div>
                        <div>
                            <strong style="color:var(--text-muted);font-size:12px;">RUANGAN</strong>
                            <p style="font-size:15px;margin-top:4px;"><?= sanitize($reg['ruangan'] ?: '-') ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
