<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/program-separation/additive-rehearsal.php';
use Lppai\ProgramSeparation\Capture;
use Lppai\ProgramSeparation\RestoreVerification;
use Lppai\ProgramSeparation\AdditiveRehearsal;
try{
    $args=[];
    foreach(array_slice($argv,1) as $arg){
        if(!preg_match('/^--(capture-dir|database|username)=(.+)$/D',$arg,$m)||isset($args[$m[1]]))throw new RuntimeException('Expected --capture-dir, --database and --username.');
        $args[$m[1]]=$m[2];
    }
    if(count($args)!==3)throw new RuntimeException('Three arguments required; inspection is read-only.');
    require_once __DIR__.'/../config/database.php';
    if($args['database']!=='quic1934_uji'||strcasecmp($args['database'],(string)DB_NAME)===0)throw new RuntimeException('Only the approved test database quic1934_uji is allowed; production is forbidden.');
    if(!preg_match('/^[a-zA-Z0-9_]+$/D',$args['username']))throw new RuntimeException('Invalid username.');
    $run=Capture::privateDirectory($args['capture-dir'],dirname(__DIR__));
    $baseline=RestoreVerification::baseline($run,hash('sha256',serialize([(string)DB_HOST,(string)DB_NAME])));
    $files=glob($run.'/schema-rehearsal-*-before.json');
    if(!$files)throw new RuntimeException('No private rehearsal preimage found.');
    // Inspect every preimage rather than silently choosing one when multiple attempts exist.
    $journals=[];
    foreach($files as $file){
        if(is_link($file)||filesize($file)>33554432)throw new RuntimeException('Invalid preimage file.');
        $data=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
        if(($data['test_database']??null)!==$args['database']||($data['backup_sha256']??null)!==$baseline['backup_sha256']||!is_array($data['plan']??null))throw new RuntimeException('Preimage belongs to another test database/backup.');
        $journals[$file]=$data['plan'];
    }
    if(function_exists('stream_isatty')&&stream_isatty(STDIN))throw new RuntimeException('Use a concealed password prompt piped through stdin.');
    $password=stream_get_contents(STDIN,65537);
    if($password===false||$password===''||strlen($password)>65536)throw new RuntimeException('Password required on stdin.');
    $pdo=new PDO('mysql:host='.DB_HOST.';dbname='.$args['database'].';charset='.DB_CHARSET,$args['username'],$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);unset($password);
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$args['database'])throw new RuntimeException('Unexpected connection database.');
    $results=[];foreach($journals as $file=>$plan)$results[]=['private_preimage'=>$file]+AdditiveRehearsal::inspect($pdo,$plan);
    echo json_encode(['status'=>'read_only_rehearsal_inspection','test_database'=>$args['database'],'production_database_connected'=>false,'inspections'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $error){
    $safe=$error instanceof PDOException?'Could not inspect test database.':$error->getMessage();
    fwrite(STDERR,'INSPECTION INCOMPLETE: '.$safe."\nNo ALTER/UPDATE or production connection performed.\n");exit(1);
}
