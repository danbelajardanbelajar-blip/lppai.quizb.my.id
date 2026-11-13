<?php
/**
 * LPPAI Corner - Stream Diktat Tutorial (terproteksi)
 *
 * Mengirim isi PDF diktat hanya kepada pengguna yang login dan hanya melalui
 * reader di halaman Info Tutorial (fetch dengan header X-Diktat-Key).
 * Byte PDF dikirim dalam bentuk ter-XOR dengan kunci sesi, sehingga membuka URL
 * langsung atau menyimpan respons jaringan tidak menghasilkan file PDF yang valid.
 * Catatan: ini pencegahan (deterrent), bukan DRM mutlak.
 */
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Silakan login terlebih dahulu.');
}

$sessionKey = $_SESSION['diktat_key'] ?? '';
$headerKey  = $_SERVER['HTTP_X_DIKTAT_KEY'] ?? '';
$fetchDest  = strtolower($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '');

// Tolak akses langsung dari address bar / tab baru / tag <embed>/<iframe>
if ($sessionKey === '' || !hash_equals($sessionKey, $headerKey) || in_array($fetchDest, ['document', 'iframe', 'embed', 'object'], true)) {
    http_response_code(403);
    exit('Akses langsung ke berkas diktat tidak diizinkan. Buka melalui menu Info Tutorial.');
}

$pdfPath = __DIR__ . '/../storage/diktat/diktat-lppai-2026.pdf';
if (!is_file($pdfPath)) {
    http_response_code(404);
    exit('Berkas diktat belum tersedia.');
}

// Lepaskan lock sesi agar request lain tidak tertahan selama streaming
session_write_close();

$data = file_get_contents($pdfPath);
$key  = hex2bin($sessionKey);
$len  = strlen($data);
$mask = substr(str_repeat($key, intdiv($len, strlen($key)) + 1), 0, $len);
$out  = $data ^ $mask;

header('Content-Type: application/octet-stream');
header('Content-Length: ' . $len);
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
echo $out;
