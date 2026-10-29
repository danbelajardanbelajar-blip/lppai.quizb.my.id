<?php
/**
 * LPPAI Corner - Peserta & Jadwal Pretes (Mahasiswa)
 * Menampilkan jadwal pretes, daftar peserta, dan credentials tes tulis pribadi.
 */

// ── Handler AJAX: verifikasi password (harus di paling atas, sebelum HTML) ──
if (isset($_POST['action']) && $_POST['action'] === 'verify_password') {
    require_once __DIR__ . '/../includes/auth.php';
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $ok  = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    $tok = $_POST['csrf_token'] ?? '';
    $validTok = isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $tok);

    if (!$ok)       { echo json_encode(['ok'=>false,'message'=>'Sesi habis. Muat ulang halaman.']); exit; }
    if (!$validTok) { echo json_encode(['ok'=>false,'message'=>'Token tidak valid. Muat ulang halaman.']); exit; }

    $pw = $_POST['password'] ?? '';
    if ($pw === '') { echo json_encode(['ok'=>false,'message'=>'Password tidak boleh kosong.']); exit; }

    try {
        $pdo2 = getDBConnection();
        $s = $pdo2->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $s->execute([$_SESSION['user_id']]);
        $u = $s->fetch();
        if ($u && password_verify($pw, $u['password'])) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok'=>false,'message'=>'Password salah. Coba lagi.']);
        }
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'message'=>'Kesalahan server.']);
    }
    exit;
}

define('PAGE_TITLE', 'Peserta & Jadwal Pretes');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user = getCurrentUser();
$pdo  = getDBConnection();
require_once __DIR__ . '/../includes/sync_eujian.php';
checkAutoSyncEujian($pdo);

