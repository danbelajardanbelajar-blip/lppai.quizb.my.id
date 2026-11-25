<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/pretes-program-repository.php';
require_once __DIR__.'/../includes/pretes-helper.php';
require_once __DIR__.'/../includes/program-separation/capture.php';
use Lppai\ProgramSeparation\Scope;
use Lppai\ProgramSeparation\Capture;
function portalCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$dir=str_replace('\\','/',sys_get_temp_dir()).'/pretes-portals-'.bin2hex(random_bytes(5));mkdir($dir,0700);mkdir($dir.'/admin');mkdir($dir.'/includes');
$pdo=new PDO('sqlite:'.$dir.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,nama_lengkap TEXT,nim TEXT,program_studi TEXT,jenis_program TEXT,role TEXT);
CREATE TABLE master_pretes(id INTEGER PRIMARY KEY,periode TEXT,tahun_ajaran TEXT,target_program TEXT,status TEXT,tanggal_mulai TEXT);
INSERT INTO master_pretes VALUES(1,'Arsip campuran','2026','Semua','selesai','2026-11-01'),(2,'RPL saja','2026','RPL','nonaktif','2026-12-01'),(3,'Reguler saja','2026','Reguler','nonaktif','2026-12-02');
CREATE TABLE pretes_registrations(id INTEGER PRIMARY KEY,user_id INT,periode TEXT,hari_pilihan TEXT,jam TEXT,dosen_penguji TEXT,ruangan TEXT,status TEXT);
CREATE TABLE pretes_cbt_sessions(id INTEGER PRIMARY KEY,title TEXT,mode TEXT,is_active INT,exam_open INT);
INSERT INTO pretes_cbt_sessions VALUES(1,'Resmi arsip','resmi',0,0),(2,'Latihan arsip','tryout',0,0);
CREATE TABLE pretes_cbt_attempts(id INTEGER PRIMARY KEY,user_id INT,registration_id INT,session_id INT,started_at INT,deadline_at INT,submitted_at INT,score REAL,correct_count INT);
CREATE TABLE pretes_cbt_items(attempt_id INT,position INT,question_id INT,question_text TEXT,options_json TEXT,correct_answer TEXT,answer TEXT,flagged INT);
");
$user=$pdo->prepare('INSERT INTO users VALUES(?,?,?,?,?,?)');$registration=$pdo->prepare('INSERT INTO pretes_registrations VALUES(?,?,?,?,?,?,?,?)');
for($i=1;$i<=27;$i++){
    $name=$i===1?'=RPL <b>':'RPL Mahasiswa '.$i;if($i===2)$name='RPL %_ Literal';
    $user->execute([$i,$name,sprintf('001%03d',$i),'PAI','RPL','mahasiswa']);
    $registration->execute([$i,$i,$i===27?'RPL saja':'Arsip campuran','Senin','13.00','Tutor A','Ruang 1','terdaftar']);
}
$user->execute([99,'REGULER PRIVATE','0099','TI','Reguler','mahasiswa']);$user->execute([199,'UNKNOWN PRIVATE','0199','TI','','mahasiswa']);
$registration->execute([999,99,'Reguler saja','Selasa','13.00','Tutor B','Ruang 2','terdaftar']);
$pdo->exec("INSERT INTO pretes_cbt_attempts VALUES(1,1,1,1,100,200,150,86.5,1),(2,1,-2001,2,100,200,NULL,NULL,NULL),(3,99,999,1,100,200,150,93.75,1),(4,199,1999,1,100,200,150,70,1);
INSERT INTO pretes_cbt_items VALUES(1,1,1,'RPL SNAPSHOT ONLY','{\"A\":\"Pilihan A\",\"B\":\"Pilihan B\"}','B','B',0),(3,1,2,'KUNCI REGULER RAHASIA','{\"A\":\"Rahasia\",\"B\":\"Lain\"}','A','A',0);");
ensurePretesFeatures($pdo);ensurePretesComponentTable($pdo);ensurePretesSettingsTable($pdo);
$pdo->exec("INSERT INTO pretes_assessments(registration_id,scores_json,assessed_by)VALUES(1,'{\"ujian_tulis\":86.5}',99),(999,'{\"ujian_tulis\":93.75}',99)");
$rplTask=(int)$pdo->query("SELECT id FROM pretes_components WHERE program='rpl' ORDER BY id LIMIT 1")->fetchColumn();
$regTask=(int)$pdo->query("SELECT id FROM pretes_components WHERE program='reguler' ORDER BY id LIMIT 1")->fetchColumn();
$rplRepo=new PretesProgramRepository($pdo,new Scope('pretes-rpl'));$regRepo=new PretesProgramRepository($pdo,new Scope('pretes-reguler'));
$before=Capture::fingerprint($pdo,Capture::tables($pdo));
portalCheck($rplRepo->counts()['participants']===27 && $regRepo->counts()['participants']===1,'Counts harus dibatasi program');
portalCheck(array_column($rplRepo->periods(),'id')===[2,1] && array_column($regRepo->periods(),'id')===[3,1],'Periode sendiri + arsip campuran, bukan periode program lain');
portalCheck($rplRepo->listing('participants','%_')['count']===1,'Wildcard pencarian diperlakukan literal');
portalCheck(count($rplRepo->listing('participants','',null,'all',2)['cursor']->fetchAll())===2,'Halaman kedua memuat sisa data');
foreach([fn()=>$rplRepo->periodName(3),fn()=>$rplRepo->review(3)]as$denied){try{$denied();throw new LogicException('Cross-program resource accepted');}catch(OutOfBoundsException $expected){}}
portalCheck($rplRepo->review(2)['attempt']['submitted_at']===null,'Review pending tidak memfinalisasi ujian');

foreach(['pretes-rpl.php','pretes-reguler.php','pretes-program.php','pretes-rpl-tugas.php','pretes-reguler-tugas.php','pretes-tugas.php']as$file)copy(__DIR__.'/../admin/'.$file,$dir.'/admin/'.$file);
foreach(['pretes-program-repository.php','pretes-helper.php']as$file)file_put_contents($dir.'/includes/'.$file,'<?php require_once '.var_export(realpath(__DIR__.'/../includes/'.$file),true).';');
foreach(['pretes-program-view.php']as$file)file_put_contents($dir.'/includes/'.$file,'<?php include '.var_export(realpath(__DIR__.'/../includes/'.$file),true).';');
$reviewView=file_get_contents(__DIR__.'/../includes/cbt-attempt-review-view.php');
foreach(['header','footer']as$part)$reviewView=str_replace("include __DIR__ . '/".$part.".php';",'include '.var_export($dir.'/includes/'.$part.'.php',true).';',$reviewView);
file_put_contents($dir.'/includes/cbt-attempt-review-view.php',$reviewView);
file_put_contents($dir.'/includes/auth.php', <<<'PHP'
<?php
define('BASE_URL','');
function requireAdmin(){if(($GLOBALS['request']['role']??'admin')!=='admin'){http_response_code(403);exit;}}
function getDBConnection(){return new PDO('sqlite:'.__DIR__.'/../fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
function verifyCsrf($token){return $token==='valid';}function csrfToken(){return 'valid';}
function sanitize($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function adminUserProfileLink($name,$id){return sanitize($name);}
function renderJenisProgramBadge($program,$small=false){return sanitize($program);}
PHP
);
file_put_contents($dir.'/includes/header.php','<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main>');
file_put_contents($dir.'/includes/footer.php','</main></body></html>');
file_put_contents($dir.'/request.php', <<<'PHP'
<?php
$request=json_decode($argv[1],true);$_GET=$request['get']??[];$_POST=$request['post']??[];$_SERVER['REQUEST_METHOD']=$request['method']??'GET';session_start();http_response_code(200);ob_start();
register_shutdown_function(function(){echo json_encode(['status'=>http_response_code(),'html'=>ob_get_clean()]);});
include __DIR__.'/admin/'.($request['page']??'pretes-rpl.php');
PHP
);
$request=static function(array $payload)use($dir):array{
    $process=proc_open([PHP_BINARY,$dir.'/request.php',json_encode($payload)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    portalCheck(proc_close($process)===0&&$err==='','Controller error: '.$err);return json_decode($out,true,512,JSON_THROW_ON_ERROR);
};
try{
    foreach(['pretes-rpl.php'=>'RPL','pretes-reguler.php'=>'Reguler']as$page=>$label){
        foreach(['periods','participants','results','tasks']as$tab){$response=$request(['page'=>$page,'get'=>['tab'=>$tab]]);portalCheck($response['status']===200&&str_contains($response['html'],'Pretes '.$label),'Tab scoped harus render: '.$page.'/'.$tab);}
    }
    $response=$request(['get'=>['tab'=>'participants']]);
    portalCheck(!str_contains($response['html'],'REGULER PRIVATE')&&!str_contains($response['html'],'UNKNOWN PRIVATE'),'Nama mahasiswa program lain tidak bocor');
    $response=$request(['get'=>['tab'=>'participants','page'=>'2']]);portalCheck(str_contains($response['html'],'=RPL &lt;b&gt;'),'Nama di-escape pada HTML');
    foreach([['get'=>['program'=>'reguler']],['get'=>['program'=>['rpl']]],['method'=>'POST'],['get'=>['tab'=>'bad']],['get'=>['review'=>3]],['get'=>['period'=>3]]]as$payload){$response=$request($payload);portalCheck($response['status']>=400&&!str_contains($response['html'],'KUNCI REGULER RAHASIA'),'Forged route/filter/review harus ditolak');}
    portalCheck($request(['page'=>'pretes-program.php','get'=>['program'=>'rpl']])['status']===404,'Controller bersama tidak menerima program dari URL');
    portalCheck($request(['role'=>'mahasiswa'])['status']===403,'Mahasiswa tidak boleh membaca portal admin');
    portalCheck($request(['get'=>['tab'=>'participants','period'=>'']])['status']===200,'Semua periode dari form kosong valid');
    $response=$request(['get'=>['review'=>1]]);portalCheck($response['status']===200&&str_contains($response['html'],'RPL SNAPSHOT ONLY')&&!str_contains($response['html'],'KUNCI REGULER RAHASIA'),'Review memakai snapshot peserta di program yang benar');
    $response=$request(['get'=>['tab'=>'results','kind'=>'tryout']]);portalCheck(str_contains($response['html'],'Belum dikumpulkan')&&!str_contains($response['html'],'86.5'),'Filter tryout tidak mencampur resmi');
    $response=$request(['get'=>['tab'=>'participants','download'=>'csv']]);portalCheck(str_contains($response['html'],"'=RPL <b>")&&!str_contains($response['html'],'REGULER PRIVATE'),'CSV scoped dan terlindung formula');
    portalCheck(Capture::fingerprint($pdo,Capture::tables($pdo))===$before,'Portal/review/ekspor tidak menulis data, nilai, jawaban, atau sesi');
    $pdo->exec("INSERT INTO master_pretes VALUES(4,'Arsip campuran','2026','RPL','selesai','2026-11-01')");
    portalCheck($request(['get'=>['period'=>1]])['status']===409,'Periode legacy bernama sama tidak ditebak');$pdo->exec('DELETE FROM master_pretes WHERE id=4');
    foreach(['pretes-rpl-tugas.php'=>'rpl','pretes-reguler-tugas.php'=>'reguler']as$page=>$program){
        $response=$request(['page'=>$page]);portalCheck($response['status']===200&&str_contains($response['html'],'pretes-'.$program.'-tugas.php'),'Route tugas tetap pada programnya');
        portalCheck($request(['page'=>$page,'get'=>['program'=>$program==='rpl'?'reguler':'rpl']])['status']===403,'Query program tidak mengubah scope route tugas');
    }
    $response=$request(['page'=>'pretes-rpl-tugas.php','method'=>'POST','post'=>['action'=>'save','id'=>$rplTask,'program'=>'rpl','label'=>'Without CSRF']]);
    portalCheck($response['status']===403&&$pdo->query('SELECT label FROM pretes_components WHERE id='.$rplTask)->fetchColumn()!=='Without CSRF','CSRF diperlukan untuk perubahan tugas');
    $response=$request(['page'=>'pretes-rpl-tugas.php','method'=>'POST','post'=>['action'=>'save','id'=>$regTask,'program'=>'reguler','label'=>'Spoof','csrf_token'=>'valid']]);portalCheck($response['status']===403,'POST program lain ditolak sebelum mutasi');
    $request(['page'=>'pretes-rpl-tugas.php','method'=>'POST','post'=>['action'=>'save','id'=>$regTask,'program'=>'rpl','label'=>'Spoof','csrf_token'=>'valid']]);
    portalCheck($pdo->query('SELECT label FROM pretes_components WHERE id='.$regTask)->fetchColumn()!=='Spoof','ID tugas lain tidak dapat diedit');
    $mode=$pdo->query('SELECT * FROM pretes_settings')->fetchAll();
    portalCheck($request(['page'=>'pretes-reguler-tugas.php','method'=>'POST','post'=>['action'=>'set_rpl_mode','rpl_pass_mode'=>'cbt_only','csrf_token'=>'valid','program'=>'reguler']])['status']===403,'Halaman Reguler tidak mengubah kelulusan RPL');
    portalCheck($pdo->query('SELECT * FROM pretes_settings')->fetchAll()===$mode,'Pengaturan kelulusan tetap');
    $response=$request(['page'=>'pretes-rpl-tugas.php','method'=>'POST','post'=>['action'=>'save','id'=>$rplTask,'program'=>'rpl','label'=>'Edit tugas RPL valid','csrf_token'=>'valid']]);
    portalCheck($response['status']===302&&$pdo->query('SELECT label FROM pretes_components WHERE id='.$rplTask)->fetchColumn()==='Edit tugas RPL valid','Tugas sendiri tetap bisa disimpan');
    portalCheck((float)$pdo->query('SELECT score FROM pretes_cbt_attempts WHERE id=1')->fetchColumn()===86.5&&(int)$pdo->query('SELECT SUM(is_active) FROM pretes_cbt_sessions')->fetchColumn()===0,'CRUD tugas tidak mengubah nilai atau membuka ujian');
    echo "PASS: dua portal admin scoped, periode/arsip, search/pagination, review/CSV, role, forged GET/POST/ID, tugas sendiri dan integritas nilai/jawaban/sesi.\n";
}finally{
    pretesComponentsPdo(null,true);$denied=null;
    $rplRepo=$regRepo=$pdo=$user=$registration=null;
    foreach(['admin','includes']as$folder){foreach(glob($dir.'/'.$folder.'/*')as$file)unlink($file);rmdir($dir.'/'.$folder);}
    foreach(glob($dir.'/*')as$file)unlink($file);rmdir($dir);
}
