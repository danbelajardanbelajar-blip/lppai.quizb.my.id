<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/program-separation/capture.php';
use Lppai\ProgramSeparation\Capture;
function captureCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE pretes_assessments(registration_id INT,scores_json TEXT,notes BLOB);
    CREATE TABLE pretes_cbt_items(attempt_id INT,position INT,answer TEXT,flagged INT);
    CREATE TABLE users(id INT,jenis_program TEXT,role TEXT,password TEXT);
    INSERT INTO users VALUES(1,'RPL','mahasiswa','not-exported'),(2,'Reguler','mahasiswa','not-exported'),(3,'','admin','not-exported');
    CREATE TABLE pretes_registrations(id INT,user_id INT,periode TEXT);
    INSERT INTO pretes_registrations VALUES(11,1,'Periode lama');
    CREATE TABLE tutorial_rpl_members(id INT,user_id INT);
    INSERT INTO tutorial_rpl_members VALUES(12,1);
    CREATE TABLE sessions(id INT,value TEXT);
    INSERT INTO pretes_assessments VALUES(11,'{\"ujian_tulis\":86.5,\"praktik\":90}',NULL);
    INSERT INTO pretes_cbt_items VALUES(10,1,'A',0),(10,2,'B',1);
");
$academic=Capture::academicTables(Capture::tables($pdo));
captureCheck(in_array('pretes_assessments',$academic,true)&&!in_array('sessions',$academic,true),'Pilih tabel akademik, bukan sesi login');
$before=Capture::fingerprint($pdo,$academic);$capture=Capture::minimumSnapshot($pdo);
captureCheck(count($capture['snapshot']['tables']['users'])===2,'Hanya akun mahasiswa diproyeksikan ke audit');
captureCheck(!str_contains(json_encode($capture),'not-exported'),'Password tidak masuk snapshot minimum');
captureCheck($capture['missing_schema']['tutorial_rpl_members']===['class_id'],'Kolom hilang dilaporkan tanpa ALTER TABLE');
captureCheck($capture['snapshot']['tables']['tutorial_rpl_members'][0]['class_id']===null,'Kolom tidak tersedia bukan nilai hasil tebakan');
captureCheck(Capture::fingerprint($pdo,$academic)===$before,'Capture tidak mengubah nilai/jawaban/data');
$pdo->exec("UPDATE pretes_cbt_items SET answer='C' WHERE position=2");
captureCheck(Capture::fingerprint($pdo,$academic)!==$before,'Perubahan satu jawaban terdeteksi');
$pdo->exec("UPDATE pretes_cbt_items SET answer='B' WHERE position=2");
captureCheck(Capture::fingerprint($pdo,$academic)===$before,'Hash deterministik setelah restore fixture');
$pdo->exec("UPDATE pretes_assessments SET scores_json='{\"ujian_tulis\":85}'");
captureCheck(Capture::fingerprint($pdo,$academic)!==$before,'Perubahan nilai terdeteksi');
$pdo->exec("INSERT INTO pretes_cbt_items VALUES(10,1,'A',0)");
captureCheck(Capture::fingerprint($pdo,['pretes_cbt_items'])['pretes_cbt_items']['rows']===3,'Baris duplikat tetap dihitung');
$options=Capture::clientOptions(['host'=>'localhost','user'=>'fixture','password'=>'has"quote\\slash']);
captureCheck(str_contains($options,'password="has\\"quote\\\\slash"'),'Opsi klien meng-escape kutip/backslash');
foreach(["name\npassword=oops",'x` WHERE 1=1 --'] as $bad) {
    try {
        if(str_contains($bad,"\n"))Capture::clientOptions(['host'=>$bad,'user'=>'x','password'=>'x']);
        else Capture::identifier($bad);
        throw new RuntimeException('Input berbahaya diterima');
    } catch(InvalidArgumentException $expected){}
}
try{Capture::privateDirectory(dirname(__DIR__),dirname(__DIR__));throw new RuntimeException('Direktori web root diterima');}catch(RuntimeException $expected){captureCheck($expected->getMessage()!=='Direktori web root diterima','Backup wajib di luar root proyek');}
$cli=__DIR__.'/../tools/program-separation-capture.php';
foreach([['--help',0],['--apply',2],['',2]] as [$arg,$expected]) {
    $command=[PHP_BINARY,$cli];if($arg!=='')$command[]=$arg;
    $p=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);captureCheck(proc_close($p)===$expected,'Validasi CLI sebelum koneksi database');
}
foreach(get_included_files() as $file)captureCheck(!preg_match('~[\\/](auth|database|pretes-cbt|tutorial-rpl-helper)\.php$~',$file),'Inspeksi tidak memuat initializer aplikasi');
echo "PASS: capture read-only, sidik nilai/jawaban, schema gap, minimasi password, opsi klien, larangan output web root dan argumen CLI.\n";
