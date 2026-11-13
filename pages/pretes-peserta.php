<?php
/**
 * LPPAI Corner - Peserta & Jadwal Pretes (Mahasiswa)
 *
 * - Peserta pretes AKTIF (terdaftar pada periode pretes aktif yang sesuai programnya):
 *   halaman peserta, jadwal & persiapan pretes (renderActivePretesParticipantPage).
 * - Selain itu (tidak terdaftar di periode aktif / periode sudah tidak aktif):
 *   isi halaman sama persis dengan Hasil Pretes (pages/pretes-hasil.php), URL tetap.
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user = getCurrentUser();
$pdo  = getDBConnection();
require_once __DIR__ . '/../includes/pretes-active-page.php';
if (renderActivePretesParticipantPage($pdo, $user)) return;

// Bukan peserta pretes aktif → tampilkan isi halaman Hasil Pretes
require __DIR__ . '/pretes-hasil.php';
