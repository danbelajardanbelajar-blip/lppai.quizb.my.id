<?php
/**
 * Test Suite: Sinkronisasi Nilai Pretes ke Kelola Nilai & Cetak Sertifikat
 * Menguji pemetaan 12 komponen pretes ke 6 komponen sertifikat dan eksekusi fungsi syncPretesToKelolaNilai().
 */

require_once __DIR__ . '/../includes/pretes-helper.php';

$errors = [];
function syncAssert(bool $cond, string $msg, array &$errors): void {
    if (!$cond) {
        $errors[] = $msg;
    }
}

// ── 1. Uji Pemetaan Komponen (mapPretesScoresToCertificate) ───────────────────
$testScores = [
    'praktik_wudlu'       => 84,
    'doa_setelah_wudlu'   => 88,
    'praktik_tayammum'    => 80,
    'niat_mandi_besar'    => 88, // Thaharah = (84+88+80+88)/4 = 85.00
    'niat_jamak_qashar'   => 82,
    'bacaan_shalat_subuh' => 86, // Shalat = (82+86)/2 = 84.00
    'al_bayyinah'         => 90,
    'al_alaq'             => 85,
    'al_dluha'            => 80, // Surat Pendek = (90+85+80)/3 = 85.00
    'istighasah'          => 88, // Amaliyah = 88.00
    'shalat_jenazah'      => 92, // Jenazah = 92.00
    'ujian_tulis'         => 80, // Ujian Tulis = 80.00
];

$mapped = mapPretesScoresToCertificate($testScores);
syncAssert($mapped['thaharah'] === 85.0, 'Thaharah rata-rata harus 85.00, dapat: ' . $mapped['thaharah'], $errors);
syncAssert($mapped['shalat'] === 84.0, 'Shalat rata-rata harus 84.00, dapat: ' . $mapped['shalat'], $errors);
syncAssert($mapped['surat_pendek'] === 85.0, 'Surat pendek rata-rata harus 85.00, dapat: ' . $mapped['surat_pendek'], $errors);
syncAssert($mapped['amaliyah'] === 88.0, 'Amaliyah harus 88.00, dapat: ' . $mapped['amaliyah'], $errors);
syncAssert($mapped['jenazah'] === 92.0, 'Jenazah harus 92.00, dapat: ' . $mapped['jenazah'], $errors);
syncAssert($mapped['ujian_tulis'] === 80.0, 'Ujian tulis harus 80.00, dapat: ' . $mapped['ujian_tulis'], $errors);
syncAssert($mapped['rata_rata'] === 85.67, 'Nilai rata-rata sertifikat harus 85.67, dapat: ' . $mapped['rata_rata'], $errors);

// ── 2. Uji End-to-End Database Sync (syncPretesToKelolaNilai) ─────────────────
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("
    CREATE TABLE master_pretes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        periode VARCHAR(100) NOT NULL,
        tahun_ajaran VARCHAR(50) NOT NULL,
        target_program VARCHAR(50) DEFAULT 'Semua',
        status VARCHAR(20) DEFAULT 'aktif'
    );
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nama_lengkap VARCHAR(255) NOT NULL,
        nim VARCHAR(50) NOT NULL,
        role VARCHAR(50) DEFAULT 'mahasiswa'
    );
    CREATE TABLE pretes_registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        periode VARCHAR(100) NOT NULL,
        hari_pilihan VARCHAR(50),
        status VARCHAR(50) DEFAULT 'terdaftar'
    );
    CREATE TABLE pretes_assessments (
        registration_id INTEGER PRIMARY KEY,
        scores_json TEXT NOT NULL,
        assessed_by INTEGER NOT NULL
    );
    CREATE TABLE tutorial_registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        status VARCHAR(50) DEFAULT 'terdaftar',
        gelombang VARCHAR(50),
        tahun_ajaran VARCHAR(50),
        tipe_nilai VARCHAR(50),
        nomor_sertifikat VARCHAR(100),
        nilai_thaharah REAL,
        nilai_shalat REAL,
        nilai_surat_pendek REAL,
        nilai_amaliyah REAL,
        nilai_jenazah REAL,
        nilai_ujian_tulis REAL,
        nilai_akhir REAL
    );
