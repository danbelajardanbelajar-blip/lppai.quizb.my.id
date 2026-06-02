<?php
/**
 * LPPAI Corner - Sidebar
 */
$currentPage = basename($_SERVER['PHP_SELF']);
$isAdmin = isset($currentUser) && $currentUser['role'] === 'admin';

function menuActive($page) {
    global $currentPage;
    return $currentPage === $page ? 'active' : '';
}
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <div class="app-logo">
            <img src="<?= BASE_URL ?>/assets/logo.svg" alt="<?= APP_NAME ?> Logo">
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

            <div class="menu-label">Pengumuman</div>
            <a href="<?= BASE_URL ?>/admin/pengumuman.php" class="page-nav <?= menuActive('pengumuman.php') ?>">
                <span class="icon">📢</span> Kelola Pengumuman
            </a>

            <div class="menu-label">Users</div>
            <a href="<?= BASE_URL ?>/admin/users.php" class="page-nav <?= menuActive('users.php') ?>">
                <span class="icon">👤</span> Kelola Pengguna
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

            <div class="menu-label">Tutorial Gelombang 1 (Ganjil)</div>
            <a href="<?= BASE_URL ?>/tutorial-gel1-pendaftaran.php" class="page-nav <?= menuActive('tutorial-gel1-pendaftaran.php') ?>">
                <span class="icon">📝</span> Pendaftaran Gel. 1
            </a>
            <a href="<?= BASE_URL ?>/tutorial-gel1-pembagian.php" class="page-nav <?= menuActive('tutorial-gel1-pembagian.php') ?>">
                <span class="icon">🏫</span> Pembagian Kelas Gel. 1
            </a>
            <a href="<?= BASE_URL ?>/tutorial-gel1-kelulusan.php" class="page-nav <?= menuActive('tutorial-gel1-kelulusan.php') ?>">
                <span class="icon">🎓</span> Kelulusan Gel. 1
            </a>

            <div class="menu-label">Tutorial Gelombang 2 (Genap)</div>
            <a href="<?= BASE_URL ?>/tutorial-gel2-pendaftaran.php" class="page-nav <?= menuActive('tutorial-gel2-pendaftaran.php') ?>">
                <span class="icon">📝</span> Pendaftaran Gel. 2
            </a>
            <a href="<?= BASE_URL ?>/tutorial-gel2-pembagian.php" class="page-nav <?= menuActive('tutorial-gel2-pembagian.php') ?>">
                <span class="icon">🏫</span> Pembagian Kelas Gel. 2
            </a>
            <a href="<?= BASE_URL ?>/tutorial-gel2-kelulusan.php" class="page-nav <?= menuActive('tutorial-gel2-kelulusan.php') ?>">
                <span class="icon">🎓</span> Kelulusan Gel. 2
            </a>

            <div class="menu-label">Tutorial Mandiri</div>
            <a href="<?= BASE_URL ?>/tutorial-mandiri-pendaftaran.php" class="page-nav <?= menuActive('tutorial-mandiri-pendaftaran.php') ?>">
                <span class="icon">📝</span> Pendaftaran Mandiri
            </a>
            <a href="<?= BASE_URL ?>/tutorial-mandiri-pembagian.php" class="page-nav <?= menuActive('tutorial-mandiri-pembagian.php') ?>">
                <span class="icon">🏫</span> Pembagian Kelas Mandiri
            </a>
        <?php endif; ?>

        <div class="menu-label">Akun</div>
        <a href="<?= BASE_URL ?>/logout.php">
            <span class="icon">🚪</span> Keluar
        </a>
    </nav>
</aside>
