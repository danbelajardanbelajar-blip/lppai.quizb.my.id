<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/program-separation/audit.php';
use Lppai\ProgramSeparation\Audit;
use Lppai\ProgramSeparation\Scope;

function separationCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function separationDenied(callable $action): void {
    try { $action(); } catch (DomainException | InvalidArgumentException $e) { return; }
    throw new RuntimeException('Akses/input yang seharusnya ditolak justru diterima.');
}
$modules = ['pretes-rpl','pretes-reguler','tutorial-rpl','tutorial-reguler'];
foreach ($modules as $module) {
    $scope = new Scope($module);
    $student = ['id'=>10,'role'=>'mahasiswa','jenis_program'=>$scope->programKey()];
    foreach ($modules as $other) {
        $otherScope = new Scope($other);
        $owner = ['activity'=>$otherScope->activity(),'program'=>$otherScope->programKey(),'user_id'=>10,'dosen_id'=>20];
        if ($module === $other) {
            $scope->assertStudent($student,$owner);
            $scope->assertLecturer(['id'=>20,'role'=>'dosen'],$owner);
            $scope->assertAdmin(['id'=>30,'role'=>'admin'],$owner);
        } else {
            separationDenied(fn()=>$scope->assertStudent($student,$owner));
            separationDenied(fn()=>$scope->assertLecturer(['id'=>20,'role'=>'dosen'],$owner));
            separationDenied(fn()=>$scope->assertAdmin(['id'=>30,'role'=>'admin'],$owner));
        }
    }
    $owner=['activity'=>$scope->activity(),'program'=>$scope->programKey(),'user_id'=>11,'dosen_id'=>21];
    separationDenied(fn()=>$scope->assertStudent($student,$owner));
    separationDenied(fn()=>$scope->assertLecturer(['id'=>20,'role'=>'dosen'],$owner));
    separationDenied(fn()=>$scope->assertStudent(['id'=>11,'role'=>'admin','jenis_program'=>$scope->programKey()],$owner));
    separationDenied(fn()=>$scope->assertOwner(['activity'=>$scope->activity(),'program'=>'Semua']));
    separationDenied(fn()=>$scope->assertAdmin($student,$owner));
}
foreach (['', 'rpl', 'pretes', 'pretes-Semua'] as $bad) separationDenied(fn()=>new Scope($bad));
foreach ([null, '', 'Semua', 'RPL Reguler', 1, ['rpl']] as $bad) separationCheck(Scope::program($bad)===null,'Program tidak dikenal tidak boleh ditebak sebagai Reguler');
separationCheck(Scope::program(' RPL ')==='rpl','Normalisasi program eksplisit');

$snapshot=['schema_version'=>1,'tables'=>array_fill_keys(array_keys(Audit::FIELDS),[])];
$snapshot['tables']['users']=[['id'=>1,'jenis_program'=>'RPL'],['id'=>2,'jenis_program'=>'Reguler']];
$snapshot['tables']['master_pretes']=[['id'=>10,'periode'=>'Khusus RPL','target_program'=>'RPL','status'=>'selesai'],
    ['id'=>20,'periode'=>'Khusus Reguler','target_program'=>'Reguler','status'=>'selesai']];
$snapshot['tables']['pretes_registrations']=[['id'=>11,'user_id'=>1,'periode'=>'Khusus RPL'],['id'=>21,'user_id'=>2,'periode'=>'Khusus Reguler']];
$snapshot['tables']['pretes_cbt_sessions']=[['id'=>30,'is_active'=>0,'package_ids_json'=>'[40]'],['id'=>31,'is_active'=>0,'package_ids_json'=>'[41]']];
$snapshot['tables']['pretes_cbt_packages']=[['id'=>40],['id'=>41]];
$snapshot['tables']['pretes_cbt_attempts']=[['id'=>50,'user_id'=>1,'registration_id'=>11,'session_id'=>30,'submitted_at'=>100],
    ['id'=>51,'user_id'=>2,'registration_id'=>21,'session_id'=>31,'submitted_at'=>100]];
