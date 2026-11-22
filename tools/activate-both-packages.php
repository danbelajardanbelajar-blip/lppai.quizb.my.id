<?php
/**
 * LPPAI Corner - CLI Tool untuk Mengaktifkan Kedua Paket Pretes Resmi
 * (Paket 1 - Ubudiyah / Islam & Paket 2 - Kristen Protestan)
 *
 * Pemakaian:
 *   php tools/activate-both-packages.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/pretes-cbt.php';

try {
    $pdo = getDBConnection();
    echo "=== AKTIVASI KEDUA PAKET SOAL RESMI CBT LPPAI ===\n";
    pretesCbtEnsureMultiPackageActive($pdo);

    $pkgs = pretesCbtGetActivePackages($pdo);
    echo "\nPaket Aktif Saat Ini:\n";
    foreach ($pkgs as $p) {
        echo "  [OK] ID {$p['id']} - {$p['code']}: {$p['name']} ({$p['question_count_actual']} butir aktif)\n";
    }

    $activeSes = pretesCbtGetActiveSession($pdo);
    if ($activeSes) {
        $pkgList = !empty($activeSes['package_names']) ? implode(', ', $activeSes['package_names']) : '-';
        echo "\nSesi Resmi Aktif:\n";
        echo "  [OK] ID {$activeSes['id']} - {$activeSes['code']}: {$activeSes['title']}\n";
        echo "       Paket terhubung: {$pkgList}\n";
        echo "       Sakelar Ujian: " . ((int)($activeSes['exam_open'] ?? 1) === 1 ? 'DIBUKA (Peserta dapat memulai ujian)' : 'DITUTUP') . "\n";
    } else {
        echo "\n[PERINGATAN] Belum ada sesi resmi aktif.\n";
    }

    echo "\nKedua paket soal resmi berhasil diaktifkan. Mahasiswa kini dapat memilih salah satu paket saat memulai pretes.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[GAGAL] " . $e->getMessage() . PHP_EOL);
    exit(1);
}
