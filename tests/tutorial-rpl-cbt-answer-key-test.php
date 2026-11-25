<?php
require_once __DIR__.'/../includes/tutorial-rpl-cbt-engine.php';
function keyCheck($condition,string $message):void{if(!$condition)throw new RuntimeException('FAIL: '.$message);}
function keyReject(callable $fn,string $message):void{try{$fn();}catch(InvalidArgumentException|JsonException $e){return;}throw new RuntimeException('FAIL: expected rejection '.$message);}
$list=['Baju batik','Pakaian hitam','Pakaian warna-warni','Jubah merah','Pakaian putih'];
$map=array_combine(range('A','E'),$list);
foreach([$list,$map,array_change_key_case($map,CASE_LOWER),array_map(static fn($text)=>['text'=>$text],$list)]as$options){
    for($i=0;$i<100;$i++){
        $result=tutorialRplCbtShuffleOptions(json_encode($options),'B','E',true);
        $out=json_decode($result['options_json'],true);
        keyCheck($out[$result['correct_answer']]==='Pakaian putih','key follows correct option text for all JSON formats');
        keyCheck($out[$result['answer']]==='Pakaian hitam','selected option follows the same original choice');
        $a=array_values($out);$b=$list;sort($a);sort($b);keyCheck($a===$b,'shuffle never drops or invents options');
    }
}
$duplicate=tutorialRplCbtShuffleOptions(['A'=>'Sama','B'=>'Sama','C'=>'Lain'],'A','B',true);
keyCheck($duplicate['answer']!==$duplicate['correct_answer'],'identical text does not conflate two different option identities');
foreach([['A'=>'Ya','B'=>'Tidak'],['Ya','Tidak','','',''],['Ya','Tidak',null,null,null]]as$options){$r=tutorialRplCbtShuffleOptions($options,null,'B');keyCheck(count(json_decode($r['options_json'],true))===2,'unused blank options excluded');}
keyReject(fn()=>tutorialRplCbtShuffleOptions($list,null,'Z'),'missing key never falls back to A');
keyReject(fn()=>tutorialRplCbtShuffleOptions(['A'=>'Ya','B'=>'Tidak','C'=>''],null,'C'),'blank key');
keyReject(fn()=>tutorialRplCbtShuffleOptions($list,'Z','E'),'invalid stored selection');
keyReject(fn()=>tutorialRplCbtNormalizeOptions('broken'),'malformed JSON');
keyReject(fn()=>tutorialRplCbtNormalizeOptions([1=>'x',2=>'y']),'ambiguous one-based numeric format');

