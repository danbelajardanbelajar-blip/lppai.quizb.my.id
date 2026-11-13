<?php
/**
 * LPPAI Corner - Peserta & Jadwal Pretes (Mahasiswa)
 * Menampilkan jadwal pretes, petunjuk pelaksanaan ujian, dan akses pengerjaan soal.
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user = getCurrentUser();
$pdo  = getDBConnection();
require_once __DIR__ . '/../includes/pretes-active-page.php';
if (renderActivePretesParticipantPage($pdo, $user)) return;
define('PAGE_TITLE', 'Peserta & Jadwal Pretes');
require_once __DIR__ . '/../includes/sync_eujian.php';
checkAutoSyncEujian($pdo);

// ── Ambil credentials & registrasi mahasiswa ini ──────────────────────────────
$stmtMyReg = $pdo->prepare("
    SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai, ps.ruangan as ruangan_tes
    FROM pretes_registrations pr
    LEFT JOIN pretes_schedules ps ON pr.periode = ps.periode AND ps.status = 'aktif'
    WHERE pr.user_id = ?
    ORDER BY pr.tanggal_daftar DESC
    LIMIT 1
");
$stmtMyReg->execute([$user['id']]);
$myReg = $stmtMyReg->fetch();

// ── Logika Gating Tanggal Pengerjaan Soal Pretes ───────────────────────────────
// Periode resmi pretes: 16 – 20 November 2026
$tglSekarang        = date('Y-m-d');
$tglBukaUjian       = '2026-11-16';
$isTesterPrivileged = (isset($user['username']) && (string)$user['username'] === '12345') 
                   || (isset($user['nim']) && (string)$user['nim'] === '12345')
                   || ((int)($user['id'] ?? 0) === 12345);
$isUjianDibuka      = ($tglSekarang >= $tglBukaUjian) || $isTesterPrivileged;
$linkSoalPretes     = BASE_URL . '/pretes-ujian.php'; // Mengarahkan ke Portal Ujian Pretes & Pengawas AI (Skenario A & B)

// ── Ambil jadwal pretes aktif untuk informasi sesi ─────────────────────────────
$schedules = $pdo->query(
    "SELECT * FROM pretes_schedules WHERE (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%') ORDER BY tanggal ASC, waktu_mulai ASC"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php include __DIR__ . '/../includes/pretes-participant-view.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
