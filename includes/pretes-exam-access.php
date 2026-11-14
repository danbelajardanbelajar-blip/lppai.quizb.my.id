<?php
/**
 * LPPAI Corner - Aturan Akses Ujian CBT Pretes RESMI per Mahasiswa (satu sumber kebenaran)
 *
 * Mahasiswa boleh memulai ujian resmi hanya bila SEMUA terpenuhi:
 *  1. Role mahasiswa, terdaftar pada periode pretes AKTIF yang sesuai target programnya (RPL/Reguler/Semua).
 *  2. Belum dinyatakan lulus pretes.
 *  3. Sakelar admin "Buka Ujian" terbuka & jadwal sesi resmi (mulai/batas) terpenuhi (pretesCbtExamStatus).
 *  4. Sudah masuk jadwal pribadinya: tanggal hari pilihan (dari master_pretes.tanggal_mulai + hari_pilihan)
 *     mulai jam pendaftaran (default 13.00 WIB / Jam 1) sampai akhir hari tersebut.
 *
 * Dipakai oleh: halaman peserta (link), dashboard, pages/pretes-ujian.php, api/pretes-cbt.php, tryout.
 */
require_once __DIR__ . '/pretes-helper.php';
require_once __DIR__ . '/pretes-cbt.php';

const PRETES_EXAM_DEFAULT_TIME = '13:00';
const PRETES_EXAM_TZ = 'Asia/Jakarta';

/** Offset hari dari tanggal_mulai (Senin = 0 ... Jumat = 4), sama dengan getPretesPeriodDayLabels(). */
function pretesExamDayOffset(string $hari): ?int {
    $map = ['senin' => 0, 'selasa' => 1, 'rabu' => 2, 'kamis' => 3, 'jumat' => 4, "jum'at" => 4, 'sabtu' => 5, 'minggu' => 6, 'ahad' => 6];
    $key = strtolower(trim($hari));
    return $map[$key] ?? null;
}

/** "13.00 WIB (Jam 1)" → [13, 0]; tidak terbaca → 13.00. */
function pretesExamParseTime(?string $jam): array {
    if ($jam !== null && preg_match('/(\d{1,2})[.:](\d{2})/', $jam, $m)) {
        $h = (int)$m[1]; $i = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) return [$h, $i];
    }
    [$h, $i] = array_map('intval', explode(':', PRETES_EXAM_DEFAULT_TIME));
    return [$h, $i];
}

/** Jadwal pribadi ujian: ['opens_at'=>ts, 'closes_at'=>ts, 'label'=>"Senin, 16 November 2026 pukul 13.00 WIB"] atau null. */
function pretesExamPersonalSchedule(?array $period, ?array $registration): ?array {
    if (!$period || !$registration) return null;
    $start = trim((string)($period['tanggal_mulai'] ?? ''));
    $offset = pretesExamDayOffset((string)($registration['hari_pilihan'] ?? ''));
    if ($offset === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) return null;
    $tz = new DateTimeZone(PRETES_EXAM_TZ);
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $start, $tz);
    if (!$day) return null;
    $day = $day->modify("+{$offset} days");
    [$h, $i] = pretesExamParseTime($registration['jam'] ?? null);
    $opens = $day->setTime($h, $i);
    $closes = $day->setTime(23, 59, 59);
    $months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $label = trim((string)$registration['hari_pilihan']) . ', ' . (int)$day->format('j') . ' ' . $months[(int)$day->format('n')] . ' ' . $day->format('Y')
        . ' pukul ' . sprintf('%02d.%02d', $h, $i) . ' WIB';
    return ['opens_at' => $opens->getTimestamp(), 'closes_at' => $closes->getTimestamp(), 'label' => $label];
}

