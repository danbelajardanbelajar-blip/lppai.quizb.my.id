<?php
/**
 * LPPAI Corner - Aktivasi Akun Mahasiswa
 */
define('PAGE_TITLE', 'Aktivasi Akun');
require_once __DIR__ . '/includes/auth.php';

// Pastikan user sudah login
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Jika bukan mahasiswa atau sudah aktif, kembalikan ke dashboard
if ($_SESSION['role'] !== 'mahasiswa' || (isset($_SESSION['is_active']) && $_SESSION['is_active'] == 1)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$pdo = getDBConnection();
$message = '';
$msgType = '';
$user = getCurrentUser();
$userId = $user['id'];

// Ambil data terbaru dari database
$noHp = '';
try {
    $stmt = $pdo->prepare("SELECT no_hp FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch();
    $noHp = $userData['no_hp'] ?? '';
} catch (PDOException $e) {
    // Abaikan error jika tabel belum dimigrasi
    $message = "Sistem sedang dalam perbaikan (menunggu admin melakukan migrasi). Harap tunggu.";
    $msgType = "warning";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf($_POST['csrf_token'])) {
        $message = 'Sesi tidak valid. Silakan coba lagi.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        if ($action === 'update_nohp') {
            $newNoHp = trim($_POST['no_hp']);
            if (empty($newNoHp)) {
                $message = 'Nomor HP tidak boleh kosong.';
                $msgType = 'danger';
            } else {
                // Pastikan nomor HP formatnya benar (contoh: +62xxx atau 08xxx)
                $pdo->prepare("UPDATE users SET no_hp = ? WHERE id = ?")->execute([$newNoHp, $userId]);
                $noHp = $newNoHp;
                $message = 'Nomor HP berhasil diperbarui.';
                $msgType = 'success';
            }
        } elseif ($action === 'request_activation') {
            if (empty($noHp)) {
                $message = 'Harap isi Nomor HP / WhatsApp Anda terlebih dahulu sebelum meminta aktivasi.';
                $msgType = 'danger';
            } else {
                // Generate token
                $token = bin2hex(random_bytes(32));
                try {
                    $pdo->prepare("UPDATE users SET activation_token = ? WHERE id = ?")->execute([$token, $userId]);
                } catch (PDOException $e) {
                    $message = 'Error: Migrasi database belum dijalankan oleh Admin. Fitur aktivasi belum siap.';
                    $msgType = 'danger';
                    // Stop eksekusi agar tidak redirect WA jika database gagal
                    include __DIR__ . '/includes/header.php';
                    echo "<div class='container mt-5'><div class='alert alert-danger'>$message</div></div>";
                    include __DIR__ . '/includes/footer.php';
                    exit;
                }
                
                // Siapkan pesan WhatsApp
                $adminWa = '6281515726827';
                $activationLink = 'https://lppai.quizb.my.id/admin/aktivasi-mahasiswa.php?token=' . $token;
                
                $waText = "Halo Ibu Umami,\n\n";
                $waText .= "Saya mahasiswa meminta persetujuan aktivasi akun LPPAI Corner.\n";
                $waText .= "NIM: " . $user['nim'] . "\n";
                $waText .= "Nama: " . $user['nama_lengkap'] . "\n";
                $waText .= "No. HP: " . $noHp . "\n\n";
                $waText .= "Berikut adalah link aktivasi akun saya (Hanya dapat diakses oleh Admin):\n";
                $waText .= $activationLink;

                $waUrl = "https://wa.me/" . $adminWa . "?text=" . rawurlencode($waText);

                // Redirect ke WhatsApp
                echo "<script>window.location.href = '$waUrl';</script>";
                exit;
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <div class="card-header bg-warning" style="background-color: #ffc107; color: #000;">
        ⚠️ Akun Belum Aktif
    </div>
    <div class="card-body">
        <?php if ($message): ?>
            <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
        <?php endif; ?>

        <p>Halo <strong><?= sanitize($user['nama_lengkap']) ?></strong>,</p>
        <p>Akun Anda saat ini <strong>belum aktif</strong>. Untuk mengaktifkan akun, Anda perlu mengirimkan permintaan aktivasi kepada Admin (Ibu Umami) melalui WhatsApp.</p>
        
        <div style="background:#f8f9fa; border-left:4px solid #17a2b8; padding:15px; margin-bottom:20px;">
            <strong>Nomor WhatsApp Anda Saat Ini:</strong><br>
            <?php if (!empty($noHp)): ?>
                <span style="font-size: 1.2em; font-weight: bold; color: #28a745;"><?= sanitize($noHp) ?></span>
                <p style="margin-top: 10px; font-size: 0.9em; color: #6c757d;">Pastikan nomor di atas sudah benar agar Admin dapat membalas dan menginformasikan status aktivasi Anda.</p>
            <?php else: ?>
                <span style="color: #dc3545; font-weight: bold;">Belum ada nomor HP yang terdaftar.</span>
            <?php endif; ?>
        </div>

        <form method="POST" style="margin-bottom: 30px; border-bottom: 1px solid #eee; padding-bottom: 20px;">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_nohp">
            
            <div class="form-group">
                <label>Koreksi / Isi Nomor WhatsApp Anda:</label>
                <div style="display: flex; gap: 10px;">
                    <input type="text" name="no_hp" value="<?= sanitize($noHp) ?>" class="form-control" placeholder="Contoh: 08123456789" required>
                    <button type="submit" class="btn btn-secondary">Simpan Nomor</button>
                </div>
            </div>
        </form>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="request_activation">
            
            <div style="text-align: center;">
                <p style="margin-bottom: 15px;">Jika nomor sudah benar, klik tombol di bawah ini untuk menghasilkan pesan WhatsApp secara otomatis ke Admin.</p>
                <button type="submit" class="btn btn-primary" style="font-size: 1.1em; padding: 12px 24px; background-color: #25D366; border-color: #25D366;" <?= empty($noHp) ? 'disabled' : '' ?>>
                    💬 Minta Aktivasi via WhatsApp
                </button>
                <?php if (empty($noHp)): ?>
                    <p style="color: #dc3545; font-size: 0.8em; margin-top: 8px;">Silakan isi dan simpan nomor WhatsApp terlebih dahulu.</p>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
