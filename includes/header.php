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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <?= EXTRA_HEAD ?>
</head>
<body>
<?php if (isset($_SESSION['admin_login_as'])): ?>
<div style="background-color:#f59e0b; color:#fff; padding:10px 14px; text-align:center; font-weight:600; position:sticky; top:0; z-index:99999; box-shadow:0 2px 4px rgba(0,0,0,0.1); display:flex; justify-content:center; align-items:center; flex-wrap:wrap; gap:8px 14px; font-size:13px; max-width:100%; box-sizing:border-box;">
    <span>⚠️ Mode Penyamaran: Anda sedang masuk sebagai <strong><?= sanitize($currentUser['nama_lengkap']) ?></strong>.</span>
    <a href="?action=return_admin" style="background:#b45309; color:#fff; padding:5px 12px; border-radius:6px; text-decoration:none; font-size:12px; border:1px solid #92400e; transition:0.2s; white-space:nowrap;">🔙 Kembali ke Admin</a>
</div>
<?php endif; ?>
<div class="app-wrapper">
    <div class="sidebar-overlay"></div>
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="hamburger">&#9776;</button>
                <span class="page-title"><?= sanitize(PAGE_TITLE) ?></span>
            </div>
            <div class="user-info">
                <div>
                    <div class="name"><?= sanitize($currentUser['nama_lengkap']) ?></div>
                    <span class="role-badge"><?= $currentUser['role'] === 'admin' ? 'Admin' : ($currentUser['role'] === 'dosen' ? 'Dosen / Tutor' : 'Mahasiswa') ?></span>
                </div>
                <div class="avatar"><?= strtoupper(substr($currentUser['nama_lengkap'], 0, 1)) ?></div>
            </div>
        </div>
        <div class="content-area">
