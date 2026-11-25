<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/program-separation/gates.php';
use Lppai\ProgramSeparation\Capture;
use Lppai\ProgramSeparation\Gates;
if($argc!==3||!str_starts_with($argv[1],'--capture-dir=')||!str_starts_with($argv[2],'--close-pretes=')){
    fwrite(STDERR,"Usage: php tools/program-separation-close-gates.php --capture-dir=PRIVATE_CAPTURE --close-pretes=ID,ID\nOnly closes selected gates; never submits/deletes attempts or migrates data.\n");exit(2);
}
$pdo=null;$committed=false;
try{
    $ids=Gates::ids(substr($argv[2],15));
    $dir=Capture::privateDirectory(substr($argv[1],14),dirname(__DIR__));
    $read=static function(string $name)use($dir):array{
        $path=$dir.'/'.$name;
        if(!is_file($path)||is_link($path)||filesize($path)>33554432)throw new RuntimeException('Capture file missing/invalid.');
        $data=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('Capture file invalid.');return $data;
    };
    $summary=$read('summary.json');$state=$read('capture-state.json');
    if(!is_file($dir.'/database.sql')||is_link($dir.'/database.sql')||!hash_equals((string)($summary['backup_sha256']??''),hash_file('sha256',$dir.'/database.sql'))
        ||!is_file($dir.'/snapshot.json')||is_link($dir.'/snapshot.json')||!hash_equals((string)($state['snapshot_sha256']??''),hash_file('sha256',$dir.'/snapshot.json')))throw new RuntimeException('Backup/snapshot checksum mismatch.');
    require_once __DIR__.'/../config/database.php';
    if(!hash_equals((string)($state['database_key']??''),hash('sha256',serialize([(string)DB_HOST,(string)DB_NAME]))))throw new RuntimeException('Capture belongs to another database.');
    $pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'")->fetchColumn()!==false)throw new RuntimeException('Nontransactional tables need separate review before writes.');
    $log=$dir.'/gate-close-'.(new DateTimeImmutable('now',new DateTimeZone('Asia/Jakarta')))->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
    $write=static function(array $value)use($log):void{
        $text=json_encode($value,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
        if(file_put_contents($log,$text,LOCK_EX)!==strlen($text)||!chmod($log,0600))throw new RuntimeException('Private gate audit could not be saved.');
    };
    $beforeImage=[];
    $saveBefore=static function(array $value)use(&$beforeImage,$write):void{$beforeImage=$value;$write($value);};
    $result=Gates::close($pdo,$ids,$saveBefore);$committed=true;
    $result['private_gate_audit']=$log;$write(['before'=>$beforeImage,'result'=>$result]);
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $error){
    if($pdo&&$pdo->inTransaction())$pdo->rollBack();
    if($committed)fwrite(STDERR,"Gate closure committed, but final audit output failed. Recheck session flags; do not assume rollback. No migration performed.\n");
    else fwrite(STDERR,($error instanceof PDOException?'Gate transaction failed.':$error->getMessage())."\nNo gate changes committed by this command; no migration performed.\n");
    exit(1);
}
