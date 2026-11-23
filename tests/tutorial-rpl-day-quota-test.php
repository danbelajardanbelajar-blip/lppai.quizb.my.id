<?php
require_once __DIR__.'/../includes/tutorial-rpl-registration.php';
function dayCheck($condition,string $message): void { if (!$condition) throw new RuntimeException('FAIL: '.$message); }
function dayReject(callable $fn,string $message): void { try { $fn(); } catch (DomainException | InvalidArgumentException $e) { return; } throw new RuntimeException('FAIL: expected rejection '.$message); }
$dir=sys_get_temp_dir().'/rpl-day-'.bin2hex(random_bytes(6)); mkdir($dir);
$file=$dir.'/test.sqlite'; $pdo=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("PRAGMA busy_timeout=5000;
CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,jenis_program TEXT,nama_lengkap TEXT);
CREATE TABLE pretes_results(id INTEGER PRIMARY KEY,user_id INT,status_lulus TEXT,nilai REAL);
CREATE TABLE pretes_registrations(id INTEGER PRIMARY KEY,user_id INT);
INSERT INTO users VALUES(1,'admin','RPL','Admin'),(100,'dosen','RPL','Dosen A'),(101,'dosen','RPL','Dosen B');");
for($i=2;$i<=15;$i++)$pdo->prepare('INSERT INTO users VALUES(?,?,?,?)')->execute([$i,'mahasiswa','RPL','Mahasiswa '.$i]);
ensureTutorialRplPeriodTable($pdo);
$admin=['id'=>1,'role'=>'admin'];
$slots=[['dosen_id'=>100,'hari'=>'Senin','quota'=>1],['dosen_id'=>101,'hari'=>'Senin','quota'=>1],['dosen_id'=>100,'hari'=>'Minggu','quota'=>2]];
$data=['nama'=>'Periode hari RPL','gelombang'=>'1','opens_at'=>'2000-01-01 00:00:00','closes_at'=>'2099-12-31 23:59:59','quota'=>0,'active_slot'=>'1','schedules'=>$slots];
dayReject(fn()=>tutorialRplSavePeriod($pdo,array_replace($data,['schedules'=>[]]),$admin),'active period requires lecturers/days');
dayReject(fn()=>tutorialRplSavePeriod($pdo,array_replace($data,['schedules'=>[$slots[0],$slots[0]]]),$admin),'duplicate lecturer/day');
dayReject(fn()=>tutorialRplSavePeriod($pdo,array_replace($data,['schedules'=>[['dosen_id'=>2,'hari'=>'Senin','quota'=>1]]]),$admin),'student cannot be lecturer');
dayReject(fn()=>tutorialRplSavePeriod($pdo,array_replace($data,['schedules'=>[['dosen_id'=>100,'hari'=>'Bad','quota'=>1]]]),$admin),'invalid day');
dayReject(fn()=>tutorialRplSavePeriod($pdo,array_replace($data,['schedules'=>[['dosen_id'=>100,'hari'=>'Senin','quota'=>0]]]),$admin),'lecturer quota must be positive');
dayCheck((int)$pdo->query('SELECT COUNT(*) FROM tutorial_rpl_registration_periods')->fetchColumn()===0,'invalid schedules roll back whole period creation');
$period=tutorialRplSavePeriod($pdo,$data,$admin);
// Upgrade the original stage-3 schema without assigning arbitrary days to historical records.
$pdo->exec("CREATE TABLE tutorial_rpl_registrations(id INTEGER PRIMARY KEY AUTOINCREMENT,period_id INT,user_id INT,channel TEXT,registered_by INT,registered_at TEXT,evidence_json TEXT,UNIQUE(period_id,user_id));
INSERT INTO tutorial_rpl_registrations VALUES(50,$period,14,'mandiri',14,'2026-11-23 01:02:03','{}');");
ensureTutorialRplRegistrationTable($pdo); ensureTutorialRplRegistrationTable($pdo);
$old=tutorialRplRegistrationFor($pdo,$period,14);
dayCheck($old['hari_pilihan']===null && $old['schedule_id']===null && (int)$old['id']===50,'idempotent migration preserves historical registration');
$now=(new DateTimeImmutable('2026-11-24',new DateTimeZone('Asia/Jakarta')))->getTimestamp();
dayReject(fn()=>tutorialRplRegister($pdo,$period,2,$admin,'admin',$now),'must explicitly choose a day');
dayReject(fn()=>tutorialRplRegister($pdo,$period,2,$admin,'admin',$now,'Selasa'),'unconfigured day');
tutorialRplRegister($pdo,$period,2,['id'=>2],'mandiri',$now,'Senin');
tutorialRplRegister($pdo,$period,3,$admin,'admin',$now,'Senin');
$a=tutorialRplRegistrationFor($pdo,$period,2);$b=tutorialRplRegistrationFor($pdo,$period,3);
dayCheck($a['schedule_id']!==$b['schedule_id'],'two lecturers each receive at most their own quota');
dayReject(fn()=>tutorialRplRegister($pdo,$period,4,$admin,'admin',$now,'Senin'),'day full even though other day available and total quota unlimited');
$availability=tutorialRplDayAvailability($pdo,$period);
dayCheck($availability['Senin']['quota']===2 && $availability['Senin']['remaining']===0 && $availability['Minggu']['remaining']===2,'per-day capacity sums lecturer quotas including Sunday');
$picked=tutorialRplRegister($pdo,$period,14,$admin,'admin',$now,'Minggu');
$migrated=tutorialRplRegistrationFor($pdo,$period,14);
dayCheck(!$picked['created'] && $picked['day_selected'] && (int)$migrated['id']===50 && (int)$migrated['registered_by']===14 && $migrated['channel']==='mandiri' && $migrated['registered_at']==='2026-11-23 01:02:03','old student selects day without changing original audit');
dayCheck((int)$migrated['day_selected_by']===1 && $migrated['day_selected_at']==='2026-11-24 00:00:00','record day selection actor and WIB time separately');
tutorialRplRegister($pdo,$period,14,$admin,'admin',$now,'Senin');
dayCheck(tutorialRplRegistrationFor($pdo,$period,14)['hari_pilihan']==='Minggu','duplicate submit does not move reserved day');
tutorialRplRegister($pdo,$period,4,$admin,'admin',$now,'Minggu');
$row=tutorialRplActivePeriod($pdo);
$tooSmall=$slots;$tooSmall[2]['quota']=1;
dayReject(fn()=>tutorialRplSavePeriod($pdo,array_replace($row,['schedules'=>$tooSmall]),$admin),'cannot shrink reserved lecturer quota');
dayReject(fn()=>tutorialRplSavePeriod($pdo,array_replace($row,['schedules'=>array_slice($slots,1)]),$admin),'cannot remove occupied lecturer/day');
dayCheck(tutorialRplActivePeriod($pdo)['version']===$row['version'] && count(tutorialRplPeriodTutors($pdo,$period))===3,'failed edit rolls back period version and schedule rows');
$pdo->exec("UPDATE users SET role='mahasiswa' WHERE id=101");
dayCheck(tutorialRplDayAvailability($pdo,$period)['Senin']['quota']===1,'role changes remove invalid lecturer capacity');
tutorialRplSavePeriod($pdo,array_replace($row,['active_slot'=>null]),$admin);
$pdo->exec("UPDATE users SET role='dosen' WHERE id=101");
// Actual concurrent requests: total quota unlimited; only the lecturer's single remaining seat limits them.
$race=tutorialRplSavePeriod($pdo,array_replace($data,['nama'=>'Race day','schedules'=>[['dosen_id'=>100,'hari'=>'Sabtu','quota'=>1]]]),$admin);
$helper=var_export(realpath(__DIR__.'/../includes/tutorial-rpl-registration.php'),true);
file_put_contents($dir.'/worker.php','<?php require '.$helper.';$pdo=new PDO("sqlite:".$argv[1],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec("PRAGMA busy_timeout=5000");try{tutorialRplRegister($pdo,(int)$argv[2],(int)$argv[3],["id"=>1],"admin",null,"Sabtu");echo "saved";}catch(DomainException $e){echo "full";}');
$jobs=[];foreach([10,11]as$uid){$pipes=[];$p=proc_open([PHP_BINARY,$dir.'/worker.php',$file,(string)$race,(string)$uid],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$jobs[]=[$p,$pipes];}
$results=[];foreach($jobs as[$p,$pipes]){$results[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);dayCheck(proc_close($p)===0 && $err==='','race worker has no errors:'.$err);}sort($results);
dayCheck($results===['full','saved'] && tutorialRplRegistrationCount($pdo,$race)===1,'lecturer/day capacity cannot overflow across simultaneous requests');
$pdo=null;foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);
echo "PASS: lecturer/day validation, per-lecturer capacity, weekend choice, legacy migration, protected edits and concurrent day-quota enforcement.\n";
