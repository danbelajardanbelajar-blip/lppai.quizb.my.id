<?php
/**
 * LPPAI Corner - Sidebar
 */
$currentPage = basename($_SERVER['PHP_SELF']);
$isAdmin = isset($currentUser) && $currentUser['role'] === 'admin';
$isDosen = isset($currentUser) && $currentUser['role'] === 'dosen';

if (!function_exists('menuActive')) {
    function menuActive($page) {
        global $currentPage;
        if ($page === 'pretes-peserta.php' && $currentPage === 'pretes-peserta-export.php') {
            return 'active';
        }
        return $currentPage === $page ? 'active' : '';
    }
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
            <a href="<?= BASE_URL ?>/admin/pretes-pengaturan-tutor.php" class="page-nav <?= menuActive('pretes-pengaturan-tutor.php') ?>">
                <span class="icon">🧑‍🏫</span> Pengaturan Tutor
            </a>
            <a href="<?= BASE_URL ?>/admin/pretes-dosen.php" class="page-nav <?= menuActive('pretes-dosen.php') ?>">
                <span class="icon">👨‍🏫</span> Penguji & Kuota Pretes
            </a>
            <a href="<?= BASE_URL ?>/admin/pretes-jadwal.php" class="page-nav <?= menuActive('pretes-jadwal.php') ?>">
                <span class="icon">📅</span> Kelola Jadwal Pretes
            </a>
            <a href="<?= BASE_URL ?>/admin/pretes-peserta.php" class="page-nav <?= menuActive('pretes-peserta.php') ?>">
                <span class="icon">👥</span> Data Peserta Pretes
            </a>
            <a href="<?= BASE_URL ?>/admin/pretes-nilai.php" class="<?= menuActive('pretes-nilai.php') ?>" data-no-spa>
                <span class="icon">📚</span> Nilai Rinci & Absensi Pretes
            </a>
            <a href="<?= BASE_URL ?>/pretes-ujian.php?cbt_bank=1" class="<?= isset($_GET['cbt_bank']) ? 'active' : '' ?>" data-no-spa>
                <span class="icon">🧩</span> Bank Soal & Hasil CBT
            </a>
            <a href="<?= BASE_URL ?>/admin/pretes-rekap-nilai.php" class="page-nav <?= menuActive('pretes-rekap-nilai.php') ?>">
                <span class="icon">🎓</span> Rekapitulasi Nilai Pretes
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

            <div class="menu-label">Keuangan</div>
            <div class="menu-item has-submenu">
                <a href="#" class="page-nav submenu-toggle <?= (in_array($currentPage, ['keuangan.php'])) ? 'active' : '' ?>">
                    <span class="icon">💰</span> Keuangan
                    <span class="arrow" style="float: right; font-size: 10px; margin-top: 4px; transition: transform 0.2s;">▼</span>
                </a>
                <div class="submenu" style="display: <?= (in_array($currentPage, ['keuangan.php'])) ? 'block' : 'none' ?>; background: rgba(0,0,0,0.2); border-radius: 8px; margin: 2px 10px; padding: 4px 0;">
                    <a href="<?= BASE_URL ?>/admin/keuangan.php?view=rencana-anggaran" class="page-nav <?= (isset($_GET['view']) && $_GET['view'] === 'rencana-anggaran') ? 'active' : '' ?>" style="margin: 2px 0; padding-left: 40px; font-size: 13px;">
                        Rencana Anggaran
                    </a>
                    <a href="<?= BASE_URL ?>/admin/keuangan.php?view=transaksi" class="page-nav <?= (isset($_GET['view']) && $_GET['view'] === 'transaksi') ? 'active' : '' ?>" style="margin: 2px 0; padding-left: 40px; font-size: 13px;">
                        Transaksi (In/Out)
                    </a>
                    <a href="<?= BASE_URL ?>/admin/keuangan.php?view=laporan" class="page-nav <?= (isset($_GET['view']) && $_GET['view'] === 'laporan') ? 'active' : '' ?>" style="margin: 2px 0; padding-left: 40px; font-size: 13px;">
                        Laporan
                    </a>
                </div>
            </div>

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

            <div class="menu-label">Administrasi</div>
            <a href="<?= BASE_URL ?>/admin/pengambilan-sertifikat.php" class="page-nav <?= menuActive('pengambilan-sertifikat.php') ?>">
                <span class="icon">🎓</span> Pengambilan Sertifikat
            </a>
            <a href="<?= BASE_URL ?>/admin/inventaris-surat.php" class="page-nav <?= menuActive('inventaris-surat.php') ?>">
                <span class="icon">📑</span> Inventaris Surat
            </a>
            <a href="<?= BASE_URL ?>/admin/jaminan.php" class="page-nav <?= menuActive('jaminan.php') ?>">
                <span class="icon">💳</span> Jaminan Mahasiswa
            </a>

            <div class="menu-label">Sistem</div>
            <a href="<?= BASE_URL ?>/admin/log-aktivitas.php" class="page-nav <?= menuActive('log-aktivitas.php') ?>">
                <span class="icon">📜</span> Log Aktivitas
            </a>
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
            <a href="<?= BASE_URL ?>/dosen/pretes.php" class="<?= menuActive('pretes.php') ?>" data-no-spa>
                <span class="icon">📝</span> Penilaian & Absensi Pretes
            </a>
            <a href="<?= BASE_URL ?>/dosen/pretes-rekap-nilai.php" class="page-nav <?= menuActive('pretes-rekap-nilai.php') ?>">
                <span class="icon">🎓</span> Rekapitulasi Nilai Pretes
            </a>
            <a href="<?= BASE_URL ?>/dosen/kelas.php" class="page-nav <?= menuActive('kelas.php') ?>">
                <span class="icon">🏫</span> Kelas Anda
            </a>
            <a href="<?= BASE_URL ?>/rekap-nilai.php" class="page-nav <?= menuActive('rekap-nilai.php') ?>">
                <span class="icon">🎓</span> Rekapitulasi Nilai
            </a>
                <?php else: ?>
            <?php
            require_once __DIR__ . '/pretes-helper.php';
            require_once __DIR__ . '/tutorial-membership.php';

            $db = null;
            if (function_exists('getDBConnection')) {
                try {
                    $db = getDBConnection();
                } catch (Throwable $e) {
                    $db = null;
                }
            }

            $hasTutorialReg = ($db && function_exists('studentHasTutorialRegistration'))
                ? studentHasTutorialRegistration($db, $currentUser ?? null)
                : false;
            $hasPretesReg = ($db && function_exists('studentHasPretesRegistration'))
                ? studentHasPretesRegistration($db, $currentUser ?? null)
                : false;
            $hasActiveTutorialReg = ($db && function_exists('studentHasActiveTutorialRegistration'))
                ? studentHasActiveTutorialRegistration($db, $currentUser ?? null)
                : false;

            // Sembunyikan menu pretes jika:
            // 1. Mahasiswa tidak terdaftar pretes sama sekali (baik periode/status aktif maupun non-aktif), ATAU
            // 2. Mahasiswa memiliki pendaftaran tutorial aktif (fokus mengikuti tutorial)
            $hidePretesMenus = (!$hasPretesReg) || $hasActiveTutorialReg;
            $showDaftarPretesMenu = ($db && function_exists('shouldShowDaftarPretesToStudent'))
                ? shouldShowDaftarPretesToStudent($db, $currentUser ?? null)
                : true;
            $isMahasiswaPretesAktif = !$hidePretesMenus && $db && function_exists('isMahasiswaPretesAktif')
                && isMahasiswaPretesAktif($db, $currentUser ?? null);

            // Sembunyikan menu tutorial jika:
            // 1. Mahasiswa sedang dalam tahapan pretes aktif, ATAU
            // 2. Mahasiswa tidak terdaftar tutorial dan tidak terdaftar pretes yang membutuhkan tutorial
            //    (Mahasiswa yang telah lulus pretes dibebaskan dari tutorial).
            $isLulusPretes = ($currentUser && $db && function_exists('isUserLulusPretes'))
                ? isUserLulusPretes($db, (int)($currentUser['id'] ?? 0))
                : false;
            $showTutorialMenus = !$isMahasiswaPretesAktif && ($hasTutorialReg || ($hasPretesReg && !$isLulusPretes));

            // Item dan label Absensi: tampil hanya bila ada item yang relevan dan tidak dalam pretes aktif
            $showAbsensiPretes    = !$hidePretesMenus;
            $showAbsensiTutorial  = $showTutorialMenus && $hasTutorialReg;
            $showAbsensiAlKhidmah = $showTutorialMenus && $hasTutorialReg;
            $hasAnyAbsensiItem    = $showAbsensiPretes || $showAbsensiTutorial || $showAbsensiAlKhidmah;
            ?>
            <!-- MAHASISWA MENU -->
            <div class="menu-label">Dashboard</div>
            <a href="<?= BASE_URL ?>/dashboard.php" class="page-nav <?= menuActive('dashboard.php') ?>">
                <span class="icon">🏠</span> Dashboard
            </a>

            <?php if (!$hidePretesMenus): ?>
            <div class="menu-label">Pretes</div>
            <?php if ($showDaftarPretesMenu): ?>
            <a href="<?= BASE_URL ?>/pretes-daftar.php" class="page-nav <?= menuActive('pretes-daftar.php') ?>">
                <span class="icon">✍️</span> Daftar Pretes
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/pretes-peserta.php" class="page-nav <?= menuActive('pretes-peserta.php') ?>">
                <span class="icon">📋</span> Peserta & Jadwal Pretes
            </a>
            <a href="<?= BASE_URL ?>/pretes-hasil.php" class="page-nav <?= menuActive('pretes-hasil.php') ?>">
                <span class="icon">📊</span> Hasil Pretes
            </a>
            <?php endif; ?>

            <?php if ($showTutorialMenus): ?>
            <div class="menu-label">Tutorial</div>
            <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="page-nav <?= menuActive('tutorial-pendaftaran.php') ?>">
                <span class="icon">📝</span> Pendaftaran Tutorial
            </a>
            <a href="<?= BASE_URL ?>/tutorial-info.php" class="page-nav <?= menuActive('tutorial-info.php') ?>">
                <span class="icon">📘</span> Info Tutorial
            </a>
            <a href="<?= BASE_URL ?>/rekap-nilai.php" class="page-nav <?= menuActive('rekap-nilai.php') ?>">
                <span class="icon">📋</span> Rincian Nilai
            </a>
            <a href="<?= BASE_URL ?>/tutorial-pengumuman.php" class="page-nav <?= menuActive('tutorial-pengumuman.php') ?>">
                <span class="icon">🎉</span> Kelulusan Tutorial
            </a>
            <a href="<?= BASE_URL ?>/ambil-sertifikat.php" class="page-nav <?= menuActive('ambil-sertifikat.php') ?>">
                <span class="icon">📷</span> Scan Pengambilan Sertifikat
            </a>
            <?php endif; ?>

            <?php if ($hasAnyAbsensiItem && !$isMahasiswaPretesAktif): ?>
            <div class="menu-label">Absensi</div>
            <div class="menu-item has-submenu">
                <a href="#" class="page-nav submenu-toggle <?= in_array($currentPage, ['absensi-pretes.php', 'absensi-tutorial.php', 'absensi-alkhidmah.php']) ? 'active' : '' ?>">
                    <span class="icon">📅</span> Absensi
                    <span class="arrow" style="float: right; font-size: 10px; margin-top: 4px; transition: transform 0.2s;">▼</span>
                </a>
                <div class="submenu" style="display: <?= in_array($currentPage, ['absensi-pretes.php', 'absensi-tutorial.php', 'absensi-alkhidmah.php']) ? 'block' : 'none' ?>; background: rgba(0,0,0,0.2); border-radius: 8px; margin: 2px 10px; padding: 4px 0;">
                    <?php if ($showAbsensiPretes): ?>
                    <a href="<?= BASE_URL ?>/absensi-pretes.php" class="page-nav <?= menuActive('absensi-pretes.php') ?>" style="margin: 2px 0; padding-left: 40px; font-size: 13px;">
                        Absensi Pretes
                    </a>
                    <?php endif; ?>
                    <?php if ($showAbsensiTutorial): ?>
                    <a href="<?= BASE_URL ?>/absensi-tutorial.php" class="page-nav <?= menuActive('absensi-tutorial.php') ?>" style="margin: 2px 0; padding-left: 40px; font-size: 13px;">
                        Absensi Tutorial
                    </a>
                    <?php endif; ?>
                    <?php if ($showAbsensiAlKhidmah): ?>
                    <a href="<?= BASE_URL ?>/absensi-alkhidmah.php" class="page-nav <?= menuActive('absensi-alkhidmah.php') ?>" style="margin: 2px 0; padding-left: 40px; font-size: 13px;">
                        Absensi al Khidmah
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="menu-label">Informasi</div>
        <a href="<?= BASE_URL ?>/about.php" class="page-nav <?= menuActive('about.php') ?>">
            <span class="icon">ℹ️</span> Tentang Kami
        </a>
        <a href="<?= BASE_URL ?>/privacy-policy.php" class="page-nav <?= menuActive('privacy-policy.php') ?>">
            <span class="icon">🔒</span> Kebijakan Privasi
        </a>
        <a href="<?= BASE_URL ?>/hapus-akun.php" class="page-nav <?= menuActive('hapus-akun.php') ?>">
            <span class="icon">🗑️</span> Hapus Akun
        </a>

        <div class="menu-label">Akun</div>
        <a href="<?= BASE_URL ?>/logout.php" style="margin-bottom:14px;">
            <span class="icon">🚪</span> Keluar
        </a>

        <div style="padding: 10px 14px 20px; text-align: center;">
            <div style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 7px 12px; display: inline-flex; align-items: center; justify-content: center; gap: 7px; font-size: 11px; color: rgba(255,255,255,0.55); width: 100%; box-sizing:border-box;">
                <span style="width: 6px; height: 6px; border-radius: 50%; background: #10b981; display: inline-block; box-shadow: 0 0 6px #10b981;"></span>
                <span>LPPAI Core &bull; Online</span>
            </div>
        </div>
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