// ── Ambil credentials & registrasi mahasiswa ini ──────────────────────────────
$stmtMyReg = $pdo->prepare("
    SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan as ruangan_tes
    FROM pretes_registrations pr
    LEFT JOIN pretes_schedules ps ON pr.periode = ps.periode AND ps.status = 'aktif'
    WHERE pr.user_id = ?
    ORDER BY pr.tanggal_daftar DESC
    LIMIT 1
");
$stmtMyReg->execute([$user['id']]);
$myReg = $stmtMyReg->fetch();

// ── Ambil semua jadwal pretes ──────────────────────────────────────────────────
$schedules = $pdo->query(
    "SELECT * FROM pretes_schedules WHERE (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%') ORDER BY tanggal ASC, waktu_mulai ASC"
)->fetchAll();

// ── Ambil daftar semua peserta (nama + nim saja, untuk informasi publik) ────────
$participants = $pdo->query("
    SELECT pr.id, pr.periode, pr.hari_pilihan, pr.jam, pr.tanggal_daftar, pr.status,
           u.nama_lengkap, u.nim, u.program_studi
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    ORDER BY u.nama_lengkap ASC
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- ═══════════════════════════════════════════════════════════════════
     PANEL CREDENTIALS TES TULIS — ditampilkan paling atas & menonjol
═══════════════════════════════════════════════════════════════════ -->
<?php if ($myReg): ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         PENGUMUMAN PENTING & ATURAN WAJIB PRETES CBT (E-UJIAN)
    ═══════════════════════════════════════════════════════════════════ -->
    <div class="card" style="border:2px solid #dc2626;background:#ffffff;margin-bottom:24px;border-radius:16px;box-shadow:0 4px 20px rgba(220,38,38,0.08);overflow:hidden;">
        <div style="background:linear-gradient(135deg, #991b1b 0%, #dc2626 100%);color:#ffffff;padding:16px 22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,0.2);font-size:20px;">
                    🚨
                </span>
                <div>
                    <h3 style="margin:0;font-size:16px;font-weight:800;color:#ffffff;letter-spacing:0.3px;">
                        PENGUMUMAN & ATURAN WAJIB TES TULIS PRETES (CBT E-UJIAN)
                    </h3>
                    <span style="font-size:12px;color:#fecaca;">Harap dibaca, dipersiapkan, dan dipatuhi oleh seluruh mahasiswa peserta pretes</span>
                </div>
            </div>
            <span style="background:rgba(255,255,255,0.22);color:#ffffff;padding:5px 14px;border-radius:20px;font-size:11.5px;font-weight:800;letter-spacing:0.5px;">
                WAJIB DIPATUHI
            </span>
        </div>

        <div class="card-body" style="padding:22px;">
            <!-- Grid 4 Poin Utama -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:16px;">
                <!-- Poin 1: Wajib Install Exam Browser -->
                <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:16px;display:flex;flex-direction:column;justify-content:space-between;">
                    <div>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <span style="font-size:22px;">📱</span>
                            <strong style="color:#1e40af;font-size:13.5px;">1. Wajib Install Exam Browser</strong>
                        </div>
                        <p style="font-size:12.5px;color:#334155;line-height:1.55;margin:0 0 10px;">
                            Peserta <strong>wajib menginstall salah satu dari dua aplikasi</strong> berikut pada HP / perangkat ujian Anda:
                        </p>
                        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:10px;">
                            <!-- Android -->
                            <div style="background:#ffffff;border:1px solid #bfdbfe;padding:9px 12px;border-radius:8px;display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                <div style="font-size:12px;color:#1e293b;">
                                    🤖 <strong>Android:</strong> E-Ujian Browser
                                </div>
                                <a href="https://play.google.com/store/apps/details?id=com.doovera.eujianbrowser" target="_blank" rel="noopener noreferrer"
                                   style="background:#0284c7;color:#ffffff;padding:5px 11px;border-radius:6px;font-size:11.5px;font-weight:700;text-decoration:none;white-space:nowrap;">
                                    Google Play ↗
                                </a>
                            </div>
                            <!-- iPhone / iOS -->
                            <div style="background:#ffffff;border:1px solid #bfdbfe;padding:9px 12px;border-radius:8px;display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                <div style="font-size:12px;color:#1e293b;">
                                    🍏 <strong>iPhone/iPad:</strong> Safe Exam Browser
                                </div>
                                <a href="https://apps.apple.com/id/app/safeexambrowser/id1155002964?l=id" target="_blank" rel="noopener noreferrer"
                                   style="background:#0f766e;color:#ffffff;padding:5px 11px;border-radius:6px;font-size:11.5px;font-weight:700;text-decoration:none;white-space:nowrap;">
                                    App Store ↗
                                </a>
                            </div>
                        </div>
                    </div>
                    <div style="font-size:11.5px;color:#64748b;line-height:1.4;">
                        *(Pengguna PC / Laptop Windows & Mac juga menggunakan <strong>Safe Exam Browser</strong>)*
                    </div>
                </div>

                <!-- Poin 2: Kode Lembaga -->
                <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:12px;padding:16px;display:flex;flex-direction:column;justify-content:space-between;">
                    <div>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <span style="font-size:22px;">🏫</span>
                            <strong style="color:#166534;font-size:13.5px;">2. Kode Lembaga / Sekolah</strong>
                        </div>
                        <p style="font-size:12.5px;color:#334155;line-height:1.55;margin:0 0 10px;">
                            Saat pertama kali membuka aplikasi E-Ujian Browser / Safe Exam Browser, Anda diminta memasukkan Kode Lembaga:
                        </p>
                        <div style="background:#ffffff;border:2px dashed #16a34a;border-radius:10px;padding:12px;text-align:center;margin-bottom:8px;">
                            <span style="font-size:11px;font-weight:700;color:#16a34a;display:block;letter-spacing:1px;margin-bottom:2px;">KODE RESMI LEMBAGA</span>
                            <span style="font-family:monospace;font-size:30px;font-weight:900;color:#15803d;letter-spacing:4px;">
                                22205
                            </span>
                        </div>
                    </div>
                    <button type="button" onclick="copyText('22205', 'btn-copy-lembaga-box')" id="btn-copy-lembaga-box"
                            style="width:100%;background:#16a34a;color:#ffffff;border:none;border-radius:8px;padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer;transition:all 0.2s;">
                        📋 Salin Kode: 22205
                    </button>
                </div>

                <!-- Poin 3: Try Out Mandiri dari Rumah -->
                <div style="background:#fefce8;border:1.5px solid #fef08a;border-radius:12px;padding:16px;display:flex;flex-direction:column;justify-content:space-between;">
                    <div>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <span style="font-size:22px;">🏠</span>
                            <strong style="color:#854d0e;font-size:13.5px;">3. Try Out Mandiri dari Rumah</strong>
                        </div>
                        <div style="background:#ca8a04;color:#ffffff;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:800;display:inline-block;margin-bottom:8px;">
                            Malam Ini s.d. Malam Senin
                        </div>
                        <p style="font-size:12.5px;color:#334155;line-height:1.55;margin:0;">
                            Diadakan <strong>Try Out Mandiri dari rumah masing-masing</strong> mulai malam ini hingga malam Senin. Seluruh peserta diharapkan mencoba login dan simulasi pengerjaan agar familiar dengan aplikasi dan credentials Anda berjalan lancar.
                        </p>
                    </div>
                    <div style="margin-top:10px;background:#ffffff;border:1px solid #fef08a;padding:8px 10px;border-radius:6px;font-size:11.5px;color:#713f12;">
                        💡 Gunakan Username & Password tes Anda di bawah.
                    </div>
                </div>

                <!-- Poin 4: Larangan 2 HP di Meja Ujian -->
                <div style="background:#fff1f2;border:2px solid #fecdd3;border-radius:12px;padding:16px;display:flex;flex-direction:column;justify-content:space-between;">
                    <div>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <span style="font-size:22px;">⛔</span>
                            <strong style="color:#9f1239;font-size:13.5px;">4. Dilarang Membawa 2 HP di Meja!</strong>
                        </div>
                        <div style="background:#e11d48;color:#ffffff;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:800;display:inline-block;margin-bottom:8px;">
                            Ujian Resmi Senin s.d. Jumat di Kampus
                        </div>
                        <p style="font-size:12.5px;color:#334155;line-height:1.55;margin:0 0 10px;">
                            Pada hari pelaksanaan ujian di kampus, <strong>DILARANG membawa dua HP di meja ujian</strong>. Jika ketahuan membawa dua HP di mejanya, <strong>akun ujian akan langsung dimatikan otomatis oleh Pengawas Ruangan</strong> dan <strong>TIDAK BISA MENGERJAKAN KEMBALI</strong> (gugur/didiskualifikasi).
                        </p>
                    </div>
                    <div style="background:#ffffff;border:1px dashed #e11d48;padding:8px 10px;border-radius:8px;font-size:11.5px;color:#9f1239;line-height:1.45;">
                        🎒 <strong>Jika Membawa 2 HP:</strong> HP kedua wajib dinonaktifkan/silent, disimpan di dalam tas, dan <strong>tas WAJIB ditaruh di depan kelas / ruangan ujian</strong>.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($myReg['username_tes']) && !empty($myReg['password_tes'])): ?>
    <!-- Credentials sudah tersedia -->
    <div class="card" style="border:2px solid #1a73e8;margin-bottom:28px;position:relative;overflow:hidden;">
        <!-- Dekorasi background -->
        <div style="position:absolute;top:-30px;right:-30px;width:120px;height:120px;
                    background:rgba(26,115,232,0.08);border-radius:50%;pointer-events:none;"></div>
        <div style="position:absolute;bottom:-20px;right:60px;width:80px;height:80px;
                    background:rgba(26,115,232,0.05);border-radius:50%;pointer-events:none;"></div>

        <div class="card-header" style="background:#1a73e8;color:#fff;font-weight:700;font-size:15px;">
            🔑 Credentials Tes Tulis Pretes Anda
        </div>
        <div class="card-body">
            <!-- Hidden: password ter-encode base64, hanya ditampilkan setelah verifikasi -->
            <input type="hidden" id="__enc_pass" value="<?= base64_encode($myReg['password_tes'] ?? '') ?>">

            <div class="alert alert-info" style="margin-bottom:20px;">
                ⚠️ Gunakan username dan password di bawah ini untuk mengakses soal tes tulis pretes. Jangan bagikan ke orang lain.
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;margin-bottom:8px;">
                <!-- Username -->
                <div style="background:#e8f0fe;border-radius:14px;padding:20px 24px;text-align:center;">
                    <div style="color:#1a73e8;font-size:12px;font-weight:700;letter-spacing:1px;margin-bottom:8px;">USERNAME TES TULIS</div>
                    <div id="username-display"
                         style="font-family:monospace;font-size:22px;font-weight:800;color:#1565c0;
                                letter-spacing:2px;word-break:break-all;">
                        <?= sanitize($myReg['username_tes']) ?>
                    </div>
                    <button onclick="copyText('<?= sanitize($myReg['username_tes']) ?>', 'btn-copy-user')"
                            id="btn-copy-user"
                            style="margin-top:12px;background:#1a73e8;color:#fff;border:none;border-radius:8px;
                                   padding:6px 18px;cursor:pointer;font-size:13px;transition:all .2s;">
                        📋 Salin Username
                    </button>
                </div>

                <!-- Password -->
                <div style="background:#fff3e0;border-radius:14px;padding:20px 24px;text-align:center;">
                    <div style="color:#e65100;font-size:12px;font-weight:700;letter-spacing:1px;margin-bottom:8px;">PASSWORD TES TULIS</div>
                    <div id="password-display"
                         style="font-family:monospace;font-size:22px;font-weight:800;color:#bf360c;
                                letter-spacing:6px;">
                        ••••••
                    </div>
                    <div style="display:flex;gap:8px;justify-content:center;margin-top:12px;flex-wrap:wrap;">
                        <button onclick="askAndShowPassword()"
                                id="btn-toggle-pass"
                                style="background:#e65100;color:#fff;border:none;border-radius:8px;
                                       padding:6px 14px;cursor:pointer;font-size:13px;">
                            👁️ Tampilkan
                        </button>
                        <button onclick="copyPasswordIfVisible('btn-copy-pass')"
                                id="btn-copy-pass"
                                style="background:#bf360c;color:#fff;border:none;border-radius:8px;
                                       padding:6px 14px;cursor:pointer;font-size:13px;">
                            📋 Salin
                        </button>
                    </div>
                </div>
            </div>

            <!-- Info jadwal tes jika ada -->
            <?php if (!empty($myReg['hari_pilihan'])): ?>
            <div style="margin-top:16px;padding:14px 18px;background:#f8f9fa;border-radius:10px;
                        display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <span style="font-size:28px;">📅</span>
                <div>
                    <strong>Jadwal Pretes Anda:</strong>
                    Hari <strong><?= sanitize($myReg['hari_pilihan']) ?></strong>
                    | Jam: <strong><?= sanitize($myReg['jam'] ?? '13.00 WIB (Jam 1)') ?></strong>
                    | Penguji: <strong><?= sanitize($myReg['dosen_penguji'] ?? 'Menunggu pembagian') ?></strong>
                    | Ruangan: <strong><?= sanitize($myReg['ruangan'] ?? ($myReg['ruangan_tes'] ?? 'Menunggu pembagian')) ?></strong>
                </div>
            </div>
            <?php elseif ($myReg['tanggal']): ?>
            <div style="margin-top:16px;padding:14px 18px;background:#f8f9fa;border-radius:10px;
                        display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <span style="font-size:28px;">📅</span>
                <div>
                    <strong>Jadwal Tes Anda:</strong>
                    <?= date('l, d M Y', strtotime($myReg['tanggal'])) ?>
                    | <?= date('H:i', strtotime($myReg['waktu_mulai'])) ?> -
                      <?= date('H:i', strtotime($myReg['waktu_selesai'])) ?>
                    | <?= sanitize($myReg['ruangan_tes'] ?? '-') ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Akses Cepat Langsung ke E-Ujian -->
            <div style="margin-top:18px;padding:14px 18px;background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:24px;">🌐</span>
                    <div>
                        <strong style="color:#1e40af;font-size:14px;display:block;">Siap Mengerjakan Tes Tulis?</strong>
                        <span style="color:#3b82f6;font-size:12.5px;">Gunakan username & password di atas untuk login pada platform CBT <strong>E-Ujian</strong></span>
                    </div>
                </div>
                <a href="https://www.e-ujian.com/" target="_blank" rel="noopener noreferrer" class="btn"
                   style="background:#2563eb;color:#ffffff;border:none;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:8px;text-decoration:none;box-shadow:0 2px 6px rgba(37,99,235,0.25);">
                    🚀 Buka Website E-Ujian ↗
                </a>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- Terdaftar tapi credentials belum dikeluarkan -->
    <div class="card" style="border:2px dashed #e0e0e0;margin-bottom:28px;">
        <div class="card-header" style="background:#f5f5f5;color:#555;">
            🔑 Credentials Tes Tulis Pretes
        </div>
        <div class="card-body">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <div style="font-size:40px;">⏳</div>
                <div>
                    <h3 style="margin:0 0 6px;color:#555;">Credentials Belum Tersedia</h3>
                    <p style="color:var(--text-muted);margin:0;">
                        Anda sudah terdaftar pretes (periode: <strong><?= sanitize($myReg['periode']) ?></strong>).
                        Username dan password untuk tes tulis akan diberikan oleh TU menjelang hari pelaksanaan pretes.
                        Silakan cek kembali halaman ini secara berkala.
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         PANDUAN PENGGUNAAN E-UJIAN (www.e-ujian.com) BAGI PESERTA PRETES
    ═══════════════════════════════════════════════════════════════════ -->
    <div class="card" style="margin-bottom:28px;border:1.5px solid #cbd5e1;border-radius:16px;background:#ffffff;box-shadow:0 4px 16px rgba(0,0,0,0.04);overflow:hidden;">
        <div class="card-header" style="background:linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);color:#ffffff;padding:18px 22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,0.18);font-size:22px;">
                    📖
                </span>
                <div>
                    <h3 style="margin:0;font-size:16px;font-weight:800;color:#ffffff;letter-spacing:0.2px;">
                        Panduan Penggunaan Platform E-Ujian (CBT Tes Tulis Pretes)
                    </h3>
                    <p style="margin:2px 0 0;font-size:12.5px;color:#dbeafe;">
                        Tata cara resmi pengerjaan ujian berbasis komputer di <a href="https://www.e-ujian.com/" target="_blank" rel="noopener noreferrer" style="color:#ffffff;text-decoration:underline;font-weight:700;">www.e-ujian.com</a>
                    </p>
                </div>
            </div>
            <a href="https://www.e-ujian.com/" target="_blank" rel="noopener noreferrer"
               style="background:#ffffff;color:#1e3a8a;border:none;border-radius:8px;padding:8px 16px;font-size:12.5px;font-weight:700;display:inline-flex;align-items:center;gap:6px;text-decoration:none;box-shadow:0 2px 6px rgba(0,0,0,0.15);">
                🌐 Kunjungi e-ujian.com ↗
            </a>
        </div>

        <div class="card-body" style="padding:24px;">
            <!-- Info Pengantar -->
            <div style="background:#f8fafc;border-left:4px solid #2563eb;padding:14px 16px;border-radius:8px;margin-bottom:24px;">
                <p style="margin:0;font-size:13.5px;color:#334155;line-height:1.6;">
                    Ujian tulis pretes LPPAI dilaksanakan secara digital menggunakan platform <strong>E-Ujian (Computer Based Test)</strong>. 
                    Silakan ikuti petunjuk langkah demi langkah di bawah ini untuk memulai dan menyelesaikan ujian dengan lancar:
                </p>
            </div>

            <!-- Grid 6 Langkah Panduan -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:18px;margin-bottom:24px;">
                <!-- Langkah 1 -->
                <div style="background:#ffffff;border:1.5px solid #e2e8f0;border-radius:12px;padding:18px;position:relative;display:flex;flex-direction:column;justify-content:space-between;transition:all 0.2s;">
                    <div>
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#dbeafe;color:#1e40af;font-size:14px;font-weight:800;">
                                1
                            </span>
                            <strong style="font-size:14px;color:#0f172a;">Install Exam Browser Sesuai HP</strong>
                        </div>
                        <p style="font-size:13px;color:#64748b;line-height:1.55;margin:0 0 12px;">
                            Peserta <strong>wajib menginstall salah satu dari dua aplikasi</strong>: <strong>E-Ujian Browser</strong> untuk Android, atau <strong>Safe Exam Browser (SEB)</strong> untuk iPhone/iPad (iOS).
                        </p>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <a href="https://play.google.com/store/apps/details?id=com.doovera.eujianbrowser" target="_blank" rel="noopener noreferrer"
                           style="background:#0284c7;color:#fff;padding:5px 9px;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;">Play Store (Android) ↗</a>
                        <a href="https://apps.apple.com/id/app/safeexambrowser/id1155002964?l=id" target="_blank" rel="noopener noreferrer"
                           style="background:#0f766e;color:#fff;padding:5px 9px;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;">App Store (iOS) ↗</a>
                    </div>
                </div>

                <!-- Langkah 2 -->
                <div style="background:#ffffff;border:1.5px solid #e2e8f0;border-radius:12px;padding:18px;position:relative;display:flex-direction:column;justify-content:space-between;transition:all 0.2s;">
                    <div>
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#dbeafe;color:#1e40af;font-size:14px;font-weight:800;">
                                2
                            </span>
                            <strong style="font-size:14px;color:#0f172a;">Masukkan Kode Lembaga: 22205</strong>
                        </div>
                        <p style="font-size:13px;color:#64748b;line-height:1.55;margin:0 0 12px;">
                            Buka aplikasi ujian yang telah diinstall. Saat aplikasi meminta <strong>Kode Lembaga / ID Sekolah</strong>, ketik kode resmi: <strong>22205</strong>.
                        </p>
                    </div>
                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;padding:6px 10px;border-radius:8px;font-size:12px;color:#166534;font-weight:700;display:flex;justify-content:space-between;align-items:center;">
                        <span>Kode: <strong>22205</strong></span>
                        <button type="button" onclick="copyText('22205', 'btn-copy-lbg-step')" id="btn-copy-lbg-step" style="background:#16a34a;color:#fff;border:none;border-radius:4px;padding:3px 8px;font-size:10.5px;cursor:pointer;">Salin</button>
                    </div>
                </div>

                <!-- Langkah 3 -->
                <div style="background:#ffffff;border:1.5px solid #e2e8f0;border-radius:12px;padding:18px;position:relative;display:flex;flex-direction:column;justify-content:space-between;transition:all 0.2s;">
                    <div>
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#dbeafe;color:#1e40af;font-size:14px;font-weight:800;">
                                3
                            </span>
                            <strong style="font-size:14px;color:#0f172a;">Login Sebagai Peserta</strong>
                        </div>
                        <p style="font-size:13px;color:#64748b;line-height:1.55;margin:0 0 12px;">
                            Pilih menu <strong>Masuk / Login Siswa/Peserta</strong>. Masukkan Username dan Password yang telah Anda salin sebelumnya, lalu klik tombol <strong>Login / Masuk</strong>.
                        </p>
                    </div>
                    <div style="background:#f1f5f9;padding:8px 12px;border-radius:8px;font-size:12px;color:#475569;">
                        🔑 Pastikan huruf besar/kecil sesuai
                    </div>
                </div>

                <!-- Langkah 4 -->
                <div style="background:#ffffff;border:1.5px solid #e2e8f0;border-radius:12px;padding:18px;position:relative;display:flex;flex-direction:column;justify-content:space-between;transition:all 0.2s;">
                    <div>
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#dbeafe;color:#1e40af;font-size:14px;font-weight:800;">
                                4
                            </span>
                            <strong style="font-size:14px;color:#0f172a;">Pilih Ujian & Masukkan Token</strong>
                        </div>
                        <p style="font-size:13px;color:#64748b;line-height:1.55;margin:0 0 12px;">
                            Pada beranda dashboard peserta, buka menu <strong>Daftar Ujian</strong> / <strong>Ujian Aktif</strong>. Pilih sesi <strong>Pretes LPPAI</strong>. Jika diminta <strong>Token Ujian</strong>, mintalah kepada Pengawas Ruangan Anda.
                        </p>
                    </div>
                    <div style="background:#f1f5f9;padding:8px 12px;border-radius:8px;font-size:12px;color:#475569;">
                        🎟️ Token diumumkan pengawas saat jam tes
                    </div>
                </div>

                <!-- Langkah 5 -->
                <div style="background:#ffffff;border:1.5px solid #e2e8f0;border-radius:12px;padding:18px;position:relative;display:flex;flex-direction:column;justify-content:space-between;transition:all 0.2s;">
                    <div>
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#dbeafe;color:#1e40af;font-size:14px;font-weight:800;">
                                5
                            </span>
                            <strong style="font-size:14px;color:#0f172a;">Kerjakan Soal dengan Teliti</strong>
                        </div>
                        <p style="font-size:13px;color:#64748b;line-height:1.55;margin:0 0 12px;">
                            Klik <strong>Mulai Ujian</strong>. Jawab pertanyaan satu demi satu. Anda dapat berpindah nomor soal lewat nomor daftar soal, menandai ragu-ragu, dan memantau hitungan mundur sisa waktu ujian.
                        </p>
                    </div>
                    <div style="background:#f1f5f9;padding:8px 12px;border-radius:8px;font-size:12px;color:#475569;">
                        ⏱️ Jawaban tersimpan otomatis ke server
                    </div>
                </div>

                <!-- Langkah 6 -->
                <div style="background:#ffffff;border:1.5px solid #e2e8f0;border-radius:12px;padding:18px;position:relative;display:flex;flex-direction:column;justify-content:space-between;transition:all 0.2s;">
                    <div>
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#dbeafe;color:#1e40af;font-size:14px;font-weight:800;">
                                6
                            </span>
                            <strong style="font-size:14px;color:#0f172a;">Selesai & Kirim Jawaban</strong>
                        </div>
                        <p style="font-size:13px;color:#64748b;line-height:1.55;margin:0 0 12px;">
                            Setelah semua nomor soal terjawab dan diperiksa kembali, klik tombol <strong>Selesai Ujian</strong> pada soal terakhir. Beri centang pada kotak konfirmasi persetujuan, lalu klik <strong>Selesai</strong>.
                        </p>
                    </div>
                    <div style="background:#f1f5f9;padding:8px 12px;border-radius:8px;font-size:12px;color:#475569;">
                        ✅ Status ujian berubah menjadi "Selesai"
                    </div>
                </div>
            </div>

            <!-- Box Ketentuan Penting & FAQ -->
            <div style="background:#fffbeb;border:1.5px solid #fef3c7;border-radius:12px;padding:16px 20px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                    <span style="font-size:20px;">⚠️</span>
                    <strong style="font-size:14px;color:#92400e;">Hal-Hal Penting yang Wajib Diperhatikan Selama Tes:</strong>
                </div>
                <ul style="margin:0;padding-left:20px;font-size:13px;color:#78350f;line-height:1.7;">
                    <li><strong>Aturan Larangan 2 HP (Sanksi Diskualifikasi):</strong> Peserta dilarang keras membawa / meletakkan 2 HP di atas meja ujian. Jika ketahuan membawa 2 HP di meja saat ujian hari Senin–Jumat, akun ujian akan <strong>langsung dimatikan otomatis oleh Pengawas</strong> dan <strong>tidak bisa mengerjakan kembali</strong>. HP kedua wajib dimatikan dan dimasukkan ke tas, lalu tas diletakkan di depan kelas.</li>
                    <li><strong>Try Out Mandiri:</strong> Manfaatkan waktu <strong>malam ini s.d. malam Senin</strong> untuk mencoba ujian dari rumah menggunakan Kode Lembaga <code>22205</code> dan akun credentials Anda.</li>
                    <li><strong>Koneksi Terputus / Baterai Habis:</strong> Jika perangkat mendadak mati atau internet terputus, jawaban yang sudah Anda klik tetap tersimpan aman di server E-Ujian. Segera hidupkan perangkat, buka kembali E-Ujian, login, dan lanjutkan tes.</li>
                    <li><strong>Hadir Tepat Waktu:</strong> Hadirlah di ruangan ujian minimal 15 menit sebelum jam pelaksanaan pretes yang ditentukan pada jadwal Anda.</li>
                    <li><strong>Bantuan Pengawas:</strong> Apabila menemui kendala login atau token ujian tidak cocok, segera angkat tangan dan lapor kepada Pengawas Ruangan di lokasi.</li>
                </ul>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ── Jadwal Pretes ─────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">📅 Jadwal Pretes</div>
    <div class="card-body">
        <?php if (empty($schedules)): ?>
            <div class="empty-state">
                <div class="icon">📅</div>
                <h3>Jadwal belum tersedia</h3>
                <p>Jadwal pretes akan ditampilkan ketika TU sudah menginputkan jadwal.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Periode</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Ruangan</th>
                        <th>Kuota</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $i => $s): ?>
                    <tr <?= ($myReg && $myReg['periode'] === $s['periode']) ? 'style="background:#e8f0fe;"' : '' ?>>
                        <td><?= $i + 1 ?></td>
                        <td><?= sanitize($s['periode']) ?></td>
                        <td><?= date('d M Y', strtotime($s['tanggal'])) ?></td>
                        <td><?= date('H:i', strtotime($s['waktu_mulai'])) ?> - <?= date('H:i', strtotime($s['waktu_selesai'])) ?></td>
                        <td><?= sanitize($s['ruangan']) ?></td>
                        <td><?= $s['terisi'] ?>/<?= $s['kuota'] ?></td>
                        <td>
                            <?php
                            $statusMap = ['aktif' => 'badge-success', 'selesai' => 'badge-info', 'dibatalkan' => 'badge-danger'];
                            ?>
                            <span class="badge <?= $statusMap[$s['status']] ?? 'badge-info' ?>">
                                <?= ucfirst($s['status']) ?>
                            </span>
                            <?php if ($myReg && $myReg['periode'] === $s['periode']): ?>
                                <span class="badge badge-primary" style="margin-left:4px;">← Jadwal Anda</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Daftar Peserta (Publik) ───────────────────────────────────── -->
