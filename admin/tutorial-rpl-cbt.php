<?php
/**
 * LPPAI Corner - Admin: Pengaturan Sesi Ujian Tulis Tutorial RPL (CBT)
 */
define('PAGE_TITLE', 'Ujian Tulis Tutorial RPL (CBT)');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
requireAdmin();

$pdo = getDBConnection();
ensureTutorialRplTables($pdo);

$message = '';
$msgType = '';

include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0; font-size:22px; font-weight:800; color:var(--text-dark);">
            🧩 Ujian Tulis Tutorial RPL Online (CBT)
        </h2>
        <p style="margin:0; font-size:13.5px; color:#64748b;">
            Pengaturan sesi, bank soal, dan pemantauan ujian tulis berbasis komputer untuk mahasiswa program RPL.
        </p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="<?= BASE_URL ?>/admin/tutorial-rpl-presensi.php" class="btn btn-secondary">
            <span>✅</span> Cek Presensi
        </a>
    </div>
</div>

<div class="card" style="margin-bottom:24px; border-left:4px solid #8b5cf6; background:#f5f3ff; border-radius:12px;">
    <div class="card-body" style="padding:16px 20px;">
        <div style="display:flex; align-items:flex-start; gap:14px;">
            <span style="font-size:26px;">💡</span>
            <div style="flex:1; font-size:13.5px; color:#5b21b6; line-height:1.6;">
                <strong>Arsitektur Mesin CBT Tutorial RPL:</strong>
                <p style="margin:4px 0 0 0;">
                    Ujian Tulis Tutorial RPL mengadopsi sistem mesin CBT otomatis (pengacakan soal, token sesi, timer server, dan penilaian langsung) namun terisolasi dari bank soal pretes. Mahasiswa mengerjakan soal evaluasi materi tutorial secara daring sebagai salah satu syarat pengesahan kehadiran &amp; nilai akhir tutorial.
                </p>
            </div>
        </div>
    </div>
</div>

<div class="card" style="box-shadow:var(--shadow); border-radius:12px;">
    <div class="card-header" style="background:#f8fafc; font-weight:700; color:var(--text-dark);">
        ⚙️ Status Sesi Ujian Tulis Tutorial RPL
    </div>
    <div class="card-body" style="padding:24px;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:16px; margin-bottom:20px;">
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px;">
                <span style="font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;">Status Ujian</span>
                <div style="font-size:18px; font-weight:700; color:#15803d; margin-top:4px;">🟢 Siap / Sesi Aktif</div>
                <small style="color:#64748b;">Mengikuti kalender akademik Tutorial RPL</small>
            </div>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px;">
                <span style="font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;">Jumlah Soal</span>
                <div style="font-size:18px; font-weight:700; color:#1e293b; margin-top:4px;">25 Butir Pilihan Ganda</div>
                <small style="color:#64748b;">Materi Ubudiyah &amp; Praktik Sholat</small>
            </div>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px;">
                <span style="font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;">Durasi Pengerjaan</span>
                <div style="font-size:18px; font-weight:700; color:#1e293b; margin-top:4px;">30 Menit</div>
                <small style="color:#64748b;">Timer otomatis di sisi server</small>
            </div>
        </div>
        <div style="border-top:1px solid #e2e8f0; padding-top:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <span style="font-size:13px; color:#64748b;">Integrasi modul bank soal &amp; sesi CBT tutorial RPL siap dikonfigurasi pada Tahap 4.</span>
            <a href="<?= BASE_URL ?>/admin/tutorial-rpl-kelas.php" class="btn btn-primary btn-sm">
                Kelola Kelompok RPL →
            </a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
