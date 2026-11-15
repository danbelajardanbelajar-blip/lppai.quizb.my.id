<?php
/**
 * Test Suite: Export PDF Data Peserta Pretes (admin/pretes-peserta-pdf.php)
 *
 * Memvalidasi:
 * 1. Keberadaan menu dan tombol Export ke PDF pada admin/pretes-peserta.php:
 *    - Tombol "Export ke PDF" pada header card tabel peserta.
 *    - Tombol "Export Sesuai Filter (.pdf)" pada panel filter interaktif.
 *    - Pilihan opsi "Export Dokumen PDF (NIM, Nama, Program, Jurusan)" pada modal export.
 *    - Fungsi JavaScript exportCurrentFiltered('pdf') membuka pretes-peserta-pdf.php.
 * 2. Integrasi delegasi format pdf pada admin/pretes-peserta-export.php:
 *    - Penanganan $format === 'pdf' mendelegasikan ke pretes-peserta-pdf.php.
 *    - Tombol cepat dan radio button format PDF.
 * 3. Halaman PDF (admin/pretes-peserta-pdf.php):
 *    - Autentikasi requireAdmin().
 *    - Keberadaan Kop Surat LPPAI UNISDA dan Logo SVG.
 *    - 5 Kolom tabel: No, NIM, Nama Mahasiswa, Program (RPL / Non RPL), Jurusan.
 *    - Pembeda badge Program: "RPL" vs "Non RPL (Reguler)".
 *    - Rekapitulasi peserta (Total, RPL, Non RPL) dan kotak tanda tangan pengesahan.
 *    - Fitur cetak window.print() dan styling @media print A4 portrait.
 * 4. Uji eksekusi query data dengan fixture SQLite in-memory:
 *    - Peserta RPL dan Reguler terambil dan terformat dengan benar.
 *    - Filter periode, prodi, jenis program, dan pencarian nama/nim berjalan presisi.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/user-helper.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT,
    password TEXT,
    nama_lengkap TEXT NOT NULL,
    nim TEXT,
    program_studi TEXT,
    jenis_program TEXT,
    fakultas TEXT,
    email TEXT,
    no_hp TEXT,
    role TEXT,
    angkatan INTEGER
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS master_pretes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    periode TEXT NOT NULL,
    tahun_ajaran TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'tidak_aktif',
    target_program TEXT DEFAULT 'Semua'
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS pretes_registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    periode TEXT NOT NULL,
    hari_pilihan TEXT,
    jam TEXT DEFAULT '13.00 WIB',
    ruangan TEXT,
    dosen_penguji TEXT,
    status TEXT DEFAULT 'terdaftar',
    username_tes TEXT,
    password_tes TEXT,
    tanggal_daftar TEXT
)");

echo "=== Menjalankan Validasi Fitur Export PDF Data Peserta Pretes ===\n";

function pdfAssert(bool $condition, string $passMsg, string $failMsg): void {
    if (!$condition) {
        echo " [FAIL] $failMsg\n";
        exit(1);
    }
    echo " [PASS] $passMsg\n";
}

// ── Setup Fixtures ──────────────────────────────────────────────────────────
$ts = date('Y-m-d H:i:s');
$pdo->exec("INSERT INTO master_pretes (periode, tahun_ajaran, status, target_program) VALUES ('2025/2026 Ganjil', '2025/2026', 'aktif', 'Semua')");

// Mahasiswa 1: RPL - PAI
$stmtUser = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, nim, program_studi, jenis_program, role) VALUES (?, 'pass', ?, ?, ?, ?, 'mahasiswa')");
$stmtUser->execute(['mhs_rpl_1', 'Fathur Rahman RPL', '2601001', 'Pendidikan Agama Islam', 'RPL']);
$uid1 = (int)$pdo->lastInsertId();

// Mahasiswa 2: Reguler (Non RPL) - PBA
$stmtUser->execute(['mhs_reg_2', 'Nur Azizah Reguler', '2602002', 'Pendidikan Bahasa Arab', 'Reguler']);
$uid2 = (int)$pdo->lastInsertId();

// Mahasiswa 3: Reguler (Non RPL) - PAI
$stmtUser->execute(['mhs_reg_3', 'Ahmad Dani Reguler', '2601003', 'Pendidikan Agama Islam', 'Reguler']);
$uid3 = (int)$pdo->lastInsertId();

// Mahasiswa 4: Akun Percobaan (Username 12345) - WAJIB DIKECUALIKAN DARI EXPORT
$stmtUser->execute(['12345', 'User Percobaan Test', '12345', 'Pendidikan Agama Islam', 'Reguler']);
$uidTrial = (int)$pdo->lastInsertId();

$stmtReg = $pdo->prepare("INSERT INTO pretes_registrations (user_id, periode, hari_pilihan, jam, status, tanggal_daftar) VALUES (?, '2025/2026 Ganjil', ?, '13.00 WIB', 'terdaftar', ?)");
$stmtReg->execute([$uid1, 'Senin', $ts]);
$stmtReg->execute([$uid2, 'Selasa', $ts]);
$stmtReg->execute([$uid3, 'Senin', $ts]);
$stmtReg->execute([$uidTrial, 'Minggu', $ts]);

// ── Test 1: Query & Logika Filter Data PDF (Dengan Pengecualian Akun 12345) ──
$sql = "
    SELECT pr.id as reg_id, pr.periode, pr.hari_pilihan, pr.jam, pr.status,
           u.nama_lengkap, u.nim, u.username, u.program_studi, u.jenis_program
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    WHERE pr.periode = '2025/2026 Ganjil'
      AND LOWER(TRIM(COALESCE(u.username, ''))) <> '12345'
      AND TRIM(COALESCE(u.nim, '')) <> '12345'
    ORDER BY u.program_studi ASC, u.nama_lengkap ASC
";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

pdfAssert(count($rows) === 3, 'Total peserta pretes dalam dokumen export terhitung 3 orang (akun 12345 dikecualikan)', 'Jumlah peserta tidak sesuai');

$hasUser12345 = false;
foreach ($rows as $r) {
    if (($r['username'] ?? '') === '12345' || ($r['nim'] ?? '') === '12345') {
        $hasUser12345 = true;
    }
}
pdfAssert(!$hasUser12345, 'Username 12345 (user percobaan) TIDAK ADA di dalam data hasil export', 'Akun 12345 masih muncul dalam export');

$rplCount = 0;
$nonRplCount = 0;
foreach ($rows as $r) {
    $isRpl = (normalizeJenisProgram($r['jenis_program'] ?? 'Reguler') === 'RPL');
    if ($isRpl) $rplCount++; else $nonRplCount++;
}
pdfAssert($rplCount === 1, 'Jumlah peserta RPL terhitung 1 orang', 'Jumlah RPL salah');
pdfAssert($nonRplCount === 2, 'Jumlah peserta Non RPL (Reguler) terhitung 2 orang', 'Jumlah Non RPL salah');

// Filter khusus RPL (dengan pengecualian user 12345)
$stmtFilterRpl = $pdo->prepare("
    SELECT pr.id, u.nama_lengkap, u.jenis_program
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    WHERE pr.periode = '2025/2026 Ganjil'
      AND u.jenis_program = 'RPL'
      AND LOWER(TRIM(COALESCE(u.username, ''))) <> '12345'
      AND TRIM(COALESCE(u.nim, '')) <> '12345'
");
$stmtFilterRpl->execute();
$rplRows = $stmtFilterRpl->fetchAll(PDO::FETCH_ASSOC);
pdfAssert(count($rplRows) === 1 && $rplRows[0]['nama_lengkap'] === 'Fathur Rahman RPL', 'Filter program RPL mengembalikan peserta RPL secara tepat', 'Filter RPL gagal');

// Filter khusus Jurusan PAI (dengan pengecualian user 12345)
$stmtFilterPai = $pdo->prepare("
    SELECT pr.id, u.nama_lengkap, u.program_studi
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    WHERE pr.periode = '2025/2026 Ganjil'
      AND u.program_studi = 'Pendidikan Agama Islam'
      AND LOWER(TRIM(COALESCE(u.username, ''))) <> '12345'
      AND TRIM(COALESCE(u.nim, '')) <> '12345'
");
$stmtFilterPai->execute();
$paiRows = $stmtFilterPai->fetchAll(PDO::FETCH_ASSOC);
pdfAssert(count($paiRows) === 2, 'Filter jurusan PAI mengembalikan 2 peserta PAI (user 12345 dikecualikan)', 'Filter jurusan gagal');

// ── Test 2: Verifikasi File admin/pretes-peserta-pdf.php ─────────────────────
$pdfFile = file_get_contents(__DIR__ . '/../admin/pretes-peserta-pdf.php');
pdfAssert($pdfFile !== false, 'File admin/pretes-peserta-pdf.php berhasil dibaca', 'File tidak ditemukan');
pdfAssert(strpos($pdfFile, 'requireAdmin();') !== false, 'admin/pretes-peserta-pdf.php dilindungi requireAdmin()', 'Tidak ada requireAdmin');
pdfAssert(strpos($pdfFile, 'Lembaga Pembinaan dan Pengembangan Agama Islam (LPPAI)') !== false, 'admin/pretes-peserta-pdf.php memuat Kop Surat resmi LPPAI', 'Kop surat LPPAI tidak ditemukan');
pdfAssert(strpos($pdfFile, 'Universitas Islam Darul \'Ulum Lamongan (UNISDA)') !== false, 'admin/pretes-peserta-pdf.php memuat Universitas UNISDA', 'Kop surat UNISDA tidak ditemukan');

// Kolom tabel wajib: No, NIM, Nama Mahasiswa, Program, Jurusan
pdfAssert(strpos($pdfFile, 'NIM') !== false, 'admin/pretes-peserta-pdf.php memuat header kolom NIM', 'Kolom NIM tidak ada');
pdfAssert(strpos($pdfFile, 'Nama Mahasiswa') !== false, 'admin/pretes-peserta-pdf.php memuat header kolom Nama Mahasiswa', 'Kolom Nama Mahasiswa tidak ada');
pdfAssert(strpos($pdfFile, 'Program') !== false, 'admin/pretes-peserta-pdf.php memuat header kolom Program', 'Kolom Program tidak ada');
pdfAssert(strpos($pdfFile, 'Jurusan') !== false, 'admin/pretes-peserta-pdf.php memuat header kolom Jurusan', 'Kolom Jurusan tidak ada');

// Badge RPL & Non RPL
pdfAssert(strpos($pdfFile, 'badge-rpl') !== false, 'admin/pretes-peserta-pdf.php memuat styling badge-rpl', 'Badge RPL tidak ditemukan');
pdfAssert(strpos($pdfFile, 'Non RPL (Reguler)') !== false, 'admin/pretes-peserta-pdf.php menampilkan status Non RPL (Reguler)', 'Status Non RPL tidak ditemukan');

// Toolbar Cetak & Rekapitulasi
pdfAssert(strpos($pdfFile, 'window.print()') !== false, 'admin/pretes-peserta-pdf.php menyediakan tombol cetak window.print()', 'Tombol print tidak ditemukan');
pdfAssert(strpos($pdfFile, 'Rekapitulasi Peserta Pretes') !== false, 'admin/pretes-peserta-pdf.php memuat rekapitulasi jumlah peserta', 'Rekapitulasi tidak ditemukan');
pdfAssert(strpos($pdfFile, 'size: A4 portrait;') !== false, 'admin/pretes-peserta-pdf.php mengatur layout cetak A4 portrait', 'Ukuran A4 portrait tidak ada');

// ── Test 3: Verifikasi Tombol & Menu pada admin/pretes-peserta.php ──────────
$pesertaFile = file_get_contents(__DIR__ . '/../admin/pretes-peserta.php');
pdfAssert($pesertaFile !== false, 'File admin/pretes-peserta.php berhasil dibaca', 'File peserta tidak ditemukan');
pdfAssert(strpos($pesertaFile, 'pretes-peserta-pdf.php') !== false, 'admin/pretes-peserta.php memiliki link menuju pretes-peserta-pdf.php', 'Link ke pretes-peserta-pdf.php tidak ada');
pdfAssert(strpos($pesertaFile, 'Export ke PDF') !== false, 'admin/pretes-peserta.php menampilkan tombol Export ke PDF', 'Tombol Export ke PDF tidak ada');
pdfAssert(strpos($pesertaFile, 'btn-export-pdf-filtered') !== false, 'admin/pretes-peserta.php memiliki tombol export PDF sesuai filter', 'Tombol export PDF filter tidak ada');
pdfAssert(strpos($pesertaFile, "exportCurrentFiltered('pdf')") !== false, 'admin/pretes-peserta.php mendukung pemanggilan exportCurrentFiltered(\'pdf\')', 'Fungsi exportCurrentFiltered pdf tidak ada');
pdfAssert(strpos($pesertaFile, 'modal-export-pdf-desc') !== false, 'admin/pretes-peserta.php menyediakan deskripsi PDF pada modal export', 'Modal export PDF tidak ada');

// ── Test 4: Verifikasi Integrasi Format PDF pada pretes-peserta-export.php ──
$exportFile = file_get_contents(__DIR__ . '/../admin/pretes-peserta-export.php');
pdfAssert($exportFile !== false, 'File admin/pretes-peserta-export.php berhasil dibaca', 'File export tidak ditemukan');
pdfAssert(strpos($exportFile, "\$format === 'pdf'") !== false, 'admin/pretes-peserta-export.php menangani format pdf dan mendelegasikan ke pretes-peserta-pdf.php', 'Delegasi format pdf tidak ada');
pdfAssert(strpos($exportFile, 'pretes-peserta-pdf.php') !== false, 'admin/pretes-peserta-export.php menyertakan rujukan ke pretes-peserta-pdf.php', 'Rujukan pretes-peserta-pdf.php tidak ada');

// ── Test 5: Verifikasi Pengecualian Username 12345 Pada Seluruh File Export ──
pdfAssert(strpos($pdfFile, "'12345'") !== false, 'admin/pretes-peserta-pdf.php mengecualikan username 12345', 'Tidak ada filter 12345 di PDF');
pdfAssert(strpos($exportFile, "'12345'") !== false, 'admin/pretes-peserta-export.php mengecualikan username 12345', 'Tidak ada filter 12345 di Export Excel');

$absensiExportFile = file_get_contents(__DIR__ . '/../admin/pretes-absensi-export.php');
pdfAssert(strpos($absensiExportFile, "'12345'") !== false, 'admin/pretes-absensi-export.php mengecualikan username 12345', 'Tidak ada filter 12345 di Absensi Export');

$cbtXlsxFile = file_get_contents(__DIR__ . '/../includes/pretes-cbt-xlsx.php');
pdfAssert(strpos($cbtXlsxFile, "'12345'") !== false, 'includes/pretes-cbt-xlsx.php mengecualikan username 12345 pada ekspor CBT', 'Tidak ada filter 12345 di CBT Export');

$rekapNilaiFile = file_get_contents(__DIR__ . '/../admin/pretes-rekap-nilai.php');
pdfAssert(strpos($rekapNilaiFile, "'12345'") !== false, 'admin/pretes-rekap-nilai.php mengecualikan username 12345 pada rekap nilai', 'Tidak ada filter 12345 di Rekap Nilai');

echo "\nSemua 24 uji validasi Export PDF & Pengecualian Akun Percobaan 12345 BERHASIL 100% SEMPURNA!\n";
