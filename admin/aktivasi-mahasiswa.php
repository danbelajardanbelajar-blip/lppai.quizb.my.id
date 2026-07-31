<?php
/**
 * LPPAI Corner - Admin: Aktivasi Mahasiswa (via Link)
 */
define('PAGE_TITLE', 'Aktivasi Mahasiswa');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
$message = '';
$msgType = '';
$userData = null;

$token = $_GET['token'] ?? '';

if (empty($token)) {
    $message = "Token aktivasi tidak valid atau tidak ditemukan.";
    $msgType = "danger";
} else {
    // Cari user berdasarkan token
    $stmt = $pdo->prepare("SELECT id, username, nim, nama_lengkap, program_studi, no_hp, is_active FROM users WHERE activation_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $userData = $stmt->fetch();

    if (!$userData) {
        $message = "Token aktivasi tidak valid atau sudah kadaluarsa (kemungkinan akun sudah diaktifkan).";
        $msgType = "warning";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'activate_account') {
    if (!verifyCsrf($_POST['csrf_token'])) {
        $message = "Sesi tidak valid.";
        $msgType = "danger";
    } elseif ($userData) {
        $userId = $userData['id'];
        $noHpMahasiswa = $userData['no_hp'];
        $namaMahasiswa = $userData['nama_lengkap'];

        // Aktifkan akun dan hapus token
        $pdo->prepare("UPDATE users SET is_active = 1, activation_token = NULL WHERE id = ?")->execute([$userId]);
        
        // Bersihkan format nomor HP jika perlu (pastikan berawalan kode negara)
        $waNumber = preg_replace('/[^0-9]/', '', $noHpMahasiswa);
        if (substr($waNumber, 0, 1) === '0') {
            $waNumber = '62' . substr($waNumber, 1);
        }

        // Siapkan pesan ke mahasiswa
        $waText = "Halo {$namaMahasiswa},\n\n";
        $waText .= "Akun LPPAI Corner Anda telah *DISETUJUI* dan *AKTIF*.\n\n";
        $waText .= "Silakan login kembali melalui tautan berikut:\n";
        $waText .= "https://lppai.quizb.my.id/index.php\n\n";
        $waText .= "Terima kasih.";

        $waUrl = "https://wa.me/" . $waNumber . "?text=" . rawurlencode($waText);

        // Redirect Admin ke WA untuk kirim pesan ke mahasiswa
        header("Location: $waUrl");
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <div class="card-header bg-primary text-white">
        ✅ Persetujuan Aktivasi Akun
    </div>
    <div class="card-body">
        <?php if ($message): ?>
            <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
        <?php endif; ?>

        <?php if ($userData): ?>
            <p>Anda menerima permintaan aktivasi dari mahasiswa berikut:</p>
            
            <table class="table table-bordered mt-3">
                <tr>
                    <th style="width: 150px;">NIM / Username</th>
                    <td><strong><?= sanitize($userData['nim'] ?? $userData['username']) ?></strong></td>
                </tr>
                <tr>
                    <th>Nama Lengkap</th>
                    <td><?= sanitize($userData['nama_lengkap']) ?></td>
                </tr>
                <tr>
                    <th>Program Studi</th>
                    <td><?= sanitize($userData['program_studi']) ?: '-' ?></td>
                </tr>
                <tr>
                    <th>No. WhatsApp</th>
                    <td><?= sanitize($userData['no_hp']) ?: '-' ?></td>
                </tr>
                <tr>
                    <th>Status Saat Ini</th>
                    <td>
                        <?php if ($userData['is_active'] == 1): ?>
                            <span class="badge badge-success">Sudah Aktif</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Belum Aktif</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <form method="POST" class="text-center mt-4" data-no-spa>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="activate_account">
                
                <p>Klik tombol di bawah ini untuk mengaktifkan akun dan mengirimkan pesan konfirmasi ke WhatsApp mahasiswa.</p>
                <button type="submit" class="btn btn-primary" style="font-size: 1.1em; padding: 10px 20px;">
                    ✅ Aktifkan Akun & Beritahu Mahasiswa
                </button>
            </form>
        <?php else: ?>
            <div class="text-center mt-4">
                <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-secondary">Kembali ke Daftar Pengguna</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
