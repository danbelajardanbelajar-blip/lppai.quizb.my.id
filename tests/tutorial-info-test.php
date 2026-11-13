<?php
/**
 * Test Suite: Validasi Halaman Info Tutorial & Edukasi Diktat (pages/tutorial-info.php)
 */

$errors = [];
function infoAssert(bool $condition, string $message, array &$errors): void {
    if (!$condition) {
        $errors[] = $message;
        echo " [FAIL] $message\n";
    } else {
        echo " [PASS] $message\n";
    }
}

echo "=== Menjalankan Validasi Halaman Info Tutorial ===\n";

$file = __DIR__ . '/../pages/tutorial-info.php';
infoAssert(file_exists($file), 'File pages/tutorial-info.php harus ada.', $errors);

$syntaxCheck = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
infoAssert(strpos($syntaxCheck, 'No syntax errors detected') !== false, 'Syntax PHP pages/tutorial-info.php harus valid.', $errors);

$content = file_get_contents($file);

// 1. Informasi Menunggu Plotting
infoAssert(strpos($content, 'Pendaftaran Anda Telah Diterima &amp; Sedang Menunggu Jadwal Plotting') !== false, 'Penjelasan pendaftaran diterima dan menunggu plotting harus ada.', $errors);
infoAssert(strpos($content, 'ti-waiting-card') !== false, 'Elemen kartu informasi menunggu plotting harus ada.', $errors);

// 2. Timeline Stepper
infoAssert(strpos($content, '1. Pendaftaran') !== false, 'Langkah 1 pendaftaran harus ada.', $errors);
infoAssert(strpos($content, '2. Plotting Kelas') !== false, 'Langkah 2 plotting kelas harus ada.', $errors);
infoAssert(strpos($content, 'Sedang Diproses TU') !== false, 'Status aktif proses TU pada stepper harus ada.', $errors);

// 3. Edukasi Diktat & Call-to-Action
infoAssert(strpos($content, 'Silakan Pelajari Diktat Tutorial') !== false, 'Ajakan mempelajari diktat harus ada.', $errors);
infoAssert(strpos($content, 'data-open-tab="diktat"') !== false, 'Atribut data-open-tab diktat harus terpasang pada tombol CTA.', $errors);
infoAssert(strpos($content, 'Buka &amp; Pelajari Diktat Sekarang') !== false, 'Tombol CTA buka diktat harus ada.', $errors);

// 4. Poin Pokok Materi Diktat
infoAssert(strpos($content, 'Thaharah &amp; Wudhu Sempurna') !== false, 'Topik thaharah harus dicantumkan.', $errors);
infoAssert(strpos($content, 'Praktik Shalat Fardhu &amp; Jenazah') !== false, 'Topik shalat harus dicantumkan.', $errors);
infoAssert(strpos($content, 'Dzikir &amp; Wirid Sesudah Shalat') !== false, 'Topik dzikir harus dicantumkan.', $errors);
infoAssert(strpos($content, 'Kaidah Tajwid &amp; Tartil Al-Qur\'an') !== false, 'Topik tajwid Al-Quran harus dicantumkan.', $errors);

// 5. Bantuan WhatsApp Bu Umami
infoAssert(strpos($content, 'wa.me/6281515726827') !== false, 'Tautan WhatsApp Bu Umami berformat wa.me harus ada.', $errors);

// 6. JavaScript Tab Handler
infoAssert(strpos($content, 'window.openTab = openTab;') !== false, 'Fungsi window.openTab harus diekspos secara global.', $errors);
infoAssert(strpos($content, "btn.dataset.openTab") !== false, 'Event delegation click data-open-tab harus terpasang.', $errors);

if (!empty($errors)) {
    echo "\n❌ GAGAL: " . count($errors) . " asersi gagal.\n";
    exit(1);
} else {
    echo "\n✅ Seluruh pengujian Halaman Info Tutorial BERHASIL 100%!\n";
}
