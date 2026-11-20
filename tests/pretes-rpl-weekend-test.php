<?php
/**
 * Test: Sabtu & Ahad khusus periode pretes RPL (PRETES-RPL-SABTU-AHAD).
 * - Hari periode: Senin–Jumat; + Sabtu & Ahad hanya bila target_program = RPL.
 * - Kolom kuota/penguji Sabtu & Ahad dibuat otomatis; disimpan hanya untuk periode RPL.
 * - Label tanggal: Sabtu = mulai + 5, Ahad = mulai + 6.
 * - Validasi hari pendaftaran mengikuti periode pendaftaran; penguji akhir pekan dikenali.
 * - Integrasi halaman Dosen Penguji, pendaftaran, peserta, jadwal, ekspor.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$fail = 0;
$t = function (bool $ok, string $msg) use (&$fail): void { echo ($ok ? ' [PASS] ' : '❌ [FAIL] ') . $msg . "\n"; if (!$ok) $fail++; };

require_once $root . '/includes/pretes-helper.php';
require_once $root . '/includes/pretes-examiner.php';
require_once $root . '/includes/pretes-cbt-importer.php';

$rpl = ['target_program' => 'RPL', 'tanggal_mulai' => '2026-11-23'];
$all = ['target_program' => 'Semua', 'tanggal_mulai' => '2026-11-23'];
$reg = ['target_program' => 'Reguler'];

// 1) Aturan hari
$t(pretesPeriodDays($rpl) === ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Ahad'], 'Periode Khusus RPL: Senin–Ahad');
$t(pretesPeriodDays($all) === pretesWeekdays() && pretesPeriodDays($reg) === pretesWeekdays() && pretesPeriodDays(null) === pretesWeekdays(), 'Periode Semua/Reguler/tanpa periode: Senin–Jumat');
$t(pretesPeriodAllowsWeekend(['target_program' => 'rpl']) && !pretesPeriodAllowsWeekend($all), 'Deteksi periode RPL (tidak peka huruf)');
$t(pretesNormalizeDay('minggu') === 'Ahad' && pretesNormalizeDay(' SABTU ') === 'Sabtu' && pretesNormalizeDay('libur') === '', 'Normalisasi nama hari');
$t(pretesDisplayDays($all, ['Sabtu' => 2]) === ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'] && pretesDisplayDays(null, []) === pretesWeekdays(), 'Tampilan: Sabtu/Ahad muncul bila sudah ada pesertanya');
$t(pretesDayOffset('Sabtu') === 5 && pretesDayOffset('Ahad') === 6 && pretesDayOffset('Senin') === 0, 'Offset tanggal Sabtu +5, Ahad +6');

// 2) Label tanggal
$lr = getPretesPeriodDayLabels($rpl);
$t(($lr['days']['Sabtu'] ?? '') === 'Sabtu, 28 November 2026' && ($lr['days']['Ahad'] ?? '') === 'Ahad, 29 November 2026' && $lr['short_range'] === '23 – 29 November 2026', 'Label & rentang tanggal periode RPL sampai Ahad');
$la = getPretesPeriodDayLabels($all);
$t(!isset($la['days']['Sabtu']) && $la['short_range'] === '23 – 27 November 2026', 'Periode Semua tetap Senin–Jumat');
$t(array_keys(getPretesPeriodDayLabels(['target_program' => 'RPL'])['days']) === pretesAllDays(), 'Tanpa tanggal mulai: label hari RPL tetap 7 hari');

// 3) Skema & penyimpanan slot akhir pekan
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE master_pretes (id INTEGER PRIMARY KEY, periode TEXT, tahun_ajaran TEXT, status TEXT, kuota_senin INT, kuota_jumat INT, penguji_senin TEXT, penguji_jumat TEXT)");
ensurePretesPeriodTargetColumns($pdo);
$cols = array_column($pdo->query('PRAGMA table_info(master_pretes)')->fetchAll(), 'name');
$t(count(array_intersect(['kuota_sabtu', 'kuota_ahad', 'penguji_sabtu', 'penguji_ahad', 'target_program'], $cols)) === 5, 'Kolom Sabtu/Ahad ditambahkan otomatis');
$pdo->exec("INSERT INTO master_pretes (id, periode, tahun_ajaran, status, target_program) VALUES (1, 'Pretes RPL', '2026', 'aktif', 'RPL'), (2, 'Pretes Umum', '2026', 'nonaktif', 'Semua')");
pretesSaveWeekendSlots($pdo, 1, 'RPL', ['kuota_sabtu' => '60', 'kuota_ahad' => '30', 'penguji_sabtu' => ['Dr. A', ' ', 'Dr. B'], 'penguji_ahad' => ['Dr. C']]);
$r1 = $pdo->query('SELECT * FROM master_pretes WHERE id = 1')->fetch();
$t((int)$r1['kuota_sabtu'] === 60 && $r1['penguji_sabtu'] === 'Dr. A|||Dr. B' && (int)$r1['kuota_ahad'] === 30 && $r1['penguji_ahad'] === 'Dr. C', 'Periode RPL: kuota & penguji Sabtu/Ahad tersimpan');
pretesSaveWeekendSlots($pdo, 1, 'RPL', ['kuota_senin' => 5]); // form lain tanpa isian akhir pekan
$t((int)$pdo->query('SELECT kuota_sabtu FROM master_pretes WHERE id = 1')->fetchColumn() === 60, 'Form tanpa isian Sabtu/Ahad tidak menghapus slot akhir pekan');
pretesSaveWeekendSlots($pdo, 2, 'Semua', ['kuota_sabtu' => '60', 'penguji_sabtu' => ['Dr. A']]);
$r2 = $pdo->query('SELECT * FROM master_pretes WHERE id = 2')->fetch();
$t((int)$r2['kuota_sabtu'] === 0 && $r2['penguji_sabtu'] === '', 'Periode non-RPL: Sabtu/Ahad dikosongkan');

// 4) Hari valid per pendaftaran & penguji
$pdo->exec("CREATE TABLE pretes_registrations (id INTEGER PRIMARY KEY, user_id INT, periode TEXT, hari_pilihan TEXT)");
$pdo->exec("INSERT INTO pretes_registrations VALUES (10, 1, 'Pretes RPL', 'Sabtu'), (20, 2, 'Pretes Umum', 'Senin')");
$t(in_array('Ahad', pretesDaysForRegistration($pdo, 10), true) && !in_array('Sabtu', pretesDaysForRegistration($pdo, 20), true), 'Hari valid mengikuti periode pendaftaran');
$t(pretesPeriodByName($pdo, 'Pretes RPL')['id'] == 1 && pretesPeriodByName($pdo, 'Tidak Ada') === null, 'Cari periode berdasarkan nama');
$names = pretesExaminerPeriodNames($pdo->query('SELECT * FROM master_pretes WHERE id = 1')->fetch());
$t(in_array('Dr. C', $names, true) && in_array('Dr. B', $names, true), 'Penguji Sabtu/Ahad ikut terdaftar sebagai penguji terjadwal');
$t(normalizePretesDay('Minggu') === 'Ahad' && normalizePretesDay('sabtu') === 'Sabtu' && normalizePretesDay('sunday') === 'Ahad', 'Impor CBT mengenali Sabtu/Ahad/Minggu');

// 5) Integrasi halaman (markup/kode)
$src = static fn(string $f) => file_get_contents($root . '/' . $f);
$dosen = $src('admin/pretes-dosen.php');
$t(substr_count($dosen, 'pretesSaveWeekendSlots(') === 2 && str_contains($dosen, "renderPengujiSelect('sabtu'") && str_contains($dosen, "renderPengujiSelect('ahad'") && str_contains($dosen, "window.syncWeekendDays = function"), 'Dosen Penguji: isian Sabtu/Ahad + simpan + tampil hanya saat Khusus RPL');
$t(str_contains($dosen, "data-weekend-for=\"create\"") && str_contains($dosen, "data-weekend-for=\"edit\"") && str_contains($dosen, "data-penguji-ahad="), 'Dosen Penguji: form tambah & modal edit + data edit');
$daftar = $src('pages/pretes-daftar.php');
$t(str_contains($daftar, '$validDays    = pretesPeriodDays($active_pretes ?: null);') && str_contains($daftar, '$days = pretesPeriodDays($active_pretes ?: null);'), 'Pendaftaran: pilihan & validasi hari mengikuti periode');
$t(str_contains($src('admin/pretes-peserta.php'), 'pretesDaysForRegistration($pdo, $regId)') && str_contains($src('admin/pretes-jadwal.php'), '$days = pretesPeriodDays($active_pretes);'), 'Peserta & generate jadwal mendukung Sabtu/Ahad');
$t(str_contains($src('admin/pretes-pengaturan-tutor.php'), '$days = pretesPeriodDays($currentMaster ?: null);') && str_contains($src('admin/pretes-jadwal-cetak.php'), '$days = pretesPeriodDays($currentMaster ?: null);'), 'Pengaturan tutor & cetak jadwal mendukung Sabtu/Ahad');
$t(str_contains($src('admin/pretes-absensi-export.php'), 'pretesAllDays(), true)') && str_contains($src('admin/pretes-peserta-export.php'), 'pretesDisplayDays('), 'Ekspor absensi & peserta mendukung Sabtu/Ahad');

if ($fail) { echo "\n$fail test gagal\n"; exit(1); }
echo "\nSemua test Sabtu & Ahad periode RPL lulus.\n";
