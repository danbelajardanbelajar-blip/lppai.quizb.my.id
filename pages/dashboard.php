<?php
/**
 * LPPAI Corner - Dashboard Mahasiswa
 */
define('PAGE_TITLE', 'Dashboard Mahasiswa');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$user = getCurrentUser();
$pdo = getDBConnection();

// Get pretes status
$stmt = $pdo->prepare("SELECT pr.*, ps.tanggal, ps.ruangan FROM pretes_results pr LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id WHERE pr.user_id = ? ORDER BY pr.created_at DESC LIMIT 1");
$stmt->execute([$user['id']]);
$pretesResult = $stmt->fetch();

// Get active registrations
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pretes_registrations WHERE user_id = ? AND (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%')");
$stmt->execute([$user['id']]);
$pretesRegistered = $stmt->fetchColumn();

// Get tutorial registrations
$stmt = $pdo->prepare("SELECT tr.*, tc.nama_kelas, tc.gelombang, tc.hari, tc.jam, tc.ruangan FROM tutorial_registrations tr JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id WHERE tr.user_id = ? AND (tahun_ajaran LIKE '%2026%' OR tahun_ajaran LIKE '%2027%' OR tahun_ajaran LIKE '%2028%' OR tahun_ajaran LIKE '%2029%' OR tahun_ajaran LIKE '%2030%') ORDER BY tr.created_at DESC");
$stmt->execute([$user['id']]);
$tutorialRegs = $stmt->fetchAll();

// Get recent announcements
$stmt = $pdo->query("SELECT * FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 5");
$recentAnnouncements = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Welcome Card -->
<div class="card" style="border-left: 4px solid var(--primary); margin-bottom: 24px;">
    <div class="card-body" style="display:flex;align-items:center;gap:20px;">
        <div class="stat-icon green" style="width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;">
            👋
        </div>
        <div>
            <h2 style="font-size:22px;margin-bottom:4px;">Assalamu'alaikum, <?= sanitize($user['nama_lengkap']) ?>!</h2>
            <p style="color:var(--text-muted);font-size:14px;">
                NIM: <?= sanitize($user['nim']) ?> | <?= sanitize($user['program_studi']) ?> - <?= sanitize($user['fakultas']) ?>
            </p>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon green">✍️</div>
        <div class="stat-info">
            <h3><?= $pretesRegistered ?></h3>
            <p>Pretes Terdaftar</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">📝</div>
        <div class="stat-info">
            <h3><?= $pretesResult ? ($pretesResult['status_lulus'] === 'lulus' ? '✅ Lulus' : ($pretesResult['status_lulus'] === 'tidak_lulus' ? '❌ Belum Lulus' : '⏳ Menunggu')) : '-' ?></h3>
            <p>Status Pretes</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">📚</div>
        <div class="stat-info">
            <h3><?= count($tutorialRegs) ?></h3>
            <p>Kelas Tutorial</p>
        </div>
    </div>
</div>

<!-- Tutorial Registrations -->
<?php if (!empty($tutorialRegs)): ?>
<div class="card">
    <div class="card-header">📚 Kelas Tutorial Saya</div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Kelas</th>
                        <th>Gelombang</th>
                        <th>Jadwal</th>
                        <th>Ruangan</th>
                        <th>Status</th>
                        <th>Nilai</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tutorialRegs as $reg): ?>
                    <tr>
                        <td><?= sanitize($reg['nama_kelas']) ?></td>
                        <td>
                            <?php
                            $gelLabels = ['gel1' => 'Gelombang 1', 'gel2' => 'Gelombang 2', 'mandiri' => 'Mandiri'];
                            echo $gelLabels[$reg['gelombang']] ?? $reg['gelombang'];
                            ?>
                        </td>
                        <td><?= sanitize($reg['hari']) ?>, <?= sanitize($reg['jam']) ?></td>
                        <td><?= sanitize($reg['ruangan']) ?></td>
                        <td>
                            <?php
                            $statusBadge = [
                                'terdaftar' => 'badge-info',
                                'aktif' => 'badge-primary',
                                'lulus' => 'badge-success',
                                'tidak_lulus' => 'badge-danger',
                                'mengundurkan_diri' => 'badge-warning'
                            ];
                            $badge = $statusBadge[$reg['status']] ?? 'badge-info';
                            ?>
                            <span class="badge <?= $badge ?>"><?= ucfirst(str_replace('_', ' ', $reg['status'])) ?></span>
                        </td>
                        <td><?= $reg['nilai_akhir'] ? number_format($reg['nilai_akhir'], 1) : '-' ?></td>
                        <td>
                            <?php if (!empty($reg['nomor_sertifikat'])): ?>
                                <button type="button" class="btn btn-sm btn-success" style="padding: 4px 10px; font-weight: bold;" onclick="showPengumuman('<?= htmlspecialchars($reg['id']) ?>')">🎉 Lihat Pengumuman Kelulusan</button>
                                
                                <!-- Hidden data for modal -->
                                <div id="data-pengumuman-<?= htmlspecialchars($reg['id']) ?>" style="display: none;"
                                     data-th="<?= htmlspecialchars($reg['nilai_thaharah']) ?>"
                                     data-sh="<?= htmlspecialchars($reg['nilai_shalat']) ?>"
                                     data-sp="<?= htmlspecialchars($reg['nilai_surat_pendek']) ?>"
                                     data-am="<?= htmlspecialchars($reg['nilai_amaliyah']) ?>"
                                     data-jn="<?= htmlspecialchars($reg['nilai_jenazah']) ?>"
                                     data-ut="<?= htmlspecialchars($reg['nilai_ujian_tulis']) ?>"
                                     data-no="<?= htmlspecialchars($reg['nomor_sertifikat']) ?>">
                                </div>
                            <?php else: ?>
                                <span style="font-size: 12px; color: #888;">Belum tersedia</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Recent Announcements -->
