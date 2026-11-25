<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/program-separation/checkpoint.php';
use Lppai\ProgramSeparation\Checkpoint;
use Lppai\ProgramSeparation\Capture;
function shellCaptureCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function shellCaptureReject(callable $action):void {
    try {$action();}catch(RuntimeException $expected){return;}
    throw new LogicException('Capture yang tidak lengkap harus ditolak.');
}
$bash=PHP_OS_FAMILY==='Windows'?'C:/Program Files/Git/bin/bash.exe':'/bin/sh';
shellCaptureCheck(is_file($bash),'Shell harus tersedia untuk pengujian integrasi.');
$root=str_replace('\\','/',sys_get_temp_dir())."/capture shell's-".bin2hex(random_bytes(5));mkdir($root,0700);mkdir($root.'/bin',0700);
$runProcess=static function(array $command):array {
    $p=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];
};
$binShell=PHP_OS_FAMILY==='Windows' ? preg_replace_callback('~^([A-Za-z]):/~',static fn($m)=>'/'.strtolower($m[1]).'/', $root.'/bin') : $root.'/bin';
$shell=static function(string $script,bool $fail=false)use($runProcess,$bash,$binShell):array {
    return $runProcess([$bash,'-c','PATH="$1:$PATH"; export PATH; DUMP_FAIL="$3"; export DUMP_FAIL; sh "$2"','capture-test',$binShell,$script,$fail?'1':'0']);
};
$fixture=static function():PDO {
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE users(id INT,jenis_program TEXT,role TEXT);INSERT INTO users VALUES(1,'RPL','mahasiswa');
        CREATE TABLE pretes_assessments(id INT,score TEXT);INSERT INTO pretes_assessments VALUES(1,'87.5')");return $pdo;
};
$prepare=static function(PDO $pdo)use($root):string {
    return Checkpoint::prepare($pdo,$root,'fixture-db',Capture::clientOptions(['host'=>'localhost','user'=>'fixture','password'=>'private-fixture-password']),"db'quoted;do-not-run",'utf8mb4');
};
file_put_contents($root.'/bin/mysqldump', <<<'SH'
#!/bin/sh
set -eu
case "$1" in --defaults-extra-file=*) client=${1#--defaults-extra-file=} ;; *) exit 9 ;; esac
test -f "$client" || exit 8
if [ "${DUMP_FAIL:-0}" = 1 ]; then printf 'synthetic failure\n' >&2; printf 'incomplete\n'; exit 7; fi
printf '%s\n' '-- SYNTHETIC TEST DUMP; NOT A REAL MYSQL BACKUP'
printf '%s\n' 'CREATE TABLE fixture(id INTEGER);'
printf '%s\n' 'INSERT INTO fixture VALUES(1);'
printf '%s\n' '-- End of synthetic dump used only to exercise shell orchestration.'
SH
);
chmod($root.'/bin/mysqldump',0700);
try {
    $pdo=$fixture();$run=$prepare($pdo);$before=Capture::fingerprint($pdo,['pretes_assessments']);
    [$code,$out,$err]=$shell($run.'/dump.sh');
    shellCaptureCheck($code===0 && $out==='' && $err==='','Shell sukses tanpa mencetak kredensial/data: '.json_encode([$code,$out,$err]));
    shellCaptureCheck(!file_exists($run.'/mysql-client.cnf'),'Credential file dihapus setelah dump sukses');
    $summary=Checkpoint::finish($pdo,$run,'fixture-db');
    shellCaptureCheck($summary['status']==='backup_created_no_migration' && $summary['cutover_allowed']===false && $summary['restore_tested']===false,'Capture tetap tanpa migrasi/restore claim');
    shellCaptureCheck(Capture::fingerprint($pdo,['pretes_assessments'])===$before,'Nilai tidak berubah');
    $hash=hash_file('sha256',$run.'/database.sql');
    shellCaptureCheck($shell($run.'/dump.sh')[0]!==0 && hash_file('sha256',$run.'/database.sql')===$hash,'Dump tidak menimpa backup lama');
    shellCaptureReject(fn()=>Checkpoint::finish($pdo,$run,'different-db'));
    $pdo->exec("UPDATE pretes_assessments SET score='88'");
    shellCaptureReject(fn()=>Checkpoint::finish($pdo,$run,'fixture-db'));
    $pdo=$fixture();$failed=$prepare($pdo);
    shellCaptureCheck($shell($failed.'/dump.sh',true)[0]!==0,'Exit dump gagal diteruskan');
    shellCaptureCheck(!file_exists($failed.'/mysql-client.cnf') && !file_exists($failed.'/dump-complete'),'Trap membersihkan kredensial dan tidak menandai sukses');
    shellCaptureReject(fn()=>Checkpoint::finish($pdo,$failed,'fixture-db'));
    $notDumped=$prepare($pdo);shellCaptureReject(fn()=>Checkpoint::finish($pdo,$notDumped,'fixture-db'));
    $tampered=$prepare($pdo);shellCaptureCheck($shell($tampered.'/dump.sh')[0]===0,'Dump fixture untuk uji integritas');
    file_put_contents($tampered.'/snapshot.json','{}');
    shellCaptureReject(fn()=>Checkpoint::finish($pdo,$tampered,'fixture-db'));

    // Original PHP entry point fails early with actionable instructions if subprocess APIs are disabled.
    [$code,$out,$err]=$runProcess([PHP_BINARY,'-d','disable_functions=proc_open,proc_close',__DIR__.'/../tools/program-separation-capture.php','--output-dir='.$root]);
    shellCaptureCheck($code===2 && str_contains($err,'capture.sh') && !str_contains($out.$err,'undefined function'),'PHP terbatas diarahkan ke shell sebelum menyentuh DB');

    // Actual shell wrapper + PHP fixture entry point with proc_open disabled in both PHP phases.
    mkdir($root.'/wrapper',0700);mkdir($root.'/wrapper/tools',0700);
    copy(__DIR__.'/../tools/program-separation-capture.sh',$root.'/wrapper/tools/program-separation-capture.sh');
    $lib=var_export(realpath(__DIR__.'/../includes/program-separation/checkpoint.php'),true);
    $worker='<?php require_once '.$lib.'; if(function_exists("proc_open"))exit(77); $pdo=new PDO("sqlite:".__DIR__."/fixture.sqlite"); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $arg=$argv[1]; if(str_starts_with($arg,"--prepare=")){echo \\Lppai\\ProgramSeparation\\Checkpoint::prepare($pdo,substr($arg,10),"fixture-db","[client]\\npassword=private-fixture-password\\n","fixture","utf8mb4")."\\n";}else{echo json_encode(\\Lppai\\ProgramSeparation\\Checkpoint::finish($pdo,substr($arg,9),"fixture-db"));}';
    file_put_contents($root.'/wrapper/tools/program-separation-capture.php',$worker);
    $disk=new PDO('sqlite:'.$root.'/wrapper/tools/fixture.sqlite');$disk->exec("CREATE TABLE pretes_assessments(id INT,score TEXT);INSERT INTO pretes_assessments VALUES(1,'87.5')");$disk=null;
    file_put_contents($root.'/bin/php','#!/bin/sh'."\nexec ".Checkpoint::quote(str_replace('\\','/',PHP_BINARY)).' -d disable_functions=proc_open,proc_close "$@"'."\n");chmod($root.'/bin/php',0700);
    [$code,$out,$err]=$runProcess([$bash,'-c','PATH="$1:$PATH"; export PATH; sh "$2" "--output-dir=$3"','wrapper-test',$binShell,$root.'/wrapper/tools/program-separation-capture.sh',$root]);
    shellCaptureCheck($code===0 && $err==='' && (json_decode($out,true)['status']??'')==='backup_created_no_migration','Wrapper end-to-end dengan proc_open PHP dinonaktifkan: '.$err);
    shellCaptureCheck(!str_contains($out.$err,'private-fixture-password'),'Kredensial tidak masuk stdout/stderr');
    echo "PASS: shell capture end-to-end tanpa proc_open PHP, quoting, trap credential, dump gagal/ulang, deteksi perubahan data/snapshot, tanpa migrasi.\n";
} finally {
    $pdo=null;
    $resolvedRoot=realpath($root);$resolvedTemp=realpath(sys_get_temp_dir());
    shellCaptureCheck($resolvedRoot!==false && $resolvedTemp!==false && strcasecmp(dirname($resolvedRoot),$resolvedTemp)===0 && str_starts_with(basename($resolvedRoot),"capture shell's-"),'Cleanup hanya boleh pada direktori fixture temp yang diverifikasi');
    $remove=static function(string $dir)use(&$remove):void {
        foreach(scandir($dir) as $name)if($name!=='.'&&$name!=='..'){
            $path=$dir.'/'.$name;if(is_dir($path)&&!is_link($path))$remove($path);else unlink($path);
        }rmdir($dir);
    };$remove($root);
}
