<?php
/**
 * LPPAI Corner - Peserta & Jadwal Pretes
 */
define('PAGE_TITLE', 'Peserta & Jadwal Pretes');
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo = getDBConnection();

// Get all schedules
$schedules = $pdo->query("SELECT * FROM pretes_schedules ORDER BY tanggal DESC, waktu_mulai")->fetchAll();

// Get registered participants
$participants = $pdo->query("
    SELECT pr.*, u.nama_lengkap, u.nim, u.program_studi, u.fakultas, ps.tanggal, ps.ruangan
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    LEFT JOIN pretes_schedules ps ON pr.periode = ps.periode
    ORDER BY pr.tanggal_daftar DESC
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Jadwal Pretes -->
<div class="card">
    <div class="card-header">📅 Jadwal Pretes</div>
    <div class="card-body">
        <?php if (empty($schedules)): ?>
            <div class="empty-state">
                <div class="icon">📅</div>
                <h3>Belum ada jadwal</h3>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Periode</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Ruangan</th>
                        <th>Kuota</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $i => $s): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= sanitize($s['periode']) ?></td>
                        <td><?= date('d M Y', strtotime($s['tanggal'])) ?></td>
                        <td><?= date('H:i', strtotime($s['waktu_mulai'])) ?> - <?= date('H:i', strtotime($s['waktu_selesai'])) ?></td>
                        <td><?= sanitize($s['ruangan']) ?></td>
                        <td><?= $s['terisi'] ?>/<?= $s['kuota'] ?></td>
                        <td>
                            <?php
                            $statusMap = ['aktif' => 'badge-success', 'selesai' => 'badge-info', 'dibatalkan' => 'badge-danger'];
                            ?>
                            <span class="badge <?= $statusMap[$s['status']] ?? 'badge-info' ?>"><?= ucfirst($s['status']) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Daftar Peserta -->
<div class="card">
    <div class="card-header">👥 Daftar Peserta Pretes</div>
    <div class="card-body">
        <?php if (empty($participants)): ?>
            <div class="empty-state">
                <div class="icon">👥</div>
                <h3>Belum ada peserta terdaftar</h3>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>NIM</th>
                        <th>Program Studi</th>
                        <th>Periode</th>
                        <th>Tgl Daftar</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($participants as $i => $p): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= sanitize($p['nama_lengkap']) ?></td>
                        <td><?= sanitize($p['nim']) ?></td>
                        <td><?= sanitize($p['program_studi']) ?></td>
                        <td><?= sanitize($p['periode']) ?></td>
                        <td><?= date('d M Y', strtotime($p['tanggal_daftar'])) ?></td>
                        <td><span class="badge badge-success"><?= ucfirst($p['status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