<div class="card">
    <div class="card-header">📢 Pengumuman Terbaru</div>
    <div class="card-body">
        <?php if (empty($recentAnnouncements)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <h3>Belum ada pengumuman</h3>
                <p>Pengumuman baru akan tampil di sini.</p>
            </div>
        <?php else: ?>
            <?php foreach ($recentAnnouncements as $ann): ?>
            <div class="announcement-card">
                <div class="ann-title"><?= sanitize($ann['judul']) ?></div>
                <div class="ann-date">🕐 <?= date('d M Y, H:i', strtotime($ann['created_at'])) ?></div>
                <div class="ann-content"><?= nl2br(sanitize($ann['konten'])) ?></div>
                <?php if (!empty($ann['link_tujuan'])): ?>
                <div style="margin-top: 15px;">
                    <a href="<?= BASE_URL . sanitize($ann['link_tujuan']) ?>" class="btn btn-primary" style="display:inline-block; padding:8px 16px; font-size:14px; width:auto; text-decoration:none; background:var(--primary); color:#fff; border-radius:6px; font-weight:600;">
                        Buka Halaman ➔
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<!-- Modal Pengumuman Kelulusan -->
<div id="modalPengumuman" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.5); backdrop-filter: blur(4px); transition: all 0.3s ease;">
    <div class="modal-content" style="background-color:#ffffff; margin:5% auto; padding:0; border-radius:12px; width:90%; max-width:550px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); overflow: hidden; animation: modalFadeIn 0.3s ease-out;">
        <div style="background-color: #10b981; color: white; padding: 20px; text-align: center;">
            <h2 style="margin: 0; font-size: 24px;">🎉 PENGUMUMAN KELULUSAN 🎉</h2>
            <p style="margin: 5px 0 0 0; opacity: 0.9;">Lembaga Pengembangan Pendidikan Agama Islam</p>
        </div>
        <div style="padding: 24px;">
            <div style="text-align: center; margin-bottom: 20px;">
                <h3 style="margin: 0 0 10px 0; color: #1f2937;">Selamat, <?= sanitize($user['nama_lengkap']) ?>!</h3>
                <p style="margin: 0; color: #4b5563;">Anda dinyatakan <strong style="color: #10b981; font-size: 18px;">LULUS</strong> pada program Tutorial LPPAI.</p>
                <p style="margin: 5px 0 0 0; font-size: 14px; color: #6b7280;">No. Sertifikat: <strong id="pengNoSurat"></strong></p>
            </div>
            
            <h4 style="margin: 0 0 10px 0; border-bottom: 2px solid #e5e7eb; padding-bottom: 5px; color: #374151;">Rincian Nilai:</h4>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <tbody>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 8px 0; color: #4b5563;">Nilai Thaharah</td>
                        <td style="padding: 8px 0; text-align: right; font-weight: bold;" id="pengTh"></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 8px 0; color: #4b5563;">Nilai Shalat</td>
                        <td style="padding: 8px 0; text-align: right; font-weight: bold;" id="pengSh"></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 8px 0; color: #4b5563;">Nilai Surat Pendek</td>
                        <td style="padding: 8px 0; text-align: right; font-weight: bold;" id="pengSp"></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 8px 0; color: #4b5563;">Nilai Praktik Amaliyah</td>
                        <td style="padding: 8px 0; text-align: right; font-weight: bold;" id="pengAm"></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 8px 0; color: #4b5563;">Nilai Perawatan Jenazah</td>
                        <td style="padding: 8px 0; text-align: right; font-weight: bold;" id="pengJn"></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 8px 0; color: #4b5563;">Nilai Ujian Tulis</td>
                        <td style="padding: 8px 0; text-align: right; font-weight: bold;" id="pengUt"></td>
                    </tr>
                    <tr style="background-color: #f9fafb;">
                        <td style="padding: 12px 8px; color: #111827; font-weight: bold;">NILAI AKHIR</td>
                        <td style="padding: 12px 8px; text-align: right; font-weight: bold; color: #10b981; font-size: 18px;" id="pengAkhir"></td>
                    </tr>
                </tbody>
            </table>
            
            <div style="text-align: right;">
                <button type="button" class="btn btn-secondary" onclick="closePengumuman()">Tutup</button>
            </div>
        </div>
    </div>
