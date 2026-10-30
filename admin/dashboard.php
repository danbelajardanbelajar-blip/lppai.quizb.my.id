<?php
/**
 * LPPAI Corner - Admin Dashboard
 */
define('PAGE_TITLE', 'Dashboard Admin');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

require_once __DIR__ . '/../includes/activity-logger.php';

$pdo = getDBConnection();
ensureActivityLogTablesExist($pdo);

// Stats LPPAI
$totalMahasiswa = $pdo->query("SELECT COUNT(*) FROM users WHERE role='mahasiswa'")->fetchColumn();
$totalPretes = $pdo->query("SELECT COUNT(*) FROM pretes_registrations WHERE (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%')")->fetchColumn();
$totalTutorial = $pdo->query("SELECT COUNT(*) FROM tutorial_registrations WHERE (tahun_ajaran LIKE '%2025%' OR tahun_ajaran LIKE '%2026%' OR tahun_ajaran LIKE '%2027%' OR tahun_ajaran LIKE '%2028%' OR tahun_ajaran LIKE '%2029%' OR tahun_ajaran LIKE '%2030%')")->fetchColumn();
$totalKelas = $pdo->query("SELECT COUNT(*) FROM tutorial_classes")->fetchColumn();
$totalPengumuman = $pdo->query("SELECT COUNT(*) FROM announcements WHERE is_active=1")->fetchColumn();
$lulusPretes = $pdo->query("SELECT COUNT(*) FROM pretes_results WHERE status_lulus='lulus'")->fetchColumn();

// Stats Log Aktivitas
$activityStats = getActivityLogStats();

