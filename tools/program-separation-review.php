<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/program-separation/capture.php';
use Lppai\ProgramSeparation\Audit;
use Lppai\ProgramSeparation\Capture;
if($argc<2 || $argc>3 || !str_starts_with($argv[1],'--capture-dir=') || ($argc===3 && $argv[2]!=='--live-sessions')){
    fwrite(STDERR,"Usage: php tools/program-separation-review.php --capture-dir=PRIVATE_CAPTURE [--live-sessions]\nRead-only review; no migration, cleanup or backup overwrite.\n");exit(2);
}
$pdo=null;
try{
    $dir=Capture::privateDirectory(substr($argv[1],14),dirname(__DIR__));
    $read=static function(string $file)use($dir):array{
        $path=$dir.'/'.$file;
        if(!is_file($path)||is_link($path)||filesize($path)>33554432)throw new RuntimeException('Capture file missing/invalid.');
        $value=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($value))throw new RuntimeException('Invalid capture.');return $value;
    };
    $summary=$read('summary.json');$state=$read('capture-state.json');$snapshot=$read('snapshot.json');
    if(!is_file($dir.'/database.sql') || is_link($dir.'/database.sql')
        || !hash_equals((string)($summary['backup_sha256']??''),hash_file('sha256',$dir.'/database.sql'))
        || !hash_equals((string)($state['snapshot_sha256']??''),hash_file('sha256',$dir.'/snapshot.json')))throw new RuntimeException('Backup/snapshot checksum mismatch; preserve originals and investigate.');
    $metadata=[];$live=false;
    if($argc===3){
        require_once __DIR__.'/../config/database.php';
        $key=hash('sha256',serialize([(string)DB_HOST,(string)DB_NAME]));
        if(!hash_equals((string)($state['database_key']??''),$key))throw new RuntimeException('Capture database differs from deployment config.');
        $pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
        $metadata=$pdo->query('SELECT id,mode,is_active,exam_open FROM pretes_cbt_sessions ORDER BY id')->fetchAll();$pdo->commit();$live=true;
        // Live metadata is displayed separately. Never replace historical status or invent old modes.
    }
    $report=Audit::inspect($snapshot);$grouped=[];$kinds=[];
    foreach($report['issues'] as $issue)$grouped[$issue['code'].' / '.$issue['table']][]=$issue['id'];
    $findings=[];foreach($grouped as $name=>$ids)$findings[$name]=['jumlah'=>count($ids),'id'=>implode(',',$ids)];
    foreach($report['candidate_mapping']['pretes_cbt_attempts']??[] as $id=>$row)$kinds[$row['registration_kind']][]= $id;
    $types=[];foreach($kinds as $kind=>$ids)$types[$kind]=['jumlah'=>count($ids),'id'=>implode(',',$ids)];
    $result=['mode'=>'read_only_review','backup_checksum_matches'=>true,'snapshot_checksum_matches'=>true,
        'database_modified'=>false,'snapshot_modified'=>false,'cutover_allowed'=>false,
        'attempt_types'=>$types,'findings'=>$findings,'live_session_metadata_read'=>$live,'live_sessions'=>$metadata,
        'note'=>'Live session metadata is current, while findings use the saved historical snapshot; modes absent in that snapshot remain unverified.'];
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $error){
    if($pdo && $pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,$error instanceof PDOException?"Review query failed; no database changes performed.\n":"Review incomplete: ".$error->getMessage()."\n");exit(1);
}
