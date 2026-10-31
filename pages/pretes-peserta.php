<?php
/**
 * LPPAI Corner - Peserta & Jadwal Pretes (Mahasiswa)
 * Menampilkan jadwal pretes, kredensial tes tulis pribadi, dan petunjuk pelaksanaan ujian.
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

// ── Ambil jadwal pretes aktif untuk informasi sesi ─────────────────────────────
$schedules = $pdo->query(
    "SELECT * FROM pretes_schedules WHERE (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%') ORDER BY tanggal ASC, waktu_mulai ASC"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Layout & Responsif Khusus Halaman Pretes Mahasiswa ── */
.pretes-info-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 16px;
    width: 100%;
    box-sizing: border-box;
}

.pretes-cred-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 16px;
    width: 100%;
    box-sizing: border-box;
}

.pretes-steps-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    width: 100%;
    box-sizing: border-box;
}

.pretes-rules-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    width: 100%;
    box-sizing: border-box;
}

.pretes-info-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 14px;
    box-sizing: border-box;
    min-width: 0;
    overflow: hidden;
}

.pretes-step-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-sizing: border-box;
    min-width: 0;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}

@media (max-width: 960px) {
    .pretes-steps-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .pretes-rules-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .pretes-info-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 600px) {
    .pretes-cred-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 520px) {
    .pretes-steps-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .pretes-info-grid {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    .pretes-info-grid > div:nth-child(3),
    .pretes-info-grid > div:nth-child(4) {
        grid-column: span 2;
    }
    .pretes-info-box {
        padding: 10px 12px;
    }
}

@media (max-width: 360px) {
    .pretes-info-grid {
        grid-template-columns: 1fr;
    }
    .pretes-info-grid > div:nth-child(3),
    .pretes-info-grid > div:nth-child(4) {
        grid-column: span 1;
    }
}
</style>

<!-- ═══════════════════════════════════════════════════════════════════
     INFORMASI RESMI & PANDUAN PRETES MAHASISWA
═══════════════════════════════════════════════════════════════════ -->
<?php if ($myReg): ?>

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
        <div>
            <h2 class="page-title" style="margin:0;">Pretes Saya</h2>
            <p style="margin:4px 0 0;color:#64748b;font-size:13px;">Tutorial Ubudiyah Unisda (Pelaksanaan: 2 – 6 November 2026). Simpan kartu peserta dan periksa jadwal Anda.</p>
        </div>
        <a href="<?= BASE_URL ?>/cetak-kartu-pretes.php" target="_blank" rel="noopener" data-no-spa class="btn btn-primary" style="display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:11px 18px;border-radius:10px;font-weight:700;text-decoration:none;box-shadow:0 4px 12px rgba(2,132,199,0.16);" aria-label="Cetak kartu pretes (buka tab baru)">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9V3h12v6M6 18H3V9h18v9h-3M6 14h12v7H6z"/><path d="M17 12h1"/></svg>
            Cetak Kartu Pretes
        </a>
    </div>

    <!-- ── 1. KARTU UTAMA: JADWAL PRETES & KREDENSIAL AKUN CBT ── -->
    <div class="card" style="border:2px solid #0284c7;background:#ffffff;margin-bottom:20px;border-radius:16px;box-shadow:0 4px 18px rgba(2,132,199,0.08);overflow:hidden;box-sizing:border-box;max-width:100%;">
        <!-- Header Kartu -->
        <div style="background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%);color:#ffffff;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px 12px;box-sizing:border-box;max-width:100%;">
            <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                <span style="font-size:24px;flex-shrink:0;">🔑</span>
                <div style="min-width:0;">
                    <h3 style="margin:0;font-size:15px;font-weight:800;color:#ffffff;line-height:1.3;word-break:break-word;">
                        Jadwal & Akun Tes Pretes Anda
                    </h3>
                    <div style="font-size:12px;color:#e0f2fe;word-break:break-word;margin-top:2px;">
                        Periode: <strong><?= sanitize($myReg['periode']) ?> (2 – 6 Nov 2026)</strong> | Status: <strong style="text-transform:capitalize;"><?= sanitize(str_replace('_', ' ', $myReg['status'] ?? 'Terdaftar')) ?></strong>
                    </div>
                </div>
            </div>
            <span style="background:rgba(255,255,255,0.22);color:#ffffff;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap;letter-spacing:0.3px;">
                Kode Sekolah: 22205
            </span>
        </div>

        <div class="card-body" style="padding:16px 14px;box-sizing:border-box;max-width:100%;">
            
            <!-- Grid Ringkasan Jadwal Pribadi -->
            <div class="pretes-info-grid">
                <!-- Hari -->
                <div class="pretes-info-box">
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">📅 Hari Ujian</div>
                    <div style="font-size:15px;font-weight:800;color:#0f172a;word-break:break-word;">
                        <?php 
                        $dMap = ['senin' => 'Senin, 2 Nov 2026', 'selasa' => 'Selasa, 3 Nov 2026', 'rabu' => 'Rabu, 4 Nov 2026', 'kamis' => 'Kamis, 5 Nov 2026', 'jumat' => 'Jumat, 6 Nov 2026'];
                        $hClean = strtolower(trim((string)($myReg['hari_pilihan'] ?? '')));
                        echo sanitize($dMap[$hClean] ?? ($myReg['hari_pilihan'] ?: 'Belum ditentukan'));
                        ?>
                    </div>
                </div>

                <!-- Jam -->
                <div class="pretes-info-box">
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">⏰ Jam Pelaksanaan</div>
                    <div style="font-size:15px;font-weight:800;color:#0f172a;word-break:break-word;">
                        <?= sanitize($myReg['jam'] ?: '13.00 WIB (Jam 1)') ?>
                    </div>
                </div>

                <!-- Ruangan -->
                <div class="pretes-info-box">
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">🏢 Ruangan</div>
                    <div style="font-size:13.5px;font-weight:700;color:#0f172a;word-break:break-word;">
                        <?= sanitize($myReg['ruangan'] ?? ($myReg['ruangan_tes'] ?? 'Menunggu pembagian')) ?>
                    </div>
                </div>

                <!-- Dosen Penguji -->
                <div class="pretes-info-box">
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
                <div class="pretes-cred-grid">
                    <!-- Username Box -->
                    <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:12px;padding:16px 12px;text-align:center;box-sizing:border-box;min-width:0;">
                        <span style="display:block;font-size:11.5px;font-weight:800;color:#0369a1;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px;">
                            USERNAME CBT
                        </span>
                        <div id="username-display" style="font-family:monospace;font-size:20px;font-weight:900;color:#0284c7;letter-spacing:1px;word-break:break-all;margin-bottom:10px;">
                            <?= sanitize($myReg['username_tes']) ?>
                        </div>
                        <button type="button" onclick="copyText('<?= sanitize($myReg['username_tes']) ?>', 'btn-copy-user')" id="btn-copy-user"
                            style="background:#0284c7;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;transition:all 0.2s;max-width:100%;">
                            📋 Salin Username
                        </button>
                    </div>

                    <!-- Password Box -->
                    <div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:12px;padding:16px 12px;text-align:center;box-sizing:border-box;min-width:0;">
                        <span style="display:block;font-size:11.5px;font-weight:800;color:#c2410c;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px;">
                            PASSWORD / KODE AKSES
                        </span>
                        <div id="password-display" style="font-family:monospace;font-size:20px;font-weight:900;color:#ea580c;letter-spacing:4px;margin-bottom:10px;word-break:break-all;">
                            ••••••
                        </div>
                        <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                            <button type="button" onclick="togglePassword()" id="btn-toggle-pass"
                                style="background:#ea580c;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer;">
                                👁️ Tampilkan
                            </button>
                            <button type="button" onclick="copyPassword('btn-copy-pass')" id="btn-copy-pass"
                                style="background:#c2410c;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer;">
                                📋 Salin
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Peringatan Utama: Wajib Catat di Kertas (Satu tempat saja, tidak diulang) -->
                <div style="background:#fffbeb;border:1.5px solid #f59e0b;border-radius:12px;padding:14px;box-sizing:border-box;max-width:100%;margin-bottom:4px;">
                    <div style="display:flex;align-items:flex-start;gap:10px;">
                        <span style="font-size:24px;line-height:1;flex-shrink:0;">✍️</span>
                        <div style="font-size:13px;color:#92400e;line-height:1.55;min-width:0;word-break:break-word;">
                            <strong style="color:#b45309;display:block;margin-bottom:4px;">
                                Wajib Catat di Secarik Kertas atau Cetak Kartu Pretes Sekarang Sebelum Membuka Aplikasi!
                            </strong>
                            Aplikasi ujian mengunci layar HP secara penuh (<em>Kiosk Mode</em>), sehingga Anda <strong>tidak dapat membuka catatan di HP</strong> saat ujian berlangsung.
                            Ambil secarik kertas dan pulpen, lalu catat:
                            <ul style="margin:6px 0 10px;padding-left:18px;">
                                <li>Kode Sekolah: <strong>22205</strong></li>
                                <li>Username: <strong><?= sanitize($myReg['username_tes']) ?></strong></li>
                                <li>Password: <strong><?= sanitize($myReg['password_tes']) ?></strong></li>
                            </ul>
                            <button type="button" onclick="copyText('22205', 'btn-copy-lbg-top')" id="btn-copy-lbg-top"
                                style="background:#f59e0b;color:#fff;border:none;border-radius:6px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;">
                                📋 Salin Kode Sekolah: 22205
                            </button>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Akun belum dikeluarkan TU -->
                <div style="background:#eff6ff;border:1.5px dashed #93c5fd;border-radius:12px;padding:16px;display:flex;align-items:center;gap:14px;box-sizing:border-box;max-width:100%;">
                    <span style="font-size:32px;flex-shrink:0;">⏳</span>
                    <div style="font-size:13px;color:#1e40af;line-height:1.5;min-width:0;word-break:break-word;">
                        <strong style="display:block;margin-bottom:2px;">Akun CBT Sedang Dipersiapkan</strong>
                        Anda sudah terdaftar pretes. Username dan password tes tulis sedang diproses oleh petugas TU dan akan dibagikan menjelang pelaksanaan pretes. Silakan cek kembali halaman ini secara berkala.
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- ── 2. PANDUAN PENGERJAAN UJIAN (ATAS: ANDROID, BAWAH: IPHONE) ── -->
    <div class="card" id="panduan-ujian" style="margin-bottom:20px;border:1.5px solid #cbd5e1;border-radius:16px;background:#ffffff;box-shadow:0 3px 12px rgba(0,0,0,0.03);overflow:hidden;box-sizing:border-box;max-width:100%;">
        <div style="background:#f8fafc;border-bottom:1.5px solid #e2e8f0;padding:14px 18px;display:flex;align-items:center;gap:10px;box-sizing:border-box;">
            <span style="font-size:22px;flex-shrink:0;">📱</span>
            <div style="min-width:0;">
                <h4 style="margin:0;font-size:15px;font-weight:800;color:#0f172a;word-break:break-word;">Petunjuk Masuk & Mengerjakan Ujian</h4>
                <span style="font-size:12px;color:#64748b;">Ikuti panduan berikut sesuai tipe HP yang Anda gunakan untuk ujian</span>
            </div>
        </div>

        <div class="card-body" style="padding:18px 14px;box-sizing:border-box;max-width:100%;">
            
            <!-- ── BAGIAN 1: PENGGUNA HP ANDROID (ATAS) ── -->
            <div id="section-panduan-android" style="margin-bottom:28px;padding-bottom:24px;border-bottom:2px dashed #e2e8f0;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:10px 14px;background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;">
                    <span style="font-size:22px;flex-shrink:0;">🤖</span>
                    <div style="min-width:0;">
                        <strong style="font-size:14px;color:#0369a1;display:block;word-break:break-word;">1. Khusus Pengguna HP Android (E-Ujian Browser)</strong>
                        <span style="font-size:12px;color:#0284c7;">Wajib gunakan aplikasi resmi E-Ujian Browser dari Google Play Store</span>
                    </div>
                </div>

                <div class="pretes-steps-grid">
                    <!-- Step 1 Android -->
                    <div class="pretes-step-box">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0284c7;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;flex-shrink:0;">1</span>
                                <strong style="font-size:13px;color:#0f172a;">Install E-Ujian Browser</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                Buka Google Play Store di HP Android Anda, pasang aplikasi resmi <strong>E-Ujian Browser</strong>.
                            </p>
                        </div>
                        <a href="https://play.google.com/store/apps/details?id=com.doovera.eujianbrowser" target="_blank" rel="noopener noreferrer"
                            style="background:#0284c7;color:#fff;padding:8px 12px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;text-align:center;display:block;">
                            📥 Buka Play Store ↗
                        </a>
                    </div>

                    <!-- Step 2 Android -->
                    <div class="pretes-step-box">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0284c7;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;flex-shrink:0;">2</span>
                                <strong style="font-size:13px;color:#0f172a;">Masukkan Kode Sekolah</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                Buka aplikasi. Ketika diminta kode lembaga / ID sekolah, ketik: <strong>22205</strong>.
                            </p>
                        </div>
                        <div style="background:#e0f2fe;color:#0369a1;padding:7px 10px;border-radius:6px;font-size:12px;font-weight:700;text-align:center;">
                            Kode: 22205
                        </div>
                    </div>

                    <!-- Step 3 Android -->
                    <div class="pretes-step-box">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0284c7;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;flex-shrink:0;">3</span>
                                <strong style="font-size:13px;color:#0f172a;">Login dari Catatan Kertas / Kartu Pretes</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                Masuk sebagai Siswa/Peserta. Ketikkan Username & Password sesuai catatan kertas atau cetak kartu pretes Anda.
                            </p>
                        </div>
                        <div style="background:#fef3c7;color:#92400e;padding:7px 10px;border-radius:6px;font-size:11.5px;font-weight:600;text-align:center;">
                            📝 Ketik dari catatan kertas / kartu pretes
                        </div>
                    </div>

                    <!-- Step 4 Android -->
                    <div class="pretes-step-box">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0284c7;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;flex-shrink:0;">4</span>
                                <strong style="font-size:13px;color:#0f172a;">Mulai Ujian</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                Pilih sesi Pretes LPPAI, masukkan Token dari pengawas ruangan, lalu kerjakan hingga selesai.
                            </p>
                        </div>
                        <div style="background:#dcfce7;color:#15803d;padding:7px 10px;border-radius:6px;font-size:11.5px;font-weight:700;text-align:center;">
                            ✅ Jawaban tersimpan otomatis
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── BAGIAN 2: PENGGUNA IPHONE / IPAD (BAWAH) ── -->
            <div id="panduan-seb-iphone">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:10px 14px;background:#f0fdf4;border:1.5px solid #a7f3d0;border-radius:10px;">
                    <span style="font-size:22px;flex-shrink:0;">🍏</span>
                    <div style="min-width:0;">
                        <strong style="font-size:14px;color:#065f46;display:block;word-break:break-word;">2. Khusus Pengguna iPhone / iPad (Safe Exam Browser)</strong>
                        <span style="font-size:12px;color:#047857;">Wajib gunakan Safe Exam Browser (SEB) & pasang file konfigurasi resmi</span>
                    </div>
                </div>

                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-bottom:14px;font-size:12px;color:#475569;line-height:1.55;box-sizing:border-box;word-break:break-word;">
                    💡 <strong>Catatan Khusus iPhone:</strong> Safe Exam Browser di iPhone memerlukan file konfigurasi resmi (<code>seb-config-lppai.seb</code>) agar browser otomatis mengunci layar dan langsung membuka ujian.
                </div>

                <div class="pretes-steps-grid">
                    <!-- Step 1 iOS -->
                    <div class="pretes-step-box">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0f766e;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;flex-shrink:0;">1</span>
                                <strong style="font-size:13px;color:#0f172a;">Install Safe Exam Browser</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                Buka App Store di iPhone, pasang aplikasi resmi <strong>Safe Exam Browser</strong>.
                            </p>
                        </div>
                        <a href="https://apps.apple.com/id/app/safeexambrowser/id1155002964?l=id" target="_blank" rel="noopener noreferrer"
                            style="background:#0f766e;color:#fff;padding:8px 12px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;text-align:center;display:block;">
                            🍏 Buka App Store ↗
                        </a>
                    </div>

                    <!-- Step 2 iOS -->
                    <div class="pretes-step-box">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0f766e;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;flex-shrink:0;">2</span>
                                <strong style="font-size:13px;color:#0f172a;">Pasang Konfigurasi Ujian</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                Buka web ini lewat Safari iPhone, klik tombol di bawah untuk otomatis membuka SEB:
                            </p>
                        </div>
                        <div style="display:flex;gap:6px;flex-direction:column;">
                            <a href="sebs://<?= preg_replace('#^https?://#', '', BASE_URL) ?>/assets/downloads/seb-config-lppai.seb"
                                style="background:#0f766e;color:#fff;padding:8px 10px;border-radius:6px;font-size:11.5px;font-weight:700;text-decoration:none;text-align:center;">
                                ⚡ Buka Langsung di SEB (Safari)
                            </a>
                            <a href="<?= BASE_URL ?>/assets/downloads/seb-config-lppai.seb" download="seb-config-lppai.seb"
                                style="background:#f0fdfa;color:#0f766e;border:1px solid #99f6e4;padding:7px 8px;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;text-align:center;">
                                📥 Atau Unduh File .seb
                            </a>
                        </div>
                    </div>

                    <!-- Step 3 iOS -->
                    <div class="pretes-step-box">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0f766e;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;flex-shrink:0;">3</span>
                                <strong style="font-size:13px;color:#0f172a;">Login dari Catatan Kertas / Kartu Pretes</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                SEB otomatis mengunci layar. Ketik Username & Password dari catatan kertas atau cetak kartu pretes Anda.
                            </p>
                        </div>
                        <div style="background:#fef3c7;color:#92400e;padding:7px 10px;border-radius:6px;font-size:11.5px;font-weight:600;text-align:center;">
                            📝 Ketik dari catatan kertas / kartu pretes
                        </div>
                    </div>

                    <!-- Step 4 iOS -->
                    <div class="pretes-step-box">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="background:#0f766e;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;flex-shrink:0;">4</span>
                                <strong style="font-size:13px;color:#0f172a;">Mulai Ujian</strong>
                            </div>
                            <p style="font-size:12px;color:#64748b;line-height:1.5;margin:0 0 10px;">
                                Masukkan Token dari Pengawas dan kerjakan soal sampai selesai.
                            </p>
                        </div>
                        <div style="background:#dcfce7;color:#15803d;padding:7px 10px;border-radius:6px;font-size:11.5px;font-weight:700;text-align:center;">
                            ✅ Kiosk mode aktif
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- ── 3. ATURAN PENTING & TATA TERTIB PRETES ── -->
    <div class="card" style="margin-bottom:22px;border:1.5px solid #e2e8f0;border-radius:16px;background:#ffffff;box-shadow:0 3px 12px rgba(0,0,0,0.03);overflow:hidden;box-sizing:border-box;max-width:100%;">
        <div style="background:#fef2f2;border-bottom:1px solid #fecaca;padding:12px 18px;display:flex;align-items:center;gap:8px;box-sizing:border-box;">
            <span style="font-size:18px;flex-shrink:0;">⚖️</span>
            <strong style="font-size:13.5px;color:#991b1b;word-break:break-word;">Tata Tertib & Ketentuan Wajib Pretes</strong>
        </div>
        <div class="card-body" style="padding:16px 14px;box-sizing:border-box;max-width:100%;">
            <div class="pretes-rules-grid">
                
                <!-- Poin 1: Try Out Mandiri -->
                <div style="background:#fefce8;border:1px solid #fde047;border-radius:12px;padding:14px;box-sizing:border-box;min-width:0;overflow:hidden;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <span style="font-size:18px;flex-shrink:0;">🏠</span>
                        <strong style="font-size:13px;color:#854d0e;">1. Try Out Mandiri dari Rumah</strong>
                    </div>
                    <div style="font-size:12px;color:#713f12;line-height:1.55;word-break:break-word;">
                        Manfaatkan waktu <strong>malam ini s.d. sebelum ujian (2 s.d. 6 November 2026)</strong> untuk mencoba login simulasi dari rumah dengan Kode Sekolah <code>22205</code> dan akun Anda, agar terbiasa dengan aplikasi.
                    </div>
                </div>

                <!-- Poin 2: Larangan 2 HP -->
                <div style="background:#fff1f2;border:1px solid #fecdd3;border-radius:12px;padding:14px;box-sizing:border-box;min-width:0;overflow:hidden;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <span style="font-size:18px;flex-shrink:0;">⛔</span>
                        <strong style="font-size:13px;color:#9f1239;">2. Dilarang 2 HP di Meja Ujian!</strong>
                    </div>
                    <div style="font-size:12px;color:#9f1239;line-height:1.55;word-break:break-word;">
                        Saat ujian resmi di kampus, <strong>dilarang meletakkan 2 HP di atas meja</strong>. Pelanggaran berakibat <strong>akun dinonaktifkan otomatis oleh Pengawas (gugur)</strong>. HP cadangan wajib dimatikan & ditaruh di tas di depan kelas.
                    </div>
                </div>

                <!-- Poin 3: Hadir Tepat Waktu -->
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px;box-sizing:border-box;min-width:0;overflow:hidden;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <span style="font-size:18px;flex-shrink:0;">⏰</span>
                        <strong style="font-size:13px;color:#166534;">3. Hadir 15 Menit Lebih Awal</strong>
                    </div>
                    <div style="font-size:12px;color:#166534;line-height:1.55;word-break:break-word;">
                        Hadir di ruangan ujian 15 menit sebelum waktu tes. Jika koneksi terputus atau baterai habis saat ujian, jawaban tersimpan aman di server; segera hidupkan HP atau lapor pengawas di ruangan.
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- ── 4. SESI JADWAL LAINNYA (ACCORDION / CLEAN CARDS) ── -->
    <?php if (!empty($schedules)): ?>
    <details style="background:#ffffff;border:1.5px solid #e2e8f0;border-radius:14px;margin-bottom:24px;overflow:hidden;box-sizing:border-box;box-shadow:0 2px 8px rgba(0,0,0,0.02);">
        <summary style="padding:14px 16px;font-size:13.5px;font-weight:700;color:#334155;cursor:pointer;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;outline:none;user-select:none;">
            <span style="display:flex;align-items:center;gap:8px;">
                <span>📅</span>
                <span>Informasi Sesi Jadwal Lainnya (<?= count($schedules) ?> Sesi)</span>
            </span>
            <span style="font-size:12px;color:#64748b;">▼</span>
        </summary>
        <div style="padding:14px;box-sizing:border-box;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(min(100%, 250px), 1fr));gap:10px;">
                <?php foreach ($schedules as $s): ?>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;box-sizing:border-box;<?= ($myReg && $myReg['periode'] === $s['periode']) ? 'border-color:#38bdf8;background:#f0f9ff;' : '' ?>">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <strong style="font-size:13px;color:#0f172a;"><?= sanitize($s['periode']) ?></strong>
                        <?php if ($myReg && $myReg['periode'] === $s['periode']): ?>
                            <span class="badge badge-primary" style="font-size:10px;">Sesi Anda</span>
                        <?php else: ?>
                            <span class="badge badge-success" style="font-size:10px;"><?= ucfirst($s['status']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:11.5px;color:#475569;line-height:1.6;">
                        <div>📅 <?= date('d M Y', strtotime($s['tanggal'])) ?> (<?= date('H:i', strtotime($s['waktu_mulai'])) ?> - <?= date('H:i', strtotime($s['waktu_selesai'])) ?>)</div>
                        <div>🏢 Ruangan: <strong><?= sanitize($s['ruangan']) ?></strong></div>
                        <div>👥 Kuota: <?= $s['terisi'] ?> / <?= $s['kuota'] ?> peserta</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </details>
    <?php endif; ?>

<?php else: ?>

    <!-- ── MAHASISWA BELUM DAFTAR PRETES ── -->
    <div class="card" style="border:2px solid #0284c7;background:#ffffff;margin-bottom:24px;border-radius:16px;box-shadow:0 4px 18px rgba(2,132,199,0.08);overflow:hidden;box-sizing:border-box;">
        <div style="background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%);color:#ffffff;padding:24px 18px;text-align:center;">
            <span style="font-size:40px;display:block;margin-bottom:10px;">📝</span>
            <h3 style="margin:0 0 8px;font-size:18px;font-weight:800;">Anda Belum Terdaftar di Sesi Pretes</h3>
            <p style="margin:0 auto 16px;font-size:13.5px;color:#e0f2fe;line-height:1.55;max-width:500px;">
                Silakan pilih hari dan jam pelaksanaan pretes yang Anda inginkan melalui menu pendaftaran sebelum kuota terisi penuh.
            </p>
            <a href="<?= BASE_URL ?>/pretes-daftar.php"
                style="background:#f59e0b;color:#ffffff;border:none;padding:11px 24px;border-radius:10px;font-size:14px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 14px rgba(245,158,11,0.4);transition:all 0.2s;">
                👉 Daftar Pretes Sekarang ➔
            </a>
        </div>

        <div class="card-body" style="padding:18px 16px;">
            <h4 style="margin:0 0 12px;font-size:14.5px;font-weight:800;color:#0f172a;">
                📅 Sesi Jadwal Pretes yang Tersedia:
            </h4>

            <?php if (empty($schedules)): ?>
                <div class="empty-state" style="padding:30px 10px;">
                    <div class="icon">📅</div>
                    <h3>Jadwal belum tersedia</h3>
                    <p>Jadwal pretes akan ditampilkan ketika TU sudah menginputkan jadwal.</p>
                </div>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(min(100%, 250px), 1fr));gap:12px;">
                    <?php foreach ($schedules as $s): ?>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-sizing:border-box;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <strong style="font-size:13.5px;color:#0f172a;"><?= sanitize($s['periode']) ?></strong>
                            <span class="badge badge-success" style="font-size:11px;"><?= ucfirst($s['status']) ?></span>
                        </div>
                        <div style="font-size:12px;color:#475569;line-height:1.6;margin-bottom:12px;">
                            <div>📅 <strong><?= date('d M Y', strtotime($s['tanggal'])) ?></strong></div>
                            <div>⏰ Waktu: <?= date('H:i', strtotime($s['waktu_mulai'])) ?> - <?= date('H:i', strtotime($s['waktu_selesai'])) ?> WIB</div>
                            <div>🏢 Ruangan: <strong><?= sanitize($s['ruangan']) ?></strong></div>
                            <div>👥 Kuota Terisi: <strong><?= $s['terisi'] ?> / <?= $s['kuota'] ?></strong></div>
                        </div>
                        <a href="<?= BASE_URL ?>/pretes-daftar.php"
                            style="background:#0284c7;color:#fff;display:block;text-align:center;padding:7px 12px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;">
                            Pilih Sesi Ini ➔
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

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
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
