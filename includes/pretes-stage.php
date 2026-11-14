<?php
/**
 * LPPAI Corner - Status Tahap Pretes Mahasiswa (dipakai bersama)
 *
 * Menentukan apakah mahasiswa sedang berada di tahap pretes (periode pretes aktif,
 * hasil belum final) dan menyediakan kartu "Tahap Pretes" yang sama di halaman:
 * tutorial-pengumuman, tutorial-pendaftaran, tutorial-info, rekap-nilai, ambil-sertifikat.
 */
require_once __DIR__ . '/pretes-helper.php';

if (!function_exists('getStudentPretesStage')) {
    /**
     * @return array{
     *   state:string, reg:?array, period:?array, filled:int, total:int, tanggal:string,
     *   tutorialReg:?array, isTutorialLulus:bool, isTutorialOngoing:bool,
     *   showOngoing:bool, showLulus:bool
     * }
     * state: none | berlangsung | lulus | tidak_lulus
     */
    function getStudentPretesStage(PDO $pdo, array $user): array
    {
        $stage = [
            'state' => 'none', 'reg' => null, 'period' => null,
            'filled' => 0, 'total' => count(pretesComponents()), 'tanggal' => '',
            'tutorialReg' => null, 'isTutorialLulus' => false, 'isTutorialOngoing' => false,
            'showOngoing' => false, 'showLulus' => false,
        ];
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return $stage;

        // Pendaftaran tutorial terakhir
        try {
            $stmt = $pdo->prepare("SELECT status, nomor_sertifikat FROM tutorial_registrations WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$userId]);
            $tr = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare("SELECT status FROM tutorial_registrations WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$userId]);
                $tr = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e2) {
                $tr = null;
            }
        }
        $stage['tutorialReg'] = $tr;
        $stage['isTutorialLulus'] = $tr && (!empty($tr['nomor_sertifikat']) || ($tr['status'] ?? '') === 'lulus');
        $stage['isTutorialOngoing'] = $tr && in_array($tr['status'] ?? '', ['terdaftar', 'aktif'], true);

        try {
            $stmtPr = $pdo->prepare("
                SELECT pr.*, pa.scores_json
                FROM pretes_registrations pr
                LEFT JOIN pretes_assessments pa ON pa.registration_id = pr.id
                WHERE pr.user_id = ?
                ORDER BY pr.id DESC
                LIMIT 1
            ");
            $stmtPr->execute([$userId]);
            $reg = $stmtPr->fetch(PDO::FETCH_ASSOC) ?: null;
            $stage['reg'] = $reg;

            if (isUserLulusPretes($pdo, $userId)) {
                $stage['state'] = 'lulus';
            } elseif ($reg) {
                $scores = json_decode($reg['scores_json'] ?? '{}', true);
                foreach (pretesComponents() as $ck => $cl) {
                    $v = is_array($scores) ? ($scores[$ck] ?? null) : null;
                    if ($v !== null && $v !== '' && is_numeric($v)) $stage['filled']++;
                }
                $rawStatus = getPretesStatusLulus($scores);

                $resultStatus = '';
                try {
                    $stmtRes = $pdo->prepare("SELECT status_lulus FROM pretes_results WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                    $stmtRes->execute([$userId]);
                    $resultStatus = strtolower(trim((string)$stmtRes->fetchColumn()));
                } catch (Throwable $e) {}

                $stmtMp = $pdo->prepare("SELECT * FROM master_pretes WHERE periode = ? ORDER BY id DESC LIMIT 1");
                $stmtMp->execute([$reg['periode'] ?? '']);
                $period = $stmtMp->fetch(PDO::FETCH_ASSOC) ?: null;
                $stage['period'] = $period;
                $periodActive = $period && ($period['status'] ?? '') === 'aktif';

                if ($rawStatus === 'tidak_lulus' || $resultStatus === 'tidak_lulus') {
                    $stage['state'] = 'tidak_lulus';
                } elseif ($periodActive) {
                    $stage['state'] = 'berlangsung';
                } else {
                    // Periode pretes sudah ditutup tanpa nilai lengkap → dianggap belum lulus
                    $stage['state'] = 'tidak_lulus';
                }
            }
        } catch (Throwable $e) {
            $stage['state'] = 'none';
        }

        if ($stage['reg']) {
            $hariP = trim((string)($stage['reg']['hari_pilihan'] ?? ''));
            if ($stage['period']) {
                $dayInfo = getPretesPeriodDayLabels($stage['period']);
                $stage['tanggal'] = $dayInfo['days'][$hariP] ?? $hariP;
            } else {
                $stage['tanggal'] = $hariP;
            }
        }

        // Prioritas: lulus tutorial > lulus pretes > tutorial berjalan > pretes berlangsung
        $stage['showLulus']   = !$stage['isTutorialLulus'] && $stage['state'] === 'lulus';
        $stage['showOngoing'] = !$stage['isTutorialLulus'] && !$stage['isTutorialOngoing'] && $stage['state'] === 'berlangsung';
        return $stage;
    }
}

if (!function_exists('renderPretesStageCard')) {
    /** Kartu "Tahap Pretes" (tampilan seragam). $note = kalimat konteks halaman (HTML aman). */
    function renderPretesStageCard(array $stage, string $note = ''): void
    {
        $reg = $stage['reg'] ?? [];
        $total = (int)$stage['total'];
        $filled = (int)$stage['filled'];
        $pct = $total > 0 ? round($filled / $total * 100) : 0;
        $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        ?>
<div class="card" style="max-width: 700px; margin: 0 auto; overflow: hidden; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
    <div style="background: linear-gradient(135deg, #1e3a8a, #2563eb); color: white; padding: 30px 20px; text-align: center;">
        <h2 style="margin: 0; font-size: 26px; color:#fff;">📝 TAHAP PRETES</h2>
        <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 16px;">Lembaga Pengembangan Pendidikan Agama Islam</p>
    </div>
    <div style="padding: 34px 30px; text-align: center;">
        <h3 style="color: #1e3a8a; margin: 0 0 10px;">Anda Sedang Mengikuti Pretes</h3>
        <p style="color: #4b5563; font-size: 16px; margin: 0 0 20px; line-height:1.6;">
            Status kelulusan Anda ditentukan oleh hasil Pretes <strong><?= $e($reg['periode'] ?? '') ?></strong>.
            Anda <strong>belum perlu mendaftar Tutorial</strong> sampai hasil pretes diumumkan.
            <?php if ($note !== ''): ?><br><span style="color:#64748b; font-size:14.5px;"><?= $note ?></span><?php endif; ?>
        </p>

        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; text-align:left; margin-bottom:18px;">
            <div style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:10px; padding:12px;">
                <div style="font-size:11px; font-weight:800; color:#64748b; letter-spacing:.05em;">🗓️ JADWAL</div>
                <div style="font-weight:700; color:#0f172a; margin-top:4px;"><?= $e($stage['tanggal'] ?: '-') ?><?= !empty($reg['jam']) ? '<br><span style="font-weight:600;color:#475569;font-size:13.5px;">' . $e($reg['jam']) . '</span>' : '' ?></div>
            </div>
            <div style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:10px; padding:12px;">
                <div style="font-size:11px; font-weight:800; color:#64748b; letter-spacing:.05em;">📍 RUANGAN</div>
                <div style="font-weight:700; color:#0f172a; margin-top:4px;"><?= $e(($reg['ruangan'] ?? '') ?: 'Menunggu plotting') ?></div>
            </div>
            <div style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:10px; padding:12px;">
                <div style="font-size:11px; font-weight:800; color:#64748b; letter-spacing:.05em;">👨‍🏫 PENGUJI</div>
                <div style="font-weight:700; color:#0f172a; margin-top:4px;"><?= (function_exists('renderDosenWithWa') && function_exists('getDBConnection')) ? renderDosenWithWa(getDBConnection(), $reg['dosen_penguji'] ?? null, null, 'Konfirmasi Ujian Pretes', 'Menunggu plotting') : $e(($reg['dosen_penguji'] ?? '') ?: 'Menunggu plotting') ?></div>
            </div>
            <div style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:10px; padding:12px;">
                <div style="font-size:11px; font-weight:800; color:#64748b; letter-spacing:.05em;">📊 PENILAIAN</div>
                <div style="font-weight:700; color:#0f172a; margin-top:4px;"><?= $filled ?> / <?= $total ?> komponen</div>
                <div style="height:6px; background:#e5e7eb; border-radius:999px; margin-top:8px; overflow:hidden;"><div style="height:100%; width:<?= $pct ?>%; background:#2563eb;"></div></div>
            </div>
        </div>

        <div style="text-align:left; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:16px 18px; margin-bottom:24px; color:#1e3a8a; font-size:14.5px; line-height:1.6;">
            <strong>📌 Ketentuan setelah pretes:</strong>
            <ul style="margin:8px 0 0 18px; padding:0;">
                <li><strong>Lulus</strong> jika seluruh <?= $total ?> komponen bernilai minimal <strong>80</strong> → dibebaskan dari Tutorial dan lanjut ke tahap pengambilan sertifikat.</li>
                <li><strong>Belum lulus</strong> (ada komponen di bawah 80) → wajib mendaftar <strong>Tutorial LPPAI</strong> pada gelombang berikutnya.</li>
            </ul>
        </div>

        <div style="display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
            <a href="<?= BASE_URL ?>/pretes-peserta.php" class="btn btn-primary" style="padding: 10px 22px; text-decoration:none;">📋 Peserta &amp; Jadwal Pretes</a>
            <a href="<?= BASE_URL ?>/pretes-hasil.php" class="btn btn-secondary" style="padding: 10px 22px; text-decoration:none;">📊 Hasil Pretes</a>
            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary" style="padding: 10px 22px; text-decoration:none;">🏠 Dashboard</a>
        </div>
    </div>
</div>
        <?php
    }
}

if (!function_exists('pretesStageGate')) {
    /**
     * Untuk halaman tutorial/sertifikat mahasiswa: bila mahasiswa sedang di tahap pretes
     * (periode aktif, hasil belum final), tampilkan kartu Tahap Pretes lalu hentikan halaman.
     * Juga menolak POST (mis. submit pendaftaran tutorial) selama tahap pretes.
     */
    function pretesStageGate(PDO $pdo, ?array $user, string $note = ''): void
    {
        if (!$user || ($user['role'] ?? 'mahasiswa') !== 'mahasiswa') return;
        $stage = getStudentPretesStage($pdo, $user);
        $isPretesAktif = function_exists('isMahasiswaPretesAktif')
            ? isMahasiswaPretesAktif($pdo, $user)
            : !empty($stage['showOngoing']);
        if (!$isPretesAktif && empty($stage['showOngoing'])) return;

        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Anda sedang berada di tahap Pretes. Fitur ini belum tersedia.']);
            exit;
        }

        include __DIR__ . '/header.php';
        renderPretesStageCard($stage, $note);
        include __DIR__ . '/footer.php';
        exit;
    }
}
