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
    } elseif ($action === 'save_ui_preferences') {
        $theme = (string)($_POST['theme'] ?? 'emerald');
        $fontFamily = (string)($_POST['font_family'] ?? 'plus-jakarta-sans');
        $fontSize = (string)($_POST['font_size'] ?? 'normal');
        $density = (string)($_POST['table_density'] ?? 'comfortable');
        $sidebar = (string)($_POST['sidebar_mode'] ?? 'default');

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_POST['is_ajax']) && $_POST['is_ajax'] === '1');

        $prefs = [
            'theme' => $theme,
            'font_family' => $fontFamily,
            'font_size' => $fontSize,
            'table_density' => $density,
            'sidebar_mode' => $sidebar
        ];

        $saved = function_exists('saveUserUiPreferences') ? saveUserUiPreferences($userId, $prefs, $pdo) : false;

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => $saved,
                'message' => $saved ? 'Preferensi tampilan berhasil disimpan ke akun Anda!' : 'Gagal menyimpan preferensi ke server.',
                'preferences' => function_exists('getUserUiPreferences') ? getUserUiPreferences($userId, $pdo) : $prefs
            ]);
            exit;
        }

        if ($saved) {
            $message = 'Preferensi tampilan dan antarmuka berhasil disimpan ke akun Anda!';
            $msgType = 'success';
        } else {
            $message = 'Gagal menyimpan preferensi tampilan ke server.';
            $msgType = 'danger';
        }
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
$userUiPrefs = function_exists('getUserUiPreferences') ? getUserUiPreferences($userId, $pdo) : [
    'theme' => 'emerald',
    'font_family' => 'plus-jakarta-sans',
    'font_size' => 'normal',
    'table_density' => 'comfortable',
    'sidebar_mode' => 'default'
];

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
        <!-- KOLOM UTAMA: Personalisasi Tampilan & Ganti Password -->
        <div style="display:flex; flex-direction:column; gap:24px;">

            <!-- KARTU 1: PERSONALISASI TAMPILAN & ANTARMUKA -->
            <div class="card" id="theme-settings" style="box-shadow: 0 4px 18px rgba(11, 59, 34, 0.07); border-radius:16px;">
                <div class="card-header" style="background:#f8fafc; font-weight:700; border-bottom:1px solid #e2e8f0; padding:16px 24px; display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span>🎨</span>
                        <span>Personalisasi Tampilan & Antarmuka</span>
                    </div>
                    <span class="badge badge-emerald" style="font-size:11px;">Tersimpan Multi-Perangkat</span>
                </div>
                <div class="card-body" style="padding:24px;">
                    <p style="font-size:13px; color:var(--text-muted); margin-top:0; margin-bottom:20px; line-height:1.5;">
                        Sesuaikan tema warna, gaya huruf, ukuran tulisan, dan kepadatan tata letak sesuai kenyamanan mata Anda. Pengaturan ini otomatis disinkronkan ke akun dan browser Anda.
                    </p>

                    <form method="POST" id="formUiPreferences" action="<?= BASE_URL ?>/pengaturan.php">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="save_ui_preferences">
                        <input type="hidden" name="is_ajax" id="uiIsAjax" value="0">

                        <!-- 1. TEMA WARNA -->
                        <div style="margin-bottom:24px;">
                            <label style="display:flex; align-items:center; justify-content:space-between; font-size:13px; font-weight:700; margin-bottom:10px; color:var(--text-dark);">
                                <span>1. Tema Warna Utama (Color Scheme)</span>
                                <span style="font-size:11.5px; font-weight:500; color:var(--text-muted);" id="activeThemeLabel">Emerald Royale</span>
                            </label>
                            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap:12px;">
                                <!-- Emerald Royale -->
                                <label class="ui-pref-card <?= ($userUiPrefs['theme'] === 'emerald') ? 'selected' : '' ?>" style="cursor:pointer; display:block; margin:0;">
                                    <input type="radio" name="theme" value="emerald" <?= ($userUiPrefs['theme'] === 'emerald') ? 'checked' : '' ?> style="display:none;">
                                    <div class="ui-pref-card-inner" style="border:2px solid <?= ($userUiPrefs['theme'] === 'emerald') ? 'var(--primary)' : '#e2e8f0' ?>; border-radius:12px; padding:14px; background:#ffffff; transition:0.2s;">
                                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                                            <div style="display:flex; gap:6px;">
                                                <span style="width:18px; height:18px; border-radius:50%; background:#0b3b22; display:inline-block; border:1px solid rgba(0,0,0,0.1);" title="Emerald Deep"></span>
                                                <span style="width:18px; height:18px; border-radius:50%; background:#c59b27; display:inline-block; border:1px solid rgba(0,0,0,0.1);" title="Champagne Gold"></span>
                                                <span style="width:18px; height:18px; border-radius:50%; background:#f8fafc; display:inline-block; border:1px solid #cbd5e1;" title="Light Canvas"></span>
                                            </div>
                                            <span class="ui-pref-check" style="font-size:13px;"><?= ($userUiPrefs['theme'] === 'emerald') ? '✅' : '⚪' ?></span>
                                        </div>
                                        <div style="font-weight:700; font-size:13px; color:#0f172a; margin-bottom:3px;">Emerald Royale</div>
                                        <div style="font-size:11px; color:#64748b; line-height:1.35;">Nuansa hijau elegan LPPAI & aksen champagne gold. (Bawaan)</div>
                                    </div>
                                </label>

                                <!-- Midnight Dark -->
                                <label class="ui-pref-card <?= ($userUiPrefs['theme'] === 'dark') ? 'selected' : '' ?>" style="cursor:pointer; display:block; margin:0;">
                                    <input type="radio" name="theme" value="dark" <?= ($userUiPrefs['theme'] === 'dark') ? 'checked' : '' ?> style="display:none;">
                                    <div class="ui-pref-card-inner" style="border:2px solid <?= ($userUiPrefs['theme'] === 'dark') ? 'var(--primary)' : '#e2e8f0' ?>; border-radius:12px; padding:14px; background:#131c31; color:#f1f5f9; transition:0.2s;">
                                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                                            <div style="display:flex; gap:6px;">
                                                <span style="width:18px; height:18px; border-radius:50%; background:#0b1120; display:inline-block; border:1px solid rgba(255,255,255,0.2);" title="Obsidian Canvas"></span>
                                                <span style="width:18px; height:18px; border-radius:50%; background:#10b981; display:inline-block; border:1px solid rgba(0,0,0,0.2);" title="Neon Emerald"></span>
                                                <span style="width:18px; height:18px; border-radius:50%; background:#fbbf24; display:inline-block; border:1px solid rgba(0,0,0,0.2);" title="Amber Light"></span>
                                            </div>
                                            <span class="ui-pref-check" style="font-size:13px;"><?= ($userUiPrefs['theme'] === 'dark') ? '✅' : '⚪' ?></span>
                                        </div>
                                        <div style="font-weight:700; font-size:13px; color:#f8fafc; margin-bottom:3px;">Midnight Dark</div>
                                        <div style="font-size:11px; color:#94a3b8; line-height:1.35;">Mode gelap kontras tinggi, nyaman di mata saat malam / ruangan redup.</div>
                                    </div>
                                </label>

                                <!-- Sapphire Academic -->
                                <label class="ui-pref-card <?= ($userUiPrefs['theme'] === 'sapphire') ? 'selected' : '' ?>" style="cursor:pointer; display:block; margin:0;">
                                    <input type="radio" name="theme" value="sapphire" <?= ($userUiPrefs['theme'] === 'sapphire') ? 'checked' : '' ?> style="display:none;">
                                    <div class="ui-pref-card-inner" style="border:2px solid <?= ($userUiPrefs['theme'] === 'sapphire') ? 'var(--primary)' : '#e2e8f0' ?>; border-radius:12px; padding:14px; background:#ffffff; transition:0.2s;">
                                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                                            <div style="display:flex; gap:6px;">
                                                <span style="width:18px; height:18px; border-radius:50%; background:#0f3d68; display:inline-block; border:1px solid rgba(0,0,0,0.1);" title="Sapphire Navy"></span>
                                                <span style="width:18px; height:18px; border-radius:50%; background:#e69d24; display:inline-block; border:1px solid rgba(0,0,0,0.1);" title="Warm Amber"></span>
                                                <span style="width:18px; height:18px; border-radius:50%; background:#eff6ff; display:inline-block; border:1px solid #bfdbfe;" title="Ice Blue Canvas"></span>
                                            </div>
                                            <span class="ui-pref-check" style="font-size:13px;"><?= ($userUiPrefs['theme'] === 'sapphire') ? '✅' : '⚪' ?></span>
                                        </div>
                                        <div style="font-weight:700; font-size:13px; color:#0f172a; margin-bottom:3px;">Sapphire Academic</div>
                                        <div style="font-size:11px; color:#64748b; line-height:1.35;">Biru samudra bernuansa akademis formal dipadu aksen emas hangat.</div>
                                    </div>
                                </label>

                                <!-- Warm Sepia -->
                                <label class="ui-pref-card <?= ($userUiPrefs['theme'] === 'sepia') ? 'selected' : '' ?>" style="cursor:pointer; display:block; margin:0;">
                                    <input type="radio" name="theme" value="sepia" <?= ($userUiPrefs['theme'] === 'sepia') ? 'checked' : '' ?> style="display:none;">
                                    <div class="ui-pref-card-inner" style="border:2px solid <?= ($userUiPrefs['theme'] === 'sepia') ? 'var(--primary)' : '#e2e8f0' ?>; border-radius:12px; padding:14px; background:#fbf8f3; transition:0.2s;">
                                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                                            <div style="display:flex; gap:6px;">
                                                <span style="width:18px; height:18px; border-radius:50%; background:#4a3424; display:inline-block; border:1px solid rgba(0,0,0,0.1);" title="Sepia Coffee"></span>
                                                <span style="width:18px; height:18px; border-radius:50%; background:#b47d34; display:inline-block; border:1px solid rgba(0,0,0,0.1);" title="Warm Wood"></span>
                                                <span style="width:18px; height:18px; border-radius:50%; background:#fbf8f3; display:inline-block; border:1px solid #e8decb;" title="Warm Paper"></span>
                                            </div>
                                            <span class="ui-pref-check" style="font-size:13px;"><?= ($userUiPrefs['theme'] === 'sepia') ? '✅' : '⚪' ?></span>
                                        </div>
                                        <div style="font-weight:700; font-size:13px; color:#2c2521; margin-bottom:3px;">Warm Sepia</div>
                                        <div style="font-size:11px; color:#786d65; line-height:1.35;">Kertas buku klasik bernuansa hangat, ramah dan santai untuk membaca panjang.</div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- 2. PILIHAN FONT & UKURAN -->
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:20px; margin-bottom:24px;">
                            <!-- Font Family -->
                            <div>
                                <label style="display:block; font-size:13px; font-weight:700; margin-bottom:8px; color:var(--text-dark);">
                                    2. Jenis Tulisan (Font Family)
                                </label>
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <label class="ui-font-option" style="cursor:pointer; display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid <?= ($userUiPrefs['font_family'] === 'plus-jakarta-sans') ? 'var(--primary)' : '#e2e8f0' ?>; border-radius:10px; background:var(--white);">
                                        <input type="radio" name="font_family" value="plus-jakarta-sans" <?= ($userUiPrefs['font_family'] === 'plus-jakarta-sans') ? 'checked' : '' ?>>
                                        <div style="flex:1;">
                                            <div style="font-family:'Plus Jakarta Sans', sans-serif; font-weight:700; font-size:13px; color:var(--text-dark);">Plus Jakarta Sans</div>
                                            <div style="font-size:11px; color:var(--text-muted);">Modern, geometris & berkelas (Bawaan)</div>
                                        </div>
                                    </label>
                                    <label class="ui-font-option" style="cursor:pointer; display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid <?= ($userUiPrefs['font_family'] === 'inter') ? 'var(--primary)' : '#e2e8f0' ?>; border-radius:10px; background:var(--white);">
                                        <input type="radio" name="font_family" value="inter" <?= ($userUiPrefs['font_family'] === 'inter') ? 'checked' : '' ?>>
                                        <div style="flex:1;">
                                            <div style="font-family:'Inter', sans-serif; font-weight:700; font-size:13px; color:var(--text-dark);">Inter</div>
                                            <div style="font-size:11px; color:var(--text-muted);">Standar UI global, bersih & tajam</div>
                                        </div>
                                    </label>
                                    <label class="ui-font-option" style="cursor:pointer; display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid <?= ($userUiPrefs['font_family'] === 'lexend') ? 'var(--primary)' : '#e2e8f0' ?>; border-radius:10px; background:var(--white);">
                                        <input type="radio" name="font_family" value="lexend" <?= ($userUiPrefs['font_family'] === 'lexend') ? 'checked' : '' ?>>
                                        <div style="flex:1;">
                                            <div style="font-family:'Lexend', sans-serif; font-weight:700; font-size:13px; color:var(--text-dark);">Lexend</div>
                                            <div style="font-size:11px; color:var(--text-muted);">Didesain khusus untuk kemudahan membaca</div>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Font Size Scaling -->
                            <div>
                                <label style="display:block; font-size:13px; font-weight:700; margin-bottom:8px; color:var(--text-dark);">
                                    3. Skala Ukuran Teks (Font Size)
                                </label>
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <label class="ui-size-option" style="cursor:pointer; display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid <?= ($userUiPrefs['font_size'] === 'normal') ? 'var(--primary)' : '#e2e8f0' ?>; border-radius:10px; background:var(--white);">
                                        <input type="radio" name="font_size" value="normal" <?= ($userUiPrefs['font_size'] === 'normal') ? 'checked' : '' ?>>
                                        <div style="flex:1;">
                                            <div style="font-weight:700; font-size:13px; color:var(--text-dark);">Standar (100%)</div>
                                            <div style="font-size:11px; color:var(--text-muted);">Ukuran asli yang seimbang untuk semua monitor</div>
                                        </div>
                                    </label>
                                    <label class="ui-size-option" style="cursor:pointer; display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid <?= ($userUiPrefs['font_size'] === 'medium') ? 'var(--primary)' : '#e2e8f0' ?>; border-radius:10px; background:var(--white);">
                                        <input type="radio" name="font_size" value="medium" <?= ($userUiPrefs['font_size'] === 'medium') ? 'checked' : '' ?>>
                                        <div style="flex:1;">
                                            <div style="font-weight:700; font-size:13.5px; color:var(--text-dark);">Sedang (110%)</div>
                                            <div style="font-size:11px; color:var(--text-muted);">Sedikit lebih besar (+10%), lebih santai di mata</div>
                                        </div>
                                    </label>
                                    <label class="ui-size-option" style="cursor:pointer; display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid <?= ($userUiPrefs['font_size'] === 'large') ? 'var(--primary)' : '#e2e8f0' ?>; border-radius:10px; background:var(--white);">
                                        <input type="radio" name="font_size" value="large" <?= ($userUiPrefs['font_size'] === 'large') ? 'checked' : '' ?>>
                                        <div style="flex:1;">
                                            <div style="font-weight:700; font-size:14px; color:var(--text-dark);">Besar (120%)</div>
                                            <div style="font-size:11px; color:var(--text-muted);">Ekstra besar (+20%), terbaca jelas tanpa zoom</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- 3. TATA LETAK & KEPADATAN -->
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:20px; margin-bottom:24px;">
                            <!-- Table Density -->
                            <div>
                                <label style="display:block; font-size:13px; font-weight:700; margin-bottom:8px; color:var(--text-dark);">
                                    4. Kepadatan Tabel & Data
                                </label>
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <label style="cursor:pointer; display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid <?= ($userUiPrefs['table_density'] === 'comfortable') ? 'var(--primary)' : '#e2e8f0' ?>; border-radius:10px; background:var(--white);">
                                        <input type="radio" name="table_density" value="comfortable" <?= ($userUiPrefs['table_density'] === 'comfortable') ? 'checked' : '' ?>>
                                        <div style="flex:1;">
                                            <div style="font-weight:700; font-size:13px; color:var(--text-dark);">Lega & Nyaman (Bawaan)</div>
                                            <div style="font-size:11px; color:var(--text-muted);">Spasi standar proporsional dan santai</div>
                                        </div>
                                    </label>
                                    <label style="cursor:pointer; display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid <?= ($userUiPrefs['table_density'] === 'compact') ? 'var(--primary)' : '#e2e8f0' ?>; border-radius:10px; background:var(--white);">
                                        <input type="radio" name="table_density" value="compact" <?= ($userUiPrefs['table_density'] === 'compact') ? 'checked' : '' ?>>
                                        <div style="flex:1;">
                                            <div style="font-weight:700; font-size:13px; color:var(--text-dark);">Ramping & Padat (Compact)</div>
                                            <div style="font-size:11px; color:var(--text-muted);">Spasi baris rapat, muat lebih banyak baris tabel</div>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Sidebar Mode -->
                            <div>
                                <label style="display:block; font-size:13px; font-weight:700; margin-bottom:8px; color:var(--text-dark);">
                                    5. Lebar Bilah Menu Samping (Sidebar)
                                </label>
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <label style="cursor:pointer; display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid <?= ($userUiPrefs['sidebar_mode'] === 'default') ? 'var(--primary)' : '#e2e8f0' ?>; border-radius:10px; background:var(--white);">
                                        <input type="radio" name="sidebar_mode" value="default" <?= ($userUiPrefs['sidebar_mode'] === 'default') ? 'checked' : '' ?>>
                                        <div style="flex:1;">
                                            <div style="font-weight:700; font-size:13px; color:var(--text-dark);">Standar 272px (Bawaan)</div>
                                            <div style="font-size:11px; color:var(--text-muted);">Format menu desktop standar yang luas</div>
                                        </div>
                                    </label>
                                    <label style="cursor:pointer; display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid <?= ($userUiPrefs['sidebar_mode'] === 'compact') ? 'var(--primary)' : '#e2e8f0' ?>; border-radius:10px; background:var(--white);">
                                        <input type="radio" name="sidebar_mode" value="compact" <?= ($userUiPrefs['sidebar_mode'] === 'compact') ? 'checked' : '' ?>>
                                        <div style="flex:1;">
                                            <div style="font-weight:700; font-size:13px; color:var(--text-dark);">Ramping 215px (Sleek)</div>
                                            <div style="font-size:11px; color:var(--text-muted);">Lebih hemat ruang horizontal layar kerja</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- 4. LIVE INTERACTIVE PREVIEW BOX -->
                        <div style="background:var(--bg-light); border:1px solid var(--border); border-radius:12px; padding:16px; margin-bottom:24px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <div style="font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:0.4px; color:var(--primary); display:flex; align-items:center; gap:6px;">
                                    <span>👁️</span> Pratinjau Tampilan Langsung
                                </div>
                                <span style="font-size:11px; color:var(--text-muted); font-weight:600;">Otomatis berubah seketika saat diklik</span>
                            </div>

                            <div id="uiPreviewBox" style="background:var(--white); border:1px solid var(--border-subtle); border-radius:10px; padding:16px; box-shadow:var(--shadow-sm);">
                                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-subtle); padding-bottom:10px; margin-bottom:12px;">
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:var(--primary);"></span>
                                        <strong style="font-size:13.5px; color:var(--text-dark);">LPPAI Corner CBT & Mahasiswa</strong>
                                    </div>
                                    <span class="badge badge-success" style="font-size:11px;">Status: Aktif</span>
                                </div>
                                <p style="font-size:13px; color:var(--text-muted); margin:0 0 12px; line-height:1.5;">
                                    "Menuntut ilmu adalah kewajiban bagi setiap muslim." Contoh teks antarmuka dengan gaya font dan tema pilihan Anda.
                                </p>
                                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-primary" style="padding:6px 14px; font-size:12px; border-radius:8px; pointer-events:none;">
                                        Tombol Utama
                                    </button>
                                    <span class="badge badge-emerald">LULUS PRETES</span>
                                    <span class="badge badge-gold">TUTORIAL B</span>
                                    <span style="font-size:11.5px; color:var(--text-muted); margin-left:auto;">
                                        NIM: <strong>2301010001</strong>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- TOMBOL AKSI -->
                        <div style="padding-top:16px; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                            <button type="button" id="btnResetUiPreferences" class="btn btn-secondary" style="padding:10px 18px; border-radius:10px; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
                                <span>🔄</span> Kembalikan ke Bawaan
                            </button>
                            <button type="submit" id="btnSaveUiPreferences" class="btn btn-primary" style="padding:10px 24px; border-radius:10px; font-size:13.5px; font-weight:700; display:inline-flex; align-items:center; gap:8px;">
                                <span id="saveBtnIcon">💾</span>
                                <span id="saveBtnText">Simpan Preferensi Tampilan</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- KARTU 2: GANTI PASSWORD -->
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
        </div><!-- END OF KOLOM UTAMA WRAPPER -->

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

