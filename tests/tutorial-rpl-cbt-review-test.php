<?php
require_once __DIR__.'/../includes/tutorial-rpl-cbt-review.php';
function reviewCheck($condition,string $message):void {if(!$condition)throw new RuntimeException('FAIL: '.$message);}
$dir=sys_get_temp_dir().'/cbt-review-'.bin2hex(random_bytes(6));
foreach(['','/admin','/includes']as$folder)mkdir($dir.$folder);
$pdo=new PDO('sqlite:'.$dir.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,nama_lengkap TEXT,nim TEXT,program_studi TEXT,jenis_program TEXT,no_hp TEXT,email TEXT);
INSERT INTO users VALUES(1,'Mahasiswa <uji>','RPL001','PAI','RPL','',''),(2,'Peserta Pretes','PRE001','Hukum','Reguler','','');
CREATE TABLE tutorial_rpl_classes(id INTEGER PRIMARY KEY,nama_kelompok TEXT);INSERT INTO tutorial_rpl_classes VALUES(1,'Kelompok RPL');
CREATE TABLE tutorial_rpl_cbt_sessions(id INTEGER PRIMARY KEY,title TEXT,passing_grade REAL);INSERT INTO tutorial_rpl_cbt_sessions VALUES(1,'Sesi RPL',70);
CREATE TABLE tutorial_rpl_cbt_packages(id INTEGER PRIMARY KEY,name TEXT);INSERT INTO tutorial_rpl_cbt_packages VALUES(1,'Paket RPL');
CREATE TABLE tutorial_rpl_cbt_attempts(id INTEGER PRIMARY KEY,user_id INT,class_id INT,session_id INT,package_id INT,started_at INT,deadline_at INT,submitted_at INT,score REAL);
CREATE TABLE tutorial_rpl_cbt_items(attempt_id INT,position INT,question_id INT,question_text TEXT,options_json TEXT,correct_answer TEXT,answer TEXT,flagged INT);
CREATE TABLE pretes_cbt_attempts(id INTEGER PRIMARY KEY,user_id INT,registration_id INT,session_id INT,started_at INT,deadline_at INT,submitted_at INT,score REAL);
CREATE TABLE pretes_cbt_items(attempt_id INT,position INT,question_id INT,question_text TEXT,options_json TEXT,correct_answer TEXT,answer TEXT,flagged INT);
CREATE TABLE pretes_cbt_sessions(id INTEGER PRIMARY KEY,title TEXT);INSERT INTO pretes_cbt_sessions VALUES(1,'Sesi Pretes');");
$now=time();
foreach([1,2,3]as$id){$start=$now-120;$deadline=$id===3?$now-60:$now+3600;$submitted=$id===1?$now:null;$score=$id===1?75:null;
    $pdo->prepare('INSERT INTO tutorial_rpl_cbt_attempts VALUES(?,1,1,1,1,?,?,?,?)')->execute([$id,$start,$deadline,$submitted,$score]);
    $pdo->prepare('INSERT INTO pretes_cbt_attempts VALUES(?,2,1,1,?,?,?,?)')->execute([$id,$start,$deadline,$submitted,$score]);
    foreach([
        [1,['<b>Opsi A</b>','B','C','D','E'],'A','A',0],
        [2,['A'=>'Pilihan A','B'=>'Pilihan B','C'=>'Pilihan C'],'C','B',0],
        [3,['a'=>'Opsi kecil A','b'=>'Opsi kecil B'],'B',null,0],
        [4,[['text'=>'Objek A'],['text'=>'Objek B'],['text'=>'Objek C'],['text'=>'Objek D']],'D','d',1]
    ]as[$position,$opts,$key,$answer,$flag])foreach(['tutorial_rpl_cbt_items','pretes_cbt_items']as$table)$pdo->prepare("INSERT INTO $table VALUES(?,?,?,?,?,?,?,?)")->execute([$id,$position,$position,'Soal <img src=x onerror=alert(1)> '.$position,json_encode($opts),$key,$answer,$flag]);
}
$pdo->exec('PRAGMA query_only=ON');
$data=tutorialRplCbtReviewData($pdo,1);
reviewCheck($data['attempt']['nim']==='RPL001' && count($data['items'])===4,'adapter uses RPL data only, under query-only connection');
reviewCheck(tutorialRplCbtReviewData($pdo,999)['attempt']===null,'unknown attempt');
reviewCheck(tutorialRplCbtReviewContext(['q'=>'uji','page'=>'3','return_url'=>'https://evil.test','group_filter'=>[]])===['tab'=>'results','q'=>'uji','page'=>'3'],'back context whitelist rejects external return URL and arrays');
foreach(['admin/tutorial-rpl-cbt-review.php','admin/pretes-cbt-review.php','includes/tutorial-rpl-cbt-review.php','includes/cbt-attempt-review-view.php']as$file)copy(__DIR__.'/../'.$file,$dir.'/'.$file);
file_put_contents($dir.'/includes/auth.php', <<<'PHP'
<?php
define('BASE_URL','');
function requireAdmin(){if(($GLOBALS['request']['role']??'admin')!=='admin'){http_response_code(403);exit;}}
function getDBConnection(){$p=new PDO('sqlite:'.__DIR__.'/../fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->exec('PRAGMA query_only=ON');return $p;}
function sanitize($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function adminUserProfileLink($s,$id){return '<a href="/admin/profil-pengguna.php?id='.(int)$id.'">'.sanitize($s).'</a>';}
function renderJenisProgramBadge($s,$flag){return '<span>'.sanitize($s).'</span>';}
PHP
);
file_put_contents($dir.'/includes/user-helper.php','<?php');
file_put_contents($dir.'/includes/pretes-cbt.php','<?php function ensurePretesCbtTables($p){} function pretesCbtGetSession($p,$id){$s=$p->prepare("SELECT * FROM pretes_cbt_sessions WHERE id=?");$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC);}');
file_put_contents($dir.'/includes/pretes-tryout.php','<?php function pretesTryoutAttemptLabel($p,$a,&$cache){return ["kind"=>"resmi","label"=>"Pretes Resmi"];}');
file_put_contents($dir.'/includes/header.php','<!doctype html><html><body>');file_put_contents($dir.'/includes/footer.php','</body></html>');
file_put_contents($dir.'/request.php', <<<'PHP'
<?php
$request=json_decode($argv[1],true);$_GET=$request['query']??['attempt_id'=>1];http_response_code(200);ob_start();
register_shutdown_function(function(){echo json_encode(['status'=>http_response_code(),'html'=>ob_get_clean()]);});
include __DIR__.'/admin/'.(($request['type']??'rpl')==='pretes'?'pretes-cbt-review.php':'tutorial-rpl-cbt-review.php');
PHP
);
$request=static function(array $payload)use($dir){$pipes=[];$p=proc_open([PHP_BINARY,$dir.'/request.php',json_encode($payload)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$expected=!empty($payload['expect_unavailable']) && str_contains($err,'Tutorial RPL CBT review: SQLSTATE') && !preg_match('/Warning|Fatal error|Parse error/',$err);reviewCheck(proc_close($p)===0 && ($err==='' || $expected),'no unexpected PHP errors: '.$err);return json_decode($out,true,512,JSON_THROW_ON_ERROR);};
try{
    $rpl=$request([]);$pretes=$request(['type'=>'pretes']);
    foreach([$rpl,$pretes]as$result){$html=$result['html'];reviewCheck($result['status']===200,'review renders');
        foreach(['Cetak / Simpan PDF','Total Butir Soal','Jawaban Benar','Jawaban Salah','Ditandai Ragu','Belum Dijawab','filterQuestions','highlightQuestion','number-palette-grid']as$text)reviewCheck(str_contains($html,$text),'shared feature '.$text);
        reviewCheck(substr_count($html,'class="review-question-card status-correct"')===2 && substr_count($html,'class="review-question-card status-wrong"')===1 && substr_count($html,'class="review-question-card status-empty"')===1,'correct/wrong/empty metrics use snapshots');
        reviewCheck(str_contains($html,'data-q-flagged="1"') && str_contains($html,'Objek D') && str_contains($html,'Opsi kecil B'),'flag and option formats preserved');
        reviewCheck(!str_contains($html,'<img src=x') && !str_contains($html,'<b>Opsi A</b>'),'question and option HTML escaped');
    }
    reviewCheck(str_contains($rpl['html'],'RPL001') && !str_contains($rpl['html'],'PRE001'),'RPL identity does not cross pretes data');
    reviewCheck(str_contains($rpl['html'],'✓ Lulus') && str_contains($pretes['html'],'Belum Lulus'),'same UI respects RPL session threshold 70 and pretes 80');
    $pdo->exec('PRAGMA query_only=OFF; UPDATE tutorial_rpl_cbt_sessions SET passing_grade=90');
    reviewCheck(str_contains($request([])['html'],'Belum Lulus'),'RPL review reads configured passing grade instead of hardcoding 70/80');
    $pdo->exec('UPDATE tutorial_rpl_cbt_sessions SET passing_grade=70; PRAGMA query_only=ON');
    reviewCheck(str_contains($request(['query'=>['attempt_id'=>2]])['html'],'Sedang Berlangsung'),'ongoing state');
    reviewCheck(str_contains($request(['query'=>['attempt_id'=>3]])['html'],'Waktu Habis'),'expired unsubmitted state');
    reviewCheck($request(['query'=>['attempt_id'=>999]])['status']===404,'missing RPL attempt returns 404');
    reviewCheck($request(['role'=>'mahasiswa'])['status']===403 && $request(['role'=>'dosen'])['status']===403,'nonadmin denied before reading answers');
    $back=$request(['query'=>['attempt_id'=>1,'result_filter'=>'2','q'=>'uji & nama','page'=>'3']]);
    reviewCheck(str_contains($back['html'],'/admin/tutorial-rpl-cbt.php?tab=results&amp;result_filter=2&amp;q=uji+%26+nama&amp;page=3'),'return to RPL results retains filters and pagination');
    $pdo->exec('PRAGMA query_only=OFF; ALTER TABLE tutorial_rpl_cbt_items RENAME TO unavailable_items');
    $failure=$request(['expect_unavailable'=>true]);
    reviewCheck($failure['status']===503 && str_contains($failure['html'],'Review Belum Tersedia') && !str_contains($failure['html'],'SQLSTATE'),'database failure returns a safe error without exposing SQL');
}finally{
    $pdo=null;
    foreach(['admin','includes']as$folder){foreach(glob($dir.'/'.$folder.'/*')as$file)unlink($file);rmdir($dir.'/'.$folder);}
    foreach(glob($dir.'/*')as$file)unlink($file);rmdir($dir);
}
echo "PASS: shared Pretes/RPL renderer, RPL-only snapshot queries, read-only review, answer states/options, threshold, authorization and return filters.\n";
