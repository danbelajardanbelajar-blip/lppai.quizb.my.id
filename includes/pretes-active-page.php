<?php
require_once __DIR__ . '/pretes-helper.php';

/**
 * Both student routes render this exact page for ACTIVE pretes participants only:
 * mahasiswa terdaftar (pretes_registrations) pada periode pretes aktif yang sesuai programnya.
 * Mengembalikan false untuk selain itu, sehingga halaman memakai tampilan Hasil Pretes.
 */
function renderActivePretesParticipantPage(PDO $pdo, array $user): bool {
    if (strtolower((string)($user['role'] ?? 'mahasiswa')) !== 'mahasiswa') return false;
    ensurePretesPeriodTargetColumns($pdo);
    $activePretes = null;
    try {
        $periods = $pdo->query("SELECT * FROM master_pretes WHERE status = 'aktif' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($periods as $period) {
            if (isUserEligibleForPretesPeriod($user, $period)) { $activePretes = $period; break; }
        }
    } catch (PDOException $e) {
        return false;
    }
    if (!$activePretes) return false;

    require_once __DIR__ . '/sync_eujian.php';
    checkAutoSyncEujian($pdo);
    $stmt = $pdo->prepare('SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan AS ruangan_tes
        FROM pretes_registrations pr
        LEFT JOIN pretes_schedules ps ON ps.id = pr.pretes_schedule_id AND ps.periode = pr.periode
        WHERE pr.user_id = ? AND pr.periode = ? ORDER BY pr.id DESC LIMIT 1');
    $stmt->execute([$user['id'], $activePretes['periode']]);
    $myReg = $stmt->fetch(PDO::FETCH_ASSOC);
    // Belum terdaftar di periode aktif → bukan peserta aktif (tampilkan Hasil Pretes)
    if (!$myReg) return false;
    $registeredForActivePretes = (bool)$myReg;
    $stmt = $pdo->prepare("SELECT * FROM pretes_schedules WHERE periode = ? AND status = 'aktif' ORDER BY tanggal ASC, waktu_mulai ASC");
    $stmt->execute([$activePretes['periode']]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Preserve the participant page's exam access rules in both routes.
    $tglSekarang = date('Y-m-d');
    $tglBukaUjian = '2026-11-16';
    $isTesterPrivileged = (string)($user['username'] ?? '') === '12345'
        || (string)($user['nim'] ?? '') === '12345' || (int)$user['id'] === 12345;
    $isUjianDibuka = $tglSekarang >= $tglBukaUjian || $isTesterPrivileged;
    $linkSoalPretes = BASE_URL . '/pretes-ujian.php';

    define('PAGE_TITLE', 'Peserta, Jadwal & Persiapan Pretes');
    include __DIR__ . '/header.php';
    echo '<div id="pretes-active-information" style="max-width:1040px;margin:0 auto">';
    echo '<section id="pretes-active-participant" aria-label="Peserta dan jadwal pretes">';
    include __DIR__ . '/pretes-participant-view.php';
    echo '</section><section id="pretes-active-preparation" aria-label="Persiapan materi pretes">';
    include __DIR__ . '/pretes-preparation-view.php';
    echo '</section></div>';
    include __DIR__ . '/footer.php';
    return true;
}
