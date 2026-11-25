<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/program-separation/capture.php';
use Lppai\ProgramSeparation\Audit;
function kindCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$s=['schema_version'=>1,'tables'=>array_fill_keys(array_keys(Audit::FIELDS),[])];
$s['tables']['users']=[['id'=>1,'jenis_program'=>'RPL'],['id'=>2,'jenis_program'=>'Reguler']];
$s['tables']['master_pretes']=[['id'=>1,'periode'=>'Campuran lama','target_program'=>'Semua','status'=>'selesai']];
$s['tables']['pretes_registrations']=[['id'=>11,'user_id'=>1,'periode'=>'Campuran lama']];
$s['tables']['pretes_cbt_packages']=[['id'=>1],['id'=>2]];
$s['tables']['pretes_cbt_sessions']=[
    ['id'=>3,'is_active'=>1,'package_ids_json'=>'[1]','mode'=>'tryout','exam_open'=>0],
    ['id'=>4,'is_active'=>0,'package_ids_json'=>'[1]','mode'=>'tryout','exam_open'=>0],
    ['id'=>5,'is_active'=>0,'package_ids_json'=>'[2]','mode'=>'resmi','exam_open'=>0],
];
$add=static function(int $id,mixed $reg,int $session,int $user=1,?int $submitted=100)use(&$s):void{
    $s['tables']['pretes_cbt_attempts'][]=['id'=>$id,'user_id'=>$user,'registration_id'=>$reg,'session_id'=>$session,'submitted_at'=>$submitted];
};
$add(100,11,5);$add(101,-3001,3);$add(102,'-3999',3,2,null);
$add(103,-3000,3);$add(104,-3001,4);$add(105,-5001,5);
$add(106,999999,5);$add(107,666,5);$add(108,11,5,2);$add(109,(string)PHP_INT_MIN,5);$add(110,11,3);
$before=$s;$r=Audit::inspect($s);
$ids=static fn(string $code)=>array_column(array_filter($r['issues'],static fn($i)=>$i['code']===$code),'id');
kindCheck($s===$before,'Audit tidak mengubah snapshot');
kindCheck($ids('attempt_registration_owner_conflict')===[107,108],'Tryout/tester tidak disamakan dengan orphan resmi; konflik pemilik tetap ditolak');
kindCheck($ids('attempt_registration_invalid')===[109],'Integer tidak valid tidak ditebak sebagai pendaftaran resmi');
kindCheck($ids('official_registration_tryout_session_conflict')===[110],'Pendaftaran resmi tidak boleh memakai sesi tryout');
kindCheck($ids('tryout_registration_session_conflict')===[103,104,105],'Slot nol, sesi tidak sesuai dan mode resmi ditolak');
kindCheck($ids('synthetic_tester_registration_requires_review')===[106],'Placeholder tester bukan pendaftaran resmi yang disetujui');
kindCheck($ids('unfinished_pretes_tryout_attempt')===[102],'Attempt tryout belum submit tetap dilaporkan');
kindCheck($ids('active_pretes_tryout_session')===[3] && $ids('active_official_pretes_session')===[],'Status tryout aktif dibedakan dari resmi');
kindCheck($r['candidate_mapping']['pretes_cbt_attempts'][101]['registration_source_id']===-3001,'ID sintetis asli dipertahankan');
kindCheck($r['candidate_mapping']['pretes_cbt_attempts'][106]['candidate_program']===null && $r['cutover_allowed']===false,'Tester tidak menjadi izin migrasi');
$older=$s;unset($older['tables']['pretes_cbt_sessions'][0]['mode']);
$olderReport=Audit::inspect($older);
kindCheck(count(array_filter($olderReport['issues'],static fn($i)=>$i['code']==='tryout_session_mode_unverified'))===2,'Snapshot lama tanpa mode dibaca, tetapi tidak dianggap terverifikasi');
// Even if 999999 exists as a real registration, wrong ownership must not become a tester bypass.
$s['tables']['pretes_registrations'][]=['id'=>999999,'user_id'=>2,'periode'=>'Campuran lama'];
$real=Audit::inspect($s);
kindCheck(count(array_filter($real['issues'],static fn($i)=>$i['code']==='attempt_registration_owner_conflict'&&$i['id']===106))===1,'Pendaftaran nyata 999999 tetap memeriksa pemilik');

// Optional session metadata is exported only when present, without schema changes.
$projectionPdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$projectionPdo->exec("CREATE TABLE pretes_cbt_sessions(id INT,is_active INT,package_ids_json TEXT,mode TEXT,exam_open INT);
    INSERT INTO pretes_cbt_sessions VALUES(3,1,'[1]','tryout',0)");
$projected=\Lppai\ProgramSeparation\Capture::minimumSnapshot($projectionPdo);
kindCheck($projected['snapshot']['tables']['pretes_cbt_sessions'][0]['mode']==='tryout','Mode snapshot baru berasal dari kolom asli');
kindCheck(!isset($projected['missing_schema']['pretes_cbt_sessions']),'Kolom opsional tidak merusak kontrak minimum');
$projectionPdo=null;
// Actual review CLI on a private synthetic capture: reads only, verifies hashes, no proc_open inside tool.
$dir=str_replace('\\','/',sys_get_temp_dir()).'/attempt-review-'.bin2hex(random_bytes(5));mkdir($dir,0700);
$write=static function(string $name,array $value)use($dir):void{file_put_contents($dir.'/'.$name,json_encode($value));chmod($dir.'/'.$name,0600);};
try{
    file_put_contents($dir.'/database.sql',str_repeat('-- synthetic backup fixture only\n',10));
    $write('snapshot.json',$before);
    $write('summary.json',['backup_sha256'=>hash_file('sha256',$dir.'/database.sql')]);
    $write('capture-state.json',['snapshot_sha256'=>hash_file('sha256',$dir.'/snapshot.json')]);
    $invoke=static function()use($dir):array{
        $p=proc_open([PHP_BINARY,'-d','disable_functions=proc_open,proc_close',__DIR__.'/../tools/program-separation-review.php','--capture-dir='.$dir],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return[proc_close($p),$out,$err];
    };
    $hash=hash_file('sha256',$dir.'/snapshot.json');[$code,$out,$err]=$invoke();$review=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    kindCheck($code===0&&$err===''&&$review['database_modified']===false&&$review['cutover_allowed']===false,'Review CLI tanpa subprocess/migrasi');
    kindCheck($review['attempt_types']['tryout']['jumlah']===5 && hash_file('sha256',$dir.'/snapshot.json')===$hash,'Ringkasan jenis dan file asli dipertahankan');
    file_put_contents($dir.'/database.sql','changed');
    kindCheck($invoke()[0]===1,'Backup diubah menyebabkan review ditolak');
}finally{foreach(glob($dir.'/*')as$file)unlink($file);rmdir($dir);}
echo "PASS: resmi/tryout/tester, encoding negatif, pemilik, snapshot lama, sesi aktif per mode dan review CLI read-only tanpa proc_open.\n";
