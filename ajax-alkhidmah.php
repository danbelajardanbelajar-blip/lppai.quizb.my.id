<?php
/**
 * AJAX Handler for Absensi Al Khidmah
 */
require_once __DIR__ . '/includes/auth.php';

// Cek autentikasi dan role mahasiswa
if (!isLoggedIn() || $_SESSION['role'] !== 'mahasiswa') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$nim = $_SESSION['nim'];
$qr_data_raw = $_POST['qr_data'] ?? '';
$foto_base64 = $_POST['foto'] ?? '';
$today = date('Y-m-d');
$now = date('H:i:s');

// 1. Validasi QR Data
$qr_data = json_decode($qr_data_raw, true);
if (!$qr_data || !isset($qr_data['type']) || $qr_data['type'] !== 'alkhidmah' || !isset($qr_data['date'])) {
    echo json_encode(['status' => 'error', 'message' => 'QR Code tidak valid untuk absensi Al Khidmah.']);
    exit;
}

if ($qr_data['date'] !== $today) {
    echo json_encode(['status' => 'error', 'message' => 'QR Code kadaluarsa. Pastikan Anda scan QR hari ini.']);
    exit;
}

// 2. Validasi Waktu (Hadir: 13:00-14:00, Pulang: 16:00-17:00)
// UNTUK SEMENTARA DINONAKTIFKAN SESUAI PERMINTAAN USER (Bisa diaktifkan nanti)
/*
$waktuHadirStart = '13:00:00';
$waktuHadirEnd   = '14:00:00';
$waktuPulangStart = '16:00:00';
$waktuPulangEnd   = '17:00:00';

// Nanti butuh logic untuk ngecek hari Jumat minggu pertama juga
*/

// 3. Cek Status Absensi (Hadir atau Pulang)
$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT * FROM absensi_alkhidmah WHERE nim = ? AND tanggal = ?");
$stmt->execute([$nim, $today]);
$absen = $stmt->fetch();

$tipe_absen = 'hadir';
if ($absen) {
    if ($absen['waktu_pulang']) {
        echo json_encode(['status' => 'error', 'message' => 'Anda sudah melakukan absensi hadir dan pulang hari ini.']);
        exit;
    }
    $tipe_absen = 'pulang';
}

// 4. Proses Foto Selfie
if (empty($foto_base64)) {
    echo json_encode(['status' => 'error', 'message' => 'Foto selfie tidak ditemukan.']);
    exit;
}

// Ekstrak base64
list($type, $foto_base64) = explode(';', $foto_base64);
list(, $foto_base64)      = explode(',', $foto_base64);
$foto_data = base64_decode($foto_base64);

$upload_dir = __DIR__ . '/assets/uploads/alkhidmah';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$filename = 'alkhidmah_' . $nim . '_' . $today . '_' . $tipe_absen . '.jpg';
$filepath = $upload_dir . '/' . $filename;
$db_filepath = 'assets/uploads/alkhidmah/' . $filename;

if (file_put_contents($filepath, $foto_data) === false) {
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan foto selfie.']);
    exit;
}

// 5. Simpan ke Database
try {
    if ($tipe_absen === 'hadir') {
        $stmt = $pdo->prepare("INSERT INTO absensi_alkhidmah (nim, tanggal, waktu_hadir, foto_hadir) VALUES (?, ?, ?, ?)");
        $stmt->execute([$nim, $today, $now, $db_filepath]);
    } else {
        $stmt = $pdo->prepare("UPDATE absensi_alkhidmah SET waktu_pulang = ?, foto_pulang = ? WHERE id = ?");
        $stmt->execute([$now, $db_filepath, $absen['id']]);
    }
    
    echo json_encode(['status' => 'success', 'message' => 'Absensi ' . ucfirst($tipe_absen) . ' berhasil tersimpan.']);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
