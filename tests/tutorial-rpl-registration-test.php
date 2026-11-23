<?php
require_once __DIR__ . '/../includes/tutorial-rpl-registration.php';
function rplCheck($condition, string $message): void { if (!$condition) throw new RuntimeException('FAIL: '.$message); }
function rplReject(callable $fn, string $message): void {
    try { $fn(); } catch (Throwable $e) { return; }
    throw new RuntimeException('FAIL: expected rejection '.$message);
}
$dir = sys_get_temp_dir().'/rpl-registration-'.bin2hex(random_bytes(6)); mkdir($dir);
$dbFile = $dir.'/test.sqlite';
$pdo = new PDO('sqlite:'.$dbFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA busy_timeout=5000');
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,jenis_program TEXT,nama_lengkap TEXT,nim TEXT);
    CREATE TABLE pretes_results(id INTEGER PRIMARY KEY,user_id INT,status_lulus TEXT,nilai REAL);
    CREATE TABLE pretes_registrations(id INTEGER PRIMARY KEY,user_id INT);
    CREATE TABLE pretes_assessments(registration_id INT PRIMARY KEY,scores_json TEXT);
    CREATE TABLE pretes_cbt_attempts(id INTEGER PRIMARY KEY,user_id INT,registration_id INT,score REAL,submitted_at TEXT);");
