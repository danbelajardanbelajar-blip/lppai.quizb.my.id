<?php
/**
 * Test: Verifikasi Pencegahan Tampilan Tombol Daftar Pretes Saat Periode Pretes Berakhir
 */

$pretesHasilFile = __DIR__ . '/../pages/pretes-hasil.php';
$pretesDaftarFile = __DIR__ . '/../pages/pretes-daftar.php';

// 1. Verifikasi pembacaan file
$hasilCode = file_get_contents($pretesHasilFile);
assert($hasilCode !== false, "File pages/pretes-hasil.php harus dapat dibaca");

$daftarCode = file_get_contents($pretesDaftarFile);
assert($daftarCode !== false, "File pages/pretes-daftar.php harus dapat dibaca");

// 2. Verifikasi pengecekan status master_pretes & master_gelombang di pages/pretes-hasil.php
assert(strpos($hasilCode, "SELECT * FROM master_pretes WHERE status = 'aktif'") !== false, "Query master_pretes aktif harus ada di pretes-hasil.php");
assert(strpos($hasilCode, '$showPretesPreparation') !== false, "Periode pretes aktif harus memiliki tampilan persiapan tersendiri");
assert(strpos($hasilCode, '&& $activeGelombang') === false, "Tutorial aktif tidak boleh menutup informasi pretes yang masih aktif");
assert(strpos($hasilCode, '$isPretesSelesai') !== false, "Variabel \$isPretesSelesai harus ada di pretes-hasil.php");
assert(strpos($hasilCode, '$hasPretesRecord') !== false, "Variabel \$hasPretesRecord harus ada di pretes-hasil.php");

// 3. Verifikasi kondisi kondisional pada empty state:
// Pastikan tidak ada tombol daftar pretes statis tanpa pengecekan $isPretesSelesai
assert(strpos($hasilCode, '<?php if (!$hasPretesRecord): ?>') !== false, "Blok pengecekan riwayat pretes \$hasPretesRecord harus ada");
assert(strpos($hasilCode, '<?php if ($isPretesSelesai): ?>') !== false, "Pengecekan \$isPretesSelesai pada empty state harus ada");
assert(strpos($hasilCode, 'Periode Pretes Telah Berakhir') !== false, "Pemberitahuan periode pretes telah berakhir harus ada");
assert(strpos($hasilCode, 'tutorial-pendaftaran.php') !== false, "Harus mengarahkan mahasiswa yang belum lulus/belum pretes ke tutorial-pendaftaran.php");

// 4. Verifikasi badge header: mahasiswa tanpa riwayat pretes tidak boleh dilabeli 'Status Akhir: TIDAK LULUS'
assert(strpos($hasilCode, 'BELUM MENGIKUTI PRETES') !== false, "Badge status untuk mahasiswa belum pretes harus ada");

// 5. Verifikasi pages/pretes-daftar.php memiliki fallback pengalihan ke tutorial
assert(strpos($daftarCode, 'Pendaftaran Pretes Sedang Ditutup / Telah Berakhir') !== false, "Judul fallback pretes telah berakhir harus ada di pretes-daftar.php");
assert(strpos($daftarCode, 'tutorial-pendaftaran.php') !== false, "Link ke pendaftaran tutorial harus ada di pretes-daftar.php");

echo "✅ PASS: Seluruh pengujian pencegahan tombol daftar pretes saat periode pretes berakhir BERHASIL 100%!\n";
