<?php
require_once __DIR__.'/../includes/tutorial-rpl-tasks.php';
function taskCheck($ok,string $message):void{if(!$ok)throw new RuntimeException('FAIL: '.$message);}
$dir=sys_get_temp_dir().'/rpl-task-copy-'.bin2hex(random_bytes(6));mkdir($dir);
$file=$dir.'/fixture.sqlite';$pdo=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
ensurePretesComponentTable($pdo);ensurePretesSettingsTable($pdo);
$pdo->exec("DELETE FROM pretes_components WHERE program='rpl';
INSERT INTO pretes_components(program,comp_key,label,bentuk,kategori,video_url,video_title,reading_title,reading_text,sort_order,is_active) VALUES
('rpl','rpl_a','Tugas <RPL A>','Hafalan','Al-Qur''an & Doa','https://example.test/video','Video RPL','Bacaan RPL','Teks Arab & latin',20,1),
('rpl','rpl_b','Tugas RPL B','Praktik','Praktik Ibadah & Thaharah',NULL,NULL,NULL,NULL,10,0);
CREATE TABLE tutorial_rpl_tasks(id INTEGER PRIMARY KEY AUTOINCREMENT,task_key TEXT UNIQUE,label TEXT,bentuk TEXT,kategori TEXT,video_url TEXT,video_title TEXT,reading_title TEXT,reading_text TEXT,sort_order INT,is_active INT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
INSERT INTO tutorial_rpl_tasks(task_key,label,bentuk,kategori,sort_order,is_active) VALUES('old_key','Tugas lama','Hafalan','Ubudiyah',1,1);
CREATE TABLE tutorial_rpl_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
INSERT INTO tutorial_rpl_settings VALUES('rpl_tutorial_pass_mode','cbt_only',CURRENT_TIMESTAMP);
CREATE TABLE tutorial_rpl_members(id INTEGER PRIMARY KEY,nilai_akhir REAL);INSERT INTO tutorial_rpl_members VALUES(1,85);
");
pretesSettingSet($pdo,'rpl_pass_mode','cbt_tasks');
$sourceBefore=$pdo->query('SELECT * FROM pretes_components ORDER BY id')->fetchAll();
$pretesSettings=$pdo->query('SELECT * FROM pretes_settings')->fetchAll();
$old=$pdo->query('SELECT * FROM tutorial_rpl_tasks')->fetchAll();
ensureTutorialRplTasksTables($pdo,99);
$target=static function()use($pdo){return $pdo->query('SELECT task_key AS comp_key,label,bentuk,kategori,video_url,video_title,reading_title,reading_text,sort_order,is_active FROM tutorial_rpl_tasks ORDER BY sort_order,id')->fetchAll();};
$source=$pdo->query("SELECT comp_key,label,bentuk,kategori,video_url,video_title,reading_title,reading_text,sort_order,is_active FROM pretes_components WHERE program='rpl' ORDER BY sort_order,id")->fetchAll();
taskCheck($target()===$source,'all list fields copied exactly, including inactive order and media');
taskCheck(count($target())===2,'Reguler tasks never copied');
taskCheck(tutorialRplPassMode($pdo)==='cbt_only','tutorial pass mode not copied/changed');
taskCheck($pdo->query('SELECT * FROM pretes_components ORDER BY id')->fetchAll()===$sourceBefore && $pdo->query('SELECT * FROM pretes_settings')->fetchAll()===$pretesSettings,'pretes source and settings unchanged');
$audit=$pdo->query('SELECT * FROM tutorial_rpl_task_imports')->fetch();
taskCheck(json_decode($audit['backup_json'],true)===$old && (int)$audit['actor_id']===99,'complete old list backed up with actor');
taskCheck(json_decode($audit['source_json'],true)===$source,'exact source snapshot saved');
$ids=$pdo->query('SELECT task_key,id FROM tutorial_rpl_tasks')->fetchAll(PDO::FETCH_KEY_PAIR);
$pdo->exec("UPDATE pretes_components SET label='Sumber berubah' WHERE program='rpl' AND comp_key='rpl_a'");
$second=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
taskCheck(array_column(tutorialRplTaskRows($second),'label')===['Tugas RPL B','Tugas <RPL A>'],'fresh connection does not overwrite tutorial list on every GET');$second=null;
tutorialRplTaskCopyPretesRpl($pdo,true,99);
taskCheck(array_column($target(),'label')===['Tugas RPL B','Sumber berubah'],'explicit copy refreshes source');
taskCheck($pdo->query('SELECT task_key,id FROM tutorial_rpl_tasks')->fetchAll(PDO::FETCH_KEY_PAIR)===$ids,'same source keys retain tutorial IDs');
taskCheck((float)$pdo->query('SELECT nilai_akhir FROM tutorial_rpl_members WHERE id=1')->fetchColumn()===85.0,'student grades unaffected');
// A failed replacement must roll back row changes and the backup/import log together.
$pdo->exec("INSERT INTO tutorial_rpl_tasks(task_key,label,bentuk,kategori,sort_order,is_active) VALUES('protected_custom','Pertahankan','Praktik','Ubudiyah',30,1);
CREATE TRIGGER block_task_delete BEFORE DELETE ON tutorial_rpl_tasks WHEN OLD.task_key='protected_custom' BEGIN SELECT RAISE(ABORT,'fixture');END;
UPDATE pretes_components SET label='Belum boleh tersalin' WHERE program='rpl' AND comp_key='rpl_a';");
$before=$target();$imports=(int)$pdo->query('SELECT COUNT(*) FROM tutorial_rpl_task_imports')->fetchColumn();
try{tutorialRplTaskCopyPretesRpl($pdo,true,99);throw new RuntimeException('Expected rollback');}catch(PDOException $expected){}
taskCheck($target()===$before && (int)$pdo->query('SELECT COUNT(*) FROM tutorial_rpl_task_imports')->fetchColumn()===$imports,'failed copy has no partial rows or audit records');
$pdo->exec('DROP TRIGGER block_task_delete');
// Source read errors must not be replaced with defaults.
$pdo->exec('ALTER TABLE pretes_components RENAME TO pretes_source_backup; CREATE TABLE pretes_components(program TEXT)');
try{tutorialRplTaskCopyPretesRpl($pdo,true,99);throw new RuntimeException('Expected source failure');}catch(PDOException $sourceError){}
taskCheck($target()===$before,'source error preserves previous list');
$pdo->exec('DROP TABLE pretes_components; ALTER TABLE pretes_source_backup RENAME TO pretes_components');

// Actual administrator handler and CSRF check, using the fixture database only.
foreach(['admin','includes']as$folder)mkdir($dir.'/'.$folder);
copy(__DIR__.'/../admin/tutorial-rpl-tugas.php',$dir.'/admin/tutorial-rpl-tugas.php');
file_put_contents($dir.'/includes/tutorial-rpl-helper.php','<?php function ensureTutorialRplTables($pdo) {}');
file_put_contents($dir.'/includes/tutorial-rpl-tasks.php','<?php require_once '.var_export(realpath(__DIR__.'/../includes/tutorial-rpl-tasks.php'),true).';');
file_put_contents($dir.'/includes/auth.php', <<<'PHP'
<?php
define('BASE_URL','');function requireAdmin(){if(($GLOBALS['request']['role']??'admin')!=='admin'){http_response_code(403);exit;}}
function getDBConnection(){return new PDO('sqlite:'.__DIR__.'/../fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function getCurrentUser(){return ['id'=>99,'role'=>'admin'];}function csrfToken(){return 'valid';}function verifyCsrf($v){return $v==='valid';}
PHP
);
file_put_contents($dir.'/includes/header.php','<!doctype html><html><body>');file_put_contents($dir.'/includes/footer.php','</body></html>');
file_put_contents($dir.'/request.php', <<<'PHP'
<?php
$request=json_decode($argv[1],true);$_GET=$request['get']??[];$_POST=$request['post']??[];$_SERVER['REQUEST_METHOD']=$request['method']??'GET';http_response_code(200);ob_start();
register_shutdown_function(function(){echo json_encode(['status'=>http_response_code(),'html'=>ob_get_clean()]);});include __DIR__.'/admin/tutorial-rpl-tugas.php';
PHP
);
$request=static function(array $payload)use($dir){$pipes=[];$p=proc_open([PHP_BINARY,$dir.'/request.php',json_encode($payload)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);taskCheck(proc_close($p)===0 && $err==='','page errors:'.$err);return json_decode($out,true,512,JSON_THROW_ON_ERROR);};
try{
    $get=$request([]);taskCheck($get['status']===200 && str_contains($get['html'],'Salin ulang dari Pretes RPL'),'copy control renders');
    taskCheck(str_contains($get['html'],'value="cbt_only" checked'),'existing pass mode stays selected');
    $blocked=$request(['method'=>'POST','post'=>['action'=>'copy_pretes_rpl_list']]);
    taskCheck($blocked['status']===403 && $target()===$before,'missing CSRF cannot copy');
    taskCheck($request(['role'=>'mahasiswa','method'=>'POST','post'=>['action'=>'copy_pretes_rpl_list','csrf_token'=>'valid']])['status']===403,'nonadmin cannot copy');
    $copied=$request(['method'=>'POST','post'=>['action'=>'copy_pretes_rpl_list','csrf_token'=>'valid','program'=>'reguler']]);
    taskCheck($copied['status']===302 && count($target())===2 && $target()[1]['label']==='Belum boleh tersalin','actual POST copies only RPL even with forged program');
    taskCheck(tutorialRplPassMode($pdo)==='cbt_only','copy POST preserves pass mode');
}finally{
    unset($expected,$sourceError);$target=null;$pdo=null;
    foreach(['admin','includes']as$folder){foreach(glob($dir.'/'.$folder.'/*')as$f)unlink($f);rmdir($dir.'/'.$folder);}foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);
}
echo "PASS: exact RPL-only task copy, backup, one-time initialization, explicit recopy, unchanged modes/grades/source, rollback, authorization and CSRF.\n";