$snapshot['tables']['tutorial_registrations']=[['id'=>60,'user_id'=>2]];
$snapshot['tables']['tutorial_rpl_registrations']=[['id'=>61,'user_id'=>1]];
$snapshot['tables']['tutorial_rpl_members']=[['id'=>62,'user_id'=>1,'class_id'=>70]];
$snapshot['tables']['tutorial_rpl_cbt_sessions']=[['id'=>80,'is_active'=>0]];
$snapshot['tables']['tutorial_rpl_cbt_attempts']=[['id'=>81,'user_id'=>1,'class_id'=>70,'session_id'=>80,'submitted'=>1,'submitted_at'=>100]];
$original=$snapshot;
$report=Audit::inspect($snapshot);
separationCheck($snapshot===$original,'Audit tidak mengubah snapshot input');
separationCheck($report['issues']===[] && $report['cutover_allowed']===false,'Snapshot bersih tetap tidak mengizinkan aktivasi');
separationCheck($report['candidate_mapping']['pretes_registrations'][11]['candidate_program']==='rpl','Kandidat RPL diturunkan dari bukti');
separationCheck($report['candidate_mapping']['pretes_registrations'][11]['requires_history_review']===true,'Program akun saat ini bukan bukti riwayat final');
$codes=static fn(array $s)=>array_column(Audit::inspect($s)['issues'],'code');
$changed=$snapshot;
$changed['tables']['pretes_cbt_attempts'][0]['submitted_at']=null;
$changed['tables']['pretes_cbt_sessions'][0]['is_active']=1;
$changed['tables']['master_pretes'][0]['status']='aktif';
$changed['tables']['tutorial_rpl_cbt_attempts'][0]['submitted']=0;
$changed['tables']['tutorial_rpl_cbt_sessions'][0]['is_active']=1;
foreach (['unfinished_pretes_attempt','active_or_unknown_pretes_session','active_pretes_period','unfinished_tutorial_attempt','active_or_unknown_tutorial_session'] as $code) {
    separationCheck(in_array($code,$codes($changed),true),'Pendeteksi aktivitas gagal: '.$code);
}
$changed=$snapshot;
$changed['tables']['master_pretes'][0]['target_program']='Semua';
$changed['tables']['pretes_cbt_attempts'][1]['session_id']=30;
$observed=Audit::inspect($changed);
separationCheck(in_array('mixed_period_requires_split',array_column($observed['issues'],'code'),true),'Periode campuran perlu dipisah manual');
separationCheck($observed['candidate_mapping']['pretes_cbt_sessions'][30]['observed_programs']===['reguler','rpl'],'Sesi dua program tidak dipaksakan menjadi salah satu');
separationCheck(in_array('package_ownership_requires_review',array_column($observed['issues'],'code'),true),'Paket campuran perlu review');
$changed=$snapshot;
$changed['tables']['master_pretes'][]=['id'=>22,'periode'=>'Khusus RPL','target_program'=>'RPL','status'=>'selesai'];
separationCheck(in_array('period_name_missing_or_ambiguous',$codes($changed),true),'Nama periode duplikat tidak boleh memilih ID pertama');
$changed=$snapshot;
$changed['tables']['users'][0]['jenis_program']='Reguler';
$changed['tables']['pretes_cbt_attempts'][1]['user_id']=1;
foreach (['user_period_program_conflict','attempt_registration_owner_conflict','tutorial_account_program_requires_review'] as $code) separationCheck(in_array($code,$codes($changed),true),'Konflik tidak terdeteksi: '.$code);
$changed=$snapshot;
$changed['tables']['users'][0]['jenis_program']='';
$changed['tables']['pretes_cbt_attempts'][0]['session_id']=null;
$changed['tables']['pretes_cbt_sessions'][0]['package_ids_json']='{"1":40}';
foreach (['unknown_user_program','attempt_session_unresolved','session_packages_invalid'] as $code) separationCheck(in_array($code,$codes($changed),true),'Ambiguitas tidak terdeteksi: '.$code);
$changed=$snapshot;unset($changed['tables']['tutorial_rpl_cbt_attempts']);
separationCheck(in_array('missing_table_snapshot',$codes($changed),true),'Tabel tidak dikirim berbeda dari tabel kosong');
foreach (['extra_column','duplicate_id','missing_column','bad_value','bad_schema'] as $case) {
    $changed=$snapshot;
    if ($case==='extra_column') $changed['tables']['users'][0]['password']='secret';
    if ($case==='duplicate_id') $changed['tables']['users'][]=$changed['tables']['users'][0];
    if ($case==='missing_column') unset($changed['tables']['users'][0]['jenis_program']);
    if ($case==='bad_value') $changed['tables']['users'][0]['jenis_program']=['rpl'];
    if ($case==='bad_schema') $changed['schema_version']=2;
    separationDenied(fn()=>Audit::inspect($changed));
}
// Actual CLI: only local snapshots, no apply command, stable fingerprint, input remains byte-identical.
$file=tempnam(sys_get_temp_dir(),'lppai-scope-');
$run=static function(array $args):array {
    $p=proc_open(array_merge([PHP_BINARY,__DIR__.'/../tools/program-separation-audit.php'],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];
};
try {
    file_put_contents($file,json_encode($snapshot,JSON_THROW_ON_ERROR));$hash=hash_file('sha256',$file);
    [$exit,$out,$err]=$run(['--input='.$file]);$cli=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    separationCheck($exit===0 && $err==='' && $cli['cutover_allowed']===false && $cli['snapshot_sha256']===$hash,'CLI bersih hanya laporan, tidak memberi izin cutover');
    separationCheck(hash_file('sha256',$file)===$hash,'Berkas snapshot tidak berubah');
    $changed=$snapshot;$changed['tables']['pretes_cbt_attempts'][0]['submitted_at']=null;file_put_contents($file,json_encode($changed));
    separationCheck($run(['--input='.$file])[0]===3,'CLI memberi kode review untuk aktivitas ujian');
    file_put_contents($file,'{"schema_version":1,"tables":{"users":[{"id":1,"password":"do-not-echo"}]}}');
    [$exit,$out,$err]=$run(['--input='.$file]);
    separationCheck($exit===2 && !str_contains($out.$err,'do-not-echo'),'Input sensitif ditolak tanpa dibocorkan');
    foreach ([['--apply'],['--input='.$file,'--apply'],['--input=https://example.test/db'],[]] as $args) separationCheck($run($args)[0]===2,'Mode apply/remote/argumen tidak sah ditolak');
} finally { unlink($file); }
foreach (get_included_files() as $file) {
    separationCheck(!preg_match('~[\\/](auth|database|pretes-cbt|tutorial-rpl-helper)\.php$~',$file),'Audit tidak boleh memuat runtime/DB/engine ujian');
}
echo "PASS: empat lingkup modul, pemilik mahasiswa/dosen, audit offline, konflik/campuran, sesi aktif/attempt belum selesai, privasi dan CLI tanpa apply.\n";
