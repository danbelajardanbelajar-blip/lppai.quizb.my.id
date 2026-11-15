<?php
/**
 * LPPAI Corner - Halaman Profil Pengguna (Semua Role)
 */
define('PAGE_TITLE', 'Profil Saya');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/user-exam-photo.php';

$currentUser = getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
$pdo = getDBConnection();
ensureUserPhotoColumn($pdo);

$message = '';
$msgType = 'info';

// Ambil data fresh pengguna dari database
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$userData) {
    $userData = $currentUser;
}

// ── Handle Form Submissions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!verifyCsrf($token)) {
        $message = 'Token keamanan (CSRF) tidak valid atau sesi telah kedaluwarsa. Silakan muat ulang halaman.';
        $msgType = 'danger';
    } elseif ($action === 'upload_photo') {
        $photoData = is_string($_POST['foto_data'] ?? null) ? $_POST['foto_data'] : '';
        if ($photoData === '' && (!isset($_FILES['foto_profil']) || empty($_FILES['foto_profil']['name']))) {
            $message = 'Silakan pilih file foto terlebih dahulu sebelum menekan tombol unggah.';
            $msgType = 'warning';
        } else {
            $res = saveVerifiedUserPhoto($pdo, $userId, $_FILES['foto_profil'] ?? [], $photoData);
            if ($res['success']) {
                $message = 'Foto profil lolos pemeriksaan dan disimpan sebagai acuan verifikasi pretes serta tryout.';
                $msgType = 'success';
                // Refresh data
                $stmt->execute([$userId]);
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $message = $res['error'] ?? 'Gagal mengunggah foto profil.';
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'delete_photo') {
        if (deleteUserPhoto($userId, $pdo)) {
            invalidateUserPhotoVerification();
            $message = 'Foto profil berhasil dihapus. Tampilan avatar dikembalikan ke inisial nama.';
            $msgType = 'success';
            // Refresh data
            $stmt->execute([$userId]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $message = 'Gagal menghapus foto profil.';
            $msgType = 'danger';
        }
    } elseif ($action === 'update_profile') {
        $nama = trim($_POST['nama_lengkap'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $noHp = formatNoHp(trim($_POST['no_hp'] ?? ''));
        $tempatLahir = trim($_POST['tempat_lahir'] ?? '');
        $tanggalLahir = trim($_POST['tanggal_lahir'] ?? '');

        if ($nama === '') {
            $message = 'Nama lengkap tidak boleh kosong.';
            $msgType = 'danger';
        } else {
            try {
                $stmtUpdate = $pdo->prepare("
                    UPDATE users 
                    SET nama_lengkap = ?, email = ?, no_hp = ?, tempat_lahir = ?, tanggal_lahir = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([
                    $nama,
                    $email !== '' ? $email : null,
                    $noHp !== '' ? $noHp : null,
                    $tempatLahir !== '' ? $tempatLahir : null,
                    $tanggalLahir !== '' ? $tanggalLahir : null,
                    $userId
                ]);

                $_SESSION['nama_lengkap'] = $nama;
                $message = 'Data profil Anda berhasil disimpan dan diperbarui!';
                $msgType = 'success';

                // Refresh data
                $stmt->execute([$userId]);
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $message = 'Terjadi kesalahan saat menyimpan data profil: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

// Cek URL avatar aktif
$userPhotoUrl = getUserAvatarUrl($userData, $pdo);
$userInitial = strtoupper(substr($userData['nama_lengkap'] ?? 'U', 0, 1));
$roleLabel = ($userData['role'] ?? '') === 'admin' ? 'Admin' : (($userData['role'] ?? '') === 'dosen' ? 'Dosen / Tutor' : 'Mahasiswa');
$isMahasiswa = ($userData['role'] ?? '') === 'mahasiswa';

include __DIR__ . '/../includes/header.php';
?>

<div class="profile-page-wrapper" style="max-width: 1100px; margin: 0 auto;">
    <!-- Breadcrumb & Top Bar Info -->
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:24px;">
        <div>
            <div style="font-size:12px; color:var(--text-muted); font-weight:600; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                <a href="<?= BASE_URL ?>/dashboard.php" style="color:var(--text-muted); text-decoration:none;">Dashboard</a>
                <span>&rsaquo;</span>
                <span style="color:var(--primary); font-weight:700;">Profil Saya</span>
            </div>
            <h1 style="font-size:24px; font-weight:800; color:var(--primary-dark); margin:0; letter-spacing:-0.4px;">
                Profil Pengguna
            </h1>
            <p style="margin:4px 0 0; font-size:13.5px; color:var(--text-muted);">
                Kelola informasi pribadi, kontak, dan pasfoto profil akun Anda.
            </p>
        </div>
        <div style="display:flex; gap:10px;">
            <a href="<?= BASE_URL ?>/pengaturan.php" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; padding:9px 16px; border-radius:10px;">
                <span>⚙️</span> Pengaturan Akun
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $msgType ?> keep-alert" style="margin-bottom:24px; display:flex; align-items:center; gap:10px; border-radius:12px; font-size:13.5px;">
            <span><?= $msgType === 'success' ? '✅' : ($msgType === 'danger' ? '❌' : 'ℹ️') ?></span>
            <div><?= sanitize($message) ?></div>
        </div>
    <?php endif; ?>

    <style>.profile-page-wrapper [hidden]{display:none!important;}@media(max-width:800px){.profile-page-wrapper .profile-page-grid{grid-template-columns:minmax(0,1fr)!important;}}</style>
    <div class="profile-page-grid" style="display:grid; grid-template-columns:minmax(0,340px) minmax(0,1fr); gap:24px; align-items:start;">
        <!-- KOLOM KIRI: Foto & Ringkasan Akun -->
        <div style="display:flex; flex-direction:column; gap:24px;">
            <!-- Card Foto Profil -->
            <div class="card" style="box-shadow: 0 4px 18px rgba(11, 59, 34, 0.07); border-radius:16px; overflow:hidden;">
                <div class="card-header" style="background:#f8fafc; font-weight:700; border-bottom:1px solid #e2e8f0; padding:14px 20px;">
                    📷 Foto Profil
                </div>
                <div class="card-body" style="padding:24px; text-align:center;">
                    <div style="position:relative; width:150px; height:200px; margin:0 auto 16px;">
                        <div id="profileAvatarPreview" style="width:100%; height:100%; border-radius:24px; overflow:hidden; background:linear-gradient(135deg, var(--primary), var(--primary-light)); display:flex; align-items:center; justify-content:center; color:#ffffff; font-size:48px; font-weight:800; box-shadow:0 0 0 4px #ffffff, 0 0 0 6px rgba(16, 185, 129, 0.25), var(--shadow);">
                            <?php if (!empty($userPhotoUrl)): ?>
                                <img src="<?= sanitize($userPhotoUrl) ?>" alt="<?= sanitize($userData['nama_lengkap']) ?>" style="width:100%; height:100%; object-fit:cover; display:block;">
                            <?php else: ?>
                                <span><?= $userInitial ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <h3 style="margin:0 0 4px; font-size:16px; font-weight:800; color:var(--primary-dark);">
                        <?= sanitize($userData['nama_lengkap']) ?>
                    </h3>
                    <div style="display:flex; justify-content:center; gap:6px; margin-bottom:16px; flex-wrap:wrap;">
                        <span class="role-badge" style="font-size:11px;"><?= $roleLabel ?></span>
                        <?php if ($isMahasiswa && !empty($userData['jenis_program'])): ?>
                            <?= renderJenisProgramBadge($userData['jenis_program'], true) ?>
                        <?php endif; ?>
                    </div>

                    <form method="POST" enctype="multipart/form-data" id="formUploadPhoto" style="margin-top:16px;">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="upload_photo">
                        <input type="hidden" name="foto_data" id="profilePhotoData" value="">
                        <p style="text-align:left;font-size:13px;line-height:1.6;margin-bottom:12px;">Gunakan foto terbaru perorangan, berpakaian rapi, wajah menghadap depan, kedua mata terbuka, tanpa masker atau kacamata hitam. Foto harus terang, tajam, dan wajah tidak terpotong. Foto ini menjadi acuan verifikasi ujian.</p>

                        <div style="margin-bottom:12px;">
                            <label for="foto_profil" style="display:block; text-align:left; font-size:12px; font-weight:700; color:var(--text-dark); margin-bottom:6px;">
                                Pilih Foto Baru (JPG, PNG, WEBP, maks 5MB):
                            </label>
                            <input type="file" name="foto_profil" id="foto_profil" accept="image/jpeg,image/png,image/webp" required
                                style="width:100%; padding:8px 10px; font-size:12px; border:1px dashed #cbd5e1; border-radius:10px; background:#f8fafc; cursor:pointer;"
                                >
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
                            <button type="button" class="btn btn-secondary" id="profilePhotoCamera">Ambil dari Kamera</button>
                            <button type="button" class="btn btn-secondary" id="profilePhotoCapture" hidden>Ambil Foto</button>
                            <button type="button" class="btn btn-secondary" id="profilePhotoStop" hidden>Tutup Kamera</button>
                        </div>
                        <video id="profilePhotoVideo" autoplay playsinline muted hidden style="width:100%;max-height:300px;object-fit:cover;transform:scaleX(-1);border-radius:10px;"></video>
                        <p id="profilePhotoStatus" role="status" aria-live="polite" style="text-align:left;font-size:13px;line-height:1.6;">Pilih foto atau gunakan kamera. Foto akan dipotong 3:4 dan diperiksa sebelum disimpan.</p>

                        <div style="display:flex; gap:8px; justify-content:center; flex-wrap:wrap;">
                            <button type="submit" id="profilePhotoSubmit" disabled class="btn btn-primary" style="flex:1; min-width:120px; font-size:12.5px; padding:9px 14px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; gap:6px;">
                                <span>📤</span> Unggah Foto
                            </button>
                        </div>
                    </form>

                    <?php if (!empty($userPhotoUrl)): ?>
                        <form method="POST" style="margin-top:10px;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus foto profil ini dan kembali ke tampilan inisial?');">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="delete_photo">
                            <button type="submit" class="btn" style="width:100%; font-size:12px; padding:7px 12px; background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; border-radius:10px; cursor:pointer;">
                                🗑️ Hapus Foto Profil
                            </button>
                        </form>
                    <?php endif; ?>

                    <div style="margin-top:14px; padding:10px; background:#f8fafc; border-radius:10px; font-size:11.5px; color:var(--text-muted); text-align:left; line-height:1.4;">
                        💡 <strong>Catatan:</strong> Foto yang lolos pemeriksaan dipakai pada avatar dan sebagai acuan verifikasi pretes serta tryout. Ukuran minimal 240 × 320 piksel; maksimal 12 megapiksel sebelum diproses. Mengganti foto mengharuskan verifikasi wajah ulang.
                    </div>
                </div>
            </div>

            <!-- Card Informasi Akun Singkat -->
            <div class="card" style="box-shadow: 0 4px 18px rgba(11, 59, 34, 0.07); border-radius:16px;">
                <div class="card-header" style="background:#f8fafc; font-weight:700; border-bottom:1px solid #e2e8f0; padding:14px 20px;">
                    🛡️ Status Akun
                </div>
                <div class="card-body" style="padding:16px 20px; font-size:13px;">
                    <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f1f5f9;">
                        <span style="color:var(--text-muted);">Status</span>
                        <span style="color:#15803d; font-weight:700; display:inline-flex; align-items:center; gap:5px;">
                            <span style="width:7px; height:7px; border-radius:50%; background:#10b981; display:inline-block;"></span> Aktif
                        </span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f1f5f9;">
                        <span style="color:var(--text-muted);">ID Pengguna</span>
                        <span style="font-family:monospace; font-weight:700; color:var(--text-dark);">#<?= (int)$userData['id'] ?></span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f1f5f9;">
                        <span style="color:var(--text-muted);"><?= $isMahasiswa ? 'NIM' : 'Username' ?></span>
                        <span style="font-weight:700; color:var(--primary);"><?= sanitize($userData['nim'] ?: $userData['username']) ?></span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:8px 0;">
                        <span style="color:var(--text-muted);">Sesi Login</span>
                        <span style="color:var(--text-dark); font-weight:600;">Sedang Aktif</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- KOLOM KANAN: Formulir Data Diri -->
        <div class="card" style="box-shadow: 0 4px 18px rgba(11, 59, 34, 0.07); border-radius:16px;">
            <div class="card-header" style="background:#f8fafc; font-weight:700; border-bottom:1px solid #e2e8f0; padding:16px 24px; display:flex; justify-content:space-between; align-items:center;">
                <span>📋 Data Pribadi & Kontak</span>
                <span style="font-size:12px; color:var(--text-muted); font-weight:normal;">Role: <strong><?= $roleLabel ?></strong></span>
            </div>
            <div class="card-body" style="padding:24px;">
                <form method="POST" id="formUpdateProfile">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:18px;">
                        <!-- Nama Lengkap -->
                        <div style="grid-column: 1 / -1;">
                            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--text-dark);">
                                Nama Lengkap <span style="color:#ef4444;">*</span>
                            </label>
                            <input type="text" name="nama_lengkap" class="form-control" required
                                value="<?= sanitize($userData['nama_lengkap'] ?? '') ?>"
                                style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #cbd5e1; font-size:14px;">
                        </div>

                        <!-- Username / NIM (Read-Only) -->
                        <div>
                            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--text-muted);">
                                <?= $isMahasiswa ? 'Nomor Induk Mahasiswa (NIM)' : 'Username Sistem' ?> (Terkunci)
                            </label>
                            <input type="text" class="form-control" readonly disabled
                                value="<?= sanitize($userData['nim'] ?: $userData['username']) ?>"
                                style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #e2e8f0; background:#f1f5f9; color:#64748b; font-size:14px; cursor:not-allowed;">
                        </div>

                        <!-- Role (Read-Only) -->
                        <div>
                            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--text-muted);">
                                Peran Akun (Role)
                            </label>
                            <input type="text" class="form-control" readonly disabled
                                value="<?= $roleLabel ?>"
                                style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #e2e8f0; background:#f1f5f9; color:#64748b; font-size:14px; cursor:not-allowed;">
                        </div>

                        <?php if ($isMahasiswa): ?>
                            <!-- Program Studi -->
                            <div>
                                <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--text-muted);">
                                    Program Studi / Jurusan
                                </label>
                                <input type="text" class="form-control" readonly disabled
                                    value="<?= sanitize($userData['program_studi'] ?? '-') ?>"
                                    style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #e2e8f0; background:#f1f5f9; color:#64748b; font-size:14px; cursor:not-allowed;">
                            </div>

                            <!-- Jenis Program & Angkatan -->
                            <div>
                                <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--text-muted);">
                                    Program & Angkatan
                                </label>
                                <div style="display:flex; align-items:center; gap:8px; padding:8px 12px; background:#f1f5f9; border-radius:10px; border:1px solid #e2e8f0;">
                                    <?= renderJenisProgramBadge($userData['jenis_program'] ?? 'Reguler', true) ?>
                                    <span style="font-size:13px; color:#475569; font-weight:600;">
                                        Angkatan <?= sanitize($userData['angkatan'] ?? '-') ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Nomor WhatsApp / HP -->
                        <div>
                            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--text-dark);">
                                Nomor WhatsApp / HP
                            </label>
                            <input type="text" name="no_hp" class="form-control" placeholder="Contoh: 08123456789"
                                value="<?= sanitize($userData['no_hp'] ?? '') ?>"
                                style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #cbd5e1; font-size:14px;">
                            <small style="color:var(--text-muted); font-size:11.5px;">Gunakan nomor aktif untuk koordinasi dan notifikasi.</small>
                        </div>

                        <!-- Email -->
                        <div>
                            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--text-dark);">
                                Alamat Email
                            </label>
                            <input type="email" name="email" class="form-control" placeholder="nama@email.com"
                                value="<?= sanitize($userData['email'] ?? '') ?>"
                                style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #cbd5e1; font-size:14px;">
                        </div>

                        <!-- Tempat Lahir -->
                        <div>
                            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--text-dark);">
                                Tempat Lahir
                            </label>
                            <input type="text" name="tempat_lahir" class="form-control" placeholder="Kota / Kabupaten"
                                value="<?= sanitize($userData['tempat_lahir'] ?? '') ?>"
                                style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #cbd5e1; font-size:14px;">
                        </div>

                        <!-- Tanggal Lahir -->
                        <div>
                            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--text-dark);">
                                Tanggal Lahir
                            </label>
                            <input type="date" name="tanggal_lahir" class="form-control"
                                value="<?= sanitize($userData['tanggal_lahir'] ?? '') ?>"
                                style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #cbd5e1; font-size:14px;">
                        </div>
                    </div>

                    <div style="margin-top:28px; padding-top:20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:12px;">
                        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary" style="padding:10px 18px; border-radius:10px; font-size:13.5px;">
                            Batal
                        </a>
                        <button type="submit" class="btn btn-primary" style="padding:10px 22px; border-radius:10px; font-size:13.5px; font-weight:700; display:inline-flex; align-items:center; gap:8px;">
                            <span>💾</span> Simpan Perubahan Profil
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/pretes-face-verifier.js?v=<?= filemtime(__DIR__ . '/../assets/js/pretes-face-verifier.js') ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/profile-photo.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile-photo.js') ?>"></script>

<?php
include __DIR__ . '/../includes/footer.php';
