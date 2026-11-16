<?php
/**
 * LPPAI Corner - Kartu Hasil CBT Pretes di halaman peserta aktif (pretes-peserta.php)
 *
 * Dipakai setelah peserta mengerjakan CBT pretes resmi:
 *  - status 'in_progress' : CBT belum dikumpulkan → tombol "Lanjutkan Ujian"
 *  - status 'submitted'   : nilai < 80  → permohonan maaf + link Pendaftaran Tutorial RPL Gelombang 1
 *                           nilai ≥ 80  → selamat + tugas pretes RPL online (video hafalan 11 kompetensi,
 *                                         upload Google Drive, lapor tutor, batas 22 November 2026)
 */

const PRETES_CBT_MIN_SCORE = 80.0;
const PRETES_RPL_DRIVE_URL = 'https://drive.google.com/drive/folders/1fIJv6JlOL4kM99Bllxdfl1y38f_2MIOF?usp=drive_link';
const PRETES_RPL_TASK_DEADLINE = '22 November 2026';

/**
 * Status CBT resmi peserta untuk pendaftaran tertentu.
 * @return array{status:string, score:?float, correct:?int, total:int, submitted_at:?int}|null  null = belum mulai
 */
function pretesCbtResultFor(PDO $pdo, int $userId, int $registrationId): ?array {
    if ($userId <= 0 || $registrationId <= 0) return null;
    require_once __DIR__ . '/pretes-cbt.php';
    ensurePretesCbtTables($pdo);
    $state = pretesCbtState($pdo, $userId, $registrationId); // memfinalisasi attempt yang sudah lewat waktu
    $a = $state['attempt'] ?? null;
    if (!$a) return null;
    $submittedAt = null;
    if (!empty($a['submitted'])) {
        $stmt = $pdo->prepare('SELECT submitted_at FROM pretes_cbt_attempts WHERE id = ?');
        $stmt->execute([(int)$a['id']]);
        $submittedAt = (int)$stmt->fetchColumn() ?: null;
    }
    return [
        'status' => !empty($a['submitted']) ? 'submitted' : 'in_progress',
        'score' => $a['score'] !== null ? (float)$a['score'] : null,
        'correct' => $a['correct'] !== null ? (int)$a['correct'] : null,
        'total' => (int)($a['total'] ?? 0),
        'submitted_at' => $submittedAt,
    ];
}

function pretesCbtResultPassed(?array $result): bool {
    return $result && $result['status'] === 'submitted' && $result['score'] !== null && $result['score'] >= PRETES_CBT_MIN_SCORE;
}