for ($i=1;$i<=16;$i++) $pdo->prepare('INSERT INTO users VALUES(?,?,?,?,?)')->execute([$i,$i===1?'admin':'mahasiswa',$i===9?'Reguler':'RPL','Mahasiswa <'.$i.'>','NIM'.$i]);
ensureTutorialRplRegistrationTable($pdo);
$admin=['id'=>1,'role'=>'admin']; $student=['id'=>2,'role'=>'mahasiswa'];
$period=tutorialRplSavePeriod($pdo,['nama'=>'RPL 2026','gelombang'=>'1','opens_at'=>'2026-11-23 00:00:00','closes_at'=>'2026-11-27 23:59:59','quota'=>2,'active_slot'=>'1'],$admin);
$now=(new DateTimeImmutable('2026-11-24 12:00:00',new DateTimeZone('Asia/Jakarta')))->getTimestamp();
$pdo->exec("INSERT INTO pretes_registrations VALUES(3,3),(4,4),(5,5),(6,6),(8,8);
    INSERT INTO pretes_cbt_attempts VALUES(3,3,3,79.99,'2026-11-22'),(4,4,4,80,'2026-11-22'),(5,5,-5,100,'2026-11-22'),(6,6,6,100,NULL),(8,8,8,101,'2026-11-22');
    INSERT INTO pretes_results VALUES(7,7,'lulus',NULL);");
rplCheck(tutorialRplPretesEvidence($pdo,2)===null,'no pretes permitted');
rplCheck(tutorialRplPretesEvidence($pdo,3)['score']===79.99,'official fail below 80');
rplCheck(tutorialRplPretesEvidence($pdo,4)['score']===80.0,'exact 80 boundary');
rplCheck(!tutorialRplPretesEvidence($pdo,5)['submitted'],'tryout ignored');
rplCheck(!tutorialRplPretesEvidence($pdo,6)['submitted'],'pending CBT not a pass');
rplCheck(tutorialRplPretesEvidence($pdo,7)['legacy_pass'],'historical official lulus without fabricated score');
$pdo->exec("INSERT INTO pretes_registrations VALUES(1601,16),(1602,16); INSERT INTO pretes_cbt_attempts VALUES(1601,16,1601,80,'2026-11-22'),(1602,16,1602,100,NULL)");
rplCheck(tutorialRplPretesEvidence($pdo,16)['score']===80.0,'new pending registration does not erase completed official CBT pass');
rplReject(fn()=>tutorialRplPretesEvidence($pdo,8),'invalid score');
rplReject(fn()=>tutorialRplRegister($pdo,$period,4,$admin,'admin',$now),'official passed');
rplReject(fn()=>tutorialRplRegister($pdo,$period,7,$admin,'admin',$now),'historical passed');
rplReject(fn()=>tutorialRplRegister($pdo,$period,9,$admin,'admin',$now),'Reguler');
rplReject(fn()=>tutorialRplRegister($pdo,$period,3,$student,'mandiri',$now),'self cannot register another');
rplReject(fn()=>tutorialRplRegister($pdo,$period,3,['id'=>2,'role'=>'admin'],'admin',$now),'forged session role reloaded from DB');
$self=tutorialRplRegister($pdo,$period,2,$student,'mandiri',$now);
$viaAdmin=tutorialRplRegister($pdo,$period,3,$admin,'admin',$now);
rplCheck($self['created'] && $viaAdmin['created'],'self and admin persist same table');
rplCheck(tutorialRplRegistrationFor($pdo,$period,2)['registered_by']===2,'self actor audit');
rplCheck(tutorialRplRegistrationFor($pdo,$period,3)['channel']==='admin','admin channel audit');
rplCheck(!tutorialRplRegister($pdo,$period,2,$admin,'admin',$now)['created'],'cross-channel duplicate idempotent');
rplReject(fn()=>tutorialRplRegister($pdo,$period,5,$admin,'admin',$now),'shared quota exhausted');
rplCheck(tutorialRplRegistrationCount($pdo,$period)===2,'no overflow or duplicate after rejected writes');
$row=tutorialRplActivePeriod($pdo);
rplReject(fn()=>tutorialRplSavePeriod($pdo,array_replace($row,['quota'=>1]),$admin),'cannot lower quota below enrolled count');
$pdo->exec('UPDATE tutorial_rpl_registration_periods SET quota=0');
rplCheck(tutorialRplRegister($pdo,$period,5,$admin,'admin',$now)['created'],'zero quota unlimited, tryout still eligible');
rplReject(fn()=>tutorialRplRegister($pdo,$period,6,$admin,'admin',$now+10*86400),'expired window');
$pdo->exec('UPDATE tutorial_rpl_registration_periods SET active_slot=NULL');
rplReject(fn()=>tutorialRplRegister($pdo,$period,6,$admin,'admin',$now),'inactive');
$pdo->exec('UPDATE tutorial_rpl_registration_periods SET active_slot=1');

// Respect existing multi-component mode; a CBT pass alone does not replace required tasks.
$pdo->exec("CREATE TABLE pretes_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT); INSERT INTO pretes_settings VALUES('rpl_pass_mode','cbt_tasks');
    CREATE TABLE pretes_components(comp_key TEXT,program TEXT,is_active INT); INSERT INTO pretes_components VALUES('praktik','rpl',1);
    INSERT INTO pretes_assessments VALUES(4,'{\"praktik\":79}');");
rplCheck(tutorialRplPretesEvidence($pdo,4)['score']===79.0,'full mode checks every required component');
$pdo->exec("UPDATE pretes_assessments SET scores_json='{\"praktik\":80}' WHERE registration_id=4");
rplCheck(tutorialRplPretesEvidence($pdo,4)['score']===80.0,'complete full-mode pass');
$pdo->exec("UPDATE pretes_assessments SET scores_json='{}' WHERE registration_id=4");
rplCheck(!tutorialRplPretesEvidence($pdo,4)['submitted'],'incomplete assessment is not exempt');
$pdo->exec("UPDATE pretes_assessments SET scores_json='broken' WHERE registration_id=4");
rplReject(fn()=>tutorialRplPretesEvidence($pdo,4),'malformed JSON fails closed');
$pdo->exec("UPDATE pretes_settings SET setting_value='cbt_only'; DELETE FROM pretes_assessments;
    INSERT INTO pretes_results VALUES(10,10,'lulus',79);");
rplReject(fn()=>tutorialRplPretesEvidence($pdo,10),'conflicting legacy status and score');
$pdo->exec('ALTER TABLE pretes_results RENAME TO unavailable_results');
rplReject(fn()=>tutorialRplRegister($pdo,$period,6,$admin,'admin',$now),'DB error never becomes no pretes');
rplCheck(tutorialRplRegistrationFor($pdo,$period,6)===null,'DB failure rolled back');
$pdo->exec('ALTER TABLE unavailable_results RENAME TO pretes_results');

// Race two real processes against the last available place.
$pdo->exec('UPDATE tutorial_rpl_registration_periods SET quota=4');
$helper=var_export(realpath(__DIR__.'/../includes/tutorial-rpl-registration.php'),true);
file_put_contents($dir.'/worker.php','<?php require '.$helper.'; $pdo=new PDO("sqlite:".$argv[1],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $pdo->exec("PRAGMA busy_timeout=5000"); try { tutorialRplRegister($pdo,(int)$argv[2],(int)$argv[3],["id"=>1],"admin",(int)$argv[4]); echo "created"; } catch(DomainException $e){echo "full";}');
$jobs=[];
foreach ([11,12] as $uid) { $pipes=[]; $process=proc_open([PHP_BINARY,$dir.'/worker.php',$dbFile,(string)$period,(string)$uid,(string)$now],[1=>['pipe','w'],2=>['pipe','w']],$pipes); $jobs[]=[$process,$pipes]; }
$out=[];
foreach ($jobs as [$process,$pipes]) { $out[]=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);rplCheck(proc_close($process)===0 && $err==='','race worker errors: '.$err); }
sort($out); rplCheck($out===['created','full'] && tutorialRplRegistrationCount($pdo,$period)===4,'exactly one concurrent registration receives last place');

// Actual page handlers with isolated authentication and the fixture database, no production connections.
foreach (['includes','pages','admin'] as $folder) mkdir($dir.'/'.$folder);
foreach (['includes/tutorial-rpl-registration.php','includes/tutorial-rpl-periods.php','includes/tutorial-rpl-registration-policy.php','includes/pretes-components.php','pages/tutorial-rpl-daftar.php','admin/tutorial-rpl-pendaftaran.php'] as $file) copy(__DIR__.'/../'.$file,$dir.'/'.$file);
file_put_contents($dir.'/includes/auth.php', <<<'PHP'
<?php
define('BASE_URL','');
function getCurrentUser(){return $GLOBALS['request']['actor'] ?? ['id'=>1,'role'=>'admin'];}
function requireLogin(){if(!getCurrentUser()['id']){http_response_code(403);exit;}}
function requireAdmin(){if(getCurrentUser()['role']!=='admin'){http_response_code(403);exit;}}
function getDBConnection(){return new PDO('sqlite:'.__DIR__.'/../test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function csrfToken(){return 'valid';} function verifyCsrf($v){return $v==='valid';}
PHP
);
file_put_contents($dir.'/includes/header.php','<!doctype html><html><body>'); file_put_contents($dir.'/includes/footer.php','</body></html>');
file_put_contents($dir.'/request.php', <<<'PHP'
<?php
$request=json_decode($argv[1],true); $_POST=$request['post']??[];$_GET=$request['get']??[];$_SESSION=[];$_SERVER['REQUEST_METHOD']=$request['method']??'GET';http_response_code(200);ob_start();
register_shutdown_function(function(){echo json_encode(['status'=>http_response_code(),'html'=>ob_get_clean()]);});
include __DIR__.(($request['page']??'')==='student'?'/pages/tutorial-rpl-daftar.php':'/admin/tutorial-rpl-pendaftaran.php');
PHP
);
$request=static function(array $payload) use($dir){$pipes=[];$p=proc_open([PHP_BINARY,$dir.'/request.php',json_encode($payload)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);rplCheck(proc_close($p)===0 && $err==='','page handler errors: '.$err);return json_decode($out,true,512,JSON_THROW_ON_ERROR);};
$pdo->exec("UPDATE tutorial_rpl_registration_periods SET quota=0,opens_at='2000-01-01 00:00:00',closes_at='2099-12-31 23:59:59'");
try {
    $candidate=['id'=>13,'role'=>'mahasiswa'];
    $page=$request(['page'=>'student','actor'=>$candidate]);
    rplCheck(str_contains($page['html'],'name="csrf_token"') && str_contains($page['html'],'Daftar Tutorial RPL'),'self form renders');
    rplCheck($request(['page'=>'student','actor'=>$candidate,'method'=>'POST','post'=>['period_id'=>$period]])['status']===403,'self CSRF rejected');
    rplCheck($request(['page'=>'student','actor'=>$candidate,'method'=>'POST','post'=>['period_id'=>$period,'user_id'=>14,'csrf_token'=>'valid']])['status']===302,'self valid POST redirects');
    rplCheck(tutorialRplRegistrationFor($pdo,$period,13)!==null && tutorialRplRegistrationFor($pdo,$period,14)===null,'self ignores forged target user id');
    rplCheck(str_contains($request(['page'=>'student','actor'=>$candidate])['html'],'Anda sudah terdaftar'),'self success persists after new request');
    $search=$request(['get'=>['q'=>'NIM14']]);
    rplCheck(str_contains($search['html'],'Mahasiswa &lt;14&gt;') && !str_contains($search['html'],'Mahasiswa <14>'),'admin escaped search result');
    rplCheck($request(['method'=>'POST','post'=>['period_id'=>$period,'user_id'=>14]])['status']===403,'admin CSRF rejected');
    rplCheck($request(['actor'=>$candidate,'method'=>'POST','post'=>['period_id'=>$period,'user_id'=>14,'csrf_token'=>'valid']])['status']===403,'student cannot access admin handler');
    rplCheck($request(['method'=>'POST','post'=>['period_id'=>$period,'user_id'=>14,'csrf_token'=>'valid']])['status']===302,'admin valid POST redirects');
    rplCheck(tutorialRplRegistrationFor($pdo,$period,14)['channel']==='admin','admin registration persists');
    $before=tutorialRplRegistrationCount($pdo,$period);
    rplCheck($request(['method'=>'POST','post'=>['period_id'=>$period,'user_id'=>14,'csrf_token'=>'valid']])['status']===302,'repeated admin POST is idempotent');
    $passed=$request(['method'=>'POST','post'=>['period_id'=>$period,'user_id'=>4,'csrf_token'=>'valid']]);
    rplCheck(str_contains($passed['html'],'dibebaskan dari tutorial') && !str_contains($passed['html'],'Belum ada periode aktif.'),'passed student rejected without hiding valid period');
    rplCheck(tutorialRplRegistrationFor($pdo,$period,4)===null && tutorialRplRegistrationCount($pdo,$period)===$before,'pass and retry do not create extra registrations');
    $pdo->exec('UPDATE tutorial_rpl_registration_periods SET quota='.$before);
    $full=$request(['page'=>'student','actor'=>['id'=>15,'role'=>'mahasiswa'],'method'=>'POST','post'=>['period_id'=>$period,'csrf_token'=>'valid']]);
    rplCheck(str_contains($full['html'],'sudah penuh') && tutorialRplRegistrationFor($pdo,$period,15)===null,'actual self handler enforces shared quota');
    rplCheck(str_contains($request([])['html'],'Mahasiswa &lt;14&gt;'),'admin sees saved registration in list');
    rplCheck($request(['page'=>'student','actor'=>['id'=>9,'role'=>'mahasiswa']])['status']===403,'Reguler rejected on self page');
} finally {
    $pdo=null;
    foreach (['includes','pages','admin'] as $folder) { foreach(glob($dir.'/'.$folder.'/*') as $file) unlink($file); rmdir($dir.'/'.$folder); }
    foreach(glob($dir.'/*') as $file) unlink($file); rmdir($dir);
}
echo "PASS: both registration channels, official/legacy/multi-component evidence, dates, quota race, idempotency, authorization, CSRF and real page handlers.\n";
