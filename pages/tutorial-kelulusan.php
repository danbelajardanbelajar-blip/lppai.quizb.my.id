<?php
/**
 * LPPAI Corner - Kelulusan Tutorial (Terpadu)
 */
define('PAGE_TITLE', 'Hasil Kelulusan Tutorial');
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

// Ambil semua hasil tutorial mahasiswa ini
$stmt = $pdo->prepare("
    SELECT tr.*, tc.nama_kelas, tc.gelombang
    FROM tutorial_results tr
    JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
    WHERE tr.user_id = ?
    ORDER BY tr.created_at DESC
");
$stmt->execute([$user['id']]);
$results = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
    <h2 class="page-title"><?= PAGE_TITLE ?></h2>
</div>

<?php if (empty($results)): ?>
    <div class="empty-state card">
        <div class="icon">🎓</div>
        <h3>Belum Ada Hasil Kelulusan</h3>
        <p>Belum ada nilai atau hasil kelulusan tutorial yang diumumkan untuk Anda.</p>
    </div>
<?php else: ?>
    <div style="display:grid;gap:20px;">
    <?php foreach ($results as $res): ?>
        <div class="card" style="border-left:4px solid var(--primary);">
            <div class="card-header" style="background:#f8fafc; border-bottom:1px solid #e5e7eb;">
                <h3 style="margin:0; font-size:16px;">Tutorial <?= $gelombangs[$res['gelombang']] ?? $res['gelombang'] ?></h3>
            </div>
            <div class="card-body">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;">

                    <div>
                        <strong style="color:var(--text-muted);font-size:12px;">KELAS</strong>
                        <p style="font-size:15px;margin-top:4px;"><?= sanitize($res['nama_kelas']) ?></p>
                    </div>
                    <div>
                        <strong style="color:var(--text-muted);font-size:12px;">NILAI</strong>
                        <p style="font-size:18px;font-weight:700;margin-top:4px;color:var(--primary);">
                            <?= $res['nilai'] !== null ? number_format($res['nilai'], 1) : '-' ?>
                        </p>
                    </div>
                    <div>
                        <strong style="color:var(--text-muted);font-size:12px;">STATUS KELULUSAN</strong>
                        <p style="margin-top:4px;">
                            <?php
                            $statusBadge = [
                                'lulus'           => 'badge-success',
                                'tidak_lulus'     => 'badge-danger',
                                'belum_diumumkan' => 'badge-warning',
                            ];
                            $bg = $statusBadge[$res['status_lulus']] ?? 'badge-info';
                            ?>
                            <span class="badge <?= $bg ?>" style="font-size:13px;padding:4px 10px;">
                                <?= ucfirst(str_replace('_', ' ', $res['status_lulus'])) ?>
                            </span>
                        </p>
                    </div>
                    <?php if (!empty($res['keterangan'])): ?>
                    <div style="grid-column:1 / -1; margin-top:8px;">
                        <strong style="color:var(--text-muted);font-size:12px;">KETERANGAN</strong>
                        <p style="font-size:14px;margin-top:4px;color:#4b5563;"><?= nl2br(sanitize($res['keterangan'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
