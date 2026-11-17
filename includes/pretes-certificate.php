<?php
/**
 * LPPAI Corner - Kelulusan & pengambilan sertifikat pretes (fokus mahasiswa RPL mode "cukup CBT").
 *
 * - Mahasiswa RPL dengan CBT resmi ≥ 80 dinyatakan LULUS (lihat pretesRplCbtOnly()).
 * - Instruksi pengambilan sertifikat (bayar Rp 50.000 di Bank Jatim, bawa nota + pasfoto 3×4 ke
 *   Kantor LPPAI) baru tampil setelah admin memasang pengumuman aktif bertipe
 *   PRETES_CERT_ANNOUNCEMENT_TYPE ("Pengambilan Sertifikat Pretes") di Admin › Pengumuman.
 */
require_once __DIR__ . '/pretes-components.php';

const PRETES_CERT_ANNOUNCEMENT_TYPE = 'pengambilan_sertifikat_pretes';
const PRETES_CERT_FEE_TEXT = 'Rp 50.000,-';
const PRETES_CERT_BANK_TEXT = 'Bank Jatim';
const PRETES_CERT_OFFICE_TEXT = 'Kantor LPPAI (Lt. 2 Gedung Fakultas Hukum, timur kantin)';

/** Apakah user adalah mahasiswa RPL yang kelulusannya cukup dengan CBT (mode aktif). */
function pretesIsRplCbtOnlyUser(?array $user): bool {
    if (!$user) return false;
    return pretesProgramKey($user['jenis_program'] ?? '') === 'rpl' && pretesRplCbtOnly();
}

/**
 * Pengumuman pengambilan sertifikat pretes yang aktif & berlaku untuk user (terbaru), atau null.
 * Menghormati target program (Semua/RPL/Reguler) dan sasaran peserta pengumuman.
 */
function pretesCertificateAnnouncement(PDO $pdo, array $user): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM announcements WHERE is_active = 1 AND tipe = ? ORDER BY created_at DESC, id DESC LIMIT 20');
        $stmt->execute([PRETES_CERT_ANNOUNCEMENT_TYPE]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return null;
    }
    if (!$rows) return null;
    if (!function_exists('isUserEligibleForAnnouncement')) {
        $helper = __DIR__ . '/announcement-helper.php';
        if (is_file($helper)) require_once $helper;
    }
    $context = ['is_pretes_lulus' => true, 'has_pretes_reg' => true, 'has_tutorial_reg' => false];
    foreach ($rows as $ann) {
        if (!function_exists('isUserEligibleForAnnouncement') || isUserEligibleForAnnouncement($user, $ann, $context)) {
            return $ann;
        }
    }
    return null;
}

/**
 * Blok instruksi sertifikat untuk mahasiswa yang sudah lulus.
 * $announcement null → "menunggu pengumuman"; selain itu tampilkan langkah + isi pengumuman.
 */