/** Cetak kartu hasil. $linkUjian = URL halaman ujian; $tutorialUrl = halaman Pendaftaran Tutorial RPL. */
function renderPretesCbtResultCard(array $result, string $linkUjian, string $tutorialUrl): void {
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    if ($result['status'] === 'in_progress'): ?>
        <div id="pretes-cbt-result" data-state="in_progress" style="background:#fffbeb;border:2px solid #fcd34d;border-radius:14px;padding:22px 18px;text-align:center;">
            <span style="font-size:34px;display:block;margin-bottom:6px;">⏳</span>
            <h4 style="margin:0 0 6px;color:#92400e;font-size:16px;font-weight:800;">CBT Pretes Anda Belum Dikumpulkan</h4>
            <p style="margin:0 auto 14px;color:#78350f;font-size:13px;max-width:520px;line-height:1.55;">Anda sudah memulai ujian tetapi belum mengumpulkan jawaban. Lanjutkan sebelum waktu habis; jawaban yang sudah tersimpan tetap aman.</p>
            <a href="<?= $e($linkUjian) ?>" id="btn-lanjutkan-pretes" class="btn btn-success" style="display:inline-flex;align-items:center;gap:8px;padding:12px 28px;font-size:15px;font-weight:800;border-radius:10px;text-decoration:none;">▶ Lanjutkan Ujian</a>
        </div>
    <?php return; endif;

    $score = $result['score'] ?? 0.0;
    $scoreText = rtrim(rtrim(number_format((float)$score, 2, ',', ''), '0'), ',');
    $passed = pretesCbtResultPassed($result);
    // Zona waktu eksplisit (aplikasi tidak menyetel date_default_timezone, label harus benar-benar WIB)
    $when = $result['submitted_at']
        ? (new DateTimeImmutable('@' . (int)$result['submitted_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i') . ' WIB'
        : null;
    ?>
    <div id="pretes-cbt-result" data-state="<?= $passed ? 'passed' : 'failed' ?>"
         style="background:<?= $passed ? '#f0fdf4' : '#fef2f2' ?>;border:2px solid <?= $passed ? '#86efac' : '#fca5a5' ?>;border-radius:14px;padding:22px 20px;">
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
            <div style="width:74px;height:74px;border-radius:50%;display:grid;place-items:center;flex-shrink:0;background:#fff;border:3px solid <?= $passed ? '#16a34a' : '#dc2626' ?>;">
                <div style="text-align:center;line-height:1.05;">
                    <div style="font-size:22px;font-weight:900;color:<?= $passed ? '#15803d' : '#b91c1c' ?>;"><?= $e($scoreText) ?></div>
                    <div style="font-size:9.5px;font-weight:800;color:#64748b;letter-spacing:.05em;">NILAI CBT</div>
                </div>
            </div>
            <div style="flex:1;min-width:200px;">
                <div style="font-size:12px;font-weight:800;letter-spacing:.06em;color:<?= $passed ? '#166534' : '#991b1b' ?>;">✅ CBT PRETES SUDAH DIKERJAKAN</div>
                <div style="font-size:12.5px;color:#475569;margin-top:3px;">
                    <?= $result['correct'] !== null ? $e($result['correct']) . ' benar dari ' . $e($result['total']) . ' soal' : '' ?>
                    <?= $when ? ' · dikumpulkan ' . $e($when) : '' ?> · nilai minimal <?= (int)PRETES_CBT_MIN_SCORE ?>
                </div>
            </div>
        </div>

        <?php if (!$passed): ?>
            <p style="margin:0 0 14px;color:#7f1d1d;font-size:14px;line-height:1.65;font-weight:600;">
                Mohon maaf, nilai CBT Pretes Anda tidak mencapai nilai minimal (<?= (int)PRETES_CBT_MIN_SCORE ?>). Saudara dipersilakan mendaftarkan diri pada Tutorial RPL Gelombang 1 melalui link berikut.
            </p>
            <a href="<?= $e($tutorialUrl) ?>" id="btn-daftar-tutorial-rpl" class="btn btn-danger" style="display:inline-flex;align-items:center;gap:8px;padding:11px 22px;font-size:14px;font-weight:800;border-radius:10px;text-decoration:none;">
                📝 Pendaftaran Tutorial RPL Gelombang 1
            </a>
        <?php else: ?>
            <p style="margin:0 0 12px;color:#14532d;font-size:14px;line-height:1.65;font-weight:700;">
                🎉 Selamat, Saudara diperkenankan melanjutkan ke tahap pretes secara online. Berikut adalah tugas pretes RPL (online):
            </p>
            <ol type="a" id="pretes-rpl-online-tasks" style="margin:0 0 4px;padding-left:22px;color:#1f2937;font-size:13.5px;line-height:1.7;">
                <li>Membuat <strong>video rekaman hafalan</strong> yang jelas dan baik sesuai <strong>11 kompetensi</strong> (yang sudah dijelaskan di halaman pretes, lihat <a href="#pretes-active-preparation" style="color:#15803d;font-weight:700;">materi persiapan di bawah</a>).</li>
                <li>Melakukan <strong>upload ke Google Drive</strong> di alamat berikut:
                    <a href="<?= $e(PRETES_RPL_DRIVE_URL) ?>" id="link-drive-pretes-rpl" target="_blank" rel="noopener noreferrer" style="color:#1d4ed8;font-weight:700;word-break:break-all;">📁 Folder Google Drive Tugas Pretes RPL ↗</a></li>
                <li><strong>Melaporkan ke Tutor</strong> bahwa tugas telah diupload.</li>
                <li>Akhir pengumpulan adalah sampai tanggal <strong><?= $e(PRETES_RPL_TASK_DEADLINE) ?></strong>.</li>
            </ol>
        <?php endif; ?>
    </div>
    <?php
}
