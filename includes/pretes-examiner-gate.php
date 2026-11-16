<?php
/**
 * LPPAI Corner - Gerbang halaman pretes untuk dosen (dipakai dosen/pretes.php & dosen/pretes-rekap-nilai.php)
 *
 * Dosen yang tidak terjadwal sebagai penguji pada periode pretes aktif (via NIM akun) melihat
 * pesan yang jelas alih-alih tabel kosong. Dosen yang NIM akunnya kosong diminta menghubungi admin.
 * Rekap tetap dapat dibuka bila dosen punya penugasan di periode lain (riwayat).
 */
require_once __DIR__ . '/pretes-examiner.php';

function pretesExaminerGate(PDO $pdo, array $user, bool $allowHistory = false): void {
    if (strtolower((string)($user['role'] ?? '')) !== 'dosen') return;
    pretesExaminerEnsure($pdo);
    $nim = pretesExaminerUserNim($user);
    if ($nim !== '' && pretesExaminerIsScheduled($pdo, $user)) return;
    if ($nim !== '' && $allowHistory) {
        $stmt = $pdo->prepare('SELECT 1 FROM pretes_registrations WHERE dosen_penguji_nim = ? LIMIT 1');
        $stmt->execute([$nim]);
        if ($stmt->fetchColumn()) return;
    }

    if (!defined('PAGE_TITLE')) define('PAGE_TITLE', 'Penilaian Pretes');
    include __DIR__ . '/header.php';
    $title = $nim === '' ? 'NIM Akun Dosen Belum Diisi' : 'Anda Tidak Terjadwal sebagai Penguji Pretes';
    $body = $nim === ''
        ? 'Penugasan penguji pretes dicocokkan berdasarkan NIM/NIDN akun dosen. NIM pada akun Anda masih kosong, sehingga daftar peserta tidak dapat ditampilkan. Silakan hubungi admin LPPAI untuk melengkapi NIM akun Anda.'
        : 'Akun Anda (NIM ' . $nim . ') tidak terjadwal sebagai dosen penguji pada periode pretes yang sedang berlangsung. Halaman penilaian & absensi pretes hanya untuk penguji yang ditugaskan. Bila Anda seharusnya menguji, hubungi admin LPPAI agar penugasan Anda diperbarui.';
    ?>
    <div class="card" style="max-width:640px;margin:24px auto;border-radius:16px;padding:32px 24px;text-align:center;border:2px dashed #cbd5e1;">
        <div style="font-size:44px;margin-bottom:8px;"><?= $nim === '' ? '🪪' : '🗓️' ?></div>
        <h3 style="margin:0 0 10px;font-weight:800;color:#0f172a;"><?= sanitize($title) ?></h3>
        <p style="margin:0 0 18px;color:#475569;font-size:14px;line-height:1.6;"><?= sanitize($body) ?></p>
        <a href="<?= BASE_URL ?>/dosen/dashboard.php" class="btn btn-primary" style="text-decoration:none;">🏠 Kembali ke Dashboard</a>
    </div>
    <?php
    include __DIR__ . '/footer.php';
    exit;
}
