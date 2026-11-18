<?php
/**
 * Test: 15 bagian data tambahan Profil Pengguna (includes/admin-profile-extra.php + view).
 * Fixture SQLite lengkap; memastikan password tes TIDAK pernah tampil, dan tabel yang tidak ada tidak membuat halaman gagal.
 */
declare(strict_types=1);
if (!defined('BASE_URL')) define('BASE_URL', 'https://lppai.quizb.my.id');
$root = dirname(__DIR__);
$fail = 0;
$t = function (bool $ok, string $msg) use (&$fail): void { echo ($ok ? ' [PASS] ' : '❌ [FAIL] ') . $msg . "\n"; if (!$ok) $fail++; };

require_once $root . '/includes/admin-profile-extra.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
pretesComponentsPdo($pdo);
$tz = new DateTimeZone('Asia/Jakarta');
$now = (new DateTimeImmutable('2026-11-18 10:00:00', $tz))->getTimestamp();
$SECRET = 'RahasiaTes#9931';

$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, nim TEXT, nama_lengkap TEXT, role TEXT, jenis_program TEXT)");
$pdo->exec("INSERT INTO users VALUES (5,'2026005','2026005','Mahasiswa Uji','mahasiswa','RPL'), (6,'dosenx','','Dosen Uji','dosen','Reguler')");
$pdo->exec("CREATE TABLE pretes_registrations (id INTEGER PRIMARY KEY, user_id INT, periode TEXT, hari_pilihan TEXT, jam TEXT, ruangan TEXT, dosen_penguji TEXT, status TEXT, username_tes TEXT, password_tes TEXT)");
$pdo->prepare("INSERT INTO pretes_registrations VALUES (50,5,'Pretes RPL 2026/2027','Senin','13.00','Lab 1','Ust. A','hadir','tes2026005',?)")->execute([$SECRET]);
$pdo->exec("INSERT INTO pretes_registrations VALUES (40,5,'Pretes 2025/2026','Rabu','13.00','Lab 2','Ust. B','hadir','tes-lama','')");
$pdo->exec("CREATE TABLE pretes_cbt_attempts (id INTEGER PRIMARY KEY, user_id INT, registration_id INT, session_id INT, started_at INT, deadline_at INT, submitted_at INT NULL, total_questions INT, correct_count INT NULL, score REAL NULL)");
$ins = $pdo->prepare('INSERT INTO pretes_cbt_attempts VALUES (?,?,?,?,?,?,?,?,?,?)');
$ins->execute([1, 5, 50, 1, $now - 3600, $now - 1800, $now - 2400, 25, 22, 88.0]);     // resmi 88, durasi 20m
$ins->execute([2, 5, -1, 1, $now - 86400, $now - 84600, $now - 85000, 25, 15, 60.0]);  // tryout 60
$ins->execute([3, 5, -2, 1, $now - 7200, $now - 5400, $now - 6000, 25, 20, 80.0]);     // tryout 80
$ins->execute([4, 5, -3, 1, $now - 60, $now + 1700, null, 25, null, null]);            // tryout berlangsung
// Skema produksi: jumlah soal dari pretes_cbt_items (attempt 1 punya 24 item → menimpa total_questions 25)
$pdo->exec("CREATE TABLE pretes_cbt_items (id INTEGER PRIMARY KEY AUTOINCREMENT, attempt_id INT)");
for ($i = 0; $i < 24; $i++) $pdo->exec("INSERT INTO pretes_cbt_items (attempt_id) VALUES (1)");
$pdo->exec("CREATE TABLE pretes_cbt_interruption_events (attempt_id INT, event_id TEXT, reason TEXT, interrupted_position INT, paused_at INT, resumed_at INT NULL)");
$pdo->exec("INSERT INTO pretes_cbt_interruption_events VALUES (1,'e1','network',7,$now - 3000,$now - 2950)");
$pdo->exec("CREATE TABLE pretes_proctoring_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, registration_id INT, user_id INT, event_type TEXT, similarity_score REAL, status TEXT, snapshot_path TEXT, notes TEXT, created_at TEXT)");
$pdo->exec("INSERT INTO pretes_proctoring_logs (registration_id,user_id,event_type,similarity_score,status,notes,created_at) VALUES (50,5,'checkin',0.93,'verified','ok','2026-11-18 09:00:00'),(50,5,'periodic',0.41,'mismatch','wajah berbeda','2026-11-18 09:05:00')");
$pdo->exec("CREATE TABLE pretes_face_challenges (id TEXT PRIMARY KEY, registration_id INT, user_id INT, direction TEXT, issued_at INT, expires_at INT, consumed_at INT NULL)");
$pdo->exec("INSERT INTO pretes_face_challenges VALUES ('a',50,5,'left',1,2,1),('b',50,5,'right',1,2,NULL)");
$pdo->exec("CREATE TABLE pretes_attendance_sessions (id INTEGER PRIMARY KEY, periode TEXT, hari TEXT, penguji TEXT, ruangan TEXT)");
$pdo->exec("INSERT INTO pretes_attendance_sessions VALUES (9,'Pretes RPL 2026/2027','Senin','Ust. A','Lab 1')");
$pdo->exec("CREATE TABLE pretes_attendance_records (registration_id INT PRIMARY KEY, session_id INT, attended_at TEXT)");
$pdo->exec("INSERT INTO pretes_attendance_records VALUES (50,9,'2026-11-17 12:58:00')");
$pdo->exec("CREATE TABLE pretes_assessments (registration_id INT PRIMARY KEY, scores_json TEXT, assessed_by INT)");
$pdo->exec("INSERT INTO pretes_assessments VALUES (50,'{\"ujian_tulis\":88}',0)");
$pdo->exec("CREATE TABLE tutorial_classes (id INTEGER PRIMARY KEY, nama_kelas TEXT)");
$pdo->exec("INSERT INTO tutorial_classes VALUES (3,'Kelas A')");
$pdo->exec("CREATE TABLE tutorial_attendance (tutorial_class_id INT, user_id INT, pertemuan_ke INT, tanggal TEXT, status_hadir TEXT)");
$pdo->exec("INSERT INTO tutorial_attendance VALUES (3,5,1,'2026-10-01','hadir'),(3,5,2,'2026-10-08','izin'),(3,5,3,'2026-10-15','hadir')");
$pdo->exec("CREATE TABLE tutorial_rpl_classes (id INTEGER PRIMARY KEY, nama_kelompok TEXT, periode TEXT, gelombang TEXT, dosen_nama TEXT)");
$pdo->exec("INSERT INTO tutorial_rpl_classes VALUES (7,'Kelompok RPL 1','Tutorial RPL 2026/2027','Gelombang 1','Ust. C')");
$pdo->exec("CREATE TABLE tutorial_rpl_members (class_id INT, user_id INT, drive_submitted INT, drive_verified INT, exam_submitted INT, nilai_ujian_tulis REAL, nilai_praktik REAL, nilai_akhir REAL, status_lulus TEXT, catatan TEXT, created_at TEXT)");
$pdo->exec("INSERT INTO tutorial_rpl_members VALUES (7,5,1,0,1,75,85,80,'lulus',NULL,'2026-11-01')");
$pdo->exec("CREATE TABLE tutorial_rpl_cbt_attempts (user_id INT, class_id INT, started_at INT, submitted_at INT, correct_count INT, total_questions INT, score REAL, submitted INT)");
$pdo->exec("INSERT INTO tutorial_rpl_cbt_attempts VALUES (5,7,$now - 500,$now - 100,19,25,76,1)");
$pdo->exec("CREATE TABLE absensi_alkhidmah (id INTEGER PRIMARY KEY, nim TEXT, tanggal TEXT, waktu_hadir TEXT, foto_hadir TEXT, waktu_pulang TEXT, foto_pulang TEXT)");
$pdo->exec("INSERT INTO absensi_alkhidmah (nim,tanggal,waktu_hadir,foto_hadir,waktu_pulang,foto_pulang) VALUES ('2026005','2026-11-06','19:30:00','uploads/alk/h.jpg','21:10:00',NULL)");
$pdo->exec("CREATE TABLE pengambilan_sertifikat (id INTEGER PRIMARY KEY, nim TEXT, nomor_sertifikat TEXT, tahun_ajaran TEXT, status_pengambilan TEXT, tanggal_pengambilan TEXT, metode_pengambilan TEXT, nama_penerima TEXT, hubungan_penerima TEXT, catatan TEXT)");
$pdo->exec("INSERT INTO pengambilan_sertifikat (nim,nomor_sertifikat,tahun_ajaran,status_pengambilan,tanggal_pengambilan,metode_pengambilan,nama_penerima,hubungan_penerima) VALUES ('2026005','LPPAI/2026/001','2026/2027','Sudah Diambil','2026-12-01 09:00:00','Scan QR','Mahasiswa Uji','Sendiri')");
$pdo->exec("CREATE TABLE jaminan_mahasiswa (id INTEGER PRIMARY KEY, nim TEXT, jaminan_atas TEXT, jenis_jaminan TEXT, status_pengambilan INT, created_at TEXT, updated_at TEXT)");
$pdo->exec("INSERT INTO jaminan_mahasiswa (nim,jaminan_atas,jenis_jaminan,status_pengambilan,created_at) VALUES ('2026005','Sertifikat','KTP',0,'2026-11-10 08:00:00')");
$pdo->exec("CREATE TABLE user_login_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, status TEXT, ip_address TEXT, device TEXT, browser TEXT, keterangan TEXT, created_at TEXT)");
$pdo->exec("INSERT INTO user_login_logs (user_id,status,ip_address,device,browser,created_at) VALUES (5,'failed','10.0.0.1','Mobile','Chrome','2026-11-17 07:00:00'),(5,'success','10.0.0.1','Mobile','Chrome','2026-11-17 07:01:00')");
$pdo->exec("CREATE TABLE user_click_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, page_title TEXT, page_url TEXT, element_text TEXT, target_url TEXT, created_at TEXT)");
$pdo->exec("INSERT INTO user_click_logs (user_id,page_title,page_url,element_text,created_at) VALUES (5,'Hasil Pretes','/pretes-hasil.php','Ambil Sertifikat','2026-11-17 07:05:00')");
$pdo->exec("CREATE TABLE message_threads (id INTEGER PRIMARY KEY, user_id INT, last_message_at INT, last_message_preview TEXT, last_sender_role TEXT, admin_unread INT, user_unread INT)");
$pdo->exec("INSERT INTO message_threads VALUES (11,5,$now - 50,'Terima kasih','user',1,0)");
$pdo->exec("CREATE TABLE messages (id INTEGER PRIMARY KEY, thread_id INT, sender_id INT, sender_role TEXT, body TEXT, created_at INT)");
$pdo->exec("INSERT INTO messages VALUES (1,11,5,'user','tanya',1),(2,11,1,'admin','jawab',2),(3,11,5,'user','Terima kasih',3)");

