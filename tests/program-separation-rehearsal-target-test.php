<?php
declare(strict_types=1);
$dir=str_replace('\\','/',sys_get_temp_dir()).'/rehearsal-target-'.bin2hex(random_bytes(5));
foreach([$dir,$dir.'/tools',$dir.'/includes',$dir.'/includes/program-separation',$dir.'/config'] as $folder)mkdir($folder,0700);
try {
    copy(__DIR__.'/../tools/program-separation-rehearse-schema.php',$dir.'/tools/rehearse.php');
    copy(__DIR__.'/../tools/program-separation-inspect-rehearsal.php',$dir.'/tools/inspect.php');
    file_put_contents($dir.'/includes/program-separation/additive-rehearsal.php','<?php require_once '.var_export(realpath(__DIR__.'/../includes/program-separation/additive-rehearsal.php'),true).';');
    foreach([
        ['fixture_production','fixture_production','Only the approved test database'],
        ['fixture_production','other_test','Only the approved test database'],
        ['quic1934_uji','quic1934_uji','Only the approved test database'],
        ['QUIC1934_UJI','quic1934_uji','Only the approved test database'],
        ['fixture_production','quic1934_uji','Private output directory must exist'],
    ] as [$production,$target,$expected]) {
        file_put_contents($dir.'/config/database.php','<?php const DB_NAME='.var_export($production,true).';');
        foreach(['rehearse.php','inspect.php'] as $script){
        $command=[PHP_BINARY,$dir.'/tools/'.$script,'--capture-dir='.$dir.'/not-present','--database='.$target,'--username=shared_user'];
        if($script==='rehearse.php')$command[]='--restore-report='.$dir.'/not-present/proof.json';
        $proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('CLI fixture failed to start.');
        fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
        $safety=$script==='rehearse.php'?'No ALTER started.':'No ALTER/UPDATE or production connection performed.';
        if($code!==1||$stdout!==''||!str_contains($stderr,$expected)||!str_contains($stderr,$safety))throw new RuntimeException('Rehearsal/inspector target guard failed: '.$stderr);
        }
    }
    echo "PASS: rehearsal CLI forbids production (case-insensitive) and every unapproved database; approved test target reaches capture guard without connection/ALTER.\n";
}finally{
    foreach(['tools/rehearse.php','tools/inspect.php','includes/program-separation/additive-rehearsal.php','config/database.php'] as $file)if(is_file($dir.'/'.$file))unlink($dir.'/'.$file);
    foreach(['includes/program-separation','includes','tools','config',''] as $folder)rmdir($dir.($folder!==''?'/'.$folder:''));
}
