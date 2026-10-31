<?php
/** Kartu peserta pretes pribadi mahasiswa, tanpa perubahan data. */
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = getCurrentUser();
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

if (($user['role'] ?? '') !== 'mahasiswa') {
    http_response_code(403);
    exit('Kartu pretes hanya tersedia untuk mahasiswa.');
}

// Identitas pemilik selalu berasal dari sesi, bukan parameter URL.
$pdo = getDBConnection();
$stmt = $pdo->prepare("
    SELECT pr.*, ps.tanggal, ps.waktu_mulai, ps.waktu_selesai,
           ps.ruangan AS ruangan_jadwal
    FROM pretes_registrations pr
    LEFT JOIN pretes_schedules ps ON ps.id = pr.pretes_schedule_id
    WHERE pr.user_id = ?
    ORDER BY pr.tanggal_daftar DESC, pr.id DESC
    LIMIT 1
");
$stmt->execute([$user['id']]);
$registration = $stmt->fetch();

if (!$registration) {
    http_response_code(404);
}

require __DIR__ . '/includes/pretes-card.php';
