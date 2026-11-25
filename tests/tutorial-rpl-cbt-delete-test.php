<?php
require_once __DIR__.'/../includes/tutorial-rpl-helper.php';
function deleteCheck($ok,string $message):void{if(!$ok)throw new RuntimeException('FAIL: '.$message);}
$dir=sys_get_temp_dir().'/rpl-delete-'.bin2hex(random_bytes(6));mkdir($dir);
$db=$dir.'/test.sqlite';$pdo=new PDO('sqlite:'.$db,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,nim TEXT,nama_lengkap TEXT,program_studi TEXT,jenis_program TEXT,role TEXT,no_hp TEXT);
INSERT INTO users VALUES(1,'001','Admin','PAI','RPL','admin','');");
ensureTutorialRplTables($pdo);ensureTutorialRplCbtTables($pdo);
$pdo->exec("INSERT INTO tutorial_rpl_classes(id,nama_kelompok) VALUES(1,'Kelas Uji');
INSERT INTO tutorial_rpl_cbt_attempts(id,user_id,class_id,session_id,package_id,started_at,deadline_at,submitted_at,score,correct_count,total_questions,submitted) VALUES(1,1,1,1,1,10,100,60,75,3,4,1);
INSERT INTO tutorial_rpl_cbt_items(attempt_id,question_id,position,question_text,options_json,correct_answer,answer) VALUES(1,1,1,'Snapshot tetap ada','[\"A\",\"B\"]','A','A');");
$history=$pdo->query('SELECT * FROM tutorial_rpl_cbt_attempts')->fetchAll(PDO::FETCH_ASSOC);
$snapshots=$pdo->query('SELECT * FROM tutorial_rpl_cbt_items')->fetchAll(PDO::FETCH_ASSOC);
deleteCheck(tutorialRplCbtDeletePackage($pdo,1),'default package 1 can be deleted explicitly');
deleteCheck(!tutorialRplCbtGetPackage($pdo,1),'deleted package stays deleted after helpers rerun');
deleteCheck((int)$pdo->query('SELECT COUNT(*) FROM tutorial_rpl_cbt_questions WHERE package_id=1')->fetchColumn()===0,'package questions removed');
$session=$pdo->query('SELECT package_ids_json,is_active,exam_open FROM tutorial_rpl_cbt_sessions WHERE id=1')->fetch(PDO::FETCH_ASSOC);
deleteCheck($session['package_ids_json']==='[]' && (int)$session['is_active']===0 && (int)$session['exam_open']===0,'session losing every package is closed');
deleteCheck(tutorialRplCbtDeletePackage($pdo,2),'default package 2 can be deleted');
deleteCheck(tutorialRplCbtGetPackages($pdo)===[] && tutorialRplCbtGetQuestions($pdo)===[],'empty bank is not automatically restored');
deleteCheck(!tutorialRplCbtDeletePackage($pdo,999),'unknown package is not reported as deleted');
deleteCheck(tutorialRplCbtDeleteSession($pdo,1),'single default session deletion');
deleteCheck(tutorialRplCbtDeleteSessions($pdo,[2,2,0])===1,'bulk deletion deduplicates IDs and can remove last session');
deleteCheck(tutorialRplCbtGetSessions($pdo)===[],'empty sessions stay empty after reload');
$fallback=tutorialRplCbtGetSession($pdo);
deleteCheck((int)$fallback['id']===0 && (int)$fallback['exam_open']===0,'no fabricated open session after final deletion');
deleteCheck($pdo->query('SELECT * FROM tutorial_rpl_cbt_attempts')->fetchAll(PDO::FETCH_ASSOC)===$history && $pdo->query('SELECT * FROM tutorial_rpl_cbt_items')->fetchAll(PDO::FETCH_ASSOC)===$snapshots,'all scores/attempts/item snapshots survive deletion');
$second=new PDO('sqlite:'.$db,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);ensureTutorialRplCbtTables($second);
deleteCheck(tutorialRplCbtGetPackages($second)===[] && tutorialRplCbtGetSessions($second)===[],'initialization marker persists across new DB connections');$second=null;
$reset=tutorialRplCbtSeedPackagesAndQuestions($pdo,true);
deleteCheck(count(tutorialRplCbtGetPackages($pdo))===2 && count(tutorialRplCbtGetSessions($pdo))===2 && $reset['total_questions']>0,'explicit reset can still restore defaults');
$before=(int)$pdo->query('SELECT COUNT(*) FROM tutorial_rpl_cbt_questions WHERE package_id=1')->fetchColumn();
$pdo->exec("CREATE TRIGGER block_package_delete BEFORE DELETE ON tutorial_rpl_cbt_packages WHEN OLD.id=1 BEGIN SELECT RAISE(ABORT,'test');END");
try{tutorialRplCbtDeletePackage($pdo,1);throw new RuntimeException('Expected database failure');}catch(PDOException $expected){}
deleteCheck((int)$pdo->query('SELECT COUNT(*) FROM tutorial_rpl_cbt_questions WHERE package_id=1')->fetchColumn()===$before,'package delete failure rolls back question deletion');
$pdo->exec('DROP TRIGGER block_package_delete');
$emptyPackage=tutorialRplCbtSavePackage($pdo,['name'=>'Paket kosong','code'=>'EMPTY','is_active'=>1]);
try{tutorialRplCbtGetOrCreateAttempt($pdo,2,2,tutorialRplCbtGetSession($pdo,1),1,$emptyPackage);throw new RuntimeException('Expected empty package rejection');}
catch(RuntimeException $emptyError){deleteCheck(!str_contains($emptyError->getMessage(),'Expected'),'empty package does not trigger forced reset of deleted/customized bank');}

// Full controller requests with actual SQL helpers, isolated auth and local SQLite.
foreach(['admin','includes','assets','assets/css']as$folder)mkdir($dir.'/'.$folder);
copy(__DIR__.'/../admin/tutorial-rpl-cbt.php',$dir.'/admin/tutorial-rpl-cbt.php');
copy(__DIR__.'/../assets/css/pretes-cbt-admin.css',$dir.'/assets/css/pretes-cbt-admin.css');
foreach(['tutorial-rpl-helper.php','pretes-cbt-xlsx.php','tutorial-rpl-cbt-review.php']as$file)file_put_contents($dir.'/includes/'.$file,'<?php require_once '.var_export(realpath(__DIR__.'/../includes/'.$file),true).';');
file_put_contents($dir.'/includes/auth.php', <<<'PHP'
<?php
define('BASE_URL','');function requireAdmin(){if(($GLOBALS['request']['role']??'admin')!=='admin'){http_response_code(403);exit;}}
function getDBConnection(){return new PDO('sqlite:'.__DIR__.'/../test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function getCurrentUser(){return ['id'=>1,'role'=>'admin'];}
function sanitize($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function csrfToken(){return 'valid';}function verifyCsrf($v){return $v==='valid';}
PHP
);
file_put_contents($dir.'/includes/header.php','<!doctype html><html><body><main class="content-area">');file_put_contents($dir.'/includes/footer.php','</main></body></html>');
file_put_contents($dir.'/request.php', <<<'PHP'
<?php
$request=json_decode($argv[1],true);$_GET=$request['get']??['tab'=>'packages'];$_POST=$request['post']??[];$_SESSION=[];$_SERVER['REQUEST_METHOD']=$request['method']??'GET';http_response_code(200);ob_start();
register_shutdown_function(function(){echo json_encode(['status'=>http_response_code(),'html'=>ob_get_clean()]);});require __DIR__.'/admin/tutorial-rpl-cbt.php';
PHP
);
$request=static function(array $payload)use($dir){$pipes=[];$process=proc_open([PHP_BINARY,$dir.'/request.php',json_encode($payload)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);deleteCheck(proc_close($process)===0 && $err==='','controller without PHP errors:'.$err);return json_decode($out,true,512,JSON_THROW_ON_ERROR);};
try{
    $get=$request([]);deleteCheck(str_contains($get['html'],'value="delete_package"') && str_contains($get['html'],'name="csrf_token"'),'delete form available on default packages with CSRF');
    $blocked=$request(['method'=>'POST','post'=>['action'=>'delete_package','package_id'=>1]]);
    deleteCheck($blocked['status']===403 && tutorialRplCbtGetPackage($pdo,1)!==null,'missing CSRF prevents delete');
    deleteCheck($request(['role'=>'mahasiswa','method'=>'POST','post'=>['action'=>'delete_package','package_id'=>1,'csrf_token'=>'valid']])['status']===403,'student cannot delete');
    $deleted=$request(['method'=>'POST','post'=>['action'=>'delete_package','package_id'=>1,'csrf_token'=>'valid']]);
    deleteCheck(str_contains($deleted['html'],'berhasil dihapus') && tutorialRplCbtGetPackage($pdo,1)===null,'actual package POST deletes protected-before package');
    $request(['get'=>['tab'=>'sessions'],'method'=>'POST','post'=>['action'=>'delete_session','session_id'=>1,'csrf_token'=>'valid']]);
    $request(['get'=>['tab'=>'sessions'],'method'=>'POST','post'=>['action'=>'delete_sessions','session_ids'=>[2],'csrf_token'=>'valid']]);
    $reload=$request(['get'=>['tab'=>'sessions']]);deleteCheck(tutorialRplCbtGetSessions($pdo)===[] && str_contains($reload['html'],'Belum ada sesi'),'last session is still gone after a fresh full-page GET');
    $pdo->exec('DELETE FROM tutorial_rpl_cbt_questions WHERE package_id=2');
    try{tutorialRplCbtGetOrCreateAttempt($pdo,2,2,['id'=>2],2,2);throw new RuntimeException('Expected unavailable exam');}catch(RuntimeException $e){deleteCheck(!str_contains($e->getMessage(),'Expected'),'deleted sessions do not silently start attempts');}
    deleteCheck(tutorialRplCbtGetPackages($pdo)!==[] && tutorialRplCbtGetSessions($pdo)===[],'attempt path does not restore deleted sessions');
    deleteCheck($pdo->query('SELECT * FROM tutorial_rpl_cbt_attempts')->fetchAll(PDO::FETCH_ASSOC)===$history && $pdo->query('SELECT * FROM tutorial_rpl_cbt_items')->fetchAll(PDO::FETCH_ASSOC)===$snapshots,'controller deletions preserve historic exam data');
}finally{
    unset($expected,$e,$emptyError);
    $pdo=null;
    foreach(['admin','includes','assets/css']as$folder){foreach(glob($dir.'/'.$folder.'/*')as$file)unlink($file);rmdir($dir.'/'.$folder);}rmdir($dir.'/assets');foreach(glob($dir.'/*')as$file)unlink($file);rmdir($dir);
}
echo "PASS: package/session deletes persist across reloads/connections, default packages, transaction rollback, history preservation, CSRF and actual controller POST.\n";