");

$pdo->exec("INSERT INTO master_pretes (periode, tahun_ajaran, target_program, status) VALUES ('2026/2027 RPL', '2026/2027', 'RPL', 'aktif')");

// User 1: Lulus (Semua 12 komponen >= 80)
$pdo->exec("INSERT INTO users (nama_lengkap, nim) VALUES ('Ahmad Lulus', '2501001')");
$pdo->exec("INSERT INTO pretes_registrations (user_id, periode, hari_pilihan) VALUES (1, '2026/2027 RPL', 'Senin')");
$pdo->exec("INSERT INTO pretes_assessments (registration_id, scores_json, assessed_by) VALUES (1, '" . json_encode($testScores) . "', 99)");

// User 2: Tidak Lulus (ujian_tulis = 75 < 80)
$failScores = $testScores;
$failScores['ujian_tulis'] = 75;
$pdo->exec("INSERT INTO users (nama_lengkap, nim) VALUES ('Budi Gagal', '2501002')");
$pdo->exec("INSERT INTO pretes_registrations (user_id, periode, hari_pilihan) VALUES (2, '2026/2027 RPL', 'Selasa')");
$pdo->exec("INSERT INTO pretes_assessments (registration_id, scores_json, assessed_by) VALUES (2, '" . json_encode($failScores) . "', 99)");

// User 3: Belum Lengkap (Hanya sebagian komponen dinilai)
$incompleteScores = ['praktik_wudlu' => 90, 'ujian_tulis' => 85];
$pdo->exec("INSERT INTO users (nama_lengkap, nim) VALUES ('Citra Menunggu', '2501003')");
$pdo->exec("INSERT INTO pretes_registrations (user_id, periode, hari_pilihan) VALUES (3, '2026/2027 RPL', 'Rabu')");
$pdo->exec("INSERT INTO pretes_assessments (registration_id, scores_json, assessed_by) VALUES (3, '" . json_encode($incompleteScores) . "', 99)");