<div class="card">
    <div class="card-header">👥 Daftar Peserta Pretes (<?= count($participants) ?> peserta)</div>
    <div class="card-body">
        <?php if (empty($participants)): ?>
            <div class="empty-state">
                <div class="icon">👥</div>
                <h3>Belum ada peserta terdaftar</h3>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>NIM</th>
                        <th>Program Studi</th>
                        <th>Hari / Periode</th>
                        <th>Tgl Daftar</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($participants as $i => $p): ?>
                    <tr <?= ($p['nim'] === $user['nim']) ? 'style="background:#e8f0fe;font-weight:600;"' : '' ?>>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <?= sanitize($p['nama_lengkap']) ?>
                            <?php if ($p['nim'] === $user['nim']): ?>
                                <span class="badge badge-primary" style="font-size:10px;margin-left:4px;">Anda</span>
                            <?php endif; ?>
                        </td>
                        <td><?= sanitize($p['nim']) ?></td>
                        <td><?= sanitize($p['program_studi']) ?></td>
                        <td><?= sanitize(!empty($p['hari_pilihan']) ? ($p['hari_pilihan'] . ' (' . ($p['jam'] ?? '13.00 WIB') . ')') : $p['periode']) ?></td>
                        <td><?= date('d M Y', strtotime($p['tanggal_daftar'])) ?></td>
                        <td>
                            <?php
                            $statusBadge = ['terdaftar' => 'badge-info', 'hadir' => 'badge-success', 'tidak_hadir' => 'badge-danger'];
                            ?>
                            <span class="badge <?= $statusBadge[$p['status']] ?? 'badge-info' ?>">
                                <?= ucfirst(str_replace('_', ' ', $p['status'])) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Modal Konfirmasi Password ─────────────────────────── -->
