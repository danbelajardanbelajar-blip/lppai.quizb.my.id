<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/program-separation/additive-rehearsal.php';
use Lppai\ProgramSeparation\Capture;
use Lppai\ProgramSeparation\RestoreVerification;
use Lppai\ProgramSeparation\AdditiveRehearsal;

$ddlStarted=false;$metadataStarted=false;$resume=false;
try {
    $args=[];
    foreach(array_slice($argv,1) as $arg){
        if(!preg_match('/^--(capture-dir|restore-report|database|username|resume-from)=(.+)$/D',$arg,$m)||isset($args[$m[1]]))throw new RuntimeException('Expected capture/report/database/username arguments, optionally --resume-from=PRIVATE_PREIMAGE.');
        $args[$m[1]]=$m[2];
    }
    if(array_diff(['capture-dir','restore-report','database','username'],array_keys($args)))throw new RuntimeException('Four arguments required; this tool alters only the approved test copy.');
    $resume=isset($args['resume-from']);
    require_once __DIR__.'/../config/database.php';
    // Hard stop: this version has no production-apply mode or override.
    if($args['database']!=='quic1934_uji'||strcasecmp($args['database'],(string)DB_NAME)===0)throw new RuntimeException('Only the approved test database quic1934_uji is allowed; production is forbidden.');
    if(!preg_match('/^[a-zA-Z0-9_]+$/D',$args['username']))throw new RuntimeException('Invalid test username.');
    $run=Capture::privateDirectory($args['capture-dir'],dirname(__DIR__));
    $baseline=RestoreVerification::baseline($run,hash('sha256',serialize([(string)DB_HOST,(string)DB_NAME])));
    $reportPath=realpath($args['restore-report']);
    if(!$reportPath||is_link($args['restore-report'])||dirname($reportPath)!==$run||filesize($reportPath)>1048576)throw new RuntimeException('Use the successful private restore report inside this capture directory.');
    $proof=json_decode(file_get_contents($reportPath),true,64,JSON_THROW_ON_ERROR);
    if(($proof['status']??null)!=='restore_academic_verification_passed'||($proof['test_database']??null)!==$args['database']
        ||($proof['backup_sha256']??null)!==$baseline['backup_sha256']||($proof['academic_data_matches_backup']??null)!==true||($proof['restore_tested']??null)!==true)throw new RuntimeException('Successful restore evidence required.');
    if(function_exists('stream_isatty')&&stream_isatty(STDIN))throw new RuntimeException('Use a concealed shell prompt piped through stdin.');
    $password=stream_get_contents(STDIN,65537);
    if($password===false||$password===''||strlen($password)>65536)throw new RuntimeException('Test password required on stdin.');
    $pdo=new PDO('mysql:host='.DB_HOST.';dbname='.$args['database'].';charset='.DB_CHARSET,$args['username'],$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);unset($password);
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$args['database'])throw new RuntimeException('Unexpected database connection.');
    if($resume){
        $preimage=realpath($args['resume-from']);
        if(!$preimage||is_link($args['resume-from'])||dirname($preimage)!==$run||!preg_match('/^schema-rehearsal-.+-before\.json$/D',basename($preimage))||filesize($preimage)>33554432)throw new RuntimeException('Use the original private preimage inside the capture directory.');
        $journal=json_decode(file_get_contents($preimage),true,64,JSON_THROW_ON_ERROR);
        if(($journal['test_database']??null)!==$args['database']||($journal['backup_sha256']??null)!==$baseline['backup_sha256']||!is_array($journal['plan']??null))throw new RuntimeException('Preimage database/backup does not match.');
        $plan=$journal['plan'];
        if(!is_array($plan['original_fingerprint']??null)||array_intersect_key($plan['original_fingerprint'],$baseline['fingerprint'])!==$baseline['fingerprint'])throw new RuntimeException('Preimage academic data does not match the verified backup baseline.');
        $inspection=AdditiveRehearsal::inspect($pdo,$plan);
        if(!$inspection['all_original_column_values_unchanged'])throw new RuntimeException('Original data differs from preimage; resume refused.');
    }else{
        if(!RestoreVerification::compare($pdo,$baseline['fingerprint'])['academic_data_matches_backup'])throw new RuntimeException('Test copy no longer matches the backup; no ALTER started.');
        $plan=AdditiveRehearsal::prepare($pdo);
    }
    $write=static function(string $path,array $data):void{
        $handle=fopen($path,'x');if(!$handle||!chmod($path,0600))throw new RuntimeException('Cannot create private rehearsal journal.');
        $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if(fwrite($handle,$json)!==strlen($json))throw new RuntimeException('Private journal incomplete.');fclose($handle);
    };
    $prefix=$run.'/schema-rehearsal-'.(new DateTimeImmutable('now',new DateTimeZone('Asia/Jakarta')))->format('Ymd-His').'-'.bin2hex(random_bytes(4));
    $write($prefix.'-before.json',['test_database'=>$args['database'],'backup_sha256'=>$baseline['backup_sha256'],'plan'=>$plan]);
    $metadataStarted=true;
    if($resume){$result=AdditiveRehearsal::resume($pdo,$plan);}
    else{$ddlStarted=true;$result=AdditiveRehearsal::apply($pdo,$plan);}
    $out=['status'=>'test_schema_rehearsal_passed','test_database'=>$args['database'],'backup_sha256'=>$baseline['backup_sha256'],
        'production_database_connected'=>false,'test_schema_modified'=>!$resume,'test_metadata_modified'=>true,
        'mode'=>$resume?'resume_metadata_only':'fresh_schema_rehearsal','resumed_from'=>$resume?$preimage:null]+$result;
    $write($prefix.'-after.json',$out);$out['private_report']=$prefix.'-after.json';
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $error){
    $safe=$error instanceof PDOException?'Test database/schema operation failed; keep private journals for review.':$error->getMessage();
    fwrite(STDERR,'REHEARSAL INCOMPLETE: '.$safe."\nProduction was not connected. ".($ddlStarted?'Test ALTER may have committed; do not rerun/import/drop columns automatically.':'No ALTER started.').($metadataStarted?' Metadata may already be committed if final report writing failed; inspect before retry.':'')."\n");exit(1);
}