// User 4: Lulus dan sebelumnya sudah ada record di tutorial_registrations lengkap dengan nomor sertifikat
$pdo->exec("INSERT INTO users (nama_lengkap, nim) VALUES ('Doni Update', '2501004')");
$pdo->exec("INSERT INTO pretes_registrations (user_id, periode, hari_pilihan) VALUES (4, '2026/2027 RPL', 'Kamis')");
$pdo->exec("INSERT INTO pretes_assessments (registration_id, scores_json, assessed_by) VALUES (4, '" . json_encode($testScores) . "', 99)");
$pdo->exec("INSERT INTO tutorial_registrations (user_id, status, gelombang, tahun_ajaran, tipe_nilai, nomor_sertifikat, nilai_thaharah, nilai_shalat, nilai_surat_pendek, nilai_amaliyah, nilai_jenazah, nilai_ujian_tulis) 
            VALUES (4, 'terdaftar', 'pretest', '2025/2026', 'pretest', '5480/U/L.3.11/A.2/XI/2026', 70, 70, 70, 70, 70, 70)");

// Jalankan sinkronisasi
$syncResult = syncPretesToKelolaNilai($pdo, '2026/2027 RPL');

syncAssert($syncResult['success'] === true, 'Hasil sync harus sukses.', $errors);
syncAssert($syncResult['synced'] === 2, 'Jumlah synced harus 2 (User 1 & User 4), dapat: ' . $syncResult['synced'], $errors);
syncAssert($syncResult['inserted'] === 1, 'Jumlah inserted harus 1 (User 1), dapat: ' . $syncResult['inserted'], $errors);
syncAssert($syncResult['updated'] === 1, 'Jumlah updated harus 1 (User 4), dapat: ' . $syncResult['updated'], $errors);
syncAssert($syncResult['skipped'] === 2, 'Jumlah skipped harus 2 (User 2 & User 3), dapat: ' . $syncResult['skipped'], $errors);

// Verifikasi isi tabel tutorial_registrations untuk User 1
$row1 = $pdo->query("SELECT * FROM tutorial_registrations WHERE user_id = 1")->fetch();
syncAssert(!empty($row1), 'User 1 harus masuk ke tutorial_registrations.', $errors);
syncAssert($row1['status'] === 'lulus', 'Status User 1 harus lulus.', $errors);
syncAssert($row1['tipe_nilai'] === 'pretest', 'Tipe nilai User 1 harus pretest.', $errors);
syncAssert($row1['tahun_ajaran'] === '2026/2027', 'Tahun ajaran User 1 harus 2026/2027.', $errors);
syncAssert((float)$row1['nilai_thaharah'] === 85.0, 'Nilai thaharah User 1 harus 85.0.', $errors);
syncAssert((float)$row1['nilai_ujian_tulis'] === 80.0, 'Nilai ujian tulis User 1 harus 80.0.', $errors);

// Verifikasi bahwa nomor sertifikat User 4 tidak hilang/tertimpa
$row4 = $pdo->query("SELECT * FROM tutorial_registrations WHERE user_id = 4")->fetch();
syncAssert(!empty($row4), 'User 4 harus ditemukan di tutorial_registrations.', $errors);
syncAssert($row4['nomor_sertifikat'] === '5480/U/L.3.11/A.2/XI/2026', 'Nomor sertifikat User 4 harus tetap dipertahankan.', $errors);
syncAssert($row4['tahun_ajaran'] === '2026/2027', 'Tahun ajaran User 4 harus diperbarui ke 2026/2027.', $errors);
syncAssert((float)$row4['nilai_thaharah'] === 85.0, 'Nilai thaharah User 4 harus terupdate ke 85.0.', $errors);

// Verifikasi User 2 & User 3 tidak masuk ke tutorial_registrations
$cntFail = (int)$pdo->query("SELECT COUNT(*) FROM tutorial_registrations WHERE user_id IN (2, 3)")->fetchColumn();
syncAssert($cntFail === 0, 'User yang tidak lulus/belum lengkap tidak boleh masuk ke tutorial_registrations.', $errors);

// ── 3. Uji Integrasi UI File PHP ─────────────────────────────────────────────
$root = dirname(__DIR__);
$rekapSrc = file_get_contents($root . '/admin/pretes-rekap-nilai.php');
syncAssert(str_contains($rekapSrc, 'sync_to_kelola_nilai'), 'admin/pretes-rekap-nilai.php harus memiliki action sync_to_kelola_nilai.', $errors);
syncAssert(str_contains($rekapSrc, 'syncPretesToKelolaNilai('), 'admin/pretes-rekap-nilai.php harus memanggil syncPretesToKelolaNilai().', $errors);
syncAssert(str_contains($rekapSrc, 'Sinkronkan ke Kelola Nilai (Sertifikat)'), 'admin/pretes-rekap-nilai.php harus memiliki tombol Sinkronkan ke Kelola Nilai (Sertifikat).', $errors);

$kelolaSrc = file_get_contents($root . '/admin/kelola-nilai.php');
syncAssert(str_contains($kelolaSrc, '$getTa') && str_contains($kelolaSrc, '$getTipe'), 'admin/kelola-nilai.php harus membaca parameter tahun_ajaran dan tipe dari URL.', $errors);

if (!empty($errors)) {
    foreach ($errors as $e) {
        fwrite(STDERR, "[FAIL] $e\n");
    }
    exit(1);
}

echo "OK: pretes-sync-kelola-nilai tests passed (12->6 mapping, DB sync transaction, idempotent update, and UI button verified).\n";