<div id="modal-verify-pass"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;
            align-items:center;justify-content:center;backdrop-filter:blur(3px);">
    <div style="background:#fff;border-radius:16px;width:min(420px,92vw);box-shadow:0 20px 60px rgba(0,0,0,.25);
                animation:modalIn .2s ease;overflow:hidden;">
        <div style="background:#e65100;color:#fff;padding:18px 24px;
                    font-weight:700;font-size:16px;">🔐 Konfirmasi Password</div>
        <div style="padding:24px;">
            <p style="margin:0 0 16px;color:#555;font-size:14px;">
                Masukkan <strong>password akun</strong> Anda untuk melihat password tes tulis.
            </p>
            <div style="margin-bottom:12px;">
                <input type="password" id="verify-pass-input"
                    placeholder="Password akun Anda..."
                    style="width:100%;box-sizing:border-box;padding:11px 14px;
                           border:2px solid #e0e0e0;border-radius:10px;font-size:14px;
                           font-family:inherit;"
                    onkeydown="if(event.key==='Enter'){submitVerify();}"
                    autocomplete="current-password">
            </div>
            <div id="verify-pass-error"
                 style="display:none;color:#ef4444;font-size:13px;margin-bottom:12px;"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button onclick="closeVerifyModal()"
                    style="background:#f3f4f6;color:#555;border:none;border-radius:8px;
                           padding:8px 18px;cursor:pointer;font-size:13px;font-family:inherit;">
                    Batal
                </button>
                <button onclick="submitVerify()" id="verify-pass-btn"
                    style="background:#e65100;color:#fff;border:none;border-radius:8px;
                           padding:8px 20px;cursor:pointer;font-size:13px;font-weight:600;
                           font-family:inherit;">
                    🔓 Konfirmasi
                </button>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes modalIn {
    from { opacity:0; transform:translateY(-14px) scale(.97); }
    to   { opacity:1; transform:translateY(0) scale(1); }
}
</style>

