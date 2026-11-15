<?php
/**
 * LPPAI Corner - Halaman Pengaturan Akun & Keamanan (Semua Role)
 */
define('PAGE_TITLE', 'Pengaturan');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$currentUser = getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
$pdo = getDBConnection();

$message = '';
$msgType = 'info';

// Handle Change Password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!verifyCsrf($token)) {
        $message = 'Token keamanan (CSRF) tidak valid atau sesi telah kedaluwarsa. Silakan muat ulang halaman.';
        $msgType = 'danger';
    } elseif ($action === 'change_password') {
        $oldPass = (string)($_POST['current_password'] ?? '');
        $newPass = (string)($_POST['new_password'] ?? '');
        $confPass = (string)($_POST['confirm_password'] ?? '');

        if ($oldPass === '' || $newPass === '' || $confPass === '') {
            $message = 'Semua kolom kata sandi wajib diisi.';
            $msgType = 'danger';
        } elseif (strlen($newPass) < 6) {
            $message = 'Kata sandi baru minimal harus terdiri dari 6 karakter.';
            $msgType = 'danger';
        } elseif ($newPass !== $confPass) {
            $message = 'Konfirmasi kata sandi baru tidak cocok dengan kata sandi baru.';
            $msgType = 'danger';
        } else {
            // Ambil hash password saat ini
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $currentHash = (string)$stmt->fetchColumn();

            if (!password_verify($oldPass, $currentHash)) {
                $message = 'Kata sandi saat ini yang Anda masukkan salah.';
                $msgType = 'danger';
            } else {
                $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                $stmtUpdate = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmtUpdate->execute([$newHash, $userId]);

                $message = 'Kata sandi Anda berhasil diperbarui! Silakan gunakan kata sandi baru ini pada login berikutnya.';
                $msgType = 'success';
            }
        }
    }
}

// Data sesi & perangkat
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser';
$roleLabel = ($currentUser['role'] ?? '') === 'admin' ? 'Admin' : (($currentUser['role'] ?? '') === 'dosen' ? 'Dosen / Tutor' : 'Mahasiswa');

include __DIR__ . '/../includes/header.php';
?>

