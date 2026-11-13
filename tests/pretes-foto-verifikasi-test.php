<?php
/**
 * Test Suite: Verifikasi Fitur Unggah & Ambil Foto Langsung + Verifikasi Wajah Pretes
 * Memverifikasi auto-migrasi kolom foto, penyimpanan foto, proteksi direktori,
 * dan integrasi ke kartu ujian pretes, dashboard pendaftaran, dan daftar admin.
 */

require_once __DIR__ . '/../includes/pretes-helper.php';

$errors = [];
function fotoAssert(bool $cond, string $msg, array &$errors): void {
    if (!$cond) {
        $errors[] = $msg;
    }
}

// ── 1. Uji Auto-Migration Kolom Foto di SQLite ────────────────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("
    CREATE TABLE pretes_registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        hari_pilihan VARCHAR(20) DEFAULT NULL,
        jam VARCHAR(50) DEFAULT '13.00 WIB (Jam 1)',
        periode VARCHAR(100) NOT NULL,
        status VARCHAR(50) DEFAULT 'terdaftar'
    );
");

// Jalankan ensurePretesRegistrationPhotoColumn
ensurePretesRegistrationPhotoColumn($pdo);
ensurePretesRegistrationPhotoColumn($pdo); // Idempoten

$cols = $pdo->query("PRAGMA table_info(pretes_registrations)")->fetchAll();
$colNames = array_map(fn($c) => strtolower((string)$c['name']), $cols);
fotoAssert(in_array('foto', $colNames, true), 'Kolom foto harus berhasil ditambahkan ke tabel pretes_registrations.', $errors);

// ── 2. Uji Helper savePretesRegistrationPhoto ─────────────────────────────────
// Buat data base64 gambar test 200x200 pixel menggunakan GD jika tersedia, atau PNG dummy
$testNim = '25062102999';
$testBase64 = '';

if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(240, 320);
    $bg = imagecolorallocate($im, 255, 224, 189); // Warna kulit
    imagefill($im, 0, 0, $bg);
    ob_start();
    imagejpeg($im, null, 90);
    $jpegBytes = ob_get_clean();
    imagedestroy($im);
    $testBase64 = 'data:image/jpeg;base64,' . base64_encode($jpegBytes);
} else {
    // 1x1 PNG dummy
    $testBase64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
}

$savedPath = savePretesRegistrationPhoto($testBase64, $testNim);
fotoAssert(!empty($savedPath), 'savePretesRegistrationPhoto harus mengembalikan path file tersimpan.', $errors);

if ($savedPath) {
    $fullPath = dirname(__DIR__) . '/' . str_replace('\\', '/', $savedPath);
    fotoAssert(file_exists($fullPath), "File tersimpan harus benar-benar ada di disk: $fullPath", $errors);

    // Cek file .htaccess keamanan
    $htaccess = dirname($fullPath) . '/.htaccess';
    fotoAssert(file_exists($htaccess), 'File .htaccess pengaman uploads/pretes_foto harus ada.', $errors);
    if (file_exists($htaccess)) {
        $htaccessContent = file_get_contents($htaccess);
        fotoAssert(str_contains($htaccessContent, 'Deny from all'), '.htaccess harus memblokir eksekusi skrip php.', $errors);
    }

    // Bersihkan file test
    @unlink($fullPath);
}

// Uji payload kosong harus ditolak (null)
fotoAssert(savePretesRegistrationPhoto('', $testNim) === null, 'Payload foto kosong harus mengembalikan null.', $errors);
fotoAssert(savePretesRegistrationPhoto('invalid_data', $testNim) === null, 'Payload foto tidak valid harus mengembalikan null.', $errors);

// ── 3. Uji Integrasi File PHP & Front-End Script ──────────────────────────────
$root = dirname(__DIR__);

