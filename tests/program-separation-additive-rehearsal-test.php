<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/program-separation/additive-rehearsal.php';
use Lppai\ProgramSeparation\Capture;
use Lppai\ProgramSeparation\AdditiveRehearsal;
function rehearsalCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function rehearsalFixture():PDO {
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE master_pretes(id INT PRIMARY KEY,periode TEXT,target_program TEXT,status TEXT);
        INSERT INTO master_pretes VALUES(1,'Campuran','Semua','nonaktif'),(2,'RPL khusus','RPL','nonaktif'),(3,'Reguler khusus','Reguler','nonaktif'),(4,'Duplikat','RPL','nonaktif'),(5,'DUPLIKAT','Reguler','nonaktif');
        CREATE TABLE pretes_registrations(id INT PRIMARY KEY,user_id INT,periode TEXT,status TEXT);
        INSERT INTO pretes_registrations VALUES(1,10,'Campuran','lulus'),(2,20,'RPL khusus','lulus'),(3,30,'Reguler khusus','tidak_lulus'),(4,40,'Duplikat','terdaftar'),(5,50,'Hilang','terdaftar');
        CREATE TABLE pretes_cbt_sessions(id INT PRIMARY KEY,mode TEXT,is_active INT,exam_open INT);
        INSERT INTO pretes_cbt_sessions VALUES(1,'resmi',0,0),(2,'tryout',0,0);
        CREATE TABLE pretes_cbt_packages(id INT PRIMARY KEY,title TEXT);INSERT INTO pretes_cbt_packages VALUES(1,'Snapshot paket lama');
        CREATE TABLE pretes_cbt_attempts(id INT PRIMARY KEY,registration_id INT,user_id INT,score REAL,submitted_at INT,deadline_at INT);
        INSERT INTO pretes_cbt_attempts VALUES(1,1,10,90,100,200),(2,2,20,85.5,100,200),(3,3,30,60,100,200),(4,-2001,20,NULL,NULL,200),(5,2,99,70,100,200),(6,4,40,80,100,200);
        CREATE TABLE pretes_cbt_items(id INT PRIMARY KEY,attempt_id INT,answer TEXT,correct_answer TEXT,question_text TEXT);
        INSERT INTO pretes_cbt_items VALUES(1,1,'B','B','Snapshot soal'),(2,4,'C','A','Snapshot tryout belum submit');
        CREATE TABLE tutorial_registrations(id INT PRIMARY KEY,user_id INT,status TEXT);INSERT INTO tutorial_registrations VALUES(1,20,'lulus');
        CREATE TABLE users(id INT PRIMARY KEY,jenis_program TEXT);INSERT INTO users VALUES(10,'RPL'),(20,'Reguler'),(30,'RPL');");
    return $pdo;
}
$pdo=rehearsalFixture();$before=Capture::fingerprint($pdo,Capture::tables($pdo));$plan=AdditiveRehearsal::prepare($pdo);
rehearsalCheck($before===Capture::fingerprint($pdo,Capture::tables($pdo)),'Prepare must only read');
$out=AdditiveRehearsal::apply($pdo,$plan);
rehearsalCheck($out['added_columns']===6 && $out['original_tables_verified']===8 && $out['all_original_column_values_unchanged'],'Six additive columns and protection of all tables');
rehearsalCheck(AdditiveRehearsal::projection($pdo,$plan['original_columns'])===$before,'Every old value must match, including scores/items/tutorial/users/flags/deadlines');
rehearsalCheck($pdo->query('SELECT program_scope FROM master_pretes WHERE id=1')->fetchColumn()===null,'Mixed legacy period must stay unassigned');
$regs=$pdo->query('SELECT id,periode_id,program_snapshot FROM pretes_registrations ORDER BY id')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
rehearsalCheck((int)$regs[1]['periode_id']===1 && $regs[1]['program_snapshot']===null,'Mixed registration may get stable period ID, never guessed program');
rehearsalCheck($regs[2]['program_snapshot']==='rpl' && $regs[3]['program_snapshot']==='reguler','Historical period is evidence, current account program cannot move history');
rehearsalCheck($regs[4]['periode_id']===null && $regs[5]['periode_id']===null && $out['registrations_without_unique_period']===2,'Case-ambiguous and missing labels must not be guessed');
$attempts=$pdo->query('SELECT id,program_snapshot FROM pretes_cbt_attempts')->fetchAll(PDO::FETCH_KEY_PAIR);
rehearsalCheck($attempts[2]==='rpl' && $attempts[3]==='reguler' && $attempts[1]===null && $attempts[4]===null && $attempts[5]===null && $attempts[6]===null,'Only valid official registration-owner links may inherit a proven period program');
rehearsalCheck((int)$pdo->query('SELECT COUNT(*) FROM pretes_cbt_sessions WHERE program_scope IS NOT NULL')->fetchColumn()===0,'Do not infer session owners');
try {AdditiveRehearsal::prepare($pdo);throw new LogicException('Rehearsal repeated on modified schema');}catch(RuntimeException $expected){}
$stale=rehearsalFixture();$stalePlan=AdditiveRehearsal::prepare($stale);$stale->exec('UPDATE pretes_cbt_attempts SET score=0 WHERE id=1');
try{AdditiveRehearsal::apply($stale,$stalePlan);throw new LogicException('Stale input accepted');}catch(RuntimeException $expected){}
rehearsalCheck(!in_array('program_scope',AdditiveRehearsal::columns($stale,'master_pretes'),true),'Stale data rejected before DDL');
$trigger=rehearsalFixture();
$trigger->exec('CREATE TRIGGER alter_score AFTER UPDATE ON pretes_registrations BEGIN UPDATE pretes_cbt_attempts SET score=0 WHERE id=1; END;');
$triggerPlan=AdditiveRehearsal::prepare($trigger);
try {AdditiveRehearsal::apply($trigger,$triggerPlan);throw new LogicException('Side effect accepted');}catch(RuntimeException $expected){}
rehearsalCheck(AdditiveRehearsal::projection($trigger,$triggerPlan['original_columns'])===$triggerPlan['original_fingerprint'],'Metadata DML and trigger side effects roll back on preservation failure');
rehearsalCheck(in_array('program_scope',AdditiveRehearsal::columns($trigger,'master_pretes'),true),'DDL may remain after failure; never pretend schema rolled back');
echo "PASS: additive rehearsal, full original-data preservation, mixed/ambiguous history, ownership, tryout, stale input, repeat guard and trigger rollback with remaining DDL.\n";