$user = $pdo->query('SELECT * FROM users WHERE id = 5')->fetch();
$regs = $pdo->query('SELECT * FROM pretes_registrations WHERE user_id = 5 ORDER BY id DESC')->fetchAll();
$d = adminProfileExtraData($pdo, $user, $regs, $now);

$t(count($d['cbt_resmi']) === 1 && $d['cbt_resmi'][0]['score'] === 88.0 && $d['cbt_resmi'][0]['correct'] === 22 && $d['cbt_resmi'][0]['wrong'] === 2 && $d['cbt_resmi'][0]['total'] === 24 && $d['cbt_resmi'][0]['duration'] === 1200 && $d['cbt_resmi'][0]['periode'] === 'Pretes RPL 2026/2027', '1) CBT resmi: nilai, benar/salah, durasi, periode');
$t(count($d['cbt_tryout']) === 3 && $d['cbt_tryout'][0]['status'] === 'Berlangsung' && $d['cbt_tryout'][0]['score'] === null, '2) Tryout: 3 sesi, yang berlangsung tanpa nilai');
$t(count($d['interruptions']) === 1 && $d['interruptions'][0]['reason'] === 'network', '3) Gangguan CBT');
$t($d['proctoring']['total'] === 2 && $d['proctoring']['counts']['mismatch'] === 1 && $d['proctoring']['challenges_issued'] === 2 && $d['proctoring']['challenges_passed'] === 1, '4) Pengawas AI: hitungan status & tantangan wajah');
$t(count($d['pretes_presence']) === 2 && $d['pretes_presence'][0]['sumber'] === 'Scan QR presensi' && $d['pretes_presence'][1]['sumber'] === 'Ditandai hadir oleh admin/penguji', '5) Presensi pretes: scan QR + status hadir manual');
$t(count($d['credentials']) === 2 && $d['credentials'][0]['username_tes'] === 'tes2026005' && $d['credentials'][0]['password_set'] === true && $d['credentials'][1]['password_set'] === false, '6) Kredensial: username + status password saja');
$t(!str_contains(json_encode($d), $SECRET), '6) Nilai password tidak ada di data yang dikembalikan');
$st = array_column($d['pretes_status'], 'status', 'registration_id');
$t(($st[50] ?? null) === 'lulus' && ($st[40] ?? null) === 'menunggu', '7) Status kelulusan per periode (RPL cukup CBT: 88 → lulus; tanpa nilai → menunggu)');
$t(count($d['tutorial_presence']['rows']) === 3 && $d['tutorial_presence']['counts']['hadir'] === 2 && $d['tutorial_presence']['counts']['izin'] === 1, '8) Presensi tutorial + rekap');
$t(count($d['rpl']['members']) === 1 && $d['rpl']['members'][0]['nama_kelompok'] === 'Kelompok RPL 1' && count($d['rpl']['cbt']) === 1, '9) Tutorial RPL: kelas & CBT');
$t(count($d['alkhidmah']) === 1 && $d['alkhidmah'][0]['waktu_hadir'] === '19:30:00', '10) Al Khidmah per NIM');
$t(count($d['sertifikat']) === 1 && $d['sertifikat'][0]['nomor_sertifikat'] === 'LPPAI/2026/001', '11) Pengambilan sertifikat');
$t(count($d['jaminan']) === 1 && (int)$d['jaminan'][0]['status_pengambilan'] === 0, '12) Jaminan');
$t($d['logins']['total'] === 2 && $d['logins']['failed'] === 1 && $d['logins']['last_success'] === '2026-11-17 07:01:00', '13) Riwayat login');
$t($d['clicks']['total'] === 1 && $d['clicks']['rows'][0]['element_text'] === 'Ambil Sertifikat', '14) Aktivitas');
$t($d['messages']['total'] === 3 && $d['messages']['from_admin'] === 1 && $d['messages']['from_user'] === 2, '15) Pesan');

