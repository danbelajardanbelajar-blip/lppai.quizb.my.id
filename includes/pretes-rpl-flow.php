<?php
/**
 * LPPAI Corner - Alur Pretes RPL berbasis CBT (satu sumber status untuk Hasil Pretes & Peserta Pretes)
 *
 * Tahap (stage):
 *  - none         : belum ada CBT resmi yang dikumpulkan (tampilan lama berlaku)
 *  - cbt_failed   : nilai CBT < 80 → tidak lulus tahap CBT → daftar Tutorial RPL Gelombang 1
 *  - online_task  : nilai CBT ≥ 80, 11 kompetensi belum dinilai lengkap → tugas pretes online (video, Drive, lapor tutor)
 *  - final_passed : 12 komponen lengkap & semua ≥ 80 → LULUS
 *  - final_failed : 12 komponen lengkap & ada < 80 (CBT ≥ 80) → tidak lulus → Tutorial RPL Gelombang 1
 */
require_once __DIR__ . '/pretes-helper.php';
require_once __DIR__ . '/pretes-cbt-result-card.php';

const PRETES_RPL_TASK_DEADLINE_AT = '2026-11-22 23:59:59'; // WIB, batas pengumpulan tugas pretes online
const PRETES_RPL_TUTORIAL_LABEL = 'Pendaftaran Tutorial RPL Gelombang 1';

/**
 * @param array $scores scores_json terdekode (komponen → nilai)
 * @return array{stage:string, cbt:?array, assessed:int, total:int, practical_done:int, practical_total:int,
 *               deadline_ts:int, deadline_passed:bool}
 */
function pretesRplStage(PDO $pdo, int $userId, ?array $registration, array $scores, ?int $now = null): array {
    $now ??= time();
    $components = pretesComponents();
    $deadlineTs = (new DateTimeImmutable(PRETES_RPL_TASK_DEADLINE_AT, new DateTimeZone('Asia/Jakarta')))->getTimestamp();
    $out = ['stage' => 'none', 'cbt' => null, 'assessed' => 0, 'total' => count($components),
        'practical_done' => 0, 'practical_total' => count($components) - 1,
        'deadline_ts' => $deadlineTs, 'deadline_passed' => $now > $deadlineTs];
    $regId = (int)($registration['id'] ?? 0);
    if ($userId <= 0 || $regId <= 0) return $out;

    try {
        $cbt = pretesCbtResultFor($pdo, $userId, $regId);
    } catch (Throwable $e) {
        $cbt = null;
    }
    if (!$cbt || $cbt['status'] !== 'submitted' || $cbt['score'] === null) return $out;
    $out['cbt'] = $cbt;

    // Backfill: nilai CBT sistem belum tercatat sebagai komponen Ujian tulis → tulis sekarang
    if (!isset($scores['ujian_tulis']) || $scores['ujian_tulis'] === '' || !is_numeric($scores['ujian_tulis'])) {
        pretesCbtWriteUjianTulis($pdo, $regId, (float)$cbt['score']);
        $scores['ujian_tulis'] = (float)$cbt['score'];
    }

    $allGood = true;
    foreach ($components as $key => $label) {
        $v = $scores[$key] ?? null;
        $has = $v !== null && $v !== '' && is_numeric($v);
        if ($has) {
            $out['assessed']++;
            if ($key !== 'ujian_tulis') $out['practical_done']++;
            if ((float)$v < 80) $allGood = false;
        }
    }

    if ((float)$cbt['score'] < PRETES_CBT_MIN_SCORE) {
        $out['stage'] = 'cbt_failed';
    } elseif ($out['assessed'] < $out['total']) {
        $out['stage'] = 'online_task';
    } else {
        $out['stage'] = $allGood ? 'final_passed' : 'final_failed';
    }
    return $out;
}

