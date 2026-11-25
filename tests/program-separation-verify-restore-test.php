<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/program-separation/restore-verification.php';
use Lppai\ProgramSeparation\Capture;
use Lppai\ProgramSeparation\RestoreVerification;
function restoreCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function restoreReject(callable $action,string $message):void {
    try {$action();} catch (RuntimeException $expected) {return;}
    throw new LogicException($message);
}
$dir=sys_get_temp_dir().'/restore-verify-'.bin2hex(random_bytes(5));mkdir($dir,0700);
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try {
    $pdo->exec("CREATE TABLE pretes_cbt_items(id INT,answer TEXT,score REAL,detail BLOB);
        CREATE TABLE users(id INT,name TEXT);
        INSERT INTO users VALUES(1,'Nonacademic');
        INSERT INTO pretes_cbt_items VALUES(1,'B',86.5,X'00ff'),(2,NULL,NULL,NULL),(1,'B',86.5,X'00ff');");
    $allBefore=Capture::fingerprint($pdo,Capture::tables($pdo));
    $fingerprint=Capture::fingerprint($pdo,['pretes_cbt_items']);
    $json=json_encode($fingerprint,JSON_THROW_ON_ERROR);
    $sql="-- Historical backup fixture\n";
    file_put_contents($dir.'/integrity-before.json',$json);
    file_put_contents($dir.'/database.sql',$sql);file_put_contents($dir.'/dump-complete','mysqldump-exit-0');
    $state=['version'=>1,'database_key'=>'fixture-key','integrity_sha256'=>hash('sha256',$json)];
    $summary=['status'=>'backup_created_no_migration','academic_data_unchanged_during_capture'=>true,'backup_bytes'=>strlen($sql),'backup_sha256'=>hash('sha256',$sql)];
    file_put_contents($dir.'/capture-state.json',json_encode($state));
    file_put_contents($dir.'/summary.json',json_encode($summary));
    $baseline=RestoreVerification::baseline($dir,'fixture-key');
    $report=RestoreVerification::compare($pdo,$baseline['fingerprint']);
    restoreCheck($report['academic_data_matches_backup'] && $report['academic_tables']===1 && $report['restored_total_tables']===2,'Unchanged restore must match');
    restoreCheck(Capture::fingerprint($pdo,Capture::tables($pdo))===$allBefore,'Verifier may not modify any table');
    $pdo->exec("UPDATE pretes_cbt_items SET score=80 WHERE id=1");
    $changed=Capture::fingerprint($pdo,Capture::tables($pdo));
    $report=RestoreVerification::compare($pdo,$fingerprint);
    restoreCheck(!$report['academic_data_matches_backup'] && $report['different_tables']===['pretes_cbt_items'],'Changed grades must fail');
    restoreCheck(Capture::fingerprint($pdo,Capture::tables($pdo))===$changed,'Mismatch must not repair/regrade data');
    $pdo->exec("UPDATE pretes_cbt_items SET score=86.5 WHERE id=1; DELETE FROM pretes_cbt_items WHERE id=2; INSERT INTO pretes_cbt_items VALUES(2,NULL,NULL,NULL)");
    restoreCheck(RestoreVerification::compare($pdo,$fingerprint)['academic_data_matches_backup'],'Row order may differ');
    $pdo->exec("UPDATE pretes_cbt_items SET detail=X'00fe' WHERE id=1");
    restoreCheck(!RestoreVerification::compare($pdo,$fingerprint)['academic_data_matches_backup'],'Binary changes must fail');
    $pdo->exec("UPDATE pretes_cbt_items SET detail=X'00ff' WHERE id=1; DELETE FROM pretes_cbt_items WHERE rowid=(SELECT rowid FROM pretes_cbt_items WHERE id=1 LIMIT 1)");
    restoreCheck(!RestoreVerification::compare($pdo,$fingerprint)['academic_data_matches_backup'],'Lost duplicate rows must fail');
    $pdo->exec('CREATE TABLE tutorial_extra(id INT)');
    restoreCheck(in_array('tutorial_extra',RestoreVerification::compare($pdo,$fingerprint)['different_tables'],true),'Extra academic tables must fail');
    $pdo->exec('DROP TABLE pretes_cbt_items');
    restoreCheck(in_array('pretes_cbt_items',RestoreVerification::compare($pdo,$fingerprint)['different_tables'],true),'Missing academic tables must fail');
    restoreReject(fn()=>RestoreVerification::compare($pdo,[]),'Empty baseline accepted');
    $pdo->beginTransaction();restoreReject(fn()=>RestoreVerification::compare($pdo,$fingerprint),'Existing transaction accepted');$pdo->rollBack();
    restoreReject(fn()=>RestoreVerification::baseline($dir,'other-key'),'Wrong source database accepted');
    file_put_contents($dir.'/database.sql',$sql.'tampered');
    restoreReject(fn()=>RestoreVerification::baseline($dir,'fixture-key'),'Changed backup accepted');
    file_put_contents($dir.'/database.sql',$sql);file_put_contents($dir.'/integrity-before.json','{}');
    restoreReject(fn()=>RestoreVerification::baseline($dir,'fixture-key'),'Changed baseline accepted');
    file_put_contents($dir.'/integrity-before.json',$json);file_put_contents($dir.'/database.sql.part','partial');
    restoreReject(fn()=>RestoreVerification::baseline($dir,'fixture-key'),'Partial backup accepted');unlink($dir.'/database.sql.part');
    unlink($dir.'/dump-complete');restoreReject(fn()=>RestoreVerification::baseline($dir,'fixture-key'),'Missing completion marker accepted');
    echo "PASS: restored academic data, score/binary/duplicate/table mismatches, read-only preservation, capture identity/checksums and incomplete backups.\n";
} finally {
    foreach (['integrity-before.json','database.sql','dump-complete','capture-state.json','summary.json','database.sql.part'] as $name) if(is_file($dir.'/'.$name))unlink($dir.'/'.$name);
    rmdir($dir);
}
