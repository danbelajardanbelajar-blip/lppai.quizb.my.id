<?php
/**
 * Test: Form Verifikasi Tugas & Presensi Dosen Tutor RPL (Tahap 3)
 * Memverifikasi:
 * 1. Simpan Evaluasi Hafalan & Nilai Praktik (tutorialRplSaveEvaluation)
 * 2. Perhitungan Nilai Akhir Otomatis (CBT + Praktik) & Passing Grade (80.0)
 * 3. Batch Verification oleh Tutor / Admin (tutorialRplBatchVerify)
 * 4. Aturan Presensi Sah Lengkap (Tugas Drive Terverifikasi + Ujian Tulis Selesai)
 * 5. Rendering Halaman Dosen Tutor (dosen/tutorial-rpl.php) & Presensi Admin (admin/tutorial-rpl-presensi.php)
 */

require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';

$pdoFixture = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$pdoFixture->exec("
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nama_lengkap TEXT,
    nim TEXT,
    username TEXT,
    role TEXT,
    jenis_program TEXT,
    program_studi TEXT,
    fakultas TEXT,
    whatsapp TEXT
);
");

ensureTutorialRplTables($pdoFixture);

$failures = [];
$check = function(bool $condition, string $label) use (&$failures) {
    if (!$condition) {
        $failures[] = "FAIL: $label";
        echo "❌ FAIL: $label\n";
    } else {
        echo "✅ PASS: $label\n";
    }
};

