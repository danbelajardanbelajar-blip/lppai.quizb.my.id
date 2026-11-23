<?php
/**
 * LPPAI Corner - Informasi Pendaftaran Tutorial RPL Gelombang 1 (URL: /tutorial-rpl.php)
 * Tujuan link dari kartu hasil CBT pretes bila nilai di bawah 80.
 */
define('PAGE_TITLE', 'Pendaftaran Tutorial RPL Gelombang 1');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/tutorial-rpl-registration-policy.php';

$status = tutorialRplRegistrationWindow();

include __DIR__ . '/../includes/header.php';
?>
<div style="max-width:720px;margin:0 auto;">
    <div style="background:linear-gradient(135deg,#0b3b22 0%,#156138 100%);color:#fff;border-radius:18px;padding:30px 26px;text-align:center;box-shadow:0 12px 30px rgba(11,59,34,.2);">
        <div style="font-size:42px;margin-bottom:6px;">📝</div>
        <h2 style="margin:0 0 8px;font-size:24px;font-weight:900;color:#fff;">Pendaftaran Tutorial RPL Gelombang 1</h2>
        <p style="margin:0;font-size:16px;opacity:.95;line-height:1.6;" id="tutorial-rpl-period">
            Pendaftaran Tutorial RPL Gelombang 1 dimulai tanggal <strong>23 sampai 27 November 2026</strong>.
        </p>
    </div>

    <?php include __DIR__ . '/../includes/tutorial-rpl-registration-rules-view.php'; ?>
    <div class="card" style="margin-top:18px;border-radius:16px;padding:22px 22px;">
        <?php if ($status === 'soon'): ?>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <span style="font-size:28px;">⏳</span>
                <div style="font-size:14px;line-height:1.65;color:#334155;">
                    <strong style="display:block;color:#0f172a;font-size:15px;margin-bottom:2px;">Pendaftaran belum dibuka</strong>
                    Silakan kembali ke halaman ini atau menu <strong>Pendaftaran Tutorial</strong> mulai <strong>Senin, 23 November 2026</strong>. Pendaftaran ditutup pada <strong>Jumat, 27 November 2026</strong>.
                </div>
            </div>
        <?php elseif ($status === 'open'): ?>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <span style="font-size:28px;">✅</span>
                <div style="font-size:14px;line-height:1.65;color:#334155;">
                    <strong style="display:block;color:#0f172a;font-size:15px;margin-bottom:2px;">Pendaftaran sedang dibuka</strong>
                    Segera daftarkan diri Anda sebelum <strong>27 November 2026</strong>.
                    <div style="margin-top:12px;"><a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="btn btn-primary" style="text-decoration:none;">Daftar Tutorial Sekarang</a></div>
                </div>
            </div>
        <?php else: ?>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <span style="font-size:28px;">🔒</span>
                <div style="font-size:14px;line-height:1.65;color:#334155;">
                    <strong style="display:block;color:#0f172a;font-size:15px;margin-bottom:2px;">Periode pendaftaran Gelombang 1 telah berakhir</strong>
                    Silakan hubungi admin LPPAI untuk informasi gelombang berikutnya.
                </div>
            </div>
        <?php endif; ?>
        <div style="margin-top:18px;padding-top:14px;border-top:1px solid #e2e8f0;display:flex;gap:10px;flex-wrap:wrap;">
            <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-secondary" style="text-decoration:none;">← Kembali ke Halaman Pretes</a>
            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary" style="text-decoration:none;">🏠 Dashboard</a>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
