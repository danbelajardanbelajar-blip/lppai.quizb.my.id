<?php
/**
 * LPPAI Corner - Pendaftaran Tutorial (Terpadu)
 */
define('PAGE_TITLE', 'Pendaftaran Tutorial');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pretes-helper.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$user = getCurrentUser();
$pdo = getDBConnection();

// Mahasiswa yang sedang di tahap pretes aktif → tampilkan kartu Tahap Pretes (juga menolak POST pendaftaran)
require_once __DIR__ . '/../includes/pretes-stage.php';
pretesStageGate($pdo, $user, 'Pendaftaran Tutorial baru dapat dilakukan bila hasil pretes Anda dinyatakan belum lulus.');

// Cek kelulusan pretes (mahasiswa yang lulus pretes dibebaskan dari tutorial)
$isLulusPretes = isUserLulusPretes($pdo, $user['id']);

// Auto-patch database for new registration flow
try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("ALTER TABLE tutorial_registrations MODIFY tutorial_class_id INT NULL");
    $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN hari_pilihan VARCHAR(20) DEFAULT NULL");
    $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN gelombang VARCHAR(20) DEFAULT NULL");
    $pdo->exec("ALTER TABLE tutorial_registrations ADD COLUMN tahun_ajaran VARCHAR(50) DEFAULT NULL");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
} catch (Exception $e) {}

$message = '';
$msgType = '';

$gelombangs = [
    'gel1'    => ['label' => 'Tutorial Gelombang 1 (Ganjil)', 'annType' => 'pendaftaran_gel1'],
    'gel2'    => ['label' => 'Tutorial Gelombang 2 (Genap)',  'annType' => 'pendaftaran_gel2'],
    'mandiri' => ['label' => 'Tutorial Mandiri',              'annType' => 'pendaftaran_mandiri']
];