</div>

<style>
    @keyframes modalFadeIn {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<script>
    function showPengumuman(id) {
        var data = document.getElementById('data-pengumuman-' + id);
        if (!data) return;
        
        var th = parseFloat(data.getAttribute('data-th')) || 0;
        var sh = parseFloat(data.getAttribute('data-sh')) || 0;
        var sp = parseFloat(data.getAttribute('data-sp')) || 0;
        var am = parseFloat(data.getAttribute('data-am')) || 0;
        var jn = parseFloat(data.getAttribute('data-jn')) || 0;
        var ut = parseFloat(data.getAttribute('data-ut')) || 0;
        var no = data.getAttribute('data-no');
        
        var akhir = ((th + sh + sp + am + jn + ut) / 6).toFixed(2);
        
        document.getElementById('pengTh').textContent = th;
        document.getElementById('pengSh').textContent = sh;
        document.getElementById('pengSp').textContent = sp;
        document.getElementById('pengAm').textContent = am;
        document.getElementById('pengJn').textContent = jn;
        document.getElementById('pengUt').textContent = ut;
        document.getElementById('pengAkhir').textContent = akhir;
        document.getElementById('pengNoSurat').textContent = no;
        
        document.getElementById('modalPengumuman').style.display = 'block';
    }
    
    function closePengumuman() {
        document.getElementById('modalPengumuman').style.display = 'none';
    }
    
    window.addEventListener('click', function(event) {
        var modal = document.getElementById('modalPengumuman');
        if (event.target == modal) {
            closePengumuman();
        }
    });
</script>
