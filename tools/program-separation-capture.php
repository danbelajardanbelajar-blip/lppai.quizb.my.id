<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/../includes/program-separation/capture.php';
use Lppai\ProgramSeparation\Capture;
use Lppai\ProgramSeparation\Audit;

if ($argc===2 && $argv[1]==='--help') {
    echo "Usage: php tools/program-separation-capture.php --output-dir=/private/existing-directory\n";
    echo "CLI on hosting only. Full mysqldump backup + read-only academic fingerprints + offline audit. No migration or data changes.\n";exit;
}
if ($argc!==2 || !str_starts_with($argv[1],'--output-dir=')) {fwrite(STDERR,"Use --help. No apply/migration option exists.\n");exit(2);}
$pdo=null;$optionsFile=null;$run=null;
try {
    $dir=Capture::privateDirectory(substr($argv[1],13),dirname(__DIR__));
    require_once __DIR__.'/../config/database.php'; // Constants only; do not call auth/getDBConnection/initializers.
    $pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $unsupported=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'")->fetchAll(PDO::FETCH_COLUMN);
    if ($unsupported) throw new RuntimeException('Nontransactional tables require a separately verified hosting backup.');
    $run=$dir.'/lppai-'.(new DateTimeImmutable('now',new DateTimeZone('Asia/Jakarta')))->format('Ymd-His').'-'.bin2hex(random_bytes(4));
    if (!mkdir($run,0700)) throw new RuntimeException('Cannot create private backup directory.');
    $write=static function(string $name,array $data)use($run):void {
        $content=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($run.'/'.$name,$content,LOCK_EX)!==strlen($content)) throw new RuntimeException('Backup output is incomplete.');
        chmod($run.'/'.$name,0600);
    };
    // Consistent read, then release it before mysqldump uses its own read-only snapshot.
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    $academic=Capture::academicTables(Capture::tables($pdo));
    if (!$academic) throw new RuntimeException('No academic tables found; check deployment database.');
    $before=Capture::fingerprint($pdo,$academic);$capture=Capture::minimumSnapshot($pdo);$pdo->commit();
    $write('integrity-before.json',$before);$write('snapshot.json',$capture['snapshot']);
    $write('schema-gaps.json',$capture['missing_schema']);
    $optionsFile=tempnam($run,'.mysql-client-');
    if ($optionsFile===false || !chmod($optionsFile,0600)) throw new RuntimeException('Cannot create private client options.');
    file_put_contents($optionsFile,Capture::clientOptions(['host'=>(string)DB_HOST,'user'=>(string)DB_USER,'password'=>(string)DB_PASS]));
    $sql=$run.'/database.sql';
    $process=proc_open(['mysqldump','--defaults-extra-file='.$optionsFile,'--single-transaction','--skip-lock-tables','--quick','--hex-blob','--routines','--events','--triggers','--default-character-set='.(string)DB_CHARSET,(string)DB_NAME],
        [0=>['pipe','r'],1=>['file',$sql,'x'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('mysqldump is unavailable.');
    fclose($pipes[0]);$dumpError=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($process);
    unlink($optionsFile);$optionsFile=null;chmod($sql,0600);
    if ($code!==0 || !is_file($sql) || filesize($sql)<100) throw new RuntimeException('mysqldump failed; do not treat this folder as a verified backup.');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    $after=Capture::fingerprint($pdo,Capture::academicTables(Capture::tables($pdo)));$pdo->commit();
    $write('integrity-after.json',$after);
    if ($before!==$after) throw new RuntimeException('Academic data changed during capture; inspect activity and repeat before any migration.');
    $report=Audit::inspect($capture['snapshot']);$report['missing_schema']=$capture['missing_schema'];
    $report['snapshot_sha256']=hash_file('sha256',$run.'/snapshot.json');$write('audit.json',$report);
    $summary=['status'=>'backup_created_no_migration','academic_data_unchanged_during_capture'=>true,
        'academic_tables'=>count($before),'audit_findings'=>count($report['issues']),'schema_gap_tables'=>count($capture['missing_schema']),
        'backup_sha256'=>hash_file('sha256',$sql),'backup_bytes'=>filesize($sql),
        'restore_tested'=>false,'cutover_allowed'=>false,'private_directory'=>$run];
    $write('summary.json',$summary);echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    if (is_string($optionsFile) && is_file($optionsFile)) unlink($optionsFile);
    // Never echo PDO errors, DSNs, client stderr, tokens, credentials, records or SQL contents.
    $safe=$error instanceof PDOException ? 'Database capture failed; no migration performed.' : $error->getMessage();
    fwrite(STDERR,"CAPTURE INCOMPLETE: ".$safe."\nNo migration performed. Keep any partial files private.\n");exit(1);
}
