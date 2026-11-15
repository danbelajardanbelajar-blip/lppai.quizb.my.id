<?php
define('PAGE_TITLE', 'Profil Pengguna — Tampilan Admin');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); exit('Halaman ini hanya untuk melihat profil.'); }
require_once __DIR__ . '/../includes/admin-user-profile.php';
require_once __DIR__ . '/../includes/user-table-avatar.php';
$pdo = getDBConnection();
$profileUser = null;
$profileChoices = [];
if (isset($_GET['id'])) {
    $id = is_string($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if (!$id) { http_response_code(400); exit('ID pengguna tidak valid.'); }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $stmt->execute([$id]);
    $profileUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$profileUser) { http_response_code(404); exit('Pengguna tidak ditemukan.'); }
} elseif (isset($_GET['nama']) && is_string($_GET['nama']) && trim($_GET['nama']) !== '' && mb_strlen($_GET['nama'], 'UTF-8') <= 200) {
    $stmt = $pdo->prepare('SELECT id, nama_lengkap, nim, username, role, program_studi FROM users WHERE LOWER(TRIM(nama_lengkap)) = LOWER(?) ORDER BY role, id');
    $stmt->execute([trim($_GET['nama'])]);
    $profileChoices = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static fn($row) => adminProfileNameKey($row['nama_lengkap']) === adminProfileNameKey($_GET['nama'])));
    if (!$profileChoices) { http_response_code(404); exit('Pengguna tidak ditemukan.'); }
    if (count($profileChoices) === 1) {
        header('Location: ' . BASE_URL . '/admin/profil-pengguna.php?id=' . (int)$profileChoices[0]['id']); exit;
    }
} else { http_response_code(400); exit('Pilih pengguna dari nama pada tabel.'); }
$profileHistory = $profileUser ? adminProfileHistory($pdo, $profileUser) : null;
include __DIR__ . '/../includes/header.php';
?>
<style>
.admin-profile-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.admin-profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}.admin-profile-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.admin-profile-fields dt{font-size:12px;color:#64748b;margin-bottom:5px}.admin-profile-fields dd{margin:0;font-weight:600;overflow-wrap:anywhere}
.admin-profile-identity .users-table-user-icon{width:88px!important;height:88px!important;flex-basis:88px!important}.admin-profile-identity .users-table-user-icon svg{width:44px;height:44px}
.admin-profile-identity .users-table-user-cell{font-size:22px;font-weight:800;gap:18px!important}.admin-profile-table{overflow-x:auto}.admin-profile-table table{min-width:600px;width:100%}
@media(max-width:700px){.admin-profile-grid,.admin-profile-fields{grid-template-columns:minmax(0,1fr)}.admin-profile-identity .users-table-user-cell{font-size:18px}}
</style>
<div class="admin-profile-head"><div><h2 style="margin:0;">Profil Pengguna</h2><p style="margin:6px 0 0;color:#64748b;">Tampilan khusus admin · Informasi akun dan aktivitas akademik</p></div><a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-secondary" data-no-spa style="width:auto;">Kelola Pengguna</a></div>
<?php if (!$profileUser): ?>
<section class="card"><div class="card-header">Pilih akun yang dimaksud</div><div class="card-body">
    <p>Beberapa akun memiliki nama yang sama. Pilih berdasarkan NIM, username, atau peran.</p>
    <div class="admin-profile-table"><table><thead><tr><th>Nama</th><th>NIM / Username</th><th>Peran</th><th>Program Studi</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($profileChoices as $choice): ?>
    <tr><td><?= adminUserProfileLink($choice['nama_lengkap'], $choice['id']) ?></td><td><?= sanitize($choice['nim'] ?: $choice['username']) ?></td><td><?= sanitize(ucfirst($choice['role'])) ?></td><td><?= sanitize($choice['program_studi'] ?? '-') ?></td><td><a class="btn btn-sm btn-primary" data-no-spa href="<?= BASE_URL ?>/admin/profil-pengguna.php?id=<?= (int)$choice['id'] ?>">Lihat Profil</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div></section>
<?php else: ?>
<section class="card"><div class="card-body admin-profile-identity">
    <?= renderUserTableName($profileUser) ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;"><span class="badge badge-info"><?= sanitize(ucfirst($profileUser['role'])) ?></span><span class="badge <?= !empty($profileUser['is_active']) ? 'badge-success' : 'badge-danger' ?>"><?= !empty($profileUser['is_active']) ? 'Akun Aktif' : 'Akun Nonaktif' ?></span></div>
</div></section>
<div class="admin-profile-grid">
<?php foreach ([
    'Informasi Akun & Akademik' => ['id'=>'ID Pengguna','username'=>'Username','nim'=>'NIM / NIDN','jenis_program'=>'Jenis Program','program_studi'=>'Program Studi','fakultas'=>'Fakultas','angkatan'=>'Angkatan'],
    'Kontak & Data Pribadi' => ['email'=>'Email','no_hp'=>'Nomor HP / WhatsApp','tempat_lahir'=>'Tempat Lahir','tanggal_lahir'=>'Tanggal Lahir'],
] as $title => $fields): ?>
<section class="card"><div class="card-header"><?= sanitize($title) ?></div><div class="card-body"><dl class="admin-profile-fields">
    <?php foreach ($fields as $field => $label): ?><div><dt><?= sanitize($label) ?></dt><dd><?= sanitize((string)($profileUser[$field] ?? '') !== '' ? (string)$profileUser[$field] : '-') ?></dd></div><?php endforeach; ?>
</dl></div></section>
<?php endforeach; ?>
</div>
<?php if ($profileUser['role'] === 'mahasiswa'): ?>
<section class="card"><div class="card-header">Riwayat Pendaftaran Pretes</div><div class="card-body">
<?php if (!$profileHistory['pretes']): ?><p>Belum ada riwayat pretes yang tersedia.</p><?php else: ?>
<p style="font-size:12px;color:#64748b;">Maksimal 50 pendaftaran terakhir.</p><div class="admin-profile-table"><table><thead><tr><th>Periode</th><th>Hari</th><th>Jam</th><th>Ruangan</th><th>Status</th></tr></thead><tbody>
<?php foreach ($profileHistory['pretes'] as $row): ?><tr><?php foreach (['periode','hari_pilihan','jam','ruangan','status'] as $field): ?><td><?= sanitize($row[$field] ?: '-') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></section>
<section class="card"><div class="card-header">Riwayat Tutorial</div><div class="card-body">
<?php if (!$profileHistory['tutorial']): ?><p>Belum ada riwayat tutorial yang tersedia.</p><?php else: ?>
<p style="font-size:12px;color:#64748b;">Maksimal 50 pendaftaran terakhir.</p><div class="admin-profile-table"><table><thead><tr><th>Tahun / Gelombang</th><th>Kelas</th><th>Dosen</th><th>Hari / Jam</th><th>Ruangan</th><th>Status</th><th>Nilai</th></tr></thead><tbody>
<?php foreach ($profileHistory['tutorial'] as $row): ?><tr><td><?= sanitize(($row['tahun_ajaran'] ?? '-') . ' / ' . ($row['gelombang'] ?? '-')) ?></td><td><?= sanitize($row['nama_kelas'] ?: 'Menunggu Pembagian Kelas') ?></td><td><?= adminUserProfileLink($row['dosen_pengampu'] ?: '-') ?></td><td><?= sanitize(($row['hari'] ?: $row['hari_pilihan']) . ' / ' . ($row['jam'] ?: '-')) ?></td><td><?= sanitize($row['ruangan'] ?: '-') ?></td><td><?= sanitize($row['status']) ?></td><td><?= $row['nilai_akhir'] !== null ? sanitize($row['nilai_akhir']) : '-' ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></section>
<?php elseif ($profileUser['role'] === 'dosen'): ?>
<section class="card"><div class="card-header">Kelas Tutorial yang Diampu</div><div class="card-body">
<?php if ($profileHistory['ambiguous_teacher']): ?><p>Ada akun dosen lain dengan nama sama. Jadwal tidak ditampilkan agar tidak tertukar.</p>
<?php elseif (!$profileHistory['teaching']): ?><p>Belum ada kelas tutorial yang tersedia.</p>
<?php else: ?><p style="font-size:12px;color:#64748b;">Maksimal 50 kelas terakhir.</p><div class="admin-profile-table"><table><thead><tr><th>Kelas</th><th>Semester / Gelombang</th><th>Hari</th><th>Jam</th><th>Ruangan</th></tr></thead><tbody>
<?php foreach ($profileHistory['teaching'] as $row): ?><tr><td><?= sanitize($row['nama_kelas']) ?></td><td><?= sanitize($row['semester'] . ' / ' . $row['gelombang']) ?></td><td><?= sanitize($row['hari'] ?: '-') ?></td><td><?= sanitize($row['jam'] ?: '-') ?></td><td><?= sanitize($row['ruangan'] ?: '-') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></section>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
