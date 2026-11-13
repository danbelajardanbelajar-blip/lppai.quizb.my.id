<?php
/**
 * LPPAI Corner - Peserta & Jadwal Pretes (Mahasiswa)
 * Menampilkan jadwal pretes, petunjuk pelaksanaan ujian, dan akses pengerjaan soal.
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

// ── Logika Gating Tanggal Pengerjaan Soal Pretes ───────────────────────────────
// Periode resmi pretes: 16 – 20 November 2026
$tglSekarang        = date('Y-m-d');
$tglBukaUjian       = '2026-11-16';
$isTesterPrivileged = (isset($user['username']) && (string)$user['username'] === '12345') 
                   || (isset($user['nim']) && (string)$user['nim'] === '12345')
                   || ((int)($user['id'] ?? 0) === 12345);
$isUjianDibuka      = ($tglSekarang >= $tglBukaUjian) || $isTesterPrivileged;
$linkSoalPretes     = BASE_URL . '/pretes-ujian.php'; // Mengarahkan ke Portal Ujian Pretes & Pengawas AI (Skenario A & B)

// ── Ambil jadwal pretes aktif untuk informasi sesi ─────────────────────────────
$schedules = $pdo->query(
    "SELECT * FROM pretes_schedules WHERE (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%') ORDER BY tanggal ASC, waktu_mulai ASC"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Layout & Grid Responsif Khusus Pretes Mahasiswa ── */
.pretes-info-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 16px;
    width: 100%;
    box-sizing: border-box;
}

.pretes-info-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px;
    box-sizing: border-box;
    min-width: 0;
    overflow: hidden;
}

.pretes-instructions-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
    margin-top: 14px;
    box-sizing: border-box;
}

.instruction-item-card {
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 18px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.03);
    transition: transform 0.15s ease, border-color 0.15s ease;
}

.instruction-item-card:hover {
    border-color: #0d9488;
    transform: translateY(-2px);
}

.instruction-badge-num {
    background: #0f766e;
    color: #ffffff;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 15px;
    flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(15,118,110,0.25);
}

