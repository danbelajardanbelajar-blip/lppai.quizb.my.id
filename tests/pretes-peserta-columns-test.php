<?php
/**
 * Test Suite: Verifikasi Sembunyikan Kolom Status Hadir, Username Tes, Password Tes
 * pada Halaman admin/pretes-peserta.php
 */

$root = dirname(__DIR__);
$pesertaSrc = file_get_contents($root . '/admin/pretes-peserta.php');

$errors = [];

function checkAssert($condition, $passMsg, $failMsg, &$errors) {
    if ($condition) {
        echo " [PASS] $passMsg\n";
    } else {
        echo " [FAIL] $failMsg\n";
        $errors[] = $failMsg;
    }
}

echo "=== Menjalankan Validasi Sembunyikan Kolom Pretes Peserta ===\n";

// 1. Verifikasi th Status Hadir, Username Tes, Password Tes sudah tidak ada di thead tabel
checkAssert(
    strpos($pesertaSrc, '<th>Status Hadir</th>') === false,
    'Kolom "Status Hadir" telah disembunyikan dari tabel',
    'Kolom "Status Hadir" masih ditemukan di thead tabel',
    $errors
);

checkAssert(
    strpos($pesertaSrc, 'Username Tes</th>') === false,
    'Kolom "Username Tes" telah disembunyikan dari tabel',
    'Kolom "Username Tes" masih ditemukan di thead tabel',
    $errors
);

checkAssert(
    strpos($pesertaSrc, 'Password Tes</th>') === false,
    'Kolom "Password Tes" telah disembunyikan dari tabel',
    'Kolom "Password Tes" masih ditemukan di thead tabel',
    $errors
);

// 2. Verifikasi kolom utama yang tetap ada
checkAssert(strpos($pesertaSrc, '<th>No</th>') !== false, 'Header kolom No tetap ada', 'Header No hilang', $errors);
checkAssert(strpos($pesertaSrc, '<th>Nama</th>') !== false, 'Header kolom Nama tetap ada', 'Header Nama hilang', $errors);
checkAssert(strpos($pesertaSrc, '<th>NIM</th>') !== false, 'Header kolom NIM tetap ada', 'Header NIM hilang', $errors);
checkAssert(strpos($pesertaSrc, '<th>Prodi</th>') !== false, 'Header kolom Prodi tetap ada', 'Header Prodi hilang', $errors);
checkAssert(strpos($pesertaSrc, '<th>Program</th>') !== false, 'Header kolom Program tetap ada', 'Header Program hilang', $errors);
checkAssert(strpos($pesertaSrc, '<th>Hari / Periode</th>') !== false, 'Header kolom Hari / Periode tetap ada', 'Header Hari / Periode hilang', $errors);
checkAssert(strpos($pesertaSrc, '<th>Aksi</th>') !== false || strpos($pesertaSrc, '>Aksi</th>') !== false, 'Header kolom Aksi tetap ada', 'Header Aksi hilang', $errors);

// 3. Verifikasi data-status dan data-cred pada row <tr> untuk DataTables filtering
checkAssert(
    strpos($pesertaSrc, 'data-status=') !== false && strpos($pesertaSrc, 'data-cred=') !== false,
    'Row <tr> memuat data-status dan data-cred untuk filter DataTables',
    'Row <tr> tidak memuat data-status atau data-cred',
    $errors
);

// 4. Verifikasi dukungan status di modal edit
checkAssert(
    strpos($pesertaSrc, 'id="modal-status"') !== false,
    'Modal Edit menyediakan dropdown Status Kehadiran',
    'Modal Edit tidak memuat id="modal-status"',
    $errors
);

checkAssert(
    strpos($pesertaSrc, '$action === \'set_credential\'') !== false && strpos($pesertaSrc, 'status = ?') !== false,
    'Handler set_credential mendukung pembaruan status kehadiran',
    'Handler set_credential tidak memperbarui status',
    $errors
);

// 5. Verifikasi DataTables filters tidak memanggil dt.column(6) atau dt.column(7)
checkAssert(
    strpos($pesertaSrc, 'dt.column(6).search(') === false && strpos($pesertaSrc, 'dt.column(7).search(') === false,
    'DataTables script aman dari error out-of-bounds column(6) / column(7)',
    'DataTables script masih memanggil dt.column(6) atau dt.column(7)',
    $errors
);

checkAssert(
    // PRETES-PESERTA-FILTER-STATUS-PRETES: filter Status Hadir & Credentials diganti satu filter Status Pretes Resmi
    strpos($pesertaSrc, 'wantPretes') !== false && strpos($pesertaSrc, "getAttribute('data-pretes')") !== false
        && strpos($pesertaSrc, 'wantStatus') === false && strpos($pesertaSrc, 'wantCred') === false,
    'Filter Status Pretes Resmi (sudah/belum) diintegrasikan ke DataTables ext.search',
    'DataTables ext.search tidak menyaring wantPretes (data-pretes) atau masih memakai filter lama',
    $errors
);

if (!empty($errors)) {
    echo "\nTERDAPAT " . count($errors) . " KEGAGALAN TEST!\n";
    exit(1);
}

echo "\nSemua uji validasi persembunyian kolom Pretes Peserta BERHASIL 100% SEMPURNA!\n";