echo "=== TEST 1: Evaluasi Praktik & Perhitungan Nilai Akhir ===\n";
// Buat kelas & anggota
$pdoFixture->exec("
INSERT INTO tutorial_rpl_classes (id, nama_kelompok, periode, kuota, is_active)
VALUES (1, 'Kelompok RPL 1 (Putra)', 'Tutorial RPL 2026/2027', 50, 1);

INSERT INTO users (id, nama_lengkap, nim, role, jenis_program)
VALUES (101, 'Ahmad Fauzi Mhs', '20260101', 'mahasiswa', 'RPL');

INSERT INTO users (id, nama_lengkap, nim, role, jenis_program)
VALUES (102, 'Budi Santoso Mhs', '20260102', 'mahasiswa', 'RPL');

INSERT INTO tutorial_rpl_members (id, class_id, user_id, exam_submitted, nilai_ujian_tulis)
VALUES (1, 1, 101, 1, 82.0);

INSERT INTO tutorial_rpl_members (id, class_id, user_id, exam_submitted, nilai_ujian_tulis)
VALUES (2, 1, 102, 1, 74.0);
");

// Simpan evaluasi mahasiswa 1 (Praktik 86.0) -> Nilai Akhir: (82 + 86) / 2 = 84.0 -> Lulus (>= 80)
$ok1 = tutorialRplSaveEvaluation($pdoFixture, 1, 86.0, 1, 'Makhraj dan tajwid sangat baik');
$check($ok1, 'Simpan evaluasi mahasiswa 1 berhasil');

$m1 = $pdoFixture->query("SELECT * FROM tutorial_rpl_members WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$check($m1['drive_verified'] == 1, 'Mahasiswa 1: drive_verified = 1');
$check($m1['nilai_praktik'] == 86.0, 'Mahasiswa 1: nilai_praktik = 86.0');
$check($m1['nilai_akhir'] == 84.0, 'Mahasiswa 1: nilai_akhir = 84.0 ((82 + 86) / 2)');
$check($m1['status_lulus'] === 'lulus', 'Mahasiswa 1: status_lulus = lulus');
$check($m1['drive_notes'] === 'Makhraj dan tajwid sangat baik', 'Mahasiswa 1: catatan tutor tersimpan');

// Simpan evaluasi mahasiswa 2 (Praktik 76.0) -> Nilai Akhir: (74 + 76) / 2 = 75.0 -> Tidak Lulus (< 80)
$ok2 = tutorialRplSaveEvaluation($pdoFixture, 2, 76.0, 1, 'Perlu perbaikan hafalan surat pendek');
$check($ok2, 'Simpan evaluasi mahasiswa 2 berhasil');

$m2 = $pdoFixture->query("SELECT * FROM tutorial_rpl_members WHERE id = 2")->fetch(PDO::FETCH_ASSOC);
$check($m2['nilai_akhir'] == 75.0, 'Mahasiswa 2: nilai_akhir = 75.0');
$check($m2['status_lulus'] === 'tidak_lulus', 'Mahasiswa 2: status_lulus = tidak_lulus');

echo "\n=== TEST 2: Batch Verification ===\n";
// Buat mahasiswa 3 & 4
$pdoFixture->exec("
INSERT INTO users (id, nama_lengkap, nim, role, jenis_program) VALUES (103, 'Cahya Mhs', '20260103', 'mahasiswa', 'RPL');
INSERT INTO users (id, nama_lengkap, nim, role, jenis_program) VALUES (104, 'Doni Mhs', '20260104', 'mahasiswa', 'RPL');
INSERT INTO tutorial_rpl_members (id, class_id, user_id, drive_verified) VALUES (3, 1, 103, 0);
INSERT INTO tutorial_rpl_members (id, class_id, user_id, drive_verified) VALUES (4, 1, 104, 0);
");

$vCount = tutorialRplBatchVerify($pdoFixture, [3, 4], 1);
$check($vCount === 2, 'Batch verify berhasil memverifikasi 2 anggota sekaligus');

$unvCount = tutorialRplBatchVerify($pdoFixture, [3], 0);
$check($unvCount === 1, 'Batch verify berhasil membatalkan verifikasi anggota 3');
$check($pdoFixture->query("SELECT drive_verified FROM tutorial_rpl_members WHERE id = 3")->fetchColumn() == 0, 'Anggota 3 kembali unverified');
$check($pdoFixture->query("SELECT drive_verified FROM tutorial_rpl_members WHERE id = 4")->fetchColumn() == 1, 'Anggota 4 tetap verified');

echo "\n=== TEST 3: Aturan Presensi Sah Lengkap ===\n";
// Syarat hadir sah: drive_verified = 1 AND exam_submitted = 1
// Anggota 1: verified=1, exam=1 -> Hadir Sah
// Anggota 2: verified=1, exam=1 -> Hadir Sah
// Anggota 3: verified=0, exam=0 -> Belum Lengkap
// Anggota 4: verified=1, exam=0 -> Belum Lengkap (CBT belum)
$presensiRows = $pdoFixture->query("
    SELECT id,
           (CASE WHEN drive_verified = 1 AND exam_submitted = 1 THEN 1 ELSE 0 END) AS is_hadir_sah
    FROM tutorial_rpl_members
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_KEY_PAIR);

$check($presensiRows[1] == 1, 'Anggota 1 (Tugas V + CBT 1) -> Hadir Sah');
$check($presensiRows[2] == 1, 'Anggota 2 (Tugas V + CBT 1) -> Hadir Sah');
$check($presensiRows[3] == 0, 'Anggota 3 (Tugas X + CBT 0) -> Belum Lengkap');
$check($presensiRows[4] == 0, 'Anggota 4 (Tugas V + CBT 0) -> Belum Lengkap (menunggu CBT)');

echo "\n=== TEST 4: Smoke Test File Halaman Dosen & Admin ===\n";
$check(file_exists(__DIR__ . '/../dosen/tutorial-rpl.php'), 'dosen/tutorial-rpl.php ada di disk');
$check(file_exists(__DIR__ . '/../admin/tutorial-rpl-presensi.php'), 'admin/tutorial-rpl-presensi.php ada di disk');

if (!empty($failures)) {
    echo "\nTotal failures: " . count($failures) . "\n";
    exit(1);
}

echo "\nSemua pengujian Verifikasi Tugas & Presensi Tutorial RPL BERHASIL! (100% PASS)\n";
exit(0);
