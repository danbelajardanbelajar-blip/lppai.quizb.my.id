<?php
/**
 * Test: Verifikasi Sembunyikan Panel Kelola Kredensial Pretes CBT pada admin/pretes-peserta.php
 */

$root = dirname(__DIR__);
$file = $root . '/admin/pretes-peserta.php';

assert(file_exists($file), "admin/pretes-peserta.php harus ada");
$content = file_get_contents($file);

echo "=== Verifikasi Sembunyikan Panel Kelola Kredensial ===\n";

// 1. Verifikasi card-manage-credentials memiliki style display:none
assert(
    strpos($content, '<div class="card" id="card-manage-credentials" style="display:none;') !== false,
    "[FAIL] Panel #card-manage-credentials harus disembunyikan secara default (style display:none)"
);
echo " [PASS] Panel #card-manage-credentials telah disembunyikan secara default.\n";

// 2. Verifikasi tombol Sembunyikan / Tutup ada di dalam header card
assert(
    strpos($content, 'closeCredPanel()') !== false,
    "[FAIL] Harus ada pemanggilan closeCredPanel() untuk menutup/menyembunyikan panel"
);
assert(
    strpos($content, '✕ Sembunyikan') !== false,
    "[FAIL] Tombol '✕ Sembunyikan' harus ada di header panel"
);
echo " [PASS] Tombol sembunyikan/tutup panel tersedia di header card.\n";

// 3. Verifikasi fungsi openCredPanel dan closeCredPanel
assert(
    strpos($content, 'function openCredPanel(tab)') !== false,
    "[FAIL] Fungsi JavaScript openCredPanel(tab) harus terdefinisi"
);
assert(
    strpos($content, 'function closeCredPanel()') !== false,
    "[FAIL] Fungsi JavaScript closeCredPanel() harus terdefinisi"
);
echo " [PASS] Fungsi JavaScript openCredPanel dan closeCredPanel terdefinisi dengan tepat.\n";

// 4. Verifikasi tombol Impor Excel dan Generate Otomatis membuka/toggle panel saat dibutuhkan
assert(
    strpos($content, 'onclick="openCredPanel(\'import\')"') !== false,
    "[FAIL] Tombol Impor Excel harus memanggil openCredPanel('import')"
);
assert(
    strpos($content, 'onclick="openCredPanel(\'generate\')"') !== false,
    "[FAIL] Tombol Generate Otomatis harus memanggil openCredPanel('generate')"
);
echo " [PASS] Tombol aksi terhubung dengan openCredPanel untuk akses on-demand.\n";

echo "✅ SEMUA PENGUJIAN SEMBUNYIKAN PANEL KREDENSIAL BERHASIL 100%!\n";