<script>
var _revealedPassword = null; // disimpan setelah verifikasi berhasil

function askAndShowPassword() {
    const display = document.getElementById('password-display');
    // Jika sudah terlihat, sembunyikan kembali
    if (_revealedPassword !== null && display.textContent.trim() !== '••••••') {
        display.textContent = '••••••';
        display.style.letterSpacing = '6px';
        document.getElementById('btn-toggle-pass').textContent = '👁️ Tampilkan';
        return;
    }
    // Buka modal
    document.getElementById('verify-pass-input').value = '';
    document.getElementById('verify-pass-error').style.display = 'none';
    document.getElementById('modal-verify-pass').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('verify-pass-input').focus(), 100);
}

function closeVerifyModal() {
    document.getElementById('modal-verify-pass').style.display = 'none';
    document.body.style.overflow = '';
}

function submitVerify() {
    const pw  = document.getElementById('verify-pass-input').value;
    const btn = document.getElementById('verify-pass-btn');
    const err = document.getElementById('verify-pass-error');

    if (!pw) {
        err.textContent = 'Password tidak boleh kosong.';
        err.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.textContent = '⏳ Memverifikasi...';
    err.style.display = 'none';

    const fd = new FormData();
    fd.append('csrf_token', <?= json_encode(csrfToken()) ?>);
    fd.append('password', pw);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.text())
        .then(text => {
            let data;
            try { data = JSON.parse(text); }
            catch(e) {
                err.textContent = 'Respons server tidak valid: ' + text.substring(0, 120);
                err.style.display = 'block';
                btn.disabled = false;
                btn.textContent = '🔓 Konfirmasi';
                return;
            }
            if (data.ok) {
                const encoded = document.getElementById('__enc_pass').value;
                _revealedPassword = atob(encoded);

                const display = document.getElementById('password-display');
                display.textContent = _revealedPassword;
                display.style.letterSpacing = 'normal';
                document.getElementById('btn-toggle-pass').textContent = '🙈 Sembunyikan';
                closeVerifyModal();
            } else {
                err.textContent = data.message || 'Password salah.';
                err.style.display = 'block';
                btn.disabled = false;
                btn.textContent = '🔓 Konfirmasi';
                document.getElementById('verify-pass-input').select();
            }
        })
        .catch(e => {
            err.textContent = 'Koneksi gagal: ' + e.message;
            err.style.display = 'block';
            btn.disabled = false;
            btn.textContent = '🔓 Konfirmasi';
        });
}

function copyPasswordIfVisible(btnId) {
    if (_revealedPassword === null) {
        askAndShowPassword();
        return;
    }
    navigator.clipboard.writeText(_revealedPassword).then(() => {
        const btn = document.getElementById(btnId);
        if (btn) {
            const original = btn.textContent;
            btn.textContent = '✅ Tersalin!';
            btn.style.background = '#28a745';
            setTimeout(() => { btn.textContent = original; btn.style.background = ''; }, 2000);
        }
    }).catch(() => {
        const el = document.createElement('textarea');
        el.value = _revealedPassword;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    });
}

function copyText(text, btnId) {
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById(btnId);
        if (btn) {
            const original = btn.textContent;
            btn.textContent = '✅ Tersalin!';
            btn.style.background = '#28a745';
            setTimeout(() => { btn.textContent = original; btn.style.background = ''; }, 2000);
        }
    }).catch(() => {
        const el = document.createElement('textarea');
        el.value = text;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    });
}

// Tutup modal klik di luar
document.getElementById('modal-verify-pass').addEventListener('click', function(e) {
    if (e.target === this) closeVerifyModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeVerifyModal();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
