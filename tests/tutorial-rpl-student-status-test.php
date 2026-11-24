<?php
/**
 * Test Suite: Tampilan Role Mahasiswa RPL (Lulus vs Tidak Lulus) & Penanganan Pretes Tanpa Remedial
 */
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
require_once __DIR__ . '/../includes/tutorial-rpl-registration.php';
require_once __DIR__ . '/../includes/tutorial-rpl-registration-policy.php';

$errors = [];
function trAssert(bool $cond, string $msg, array &$errors): void {
    if (!$cond) {
        $errors[] = $msg;
        echo "❌ FAIL: $msg\n";
    } else {
        echo "✅ PASS: $msg\n";
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// 1. Skema Database In-Memory
ensureTutorialRplTables($pdo);
ensureTutorialRplRegistrationTable($pdo);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY,
        nama_lengkap TEXT,
        nim TEXT,
        role TEXT,
        jenis_program TEXT
    );
    INSERT INTO users VALUES (1, 'Admin LPPAI', 'ADM01', 'admin', 'Reguler');
    INSERT INTO users VALUES (10, 'Mhs RPL Lulus', 'RPL001', 'mahasiswa', 'RPL');
    INSERT INTO users VALUES (11, 'Mhs RPL Tidak Lulus', 'RPL002', 'mahasiswa', 'RPL');
    INSERT INTO users VALUES (12, 'Mhs RPL Proses', 'RPL003', 'mahasiswa', 'RPL');
    INSERT INTO users VALUES (20, 'Dosen Tutor', 'DOS01', 'dosen', 'RPL');
");

// Buat Kelas Tutorial RPL
$pdo->exec("
    INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, gelombang, dosen_id, dosen_nama, dosen_wa, drive_url, is_active)
    VALUES (1, 'Kelompok RPL 01', 'Tutorial RPL 2026/2027', 'Gelombang 1', 20, 'Dosen Tutor', '08123456789', 'https://drive.google.com/test', 1);
");

// Anggota 10 (Lulus)
$pdo->exec("
    INSERT INTO tutorial_rpl_members (id, class_id, user_id, drive_submitted, drive_verified, exam_submitted, nilai_ujian_tulis, nilai_praktik, nilai_akhir, status_lulus)
    VALUES (1, 1, 10, 1, 1, 1, 90.0, 85.0, 87.5, 'lulus');
");

// Anggota 11 (Tidak Lulus meskipun hadir lengkap)
$pdo->exec("
    INSERT INTO tutorial_rpl_members (id, class_id, user_id, drive_submitted, drive_verified, exam_submitted, nilai_ujian_tulis, nilai_praktik, nilai_akhir, status_lulus)
    VALUES (2, 1, 11, 1, 1, 1, 60.0, 65.0, 62.5, 'tidak_lulus');
");

// Anggota 12 (Proses)
$pdo->exec("
    INSERT INTO tutorial_rpl_members (id, class_id, user_id, drive_submitted, drive_verified, exam_submitted, nilai_ujian_tulis, nilai_praktik, nilai_akhir, status_lulus)
    VALUES (3, 1, 12, 0, 0, 0, NULL, NULL, NULL, 'proses');
");

echo "=== TEST 1: Fungsi Helper tutorialRplIsUserLulus ===\n";
trAssert(tutorialRplIsUserLulus($pdo, 10) === true, 'Mahasiswa 10 (status_lulus = lulus) terdeteksi lulus', $errors);
trAssert(tutorialRplIsUserLulus($pdo, 11) === false, 'Mahasiswa 11 (tidak_lulus) terdeteksi belum lulus', $errors);
trAssert(tutorialRplIsUserLulus($pdo, 12) === false, 'Mahasiswa 12 (proses) terdeteksi belum lulus', $errors);
trAssert(tutorialRplIsUserLulus($pdo, 999) === false, 'Mahasiswa nonexistent terdeteksi false', $errors);

echo "\n=== TEST 2: Kebijakan Pendaftaran Tutorial RPL Bagi yang Sudah Lulus ===\n";
$mhsLulus = ['id' => 10, 'role' => 'mahasiswa', 'jenis_program' => 'RPL', 'already_passed_rpl' => true];
$mhsGagal = ['id' => 11, 'role' => 'mahasiswa', 'jenis_program' => 'RPL', 'already_passed_rpl' => false];
$decisionLulus = tutorialRplRegistrationDecision($mhsLulus, $mhsLulus, 'mandiri', null, null, null);
trAssert($decisionLulus['allowed'] === false, 'Mahasiswa yang sudah lulus RPL tidak diizinkan mendaftar', $errors);
trAssert($decisionLulus['exempt'] === true, 'Status pendaftaran mahasiswa lulus adalah exempt', $errors);
trAssert($decisionLulus['reason'] === 'already_passed_tutorial_rpl', 'Alasan penolakan adalah already_passed_tutorial_rpl', $errors);
trAssert(str_contains(tutorialRplRegistrationReason($decisionLulus['reason']), 'LULUS'), 'Pesan alasan memuat keterangan kelulusan', $errors);

echo "\n=== TEST 3: Verifikasi Tampilan pages/tutorial-rpl.php ===\n";
$hubSrc = file_get_contents(__DIR__ . '/../pages/tutorial-rpl.php');
trAssert(str_contains($hubSrc, 'Alhamdulillah! Anda Dinyatakan LULUS Tutorial RPL Online'), 'Hub memuat banner selebrasi kelulusan resmi', $errors);
trAssert(str_contains($hubSrc, 'badge-success') && str_contains($hubSrc, '✅ LULUS'), 'Hub memformat status lulus dengan badge hijau', $errors);
trAssert(str_contains($hubSrc, 'badge-danger') && str_contains($hubSrc, '❌ TIDAK LULUS'), 'Hub memformat status tidak lulus dengan badge merah (bukan Tidak_lulus)', $errors);
trAssert(!str_contains($hubSrc, 'ucfirst($membership[\'status_lulus\'])'), 'Hub tidak lagi memakai format underscore kasar ucfirst()', $errors);
trAssert(str_contains($hubSrc, '$isSudahDaftarRpl = ($membership !== null) || (!empty($existingRegistration));'), 'Hub membersihkan card pendaftaran jika mahasiswa sudah daftar Tutorial RPL', $errors);
trAssert(str_contains($hubSrc, 'Presensi Sah &amp; Lengkap:</strong> Seluruh kewajiban tugas video Google Drive dan Ujian Tulis CBT telah Anda serahkan. Namun akumulasi Nilai Akhir Anda belum mencapai standar kelulusan'), 'Hub menjelaskan pemisahan presensi vs kelulusan saat tidak lulus', $errors);

// Verifikasi 3 Opsi Tugas Bagi yang Tidak Lulus
trAssert(str_contains($hubSrc, 'Pilihan Tugas Pemenuhan Kelulusan Tutorial RPL'), 'Hub memuat panel 3 pilihan tugas remedial kelulusan', $errors);
trAssert(str_contains($hubSrc, 'Opsi A') && str_contains($hubSrc, 'Mengerjakan Soal CBT (Min. 70)'), 'Hub memuat Opsi A: Mengerjakan soal CBT minimal 70', $errors);
trAssert(str_contains($hubSrc, 'Opsi B') && str_contains($hubSrc, 'Ujian Praktik di Depan Dosen'), 'Hub memuat Opsi B: Menemui dosen untuk ujian 6 poin di depan dosen', $errors);
trAssert(str_contains($hubSrc, 'Opsi C') && str_contains($hubSrc, 'Rekaman Video di Google Drive'), 'Hub memuat Opsi C: Ujian praktik 6 poin direkam dan diupload ke Google Drive', $errors);
trAssert(str_contains($hubSrc, 'bersifat pilihan untuk mempermudah mahasiswa RPL'), 'Hub menegaskan 3 tugas bersifat pilihan mempermudah mahasiswa', $errors);
trAssert(str_contains($hubSrc, 'Jika nilai CBT masih di bawah 70, maka <strong>tidak ada pilihan lain selain ujian praktik</strong>'), 'Hub menegaskan jika CBT di bawah 70 wajib memilih ujian praktik online/offline', $errors);
trAssert(str_contains($hubSrc, 'Rincian 6 Poin Tugas Ujian Praktik Ibadah'), 'Hub memuat rincian 6 poin tugas ibadah', $errors);

echo "\n=== TEST 4: Verifikasi Sidebar Mahasiswa RPL (includes/sidebar.php) ===\n";
$sidebarSrc = file_get_contents(__DIR__ . '/../includes/sidebar.php');
trAssert(str_contains($sidebarSrc, '$isRplStudent = strtoupper(trim((string)($currentUser[\'jenis_program\'] ?? \'\'))) === \'RPL\';'), 'Sidebar mendeteksi program RPL mahasiswa', $errors);
trAssert(str_contains($sidebarSrc, '$showTutorialMenus = !$isRplStudent && !$isMahasiswaPretesAktif'), 'Sidebar mengecualikan menu Tutorial Reguler untuk mahasiswa RPL', $errors);
trAssert(str_contains($sidebarSrc, 'tutorialRplIsUserLulus($db, (int)($currentUser[\'id\'] ?? 0))'), 'Sidebar memeriksa status kelulusan Tutorial RPL', $errors);
trAssert(str_contains($sidebarSrc, '<?php if (!$isRplLulus): ?>') && str_contains($sidebarSrc, 'tutorial-rpl-daftar.php'), 'Sidebar menyembunyikan link Daftar Tutorial RPL bagi yang sudah lulus', $errors);

echo "\n=== TEST 5: Pretes Tidak Lulus Mengarah ke Pendaftaran Pretes Tanpa Remedial ===\n";
$dashboardSrc = file_get_contents(__DIR__ . '/../pages/dashboard.php');
trAssert(str_contains($dashboardSrc, 'Tidak ada opsi remedial. Anda dipersilakan untuk melakukan <strong>Pendaftaran Pretes</strong> kembali.'), 'Dashboard menyatakan tidak ada remedial pretes', $errors);
trAssert(str_contains($dashboardSrc, 'href="<?= BASE_URL ?>/pretes-daftar.php" class="btn btn-danger"') && str_contains($dashboardSrc, 'Daftar Pretes ➔'), 'Dashboard mengarahkan langsung ke pretes-daftar.php', $errors);

$pretesHasilSrc = file_get_contents(__DIR__ . '/../pages/pretes-hasil.php');
trAssert(str_contains($pretesHasilSrc, 'tidak ada opsi remedial. Anda dapat langsung mendaftarkan diri kembali pada <strong>Pendaftaran Pretes</strong>.'), 'pretes-hasil.php menegaskan tidak ada remedial', $errors);
trAssert(str_contains($pretesHasilSrc, 'href="<?= BASE_URL ?>/pretes-daftar.php"'), 'pretes-hasil.php menautkan ke pretes-daftar.php', $errors);

if (!empty($errors)) {
    echo "\n❌ TEST SUITE FAILED with " . count($errors) . " errors.\n";
    exit(1);
} else {
    echo "\n🎉 ALL TESTS PASSED SUCCESSFULLY! (100%)\n";
    exit(0);
}
