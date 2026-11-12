<?php
/**
 * Test Suite: Filter Target Pengumuman (RPL vs Reguler & Sasaran Peserta)
 * Memverifikasi helpers, normalisasi, gating hak akses pengumuman, dan integrasi UI admin/dashboard.
 */

require_once __DIR__ . '/../includes/announcement-helper.php';

$errors = [];
function annAssert(bool $cond, string $msg, array &$errors): void {
    if (!$cond) {
        $errors[] = $msg;
    }
}

// ── 1. Uji Helper Normalisasi ────────────────────────────────────────────────
annAssert(normalizeAnnouncementTargetProgram('rpl') === 'RPL', 'normalizeAnnouncementTargetProgram rpl harus RPL', $errors);
annAssert(normalizeAnnouncementTargetProgram('Reguler') === 'Reguler', 'normalizeAnnouncementTargetProgram Reguler harus Reguler', $errors);
annAssert(normalizeAnnouncementTargetProgram('semua') === 'Semua', 'normalizeAnnouncementTargetProgram semua harus Semua', $errors);
annAssert(normalizeAnnouncementTargetProgram(null) === 'Semua', 'normalizeAnnouncementTargetProgram null harus Semua', $errors);

annAssert(normalizeAnnouncementTargetPeserta('pretes') === 'pretes', 'normalizeAnnouncementTargetPeserta pretes harus pretes', $errors);
annAssert(normalizeAnnouncementTargetPeserta('invalid') === 'semua', 'normalizeAnnouncementTargetPeserta invalid harus default semua', $errors);

// ── 2. Uji Kelayakan Pengumuman (isUserEligibleForAnnouncement) ───────────────
$userRpl = [
    'id'            => 10,
    'nim'           => '25062102001',
    'nama_lengkap'  => 'Mahasiswa RPL',
    'jenis_program' => 'RPL',
];

$userReguler = [
    'id'            => 20,
    'nim'           => '2401010001',
    'nama_lengkap'  => 'Mahasiswa Reguler',
    'jenis_program' => 'Reguler',
];

// Pengumuman khusus Reguler (Tutorial Gelombang 1)
$annReguler = [
    'id'             => 1,
    'tipe'           => 'pendaftaran_gel1',
    'judul'          => 'Pendaftaran Tutorial Gelombang 1',
    'target_program' => 'Reguler',
    'target_peserta' => 'tutorial',
];

// Pengumuman khusus RPL (Pretes Khusus RPL)
$annRpl = [
    'id'             => 2,
    'tipe'           => 'pendaftaran_pretes',
    'judul'          => 'Pendaftaran Pretes Khusus Mahasiswa RPL',
    'target_program' => 'RPL',
    'target_peserta' => 'semua',
];

// Pengumuman Umum Semua Program
$annUmum = [
    'id'             => 3,
    'tipe'           => 'umum',
    'judul'          => 'Kajian Akbar Kampus',
    'target_program' => 'Semua',
    'target_peserta' => 'semua',
];

// Mahasiswa RPL TIDAK BOLEH melihat pengumuman khusus Reguler
annAssert(
    !isUserEligibleForAnnouncement($userRpl, $annReguler),
    'Mahasiswa RPL tidak boleh menerima pengumuman khusus Reguler (Pendaftaran Tutorial).',
    $errors
);

// Mahasiswa RPL HARUS BISA melihat pengumuman khusus RPL
annAssert(
    isUserEligibleForAnnouncement($userRpl, $annRpl),
    'Mahasiswa RPL harus bisa melihat pengumuman khusus RPL.',
    $errors
);

// Mahasiswa RPL HARUS BISA melihat pengumuman Umum
annAssert(
    isUserEligibleForAnnouncement($userRpl, $annUmum),
    'Mahasiswa RPL harus bisa melihat pengumuman umum (Semua Program).',
    $errors
);

// Mahasiswa Reguler TIDAK BOLEH melihat pengumuman khusus RPL
annAssert(
    !isUserEligibleForAnnouncement($userReguler, $annRpl),
    'Mahasiswa Reguler tidak boleh menerima pengumuman khusus RPL.',
    $errors
);

// Mahasiswa Reguler HARUS BISA melihat pengumuman Reguler
annAssert(
    isUserEligibleForAnnouncement($userReguler, $annReguler, ['has_tutorial_reg' => true]),
    'Mahasiswa Reguler peserta tutorial harus bisa melihat pengumuman tutorial.',
    $errors
);

// Pengumuman tutorial lama (tanpa target_program/default Semua) tetap diblokir untuk RPL
$annTutorialLegacy = [
    'id'             => 4,
    'tipe'           => 'pendaftaran_gel1',
    'judul'          => 'Pendaftaran Tutorial Gel 1',
    'target_program' => 'Semua',
    'target_peserta' => 'semua',
];
annAssert(
    !isUserEligibleForAnnouncement($userRpl, $annTutorialLegacy),
    'Mahasiswa RPL harus otomatis terlindungi dari pengumuman tutorial reguler meskipun target_program Semua.',
    $errors
);

