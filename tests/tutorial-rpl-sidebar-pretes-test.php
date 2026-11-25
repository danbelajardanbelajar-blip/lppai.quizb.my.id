<?php
/** Render sidebar asli dengan helper RPL asli; aturan pretes/reguler diisolasi sebagai fixture. */
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
require_once __DIR__ . '/../includes/tutorial-rpl-registration.php';
define('BASE_URL', '');
define('APP_NAME', 'LPPAI Corner');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensureTutorialRplTables($pdo);
ensureTutorialRplRegistrationTable($pdo);
$pdo->exec("INSERT INTO tutorial_rpl_classes(id,nama_kelompok,is_active) VALUES(1,'Aktif',1),(2,'Arsip',0);
    INSERT INTO tutorial_rpl_members(class_id,user_id,status_lulus) VALUES
    (1,11,'proses'),(1,12,'tidak_lulus'),(2,13,'lulus'),(2,14,'proses');");
$pdo->exec("INSERT INTO tutorial_rpl_registrations(period_id,user_id,registered_by,channel,hari_pilihan,registered_at,evidence_json) VALUES(1,10,10,'mandiri','Senin',CURRENT_TIMESTAMP,'{}')");
$dir = sys_get_temp_dir() . '/rpl-sidebar-' . bin2hex(random_bytes(6));
mkdir($dir . '/includes', 0777, true);
mkdir($dir . '/assets/js', 0777, true);
copy(__DIR__ . '/../includes/sidebar.php', $dir . '/includes/sidebar.php');
file_put_contents($dir . '/assets/js/sidebar-search.js', '');
file_put_contents($dir . '/includes/tutorial-rpl-helper.php', '<?php require_once ' . var_export(realpath(__DIR__ . '/../includes/tutorial-rpl-helper.php'), true) . ';');
file_put_contents($dir . '/includes/pretes-helper.php', <<<'PHP'
<?php
function studentHasPretesRegistration($db,$user){return $GLOBALS['facts']['pretes'];}
function shouldShowDaftarPretesToStudent($db,$user){return true;}
function isMahasiswaPretesAktif($db,$user){return $GLOBALS['facts']['pretes'];}
function isUserLulusPretes($db,$id){return false;}
PHP
);
file_put_contents($dir . '/includes/tutorial-membership.php', <<<'PHP'
<?php
function studentHasTutorialRegistration($db,$user){return $GLOBALS['facts']['regular'];}
function studentHasActiveTutorialRegistration($db,$user){return $GLOBALS['facts']['regular'];}
PHP
);
function getDBConnection(){return $GLOBALS['pdo'];}
function sidebarCheck(bool $condition,string $message):void {
    if (!$condition) throw new RuntimeException($message);
}
$render = static function(int $id,string $program,bool $pretes=true,bool $regular=false)use($dir):string {
    $currentUser=['id'=>$id,'role'=>'mahasiswa','jenis_program'=>$program];
    $GLOBALS['facts']=['pretes'=>$pretes,'regular'=>$regular];
    $_SERVER['PHP_SELF']='/tutorial-rpl.php';
    ob_start();include $dir . '/includes/sidebar.php';return ob_get_clean();
};
try {
    $before=$pdo->query('SELECT * FROM tutorial_rpl_members ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ([10=>'daftar menunggu kelas',11=>'proses',12=>'tidak lulus',13=>'lulus di kelas arsip',14=>'riwayat kelas arsip'] as $id=>$state) {
        $html=$render($id,'RPL');
        foreach (['pretes-daftar.php','pretes-peserta.php','pretes-hasil.php','absensi-pretes.php'] as $url) {
            sidebarCheck(!str_contains($html,'href="/'.$url.'"'),'Menu pretes masih tampil untuk '.$state.': '.$url);
        }
        sidebarCheck(str_contains($html,'href="/tutorial-rpl.php"'),'Info & Kelas RPL hilang: '.$state);
        sidebarCheck(!str_contains($html,'href="/tutorial-rpl-daftar.php"'),'Daftar ulang masih tampil: '.$state);
        sidebarCheck(!str_contains($html,'href="/tutorial-info.php"'),'Tutorial reguler bocor ke RPL');
    }
    $html=$render(99,' rpl ');
    sidebarCheck(str_contains($html,'href="/pretes-peserta.php"'),'RPL belum tutorial kehilangan pretes');
    sidebarCheck(str_contains($html,'href="/tutorial-rpl-daftar.php"'),'RPL belum terdaftar kehilangan pendaftaran tutorial');
    $html=$render(99,'RPL',false);
    sidebarCheck(!str_contains($html,'href="/pretes-peserta.php"'),'Tanpa riwayat pretes mendapat menu pretes');
    $html=$render(11,'Reguler');
    sidebarCheck(str_contains($html,'href="/pretes-peserta.php"'),'Aturan RPL memengaruhi mahasiswa Reguler');
    $html=$render(99,'Reguler',true,true);
    sidebarCheck(!str_contains($html,'href="/pretes-peserta.php"') && str_contains($html,'href="/tutorial-info.php"'),'Aturan tutorial aktif Reguler berubah');
    sidebarCheck($pdo->query('SELECT * FROM tutorial_rpl_members ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'Render mengubah keanggotaan/nilai');
    $pdo->exec('DELETE FROM tutorial_rpl_registrations WHERE user_id=10');
    sidebarCheck(str_contains($render(10,'RPL'),'href="/pretes-peserta.php"'),'Status menu tidak mengikuti perubahan data terbaru');
    echo "PASS: sidebar RPL terdaftar/proses/tidak lulus/lulus/arsip, belum tutorial, isolasi akun/program, absensi, dan regresi Reguler.\n";
} finally {
    foreach (glob($dir . '/includes/*') as $file) unlink($file);
    unlink($dir . '/assets/js/sidebar-search.js');
    rmdir($dir . '/assets/js');rmdir($dir . '/assets');rmdir($dir . '/includes');rmdir($dir);
}