// Verifikasi keberadaan modul JavaScript deteksi wajah & model binary ML
$jsFile = $root . '/assets/js/pretes-face-verifier.js';
$binFile = $root . '/assets/js/facefinder.bin';
fotoAssert(file_exists($jsFile), 'File assets/js/pretes-face-verifier.js harus ada.', $errors);
fotoAssert(file_exists($binFile), 'File model binary assets/js/facefinder.bin harus ada.', $errors);
if (file_exists($binFile)) {
    fotoAssert(filesize($binFile) > 200000, 'Model binary facefinder.bin harus valid dan berukuran > 200 KB.', $errors);
}
if (file_exists($jsFile)) {
    $jsContent = file_get_contents($jsFile);
    fotoAssert(str_contains($jsContent, 'PretesFaceVerifier'), 'Modul JS harus mendefinisikan PretesFaceVerifier.', $errors);
    fotoAssert(str_contains($jsContent, 'startCamera'), 'Modul JS harus mendukung startCamera.', $errors);
    fotoAssert(str_contains($jsContent, 'verifyFace'), 'Modul JS harus mendukung verifyFace.', $errors);
    fotoAssert(str_contains($jsContent, 'captureSnapshot'), 'Modul JS harus mendukung captureSnapshot 3:4.', $errors);
    fotoAssert(str_contains($jsContent, 'unpack_cascade'), 'Modul JS harus mengintegrasikan engine cascade classifier Pico ML.', $errors);
    fotoAssert(str_contains($jsContent, 'facefinder.bin'), 'Modul JS harus memuat model facefinder.bin.', $errors);
    fotoAssert(str_contains($jsContent, 'cluster_detections'), 'Modul JS harus mendukung clustering deteksi wajah.', $errors);
    fotoAssert(str_contains($jsContent, 'checkEyeOpenness'), 'Modul JS harus mendukung verifikasi mata terbuka (checkEyeOpenness).', $errors);
    fotoAssert(str_contains($jsContent, 'Mata Anda terpejam/tertutup'), 'Modul JS harus menolak foto saat mata terpejam/tertutup.', $errors);
}

// Verifikasi integrasi di pages/pretes-daftar.php
$daftarSrc = file_get_contents($root . '/pages/pretes-daftar.php');
fotoAssert(str_contains($daftarSrc, 'ensurePretesRegistrationPhotoColumn('), 'pretes-daftar.php harus memanggil ensurePretesRegistrationPhotoColumn.', $errors);
fotoAssert(str_contains($daftarSrc, 'savePretesRegistrationPhoto('), 'pretes-daftar.php harus memanggil savePretesRegistrationPhoto saat POST.', $errors);
fotoAssert(str_contains($daftarSrc, 'pretes-face-verifier.js'), 'pretes-daftar.php harus memuat skrip pretes-face-verifier.js.', $errors);
fotoAssert(str_contains($daftarSrc, 'id="cameraVideo"'), 'pretes-daftar.php harus memiliki elemen cameraVideo.', $errors);
fotoAssert(str_contains($daftarSrc, 'id="btnSubmitDaftarPretes"'), 'pretes-daftar.php harus memiliki tombol submit dengan gembok.', $errors);
fotoAssert(str_contains($daftarSrc, "processAndVerifyImage(canvas, 'camera')"), 'pretes-daftar.php harus meneruskan mode kamera saat jepret foto.', $errors);
fotoAssert(str_contains($daftarSrc, "processAndVerifyImage(canvas, 'upload')"), 'pretes-daftar.php harus meneruskan mode upload saat pilih file.', $errors);
fotoAssert(str_contains($daftarSrc, 'checkEyeOpenness'), 'pretes-daftar.php fallback harus mendukung verifikasi mata terbuka.', $errors);
fotoAssert(str_contains($daftarSrc, 'renderVerificationFailureHtml'), 'pretes-daftar.php harus memiliki fungsi renderVerificationFailureHtml untuk instruksi kegagalan.', $errors);
fotoAssert(str_contains($daftarSrc, 'Ketentuan Pasfoto Resmi yang Wajib Dipenuhi'), 'pretes-daftar.php harus menyertakan checklist ketentuan pasfoto di bawah garis pemisah saat verifikasi gagal.', $errors);
fotoAssert(!str_contains($daftarSrc, 'submitBtn.disabled = false;\n            if (lockNotice) lockNotice.style.display = \'none\';\n        });'), 'pretes-daftar.php tidak boleh membuka submit lock pada catch error.', $errors);

// Verifikasi integrasi di includes/pretes-card.php
$cardSrc = file_get_contents($root . '/includes/pretes-card.php');
fotoAssert(str_contains($cardSrc, '$photoCardUrl'), 'pretes-card.php harus membaca foto peserta untuk dicetak.', $errors);
fotoAssert(str_contains($cardSrc, 'has-photo'), 'pretes-card.php harus memiliki class has-photo untuk styling pasfoto.', $errors);

// Verifikasi integrasi di admin/pretes-peserta.php & admin/pretes-jadwal.php
$pesertaSrc = file_get_contents($root . '/admin/pretes-peserta.php');
fotoAssert(str_contains($pesertaSrc, 'pr.foto'), 'admin/pretes-peserta.php harus menyertakan kolom pr.foto dalam SELECT.', $errors);

$jadwalSrc = file_get_contents($root . '/admin/pretes-jadwal.php');
fotoAssert(str_contains($jadwalSrc, 'pr.foto'), 'admin/pretes-jadwal.php harus menyertakan kolom pr.foto dalam SELECT.', $errors);

if (!empty($errors)) {
    foreach ($errors as $e) {
        fwrite(STDERR, "[FAIL] $e\n");
    }
    exit(1);
}

echo "OK: pretes-foto-verifikasi tests passed (auto-migration, savePhoto, .htaccess security, card rendering, and UI verification).\n";
