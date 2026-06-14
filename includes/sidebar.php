<?php
/**
 * LPPAI Corner - Sidebar
 */
$currentPage = basename($_SERVER['PHP_SELF']);
$isAdmin = isset($currentUser) && $currentUser['role'] === 'admin';
$isDosen = isset($currentUser) && $currentUser['role'] === 'dosen';

function menuActive($page) {
    global $currentPage;
    return $currentPage === $page ? 'active' : '';
}
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <div class="app-logo">
            <img src="<?= BASE_URL ?>/assets/logo.svg" alt="<?= APP_NAME ?> logo">
        </div>
        <h2><?= APP_NAME ?></h2>
        <small>Lembaga Pengembangan Pendidikan Agama Islam</small>
    </div>
    <nav class="sidebar-menu">
        <?php if ($isAdmin): ?>
            <!-- ADMIN MENU -->
            <div class="menu-label">Dashboard</div>
            <a href="<?= BASE_URL ?>/admin/dashboard.php" class="page-nav <?= menuActive('dashboard.php') ?>">
                <span class="icon">📊</span> Dashboard Admin
            </a>

            <div class="menu-label">Manajemen Pretes</div>
            <a href="<?= BASE_URL ?>/admin/pretes-jadwal.php" class="page-nav <?= menuActive('pretes-jadwal.php') ?>">
                <span class="icon">📅</span> Kelola Jadwal Pretes
            </a>
            <a href="<?= BASE_URL ?>/admin/pretes-peserta.php" class="page-nav <?= menuActive('pretes-peserta.php') ?>">
                <span class="icon">👥</span> Data Peserta Pretes
            </a>
            <a href="<?= BASE_URL ?>/admin/pretes-hasil.php" class="page-nav <?= menuActive('pretes-hasil.php') ?>">
                <span class="icon">📝</span> Kelola Hasil Pretes
            </a>

            <div class="menu-label">Manajemen Tutorial</div>
            <a href="<?= BASE_URL ?>/admin/tutorial-kelas.php" class="page-nav <?= menuActive('tutorial-kelas.php') ?>">
                <span class="icon">🏫</span> Kelola Kelas Tutorial
            </a>
            <a href="<?= BASE_URL ?>/admin/tutorial-peserta.php" class="page-nav <?= menuActive('tutorial-peserta.php') ?>">
                <span class="icon">📋</span> Data Peserta Tutorial
            </a>
            <a href="<?= BASE_URL ?>/rekap-nilai.php" class="page-nav <?= menuActive('rekap-nilai.php') ?>">
                <span class="icon">🎓</span> Rekapitulasi Nilai
            </a>

            <div class="menu-label">Pengumuman</div>
            <a href="<?= BASE_URL ?>/admin/pengumuman.php" class="page-nav <?= menuActive('pengumuman.php') ?>">
                <span class="icon">📢</span> Kelola Pengumuman
            </a>

            <div class="menu-label">Users</div>
            <a href="<?= BASE_URL ?>/admin/users.php" class="page-nav <?= menuActive('users.php') ?>">
                <span class="icon">👥</span> Kelola Pengguna
            </a>
            <a href="<?= BASE_URL ?>/admin/ruangan.php" class="page-nav <?= menuActive('ruangan.php') ?>">
                <span class="icon">🏢</span> Kelola Ruangan
            </a>
        <?php elseif ($isDosen): ?>
            <!-- DOSEN MENU -->
            <div class="menu-label">Dashboard</div>
            <a href="<?= BASE_URL ?>/dosen/dashboard.php" class="page-nav <?= menuActive('dashboard.php') ?>">
                <span class="icon">🏠</span> Dashboard Dosen
            </a>

            <div class="menu-label">Akademik</div>
            <a href="<?= BASE_URL ?>/dosen/kelas.php" class="page-nav <?= menuActive('kelas.php') ?>">
                <span class="icon">🏫</span> Kelas Anda
            </a>
            <a href="<?= BASE_URL ?>/rekap-nilai.php" class="page-nav <?= menuActive('rekap-nilai.php') ?>">
                <span class="icon">🎓</span> Rekapitulasi Nilai
            </a>
        <?php else: ?>
            <!-- MAHASISWA MENU -->
            <div class="menu-label">Dashboard</div>
            <a href="<?= BASE_URL ?>/dashboard.php" class="page-nav <?= menuActive('dashboard.php') ?>">
                <span class="icon">🏠</span> Dashboard
            </a>

            <div class="menu-label">Pretes</div>
            <a href="<?= BASE_URL ?>/pretes-daftar.php" class="page-nav <?= menuActive('pretes-daftar.php') ?>">
                <span class="icon">✍️</span> Daftar Pretes
            </a>
            <a href="<?= BASE_URL ?>/pretes-peserta.php" class="page-nav <?= menuActive('pretes-peserta.php') ?>">
                <span class="icon">📋</span> Peserta & Jadwal Pretes
            </a>
            <a href="<?= BASE_URL ?>/pretes-hasil.php" class="page-nav <?= menuActive('pretes-hasil.php') ?>">
                <span class="icon">📊</span> Hasil Pretes
            </a>

            <div class="menu-label">Tutorial</div>
            <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="page-nav <?= menuActive('tutorial-pendaftaran.php') ?>">
                <span class="icon">📝</span> Pendaftaran Tutorial
            </a>
            <a href="<?= BASE_URL ?>/tutorial-pembagian.php" class="page-nav <?= menuActive('tutorial-pembagian.php') ?>">
                <span class="icon">🏫</span> Pembagian Kelas
            </a>
            <a href="<?= BASE_URL ?>/rekap-nilai.php" class="page-nav <?= menuActive('rekap-nilai.php') ?>">
                <span class="icon">📋</span> Rincian Nilai
            </a>
            <a href="<?= BASE_URL ?>/tutorial-kelulusan.php" class="page-nav <?= menuActive('tutorial-kelulusan.php') ?>">
                <span class="icon">🎓</span> Kelulusan Tutorial
            </a>
        <?php endif; ?>

        <div class="menu-label">Akun</div>
        <a href="<?= BASE_URL ?>/logout.php">
            <span class="icon">🚪</span> Keluar
        </a>
    </nav>
</aside>
