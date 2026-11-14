<?php
/**
 * Test: Tombol Pesan WhatsApp Dosen beserta Template Pesan Resmi Mahasiswa
 */

require_once __DIR__ . '/../includes/user-helper.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT,
        nama_lengkap TEXT,
        nim TEXT,
        role TEXT,
        program_studi TEXT,
        no_hp TEXT,
        jenis_program TEXT DEFAULT 'Reguler',
        is_active INTEGER DEFAULT 1
    );
");

// Seed lecturers
$pdo->exec("
    INSERT INTO users (username, nama_lengkap, nim, role, no_hp) VALUES
    ('dosen1', 'Dr. H. Ahmad Khoiri, M.Pd.', 'DOS001', 'dosen', '081234567890'),
    ('dosen2', 'Siti Fatimah, M.Ag.', 'DOS002', 'dosen', '6289876543210'),
    ('dosen3', 'Budi Santoso, M.Kom.', 'DOS003', 'dosen', '+628551234567'),
    ('dosen4', 'Dosen Tanpa HP', 'DOS004', 'dosen', NULL);
");

// Seed student
$student = [
    'id' => 10,
    'username' => '21010101',
    'nim' => '21010101',
    'nama_lengkap' => 'Muhammad Zaidan',
    'program_studi' => 'Pendidikan Agama Islam',
    'role' => 'mahasiswa'
];

echo "=== TEST 1: Template Pesan WhatsApp Default (Keperluan Tidak Diisi Otomatis / Tetap Titik-Titik) ===\n";
$msg = getDosenWaMessageTemplate($student);
echo $msg . "\n\n";

assert(str_contains($msg, "Assalamu'alaikum wr wb"), 'Template harus memiliki salam resmi');
assert(str_contains($msg, "Perkenalkan nama saya : Muhammad Zaidan"), 'Harus menyertakan nama mahasiswa');
assert(str_contains($msg, "nim : 21010101"), 'Harus menyertakan nim');
assert(str_contains($msg, "jurursan/ prodi : Pendidikan Agama Islam"), 'Harus menyertakan jurusan/prodi');
assert(str_contains($msg, "Keperluan : .........................................."), 'Keperluan harus tetap titik-titik dan tidak boleh diisi asumsi otomatis');
assert(str_contains($msg, "terima kasih"), 'Harus menyertakan penutup terima kasih');

echo "=== TEST 2: Template Kosong / Fallback Dots Keseluruhan ===\n";
$msgDots = getDosenWaMessageTemplate(null, null, true);
echo $msgDots . "\n\n";
assert(str_contains($msgDots, "Perkenalkan nama saya : ........................"), 'Fallback dots nama');
assert(str_contains($msgDots, "nim : ................................."), 'Fallback dots nim');
assert(str_contains($msgDots, "jurursan/ prodi : ................................"), 'Fallback dots prodi');
assert(str_contains($msgDots, "Keperluan : .........................................."), 'Fallback dots keperluan');

echo "=== TEST 3: Pencarian Nomor HP Dosen ===\n";
// Exact
$p1 = getDosenPhoneByName($pdo, 'Dr. H. Ahmad Khoiri, M.Pd.', true);
assert($p1 === '6281234567890', "Exact match phone failed: got $p1");

// Partial / without titles
$p2 = getDosenPhoneByName($pdo, 'Ahmad Khoiri');
assert($p2 === '6281234567890', "Partial title-stripped match phone failed: got $p2");

// Siti Fatimah
$p3 = getDosenPhoneByName($pdo, 'Siti Fatimah, M.Ag.');
assert($p3 === '6289876543210', "Siti Fatimah match failed: got $p3");

// Plus prefix (+62)
$p4 = getDosenPhoneByName($pdo, 'Budi Santoso');
assert($p4 === '628551234567', "Plus prefix phone failed: got $p4");

// Lecturer without phone
$pNone = getDosenPhoneByName($pdo, 'Dosen Tanpa HP');
assert($pNone === null, "Lecturer without phone must return null");

// Placeholders must return null
assert(getDosenPhoneByName($pdo, 'Menunggu pembagian') === null, "Menunggu pembagian must return null");
assert(getDosenPhoneByName($pdo, 'Menunggu plotting') === null, "Menunggu plotting must return null");
assert(getDosenPhoneByName($pdo, '-') === null, "- must return null");
assert(getDosenPhoneByName($pdo, '') === null, "Empty must return null");

echo "=== TEST 4: Render Tombol dan HTML ===\n";
$html1 = renderDosenWithWa($pdo, 'Dr. H. Ahmad Khoiri, M.Pd.', $student);
echo "HTML with WA: " . $html1 . "\n";
assert(str_contains($html1, 'Dr. H. Ahmad Khoiri, M.Pd.'), 'Harus menampilkan nama dosen');
assert(str_contains($html1, 'https://wa.me/6281234567890?text='), 'Harus memiliki URL wa.me yang benar');
assert(str_contains($html1, rawurlencode("Keperluan : ..........................................")), 'URL text harus memuat Keperluan : ..........................................');
assert(str_contains($html1, 'btn-wa-dosen'), 'Harus memiliki kelas btn-wa-dosen');
assert(str_contains($html1, '<svg'), 'Harus menyertakan SVG icon');

$htmlPlaceholder = renderDosenWithWa($pdo, 'Menunggu pembagian', $student, null, 'Menunggu pembagian');
assert($htmlPlaceholder === 'Menunggu pembagian', "Placeholder must not render WA button: got $htmlPlaceholder");

$htmlNoPhone = renderDosenWithWa($pdo, 'Dosen Tanpa HP', $student);
assert($htmlNoPhone === 'Dosen Tanpa HP', "Lecturer without phone must render name without button: got $htmlNoPhone");

echo "=== TEST 5: Verifikasi Integrasi di File-File Tampilan Mahasiswa ===\n";
$filesToCheck = [
    'pages/dashboard.php',
    'pages/pretes-daftar.php',
    'pages/pretes-hasil.php',
    'includes/pretes-participant-view.php',
    'includes/pretes-stage.php',
    'pages/tutorial-info.php',
    'pages/tutorial-pembagian.php',
    'pages/tutorial-pendaftaran.php',
    'pages/tutorial-pengumuman.php',
    'includes/announcement-helper.php',
    'includes/pretes-card.php'
];

foreach ($filesToCheck as $relPath) {
    $fullPath = __DIR__ . '/../' . $relPath;
    assert(file_exists($fullPath), "File $relPath harus ada");
    $content = file_get_contents($fullPath);
    assert(str_contains($content, 'renderDosenWithWa'), "File $relPath harus memanggil renderDosenWithWa");
    assert(!str_contains($content, 'Konfirmasi Ujian Pretes') && !str_contains($content, 'Konfirmasi Kelas Tutorial'), "File $relPath tidak boleh mengisi keperluan dengan asumsi teks otomatis");
    echo " [OK] $relPath terintegrasi bersih tanpa teks keperluan asumsi\n";
}

$cssPath = __DIR__ . '/../assets/css/style.css';
$css = file_get_contents($cssPath);
assert(str_contains($css, '.btn-wa-dosen'), 'assets/css/style.css harus memiliki styling .btn-wa-dosen');
echo " [OK] assets/css/style.css terverifikasi memuat .btn-wa-dosen\n";

echo "\n>>> SEMUA PENGUJIAN DOSEN WA LINK BERHASIL 100%! <<<\n";
