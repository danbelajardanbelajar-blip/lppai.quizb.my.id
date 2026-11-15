<?php
/**
 * Test layanan Pesan (includes/messaging.php) dengan SQLite + integrasi widget/halaman.
 */
require_once __DIR__ . '/../includes/messaging.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, nama_lengkap TEXT, nim TEXT, role TEXT, program_studi TEXT)");
$pdo->exec("INSERT INTO users VALUES
    (1, 'admin', 'Admin Satu', NULL, 'admin', NULL),
    (2, 'admin2', 'Admin Dua', NULL, 'admin', NULL),
    (10, '2401', 'Siti Mahasiswa', '2401', 'mahasiswa', 'Hukum'),
    (11, '2402', 'Budi Mahasiswa', '2402', 'mahasiswa', 'Teknik'),
    (20, 'dsn', 'Dr. Dosen', 'D01', 'dosen', NULL)");
msgEnsureTables($pdo);
msgEnsureTables($pdo); // idempoten

$admin = ['id' => 1, 'role' => 'admin', 'nama_lengkap' => 'Admin Satu'];
$admin2 = ['id' => 2, 'role' => 'admin', 'nama_lengkap' => 'Admin Dua'];
$siti = ['id' => 10, 'role' => 'mahasiswa', 'nama_lengkap' => 'Siti Mahasiswa'];
$budi = ['id' => 11, 'role' => 'mahasiswa', 'nama_lengkap' => 'Budi Mahasiswa'];
$dosen = ['id' => 20, 'role' => 'dosen', 'nama_lengkap' => 'Dr. Dosen'];

$failures = [];
$check = function ($cond, $msg) use (&$failures) { if (!$cond) $failures[] = $msg; };
$expect = function (callable $fn, string $class, string $msg) use (&$failures) {
    try { $fn(); $failures[] = $msg . ' (tidak melempar)'; } catch (Throwable $e) { if (!($e instanceof $class)) $failures[] = $msg . ' (' . get_class($e) . ': ' . $e->getMessage() . ')'; }
};
$t = 1000000;

// Belum ada pesan
$check(msgPoll($pdo, $siti)['unread'] === 0 && msgPoll($pdo, $admin)['unread'] === 0, 'Awal: tidak ada pesan belum dibaca');
$check(msgFetchThread($pdo, $siti, null)['thread'] === null, 'Mahasiswa tanpa percakapan → thread null');

// Mahasiswa mengirim → admin unread
$m1 = msgSend($pdo, $siti, null, "  Assalamu'alaikum, kapan jadwal pretes?  ", 'c1', $t);
$check($m1['mine'] && $m1['sender_name'] === 'Anda' && $m1['body'] === "Assalamu'alaikum, kapan jadwal pretes?", 'Pesan terkirim & dirapikan');
$th = msgGetThreadForUser($pdo, 10);
$check($th && (int)$th['admin_unread'] === 1 && (int)$th['user_unread'] === 0, 'Admin unread +1');
$again = msgSend($pdo, $siti, null, "Assalamu'alaikum, kapan jadwal pretes?", 'c1', $t + 1);
$check($again['id'] === $m1['id'] && (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 1, 'client_id idempoten (tidak dobel)');
$p = msgPoll($pdo, $admin, 0);
$check($p['unread'] === 1 && $p['rev'] === $m1['id'] && $p['incoming'] === [], 'Poll admin: unread 1, tanpa incoming saat since 0');

// Daftar admin & ringkasan
$threads = msgAdminThreads($pdo, $admin);
$check(count($threads) === 1 && $threads[0]['title'] === 'Siti Mahasiswa' && $threads[0]['unread'] === 1 && str_contains($threads[0]['subtitle'], '2401'), 'Daftar percakapan admin');
$check(count(msgAdminThreads($pdo, $admin, 'budi')) === 0 && count(msgAdminThreads($pdo, $admin, '2401')) === 1, 'Pencarian percakapan admin');

// Admin membuka & membalas → user unread, admin unread 0
$view = msgFetchThread($pdo, $admin, (int)$th['id']);
$check(count($view['messages']) === 1 && !$view['messages'][0]['mine'] && $view['thread']['unread'] === 0, 'Admin membaca → unread 0');
$r1 = msgSend($pdo, $admin, (int)$th['id'], 'Wa’alaikumsalam. Jadwal sesuai hari pilihan pukul 13.00.', 'a1', $t + 10);
$pu = msgPoll($pdo, $siti, $m1['id']);
$check($pu['unread'] === 1 && count($pu['incoming']) === 1 && $pu['incoming'][0]['sender_name'] === 'Admin LPPAI' && $pu['incoming'][0]['from_admin'], 'Mahasiswa: unread 1 + incoming dari Admin LPPAI');
$check(msgPoll($pdo, $admin, $m1['id'])['incoming'] === [], 'Balasan admin tidak jadi notifikasi bagi admin');
$sv = msgFetchThread($pdo, $siti, null);
$check(count($sv['messages']) === 2 && $sv['thread']['title'] === 'Admin LPPAI' && msgPoll($pdo, $siti)['unread'] === 0, 'Mahasiswa membaca → unread 0');
$check(count(msgFetchThread($pdo, $siti, null, $m1['id'])['messages']) === 1, 'after_id hanya pesan baru');

// Admin lain melihat pesan admin pertama sebagai "Admin · nama"
$v2 = msgFetchThread($pdo, $admin2, (int)$th['id']);
$check($v2['messages'][1]['sender_name'] === 'Admin · Admin Satu' && !$v2['messages'][1]['mine'], 'Admin lain melihat nama admin pengirim');

// Akses & validasi
$expect(fn() => msgFetchThread($pdo, $budi, (int)$th['id']), InvalidArgumentException::class, 'Mahasiswa lain tidak boleh membuka percakapan orang lain');
$expect(fn() => msgSend($pdo, $budi, (int)$th['id'], 'halo'), InvalidArgumentException::class, 'Mahasiswa lain tidak boleh mengirim ke percakapan orang lain');
$expect(fn() => msgSend($pdo, $siti, null, "   \n "), InvalidArgumentException::class, 'Pesan kosong ditolak');
$expect(fn() => msgSend($pdo, $siti, null, str_repeat('a', MSG_MAX_LENGTH + 1)), InvalidArgumentException::class, 'Pesan terlalu panjang ditolak');
$expect(fn() => msgSend($pdo, $admin, null, 'tanpa thread'), InvalidArgumentException::class, 'Admin wajib memilih percakapan');
$clean = msgSend($pdo, $budi, null, "Baris1\x07\n\n\n\n\nBaris2 <script>", 'b1', $t + 20);
$check($clean['body'] === "Baris1\n\n\nBaris2 <script>", 'Karakter kontrol dibuang, teks disimpan apa adanya (dirender aman via textContent)');

// Rate limit
$expect(function () use ($pdo, $dosen, $t) { for ($i = 0; $i < MSG_RATE_LIMIT_PER_MINUTE + 1; $i++) msgSend($pdo, $dosen, null, 'spam ' . $i, null, $t + 30); }, RuntimeException::class, 'Rate limit per menit');
$check((int)$pdo->query('SELECT COUNT(*) FROM messages WHERE sender_id = 20')->fetchColumn() === MSG_RATE_LIMIT_PER_MINUTE, 'Pesan di atas batas tidak tersimpan');

// Admin memulai percakapan & mencari pengguna
$found = msgSearchUsers($pdo, 'mahasiswa');
$check(count($found) === 2 && !in_array('Admin Satu', array_column($found, 'name'), true), 'Cari penerima (tanpa admin)');
$check(msgSearchUsers($pdo, 'a') === [], 'Pencarian minimal 2 huruf');
$st = msgAdminStartThread($pdo, $admin, 11);
$check($st['user_id'] === 11 && $st['id'] === (int)msgGetThreadForUser($pdo, 11)['id'], 'Admin membuka percakapan yang sudah ada');
$expect(fn() => msgAdminStartThread($pdo, $admin, 2), InvalidArgumentException::class, 'Tidak bisa memulai percakapan ke admin');
$expect(fn() => msgAdminStartThread($pdo, $siti, 11), InvalidArgumentException::class, 'Non-admin tidak bisa memulai percakapan ke orang lain');
$check(count(msgAdminThreads($pdo, $admin)) === 3, 'Daftar admin: 3 percakapan (Siti, Budi, Dosen)');

// Integrasi statis
$root = dirname(__DIR__);
$api = file_get_contents("$root/includes/messaging-api.php");
$check(str_contains($api, 'verifyCsrf(') && str_contains($api, "if (!\$isAdmin) msgReply(403") && str_contains($api, 'session_write_close()'), 'API: CSRF, aksi admin dibatasi, sesi dilepas');
// Folder /api/ tidak dapat diakses di produksi (404) → endpoint lewat halaman root /pesan-api.php
$check(str_contains(file_get_contents("$root/pages/pesan-api.php"), "require __DIR__ . '/../includes/messaging-api.php'"), 'Endpoint /pesan-api.php memuat API');
$check(str_contains(file_get_contents("$root/includes/messages-widget.php"), "BASE_URL . '/pesan-api.php'"), 'Widget memanggil /pesan-api.php (bukan /api/)');
$check(str_contains(file_get_contents("$root/includes/footer.php"), "messages-widget.php"), 'Widget dipasang di footer');
$widget = file_get_contents("$root/includes/messages-widget.php");
$check(str_contains($widget, "HIDE_MESSAGE_WIDGET") && str_contains($widget, 'isLoggedIn()'), 'Widget hanya untuk pengguna login & bisa dimatikan per halaman');
$check(str_contains(file_get_contents("$root/pages/pretes-ujian.php"), "define('HIDE_MESSAGE_WIDGET', true)"), 'Ruang ujian CBT tanpa widget');
$check(str_contains(file_get_contents("$root/pages/tryout.php"), "if (\$session) define('HIDE_MESSAGE_WIDGET', true)"), 'Ruang tryout tanpa widget');
$js = file_get_contents("$root/assets/js/messages-widget.js");
foreach (["'exam'", "'camera'", "'upload'", "'fullscreen'", "'modal'", 'BroadcastChannel', 'Notification.permission', 'textContent'] as $needle) {
    $check(str_contains($js, $needle), "Widget JS memuat $needle");
}
$check(!preg_match('/innerHTML\s*+=\s*+(?!ICON_)/', $js), 'Widget JS tidak memasukkan data pengguna lewat innerHTML (hanya ikon SVG konstan)');

if ($failures) { echo "FAIL:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
echo "PASS: layanan Pesan (kirim/balas, unread, poll, idempoten, akses, validasi, rate limit, admin mulai & cari, integrasi widget).\n";
