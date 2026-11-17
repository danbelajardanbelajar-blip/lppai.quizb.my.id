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

if (!function_exists('isNavGroupActive')) {
    function isNavGroupActive($pages = [], $extraCondition = false) {
        global $currentPage;
        if ($extraCondition) {
            return true;
        }
        return in_array($currentPage, $pages, true);
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
    <div class="sidebar-search" role="search" aria-label="Pencarian menu">
        <label for="sidebar-menu-search">Cari menu</label>
        <div class="sidebar-search-field">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg>
            <input id="sidebar-menu-search" type="search" placeholder="Ketik nama menu…" autocomplete="off" aria-controls="sidebar-navigation" aria-describedby="sidebar-search-status">
            <button id="sidebar-search-clear" type="button" aria-label="Hapus pencarian menu" hidden>×</button>
        </div>
        <p id="sidebar-search-status" role="status" aria-live="polite" hidden></p>
    </div>
    <nav class="sidebar-menu" id="sidebar-navigation" aria-label="Menu utama">
        <?php if ($isAdmin): ?>
            <!-- ADMIN MENU -->
            <div class="menu-label">Menu Utama</div>
            <a href="<?= BASE_URL ?>/admin/dashboard.php" class="page-nav <?= menuActive('dashboard.php') ?>">
                <span class="icon">📊</span>
                <span class="nav-text">Dashboard Admin</span>
            </a>

            <!-- MANAJEMEN PRETES -->
            <?php
            $pretesPages = [
                'pretes-pengaturan-tutor.php',
                'pretes-dosen.php',
                'pretes-jadwal.php',
                'pretes-peserta.php',
                'pretes-peserta-export.php',
                'pretes-nilai.php',
                'pretes-rekap-nilai.php',
                'pretes-tugas.php'
            ];
            $isPretesGroupActive = isNavGroupActive($pretesPages, isset($_GET['cbt_bank']));
            ?>
            <div class="nav-group <?= $isPretesGroupActive ? 'open' : '' ?>" data-group="admin-pretes">
                <button type="button" class="nav-group-toggle <?= $isPretesGroupActive ? 'has-active' : '' ?>" aria-expanded="<?= $isPretesGroupActive ? 'true' : 'false' ?>">
                    <span class="icon">🧑‍🏫</span>
                    <span class="nav-text">Manajemen Pretes</span>
                    <span class="nav-badge">8</span>
                    <span class="chevron">▾</span>
                </button>
                <div class="nav-group-content">
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
                    <a href="<?= BASE_URL ?>/admin/pretes-tugas.php" class="page-nav <?= menuActive('pretes-tugas.php') ?>">
                        <span class="icon">📋</span> Kelola Tugas Pretes
                    </a>
                    <a href="<?= BASE_URL ?>/pretes-ujian.php?cbt_bank=1" class="<?= isset($_GET['cbt_bank']) ? 'active' : '' ?>" data-no-spa>
                        <span class="icon">🧩</span> Bank Soal & Hasil CBT
                    </a>
                    <a href="<?= BASE_URL ?>/admin/pretes-rekap-nilai.php" class="page-nav <?= menuActive('pretes-rekap-nilai.php') ?>">
                        <span class="icon">🎓</span> Rekapitulasi Nilai Pretes
                    </a>
                </div>
            </div>

            <!-- MANAJEMEN TUTORIAL -->
            <?php
            $tutorialPages = [
                'tutorial-kelas.php',
                'tutorial-pendaftaran-kolektif.php',
                'tutorial-data-pendaftar.php',
                'tutorial-pengaturan-generate.php',
                'tutorial-hasil-plotting.php',
                'tutorial-rekap-absensi.php',
                'rekap-nilai.php',
                'kelola-nilai.php'
            ];
            $isTutorialGroupActive = isNavGroupActive($tutorialPages);
            $isTutorialSubmenuActive = in_array($currentPage, [
                'tutorial-pendaftaran-kolektif.php',
                'tutorial-data-pendaftar.php',
                'tutorial-pengaturan-generate.php',
                'tutorial-hasil-plotting.php'
            ], true);
            ?>
            <div class="nav-group <?= $isTutorialGroupActive ? 'open' : '' ?>" data-group="admin-tutorial">
                <button type="button" class="nav-group-toggle <?= $isTutorialGroupActive ? 'has-active' : '' ?>" aria-expanded="<?= $isTutorialGroupActive ? 'true' : 'false' ?>">
                    <span class="icon">🏫</span>
                    <span class="nav-text">Manajemen Tutorial</span>
                    <span class="nav-badge">5</span>
                    <span class="chevron">▾</span>
                </button>
                <div class="nav-group-content">
                    <a href="<?= BASE_URL ?>/admin/tutorial-kelas.php" class="page-nav <?= menuActive('tutorial-kelas.php') ?>">
                        <span class="icon">🏫</span> Kelola Kelas Tutorial
                    </a>
                    <div class="menu-item has-submenu">
                        <a href="#" class="page-nav submenu-toggle <?= $isTutorialSubmenuActive ? 'active' : '' ?>">
                            <span class="icon">📋</span> Data Peserta Tutorial
                            <span class="arrow" style="float: right; font-size: 10px; margin-top: 4px; transition: transform 0.2s;<?= $isTutorialSubmenuActive ? ' transform: rotate(180deg);' : '' ?>">▼</span>
                        </a>
                        <div class="submenu" style="display: <?= $isTutorialSubmenuActive ? 'block' : 'none' ?>;">
                            <a href="<?= BASE_URL ?>/admin/tutorial-pendaftaran-kolektif.php" class="page-nav <?= menuActive('tutorial-pendaftaran-kolektif.php') ?>">
                                Pendaftaran Kolektif
                            </a>
                            <a href="<?= BASE_URL ?>/admin/tutorial-data-pendaftar.php" class="page-nav <?= menuActive('tutorial-data-pendaftar.php') ?>">
                                Data Pendaftar
                            </a>
                            <a href="<?= BASE_URL ?>/admin/tutorial-pengaturan-generate.php" class="page-nav <?= menuActive('tutorial-pengaturan-generate.php') ?>">
                                Pengaturan & Generate
                            </a>
                            <a href="<?= BASE_URL ?>/admin/tutorial-hasil-plotting.php" class="page-nav <?= menuActive('tutorial-hasil-plotting.php') ?>">
                                Hasil Plotting & Jadwal
                            </a>
                        </div>
                    </div>
                    <a href="<?= BASE_URL ?>/admin/tutorial-rekap-absensi.php" class="page-nav <?= menuActive('tutorial-rekap-absensi.php') ?>">
                        <span class="icon">📊</span> Rekap Kehadiran
                    </a>
                    <a href="<?= BASE_URL ?>/rekap-nilai.php" class="page-nav <?= menuActive('rekap-nilai.php') ?>">
                        <span class="icon">🎓</span> Rekapitulasi Nilai
                    </a>
                    <a href="<?= BASE_URL ?>/admin/kelola-nilai.php" class="page-nav <?= menuActive('kelola-nilai.php') ?>">
                        <span class="icon">📊</span> Kelola Nilai (Lama)
                    </a>
                </div>
            </div>

                        <!-- TUTORIAL RPL ONLINE -->
            <?php
            $tutorialRplPages = [
                'tutorial-rpl-kelas.php',
                'tutorial-rpl-peserta.php',
                'tutorial-rpl-presensi.php',
                'tutorial-rpl-cbt.php',
                'tutorial-rpl-nilai.php'
            ];
            $isTutorialRplGroupActive = isNavGroupActive($tutorialRplPages);
            ?>
            <div class="nav-group <?= $isTutorialRplGroupActive ? 'open' : '' ?>" data-group="admin-tutorial-rpl">
                <button type="button" class="nav-group-toggle <?= $isTutorialRplGroupActive ? 'has-active' : '' ?>" aria-expanded="<?= $isTutorialRplGroupActive ? 'true' : 'false' ?>">
                    <span class="icon">🌐</span>
                    <span class="nav-text">Tutorial RPL Online</span>
                    <span class="nav-badge">5</span>
                    <span class="chevron">▾</span>
                </button>
                <div class="nav-group-content">
                    <a href="<?= BASE_URL ?>/admin/tutorial-rpl-kelas.php" class="page-nav <?= menuActive('tutorial-rpl-kelas.php') ?>">
                        <span class="icon">🏫</span> Kelola Kelompok & Tutor
                    </a>
                    <a href="<?= BASE_URL ?>/admin/tutorial-rpl-peserta.php" class="page-nav <?= menuActive('tutorial-rpl-peserta.php') ?>">
                        <span class="icon">👥</span> Data Peserta & Plotting
                    </a>
                    <a href="<?= BASE_URL ?>/admin/tutorial-rpl-presensi.php" class="page-nav <?= menuActive('tutorial-rpl-presensi.php') ?>">
                        <span class="icon">✅</span> Verifikasi Tugas & Presensi
                    </a>
                    <a href="<?= BASE_URL ?>/admin/tutorial-rpl-cbt.php" class="page-nav <?= menuActive('tutorial-rpl-cbt.php') ?>">
                        <span class="icon">🧩</span> Ujian Tulis Tutorial (CBT)
                    </a>
                    <a href="<?= BASE_URL ?>/admin/tutorial-rpl-nilai.php" class="page-nav <?= menuActive('tutorial-rpl-nilai.php') ?>">
                        <span class="icon">🎓</span> Rekapitulasi Nilai RPL
                    </a>
                </div>
            </div>

<!-- KEUANGAN -->
            <?php
            $isKeuanganGroupActive = ($currentPage === 'keuangan.php');
            ?>
            <div class="nav-group <?= $isKeuanganGroupActive ? 'open' : '' ?>" data-group="admin-keuangan">
                <button type="button" class="nav-group-toggle <?= $isKeuanganGroupActive ? 'has-active' : '' ?>" aria-expanded="<?= $isKeuanganGroupActive ? 'true' : 'false' ?>">
                    <span class="icon">💰</span>
                    <span class="nav-text">Keuangan</span>
                    <span class="nav-badge">3</span>
                    <span class="chevron">▾</span>
                </button>
                <div class="nav-group-content">
                    <a href="<?= BASE_URL ?>/admin/keuangan.php?view=rencana-anggaran" class="page-nav <?= (isset($_GET['view']) && $_GET['view'] === 'rencana-anggaran') ? 'active' : '' ?>">
                        <span class="icon">📑</span> Rencana Anggaran
                    </a>
                    <a href="<?= BASE_URL ?>/admin/keuangan.php?view=transaksi" class="page-nav <?= (isset($_GET['view']) && $_GET['view'] === 'transaksi') ? 'active' : '' ?>">
                        <span class="icon">💳</span> Transaksi (In/Out)
                    </a>
                    <a href="<?= BASE_URL ?>/admin/keuangan.php?view=laporan" class="page-nav <?= (isset($_GET['view']) && $_GET['view'] === 'laporan') ? 'active' : '' ?>">
                        <span class="icon">📈</span> Laporan
                    </a>
                </div>
            </div>

            <!-- PENGGUNA & RUANGAN -->
            <?php
            $penggunaPages = ['users.php', 'ruangan.php', 'absensi-alkhidmah.php'];
            $isPenggunaGroupActive = isNavGroupActive($penggunaPages);
            ?>
            <div class="nav-group <?= $isPenggunaGroupActive ? 'open' : '' ?>" data-group="admin-pengguna">
                <button type="button" class="nav-group-toggle <?= $isPenggunaGroupActive ? 'has-active' : '' ?>" aria-expanded="<?= $isPenggunaGroupActive ? 'true' : 'false' ?>">
                    <span class="icon">👥</span>
                    <span class="nav-text">Pengguna & Fasilitas</span>
                    <span class="nav-badge">3</span>
                    <span class="chevron">▾</span>
                </button>
                <div class="nav-group-content">
                    <a href="<?= BASE_URL ?>/admin/users.php" class="page-nav <?= menuActive('users.php') ?>">
                        <span class="icon">👥</span> Kelola Pengguna
                    </a>
                    <a href="<?= BASE_URL ?>/admin/ruangan.php" class="page-nav <?= menuActive('ruangan.php') ?>">
                        <span class="icon">🏢</span> Kelola Ruangan
                    </a>
                    <a href="<?= BASE_URL ?>/admin/absensi-alkhidmah.php" class="page-nav <?= menuActive('absensi-alkhidmah.php') ?>">
                        <span class="icon">📅</span> Absensi Al Khidmah
                    </a>
                </div>
            </div>

            <!-- ADMINISTRASI & SURAT -->
            <?php
            $adminPages = ['pengambilan-sertifikat.php', 'inventaris-surat.php', 'jaminan.php'];
            $isAdminGroupActive = isNavGroupActive($adminPages);
            ?>
            <div class="nav-group <?= $isAdminGroupActive ? 'open' : '' ?>" data-group="admin-administrasi">
                <button type="button" class="nav-group-toggle <?= $isAdminGroupActive ? 'has-active' : '' ?>" aria-expanded="<?= $isAdminGroupActive ? 'true' : 'false' ?>">
                    <span class="icon">📑</span>
                    <span class="nav-text">Administrasi & Layanan</span>
                    <span class="nav-badge">3</span>
                    <span class="chevron">▾</span>
                </button>
                <div class="nav-group-content">
                    <a href="<?= BASE_URL ?>/admin/pengambilan-sertifikat.php" class="page-nav <?= menuActive('pengambilan-sertifikat.php') ?>">
                        <span class="icon">🎓</span> Pengambilan Sertifikat
                    </a>
                    <a href="<?= BASE_URL ?>/admin/inventaris-surat.php" class="page-nav <?= menuActive('inventaris-surat.php') ?>">
                        <span class="icon">📑</span> Inventaris Surat
                    </a>
                    <a href="<?= BASE_URL ?>/admin/jaminan.php" class="page-nav <?= menuActive('jaminan.php') ?>">
                        <span class="icon">💳</span> Jaminan Mahasiswa
                    </a>
                </div>
            </div>

            <!-- SISTEM & PENGUMUMAN -->
            <?php
            $sistemPages = ['pengumuman.php', 'log-aktivitas.php', 'backup-restore.php'];
            $isSistemGroupActive = isNavGroupActive($sistemPages);
            ?>
            <div class="nav-group <?= $isSistemGroupActive ? 'open' : '' ?>" data-group="admin-sistem">
                <button type="button" class="nav-group-toggle <?= $isSistemGroupActive ? 'has-active' : '' ?>" aria-expanded="<?= $isSistemGroupActive ? 'true' : 'false' ?>">
                    <span class="icon">⚙️</span>
                    <span class="nav-text">Sistem & Pengumuman</span>
                    <span class="nav-badge">3</span>
                    <span class="chevron">▾</span>
                </button>
                <div class="nav-group-content">
                    <a href="<?= BASE_URL ?>/admin/pengumuman.php" class="page-nav <?= menuActive('pengumuman.php') ?>">
                        <span class="icon">📢</span> Kelola Pengumuman
                    </a>
                    <a href="<?= BASE_URL ?>/admin/log-aktivitas.php" class="page-nav <?= menuActive('log-aktivitas.php') ?>">
                        <span class="icon">📜</span> Log Aktivitas
                    </a>
                    <a href="<?= BASE_URL ?>/admin/backup-restore.php" class="page-nav <?= menuActive('backup-restore.php') ?>">
                        <span class="icon">💾</span> Backup & Restore
                    </a>
                </div>
            </div>
        <?php elseif ($isDosen): ?>
            <!-- DOSEN MENU -->
            <div class="menu-label">Dashboard</div>
            <a href="<?= BASE_URL ?>/dosen/dashboard.php" class="page-nav <?= menuActive('dashboard.php') ?>">
                <span class="icon">🏠</span>
                <span class="nav-text">Dashboard Dosen</span>
            </a>

            <div class="menu-label">Akademik</div>
            <?php
            // Menu pretes hanya untuk dosen yang terjadwal sebagai penguji pada periode pretes aktif
            // (dicocokkan lewat NIM akun dosen, bukan nama). Lihat includes/pretes-examiner.php.
            $showDosenPretesMenu = false;
            if (function_exists('getDBConnection')) {
                try {
                    require_once __DIR__ . '/pretes-examiner.php';
                    $showDosenPretesMenu = pretesExaminerIsScheduled(getDBConnection(), $currentUser ?? null);
                } catch (Throwable $e) { $showDosenPretesMenu = false; }
            }
            ?>
            <?php if ($showDosenPretesMenu): ?>
            <a href="<?= BASE_URL ?>/dosen/pretes.php" class="<?= menuActive('pretes.php') ?>" data-no-spa>
                <span class="icon">📝</span>
                <span class="nav-text">Penilaian & Absensi Pretes</span>
            </a>
            <a href="<?= BASE_URL ?>/dosen/pretes-rekap-nilai.php" class="page-nav <?= menuActive('pretes-rekap-nilai.php') ?>">
                <span class="icon">🎓</span>
                <span class="nav-text">Rekapitulasi Nilai Pretes</span>
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/dosen/kelas.php" class="page-nav <?= menuActive('kelas.php') ?>">
                <span class="icon">🏫</span>
                <span class="nav-text">Kelas Anda</span>
            </a>
            <a href="<?= BASE_URL ?>/rekap-nilai.php" class="page-nav <?= menuActive('rekap-nilai.php') ?>">
                <span class="icon">🎓</span>
                <span class="nav-text">Rekapitulasi Nilai</span>
            </a>
                    <?php
            $showDosenTutorialRpl = false;
            if (function_exists('getDBConnection')) {
                try {
                    require_once __DIR__ . '/tutorial-rpl-helper.php';
                    $showDosenTutorialRpl = tutorialRplIsTutor(getDBConnection(), $currentUser ?? null);
                } catch (Throwable $e) { $showDosenTutorialRpl = false; }
            }
            ?>
            <?php if ($showDosenTutorialRpl): ?>
            <a href="<?= BASE_URL ?>/dosen/tutorial-rpl.php" class="page-nav <?= menuActive('tutorial-rpl.php') ?>">
                <span class="icon">🌐</span>
                <span class="nav-text">Tutorial RPL Online</span>
            </a>
            <?php endif; ?>
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
            // Sudah lulus pretes: tidak perlu mendaftar pretes lagi
            if ($isLulusPretes) $showDaftarPretesMenu = false;
            // Menu sertifikat bagi yang lulus pretes (bila menu tutorial yang memuatnya tidak tampil).
            // RPL mode "cukup CBT": baru tampil setelah ada pengumuman Pengambilan Sertifikat Pretes.
            $showPretesSertifikatMenu = false;
            if ($isLulusPretes && !$showTutorialMenus && !$hidePretesMenus && $db) {
                require_once __DIR__ . '/pretes-certificate.php';
                $showPretesSertifikatMenu = pretesIsRplCbtOnlyUser($currentUser ?? null)
                    ? (pretesCertificateAnnouncement($db, $currentUser) !== null)
                    : true;
            }

            // Item dan label Absensi: tampil hanya bila ada item yang relevan dan tidak dalam pretes aktif
            $showAbsensiPretes    = !$hidePretesMenus;
            $showAbsensiTutorial  = $showTutorialMenus && $hasTutorialReg;
            $showAbsensiAlKhidmah = $showTutorialMenus && $hasTutorialReg;
            $hasAnyAbsensiItem    = $showAbsensiPretes || $showAbsensiTutorial || $showAbsensiAlKhidmah;
            ?>
            <!-- MAHASISWA MENU -->
            <div class="menu-label">Dashboard</div>
            <a href="<?= BASE_URL ?>/dashboard.php" class="page-nav <?= menuActive('dashboard.php') ?>">
                <span class="icon">🏠</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <?php if (!$hidePretesMenus): ?>
            <div class="menu-label">Pretes</div>
            <?php if ($showDaftarPretesMenu): ?>
            <a href="<?= BASE_URL ?>/pretes-daftar.php" class="page-nav <?= menuActive('pretes-daftar.php') ?>">
                <span class="icon">✍️</span>
                <span class="nav-text">Daftar Pretes</span>
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/pretes-peserta.php" class="page-nav <?= menuActive('pretes-peserta.php') ?>">
                <span class="icon">📋</span>
                <span class="nav-text">Peserta & Jadwal Pretes</span>
            </a>
            <a href="<?= BASE_URL ?>/pretes-hasil.php" class="page-nav <?= menuActive('pretes-hasil.php') ?>">
                <span class="icon">📊</span>
                <span class="nav-text">Hasil Pretes</span>
            </a>
            <?php if ($showPretesSertifikatMenu): ?>
            <a href="<?= BASE_URL ?>/ambil-sertifikat.php" class="page-nav <?= menuActive('ambil-sertifikat.php') ?>">
                <span class="icon">📷</span>
                <span class="nav-text">Scan Pengambilan Sertifikat</span>
            </a>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($showTutorialMenus): ?>
            <div class="menu-label">Tutorial</div>
            <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="page-nav <?= menuActive('tutorial-pendaftaran.php') ?>">
                <span class="icon">📝</span>
                <span class="nav-text">Pendaftaran Tutorial</span>
            </a>
            <a href="<?= BASE_URL ?>/tutorial-info.php" class="page-nav <?= menuActive('tutorial-info.php') ?>">
                <span class="icon">📘</span>
                <span class="nav-text">Info Tutorial</span>
            </a>
            <a href="<?= BASE_URL ?>/rekap-nilai.php" class="page-nav <?= menuActive('rekap-nilai.php') ?>">
                <span class="icon">📋</span>
                <span class="nav-text">Rincian Nilai</span>
            </a>
            <a href="<?= BASE_URL ?>/tutorial-pengumuman.php" class="page-nav <?= menuActive('tutorial-pengumuman.php') ?>">
                <span class="icon">🎉</span>
                <span class="nav-text">Kelulusan Tutorial</span>
            </a>
            <a href="<?= BASE_URL ?>/ambil-sertifikat.php" class="page-nav <?= menuActive('ambil-sertifikat.php') ?>">
                <span class="icon">📷</span>
                <span class="nav-text">Scan Pengambilan Sertifikat</span>
            </a>
            <?php endif; ?>

            <?php if ($hasAnyAbsensiItem && !$isMahasiswaPretesAktif): ?>
            <div class="menu-label">Absensi</div>
            <div class="menu-item has-submenu">
                <a href="#" class="page-nav submenu-toggle <?= in_array($currentPage, ['absensi-pretes.php', 'absensi-tutorial.php', 'absensi-alkhidmah.php']) ? 'active' : '' ?>">
                    <span class="icon">📅</span> Absensi
                    <span class="arrow" style="float: right; font-size: 10px; margin-top: 4px; transition: transform 0.2s;">▼</span>
                </a>
                <div class="submenu" style="display: <?= in_array($currentPage, ['absensi-pretes.php', 'absensi-tutorial.php', 'absensi-alkhidmah.php']) ? 'block' : 'none' ?>;">
                    <?php if ($showAbsensiPretes): ?>
                    <a href="<?= BASE_URL ?>/absensi-pretes.php" class="page-nav <?= menuActive('absensi-pretes.php') ?>">
                        Absensi Pretes
                    </a>
                    <?php endif; ?>
                    <?php if ($showAbsensiTutorial): ?>
                    <a href="<?= BASE_URL ?>/absensi-tutorial.php" class="page-nav <?= menuActive('absensi-tutorial.php') ?>">
                        Absensi Tutorial
                    </a>
                    <?php endif; ?>
                    <?php if ($showAbsensiAlKhidmah): ?>
                    <a href="<?= BASE_URL ?>/absensi-alkhidmah.php" class="page-nav <?= menuActive('absensi-alkhidmah.php') ?>">
                        Absensi al Khidmah
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- INFORMASI & BANTUAN -->
        <?php
        $infoPages = ['about.php', 'privacy-policy.php', 'hapus-akun.php'];
        $isInfoGroupActive = isNavGroupActive($infoPages);
        ?>
        <div class="nav-group <?= $isInfoGroupActive ? 'open' : '' ?>" data-group="menu-informasi" style="margin-top: 8px;">
            <button type="button" class="nav-group-toggle <?= $isInfoGroupActive ? 'has-active' : '' ?>" aria-expanded="<?= $isInfoGroupActive ? 'true' : 'false' ?>">
                <span class="icon">ℹ️</span>
                <span class="nav-text">Informasi & Bantuan</span>
                <span class="nav-badge">3</span>
                <span class="chevron">▾</span>
            </button>
            <div class="nav-group-content">
                <a href="<?= BASE_URL ?>/about.php" class="page-nav <?= menuActive('about.php') ?>">
                    <span class="icon">ℹ️</span>
                    <span class="nav-text">Tentang Kami</span>
                </a>
                <a href="<?= BASE_URL ?>/privacy-policy.php" class="page-nav <?= menuActive('privacy-policy.php') ?>">
                    <span class="icon">🔒</span>
                    <span class="nav-text">Kebijakan Privasi</span>
                </a>
                <a href="<?= BASE_URL ?>/hapus-akun.php" class="page-nav <?= menuActive('hapus-akun.php') ?>">
                    <span class="icon">🗑️</span>
                    <span class="nav-text">Hapus Akun</span>
                </a>
            </div>
        </div>

        <!-- AKUN PENGGUNA -->
        <?php
        $akunPages = ['profil.php', 'pengaturan.php'];
        $isAkunGroupActive = isNavGroupActive($akunPages);
        ?>
        <div class="nav-group <?= $isAkunGroupActive ? 'open' : '' ?>" data-group="menu-akun">
            <button type="button" class="nav-group-toggle <?= $isAkunGroupActive ? 'has-active' : '' ?>" aria-expanded="<?= $isAkunGroupActive ? 'true' : 'false' ?>">
                <span class="icon">👤</span>
                <span class="nav-text">Akun Saya</span>
                <span class="chevron">▾</span>
            </button>
            <div class="nav-group-content">
                <a href="<?= BASE_URL ?>/profil.php" class="page-nav <?= menuActive('profil.php') ?>">
                    <span class="icon">👤</span>
                    <span class="nav-text">Profil Saya</span>
                </a>
                <a href="<?= BASE_URL ?>/pengaturan.php" class="page-nav <?= menuActive('pengaturan.php') ?>">
                    <span class="icon">⚙️</span>
                    <span class="nav-text">Pengaturan</span>
                </a>
                <a href="<?= BASE_URL ?>/logout.php">
                    <span class="icon">🚪</span>
                    <span class="nav-text">Keluar</span>
                </a>
            </div>
        </div>

        <div style="padding: 10px 14px 16px; text-align: center;">
            <div style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 7px 12px; display: inline-flex; align-items: center; justify-content: center; gap: 7px; font-size: 11px; color: rgba(255,255,255,0.55); width: 100%; box-sizing:border-box;">
                <span style="width: 6px; height: 6px; border-radius: 50%; background: #10b981; display: inline-block; box-shadow: 0 0 6px #10b981;"></span>
                <span>LPPAI Core &bull; Online</span>
            </div>
        </div>
    </nav>
</aside>
<script defer src="<?= BASE_URL ?>/assets/js/sidebar-search.js?v=<?= filemtime(__DIR__ . '/../assets/js/sidebar-search.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Nav group accordion toggles
    var groupToggles = document.querySelectorAll('.nav-group-toggle');
    groupToggles.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (this.closest('.sidebar-searching')) return;
            var group = this.closest('.nav-group');
            if (!group) return;
            var isOpen = group.classList.contains('open');

            // Accordion behavior: close sibling groups to maintain compact vertical height
            if (!isOpen) {
                var menuContainer = group.closest('.sidebar-menu');
                if (menuContainer) {
                    menuContainer.querySelectorAll('.nav-group.open').forEach(function(other) {
                        if (other !== group) {
                            other.classList.remove('open');
                            var otherBtn = other.querySelector('.nav-group-toggle');
                            if (otherBtn) otherBtn.setAttribute('aria-expanded', 'false');
                        }
                    });
                }
                group.classList.add('open');
                this.setAttribute('aria-expanded', 'true');
            } else {
                group.classList.remove('open');
                this.setAttribute('aria-expanded', 'false');
            }

            try {
                var groupId = group.getAttribute('data-group');
                if (groupId) {
                    var saved = JSON.parse(localStorage.getItem('lppai_sidebar_groups') || '{}');
                    saved[groupId] = !isOpen;
                    localStorage.setItem('lppai_sidebar_groups', JSON.stringify(saved));
                }
            } catch (err) {}
        });
    });

    // 2. Submenu toggles (for nested submenus like Tutorial Data Peserta & Student Absensi)
    var subToggles = document.querySelectorAll('.submenu-toggle');
    subToggles.forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            if (this.closest('.sidebar-searching')) return;
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
