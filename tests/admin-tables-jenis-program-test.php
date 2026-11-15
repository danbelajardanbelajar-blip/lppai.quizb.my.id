<?php
/**
 * Test Suite: Verifikasi Kolom Program (RPL / Reguler) pada Seluruh Halaman Tabel Admin
 * Memastikan setiap halaman admin yang memuat tabel data mahasiswa memiliki kolom Program/Jenis Program
 * serta merender badge RPL / Reguler secara konsisten.
 */

$root = dirname(__DIR__);

$checks = [
    'admin/aktivasi-mahasiswa.php' => [
        '<th>Program</th>',
        'renderJenisProgramBadge'
    ],
    'admin/dashboard.php' => [
        '<th style="padding:10px 14px;">Program</th>',
        'renderJenisProgramBadge($r[\'jenis_program\'] ?? \'Reguler\', true)'
    ],
    'admin/kelola-nilai.php' => [
        '<th>Program</th>',
        '"className": "text-center", "targets": [0, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16]'
    ],
    'admin/ajax-kelola-nilai.php' => [
        "5 => 'u.jenis_program'",
        "renderJenisProgramBadge(\$row['jenis_program'] ?? 'Reguler', true)"
    ],
    'admin/pengambilan-sertifikat.php' => [
        '<th>Program</th>',
        'renderJenisProgramBadge($c[\'jenis_program\'] ?? \'Reguler\', true)',
        'renderJenisProgramBadge($log[\'jenis_program\'] ?? \'Reguler\', true)'
    ],
    'admin/pretes-peserta.php' => [
        '<th>Program</th>',
        'renderJenisProgramBadge($rowJenis, true)',
        "dt.column(4).search('')",
        "dt.column(5).search("
    ],
    'admin/pretes-jadwal.php' => [
        '<th>Program</th>',
        'renderJenisProgramBadge($p[\'jenis_program\'] ?? \'Reguler\', true)'
    ],
    'admin/pretes-absensi-export.php' => [
        '<th>Program</th>',
        'normalizeJenisProgram($row[\'jenis_program\'] ?? \'Reguler\')'
    ],
    'admin/pretes-rekap-nilai.php' => [
        '<th rowspan="2" style="width:50px;">Program</th>',
        '<th rowspan="2" style="min-width:80px; text-align:center;">Program</th>',
        'renderJenisProgramBadge($r[\'jenis_program\'] ?? \'Reguler\', true)'
    ],
    'admin/pretes-bank-soal.php' => [
        "'jenis_program' => normalizeJenisProgram(\$a['jenis_program'] ?? 'Reguler')",
        "'jenis_program_badge' => renderJenisProgramBadge(\$a['jenis_program'] ?? 'Reguler', true)"
    ],
    'includes/pretes-cbt-admin-view.php' => [
        '<th>Program</th>',
        'renderJenisProgramBadge($attempt[\'jenis_program\'] ?? \'Reguler\', true)'
    ],
    'assets/js/pretes-cbt-admin.js' => [
        'att.jenis_program_badge',
        "'<td>' + programBadge + '</td>'"
    ],
    'admin/tutorial-data-pendaftar.php' => [
        '<th>Program</th>',
        'renderJenisProgramBadge($reg[\'jenis_program\'] ?? \'Reguler\', true)',
        'renderJenisProgramBadge($p[\'jenis_program\'] ?? \'Reguler\', true)'
    ],
    'admin/tutorial-hasil-plotting.php' => [
        '<th>Program</th>',
        'renderJenisProgramBadge($r[\'jenis_program\'] ?? \'Reguler\', true)'
    ],
    'admin/tutorial-pendaftaran-kolektif.php' => [
        '>Program</th>',
        'renderJenisProgramBadge($m[\'jenis_program\'] ?? \'Reguler\', true)'
    ],
    'admin/jaminan.php' => [
        '<th style="border-bottom:2px solid #e2e8f0; padding:10px;">Program</th>',
        'renderJenisProgramBadge($row[\'jenis_program\'] ?? \'Reguler\', true)'
    ],
    'admin/users.php' => [
        '<th>Jenis Program</th>'
    ]
];

$failures = [];
$totalVerified = 0;

foreach ($checks as $fileRel => $patterns) {
    $filePath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fileRel);
    if (!file_exists($filePath)) {
        $failures[] = "File tidak ditemukan: $fileRel";
        continue;
    }

    $content = file_get_contents($filePath);
    if ($fileRel === 'admin/dashboard.php' && file_exists($root . '/includes/admin-dashboard-registrations-view.php')) {
        $content .= file_get_contents($root . '/includes/admin-dashboard-registrations-view.php');
    }
    foreach ($patterns as $pat) {
        if (strpos($content, $pat) === false) {
            $failures[] = "[$fileRel] Pattern tidak ditemukan: " . htmlspecialchars($pat);
        } else {
            $totalVerified++;
        }
    }
}

if (!empty($failures)) {
    echo "FAIL: Ditemukan kegagalan validasi kolom Program pada halaman admin:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    exit(1);
}

echo "OK: Seluruh verifikasi kolom Program (RPL/Reguler) pada tabel admin BERHASIL ($totalVerified verifikasi pattern pada " . count($checks) . " file lulus 100%).\n";
exit(0);