function renderPretesCertificateSteps(?array $announcement, bool $compact = false): void {
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    if (!$announcement): ?>
        <div class="pretes-cert-box pretes-cert-wait" id="pretes-cert-steps" data-cert-state="waiting" style="margin-top:<?= $compact ? '12' : '16' ?>px;border:1.5px dashed #fcd34d;background:#fffbeb;border-radius:12px;padding:14px 16px;">
            <div style="display:flex;gap:10px;align-items:flex-start;">
                <span style="font-size:22px;line-height:1;">⏳</span>
                <div>
                    <div style="font-weight:800;color:#92400e;font-size:14px;">Menunggu Pengumuman Pengambilan Sertifikat</div>
                    <p style="margin:4px 0 0;color:#78350f;font-size:13px;line-height:1.6;">
                        Sertifikat kelulusan pretes Anda akan dapat diambil setelah LPPAI mengumumkan jadwal pengambilan sertifikat.
                        Saat ini <strong>belum perlu melakukan pembayaran</strong>. Pantau dashboard ini secara berkala.
                    </p>
                </div>
            </div>
        </div>
    <?php return; endif;
    $konten = trim((string)($announcement['konten'] ?? ''));
    ?>
    <div class="pretes-cert-box pretes-cert-open" id="pretes-cert-steps" data-cert-state="announced" style="margin-top:<?= $compact ? '12' : '16' ?>px;border:1.5px solid #86efac;background:#ffffff;border-radius:12px;padding:14px 16px;">
        <div style="display:inline-flex;align-items:center;gap:6px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-size:11.5px;font-weight:800;padding:4px 12px;border-radius:20px;text-transform:uppercase;letter-spacing:.04em;">
            📢 Pengambilan Sertifikat Telah Dibuka
        </div>
        <div style="margin:10px 0 4px;font-weight:800;color:#0f172a;font-size:14.5px;"><?= $e($announcement['judul'] ?? 'Pengambilan Sertifikat Pretes') ?></div>
        <?php if ($konten !== ''): ?>
            <div style="color:#334155;font-size:13px;line-height:1.6;white-space:pre-wrap;margin-bottom:10px;"><?= $e($konten) ?></div>
        <?php endif; ?>
        <ol id="pretes-cert-step-list" style="margin:6px 0 0;padding:0;list-style:none;display:grid;gap:8px;">
            <li style="display:flex;gap:10px;align-items:flex-start;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;">
                <span style="flex-shrink:0;width:26px;height:26px;border-radius:50%;background:#16a34a;color:#fff;font-weight:800;display:grid;place-items:center;font-size:13px;">1</span>
                <div style="font-size:13px;color:#1f2937;line-height:1.55;">
                    <strong>Bayar biaya administrasi sertifikat <?= $e(PRETES_CERT_FEE_TEXT) ?></strong> melalui <strong><?= $e(PRETES_CERT_BANK_TEXT) ?></strong>, lalu simpan <strong>nota / bukti pembayaran</strong>.
                </div>
            </li>
            <li style="display:flex;gap:10px;align-items:flex-start;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;">
                <span style="flex-shrink:0;width:26px;height:26px;border-radius:50%;background:#16a34a;color:#fff;font-weight:800;display:grid;place-items:center;font-size:13px;">2</span>
                <div style="font-size:13px;color:#1f2937;line-height:1.55;">
                    <strong>Datang ke <?= $e(PRETES_CERT_OFFICE_TEXT) ?></strong> dengan membawa <strong>nota pembayaran</strong> dan <strong>pasfoto 3×4</strong> untuk mengambil sertifikat.
                </div>
            </li>
        </ol>
    </div>
    <?php
}

/**
 * Kartu "LULUS" untuk mahasiswa RPL mode cukup CBT (dipakai kartu hasil CBT di dashboard/peserta
 * dan halaman Hasil Pretes). $scoreText = nilai CBT terformat.
 */
function renderPretesRplPassedNotice(string $scoreText, ?array $announcement, bool $compact = false, bool $showHeadline = true): void {
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    ?>
    <div id="pretes-rpl-passed" data-stage="passed_cbt_only">
        <?php if ($showHeadline): ?>
        <p style="margin:0 0 6px;color:#14532d;font-size:<?= $compact ? '14' : '15' ?>px;line-height:1.6;font-weight:800;">
            🎉 Selamat, Saudara dinyatakan <span style="color:#15803d;">LULUS PRETES</span>.
        </p>
        <?php endif; ?>
        <p style="margin:0;color:#166534;font-size:13px;line-height:1.6;">
            Nilai CBT resmi Anda <strong><?= $e($scoreText) ?></strong> memenuhi nilai minimal 80. Anda dibebaskan dari kewajiban mengikuti Tutorial RPL dan berhak mendapatkan <strong>sertifikat kelulusan pretes</strong>.
        </p>
        <?php renderPretesCertificateSteps($announcement, $compact); ?>
    </div>
    <?php
}
