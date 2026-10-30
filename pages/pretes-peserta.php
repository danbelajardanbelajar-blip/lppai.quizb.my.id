<?php
/**
 * LPPAI Corner - Peserta & Jadwal Pretes (Mahasiswa)
 * Menampilkan jadwal pretes, daftar peserta, dan credentials tes tulis pribadi.
 */



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
     INFORMASI RESMI & PANDUAN PRETES MAHASISWA
═══════════════════════════════════════════════════════════════════ -->
<?php if ($myReg): ?>

    <!-- ── 1. KARTU UTAMA: JADWAL PRETES & KREDENSIAL AKUN CBT ── -->
    <div class="card" style="border:2px solid #0284c7;background:#ffffff;margin-bottom:20px;border-radius:16px;box-shadow:0 4px 18px rgba(2,132,199,0.08);overflow:hidden;box-sizing:border-box;">
        <!-- Header Kartu -->
        <div style="background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%);color:#ffffff;padding:16px 18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:22px;">🔑</span>
                <div>
                    <h3 style="margin:0;font-size:16px;font-weight:800;color:#ffffff;line-height:1.3;">
                        Jadwal & Akun Tes Pretes Anda
                    </h3>
                    <span style="font-size:12px;color:#e0f2fe;">
                        Periode: <strong><?= sanitize($myReg['periode']) ?></strong> | Status: <strong style="text-transform:capitalize;"><?= sanitize(str_replace('_', ' ', $myReg['status'] ?? 'Terdaftar')) ?></strong>
                    </span>
                </div>
            </div>
            <span style="background:rgba(255,255,255,0.2);color:#ffffff;padding:5px 12px;border-radius:20px;font-size:11.5px;font-weight:700;">
                Kode Sekolah: 22205
            </span>
        </div>

        <div class="card-body" style="padding:18px 16px;">
            
            <!-- Grid Ringkasan Jadwal Pribadi -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(min(100%, 140px), 1fr));gap:10px;margin-bottom:16px;">
                <!-- Hari -->
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;box-sizing:border-box;">
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">📅 Hari Ujian</div>
                    <div style="font-size:15px;font-weight:800;color:#0f172a;">
                        <?= sanitize($myReg['hari_pilihan'] ?: 'Belum ditentukan') ?>
                    </div>
                </div>

                <!-- Jam -->
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;box-sizing:border-box;">
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">⏰ Jam Pelaksanaan</div>
                    <div style="font-size:15px;font-weight:800;color:#0f172a;">
                        <?= sanitize($myReg['jam'] ?: '13.00 WIB (Jam 1)') ?>
                    </div>
                </div>

                <!-- Ruangan -->
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;box-sizing:border-box;">
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">🏢 Ruangan</div>
                    <div style="font-size:13.5px;font-weight:700;color:#0f172a;word-break:break-word;">
                        <?= sanitize($myReg['ruangan'] ?? ($myReg['ruangan_tes'] ?? 'Menunggu pembagian')) ?>
                    </div>
                </div>

                <!-- Dosen Penguji -->
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;box-sizing:border-box;">
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">👨‍🏫 Dosen Penguji</div>
                    <div style="font-size:13.5px;font-weight:700;color:#0f172a;word-break:break-word;">
                        <?= sanitize($myReg['dosen_penguji'] ?: 'Menunggu pembagian') ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($myReg['username_tes']) && !empty($myReg['password_tes'])): ?>
                <!-- Hidden base64 password -->
                <input type="hidden" id="__enc_pass" value="<?= base64_encode($myReg['password_tes'] ?? '') ?>">

                <!-- Kotak Kredensial CBT -->
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(min(100%, 250px), 1fr));gap:12px;margin-bottom:16px;">
                    <!-- Username Box -->
                    <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:12px;padding:16px;text-align:center;box-sizing:border-box;">
                        <span style="display:block;font-size:11.5px;font-weight:800;color:#0369a1;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px;">
                            USERNAME CBT
                        </span>
                        <div id="username-display" style="font-family:monospace;font-size:21px;font-weight:900;color:#0284c7;letter-spacing:1px;word-break:break-all;margin-bottom:10px;">
                            <?= sanitize($myReg['username_tes']) ?>
                        </div>
                        <button type="button" onclick="copyText('<?= sanitize($myReg['username_tes']) ?>', 'btn-copy-user')" id="btn-copy-user"
                            style="background:#0284c7;color:#fff;border:none;border-radius:8px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;">
                            📋 Salin Username
                        </button>
                    </div>

                    <!-- Password Box -->
                    <div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:12px;padding:16px;text-align:center;box-sizing:border-box;">
                        <span style="display:block;font-size:11.5px;font-weight:800;color:#c2410c;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px;">
                            PASSWORD / KODE AKSES
                        </span>
                        <div id="password-display" style="font-family:monospace;font-size:21px;font-weight:900;color:#ea580c;letter-spacing:4px;margin-bottom:10px;">
                            ••••••
                        </div>
                        <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                            <button type="button" onclick="togglePassword()" id="btn-toggle-pass"
                                style="background:#ea580c;color:#fff;border:none;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;">
                                👁️ Tampilkan
                            </button>
                            <button type="button" onclick="copyPassword('btn-copy-pass')" id="btn-copy-pass"
                                style="background:#c2410c;color:#fff;border:none;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;">
                                📋 Salin
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Peringatan Utama: Wajib Catat di Kertas (Satu tempat saja, tidak diulang) -->
                <div style="background:#fffbeb;border:1.5px solid #f59e0b;border-radius:12px;padding:14px 16px;display:flex;align-items:flex-start;gap:12px;box-sizing:border-box;">
                    <span style="font-size:24px;line-height:1;flex-shrink:0;">✍️</span>
                    <div style="font-size:13px;color:#92400e;line-height:1.55;">
                        <strong style="color:#b45309;display:block;margin-bottom:3px;">
                            Wajib Catat di Secarik Kertas Sekarang Sebelum Membuka Aplikasi!
                        </strong>
                        Aplikasi ujian mengunci layar HP secara penuh (<em>Kiosk Mode</em>), sehingga Anda <strong>tidak dapat berpindah ke web atau membuka catatan di HP</strong> saat ujian berlangsung.
                        Ambil secarik kertas dan pulpen, lalu catat:
                        <ul style="margin:6px 0 10px;padding-left:18px;">
                            <li>Kode Sekolah: <strong>22205</strong></li>
                            <li>Username: <strong><?= sanitize($myReg['username_tes']) ?></strong></li>
                            <li>Password: <strong><?= sanitize($myReg['password_tes']) ?></strong></li>
                        </ul>
                        <button type="button" onclick="copyText('22205', 'btn-copy-lbg-top')" id="btn-copy-lbg-top"
                            style="background:#f59e0b;color:#fff;border:none;border-radius:6px;padding:6px 12px;font-size:11.5px;font-weight:700;cursor:pointer;">
                            📋 Salin Kode Sekolah: 22205
                        </button>
                    </div>
                </div>

            <?php else: ?>
                <!-- Akun belum dikeluarkan TU -->
                <div style="background:#eff6ff;border:1.5px dashed #93c5fd;border-radius:12px;padding:16px;display:flex;align-items:center;gap:14px;box-sizing:border-box;">
                    <span style="font-size:32px;">⏳</span>
                    <div style="font-size:13px;color:#1e40af;line-height:1.5;">
                        <strong style="display:block;margin-bottom:2px;">Akun CBT Sedang Dipersiapkan</strong>
                        Anda sudah terdaftar pretes. Username dan password tes tulis sedang diproses oleh petugas TU dan akan dibagikan menjelang pelaksanaan pretes. Silakan cek kembali halaman ini secara berkala.
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- ── 2. PANDUAN PENGERJAAN UJIAN (PILIH SESUAI PERANGKAT) ── -->
    <div class="card" id="panduan-ujian" style="margin-bottom:20px;border:1.5px solid #cbd5e1;border-radius:16px;background:#ffffff;box-shadow:0 3px 12px rgba(0,0,0,0.03);overflow:hidden;box-sizing:border-box;">
        <div id="panduan-seb-iphone" style="background:#f8fafc;border-bottom:1.5px solid #e2e8f0;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="font-size:20px;">📱</span>
                <div>
                    <h4 style="margin:0;font-size:15px;font-weight:800;color:#0f172a;">Petunjuk Masuk & Mengerjakan Ujian</h4>
                    <span style="font-size:12px;color:#64748b;">Pilih jenis perangkat HP / laptop yang Anda gunakan:</span>
                </div>
            </div>

            <!-- Segmented Tabs (Touch-Friendly & Anti Geser Kanan-Kiri) -->
            <div style="display:flex;background:#e2e8f0;border-radius:10px;padding:3px;gap:4px;flex-wrap:wrap;width:100%;max-width:440px;box-sizing:border-box;">
                <button type="button" id="device-btn-android" onclick="switchDeviceTab('android')"
                    style="flex:1 1 120px;background:#0284c7;color:#fff;border:none;padding:8px 12px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;transition:all 0.2s;">
                    🤖 Android
                </button>
                <button type="button" id="device-btn-ios" onclick="switchDeviceTab('ios')"
                    style="flex:1 1 120px;background:#ffffff;color:#334155;border:none;padding:8px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;transition:all 0.2s;">
                    🍏 iPhone / iPad
                </button>
                <button type="button" id="device-btn-laptop" onclick="switchDeviceTab('laptop')"
                    style="flex:1 1 100px;background:#ffffff;color:#334155;border:none;padding:8px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;transition:all 0.2s;">
                    💻 Laptop / PC
                </button>
            </div>
        </div>

        <div class="card-body" style="padding:18px 16px;">
            
            <!-- ── TAB KONTEN 1: ANDROID ── -->
            <div id="device-content-android">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;color:#0369a1;">
                    <span style="font-size:18px;">🤖</span>
                    <strong style="font-size:13.5px;">4 Langkah Ujian untuk Pengguna HP Android:</strong>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(min(100%, 220px), 1fr));gap:12px;">
                    <!-- Step 1 -->
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;display:flex;flex-direction:column;justify-content:space-between;box-sizing:border-box;">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0284c7;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;">1</span>
                                <strong style="font-size:13px;color:#0f172a;">Install E-Ujian Browser</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                Buka Google Play Store di HP Android Anda, pasang aplikasi resmi <strong>E-Ujian Browser</strong>.
                            </p>
                        </div>
                        <a href="https://play.google.com/store/apps/details?id=com.doovera.eujianbrowser" target="_blank" rel="noopener noreferrer"
                            style="background:#0284c7;color:#fff;padding:7px 12px;border-radius:6px;font-size:11.5px;font-weight:700;text-decoration:none;text-align:center;display:block;">
                            📥 Buka Play Store ↗
                        </a>
                    </div>

                    <!-- Step 2 -->
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;display:flex;flex-direction:column;justify-content:space-between;box-sizing:border-box;">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0284c7;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;">2</span>
                                <strong style="font-size:13px;color:#0f172a;">Masukkan Kode Sekolah</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                Buka aplikasi. Ketika diminta kode lembaga / ID sekolah, ketik: <strong>22205</strong>.
                            </p>
                        </div>
                        <div style="background:#e0f2fe;color:#0369a1;padding:6px 10px;border-radius:6px;font-size:12px;font-weight:700;text-align:center;">
                            Kode: 22205
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;display:flex;flex-direction:column;justify-content:space-between;box-sizing:border-box;">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0284c7;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;">3</span>
                                <strong style="font-size:13px;color:#0f172a;">Login dari Catatan Kertas</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                Masuk sebagai Siswa/Peserta. Ketikkan Username & Password sesuai catatan kertas Anda.
                            </p>
                        </div>
                        <div style="background:#fef3c7;color:#92400e;padding:6px 10px;border-radius:6px;font-size:11px;font-weight:600;text-align:center;">
                            📝 Ketik dari catatan kertas
                        </div>
                    </div>

                    <!-- Step 4 -->
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;display:flex;flex-direction:column;justify-content:space-between;box-sizing:border-box;">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0284c7;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;">4</span>
                                <strong style="font-size:13px;color:#0f172a;">Mulai Ujian</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                Pilih sesi Pretes LPPAI, masukkan Token dari pengawas ruangan, lalu kerjakan hingga selesai.
                            </p>
                        </div>
                        <div style="background:#dcfce7;color:#15803d;padding:6px 10px;border-radius:6px;font-size:11px;font-weight:700;text-align:center;">
                            ✅ Jawaban tersimpan otomatis
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── TAB KONTEN 2: IPHONE / IPAD (iOS) ── -->
            <div id="device-content-ios" style="display:none;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;color:#0f766e;">
                    <span style="font-size:18px;">🍏</span>
                    <strong style="font-size:13.5px;">Langkah Ujian Khusus Pengguna iPhone (iOS):</strong>
                </div>

                <div style="background:#f0fdfa;border:1.5px solid #99f6e4;border-radius:12px;padding:12px 14px;margin-bottom:12px;font-size:12px;color:#134e4a;line-height:1.55;">
                    💡 <strong>Catatan Khusus iPhone:</strong> Safe Exam Browser di iPhone memerlukan file konfigurasi resmi (<code>seb-config-lppai.seb</code>) agar browser otomatis mengunci layar dan menampilkan ujian.
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(min(100%, 220px), 1fr));gap:12px;">
                    <!-- Step 1 iOS -->
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;display:flex;flex-direction:column;justify-content:space-between;box-sizing:border-box;">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0f766e;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;">1</span>
                                <strong style="font-size:13px;color:#0f172a;">Install Safe Exam Browser</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                Buka App Store di iPhone, pasang aplikasi <strong>Safe Exam Browser</strong>.
                            </p>
                        </div>
                        <a href="https://apps.apple.com/id/app/safeexambrowser/id1155002964?l=id" target="_blank" rel="noopener noreferrer"
                            style="background:#0f766e;color:#fff;padding:7px 12px;border-radius:6px;font-size:11.5px;font-weight:700;text-decoration:none;text-align:center;display:block;">
                            🍏 Buka App Store ↗
                        </a>
                    </div>

                    <!-- Step 2 iOS -->
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;display:flex;flex-direction:column;justify-content:space-between;box-sizing:border-box;">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0f766e;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;">2</span>
                                <strong style="font-size:13px;color:#0f172a;">Pasang Konfigurasi Ujian</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                Buka web ini lewat Safari iPhone, klik tombol di bawah untuk otomatis membuka SEB:
                            </p>
                        </div>
                        <div style="display:flex;gap:6px;flex-direction:column;">
                            <a href="sebs://<?= preg_replace('#^https?://#', '', BASE_URL) ?>/assets/downloads/seb-config-lppai.seb"
                                style="background:#0f766e;color:#fff;padding:7px 10px;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;text-align:center;">
                                ⚡ Buka Langsung di SEB (Safari)
                            </a>
                            <a href="<?= BASE_URL ?>/assets/downloads/seb-config-lppai.seb" download="seb-config-lppai.seb"
                                style="background:#f0fdfa;color:#0f766e;border:1px solid #99f6e4;padding:6px 8px;border-radius:6px;font-size:10.5px;font-weight:600;text-decoration:none;text-align:center;">
                                📥 Atau Unduh File .seb
                            </a>
                        </div>
                    </div>

                    <!-- Step 3 iOS -->
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;display:flex;flex-direction:column;justify-content:space-between;box-sizing:border-box;">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0f766e;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;">3</span>
                                <strong style="font-size:13px;color:#0f172a;">Login dari Catatan Kertas</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                SEB otomatis mengunci layar. Ketik Username & Password dari catatan kertas Anda.
                            </p>
                        </div>
                        <div style="background:#fef3c7;color:#92400e;padding:6px 10px;border-radius:6px;font-size:11px;font-weight:600;text-align:center;">
                            📝 Ketik dari catatan kertas
                        </div>
                    </div>

                    <!-- Step 4 iOS -->
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;display:flex;flex-direction:column;justify-content:space-between;box-sizing:border-box;">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0f766e;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;">4</span>
                                <strong style="font-size:13px;color:#0f172a;">Mulai Ujian</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                Masukkan Token dari Pengawas dan kerjakan soal sampai selesai.
                            </p>
                        </div>
                        <div style="background:#dcfce7;color:#15803d;padding:6px 10px;border-radius:6px;font-size:11px;font-weight:700;text-align:center;">
                            ✅ Kiosk mode aktif
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── TAB KONTEN 3: LAPTOP / PC ── -->
            <div id="device-content-laptop" style="display:none;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;color:#334155;">
                    <span style="font-size:18px;">💻</span>
                    <strong style="font-size:13.5px;">Ujian Menggunakan Laptop / PC:</strong>
                </div>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;font-size:12.5px;color:#334155;line-height:1.6;box-sizing:border-box;">
                    <ul style="margin:0;padding-left:18px;">
                        <li>Ujian di PC laboratorium kampus menggunakan aplikasi <strong>Safe Exam Browser (SEB)</strong> yang telah disiapkan panitia.</li>
                        <li>Pastikan terhubung ke jaringan resmi yang ditentukan panitia pretes.</li>
                        <li>Gunakan Username, Password, dan Token Ujian yang sama untuk memulai tes.</li>
                    </ul>
                </div>
            </div>

        </div>
    </div>

    <!-- ── 3. ATURAN PENTING & TATA TERTIB PRETES ── -->
    <div class="card" style="margin-bottom:22px;border:1.5px solid #e2e8f0;border-radius:16px;background:#ffffff;box-shadow:0 3px 12px rgba(0,0,0,0.03);overflow:hidden;box-sizing:border-box;">
        <div style="background:#fef2f2;border-bottom:1px solid #fecaca;padding:12px 18px;display:flex;align-items:center;gap:8px;">
            <span style="font-size:18px;">⚖️</span>
            <strong style="font-size:13.5px;color:#991b1b;">Tata Tertib & Ketentuan Wajib Pretes</strong>
        </div>
        <div class="card-body" style="padding:16px;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(min(100%, 250px), 1fr));gap:12px;">
                
                <!-- Poin 1: Try Out Mandiri -->
                <div style="background:#fefce8;border:1px solid #fde047;border-radius:12px;padding:14px;box-sizing:border-box;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <span style="font-size:18px;">🏠</span>
                        <strong style="font-size:13px;color:#854d0e;">1. Try Out Mandiri dari Rumah</strong>
                    </div>
                    <div style="font-size:12px;color:#713f12;line-height:1.55;">
                        Manfaatkan waktu <strong>malam ini s.d. malam Senin</strong> untuk mencoba login simulasi dari rumah dengan Kode Sekolah <code>22205</code> dan akun Anda, agar terbiasa dengan aplikasi.
                    </div>
                </div>

                <!-- Poin 2: Larangan 2 HP -->
                <div style="background:#fff1f2;border:1px solid #fecdd3;border-radius:12px;padding:14px;box-sizing:border-box;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <span style="font-size:18px;">⛔</span>
                        <strong style="font-size:13px;color:#9f1239;">2. Dilarang 2 HP di Meja Ujian!</strong>
                    </div>
                    <div style="font-size:12px;color:#9f1239;line-height:1.55;">
                        Saat ujian resmi di kampus, <strong>dilarang meletakkan 2 HP di atas meja</strong>. Pelanggaran berakibat <strong>akun dinonaktifkan otomatis oleh Pengawas (gugur)</strong>. HP cadangan wajib dimatikan & ditaruh di tas di depan kelas.
                    </div>
                </div>

                <!-- Poin 3: Hadir Tepat Waktu -->
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px;box-sizing:border-box;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <span style="font-size:18px;">⏰</span>
                        <strong style="font-size:13px;color:#166534;">3. Hadir 15 Menit Lebih Awal</strong>
                    </div>
                    <div style="font-size:12px;color:#166534;line-height:1.55;">
                        Hadir di ruangan ujian 15 menit sebelum waktu tes. Jika koneksi terputus atau baterai habis saat ujian, jawaban tersimpan aman di server; segera hidupkan HP atau lapor pengawas di ruangan.
                    </div>
                </div>

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