// Ambil data kuota dari master_gelombang aktif
$active_gel = $pdo->query("SELECT * FROM master_gelombang WHERE status = 'aktif' ORDER BY created_at DESC LIMIT 1")->fetch();
$registeredCounts = ['Senin' => 0, 'Selasa' => 0, 'Rabu' => 0, 'Kamis' => 0, 'Jumat' => 0];
if ($active_gel) {
    $targetYear = str_replace('/', '-', trim($active_gel['tahun_ajaran']));
    $targetGelombang = trim($active_gel['gelombang']);
    $stmtCount = $pdo->prepare("
        SELECT hari_pilihan, COUNT(*) as cnt 
        FROM tutorial_registrations 
        WHERE gelombang = ?
          AND (
              REPLACE(TRIM(tahun_ajaran), '/', '-') = ?
              OR tahun_ajaran LIKE ?
              OR (
                  (tahun_ajaran IS NULL OR TRIM(tahun_ajaran) = '')
                  AND created_at >= ?
              )
          )
        GROUP BY hari_pilihan
    ");
    $stmtCount->execute([$targetGelombang, $targetYear, '%' . $targetYear . '%', $active_gel['created_at']]);
    foreach ($stmtCount->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hari = ucfirst(strtolower(trim($row['hari_pilihan'] ?? '')));
        if (isset($registeredCounts[$hari])) {
            $registeredCounts[$hari] = (int)$row['cnt'];
        }
    }
}

// Handle Form Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gelombang'])) {
    if ($isLulusPretes) {
        $message = 'Alhamdulillah, Anda telah dinyatakan LULUS Pretes dan dibebaskan dari kewajiban mengikuti program Tutorial LPPAI, sehingga tidak perlu mendaftar.';
        $msgType = 'info';
    } else {
        $token        = $_POST['csrf_token'] ?? '';
        $hari_pilihan = $_POST['hari_pilihan'] ?? '';
        $gelombang    = $_POST['gelombang'] ?? '';
        $validDays    = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Ahad'];

        if (!verifyCsrf($token)) {
            $message = 'Sesi tidak valid. Silakan muat ulang halaman.';
            $msgType = 'danger';
        } elseif (!in_array($hari_pilihan, $validDays) || !isset($gelombangs[$gelombang])) {
            $message = 'Pilih hari yang valid.';
            $msgType = 'danger';
        } elseif (!$active_gel || ($active_gel['status'] ?? '') !== 'aktif' || $active_gel['gelombang'] !== $gelombang) {
            $message = 'Pendaftaran untuk gelombang ini sedang ditutup atau dinonaktifkan.';
            $msgType = 'danger';
        } else {
            // Cek pengumuman aktif
            $stmtAnn = $pdo->prepare("SELECT id FROM announcements WHERE tipe = ? AND is_active = 1 LIMIT 1");
            $stmtAnn->execute([$gelombangs[$gelombang]['annType']]);
            if (!$stmtAnn->fetch()) {
                $message = 'Pendaftaran untuk gelombang ini sedang ditutup.';
                $msgType = 'danger';
            } else {
                $dayLower = strtolower($hari_pilihan);
                $totalKuota = (int)($active_gel["kuota_$dayLower"] ?? 0);
                
                // Hitung ulang secara realtime pendaftar hari pilihan untuk mencegah race-condition
                $stmtCurrent = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM tutorial_registrations 
                    WHERE LOWER(hari_pilihan) = LOWER(?)
                      AND gelombang = ?
                      AND (
                          REPLACE(TRIM(tahun_ajaran), '/', '-') = ?
                          OR tahun_ajaran LIKE ?
                          OR (
                              (tahun_ajaran IS NULL OR TRIM(tahun_ajaran) = '')
                              AND created_at >= ?
                          )
                      )
                ");
                $stmtCurrent->execute([$hari_pilihan, $targetGelombang, $targetYear, '%' . $targetYear . '%', $active_gel['created_at']]);
                $terisi = (int)$stmtCurrent->fetchColumn();
                
                if ($totalKuota <= 0 || $terisi >= $totalKuota) {
                    $message = "Maaf, kuota untuk hari $hari_pilihan sudah penuh.";
                    $msgType = 'danger';
                } else {
                    // Cek apakah sudah daftar di gelombang ini (cek dari kolom gelombang atau fallback ke class)
                    $stmtReg = $pdo->prepare("
                        SELECT tr.id FROM tutorial_registrations tr 
                        LEFT JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id 
                        WHERE tr.user_id = ? AND (tr.gelombang = ? OR tc.gelombang = ?)
                    ");
                    $stmtReg->execute([$user['id'], $gelombang, $gelombang]);
                    if ($stmtReg->fetch()) {
                        $message = 'Anda sudah terdaftar di gelombang ini.';
                        $msgType = 'warning';
                    } else {
                        $tahun_ajaran = $active_gel['tahun_ajaran'] ?? (date('Y') . '/' . (date('Y') + 1));
                        $stmtInsert = $pdo->prepare("INSERT INTO tutorial_registrations (user_id, status, hari_pilihan, gelombang, tahun_ajaran) VALUES (?, 'terdaftar', ?, ?, ?)");
                        $stmtInsert->execute([$user['id'], $hari_pilihan, $gelombang, $tahun_ajaran]);
                        $message = 'Pendaftaran tutorial berhasil! Pilihan hari Anda telah disimpan.';
                        $msgType = 'success';
                        
                        // Update counter lokal agar UI segera merefleksikan perubahan
                        if (isset($registeredCounts[$hari_pilihan])) {
                            $registeredCounts[$hari_pilihan]++;
                        }
                    }
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

<?php if ($isLulusPretes): ?>
    <!-- PEMBERITAHUAN MAHASISWA SUDAH LULUS PRETES (BEBAS TUTORIAL) -->
    <div class="card" style="border-top: 4px solid #10b981; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-radius: 12px;">
        <div class="card-body" style="padding: 30px 24px; text-align: center;">
            <div style="width: 64px; height: 64px; background: #ecfdf5; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 16px auto; color: #059669; border: 2px solid #a7f3d0;">
                🎉
            </div>
            <h3 style="font-size: 20px; font-weight: 800; color: #065f46; margin: 0 0 10px 0;">
                Selamat, Anda Dinyatakan LULUS Pretes!
            </h3>
            <p style="color: #047857; font-size: 14.5px; max-width: 640px; margin: 0 auto 20px auto; line-height: 1.6;">
                Berdasarkan hasil evaluasi pretes LPPAI, Anda telah memenuhi standar kelulusan seluruh komponen nilai. Sesuai ketentuan akademik LPPAI, Anda secara resmi <strong>dibebaskan dari kewajiban mengikuti program Tutorial LPPAI</strong> dan tidak perlu melakukan pendaftaran kelas.
            </p>
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 14px 20px; max-width: 540px; margin: 0 auto 24px auto; font-size: 13.5px; color: #166534; text-align: left; line-height: 1.8;">
                ✓ Status Evaluasi: <strong>LULUS PRETES (Bebas Tutorial)</strong><br>
                ✓ Informasi Pengambilan Sertifikat: Silakan pantau pengumuman resmi atau buka menu <strong>Scan Pengambilan Sertifikat</strong> ketika periode pembagian sertifikat telah dibuka.
            </div>
            <div style="display: flex; justify-content: center; gap: 12px; flex-wrap: wrap;">
                <a href="<?= BASE_URL ?>/pretes-hasil.php" class="btn btn-primary" style="padding: 10px 22px; font-size: 13.5px; font-weight: 700;">
                    📊 Lihat Rincian Nilai Pretes
                </a>
                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-primary" style="padding: 10px 22px; font-size: 13.5px; font-weight: 600;">
                    🏠 Kembali ke Dashboard
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
$adaBuka = false;
foreach ($gelombangs as $gelKey => $g):
    // Cek pengumuman aktif
    $stmtAnn = $pdo->prepare("SELECT * FROM announcements WHERE tipe = ? AND is_active = 1 ORDER BY created_at DESC LIMIT 1");
    $stmtAnn->execute([$g['annType']]);
    $announcement = $stmtAnn->fetch();

    $isGelActive = $active_gel && ($active_gel['status'] ?? '') === 'aktif' && $active_gel['gelombang'] === $gelKey;

    if ($announcement && $isGelActive) {
        $adaBuka = true;
    }

    // Cek registrasi user di gelombang ini
    $stmtReg = $pdo->prepare("
        SELECT tr.*, tc.nama_kelas, tc.dosen_pengampu, tc.hari, tc.jam, tc.ruangan 
        FROM tutorial_registrations tr
        LEFT JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
        WHERE tr.user_id = ? AND (tr.gelombang = ? OR tc.gelombang = ?)
        ORDER BY tr.created_at DESC LIMIT 1
    ");
    $stmtReg->execute([$user['id'], $gelKey, $gelKey]);
    $sudahDaftar = $stmtReg->fetch();

    if (($announcement || $sudahDaftar) && (!$isLulusPretes || $sudahDaftar)): // Tampilkan hanya jika ada pengumuman buka ATAU sudah pernah daftar
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
                    <?php if ($sudahDaftar['tutorial_class_id']): ?>
                        <div><strong style="font-size:12px;color:#166534;">KELAS</strong><p style="margin:4px 0 0;font-weight:bold;"><?= sanitize($sudahDaftar['nama_kelas']) ?></p></div>

                        <div><strong style="font-size:12px;color:#166534;">DOSEN</strong><p style="margin:4px 0 0;"><?= sanitize($sudahDaftar['dosen_pengampu'] ?: '-') ?></p></div>
                        <div><strong style="font-size:12px;color:#166534;">JADWAL</strong><p style="margin:4px 0 0;"><?= sanitize($sudahDaftar['hari']) ?>, <?= sanitize($sudahDaftar['jam']) ?></p></div>
                        <div><strong style="font-size:12px;color:#166534;">RUANGAN</strong><p style="margin:4px 0 0;"><?= sanitize($sudahDaftar['ruangan'] ?: '-') ?></p></div>
                    <?php else: ?>
                        <div><strong style="font-size:12px;color:#166534;">HARI PILIHAN</strong><p style="margin:4px 0 0;font-weight:bold;"><?= sanitize($sudahDaftar['hari_pilihan']) ?></p></div>
                        <div><strong style="font-size:12px;color:#166534;">JAM</strong><p style="margin:4px 0 0;">13.00 - 14.30</p></div>
                        <div style="grid-column: 1 / -1;"><p style="margin:4px 0 0;color:#15803d;font-size:14px;"><em>Anda telah memilih hari. Silakan tunggu admin membagikan kelas Anda.</em></p></div>
                    <?php endif; ?>
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
        
        <?php elseif ($announcement && $isGelActive && !$isLulusPretes): ?>
            <!-- Form Pendaftaran Hari -->
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="gelombang" value="<?= $gelKey ?>">
                <label style="font-weight:600;margin-bottom:12px;display:block;">Pilih Hari (Jam: 13.00 - 14.30) <span style="color:red">*</span></label>
                <div class="form-group" style="margin-bottom:20px;">
                    <select name="hari_pilihan" required style="width:100%; padding:14px 16px; border:2px solid #e2e8f0; border-radius:10px; font-size:15px; background-color:#fff; color:#334155; outline:none; cursor:pointer;">
                        <option value="">-- Pilih Hari --</option>
                        <?php 
                        $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
                        foreach ($days as $day): 
                            $dayLower = strtolower($day);
                            $kuota = $active_gel["kuota_$dayLower"] ?? 0;
                            $terisi = $registeredCounts[$day] ?? 0;
                            $sisa = $kuota - $terisi;
                            $isFull = $sisa <= 0;
                        ?>
                        <option value="<?= $day ?>" <?= $isFull ? 'disabled' : '' ?>>
                            <?= $day ?> <?= $isFull ? '(Penuh)' : "(Sisa $sisa kursi)" ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="daftar_tutorial" value="1" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 15px; font-weight: 600;">📝 Daftar di <?= $g['label'] ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; endforeach; ?>

<?php if (!$adaBuka && !isset($sudahDaftar) && !$isLulusPretes): ?>
    <!-- Fallback if nothing is open -->
    <div class="empty-state card">
        <div class="icon">🔒</div>
        <h3>Pendaftaran Belum Dibuka</h3>
        <p>Saat ini belum ada gelombang tutorial yang membuka pendaftaran.</p>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
