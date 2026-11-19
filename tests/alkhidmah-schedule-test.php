<?php
/**
 * Test: Generate Jadwal Majlis Dzikir Al Khidmah (includes/alkhidmah-schedule.php).
 * - Tanggal bawaan = Jumat pertama Desember, Januari, Februari.
 * - Peserta = peserta tutorial aktif (aturan sama dengan studentHasActiveTutorialRegistration).
 * - Kuota bawaan = ceil(total/3); setiap mahasiswa tepat sekali; teman se-prodi dikelompokkan.
 * - Mode tambah: peserta baru masuk sesuai sisa kuota tanpa mengubah jadwal lama.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$fail = 0;
$t = function (bool $ok, string $msg) use (&$fail): void { echo ($ok ? ' [PASS] ' : '❌ [FAIL] ') . $msg . "\n"; if (!$ok) $fail++; };

require_once $root . '/includes/alkhidmah-schedule.php';

// 1) Tanggal
$t(alkhidmahFirstFriday(2026, 12) === '2026-12-04' && alkhidmahFirstFriday(2027, 1) === '2027-01-01' && alkhidmahFirstFriday(2027, 2) === '2027-02-05', 'Jumat pertama Des 2026 / Jan 2027 / Feb 2027');
$t(alkhidmahDefaultDates(new DateTimeImmutable('2026-11-19')) === ['2026-12-04', '2027-01-01', '2027-02-05'], 'Tanggal bawaan (November) = Des, Jan, Feb berikutnya');
$t(alkhidmahDefaultDates(new DateTimeImmutable('2027-01-10')) === ['2026-12-04', '2027-01-01', '2027-02-05'], 'Tanggal bawaan (Januari) = siklus yang sedang berjalan');
$t(alkhidmahFormatDate('2026-12-04') === 'Jumat, 4 Desember 2026', 'Format tanggal Indonesia');
$threw = false; try { alkhidmahValidateDates(['2026-12-04', '2026-12-04', '2027-02-05']); } catch (InvalidArgumentException $e) { $threw = true; }
$t($threw, 'Tanggal kembar ditolak');
$threw = false; try { alkhidmahValidateDates(['2026-12-04', '2027-02-30', '2027-02-05']); } catch (InvalidArgumentException $e) { $threw = true; }
$t($threw, 'Tanggal tidak valid ditolak');

// 2) Perencana: kuota, sekali, se-prodi
$mk = function (string $prodi, int $n, int $start) { $o = []; for ($i = 0; $i < $n; $i++) $o[] = ['id' => $start + $i, 'nama_lengkap' => "$prodi $i", 'nim' => (string)($start + $i), 'program_studi' => $prodi]; return $o; };
$people = array_merge($mk('PAI', 14, 1), $mk('Hukum', 9, 100), $mk('Teknik', 7, 200), $mk('Ekonomi', 6, 300), $mk('', 2, 400)); // 38
$sessions = ['a' => ['remaining' => 13, 'prodi' => []], 'b' => ['remaining' => 13, 'prodi' => []], 'c' => ['remaining' => 13, 'prodi' => []]];
$plan = alkhidmahPlan($people, $sessions);
$ids = [];
foreach ($plan['assign'] as $k => $ms) foreach ($ms as $m) $ids[] = $m['id'];
$t(count($ids) === 38 && count(array_unique($ids)) === 38 && $plan['unassigned'] === [], 'Semua 38 dijadwalkan tepat sekali');
$t(max(array_map('count', $plan['assign'])) <= 13, 'Tidak ada sesi melebihi kuota 13');
$split = [];
foreach ($plan['assign'] as $k => $ms) foreach ($ms as $m) $split[$m['_prodi']][$k] = true;
$whole = array_filter($split, static fn($s) => count($s) === 1);
$t(count($split['Hukum']) === 1 && count($split['Teknik']) === 1 && count($split['Ekonomi']) === 1, 'Prodi yang muat dalam satu sesi tidak dipecah');
$t(count($split['PAI']) === 2, 'Prodi lebih besar dari kuota (PAI 14 > 13) dipecah ke 2 sesi saja');
$t(isset($split['(Prodi belum diisi)']), 'Mahasiswa tanpa prodi tetap dijadwalkan');
$big = [];
foreach (['a', 'b', 'c'] as $k) $big[$k] = ['remaining' => 100, 'prodi' => [], 'soft' => 13];
$planBig = alkhidmahPlan($people, $big);
$t(max(array_map('count', $planBig['assign'])) <= 13 && min(array_map('count', $planBig['assign'])) >= 12, 'Kuota dinaikkan (100) tetap rata ~total/3 per sesi');
$small = alkhidmahPlan($mk('PAI', 10, 1), ['a' => ['remaining' => 3, 'prodi' => []], 'b' => ['remaining' => 3, 'prodi' => []], 'c' => ['remaining' => 3, 'prodi' => []]]);
$t(count($small['unassigned']) === 1 && array_sum(array_map('count', $small['assign'])) === 9, 'Kuota kurang → sisa dilaporkan sebagai belum terjadwal');

// 3) Peserta tutorial aktif + generate ke DB
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, nama_lengkap TEXT, nim TEXT, program_studi TEXT, jenis_program TEXT, role TEXT)");
$pdo->exec("CREATE TABLE master_gelombang (id INTEGER PRIMARY KEY, tahun_ajaran TEXT, semester TEXT, gelombang TEXT, status TEXT, created_at TEXT)");
$pdo->exec("INSERT INTO master_gelombang VALUES (1,'2026/2027','Ganjil','gel1','aktif','2026-09-01 00:00:00')");
$pdo->exec("CREATE TABLE tutorial_classes (id INTEGER PRIMARY KEY, gelombang TEXT, semester TEXT)");
$pdo->exec("INSERT INTO tutorial_classes VALUES (1,'gel1','2026-2027-Ganjil')");
$pdo->exec("CREATE TABLE tutorial_registrations (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, tutorial_class_id INT, gelombang TEXT, tahun_ajaran TEXT, tipe_nilai TEXT, status TEXT, created_at TEXT)");
$prodis = ['PAI' => 10, 'Hukum' => 6, 'Teknik' => 5];
$uid = 1;
foreach ($prodis as $pr => $n) for ($i = 0; $i < $n; $i++, $uid++) {
    $pdo->prepare("INSERT INTO users VALUES (?,?,?,?, 'Reguler','mahasiswa')")->execute([$uid, "$pr Mhs $i", "26$uid", $pr]);
    $pdo->prepare("INSERT INTO tutorial_registrations (user_id,tutorial_class_id,gelombang,tahun_ajaran,status,created_at) VALUES (?,1,'gel1','2026/2027','aktif','2026-09-10')")->execute([$uid]);
}
// Bukan peserta aktif: mengundurkan diri, periode lama, dosen
$pdo->exec("INSERT INTO users VALUES (90,'Mundur','2690','PAI','Reguler','mahasiswa'),(91,'Lama','2691','PAI','Reguler','mahasiswa'),(92,'Dosen','','PAI','Reguler','dosen')");
$pdo->exec("INSERT INTO tutorial_registrations (user_id,tutorial_class_id,gelombang,tahun_ajaran,status,created_at) VALUES (90,1,'gel1','2026/2027','mengundurkan_diri','2026-09-10'),(91,NULL,'gel1','2025/2026','aktif','2025-09-10'),(92,1,'gel1','2026/2027','aktif','2026-09-10')");
$active = tutorialActiveParticipants($pdo);
$activeIds = array_column($active, 'id');
$t(count($active) === 21 && !in_array(90, $activeIds, true) && !in_array(91, $activeIds, true) && !in_array(92, $activeIds, true), 'Peserta aktif: 21 (tanpa yang mundur, periode lama, dosen)');
$parity = true;
foreach ([1, 90, 91] as $chk) {
    $u = $pdo->query("SELECT * FROM users WHERE id = $chk")->fetch();
    if (studentHasActiveTutorialRegistration($pdo, $u) !== in_array($chk, $activeIds, true)) $parity = false;
}
$t($parity, 'Daftar massal konsisten dengan studentHasActiveTutorialRegistration');

$res = alkhidmahGenerate($pdo, 'rebuild', ['2027-02-05', '2026-12-04', '2027-01-01'], 0, "Ba'da Isya");
$t($res['quota'] === 7 && $res['scheduled'] === 21 && $res['unassigned'] === [], 'Rebuild: kuota bawaan ceil(21/3)=7, 21 terjadwal');
$t(array_column($res['sessions'], 'tanggal') === ['2026-12-04', '2027-01-01', '2027-02-05'] && $res['sessions'][0]['waktu'] === "Ba'da Isya" && $res['sessions'][0]['lokasi'] === ALKHIDMAH_LOKASI, 'Sesi urut tanggal, waktu & lokasi tersimpan');
$t((int)$pdo->query('SELECT COUNT(DISTINCT user_id) FROM alkhidmah_jadwal_peserta')->fetchColumn() === 21, 'Setiap mahasiswa satu jadwal (UNIQUE user_id)');
$t(max(array_column($res['sessions'], 'jumlah')) <= 7, 'Tidak ada sesi melebihi kuota');
$s1 = alkhidmahScheduleForUser($pdo, 1);
$t($s1 !== null && in_array($s1['tanggal'], ['2026-12-04', '2027-01-01', '2027-02-05'], true), 'Jadwal per mahasiswa terbaca');
$t(alkhidmahScheduleForUser($pdo, 90) === null, 'Non-peserta tidak punya jadwal');

// Mode tambah: peserta baru & kuota dinaikkan; jadwal lama tetap
$before = $pdo->query('SELECT user_id, sesi_id FROM alkhidmah_jadwal_peserta ORDER BY user_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$pdo->exec("INSERT INTO users VALUES (50,'Baru Hukum','2650','Hukum','Reguler','mahasiswa'),(51,'Baru Hukum 2','2651','Hukum','Reguler','mahasiswa')");
$pdo->exec("INSERT INTO tutorial_registrations (user_id,tutorial_class_id,gelombang,tahun_ajaran,status,created_at) VALUES (50,1,'gel1','2026/2027','aktif','2026-10-01'),(51,1,'gel1','2026/2027','aktif','2026-10-01')");
$full = alkhidmahGenerate($pdo, 'add', [], 0);
$t($full['scheduled'] === 0 && count($full['unassigned']) === 2, 'Tambah tanpa sisa kuota → peserta baru dilaporkan belum terjadwal');
$add = alkhidmahGenerate($pdo, 'add', [], 9);
$after = $pdo->query('SELECT user_id, sesi_id FROM alkhidmah_jadwal_peserta ORDER BY user_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$t($add['scheduled'] === 2 && $add['quota'] === 9 && array_intersect_key($after, $before) == $before, 'Tambah (kuota 9): 2 baru terjadwal, jadwal lama tidak berubah');
$hukumSesi = $pdo->query("SELECT DISTINCT sesi_id FROM alkhidmah_jadwal_peserta WHERE program_studi = 'Hukum'")->fetchAll(PDO::FETCH_COLUMN);
$t(in_array($after[50], $hukumSesi, true) && $after[50] === $after[51], 'Peserta baru masuk ke sesi yang sudah berisi teman se-prodi');
$threw = false; try { alkhidmahGenerate(new PDO('sqlite::memory:'), 'rebuild', ['2026-12-04', '2027-01-01', '2027-02-05'], 0, '', []); } catch (InvalidArgumentException $e) { $threw = true; }
$t($threw, 'Tanpa peserta aktif → ditolak dengan pesan');

// 4) Integrasi halaman
$gen = file_get_contents($root . '/admin/tutorial-pengaturan-generate.php');
$t(str_contains($gen, "\$action === 'generate_alkhidmah'") && str_contains($gen, 'id="alkhidmah-jadwal"') && substr_count($gen, 'name="alkhidmah_tanggal_') === 1 && str_contains($gen, 'name="alkhidmah_kuota"'), 'Halaman generate memuat menu & handler Majlis Al Khidmah');
$dash = file_get_contents($root . '/pages/dashboard.php');
$t(str_contains($dash, 'alkhidmahScheduleForUser($pdo, (int)$user[\'id\'])') && str_contains($dash, 'id="dashboard-alkhidmah-jadwal"'), 'Dashboard menampilkan jadwal Majlis mahasiswa');

if ($fail) { echo "\n$fail test gagal\n"; exit(1); }
echo "\nSemua test jadwal Majlis Al Khidmah lulus.\n";