// Tabel tidak ada → bagian kosong, tanpa exception
$pdo2 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$d2 = adminProfileExtraData($pdo2, ['id' => 9, 'nim' => '123', 'role' => 'mahasiswa', 'jenis_program' => 'Reguler'], [], $now);
$t($d2['cbt_resmi'] === [] && $d2['logins']['total'] === 0 && $d2['messages']['thread'] === null && $d2['alkhidmah'] === [], 'Tabel belum ada → semua bagian kosong tanpa error');

// Render view
function sanitize($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$render = function (array $profileUser, array $regs) use ($pdo, $root): string {
    $profileHistory = ['pretes' => $regs];
    ob_start();
    include $root . '/includes/admin-profile-extra-view.php';
    return ob_get_clean();
};
$html = $render($user, $regs);
foreach (range(1, 15) as $n) $t(str_contains($html, '<span class="ape-no">' . $n . '</span>'), "View mahasiswa memuat bagian $n");
$t(!str_contains($html, $SECRET) && str_contains($html, 'Tersimpan (disembunyikan)') && str_contains($html, 'tes2026005'), 'View: password tidak tampil, hanya status tersimpan');
$t(str_contains($html, '88') && str_contains($html, 'Kelompok RPL 1') && str_contains($html, 'LPPAI/2026/001') && str_contains($html, 'https://lppai.quizb.my.id/uploads/alk/h.jpg'), 'View memuat nilai CBT, kelas RPL, sertifikat, swafoto');
$t(str_contains($html, 'id="ape-open-chat"') && str_contains($html, 'data-thread-id="11"'), 'View: tombol buka percakapan memakai thread yang ada');
$t(substr_count($html, '<table class="no-datatable">') >= 10, 'Semua tabel tambahan ber-kelas no-datatable');
$dosen = $pdo->query('SELECT * FROM users WHERE id = 6')->fetch();
$hd = $render($dosen, []);
$t(!str_contains($hd, '<span class="ape-no">1</span>') && str_contains($hd, '<span class="ape-no">13</span>') && str_contains($hd, 'data-thread-id="0"'), 'View dosen: hanya bagian 13–15; tombol pesan tanpa thread');

$page = file_get_contents($root . '/admin/profil-pengguna.php');
$t(str_contains($page, "include __DIR__ . '/../includes/admin-profile-extra-view.php'"), 'Halaman profil memasang tampilan data tambahan');

if ($fail) { echo "\n$fail test gagal\n"; exit(1); }
echo "\nSemua test data tambahan profil pengguna lulus.\n";
