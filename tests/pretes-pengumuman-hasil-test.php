<?php
/**
 * Test: Verifikasi Tampilan dan Redaksi Pengumuman Hasil Pretes
 */

// 1. Cek file pages/pretes-hasil.php
$content = file_get_contents(__DIR__ . '/../pages/pretes-hasil.php');
assert($content !== false, "File pages/pretes-hasil.php harus dapat dibaca");

// 2. Verifikasi tidak ada typo lama
assert(stripos($content, 'Faultas Hukum') === false, "Tidak boleh ada typo 'Faultas Hukum' di pretes-hasil.php");
assert(stripos($content, 'foto selfi') === false, "Tidak boleh ada kata 'foto selfi' informal tanpa format yang rapi");

// 3. Verifikasi perbaikan kalimat dan istilah
assert(stripos($content, 'Fakultas Hukum') !== false, "Harus menggunakan ejaan benar 'Fakultas Hukum'");
assert(stripos($content, 'swafoto') !== false, "Harus menggunakan istilah formal 'swafoto'");
assert(stripos($content, 'Rp 50.000') !== false, "Harus menggunakan format mata uang 'Rp 50.000'");

// 4. Verifikasi kehadiran emoticons yang sesuai
$emojis = ['🎉', '🕌', '📱', '💳', '🎓', '⚠️', '📝'];
foreach ($emojis as $emoji) {
    assert(mb_strpos($content, $emoji) !== false, "Emoji {$emoji} harus ada pada pengumuman hasil pretes");
}

// 5. Verifikasi komponen kartu langkah (pretes-step-card & pretes-step-grid)
assert(stripos($content, 'pretes-step-grid') !== false, "Harus memiliki CSS class pretes-step-grid");
assert(stripos($content, 'pretes-step-card') !== false, "Harus memiliki CSS class pretes-step-card");
assert(stripos($content, 'pretes-ann-box') !== false, "Harus memiliki CSS class pretes-ann-box");

// 6. Verifikasi CTA untuk Tidak Lulus
assert(stripos($content, 'tutorial-pendaftaran.php') !== false, "Harus memiliki link pendaftaran tutorial untuk yang tidak lulus");

// 7. Verifikasi migration file
$migContent = file_get_contents(__DIR__ . '/../migration_update_announcement_pretes.php');
assert(stripos($migContent, 'Fakultas Hukum') !== false, "Migration harus menggunakan ejaan benar Fakultas Hukum");
assert(stripos($migContent, 'Faultas Hukum') === false, "Migration tidak boleh mengandung typo Faultas Hukum");

echo "✅ Semua test pengumuman hasil pretes BERHASIL (100% lolos)!\n";
