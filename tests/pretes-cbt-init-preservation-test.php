<?php
require_once __DIR__ . '/../includes/pretes-cbt.php';
// Exercise the legacy MySQL-only maintenance branch against an isolated SQLite fixture.
final class InitPreservationPdo extends PDO {
    public function getAttribute(int $attribute): mixed {
        if ($attribute===PDO::ATTR_DRIVER_NAME) {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,4) as $frame) {
                if (($frame['function']??'')==='pretesCbtEnsureDefaultSessionsActive') return 'mysql';
            }
        }
        return parent::getAttribute($attribute);
    }
}
if (($argv[1]??'')==='--verify-child') {
    $pdo=new InitPreservationPdo('sqlite:'.$argv[2],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$snapshot=static function()use($pdo):array {
    $out=[];foreach(['pretes_cbt_sessions','pretes_cbt_packages','pretes_cbt_questions','pretes_cbt_attempts','pretes_cbt_items'] as $table) {
        $out[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    }return $out;
};
$before=$snapshot();
for($i=0;$i<3;$i++) {
    ensurePretesCbtTables($pdo);
    pretesCbtGetSessions($pdo);pretesCbtGetPackages($pdo);
}
if($snapshot()!==$before)throw new RuntimeException('Inisialisasi/read mengubah sesi, bank soal, jawaban atau nilai historis.');
if(pretesCbtGetActiveSessions($pdo)!==[])throw new RuntimeException('Sesi yang ditutup admin aktif kembali.');
echo "PASS: inisialisasi berulang mempertahankan sesi nonaktif/tertutup, paket, snapshot, jawaban belum submit dan nilai tersimpan.\n";
    exit;
}
$fixture=tempnam(sys_get_temp_dir(),'pretes-preserve-');
$pdo=new InitPreservationPdo('sqlite:'.$fixture,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
ensurePretesCbtTables($pdo);
$pdo->exec("INSERT INTO pretes_cbt_packages(id,code,name,created_at) VALUES(2,'PKT-2-KRISTEN-PROTESTAN','Paket arsip',1);
INSERT INTO pretes_cbt_sessions(id,code,title,package_ids_json,is_active,exam_open,created_at)
VALUES(2,'SESI-RESMI-PROTESTAN-2026','Sesi arsip','[1,2]',0,0,1);
UPDATE pretes_cbt_sessions SET is_active=0,exam_open=0,package_ids_json='[1,2]';
INSERT INTO pretes_cbt_attempts(id,user_id,registration_id,session_id,started_at,deadline_at,submitted_at,correct_count,score)
VALUES(10,17,27,2,100,200,150,1,100),(11,18,28,1,100,200,NULL,NULL,NULL);
INSERT INTO pretes_cbt_items(attempt_id,position,question_id,question_text,options_json,correct_answer,answer,flagged)
VALUES(10,1,1,'Snapshot tidak boleh berubah','{\"A\":\"Benar\",\"B\":\"Salah\"}','A','A',0),
(11,1,1,'Jawaban belum submit','{\"A\":\"Benar\",\"B\":\"Salah\"}','A','B',1);
");
$pdo=null;
try {
    $process=proc_open([PHP_BINARY,__FILE__,'--verify-child',$fixture],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0 || $err!=='')throw new RuntimeException($err.$out);
    echo $out;
} finally {unlink($fixture);}