$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,nama_lengkap TEXT,nim TEXT,jenis_program TEXT,no_hp TEXT);
INSERT INTO users VALUES(10,'dosen','Dosen','010','RPL',''),(201,'mahasiswa','Mahasiswa','201','RPL','');");
ensureTutorialRplTables($pdo);ensureTutorialRplCbtProctoringTables($pdo);
$pdo->exec("INSERT INTO tutorial_rpl_classes(id,nama_kelompok,is_active) VALUES(10,'Kelas',1);
INSERT INTO tutorial_rpl_members(id,class_id,user_id,nilai_praktik) VALUES(50,10,201,80);
UPDATE tutorial_rpl_cbt_packages SET question_count=2 WHERE id=1;
UPDATE tutorial_rpl_cbt_sessions SET token_required=0,camera_mode='tidak_aktif',shuffle_on_violation=1 WHERE id=1;
DELETE FROM tutorial_rpl_cbt_questions;");
$insert=$pdo->prepare('INSERT INTO tutorial_rpl_cbt_questions(id,code,package_id,category,question_text,options_json,correct_answer,is_active) VALUES(?,?,1,?,?,?,?,1)');
foreach([1,2]as$id)$insert->execute([$id,'TEST-'.$id,'Adab','Soal '.$id,json_encode($list),'E']);
$now=1700000000;$started=tutorialRplCbtStart($pdo,201,10,$now,null,false,1);$attemptId=(int)$started['attempt']['id'];
$read=static function()use($pdo,$attemptId){$s=$pdo->prepare('SELECT * FROM tutorial_rpl_cbt_items WHERE attempt_id=? ORDER BY position');$s->execute([$attemptId]);return $s->fetchAll();};
foreach($read()as$item){$opts=json_decode($item['options_json'],true);keyCheck($opts[$item['correct_answer']]==='Pakaian putih','initial attempt key matches source semantics, not just key letter');keyCheck(count(array_filter($opts))===5,'numeric legacy bank creates complete visible choices');}
$items=$read();$answer=array_search('Pakaian putih',json_decode($items[1]['options_json'],true),true);
tutorialRplCbtChange($pdo,201,10,'save',['position'=>2,'answer'=>$answer,'revision'=>0],$now+5);
$insert->execute([3,'TEST-3','Adab','Soal cadangan',json_encode($list),'E']);
tutorialRplCbtChange($pdo,201,10,'interrupt',['event_id'=>'key-audit-12345678','reason'=>'face_mismatch','position'=>1,'revision'=>0],$now+10);
foreach($read()as$item){$opts=json_decode($item['options_json'],true);keyCheck($opts[$item['correct_answer']]==='Pakaian putih','replacement/resume shuffle preserves source key');if($item['answer']!==null)keyCheck($opts[$item['answer']]==='Pakaian putih','previously answered choice retains meaning');}
tutorialRplCbtChange($pdo,201,10,'resume',[],$now+20);
foreach($read()as$item){$key=array_search('Pakaian putih',json_decode($item['options_json'],true),true);tutorialRplCbtChange($pdo,201,10,'save',['position'=>$item['position'],'answer'=>$key,'revision'=>1],$now+25);}
$submitted=tutorialRplCbtChange($pdo,201,10,'submit',['revision'=>1],$now+30);
keyCheck((float)$submitted['attempt']['score']===100.0,'answering by correct text earns 100 after start and interruption');
$qid=tutorialRplCbtSaveQuestion($pdo,['question_text'=>'Tulis baru','options_json'=>$list,'correct_answer'=>'E']);
$saved=tutorialRplCbtGetQuestions($pdo);$found=array_values(array_filter($saved,static fn($q)=>(int)$q['id']===$qid))[0];
keyCheck(json_decode($found['options_json'],true)['E']==='Pakaian putih','new bank writes canonical letters');
keyReject(fn()=>tutorialRplCbtSaveQuestion($pdo,['question_text'=>'Kunci kosong','options_json'=>['Ya','Tidak'],'correct_answer'=>'E']),'bank save rejects absent answer');
keyReject(fn()=>tutorialRplCbtSaveQuestion($pdo,['question_text'=>'Satu opsi','options_json'=>['Ya'],'correct_answer'=>'A']),'bank save rejects fewer than two nonempty options');
$before=$read();
$pdo->exec("INSERT INTO users VALUES(202,'mahasiswa','Mahasiswa 2','202','RPL',''); INSERT INTO tutorial_rpl_members(id,class_id,user_id) VALUES(51,10,202); UPDATE tutorial_rpl_cbt_questions SET correct_answer='Z'");
keyReject(fn()=>tutorialRplCbtStart($pdo,202,10,$now+60,null,false,1),'bad bank key aborts new attempt');
keyCheck((int)$pdo->query('SELECT COUNT(*) FROM tutorial_rpl_cbt_attempts WHERE user_id=202')->fetchColumn()===0,'invalid key leaves no partial attempt');
keyCheck($read()===$before && (float)$pdo->query('SELECT score FROM tutorial_rpl_cbt_attempts WHERE user_id=201')->fetchColumn()===100.0,'historical snapshot and score are not rewritten by validation');
echo "PASS: semantic answer-key/selection preservation for numeric/letter/object options, initial start, reserve shuffle, duplicate texts, validation and final scoring.\n";