/** Panel tahap RPL untuk halaman Hasil Pretes (cbt_failed / online_task / final_failed). */
function renderPretesRplStagePanel(array $rpl, string $tutorialUrl, string $tutorHtml = ''): void {
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $cbt = $rpl['cbt'];
    $score = $cbt ? rtrim(rtrim(number_format((float)$cbt['score'], 2, ',', ''), '0'), ',') : '-';
    $stage = $rpl['stage'];
    $steps = [
        ['CBT Pretes', $cbt ? 'Nilai ' . $score : '', $stage === 'cbt_failed' ? 'fail' : 'done'],
        ['Upload video hafalan', $stage === 'cbt_failed' ? 'Tidak dilanjutkan' : 'Batas 22 Nov 2026', $stage === 'cbt_failed' ? 'skip' : ($rpl['practical_done'] > 0 || in_array($stage, ['final_passed', 'final_failed'], true) ? 'done' : 'active')],
        ['Penilaian tutor', $stage === 'cbt_failed' ? 'Tidak dilanjutkan' : $rpl['practical_done'] . '/' . $rpl['practical_total'] . ' kompetensi', $stage === 'cbt_failed' ? 'skip' : (in_array($stage, ['final_passed', 'final_failed'], true) ? 'done' : ($rpl['practical_done'] > 0 ? 'active' : 'wait'))],
        ['Hasil akhir', $stage === 'final_passed' ? 'Lulus' : ($stage === 'final_failed' || $stage === 'cbt_failed' ? 'Tidak lulus' : 'Menunggu'), $stage === 'final_passed' ? 'done' : ($stage === 'final_failed' || $stage === 'cbt_failed' ? 'fail' : 'wait')],
    ];
    $stepStyle = [
        'done' => ['#dcfce7', '#16a34a', '✓'], 'active' => ['#fef3c7', '#d97706', '●'],
        'wait' => ['#f1f5f9', '#94a3b8', '○'], 'fail' => ['#fee2e2', '#dc2626', '✕'], 'skip' => ['#fee2e2', '#dc2626', '✕'],
    ];
    ?>
    <div id="pretes-rpl-stage" data-stage="<?= $e($stage) ?>" style="margin:0 0 22px;border-radius:14px;border:1.5px solid <?= $stage === 'online_task' ? '#bfdbfe' : '#fecaca' ?>;background:<?= $stage === 'online_task' ? '#f8fbff' : '#fff7f7' ?>;padding:18px 20px;">
        <div style="font-size:12px;font-weight:800;letter-spacing:.06em;color:#475569;margin-bottom:10px;">ALUR PRETES RPL</div>
        <ol style="list-style:none;margin:0 0 16px;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;">
            <?php foreach ($steps as $i => [$title, $sub, $state]): [$bg, $fg, $icon] = $stepStyle[$state]; ?>
                <li data-step-state="<?= $e($state) ?>" style="display:flex;gap:8px;align-items:center;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:8px 10px;">
                    <span style="width:26px;height:26px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;background:<?= $bg ?>;color:<?= $fg ?>;font-weight:900;font-size:13px;"><?= $icon ?></span>
                    <span style="min-width:0;"><strong style="display:block;font-size:12.5px;color:#0f172a;"><?= $i + 1 ?>. <?= $e($title) ?></strong><span style="font-size:11px;color:#64748b;"><?= $e($sub) ?></span></span>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($stage === 'cbt_failed' || $stage === 'final_failed'): ?>
            <h4 style="margin:0 0 8px;color:#991b1b;font-size:16px;font-weight:800;">❌ <?= $stage === 'cbt_failed' ? 'Tidak Lulus Tahap CBT' : 'Belum Lulus Pretes' ?></h4>
            <p style="margin:0 0 14px;color:#7f1d1d;font-size:14px;line-height:1.65;">
                <?php if ($stage === 'cbt_failed'): ?>
                    Mohon maaf, nilai CBT Pretes Anda (<strong><?= $e($score) ?></strong>) tidak mencapai nilai minimal (<?= (int)PRETES_CBT_MIN_SCORE ?>). Saudara dipersilakan mendaftarkan diri pada Tutorial RPL Gelombang 1 melalui link berikut.
                <?php else: ?>
                    Mohon maaf, terdapat kompetensi yang belum mencapai nilai minimal (<?= (int)PRETES_CBT_MIN_SCORE ?>) pada penilaian tutor. Saudara dipersilakan mendaftarkan diri pada Tutorial RPL Gelombang 1 melalui link berikut.
                <?php endif; ?>
            </p>
            <a href="<?= $e($tutorialUrl) ?>" id="btn-rpl-tutorial" class="btn btn-danger" style="display:inline-flex;align-items:center;gap:8px;padding:11px 22px;font-weight:800;border-radius:10px;text-decoration:none;">📝 <?= $e(PRETES_RPL_TUTORIAL_LABEL) ?></a>
        <?php else: /* online_task */ ?>
            <h4 style="margin:0 0 8px;color:#1e3a8a;font-size:16px;font-weight:800;">📹 Tahap 2 — Tugas Pretes Online</h4>
            <p style="margin:0 0 10px;color:#14532d;font-size:14px;line-height:1.65;font-weight:700;">
                🎉 Selamat, nilai CBT Anda <?= $e($score) ?>. Saudara diperkenankan melanjutkan ke tahap pretes secara online. Berikut adalah tugas pretes RPL (online):
            </p>
            <ol type="a" style="margin:0 0 12px;padding-left:22px;color:#1f2937;font-size:13.5px;line-height:1.7;">
                <li>Membuat <strong>video rekaman hafalan</strong> yang jelas dan baik sesuai <strong>11 kompetensi</strong> (lihat <a href="<?= BASE_URL ?>/pretes-peserta.php#pretes-active-preparation" style="color:#15803d;font-weight:700;">materi pretes</a>).</li>
                <li>Melakukan <strong>upload ke Google Drive</strong>: <a href="<?= $e(PRETES_RPL_DRIVE_URL) ?>" target="_blank" rel="noopener noreferrer" style="color:#1d4ed8;font-weight:700;">📁 Folder Google Drive Tugas Pretes RPL ↗</a></li>
                <li><strong>Melaporkan ke Tutor</strong> bahwa tugas telah diupload<?= $tutorHtml !== '' ? ': ' . $tutorHtml : '.' ?></li>
                <li>Akhir pengumpulan adalah sampai tanggal <strong>22 November 2026</strong>.</li>
            </ol>
            <?php if ($rpl['deadline_passed']): ?>
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 14px;color:#991b1b;font-size:13px;font-weight:700;">⚠️ Batas pengumpulan tugas telah lewat dan penilaian Anda belum lengkap. Segera hubungi tutor atau admin LPPAI.</div>
            <?php else: ?>
                <div id="pretes-rpl-countdown" data-deadline="<?= (int)$rpl['deadline_ts'] ?>" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px 14px;color:#1e3a8a;font-size:13px;font-weight:700;">
                    ⏳ Sisa waktu pengumpulan: <span class="pretes-rpl-countdown-value">menghitung…</span>
                </div>
                <script>
                (function () {
                    var box = document.getElementById('pretes-rpl-countdown');
                    if (!box) return;
                    var end = Number(box.getAttribute('data-deadline')) * 1000, out = box.querySelector('.pretes-rpl-countdown-value');
                    function tick() {
                        var ms = end - Date.now();
                        if (ms <= 0) { out.textContent = 'waktu pengumpulan telah berakhir'; return; }
                        var d = Math.floor(ms / 864e5), h = Math.floor(ms % 864e5 / 36e5), m = Math.floor(ms % 36e5 / 6e4);
                        out.textContent = (d ? d + ' hari ' : '') + h + ' jam ' + m + ' menit (sampai 22 November 2026 pukul 23.59 WIB)';
                        setTimeout(tick, 30000);
                    }
                    tick();
                })();
                </script>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}
