<?php
/**
 * Test Verifikasi Fitur Inline Accordion & AJAX Penilaian pada pretes-panel.php
 * Jalankan: php tests/pretes-panel-accordion-test.php
 */
require_once __DIR__ . '/../includes/pretes-helper.php';

function assertTrue($cond, $msg) {
    if (!$cond) {
        throw new RuntimeException("Assertion failed: $msg");
    }
}

// 1. Verifikasi fungsi getPretesPredikat
assertTrue(getPretesPredikat(100) === 'A', '100 harus A');
assertTrue(getPretesPredikat(85) === 'A', '85 harus A');
assertTrue(getPretesPredikat(84.9) === 'B', '84.9 harus B');
assertTrue(getPretesPredikat(75) === 'B', '75 harus B');
assertTrue(getPretesPredikat(74.9) === 'C', '74.9 harus C');
assertTrue(getPretesPredikat(65) === 'C', '65 harus C');
assertTrue(getPretesPredikat(64.9) === 'D', '64.9 harus D');
assertTrue(getPretesPredikat(55) === 'D', '55 harus D');
assertTrue(getPretesPredikat(54.9) === 'E', '54.9 harus E');
assertTrue(getPretesPredikat(0) === 'E', '0 harus E');
assertTrue(getPretesPredikat(null) === '-', 'null harus -');

// 2. Verifikasi konten includes/pretes-panel.php
$panelFile = __DIR__ . '/../includes/pretes-panel.php';
assertTrue(file_exists($panelFile), 'pretes-panel.php harus ada');
$content = file_get_contents($panelFile);

// Verifikasi top card #nilai-pretes sudah dihapus
assertTrue(strpos($content, '<div class="card" id="nilai-pretes"') === false, 'Card top #nilai-pretes harus sudah dihapus agar tidak menutupi tabel');

// Verifikasi inline accordion class & structure ada di tabel
assertTrue(strpos($content, 'pretes-inline-scoring-box') !== false, 'Class pretes-inline-scoring-box harus ada');
assertTrue(strpos($content, 'student-header-toggle') !== false, 'Class student-header-toggle harus ada');
assertTrue(strpos($content, 'btn-toggle-inline-score') !== false, 'Class btn-toggle-inline-score harus ada');
assertTrue(strpos($content, 'form-inline-score') !== false, 'Class form-inline-score harus ada');
assertTrue(strpos($content, 'is_ajax') !== false, 'Parameter is_ajax harus didukung untuk AJAX saving');

// Verifikasi elemen mobile ergonomic touch support
assertTrue(strpos($content, 'pretes-preset-chip') !== false, 'Class pretes-preset-chip harus ada untuk touch preset tombol');
assertTrue(strpos($content, 'pretes-score-card') !== false, 'Class pretes-score-card harus ada untuk card scoring mobile');
assertTrue(strpos($content, 'save-success-glow') !== false, 'Class save-success-glow harus ada untuk visual feedback');
assertTrue(strpos($content, 'inputmode="decimal"') !== false, 'Atribut inputmode decimal harus aktif untuk keyboard HP yang ramah angka');

// Verifikasi 12 komponen ada dalam form accordion
assertTrue(strpos($content, 'name="scores[<?= $key ?>]"') !== false, 'Input skor dinamis harus ada di form inline');

$components = pretesComponents();
foreach ($components as $key => $label) {
    assertTrue(strpos($content, "'$key'") !== false, "Key komponen {$key} harus terdaftar di panel");
}

// Verifikasi DataTables column definition (2 columns: Nama & Status, Aksi)
assertTrue(strpos($content, '<th>Nama & Status Mahasiswa</th>') !== false, 'Header kolom 1 DataTables harus konsisten');
assertTrue(strpos($content, '<th class="student-action">Aksi</th>') !== false, 'Header kolom 2 DataTables harus konsisten');

// Verifikasi Quick Fill toolbar presets
assertTrue(strpos($content, '[60, 70, 75, 80, 85, 90, 100]') !== false, 'Array preset quick fill lengkap');
assertTrue(strpos($content, 'data-score="<?= $preset ?>"') !== false, 'Atribut data-score preset dinamis terpasang');

echo "PASS: Seluruh pengujian Inline Accordion Penilaian & AJAX Response terverifikasi valid dan sukses!\n";
