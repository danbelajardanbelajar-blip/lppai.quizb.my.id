<?php
/** Real SQLite queries and rendered HTML for every active registration period. */
require_once __DIR__ . '/../includes/admin-dashboard-registrations.php';
require_once __DIR__ . '/../includes/admin-user-profile.php';
require_once __DIR__ . '/../includes/user-helper.php';
define('BASE_URL', 'https://example.test');
function isAdmin() { return true; }
function sanitize($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function formatWaDashboard($value) { return ''; }
function requireAdmin() { $GLOBALS['dashboardAdminGuardCalled'] = true; }
function getCurrentUser() { return ['id' => 99, 'nama_lengkap' => 'Admin Penguji']; }
function getDBConnection() { return $GLOBALS['pdo']; }
function checkDashboard($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,nama_lengkap TEXT,nim TEXT,program_studi TEXT,jenis_program TEXT,no_hp TEXT);
    INSERT INTO users VALUES(1,'Mahasiswa <uji>','001','PAI','RPL','');
    ALTER TABLE users ADD COLUMN role TEXT DEFAULT 'mahasiswa';
    CREATE TABLE announcements(id INTEGER PRIMARY KEY,is_active INTEGER);
    CREATE TABLE pretes_results(id INTEGER PRIMARY KEY,status_lulus TEXT);
    CREATE TABLE master_pretes(id INTEGER PRIMARY KEY,periode TEXT,status TEXT,created_at TEXT);
    INSERT INTO master_pretes VALUES(1,'Pretes RPL 2026/2027','aktif','2026-11-01'),
        (2,'Pretes Reguler','aktif','2026-10-01'),(3,'Pretes Kosong','aktif','2026-09-01'),(4,'Pretes Lama','nonaktif','2025-01-01');
    CREATE TABLE master_gelombang(id INTEGER PRIMARY KEY,tahun_ajaran TEXT,semester TEXT,gelombang TEXT,status TEXT,created_at TEXT);
    INSERT INTO master_gelombang VALUES(1,'2026/2027','Ganjil','gel1','aktif','2026-11-01'),
        (2,'2026/2027','Genap','gel2','aktif','2026-11-01'),(3,'2025/2026','Ganjil','gel1','nonaktif','2025-01-01');
    CREATE TABLE pretes_registrations(id INTEGER PRIMARY KEY,user_id INTEGER,periode TEXT,tanggal_daftar TEXT,hari_pilihan TEXT,status TEXT);
    CREATE TABLE tutorial_classes(id INTEGER PRIMARY KEY,nama_kelas TEXT,semester TEXT,gelombang TEXT,hari TEXT,ruangan TEXT,dosen_pengampu TEXT);
    INSERT INTO tutorial_classes VALUES(1,'Kelas Genap','2026/2027-Genap','gel2','Senin','R1','Dosen A');
    INSERT INTO tutorial_classes VALUES(2,'Kelas Semester Lain','2026/2027-Genap','gel1','Senin','R2','Dosen B');
    CREATE TABLE tutorial_registrations(id INTEGER PRIMARY KEY,user_id INTEGER,tutorial_class_id INTEGER,tahun_ajaran TEXT,gelombang TEXT,hari_pilihan TEXT,created_at TEXT);
    INSERT INTO tutorial_registrations VALUES(1,1,NULL,'2026-2027','gel1','Senin','2026-11-12 08:00:00'),
        (2,1,1,'2026/2027','gel2','Senin','2026-11-12 09:00:00'),
        (3,1,NULL,'2025/2026','gel1','Senin','2026-11-12 10:00:00'),
        (4,1,NULL,NULL,'gel1','Senin','2025-11-12 11:00:00'),
        (5,1,NULL,NULL,'gel1','Senin','2026-11-12 12:00:00'),
        (6,1,2,'2026/2027','gel1','Senin','2026-11-12 13:00:00');
");
$stmt=$pdo->prepare('INSERT INTO pretes_registrations VALUES(?,1,?,?,?,?)');
for ($i=1;$i<=12;$i++) $stmt->execute([$i,'Pretes RPL 2026/2027',sprintf('2026-11-12 08:%02d:00',$i),'Senin','terdaftar']);
$stmt->execute([99,'Pretes Reguler','2026-11-12 09:00:00','Selasa','terdaftar']);
$stmt->execute([100,'Pretes Lama','2026-11-13 09:00:00','Rabu','terdaftar']);
// A higher ID with an older timestamp must not displace a newer registration.
$stmt->execute([101,'Pretes RPL 2026/2027','2026-11-01 00:00:00','Kamis','terdaftar']);
$pretes=$pdo->query("SELECT * FROM master_pretes WHERE status='aktif' ORDER BY created_at DESC,id DESC")->fetchAll(PDO::FETCH_ASSOC);
$tutorial=$pdo->query("SELECT * FROM master_gelombang WHERE status='aktif' ORDER BY created_at DESC,id DESC")->fetchAll(PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA query_only=ON');
$registrationGroups=adminDashboardRegistrationGroups($pdo,$tutorial,$pretes);
checkDashboard(count($registrationGroups)===5,'all five active periods, including empty period');
$byLabel=array_column($registrationGroups,null,'label');
$rpl=$byLabel['Pretes RPL 2026/2027'];
checkDashboard(count($rpl['rows'])===5,'five recent registrations per period');
checkDashboard(array_column($rpl['rows'],'id')===range(12,8),'order by registration time then ID');
checkDashboard(count($byLabel['Pretes Reguler']['rows'])===1,'second active pretes is retained');
checkDashboard($byLabel['Pretes Kosong']['rows']===[],'empty active period does not fall back to historical rows');
checkDashboard(array_column($byLabel['2026/2027 / Ganjil / gel1']['rows'],'id')===[5,1],'tutorial year normalization and safe legacy dates');
checkDashboard(array_column($byLabel['2026/2027 / Genap / gel2']['rows'],'id')===[2],'class semester and wave stay scoped');
foreach ($registrationGroups as $group) checkDashboard(!$group['error'],'valid schema loads successfully');
ob_start(); include __DIR__ . '/../includes/admin-dashboard-registrations-view.php'; $html=ob_get_clean();
checkDashboard(substr_count($html,'class="dashboard-registration-period"')===5,'all five groups render simultaneously');
checkDashboard(!str_contains($html,'display: none') && !str_contains($html,'display:none'),'no active period is hidden behind a tab');
checkDashboard(!str_contains($html,'Pretes Lama'),'inactive pretes not rendered');
checkDashboard(str_contains($html,'Belum ada pendaftaran pretes pada periode ini'),'empty active period explained');
checkDashboard(str_contains($html,'Mahasiswa &lt;uji&gt;') && !str_contains($html,'Mahasiswa <uji>'),'student names escaped');
checkDashboard(str_contains($html,'profil-pengguna.php?id=1'),'profile links keep users.id');
checkDashboard(str_contains($html,'pretes-peserta.php?periode=Pretes%20RPL%202026%2F2027'),'pretes full-data link retains period');
checkDashboard(str_contains($html,'tutorial-data-pendaftar.php?gelombang_id=2'),'tutorial full-data link retains period');
$registrationGroups=adminDashboardRegistrationGroups($pdo,[],[]);
ob_start(); include __DIR__ . '/../includes/admin-dashboard-registrations-view.php'; $empty=ob_get_clean();
checkDashboard(str_contains($empty,'Belum ada periode tutorial atau pretes yang aktif.'),'no active periods has accurate empty state');
$code=file_get_contents(__DIR__.'/../admin/dashboard.php');
checkDashboard(str_contains($code,'adminDashboardRegistrationGroups($pdo, $activeTutorialPeriods, $activePretesPeriods)'),'dashboard wired to all periods');
checkDashboard(!str_contains($code,'LIMIT 1'),'active period discovery does not stop at first period');
// Exercise the actual dashboard controller with isolated auth/header/footer dependencies.
$temporary = sys_get_temp_dir() . '/dashboard-active-' . bin2hex(random_bytes(6));
mkdir($temporary . '/admin', 0777, true);
mkdir($temporary . '/includes');
$fixtureFiles = ['admin/dashboard.php', 'includes/admin-dashboard-registrations.php', 'includes/admin-dashboard-registrations-view.php'];
foreach ($fixtureFiles as $file) copy(__DIR__ . '/../' . $file, $temporary . '/' . $file);
file_put_contents($temporary . '/includes/admin-dashboard-registrations.php', '<?php // Helper already loaded by fixture.');
foreach (['auth', 'header', 'footer'] as $file) {
    file_put_contents($temporary . '/includes/' . $file . '.php', '<?php // Isolated test dependency.');
    $fixtureFiles[] = 'includes/' . $file . '.php';
}
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
try {
    ob_start(); include $temporary . '/admin/dashboard.php'; $dashboard = ob_get_clean();
    checkDashboard(!empty($GLOBALS['dashboardAdminGuardCalled']), 'actual dashboard invokes admin guard');
    checkDashboard(substr_count($dashboard, 'class="dashboard-registration-period"') === 5, 'actual controller loads every active period');
    checkDashboard(str_contains($dashboard, 'Pretes RPL 2026/2027') && str_contains($dashboard, 'Kelas Genap'), 'actual dashboard contains pretes RPL and tutorial together');
} finally {
    restore_error_handler();
    foreach ($fixtureFiles as $file) unlink($temporary . '/' . $file);
    rmdir($temporary . '/admin'); rmdir($temporary . '/includes'); rmdir($temporary);
}
$missingTablePdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$registrationGroups=adminDashboardRegistrationGroups($missingTablePdo,[],$pretes);
checkDashboard($registrationGroups[0]['error']===true,'query failure is distinguished from no registrations');
ob_start(); include __DIR__ . '/../includes/admin-dashboard-registrations-view.php'; $error=ob_get_clean();
checkDashboard(str_contains($error,'Pendaftaran periode ini belum dapat dimuat.'),'query failure shown to admin');
echo "PASS: active periods, concurrent tutorial/pretes RPL, isolated years/semesters, legacy records, five latest per period, empty/error states, escaped HTML and scoped profile links.\n";