// Recent pretes registrations
$recentRegs = $pdo->query("
    SELECT pr.*, u.nama_lengkap, u.nim
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    ORDER BY pr.tanggal_daftar DESC LIMIT 10
")->fetchAll();

// Recent Logs
$recentLogins = getRecentLoginLogs(50);
$recentClicks = getRecentClickLogs(100);

// Helper badge renderer untuk dashboard
function renderRoleBadge($role) {
    switch (strtolower(trim((string)$role))) {
        case 'admin':
            return '<span class="badge badge-primary">👑 Admin</span>';
        case 'dosen':
            return '<span class="badge badge-warning">👨‍🏫 Dosen</span>';
        case 'mahasiswa':
            return '<span class="badge badge-info">🎓 Mahasiswa</span>';
        default:
            return '<span class="badge" style="background:#e5e7eb;color:#374151;">' . htmlspecialchars($role ?: 'Tamu') . '</span>';
    }
}

function renderLoginStatusBadge($status) {
    if (strtolower(trim((string)$status)) === 'success') {
        return '<span class="badge badge-success">✓ Sukses</span>';
    }
    return '<span class="badge badge-danger">✕ Gagal</span>';
}

function renderElementTypeBadge($type) {
    switch (strtolower(trim((string)$type))) {
        case 'button':
            return '<span class="badge badge-warning">🔘 Tombol</span>';
        case 'link':
            return '<span class="badge badge-info">🔗 Link</span>';
        case 'menu':
            return '<span class="badge badge-primary">📑 Menu</span>';
        case 'card':
            return '<span class="badge badge-success">🃏 Kartu</span>';
        case 'tab':
            return '<span class="badge" style="background:#ede9fe;color:#6d28d9;">🗂️ Tab</span>';
        default:
            return '<span class="badge" style="background:#f3f4f6;color:#4b5563;">⚡ Aksi</span>';
    }
}

include __DIR__ . '/../includes/header.php';
?>

<!-- Stat Grid Utama -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon green">👥</div>
        <div class="stat-info">
            <h3><?= number_format($totalMahasiswa) ?></h3>
            <p>Total Mahasiswa</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">✍️</div>
        <div class="stat-info">
            <h3><?= number_format($totalPretes) ?></h3>
            <p>Pendaftar Pretes</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">🎓</div>
        <div class="stat-info">
            <h3><?= number_format($lulusPretes) ?></h3>
            <p>Lulus Pretes</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">📚</div>
        <div class="stat-info">
            <h3><?= number_format($totalTutorial) ?></h3>
            <p>Peserta Tutorial</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon teal">🔑</div>
        <div class="stat-info">
            <h3><?= number_format($activityStats['login_today']) ?></h3>
            <p>Login Hari Ini</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red">🖱️</div>
        <div class="stat-info">
            <h3><?= number_format($activityStats['click_today']) ?></h3>
            <p>Aktivitas Klik Hari Ini</p>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="card">
    <div class="card-header">⚡ Aksi Cepat</div>
    <div class="card-body" style="display:flex;gap:12px;flex-wrap:wrap;">
        <a href="<?= BASE_URL ?>/admin/pengumuman.php" class="btn btn-primary" style="width:auto;">📢 Kelola Pengumuman</a>
        <a href="<?= BASE_URL ?>/admin/pretes-jadwal.php" class="btn btn-success" style="width:auto;">📅 Kelola Jadwal Pretes</a>
        <a href="<?= BASE_URL ?>/admin/pretes-hasil.php" class="btn btn-warning" style="width:auto;">📝 Input Hasil Pretes</a>
        <a href="<?= BASE_URL ?>/admin/tutorial-kelas.php" class="btn btn-secondary" style="width:auto;">🏫 Kelola Kelas</a>
        <a href="<?= BASE_URL ?>/admin/pengambilan-sertifikat.php" class="btn btn-primary" style="width:auto;">🎓 Pengambilan Sertifikat</a>
        <a href="<?= BASE_URL ?>/admin/inventaris-surat.php" class="btn btn-info" style="width:auto;">📑 Inventaris Surat</a>
        <a href="#card-log-login" class="btn btn-light" style="width:auto;border:1px solid #d1d5db;">🔑 Lihat Log Login</a>
        <a href="#card-log-click" class="btn btn-light" style="width:auto;border:1px solid #d1d5db;">🖱️ Lihat Log Klik</a>
    </div>
</div>

<!-- CARD 1: LOG LOGIN PENGGUNA -->
<div class="card" id="card-log-login">
    <div class="card-header">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:18px;">🔑</span>
            <div>
                <strong>Log Aktivitas Login (Semua Pengguna)</strong>
                <div style="font-size:12px;font-weight:400;color:#6b7280;margin-top:2px;">
                    Riwayat sesi masuk sistem oleh Admin, Dosen, dan Mahasiswa
                </div>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <a href="javascript:void(0)" onclick="window.location.reload()" class="btn btn-sm btn-secondary" style="font-size:11.5px;padding:4px 9px;text-decoration:none;">🔄 Segarkan</a>
            <span class="badge badge-success"><?= number_format($activityStats['login_today']) ?> Login Hari Ini</span>
            <span class="badge badge-primary"><?= number_format($activityStats['total_login']) ?> Total Riwayat</span>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($recentLogins)): ?>
            <div class="empty-state">
                <div class="icon">🔑</div>
                <h3>Belum ada riwayat login</h3>
                <p>Log masuk sistem oleh seluruh pengguna akan secara otomatis tercatat dan tampil di sini.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="width:50px;">No</th>
                        <th>Waktu</th>
                        <th>Pengguna</th>
                        <th>Role</th>
                        <th>Username / NIM</th>
                        <th>No. HP</th>
                        <th>IP & Lokasi</th>
                        <th>Perangkat / Browser</th>
                        <th>Status</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    foreach ($recentLogins as $log): 
                        $nama = !empty($log['nama_lengkap']) ? $log['nama_lengkap'] : '-';
                        $noHp = !empty($log['no_hp']) ? trim($log['no_hp']) : '';
                        $deviceIcon = ($log['device'] === 'Mobile' ? '📱' : ($log['device'] === 'Tablet' ? '📟' : '💻'));
                        $timeStr = date('d/m/Y H:i:s', strtotime($log['created_at']));
                    ?>
                    <tr>
                        <td><?= $no++ ?></td>
                        <td style="white-space:nowrap;font-size:12.5px;">
                            <strong><?= $timeStr ?></strong>
                        </td>
                        <td>
                            <strong style="color:var(--primary-dark);"><?= sanitize($nama) ?></strong>
                        </td>
                        <td><?= renderRoleBadge($log['role']) ?></td>
                        <td><code><?= sanitize($log['username']) ?></code></td>
                        <td>
                            <?php if (!empty($noHp) && $noHp !== '-'): ?>
                                <button type="button" 
                                        class="btn-copy-hp" 
                                        onclick="copyToClipboard('<?= sanitize($noHp) ?>', this)" 
                                        title="Klik untuk menyalin nomor HP ke clipboard"
                                        style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:3px 8px; border-radius:6px; cursor:pointer; font-size:12px; font-family:monospace; display:inline-flex; align-items:center; gap:5px; transition:all 0.15s ease;">
                                    <span>📱</span>
                                    <span class="hp-number"><?= sanitize($noHp) ?></span>
                                    <span class="copy-icon" style="font-size:10px; opacity:0.65;">📋</span>
                                </button>
                            <?php else: ?>
                                <span style="color:#9ca3af; font-size:12px;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;">
                            <span style="font-family:monospace;background:#f3f4f6;padding:2px 6px;border-radius:4px;">
                                <?= sanitize($log['ip_address'] ?: '127.0.0.1') ?>
                            </span>
                        </td>
                        <td style="font-size:12.5px;">
                            <?= $deviceIcon ?> <?= sanitize($log['browser'] ?: 'Browser') ?>
                            <span style="color:#6b7280;font-size:11px;">(<?= sanitize($log['device'] ?: 'Desktop') ?>)</span>
                        </td>
                        <td><?= renderLoginStatusBadge($log['status']) ?></td>
                        <td style="font-size:12px;color:#4b5563;">
                            <?= sanitize($log['keterangan'] ?: '-') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- CARD 2: LOG AKTIVITAS KLIK PENGGUNA -->