<script>
(function() {
    var form = document.getElementById('formUiPreferences');
    if (!form) return;

    var themeInputs = form.querySelectorAll('input[name="theme"]');
    var fontInputs = form.querySelectorAll('input[name="font_family"]');
    var sizeInputs = form.querySelectorAll('input[name="font_size"]');
    var densityInputs = form.querySelectorAll('input[name="table_density"]');
    var sidebarInputs = form.querySelectorAll('input[name="sidebar_mode"]');
    var resetBtn = document.getElementById('btnResetUiPreferences');
    var saveBtn = document.getElementById('btnSaveUiPreferences');
    var saveBtnText = document.getElementById('saveBtnText');
    var saveBtnIcon = document.getElementById('saveBtnIcon');
    var themeLabel = document.getElementById('activeThemeLabel');

    var themeNames = {
        'emerald': 'Emerald Royale (Bawaan)',
        'dark': 'Midnight Dark (Mode Gelap)',
        'sapphire': 'Sapphire Academic',
        'sepia': 'Warm Sepia (Kertas Buku)'
    };

    function getSelected(inputs) {
        for (var i = 0; i < inputs.length; i++) {
            if (inputs[i].checked) return inputs[i].value;
        }
        return '';
    }

    function applyLivePreview() {
        var theme = getSelected(themeInputs) || 'emerald';
        var font = getSelected(fontInputs) || 'plus-jakarta-sans';
        var size = getSelected(sizeInputs) || 'normal';
        var density = getSelected(densityInputs) || 'comfortable';
        var sidebar = getSelected(sidebarInputs) || 'default';

        var doc = document.documentElement;
        doc.setAttribute('data-theme', theme);
        doc.setAttribute('data-font', font);
        doc.setAttribute('data-font-size', size);
        doc.setAttribute('data-density', density);
        doc.setAttribute('data-sidebar', sidebar);

        if (themeLabel && themeNames[theme]) {
            themeLabel.textContent = themeNames[theme];
        }

        // Update card borders and checks
        document.querySelectorAll('.ui-pref-card').forEach(function(card) {
            var inp = card.querySelector('input[type="radio"]');
            var inner = card.querySelector('.ui-pref-card-inner');
            var check = card.querySelector('.ui-pref-check');
            if (inp && inner) {
                if (inp.checked) {
                    card.classList.add('selected');
                    inner.style.borderColor = 'var(--primary)';
                    if (check) check.textContent = '✅';
                } else {
                    card.classList.remove('selected');
                    inner.style.borderColor = (inp.value === 'dark') ? '#334155' : '#e2e8f0';
                    if (check) check.textContent = '⚪';
                }
            }
        });

        // Update font option borders
        document.querySelectorAll('.ui-font-option').forEach(function(opt) {
            var inp = opt.querySelector('input[type="radio"]');
            if (inp) {
                opt.style.borderColor = inp.checked ? 'var(--primary)' : '#e2e8f0';
            }
        });

        // Update size option borders
        document.querySelectorAll('.ui-size-option').forEach(function(opt) {
            var inp = opt.querySelector('input[type="radio"]');
            if (inp) {
                opt.style.borderColor = inp.checked ? 'var(--primary)' : '#e2e8f0';
            }
        });
    }

    // Bind change events for immediate live preview
    [themeInputs, fontInputs, sizeInputs, densityInputs, sidebarInputs].forEach(function(group) {
        group.forEach(function(input) {
            input.addEventListener('change', function() {
                applyLivePreview();
            });
        });
    });

    // Reset button
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            if (!confirm('Kembalikan semua preferensi tampilan ke setelan bawaan sistem?')) return;
            form.querySelector('input[name="theme"][value="emerald"]').checked = true;
            form.querySelector('input[name="font_family"][value="plus-jakarta-sans"]').checked = true;
            form.querySelector('input[name="font_size"][value="normal"]').checked = true;
            form.querySelector('input[name="table_density"][value="comfortable"]').checked = true;
            form.querySelector('input[name="sidebar_mode"][value="default"]').checked = true;
            applyLivePreview();
            form.requestSubmit ? form.requestSubmit() : form.submit();
        });
    }

    // AJAX Form Submission
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(form);
        formData.set('is_ajax', '1');

        var theme = getSelected(themeInputs);
        var font = getSelected(fontInputs);
        var size = getSelected(sizeInputs);
        var density = getSelected(densityInputs);
        var sidebar = getSelected(sidebarInputs);

        try {
            var localPrefs = {
                theme: theme,
                font_family: font,
                font_size: size,
                table_density: density,
                sidebar_mode: sidebar
            };
            localStorage.setItem('lppai_ui_prefs', JSON.stringify(localPrefs));
        } catch (err) {}

        if (saveBtn) {
            saveBtn.disabled = true;
            if (saveBtnText) saveBtnText.textContent = 'Menyimpan...';
            if (saveBtnIcon) saveBtnIcon.textContent = '⏳';
        }

        fetch(form.action || window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function(res) {
            return res.json();
        }).then(function(data) {
            if (saveBtn) {
                saveBtn.disabled = false;
                if (saveBtnIcon) saveBtnIcon.textContent = '✅';
                if (saveBtnText) saveBtnText.textContent = 'Tersimpan!';
                setTimeout(function() {
                    if (saveBtnIcon) saveBtnIcon.textContent = '💾';
                    if (saveBtnText) saveBtnText.textContent = 'Simpan Preferensi Tampilan';
                }, 2500);
            }
            if (window.toast) {
                window.toast(data.message || 'Preferensi tampilan berhasil disimpan!', data.success ? 'success' : 'error');
            } else {
                alert(data.message || 'Preferensi tampilan berhasil disimpan!');
            }
        }).catch(function(err) {
            console.error('Save UI prefs failed, falling back to standard submit:', err);
            form.submit();
        });
    });

    applyLivePreview();
})();
</script>

<?php
include __DIR__ . '/../includes/footer.php';
