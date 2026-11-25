<?php
declare(strict_types=1);
// Exercise the real CLI before any database connection, using dummy config only.
$dir=str_replace('\\','/',sys_get_temp_dir()).'/restore-target-'.bin2hex(random_bytes(5));
foreach ([$dir,$dir.'/tools',$dir.'/includes',$dir.'/includes/program-separation',$dir.'/config'] as $folder) mkdir($folder,0700);
try {
    copy(__DIR__.'/../tools/program-separation-verify-restore.php',$dir.'/tools/verify.php');
    file_put_contents($dir.'/includes/program-separation/restore-verification.php','<?php require_once '.var_export(realpath(__DIR__.'/../includes/program-separation/restore-verification.php'),true).';');
    file_put_contents($dir.'/config/database.php',"<?php const DB_NAME='fixture_production';const DB_USER='shared_user';const DB_HOST='localhost';const DB_CHARSET='utf8mb4';");
    foreach (['fixture_production'=>true,'FIXTURE_PRODUCTION'=>true,'fixture_test'=>false] as $database=>$blocked) {
        $process=proc_open([PHP_BINARY,'-d','disable_functions=',''.$dir.'/tools/verify.php','--capture-dir='.$dir.'/missing-private-directory','--database='.$database,'--username=shared_user'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start CLI fixture.');
        fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        $code=proc_close($process);
        $message=$blocked?'Test database must be separate from production.':'Private output directory must exist and be writable.';
        if ($code!==1 || $stdout!=='' || !str_contains($stderr,$message)) throw new RuntimeException('CLI target guard failed for '.$database.': '.$stderr);
    }
    echo "PASS: actual CLI rejects production database case-insensitively, permits shared username with a different database, and reaches private-capture guard without connecting.\n";
} finally {
    foreach (['tools/verify.php','includes/program-separation/restore-verification.php','config/database.php'] as $file) if(is_file($dir.'/'.$file))unlink($dir.'/'.$file);
    foreach (['includes/program-separation','includes','tools','config',''] as $folder) rmdir($dir.($folder!==''?'/'.$folder:''));
}
