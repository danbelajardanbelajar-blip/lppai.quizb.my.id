<?php
require_once __DIR__ . '/../includes/tutorial-fill-existing.php';
function checkFill($ok, $message) { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
function fixtureFill(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE tutorial_classes(id INTEGER PRIMARY KEY,nama_kelas TEXT,dosen_pengampu TEXT,hari TEXT,jam TEXT,ruangan TEXT,gelombang TEXT,semester TEXT,kuota INTEGER);
        INSERT INTO tutorial_classes VALUES
        (1,'Kelas A','Tutor A','Senin','13:00','R1','gel1','2026/2027-Ganjil',3),
        (2,'Kelas B','Tutor B','Senin','13:00','R2','gel1','2026-2027-Ganjil',3),
        (3,'Lama','Tutor A','Senin','13:00','R3','gel1','2025-2026-Ganjil',30),
        (4,'Hari lain','Tutor A','Selasa','13:00','R4','gel1','2026-2027-Ganjil',30),
        (5,'Dosen nonaktif','Tutor C','Senin','13:00','R5','gel1','2026-2027-Ganjil',30),
        (6,'Kelas A kedua','Tutor A','Senin','13:00','R6','gel1','2026-2027-Ganjil',3);
        CREATE TABLE tutorial_registrations(id INTEGER PRIMARY KEY,user_id INTEGER,tutorial_class_id INTEGER,tahun_ajaran TEXT,gelombang TEXT,status TEXT,created_at TEXT,hari_pilihan TEXT,tipe_nilai TEXT);
        INSERT INTO tutorial_registrations VALUES
        (1,1,1,'2026-2027','gel1','aktif','2026-11-02','Senin','gel 1'),
        (2,2,6,'2026-2027','gel1','aktif','2026-11-02','Senin','gel 1'),
        (3,3,3,'2025-2026','gel1','aktif','2025-11-02','Senin','gel 1'),
        (4,4,NULL,'2026-2027','gel1','terdaftar','2026-11-02','Senin',NULL),
        (5,5,NULL,'2026-2027','gel1','terdaftar','2026-11-02','Senin',NULL),
        (6,6,NULL,'2026-2027','gel1','terdaftar','2026-11-02','Senin',NULL),
        (7,7,NULL,'2026-2027','gel1','terdaftar','2026-11-02','Senin',NULL),
        (8,8,NULL,'2026-2027','gel1','terdaftar','2026-11-02','Senin',NULL);");
    return $pdo;
}
$period=['tahun_ajaran'=>'2026/2027','semester'=>'Ganjil','gelombang'=>'gel1','created_at'=>'2026-11-01'];
foreach (['fill_first','distribute_evenly'] as $mode) {
    $pdo=fixtureFill();
    $before=$pdo->query('SELECT * FROM tutorial_classes ORDER BY id')->fetchAll();
    $pdo->beginTransaction();
    $result=tutorialFillExistingClasses($pdo,$period,'Senin',[4,5,6,7,8],['Tutor A','Tutor B'],$mode,3);
    $pdo->commit();
    checkFill($result['assigned']===4 && $result['remaining']===[8], 'respect total teacher quota across multiple classes');
    checkFill($pdo->query('SELECT * FROM tutorial_classes ORDER BY id')->fetchAll()===$before,'preserve classrooms, teacher, schedule, quota and room');
    $placements=$pdo->query('SELECT id,tutorial_class_id FROM tutorial_registrations WHERE id>=4 ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    checkFill($placements[4]===($mode==='fill_first'?1:2),'fill-first versus least-loaded teacher');
    checkFill($placements[8]===null,'full teachers leave remaining student unassigned');
    checkFill($pdo->query('SELECT COUNT(*) FROM tutorial_registrations WHERE tutorial_class_id=6')->fetchColumn()==1,'second class cannot bypass aggregate teacher quota');
    checkFill($pdo->query('SELECT COUNT(*) FROM tutorial_registrations WHERE tutorial_class_id IN (3,4,5) AND id>=4')->fetchColumn()==0,'exclude other periods/days/unselected teachers');
    echo "PASS existing classes: $mode\n";
}
$pdo=fixtureFill();
$pdo->exec('UPDATE tutorial_classes SET kuota=0 WHERE id IN (1,2,6)');
$result=tutorialFillExistingClasses($pdo,$period,'Senin',[4],['Tutor A','Tutor B'],'fill_first',30);
checkFill($result['assigned']===0 && $result['remaining']===[4],'zero class quota rejects extra participants');
$pdo=fixtureFill();
$pdo->beginTransaction();
try { tutorialFillExistingClasses($pdo,$period,'Senin',[4,1],['Tutor A','Tutor B'],'fill_first',30); checkFill(false,'expected conflict'); }
catch (RuntimeException $e) { $pdo->rollBack(); }
checkFill($pdo->query('SELECT tutorial_class_id FROM tutorial_registrations WHERE id=4')->fetchColumn()===null,'transaction rolls back when placement changes');
echo "PASS: full/zero quota, preserved existing data, aggregate teacher limits, period/day isolation and rollback.\n";
function sanitize($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$fillExistingResult = ['assigned'=>2,'period'=>'2026/2027 / Ganjil / gel1','waiting'=>[
    ['nama_lengkap'=>'Mahasiswa <script>uji</script>','nim'=>'001','hari_pilihan'=>'Senin'],
]];
ob_start(); include __DIR__ . '/../includes/tutorial-fill-existing-report.php'; $html=ob_get_clean();
checkFill(str_contains($html,'2 mahasiswa') && str_contains($html,'1 mahasiswa'),'report counts placed and waiting students');
checkFill(str_contains($html,'Mahasiswa &lt;script&gt;uji&lt;/script&gt;') && !str_contains($html,'<script>uji</script>'),'waiting names rendered safely');
checkFill(str_contains($html,'001') && str_contains($html,'Senin'),'report includes NIM and preferred day');
$fillExistingResult = ['assigned'=>0,'period'=>'2026/2027','waiting'=>[]];
ob_start(); include __DIR__ . '/../includes/tutorial-fill-existing-report.php'; $html=ob_get_clean();
checkFill(str_contains($html,'0 mahasiswa') && !str_contains($html,'<tbody>'),'empty waiting report does not invent students');
echo "PASS: report counts, waiting names/NIM/day, escaping and empty result.\n";
