<?php
/**
 * LPPAI Corner - Admin: Hasil Pembagian Kelas
 */
define('PAGE_TITLE', 'Hasil Pembagian Kelas');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();

// Ambil semua registrasi peserta yang sudah punya kelas, lalu join dengan kelas & tabel user
$query = "
    SELECT 
        tc.nama_kelas,
        tc.dosen_pengampu,
        tc.ruangan,
        u.nama_lengkap AS nama_mahasiswa
    FROM tutorial_registrations tr
    JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
    JOIN users u ON tr.user_id = u.id
    ORDER BY tc.hari, tc.nama_kelas, u.nama_lengkap
";
$hasil = $pdo->query($query)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">📋 Hasil Pembagian Kelas (<?= count($hasil) ?> Mahasiswa)</div>
    <div class="card-body">
        <?php if (empty($hasil)): ?>
            <div class="empty-state">
                <div class="icon">🏫</div>
                <h3>Belum ada data</h3>
                <p>Belum ada mahasiswa yang dibagikan ke dalam kelas.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Nama Kelas</th>
                            <th>Nama Dosen</th>
                            <th>Nama Mahasiswa</th>
                            <th>Nama Ruang</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hasil as $h): ?>
                        <tr>
                            <td><strong><?= sanitize($h['nama_kelas']) ?></strong></td>
                            <td><?= sanitize($h['dosen_pengampu'] ?: '-') ?></td>
                            <td><?= sanitize($h['nama_mahasiswa']) ?></td>
                            <td><span class="badge badge-success"><?= sanitize($h['ruangan'] ?: 'Belum Ada Ruang') ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
