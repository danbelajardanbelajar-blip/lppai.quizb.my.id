<?php
/**
 * Test Suite: Validasi Desain Modern & Responsif Halaman Rekapitulasi Nilai (rekap-nilai.php)
 */

$errors = [];
function rekapAssert(bool $cond, string $msg, array &$errs): void {
    if (!$cond) {
        $errs[] = $msg;
        echo " [FAIL] $msg\n";
    } else {
        echo " [PASS] $msg\n";
    }
}

echo "=== Menjalankan Validasi Desain Modern Halaman Rekap Nilai ===\n";

$file = __DIR__ . '/../rekap-nilai.php';
rekapAssert(file_exists($file), "File rekap-nilai.php harus ada.", $errors);

$lint = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
rekapAssert(strpos($lint, 'No syntax errors detected') !== false, "Syntax PHP rekap-nilai.php harus valid.", $errors);

$content = file_get_contents($file);

// 1. Desain Hero & Banner
rekapAssert(strpos($content, 'rn-hero') !== false, "Elemen banner hero (.rn-hero) harus ada.", $errors);
rekapAssert(strpos($content, 'Periode Aktif:') !== false, "Indikator Periode Aktif harus tercantum.", $errors);
rekapAssert(strpos($content, 'rn-period-dot') !== false, "Indikator pulsing dot periode harus ada.", $errors);

// 2. Ringkasan Statistik Admin/Dosen
rekapAssert(strpos($content, 'rn-stats-grid') !== false, "Grid ringkasan statistik (.rn-stats-grid) harus ada.", $errors);
rekapAssert(strpos($content, 'Total Peserta') !== false, "Statistik Total Peserta harus ada.", $errors);
rekapAssert(strpos($content, 'Nilai Lengkap (6/6)') !== false, "Statistik Nilai Lengkap harus ada.", $errors);
rekapAssert(strpos($content, 'Penilaian Berjalan') !== false, "Statistik Penilaian Berjalan harus ada.", $errors);

// 3. Filter Bar & Export
rekapAssert(strpos($content, 'rn-filter-card') !== false, "Card filter modern (.rn-filter-card) harus ada.", $errors);
rekapAssert(strpos($content, 'rn-btn-export') !== false, "Tombol export modern (.rn-btn-export) harus ada.", $errors);
rekapAssert(strpos($content, 'action=export') !== false, "Parameter action=export harus terpasang.", $errors);

// 4. Desktop Table & Mobile Cards
rekapAssert(strpos($content, 'rn-desktop-table') !== false, "Tabel desktop (.rn-desktop-table) harus ada.", $errors);
rekapAssert(strpos($content, 'rn-mobile-cards') !== false, "Tampilan kartu mobile (.rn-mobile-cards) harus ada.", $errors);
rekapAssert(strpos($content, 'renderJenisProgramBadge') !== false, "Badge jenis program harus dirender.", $errors);

// 5. 6 Komponen Asesmen Mahasiswa
$components = ['nilai_thaharah', 'nilai_shalat', 'nilai_surat_pendek', 'nilai_amaliyah', 'nilai_jenazah', 'nilai_ujian_tulis'];
foreach ($components as $c) {
    rekapAssert(strpos($content, $c) !== false, "Komponen asesmen '$c' harus didefinisikan.", $errors);
}

// 6. View Mahasiswa (Spotlight & Rubrik)
rekapAssert(strpos($content, 'rn-spotlight-card') !== false, "Spotlight nilai akhir mahasiswa (.rn-spotlight-card) harus ada.", $errors);
rekapAssert(strpos($content, 'rn-circle-score') !== false, "Indikator visual nilai lingkaran (.rn-circle-score) harus ada.", $errors);
rekapAssert(strpos($content, 'rn-components-grid') !== false, "Grid kartu 6 komponen (.rn-components-grid) harus ada.", $errors);
rekapAssert(strpos($content, 'rn-guide-card') !== false, "Panduan skala nilai & narahubung (.rn-guide-card) harus ada.", $errors);
rekapAssert(strpos($content, 'wa.me/6281515726827') !== false, "Kontak WhatsApp Bu Umami harus terpasang.", $errors);
rekapAssert(strpos($content, 'Min. 70.00') !== false, "Batas minimal kelulusan tutorial 70.00 harus dicantumkan.", $errors);
rekapAssert(strpos($content, 'Pretes: 80') !== false || strpos($content, 'Pretes 80.00') !== false, "Batas minimal pretes 80 harus dicantumkan.", $errors);

// 7. Modal Edit & Live Realtime Calculation
rekapAssert(strpos($content, 'editNilaiModal') !== false, "Modal edit nilai (#editNilaiModal) harus ada.", $errors);
rekapAssert(strpos($content, 'calculateLiveModalAvg') !== false, "Fungsi kalkulasi rata-rata real-time (calculateLiveModalAvg) harus ada.", $errors);
rekapAssert(strpos($content, 'setScoreField') !== false, "Fungsi quick-fill score chips (setScoreField) harus ada.", $errors);

// 8. Media Queries Responsif
rekapAssert(strpos($content, '@media (max-width: 1024px)') !== false, "Breakpoint media query 1024px harus ada.", $errors);
rekapAssert(strpos($content, '@media (max-width: 768px)') !== false, "Breakpoint media query 768px harus ada.", $errors);
rekapAssert(strpos($content, '@media (max-width: 480px)') !== false, "Breakpoint media query 480px harus ada.", $errors);

// 9. Unit Test Helper getTutorialScoreMeta (ekstraksi fungsi dari rekap-nilai.php)
preg_match('/(if \(!function_exists\(\'getTutorialScoreMeta\'\)\) \{.*?\}\r?\n\})/s', $content, $m);
rekapAssert(!empty($m[0]), "Definisi fungsi getTutorialScoreMeta harus ditemukan di rekap-nilai.php", $errors);
if (!empty($m[0])) {
    eval($m[0]);
    $metaA = getTutorialScoreMeta(90);
    rekapAssert($metaA['grade'] === 'A' && $metaA['tier'] === 'excellent', "Nilai 90 harus Grade A (tier excellent).", $errors);
    $metaB = getTutorialScoreMeta(78.5);
    rekapAssert($metaB['grade'] === 'B' && $metaB['tier'] === 'good', "Nilai 78.5 harus Grade B (tier good).", $errors);
    $metaC = getTutorialScoreMeta(68);
    rekapAssert($metaC['grade'] === 'C' && $metaC['tier'] === 'fair', "Nilai 68 harus Grade C (tier fair).", $errors);
    $metaD = getTutorialScoreMeta(58);
    rekapAssert($metaD['grade'] === 'D' && $metaD['tier'] === 'poor', "Nilai 58 harus Grade D (tier poor).", $errors);
    $metaE = getTutorialScoreMeta(40);
    rekapAssert($metaE['grade'] === 'E' && $metaE['tier'] === 'fail', "Nilai 40 harus Grade E (tier fail).", $errors);
    $metaNull = getTutorialScoreMeta(null);
    rekapAssert($metaNull['grade'] === '-' && $metaNull['tier'] === 'none', "Nilai null harus Grade - (tier none).", $errors);
}

if (!empty($errors)) {
    echo "\n❌ GAGAL: " . count($errors) . " asersi gagal.\n";
    exit(1);
} else {
    echo "\n✅ Seluruh pengujian Desain Modern Halaman Rekap Nilai BERHASIL 100%!\n";
}