<script>
function togglePassword() {
    const display = document.getElementById('password-display');
    const btn = document.getElementById('btn-toggle-pass');
    const encInput = document.getElementById('__enc_pass');
    if (!display || !encInput) return;

    if (display.getAttribute('data-shown') === '1') {
        display.textContent = '••••••';
        display.style.letterSpacing = '6px';
        display.setAttribute('data-shown', '0');
        if (btn) btn.textContent = '👁️ Tampilkan';
    } else {
        const plain = atob(encInput.value);
        display.textContent = plain;
        display.style.letterSpacing = 'normal';
        display.setAttribute('data-shown', '1');
        if (btn) btn.textContent = '🙈 Sembunyikan';
    }
}

function copyPassword(btnId) {
    const encInput = document.getElementById('__enc_pass');
    if (!encInput) return;
    const plain = atob(encInput.value);

    const display = document.getElementById('password-display');
    const btn = document.getElementById('btn-toggle-pass');
    if (display && display.getAttribute('data-shown') !== '1') {
        display.textContent = plain;
        display.style.letterSpacing = 'normal';
        display.setAttribute('data-shown', '1');
        if (btn) btn.textContent = '🙈 Sembunyikan';
    }

    copyText(plain, btnId);
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



function switchDeviceTab(device) {
    var tabs = ['android', 'ios', 'laptop'];
    tabs.forEach(function(d) {
        var content = document.getElementById('device-content-' + d);
        var btn = document.getElementById('device-btn-' + d);
        if (d === device) {
            if (content) content.style.display = 'block';
            if (btn) {
                btn.style.background = '#0284c7';
                btn.style.color = '#ffffff';
                btn.style.fontWeight = '700';
            }
        } else {
            if (content) content.style.display = 'none';
            if (btn) {
                btn.style.background = '#ffffff';
                btn.style.color = '#334155';
                btn.style.fontWeight = '600';
            }
        }
    });
    try { sessionStorage.setItem('pretes_device_tab', device); } catch(e) {}
}

// Inisialisasi tab perangkat
try {
    var savedDev = sessionStorage.getItem('pretes_device_tab');
    if (!savedDev) {
        var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        if (isIOS) savedDev = 'ios';
    }
    if (window.location.hash === '#panduan-seb-iphone' || savedDev === 'ios') {
        switchDeviceTab('ios');
    } else if (savedDev === 'laptop') {
        switchDeviceTab('laptop');
    } else {
        switchDeviceTab('android');
    }
} catch(e) {}

</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
