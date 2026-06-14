<?php
/**
 * LPPAI Corner - Login Page
 */
require_once __DIR__ . '/includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
    } elseif (isDosen()) {
        header('Location: ' . BASE_URL . '/dosen/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . '/dashboard.php');
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!verifyCsrf($token)) {
        $error = 'Sesi tidak valid. Silakan coba lagi.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Username dan password harus diisi.';
    } elseif (loginUser($username, $password)) {
        if (isAdmin()) {
            header('Location: ' . BASE_URL . '/admin/dashboard.php');
        } elseif (isDosen()) {
            header('Location: ' . BASE_URL . '/dosen/dashboard.php');
        } else {
            header('Location: ' . BASE_URL . '/dashboard.php');
        }
        exit;
    } else {
        $error = 'Username atau password salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="logo">
            <img src="<?= BASE_URL ?>/assets/logo.svg" alt="<?= APP_NAME ?> logo">
        </div>
        <h1><?= APP_NAME ?></h1>
        <p class="subtitle">Lembaga Pengembangan Pendidikan Agama Islam</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="Masukkan username"
                       value="<?= sanitize($_POST['username'] ?? '') ?>" required autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Masukkan password"
                       required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary">Masuk</button>
        </form>

        <p style="margin-top: 24px; font-size: 12px; color: #999;">
            &copy; <?= date('Y') ?> LPPAI Corner
        </p>
    </div>
</div>
</body>
</html>