@media (max-width: 860px) {
    .pretes-instructions-grid {
        grid-template-columns: 1fr;
    }
    .pretes-info-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 480px) {
    .pretes-info-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- ═══════════════════════════════════════════════════════════════════
     INFORMASI RESMI & PANDUAN PRETES MAHASISWA
═══════════════════════════════════════════════════════════════════ -->
<?php if ($myReg): ?>

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
        <div>
            <h2 class="page-title" style="margin:0;">Pretes Saya</h2>
            <p style="margin:4px 0 0;color:#64748b;font-size:13.5px;">
                Tutorial Ubudiyah Unisda (Pelaksanaan: 16 – 20 November 2026). Periksa jadwal Anda dan cetak kartu ujian.
            </p>
        </div>
        <a href="<?= BASE_URL ?>/cetak-kartu-pretes.php" target="_blank" rel="noopener" data-no-spa class="btn btn-primary"
            style="display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:11px 20px;border-radius:10px;font-weight:700;text-decoration:none;box-shadow:0 4px 12px rgba(15,118,110,0.2);"
            aria-label="Cetak kartu pretes (buka tab baru)">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9V3h12v6M6 18H3V9h18v9h-3M6 14h12v7H6z"/><path d="M17 12h1"/></svg>
            Cetak Kartu Pretes
        </a>
    </div>

    <!-- ── 1. KARTU JADWAL RESMI PESERTA ── -->
    <div class="card" style="border:2px solid #0f766e;background:#ffffff;margin-bottom:22px;border-radius:16px;box-shadow:0 4px 18px rgba(15,118,110,0.08);overflow:hidden;box-sizing:border-box;">
        <!-- Header Kartu -->
        <div style="background:linear-gradient(135deg, #0f766e 0%, #115e59 100%);color:#ffffff;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px 12px;">
            <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                <span style="font-size:24px;flex-shrink:0;">📅</span>
                <div style="min-width:0;">
                    <h3 style="margin:0;font-size:15px;font-weight:800;color:#ffffff;line-height:1.3;">
                        Jadwal Pelaksanaan Pretes Anda
                    </h3>
                    <div style="font-size:12px;color:#ccfbf1;margin-top:2px;">
                        Periode: <strong><?= sanitize($myReg['periode']) ?> (16 – 20 Nov 2026)</strong> | Status: <strong style="text-transform:capitalize;"><?= sanitize(str_replace('_', ' ', $myReg['status'] ?? 'Terdaftar')) ?></strong>
                    </div>
                </div>
            </div>
            <span style="background:rgba(255,255,255,0.2);color:#ffffff;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:700;">
                ✓ Terverifikasi Resmi
            </span>
        </div>

        <div class="card-body" style="padding:18px 16px;">
            <!-- Grid Ringkasan Jadwal Pribadi -->
            <div class="pretes-info-grid">
                <!-- Hari -->
                <div class="pretes-info-box">
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">📅 Hari Ujian</div>
                    <div style="font-size:15px;font-weight:800;color:#0f172a;word-break:break-word;">
                        <?php 
                        $dMap = [
                            'senin' => 'Senin, 16 Nov 2026',
                            'selasa' => 'Selasa, 17 Nov 2026',
                            'rabu' => 'Rabu, 18 Nov 2026',
                            'kamis' => 'Kamis, 19 Nov 2026',
                            'jumat' => 'Jumat, 20 Nov 2026'
                        ];
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

            <!-- Kredensial Akun Tes (Jika Ada) -->
            <?php if (!empty($myReg['username_tes']) && !empty($myReg['password_tes'])): ?>
                <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                    <div>
                        <div style="font-size:11.5px;font-weight:800;color:#15803d;text-transform:uppercase;letter-spacing:0.5px;">Akun Login Ujian CBT Anda:</div>
                        <div style="font-size:14px;color:#1e293b;margin-top:2px;">
                            Username: <strong><?= sanitize($myReg['username_tes']) ?></strong> &bull; Password: <strong><?= sanitize($myReg['password_tes']) ?></strong>
                        </div>
                    </div>
                    <button type="button" onclick="copyText('Username: <?= sanitize($myReg['username_tes']) ?> | Password: <?= sanitize($myReg['password_tes']) ?>', 'btn-copy-akun')" id="btn-copy-akun"
                        style="background:#0f766e;color:#fff;border:none;padding:7px 14px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;">
                        📋 Salin Akun
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── 2. KARTU INSTRUKSI & TATA TERTIB RESMI UJIAN PRETES ── -->
    <div class="card" style="border:1.5px solid #cbd5e1;background:#ffffff;margin-bottom:22px;border-radius:16px;box-shadow:0 3px 12px rgba(0,0,0,0.03);overflow:hidden;">
        <!-- Header Instruksi -->
        <div style="background:#f8fafc;border-bottom:1.5px solid #e2e8f0;padding:14px 18px;display:flex;align-items:center;gap:10px;">
            <span style="font-size:22px;flex-shrink:0;">📜</span>
            <div>
                <h4 style="margin:0;font-size:15px;font-weight:800;color:#0f172a;">
                    Instruksi & Tata Tertib Pelaksanaan Ujian
                </h4>
                <span style="font-size:12.5px;color:#64748b;">
                    Harap perhatikan dan patuhi seluruh instruksi berikut selama pelaksanaan ujian pretes
                </span>
            </div>
        </div>

        <div class="card-body" style="padding:18px 16px;">
            <!-- Grid 4 Poin Instruksi Utama -->
            <div class="pretes-instructions-grid">
                
                <!-- Poin 1 -->
                <div class="instruction-item-card">
                    <div class="instruction-badge-num">1</div>
                    <div>
                        <div style="font-size:14px;font-weight:800;color:#0f172a;margin-bottom:4px;">
                            Persiapkan Diri
                        </div>
                        <div style="font-size:13px;color:#475569;line-height:1.55;">
                            Persiapkan diri untuk mengikuti ujian dengan berdoa dan belajar dengan sungguh-sungguh.
                        </div>
                    </div>
                </div>

                <!-- Poin 2 -->
                <div class="instruction-item-card">
                    <div class="instruction-badge-num">2</div>
                    <div>
                        <div style="font-size:14px;font-weight:800;color:#0f172a;margin-bottom:4px;">
                            Dikerjakan Sendiri
                        </div>
                        <div style="font-size:13px;color:#475569;line-height:1.55;">
                            Pastikan dikerjakan sendiri secara mandiri, jujur, dan bertanggung jawab tanpa bantuan orang lain.
                        </div>
                    </div>
                </div>

                <!-- Poin 3 -->
                <div class="instruction-item-card">
                    <div class="instruction-badge-num">3</div>
                    <div>
                        <div style="font-size:14px;font-weight:800;color:#0f172a;margin-bottom:4px;">
                            Tidak Membuka Web Lain
                        </div>
                        <div style="font-size:13px;color:#475569;line-height:1.55;">
                            Pastikan tidak membuka web lain, tab baru, aplikasi lain, atau mesin pencari selama ujian berlangsung.
                        </div>
                    </div>
                </div>

                <!-- Poin 4 -->
                <div class="instruction-item-card">
                    <div class="instruction-badge-num">4</div>
                    <div>
                        <div style="font-size:14px;font-weight:800;color:#0f172a;margin-bottom:4px;">
                            Tidak Menggunakan HP Lain
                        </div>
                        <div style="font-size:13px;color:#475569;line-height:1.55;">
                            Pastikan tidak menggunakan HP lain atau perangkat komunikasi tambahan apapun saat mengerjakan soal.
                        </div>
                    </div>
                </div>

            </div>

            <!-- ── 3. AKSES SOAL PRETES (GATED MULAI 16 NOVEMBER) ── -->
            <div style="margin-top:24px;">
                <?php if ($isTesterPrivileged && $tglSekarang < $tglBukaUjian): ?>
                    <!-- NOTIFIKASI KHUSUS AKUN TESTER 12345 -->
                    <div style="background:#fef3c7;border:1.5px dashed #f59e0b;color:#92400e;padding:12px 16px;border-radius:12px;font-size:13px;margin-bottom:14px;font-weight:700;display:flex;align-items:center;gap:10px;">
                        <span style="font-size:24px;flex-shrink:0;">🧪</span>
                        <div>
                            <div style="font-size:13.5px;color:#78350f;">Mode Percobaan Pengembang Aktif (Privilege Khusus Akun 12345)</div>
                            <div style="font-weight:500;font-size:12px;color:#92400e;margin-top:2px;line-height:1.4;">
                                Akun Anda (<strong><?= sanitize($user['username'] ?? $user['nim'] ?? '12345') ?></strong>) diberikan privilege istimewa untuk membuka dan menguji halaman ujian pretes beserta sistem pengawas AI (Skenario A & B) secara langsung sebelum tanggal 16 November 2026.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($isUjianDibuka): ?>
                    <!-- KONDISI A: SUDAH TANGGAL 16 NOVEMBER KE ATAS / PRIVILEGE TESTER 12345 -->
                    <div style="background:#f0fdf4;border:2px solid #86efac;border-radius:14px;padding:22px 18px;text-align:center;box-shadow:0 4px 14px rgba(22,163,74,0.1);">
                        <span style="font-size:36px;display:block;margin-bottom:8px;">🚀</span>
                        <h4 style="margin:0 0 6px;color:#15803d;font-size:16px;font-weight:800;">
                            <?= ($isTesterPrivileged && $tglSekarang < $tglBukaUjian) ? 'Sesi Uji Coba Halaman Ujian Pretes (Akun 12345)' : 'Sesi Pengerjaan Soal Pretes Telah Dibuka' ?>
                        </h4>
                        <p style="margin:0 auto 16px;color:#166534;font-size:13px;max-width:520px;line-height:1.55;">
                            Pastikan Anda telah membaca 4 instruksi di atas dan siap memulai ujian. Klik tombol di bawah untuk membuka lembar soal dengan pengawasan AI.
                        </p>
                        <a href="<?= !empty($linkSoalPretes) ? htmlspecialchars($linkSoalPretes) : 'javascript:void(0);' ?>"
                            id="btn-kerjakan-pretes"
                            class="btn btn-success"
                            style="display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 32px;font-size:15px;font-weight:800;border-radius:10px;text-decoration:none;box-shadow:0 4px 14px rgba(22,163,74,0.3);min-height:46px;">
                            📝 Kerjakan Soal Pretes
                        </a>
                    </div>
                <?php else: ?>
                    <!-- KONDISI B: BELUM TANGGAL 16 NOVEMBER -->
                    <div style="background:#eff6ff;border:1.5px dashed #93c5fd;border-radius:14px;padding:18px 20px;display:flex;align-items:flex-start;gap:14px;">
                        <span style="font-size:30px;line-height:1;flex-shrink:0;">🔒</span>
                        <div style="font-size:13.5px;color:#1e40af;line-height:1.6;min-width:0;">
                            <strong style="display:block;font-size:15px;margin-bottom:4px;color:#1e3a8a;">
                                Link Pengerjaan Soal Pretes Belum Dibuka
                            </strong>
                            Link <strong>"Kerjakan Soal Pretes"</strong> akan dapat diakses pada <strong>Senin, 16 November 2026</strong> saat periode ujian pretes resmi dimulai. Silakan persiapkan diri dengan berdoa dan mempelajari materi tutorial ubudiyah.
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- ── 4. SESI JADWAL LAINNYA (ACCORDION) ── -->
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
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;box-sizing:border-box;<?= ($myReg && $myReg['periode'] === $s['periode']) ? 'border-color:#0d9488;background:#f0fdfa;' : '' ?>">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <strong style="font-size:13px;color:#0f172a;"><?= sanitize($s['periode']) ?></strong>
                        <?php if ($myReg && $myReg['periode'] === $s['periode']): ?>
                            <span class="badge badge-primary" style="font-size:10px;background:#0f766e;">Sesi Anda</span>
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
    <div class="card" style="border:2px solid #0f766e;background:#ffffff;margin-bottom:24px;border-radius:16px;box-shadow:0 4px 18px rgba(15,118,110,0.08);overflow:hidden;box-sizing:border-box;">
        <div style="background:linear-gradient(135deg, #0f766e 0%, #115e59 100%);color:#ffffff;padding:26px 18px;text-align:center;">
            <span style="font-size:42px;display:block;margin-bottom:10px;">📝</span>
            <h3 style="margin:0 0 8px;font-size:18px;font-weight:800;">Anda Belum Terdaftar di Sesi Pretes</h3>
            <p style="margin:0 auto 16px;font-size:13.5px;color:#ccfbf1;line-height:1.55;max-width:500px;">
                Silakan pilih hari dan jam pelaksanaan pretes yang Anda inginkan serta unggah pasfoto resmi melalui menu pendaftaran sebelum kuota terisi penuh.
            </p>
            <a href="<?= BASE_URL ?>/pretes-daftar.php"
                style="background:#f59e0b;color:#ffffff;border:none;padding:12px 26px;border-radius:10px;font-size:14px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 14px rgba(245,158,11,0.4);transition:all 0.2s;">
                👉 Daftar Pretes Sekarang ➔
            </a>
            <?php if ($isTesterPrivileged): ?>
                <div style="margin-top:12px;">
                    <a href="<?= BASE_URL ?>/pretes-ujian.php"
                        style="background:#0f766e;color:#ffffff;border:none;padding:10px 22px;border-radius:10px;font-size:13px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(15,118,110,0.3);">
                        🧪 Coba Langsung Halaman Ujian Pretes (Privilege Akun 12345) ➔
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-body" style="padding:18px 16px;">
            <h4 style="margin:0 0 12px;font-size:14.5px;font-weight:800;color:#0f172a;">
                📅 Sesi Jadwal Pretes yang Tersedia (16 – 20 November 2026):
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
                            style="background:#0f766e;color:#fff;display:block;text-align:center;padding:8px 12px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;">
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
function copyText(text, btnId) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            const btn = document.getElementById(btnId);
            if (btn) {
                const original = btn.textContent;
                btn.textContent = '✅ Tersalin!';
                btn.style.background = '#15803d';
                setTimeout(() => { btn.textContent = original; btn.style.background = ''; }, 2000);
            }
        }).catch(() => {
            fallbackCopyText(text, btnId);
        });
    } else {
        fallbackCopyText(text, btnId);
    }
}

function fallbackCopyText(text, btnId) {
    const el = document.createElement('textarea');
    el.value = text;
    document.body.appendChild(el);
    el.select();
    document.execCommand('copy');
    document.body.removeChild(el);
    const btn = document.getElementById(btnId);
    if (btn) {
        const original = btn.textContent;
        btn.textContent = '✅ Tersalin!';
        setTimeout(() => { btn.textContent = original; }, 2000);
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
