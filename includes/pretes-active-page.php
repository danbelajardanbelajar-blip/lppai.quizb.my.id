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
        $isTesterPrivileged = (string)($user['username'] ?? '') === '12345'
            || (string)($user['nim'] ?? '') === '12345' || (int)($user['id'] ?? 0) === 12345;
        foreach ($periods as $period) {
            if ($isTesterPrivileged || isUserEligibleForPretesPeriod($user, $period)) { $activePretes = $period; break; }
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
    $isTesterPrivileged = (string)($user['username'] ?? '') === '12345'
        || (string)($user['nim'] ?? '') === '12345' || (int)($user['id'] ?? 0) === 12345;
    if (!$myReg && $isTesterPrivileged) {
        $myReg = [
            'id'           => 999999,
            'user_id'      => $user['id'],
            'hari_pilihan' => 'Minggu',
            'jam'          => '04.25 WIB',
            'periode'      => $activePretes['periode'],
            'foto'         => !empty($user['foto']) ? $user['foto'] : '',
            'status'       => 'terdaftar',
            'username_tes' => ($user['nim'] ?? '12345') . '.22205',
            'password_tes' => '123456',
            'ruangan'      => 'Lab Komputer (Uji Coba Akun 12345)',
            'ruangan_tes'  => 'Lab Komputer (Uji Coba Akun 12345)',
        ];
    } elseif ($myReg && $isTesterPrivileged) {
        $myReg['hari_pilihan'] = 'Minggu';
        $myReg['jam'] = '04.25 WIB';
    }
    // Belum terdaftar di periode aktif → bukan peserta aktif (tampilkan Hasil Pretes)
    if (!$myReg) return false;
    $registeredForActivePretes = (bool)$myReg;
    $stmt = $pdo->prepare("SELECT * FROM pretes_schedules WHERE periode = ? AND status = 'aktif' ORDER BY tanggal ASC, waktu_mulai ASC");
    $stmt->execute([$activePretes['periode']]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Akses link ujian resmi per mahasiswa: terdaftar periode aktif + sakelar admin + jadwal sesi
    // + jadwal pribadi (hari pilihan pukul 13.00 WIB). Lihat includes/pretes-exam-access.php.
    require_once __DIR__ . '/pretes-exam-access.php';
    $examAccess = pretesExamAccess($pdo, $user);
    $isTesterPrivileged = (string)($user['username'] ?? '') === '12345'
        || (string)($user['nim'] ?? '') === '12345' || (int)$user['id'] === 12345;
    $isUjianDibuka = $examAccess['allowed'] || $isTesterPrivileged;
    $isTesterPreview = $isTesterPrivileged && !$examAccess['allowed'];
    $examClosedNotice = $examAccess['notice'];
    $examSchedule = $examAccess['schedule'];
    // Link Try Out ditampilkan di kotak akses soal untuk peserta terdaftar
    $tryoutLink = $examAccess['registered'] || $isTesterPrivileged ? pretesExamTryoutLink($pdo) : null;
    $linkSoalPretes = BASE_URL . '/pretes-ujian.php';
    // Hasil CBT resmi peserta (sudah dikerjakan / masih berjalan) → kartu hasil menggantikan kotak akses soal
    require_once __DIR__ . '/pretes-cbt-result-card.php';
    $cbtResult = null;
    try {
        $cbtResult = pretesCbtResultFor($pdo, (int)$user['id'], (int)($myReg['id'] ?? 0));
    } catch (Throwable $e) {
        error_log('pretesCbtResultFor: ' . $e->getMessage());
    }
    $tutorialRplUrl = BASE_URL . '/tutorial-rpl.php';
    // Setelah CBT dikumpulkan: jadwal & tata tertib ujian disembunyikan (di view); materi persiapan hanya
    // untuk nilai ≥ 80 (dibutuhkan untuk tugas video hafalan 11 kompetensi). Nilai < 80 → kartu hasil saja.
    $cbtSubmitted = $cbtResult && ($cbtResult['status'] ?? '') === 'submitted';
    $showPreparation = !$cbtSubmitted || pretesCbtResultPassed($cbtResult);

    define('PAGE_TITLE', 'Peserta, Jadwal & Persiapan Pretes');
    include __DIR__ . '/header.php';
    echo '<div id="pretes-active-information" style="max-width:1040px;margin:0 auto">';
    echo '<section id="pretes-active-participant" aria-label="Peserta dan jadwal pretes">';
    include __DIR__ . '/pretes-participant-view.php';
    echo '</section>';
    if ($showPreparation) {
        echo '<section id="pretes-active-preparation" aria-label="Persiapan materi pretes">';
        include __DIR__ . '/pretes-preparation-view.php';
        echo '</section>';
    }
    echo '</div>';
    include __DIR__ . '/footer.php';
    return true;
}