<div class="card" id="card-log-click">
    <div class="card-header">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:18px;">🖱️</span>
            <div>
                <strong>Log Aktivitas Klik (Semua Pengguna)</strong>
                <div style="font-size:12px;font-weight:400;color:#6b7280;margin-top:2px;">
                    Rekaman interaksi tombol, menu, navigasi, dan aksi pengguna dalam aplikasi
                </div>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <a href="javascript:void(0)" onclick="window.location.reload()" class="btn btn-sm btn-secondary" style="font-size:11.5px;padding:4px 9px;text-decoration:none;">🔄 Segarkan</a>
            <span class="badge badge-success"><?= number_format($activityStats['click_today']) ?> Klik Hari Ini</span>
            <span class="badge badge-primary"><?= number_format($activityStats['total_click']) ?> Total Aktivitas</span>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($recentClicks)): ?>
            <div class="empty-state">
                <div class="icon">🖱️</div>
                <h3>Belum ada aktivitas klik</h3>
                <p>Setiap klik tombol, menu, maupun aksi pengguna akan terekam secara otomatis di sini.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="width:50px;">No</th>
                        <th>Waktu</th>
                        <th>Pengguna</th>
                        <th>Role</th>
                        <th>Elemen / Teks Diklik</th>
                        <th>Tipe</th>
                        <th>Halaman Asal</th>
                        <th>Target / Aksi</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $noClick = 1;
                    foreach ($recentClicks as $c): 
                        $namaUser = !empty($c['nama_lengkap']) ? $c['nama_lengkap'] : (!empty($c['username']) ? $c['username'] : 'Tamu');
                        $timeStr = date('d/m/Y H:i:s', strtotime($c['created_at']));
                        $elemText = !empty($c['element_text']) ? $c['element_text'] : (!empty($c['element_id']) ? ('#' . $c['element_id']) : 'Klik Aksi');
                        $pageLabel = !empty($c['page_title']) ? $c['page_title'] : $c['page_url'];
                    ?>
                    <tr>
                        <td><?= $noClick++ ?></td>
                        <td style="white-space:nowrap;font-size:12.5px;">
                            <strong><?= $timeStr ?></strong>
                        </td>
                        <td>
                            <strong style="color:var(--primary-dark);"><?= sanitize($namaUser) ?></strong>
                            <?php if (!empty($c['username']) && $c['username'] !== $namaUser): ?>
                                <div style="font-size:11px;color:#6b7280;"><code><?= sanitize($c['username']) ?></code></div>
                            <?php endif; ?>
                        </td>
                        <td><?= renderRoleBadge($c['role']) ?></td>
                        <td>
                            <div style="font-weight:600;color:#1f2937;max-width:240px;word-break:break-word;">
                                <?= sanitize($elemText) ?>
                            </div>
                            <?php if (!empty($c['element_id'])): ?>
                                <small style="font-size:11px;color:#9ca3af;">ID: #<?= sanitize($c['element_id']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= renderElementTypeBadge($c['element_type']) ?></td>
                        <td style="max-width:220px;">
                            <div style="font-size:12.5px;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= sanitize($pageLabel) ?>">
                                <?= sanitize($pageLabel) ?>
                            </div>
                            <small style="color:#9ca3af;font-size:11px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= sanitize($c['page_url']) ?>">
                                <?= sanitize($c['page_url']) ?>
                            </small>
                        </td>
                        <td style="max-width:180px;font-size:12px;">
                            <?php if (!empty($c['target_url'])): ?>
                                <span style="color:#2563eb;word-break:break-all;" title="<?= sanitize($c['target_url']) ?>">
                                    <?= sanitize($c['target_url']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#9ca3af;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;">
                            <span style="font-family:monospace;background:#f3f4f6;padding:2px 6px;border-radius:4px;">
                                <?= sanitize($c['ip_address'] ?: '127.0.0.1') ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Pretes Registrations -->
<div class="card">
    <div class="card-header">📋 Pendaftaran Pretes Terbaru</div>
    <div class="card-body">
        <?php if (empty($recentRegs)): ?>
            <div class="empty-state">
                <div class="icon">📋</div>
                <h3>Belum ada pendaftaran</h3>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>NIM</th>
                        <th>Periode</th>
                        <th>Tgl Daftar</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentRegs as $r): ?>
                    <tr>
                        <td><?= sanitize($r['nama_lengkap']) ?></td>
                        <td><?= sanitize($r['nim']) ?></td>
                        <td><?= sanitize($r['periode']) ?></td>
                        <td><?= date('d M Y H:i', strtotime($r['tanggal_daftar'])) ?></td>
                        <td><span class="badge badge-success"><?= ucfirst($r['status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function copyToClipboard(text, btn) {
    if (!text || text === '-') return;

    function handleSuccess() {
        if (typeof showToast === 'function') {
            showToast('Nomor HP ' + text + ' berhasil disalin ke clipboard!', 'success', 2500);
        }
        if (btn) {
            var oldHtml = btn.getAttribute('data-old-html') || btn.innerHTML;
            btn.setAttribute('data-old-html', oldHtml);
            btn.innerHTML = '<span>✓</span> <span>Tersalin!</span>';
            btn.style.backgroundColor = '#dcfce7';
            btn.style.borderColor = '#86efac';
            btn.style.color = '#15803d';
            setTimeout(function() {
                btn.innerHTML = oldHtml;
                btn.style.backgroundColor = '';
                btn.style.borderColor = '';
                btn.style.color = '';
            }, 2000);
        }
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(handleSuccess).catch(function() {
            fallbackCopyText(text, handleSuccess);
        });
    } else {
        fallbackCopyText(text, handleSuccess);
    }
}

function fallbackCopyText(text, cb) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    ta.style.top = '-9999px';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
        var successful = document.execCommand('copy');
        if (successful && cb) {
            cb();
        } else if (typeof showToast === 'function') {
            showToast('Nomor HP: ' + text, 'info', 3000);
        }
    } catch (err) {
        if (typeof showToast === 'function') {
            showToast('Gagal menyalin otomatis: ' + text, 'warning', 3000);
        }
    }
    document.body.removeChild(ta);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
