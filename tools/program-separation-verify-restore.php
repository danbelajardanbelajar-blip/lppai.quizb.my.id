<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/../includes/program-separation/restore-verification.php';
use Lppai\ProgramSeparation\Capture;
use Lppai\ProgramSeparation\RestoreVerification;
use Lppai\ProgramSeparation\TestCredentials;

if ($argc===2 && $argv[1]==='--help') {
    echo "Usage: password on stdin | php tools/program-separation-verify-restore.php --capture-dir=PRIVATE_DIR --database=TEST_DB --username=TEST_USER\n";
    echo "Optional --use-config-test-credentials reuses configured password only for quic1934_uji with the same username; no stdin needed.\n";
    echo "Read-only comparison of restored academic tables against the historical capture. No migration or production connection.\n";exit;
}
try {
    $args=[];$configCredentials=false;
    foreach (array_slice($argv,1) as $arg) {
        if($arg==='--use-config-test-credentials'){if($configCredentials)throw new RuntimeException('Duplicate credential mode flag.');$configCredentials=true;continue;}
        if (!preg_match('/^--(capture-dir|database|username)=(.+)$/D',$arg,$match) || isset($args[$match[1]])) throw new RuntimeException('Invalid or duplicate arguments. Use --help.');
        $args[$match[1]]=$match[2];
    }
    if (count($args)!==3) throw new RuntimeException('Three arguments required. Use --help.');
    foreach (['database','username'] as $name) if (!preg_match('/^[a-zA-Z0-9_]+$/D',$args[$name])) throw new RuntimeException('Invalid test database or username.');
    require_once __DIR__.'/../config/database.php';
    // A shared-hosting account may grant one user access to two databases.
    // This SELECT-only verifier isolates the connection by database, not username.
    if (strcasecmp($args['database'],(string)DB_NAME)===0) throw new RuntimeException('Test database must be separate from production.');
    $run=Capture::privateDirectory($args['capture-dir'],dirname(__DIR__));
    $baseline=RestoreVerification::baseline($run,hash('sha256',serialize([(string)DB_HOST,(string)DB_NAME])));
    $password=TestCredentials::password($args['database'],$args['username'],$configCredentials);
    $pdo=new PDO('mysql:host='.DB_HOST.';dbname='.$args['database'].';charset='.DB_CHARSET,$args['username'],$password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    unset($password);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$args['database']) throw new RuntimeException('Unexpected connection database.');
    $result=RestoreVerification::compare($pdo,$baseline['fingerprint']);
    $report=['status'=>$result['academic_data_matches_backup']?'restore_academic_verification_passed':'restore_academic_verification_failed',
        'test_database'=>$args['database'],'backup_sha256'=>$baseline['backup_sha256'],'credential_source'=>$configCredentials?'hosting_config':'stdin']+$result+
        ['restore_tested'=>$result['academic_data_matches_backup'],'production_database_connected'=>false,'database_modified_by_verifier'=>false,
        'cutover_allowed'=>false,'scope'=>'Academic row counts and all column values; schema objects and nonacademic data not compared.'];
    $name=$run.'/restore-verification-'.(new DateTimeImmutable('now',new DateTimeZone('Asia/Jakarta')))->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
    $handle=fopen($name,'x');
    if (!$handle || !chmod($name,0600)) throw new RuntimeException('Cannot create private verification report.');
    $json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    if (fwrite($handle,$json)!==strlen($json)) throw new RuntimeException('Private verification report incomplete.');
    fclose($handle);$report['private_report']=$name;
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit($result['academic_data_matches_backup']?0:1);
} catch (Throwable $error) {
    $safe=$error instanceof PDOException?'Cannot read test database; no migration performed.':$error->getMessage();
    fwrite(STDERR,'RESTORE VERIFICATION INCOMPLETE: '.$safe."\nNo migration or production connection performed.\n");exit(1);
}