// Uji target sasaran peserta: lulus_pretes vs belum_lulus_pretes
$annLulusOnly = [
    'id'             => 5,
    'tipe'           => 'umum',
    'target_program' => 'Semua',
    'target_peserta' => 'lulus_pretes',
];
annAssert(
    isUserEligibleForAnnouncement($userReguler, $annLulusOnly, ['is_pretes_lulus' => true]),
    'Mahasiswa yang lulus pretes harus menerima pengumuman target lulus_pretes.',
    $errors
);
annAssert(
    !isUserEligibleForAnnouncement($userReguler, $annLulusOnly, ['is_pretes_lulus' => false]),
    'Mahasiswa yang belum lulus pretes tidak boleh menerima pengumuman target lulus_pretes.',
    $errors
);

// ── 3. Uji Auto-Migrasi & Normalisasi Database SQLite ─────────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Buat skema lawas tanpa kolom target_program dan target_peserta
$pdo->exec("
    CREATE TABLE announcements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        judul VARCHAR(255) NOT NULL,
        konten TEXT NOT NULL,
        tipe VARCHAR(50) NOT NULL,
        link_tujuan VARCHAR(255),
        is_active INTEGER DEFAULT 1
    );
");

$pdo->exec("INSERT INTO announcements (judul, konten, tipe) VALUES ('Pendaftaran Tutorial Gel 1', 'Isi', 'pendaftaran_gel1')");
$pdo->exec("INSERT INTO announcements (judul, konten, tipe) VALUES ('Pengumuman Hasil Pretes 2026/2027', 'Isi', 'hasil_pretes')");
$pdo->exec("INSERT INTO announcements (judul, konten, tipe) VALUES ('Pengumuman Umum Kampus', 'Isi', 'umum')");

// Jalankan auto-migration
ensureAnnouncementTargetColumns($pdo);

// Verifikasi kolom berhasil ditambahkan
$cols = $pdo->query("PRAGMA table_info(announcements)")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_map(fn($c) => strtolower((string)$c['name']), $cols);
annAssert(in_array('target_program', $colNames, true), 'Kolom target_program harus berhasil ditambahkan.', $errors);
annAssert(in_array('target_peserta', $colNames, true), 'Kolom target_peserta harus berhasil ditambahkan.', $errors);

// Verifikasi data lawas tutorial & hasil pretes ternormalisasi ke 'Reguler'
$rowTut = $pdo->query("SELECT target_program, target_peserta FROM announcements WHERE tipe = 'pendaftaran_gel1'")->fetch();
annAssert($rowTut['target_program'] === 'Reguler' && $rowTut['target_peserta'] === 'tutorial', 'Pengumuman tutorial lama harus dinormalisasi ke Reguler & tutorial.', $errors);

$rowHasil = $pdo->query("SELECT target_program, target_peserta FROM announcements WHERE tipe = 'hasil_pretes'")->fetch();
annAssert($rowHasil['target_program'] === 'Reguler' && $rowHasil['target_peserta'] === 'pretes', 'Pengumuman hasil pretes lama harus dinormalisasi ke Reguler & pretes.', $errors);

$rowUmum = $pdo->query("SELECT target_program, target_peserta FROM announcements WHERE tipe = 'umum'")->fetch();
annAssert($rowUmum['target_program'] === 'Semua', 'Pengumuman umum tetap Semua program.', $errors);

// ── 4. Uji Integrasi UI File PHP ─────────────────────────────────────────────
$root = dirname(__DIR__);
$adminPengumumanSrc = file_get_contents($root . '/admin/pengumuman.php');
annAssert(str_contains($adminPengumumanSrc, 'name="target_program"'), 'admin/pengumuman.php harus memiliki input target_program.', $errors);
annAssert(str_contains($adminPengumumanSrc, 'name="target_peserta"'), 'admin/pengumuman.php harus memiliki input target_peserta.', $errors);
annAssert(str_contains($adminPengumumanSrc, 'renderAnnouncementTargetBadges('), 'admin/pengumuman.php harus menampilkan badge target audiens di tabel.', $errors);

$dashboardSrc = file_get_contents($root . '/pages/dashboard.php');
annAssert(str_contains($dashboardSrc, 'isUserEligibleForAnnouncement('), 'pages/dashboard.php harus memanggil isUserEligibleForAnnouncement().', $errors);

if (!empty($errors)) {
    foreach ($errors as $e) {
        fwrite(STDERR, "[FAIL] $e\n");
    }
    exit(1);
}

echo "OK: announcement-target-filter tests passed (RPL vs Reguler isolation, target participants, auto-migration, and UI verified).\n";