<div class="settings-page-wrapper" style="max-width: 1000px; margin: 0 auto;">
    <!-- Breadcrumb & Top Bar Info -->
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:24px;">
        <div>
            <div style="font-size:12px; color:var(--text-muted); font-weight:600; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                <a href="<?= BASE_URL ?>/dashboard.php" style="color:var(--text-muted); text-decoration:none;">Dashboard</a>
                <span>&rsaquo;</span>
                <span style="color:var(--primary); font-weight:700;">Pengaturan</span>
            </div>
            <h1 style="font-size:24px; font-weight:800; color:var(--primary-dark); margin:0; letter-spacing:-0.4px;">
                Pengaturan Akun & Keamanan
            </h1>
            <p style="margin:4px 0 0; font-size:13.5px; color:var(--text-muted);">
                Kelola kata sandi akun, tinjau keamanan sesi login, dan preferensi antarmuka.
            </p>
        </div>
        <div style="display:flex; gap:10px;">
            <a href="<?= BASE_URL ?>/profil.php" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; padding:9px 16px; border-radius:10px;">
                <span>👤</span> Lihat Profil Saya
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $msgType ?> keep-alert" style="margin-bottom:24px; display:flex; align-items:center; gap:10px; border-radius:12px; font-size:13.5px;">
            <span><?= $msgType === 'success' ? '✅' : ($msgType === 'danger' ? '❌' : 'ℹ️') ?></span>
            <div><?= $message ?></div>
        </div>
    <?php endif; ?>

    <div style="display:grid; grid-template-columns: 1fr 340px; gap:24px; align-items:start;">
        <!-- KOLOM UTAMA: Ganti Password -->
        <div class="card" style="box-shadow: 0 4px 18px rgba(11, 59, 34, 0.07); border-radius:16px;">
            <div class="card-header" style="background:#f8fafc; font-weight:700; border-bottom:1px solid #e2e8f0; padding:16px 24px; display:flex; align-items:center; gap:8px;">
                <span>🔒</span> Ganti Kata Sandi (Password)
            </div>
            <div class="card-body" style="padding:24px;">
                <p style="font-size:13px; color:var(--text-muted); margin-top:0; margin-bottom:20px; line-height:1.5;">
                    Untuk keamanan akun Anda, gunakan kata sandi yang kuat dengan kombinasi huruf, angka, atau karakter khusus, serta jangan berikan kata sandi kepada orang lain.
                </p>

                <form method="POST" id="formChangePassword">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <div>
                            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--text-dark);">
                                Kata Sandi Saat Ini <span style="color:#ef4444;">*</span>
                            </label>
                            <input type="password" name="current_password" class="form-control" required
                                placeholder="Masukkan kata sandi lama Anda"
                                style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #cbd5e1; font-size:14px;">
                        </div>

                        <div>
                            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--text-dark);">
                                Kata Sandi Baru <span style="color:#ef4444;">*</span>
                            </label>
                            <input type="password" name="new_password" class="form-control" required minlength="6"
                                placeholder="Minimal 6 karakter"
                                style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #cbd5e1; font-size:14px;">
                        </div>

                        <div>
                            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--text-dark);">
                                Konfirmasi Kata Sandi Baru <span style="color:#ef4444;">*</span>
                            </label>
                            <input type="password" name="confirm_password" class="form-control" required minlength="6"
                                placeholder="Ulangi kata sandi baru"
                                style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #cbd5e1; font-size:14px;">
                        </div>
                    </div>

                    <div style="margin-top:28px; padding-top:20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end;">
                        <button type="submit" class="btn btn-primary" style="padding:10px 22px; border-radius:10px; font-size:13.5px; font-weight:700; display:inline-flex; align-items:center; gap:8px;">
                            <span>🔐</span> Perbarui Kata Sandi
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- KOLOM SAMPING: Keamanan & Info Sesi -->
        <div style="display:flex; flex-direction:column; gap:24px;">
            <!-- Card Keamanan Sesi -->
            <div class="card" style="box-shadow: 0 4px 18px rgba(11, 59, 34, 0.07); border-radius:16px;">
                <div class="card-header" style="background:#f8fafc; font-weight:700; border-bottom:1px solid #e2e8f0; padding:14px 20px;">
                    🛡️ Keamanan & Sesi
                </div>
                <div class="card-body" style="padding:16px 20px; font-size:12.5px;">
                    <div style="margin-bottom:12px;">
                        <span style="color:var(--text-muted); display:block; font-size:11px; font-weight:700; text-transform:uppercase;">Alamat IP Anda</span>
                        <span style="font-family:monospace; font-weight:700; color:var(--primary); font-size:13px;"><?= sanitize($clientIp) ?></span>
                    </div>

                    <div style="margin-bottom:12px;">
                        <span style="color:var(--text-muted); display:block; font-size:11px; font-weight:700; text-transform:uppercase;">Perangkat / Browser</span>
                        <span style="color:var(--text-dark); word-break:break-all; line-height:1.4; display:block; font-size:11.5px;">
                            <?= sanitize(substr($userAgent, 0, 90)) . (strlen($userAgent) > 90 ? '...' : '') ?>
                        </span>
                    </div>

                    <div style="margin-bottom:12px;">
                        <span style="color:var(--text-muted); display:block; font-size:11px; font-weight:700; text-transform:uppercase;">Metode Hash Keamanan</span>
                        <span style="color:#15803d; font-weight:700; display:inline-flex; align-items:center; gap:5px;">
                            <span style="width:6px; height:6px; border-radius:50%; background:#10b981;"></span> Bcrypt (Standard ISO/IEC)
                        </span>
                    </div>

                    <div>
                        <span style="color:var(--text-muted); display:block; font-size:11px; font-weight:700; text-transform:uppercase;">Proteksi Sesi</span>
                        <span style="color:#0369a1; font-weight:700;">Anti-Session Fixation & CSRF Token</span>
                    </div>
                </div>
            </div>

            <!-- Card Tautan Navigasi -->
            <div class="card" style="box-shadow: 0 4px 18px rgba(11, 59, 34, 0.07); border-radius:16px;">
                <div class="card-header" style="background:#f8fafc; font-weight:700; border-bottom:1px solid #e2e8f0; padding:14px 20px;">
                    🧭 Tautan Bantuan
                </div>
                <div class="card-body" style="padding:12px 16px; font-size:13px; display:flex; flex-direction:column; gap:8px;">
                    <a href="<?= BASE_URL ?>/about.php" style="color:var(--primary); text-decoration:none; padding:6px 8px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                        <span>ℹ️</span> Tentang LPPAI Corner
                    </a>
                    <a href="<?= BASE_URL ?>/privacy-policy.php" style="color:var(--primary); text-decoration:none; padding:6px 8px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                        <span>🔒</span> Kebijakan Privasi
                    </a>
                    <a href="<?= BASE_URL ?>/hapus-akun.php" style="color:#b91c1c; text-decoration:none; padding:6px 8px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                        <span>🗑️</span> Ketentuan Hapus Akun
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/../includes/footer.php';
