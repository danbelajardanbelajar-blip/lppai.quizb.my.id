<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/../includes/program-separation/checkpoint.php';
use Lppai\ProgramSeparation\Capture;
use Lppai\ProgramSeparation\Checkpoint;

if ($argc===2 && $argv[1]==='--help') {
    echo "Usage: sh tools/program-separation-capture.sh --output-dir=/private/existing-directory\n";
    echo "Shell runs mysqldump; PHP only prepares/verifies read-only capture. No proc_open needed in shell mode.\n";
    echo "PHP modes: --prepare=directory, --finish=prepared-directory, --output-dir=directory (requires proc_open).\n";exit;
}
if ($argc!==2 || !preg_match('/^--(output-dir|prepare|finish)=(.+)$/D',$argv[1],$args)) {fwrite(STDERR,"Use --help. No apply/migration option exists.\n");exit(2);}
[$unused,$mode,$path]=$args;
if ($mode==='output-dir' && (!function_exists('proc_open') || !function_exists('proc_close'))) {
    fwrite(STDERR,"PHP process functions are disabled. Run sh tools/program-separation-capture.sh --output-dir=PRIVATE_DIRECTORY instead.\nNo migration performed.\n");exit(2);
}
$pdo=null;$run=null;
try {
    $dir=Capture::privateDirectory($path,dirname(__DIR__));
    require_once __DIR__.'/../config/database.php';
    $pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $key=hash('sha256',serialize([(string)DB_HOST,(string)DB_NAME]));
    if ($mode==='finish') $summary=Checkpoint::finish($pdo,$dir,$key);
    else {
        $run=Checkpoint::prepare($pdo,$dir,$key,Capture::clientOptions(['host'=>(string)DB_HOST,'user'=>(string)DB_USER,'password'=>(string)DB_PASS]),(string)DB_NAME,(string)DB_CHARSET);
        if ($mode==='prepare') {echo $run."\n";exit;}
        $process=proc_open(['sh',$run.'/dump.sh'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot run private dump script.');
        fclose($pipes[0]);stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if (proc_close($process)!==0) throw new RuntimeException('Shell backup failed. Keep partial files private and prepare again.');
        $summary=Checkpoint::finish($pdo,$run,$key);
    }
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    if ($run && is_file($run.'/mysql-client.cnf')) unlink($run.'/mysql-client.cnf');
    $safe=$error instanceof PDOException ? 'Database capture failed; no migration performed.' : $error->getMessage();
    fwrite(STDERR,"CAPTURE INCOMPLETE: ".$safe."\nNo migration performed. Keep any partial files private.\n");exit(1);
}
