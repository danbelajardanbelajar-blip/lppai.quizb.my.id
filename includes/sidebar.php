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
            <div class="menu-item has-submenu">
                <a href="#" class="page-nav submenu-toggle <?= (in_array($currentPage, ['tutorial-pendaftaran-kolektif.php', 'tutorial-data-pendaftar.php', 'tutorial-pengaturan-generate.php', 'tutorial-hasil-plotting.php'])) ? 'active' : '' ?>">
                    <span class="icon">📋</span> Data Peserta Tutorial 
                    <span class="arrow" style="float: right; font-size: 10px; margin-top: 4px; transition: transform 0.2s;">▼</span>
                </a>
                <div class="submenu" style="display: <?= (in_array($currentPage, ['tutorial-pendaftaran-kolektif.php', 'tutorial-data-pendaftar.php', 'tutorial-pengaturan-generate.php', 'tutorial-hasil-plotting.php'])) ? 'block' : 'none' ?>; background: rgba(0,0,0,0.2); border-radius: 8px; margin: 2px 10px; padding: 4px 0;">
                    <a href="<?= BASE_URL ?>/admin/tutorial-pendaftaran-kolektif.php" class="page-nav <?= menuActive('tutorial-pendaftaran-kolektif.php') ?>" style="margin: 2px 0; padding-left: 40px; font-size: 13px;">
                        Pendaftaran Kolektif
                    </a>
                    <a href="<?= BASE_URL ?>/admin/tutorial-data-pendaftar.php" class="page-nav <?= menuActive('tutorial-data-pendaftar.php') ?>" style="margin: 2px 0; padding-left: 40px; font-size: 13px;">
                        Data Pendaftar
                    </a>
                    <a href="<?= BASE_URL ?>/admin/tutorial-pengaturan-generate.php" class="page-nav <?= menuActive('tutorial-pengaturan-generate.php') ?>" style="margin: 2px 0; padding-left: 40px; font-size: 13px;">
                        Pengaturan & Generate
                    </a>
                    <a href="<?= BASE_URL ?>/admin/tutorial-hasil-plotting.php" class="page-nav <?= menuActive('tutorial-hasil-plotting.php') ?>" style="margin: 2px 0; padding-left: 40px; font-size: 13px;">
                        Hasil Plotting & Jadwal
                    </a>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/rekap-nilai.php" class="page-nav <?= menuActive('rekap-nilai.php') ?>">
                <span class="icon">🎓</span> Rekapitulasi Nilai
            </a>
            <a href="<?= BASE_URL ?>/admin/kelola-nilai.php" class="page-nav <?= menuActive('kelola-nilai.php') ?>">
                <span class="icon">📊</span> Kelola Nilai (Lama)
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

            <div class="menu-label">Absensi</div>
            <a href="<?= BASE_URL ?>/admin/absensi-alkhidmah.php" class="page-nav <?= menuActive('absensi-alkhidmah.php') ?>">
                <span class="icon">📅</span> Absensi Al Khidmah
            </a>

            <div class="menu-label">Sistem</div>
            <a href="<?= BASE_URL ?>/admin/backup-restore.php" class="page-nav <?= menuActive('backup-restore.php') ?>">
                <span class="icon">💾</span> Backup & Restore
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
            <a href="<?= BASE_URL ?>/rekap-nilai.php" class="page-nav <?= menuActive('rekap-nilai.php') ?>">
                <span class="icon">📋</span> Rincian Nilai
            </a>
            <div class="menu-label">Absensi</div>
            <div class="menu-item has-submenu">
                <a href="#" class="page-nav submenu-toggle <?= (in_array($currentPage, ['absensi-tutorial.php', 'absensi-alkhidmah.php'])) ? 'active' : '' ?>">
                    <span class="icon">📅</span> Absensi
                    <span class="arrow" style="float: right; font-size: 10px; margin-top: 4px; transition: transform 0.2s;">▼</span>
                </a>
                <div class="submenu" style="display: <?= (in_array($currentPage, ['absensi-tutorial.php', 'absensi-alkhidmah.php'])) ? 'block' : 'none' ?>; background: rgba(0,0,0,0.2); border-radius: 8px; margin: 2px 10px; padding: 4px 0;">
                    <a href="<?= BASE_URL ?>/absensi-tutorial.php" class="page-nav <?= menuActive('absensi-tutorial.php') ?>" style="margin: 2px 0; padding-left: 40px; font-size: 13px;">
                        Absensi Tutorial
                    </a>
                    <a href="<?= BASE_URL ?>/absensi-alkhidmah.php" class="page-nav <?= menuActive('absensi-alkhidmah.php') ?>" style="margin: 2px 0; padding-left: 40px; font-size: 13px;">
                        Absensi al Khidmah
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <div class="menu-label">Akun</div>
        <a href="<?= BASE_URL ?>/logout.php">
            <span class="icon">🚪</span> Keluar
        </a>
    </nav>
</aside>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggles = document.querySelectorAll('.submenu-toggle');
    toggles.forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            var submenu = this.nextElementSibling;
            if (submenu) {
                var isVisible = submenu.style.display === 'block';
                submenu.style.display = isVisible ? 'none' : 'block';
                var arrow = this.querySelector('.arrow');
                if (arrow) {
                    arrow.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(180deg)';
                }
            }
        });
    });
});
</script>
