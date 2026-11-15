<?php
/**
 * LPPAI Corner - Header Include
 */
if (!defined('PAGE_TITLE')) define('PAGE_TITLE', 'LPPAI Corner');
if (!defined('EXTRA_HEAD')) define('EXTRA_HEAD', '');
$currentUser = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize(PAGE_TITLE) ?> - <?= APP_NAME ?></title>
    <link rel="icon" href="<?= BASE_URL ?>/assets/logo.svg" type="image/svg+xml">
    <link rel="alternate icon" href="<?= BASE_URL ?>/favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time() ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <?= EXTRA_HEAD ?>
</head>
<body>
<?php if (isset($_SESSION['admin_login_as'])): ?>
<div style="background: linear-gradient(135deg, #d97706 0%, #b45309 100%); color:#fff; padding:10px 18px; text-align:center; font-weight:600; position:sticky; top:0; z-index:99999; box-shadow:0 4px 14px rgba(180, 83, 9, 0.3); display:flex; justify-content:center; align-items:center; flex-wrap:wrap; gap:10px 16px; font-size:13px; max-width:100%; box-sizing:border-box; border-bottom: 1px solid rgba(255,255,255,0.2);">
    <span>⚠️ <strong>Mode Penyamaran:</strong> Anda sedang masuk sebagai <strong><?= sanitize($currentUser['nama_lengkap']) ?></strong>.</span>
    <a href="?action=return_admin" style="background:rgba(255,255,255,0.2); color:#fff; padding:5px 14px; border-radius:8px; text-decoration:none; font-size:12px; font-weight:700; border:1px solid rgba(255,255,255,0.35); transition:0.2s; white-space:nowrap; box-shadow:0 2px 6px rgba(0,0,0,0.15);">🔙 Kembali ke Admin</a>
</div>
<?php endif; ?>
<div class="app-wrapper">
    <div class="sidebar-overlay"></div>
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="hamburger" aria-label="Toggle Navigation Menu">&#9776;</button>
                <span class="page-title"><?= sanitize(PAGE_TITLE) ?></span>
            </div>
            <?php
            $userPhotoUrl = function_exists('getUserAvatarUrl') ? getUserAvatarUrl($currentUser) : null;
            $userInitial = strtoupper(substr($currentUser['nama_lengkap'] ?? 'U', 0, 1));
            $userRoleLabel = ($currentUser['role'] ?? '') === 'admin' ? 'Admin' : (($currentUser['role'] ?? '') === 'dosen' ? 'Dosen / Tutor' : 'Mahasiswa');
            ?>
            <div class="user-menu-container" id="userMenuContainer">
                <button type="button" class="user-info user-menu-btn" id="userMenuBtn" aria-haspopup="true" aria-expanded="false" title="Menu Pengguna: Profil & Pengaturan">
                    <div class="user-info-text" style="text-align:right;">
                        <div class="name"><?= sanitize($currentUser['nama_lengkap']) ?></div>
                        <span class="role-badge"><?= $userRoleLabel ?></span>
                    </div>
                    <div class="avatar <?= !empty($userPhotoUrl) ? 'has-photo' : '' ?>" title="<?= sanitize($currentUser['nama_lengkap']) ?>">
                        <?php if (!empty($userPhotoUrl)): ?>
                            <img src="<?= sanitize($userPhotoUrl) ?>" alt="<?= sanitize($currentUser['nama_lengkap']) ?>" class="avatar-img" onerror="this.onerror=null;this.style.display='none';this.parentElement.classList.remove('has-photo');var init=this.parentElement.querySelector('.avatar-initial');if(init)init.style.display='flex';">
                            <span class="avatar-initial" style="display:none;"><?= $userInitial ?></span>
                        <?php else: ?>
                            <span class="avatar-initial"><?= $userInitial ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="user-menu-chevron" aria-hidden="true">&#9662;</span>
                </button>
                <div class="user-dropdown-menu" id="userDropdownMenu" role="menu" aria-labelledby="userMenuBtn">
                    <div class="user-dropdown-header">
                        <div class="dropdown-avatar <?= !empty($userPhotoUrl) ? 'has-photo' : '' ?>">
                            <?php if (!empty($userPhotoUrl)): ?>
                                <img src="<?= sanitize($userPhotoUrl) ?>" alt="<?= sanitize($currentUser['nama_lengkap']) ?>" class="dropdown-avatar-img">
                            <?php else: ?>
                                <span><?= $userInitial ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="dropdown-user-meta">
                            <div class="dropdown-user-name"><?= sanitize($currentUser['nama_lengkap']) ?></div>
                            <div class="dropdown-user-badges">
                                <span class="role-badge"><?= $userRoleLabel ?></span>
                                <span class="user-id-badge"><?= sanitize(!empty($currentUser['nim']) ? $currentUser['nim'] : $currentUser['username']) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="user-dropdown-divider"></div>
                    <div class="user-dropdown-nav">
                        <a href="<?= BASE_URL ?>/profil.php" class="user-dropdown-item" role="menuitem">
                            <span class="dropdown-icon">👤</span>
                            <div class="dropdown-item-text">
                                <div class="dropdown-item-title">Profil Saya</div>
                                <div class="dropdown-item-desc">Lihat & perbarui data profil serta foto</div>
                            </div>
                        </a>
                        <a href="<?= BASE_URL ?>/pengaturan.php" class="user-dropdown-item" role="menuitem">
                            <span class="dropdown-icon">⚙️</span>
                            <div class="dropdown-item-text">
                                <div class="dropdown-item-title">Pengaturan</div>
                                <div class="dropdown-item-desc">Ubah kata sandi & info keamanan akun</div>
                            </div>
                        </a>
                    </div>
                    <div class="user-dropdown-divider"></div>
                    <a href="<?= BASE_URL ?>/logout.php" class="user-dropdown-item user-dropdown-logout" role="menuitem">
                        <span class="dropdown-icon">🚪</span>
                        <div class="dropdown-item-text">
                            <div class="dropdown-item-title">Keluar</div>
                            <div class="dropdown-item-desc">Akhiri sesi aktif Anda</div>
                        </div>
                    </a>
                </div>
            </div>
            <script>
            (function() {
                function setupUserMenu() {
                    var container = document.getElementById('userMenuContainer');
                    var btn = document.getElementById('userMenuBtn');
                    if (!container || !btn || btn.dataset.menuReady) return;
                    btn.dataset.menuReady = '1';

                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var open = container.classList.toggle('open');
                        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    });

                    document.addEventListener('click', function(e) {
                        if (!container.contains(e.target) && container.classList.contains('open')) {
                            container.classList.remove('open');
                            btn.setAttribute('aria-expanded', 'false');
                        }
                    });

                    document.addEventListener('keydown', function(e) {
                        if ((e.key === 'Escape' || e.key === 'Esc') && container.classList.contains('open')) {
                            container.classList.remove('open');
                            btn.setAttribute('aria-expanded', 'false');
                            btn.focus();
                        }
                    });
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', setupUserMenu);
                } else {
                    setupUserMenu();
                }
            })();
            </script>
        </div>
        <div class="content-area">