/** Periode pretes aktif yang sesuai program mahasiswa (null bila tidak ada). */
function pretesExamActivePeriodFor(PDO $pdo, array $user): ?array {
    try {
        ensurePretesPeriodTargetColumns($pdo);
        foreach ($pdo->query("SELECT * FROM master_pretes WHERE status = 'aktif' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) as $period) {
            if (isUserEligibleForPretesPeriod($user, $period)) return $period;
        }
    } catch (Throwable $e) {}
    return null;
}

/** Pendaftaran mahasiswa pada periode pretes aktif yang sesuai (null bila tidak terdaftar). */
function pretesExamActiveRegistration(PDO $pdo, array $user): ?array {
    $period = pretesExamActivePeriodFor($pdo, $user);
    if (!$period || (int)($user['id'] ?? 0) <= 0) return null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM pretes_registrations WHERE user_id = ? AND periode = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([(int)$user['id'], $period['periode']]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC);
        return $reg ? ($reg + ['_period' => $period]) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Mahasiswa terdaftar pada periode pretes aktif (syarat melihat link ujian & tryout). */
function pretesExamIsRegisteredActive(PDO $pdo, ?array $user): bool {
    if (!$user || strtolower((string)($user['role'] ?? 'mahasiswa')) !== 'mahasiswa') return false;
    return pretesExamActiveRegistration($pdo, $user) !== null;
}

/**
 * Status akses ujian resmi untuk satu mahasiswa.
 * @return array{allowed:bool, reason:string, notice:string, registered:bool, registration:?array, period:?array, schedule:?array}
 * reason: open | not_student | no_period | not_registered | already_passed | closed | no_session | not_started | ended | before_schedule | after_schedule
 */
function pretesExamAccess(PDO $pdo, array $user, ?int $now = null): array {
    $now ??= time();
    $base = ['allowed' => false, 'registered' => false, 'registration' => null, 'period' => null, 'schedule' => null];
    if (strtolower((string)($user['role'] ?? 'mahasiswa')) !== 'mahasiswa') {
        return $base + ['reason' => 'not_student', 'notice' => 'Ujian CBT pretes hanya untuk mahasiswa peserta pretes.'];
    }
    $period = pretesExamActivePeriodFor($pdo, $user);
    if (!$period) {
        return $base + ['reason' => 'no_period', 'notice' => 'Saat ini tidak ada periode pretes yang sedang berlangsung untuk program Anda.'];
    }
    $reg = pretesExamActiveRegistration($pdo, $user);
    if (!$reg) {
        return ['period' => $period] + $base + ['reason' => 'not_registered',
            'notice' => 'Anda tidak terdaftar pada periode pretes yang sedang berlangsung (' . $period['periode'] . '). Ujian CBT hanya untuk peserta yang terdaftar.'];
    }
    unset($reg['_period']);
    $schedule = pretesExamPersonalSchedule($period, $reg);
    $out = ['registered' => true, 'registration' => $reg, 'period' => $period, 'schedule' => $schedule, 'allowed' => false];
    if (isUserLulusPretes($pdo, (int)$user['id'])) {
        return $out + ['reason' => 'already_passed', 'notice' => 'Anda sudah dinyatakan lulus pretes, sehingga tidak perlu mengikuti ujian CBT lagi.'];
    }
    $status = pretesCbtExamStatus($pdo, $now);
    if (!$status['open']) {
        $notice = $status['notice'];
        if ($schedule) $notice .= ' Jadwal ujian Anda: ' . $schedule['label'] . '.';
        return $out + ['reason' => $status['reason'], 'notice' => $notice];
    }
    if ($schedule && $now < $schedule['opens_at']) {
        return $out + ['reason' => 'before_schedule', 'notice' => 'Ujian CBT pretes Anda dijadwalkan ' . $schedule['label'] . ' (sesuai hari pilihan Anda). Link akan aktif otomatis pada waktu tersebut.'];
    }
    if ($schedule && $now > $schedule['closes_at']) {
        return $out + ['reason' => 'after_schedule', 'notice' => 'Jadwal ujian CBT pretes Anda (' . $schedule['label'] . ') telah berakhir. Silakan hubungi admin LPPAI bila Anda belum mengerjakan.'];
    }
    return ['allowed' => true, 'reason' => 'open'] + $out + ['notice' => 'Ujian CBT pretes Anda sudah dibuka. Silakan kerjakan sekarang.'];
}

/** Sesi tryout aktif untuk link cepat (null bila tidak ada). Bila hanya satu, link langsung ke sesinya. */
function pretesExamTryoutLink(PDO $pdo): ?array {
    try {
        require_once __DIR__ . '/pretes-tryout.php';
        $sessions = pretesTryoutActiveSessions($pdo);
    } catch (Throwable $e) {
        return null;
    }
    if (!$sessions) return null;
    $url = BASE_URL . '/tryout.php' . (count($sessions) === 1 ? '?sesi=' . (int)$sessions[0]['id'] : '');
    return ['url' => $url, 'count' => count($sessions), 'title' => count($sessions) === 1 ? $sessions[0]['title'] : 'Tryout CBT Pretes'];
}
