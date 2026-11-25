<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/program-separation/additive-rehearsal.php';
use Lppai\ProgramSeparation\Capture;
use Lppai\ProgramSeparation\AdditiveRehearsal;
// Real MariaDB integration: dedicated local scratch server and fresh fixture DB only.
$dsn=getenv('LPPAI_TEST_MYSQL_DSN');
if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=(\d+);dbname=(lppai_ps_test_[a-z0-9_]+)$/D',$dsn,$matches))throw new RuntimeException('Explicit loopback-only lppai_ps_test_* fixture DSN required; never use application credentials.');
$pdo=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$matches[2]||Capture::tables($pdo))throw new RuntimeException('Fresh isolated fixture database required.');
function timestampCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function timestampFixture(PDO $pdo):void{
$pdo->exec("SET SESSION time_zone='+00:00';
    CREATE TABLE master_pretes(id INT PRIMARY KEY,periode VARCHAR(100),target_program VARCHAR(16),updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,touched_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)) ENGINE=InnoDB;
    INSERT INTO master_pretes VALUES(1,'Historis RPL','RPL','2020-01-02 03:04:05','2020-01-02 03:04:05.123456'),(2,'Campuran','Semua','2020-01-02 03:04:05','2020-01-02 03:04:05.123456');
    CREATE TABLE pretes_registrations(id INT PRIMARY KEY,user_id INT,periode VARCHAR(100),updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB;
    INSERT INTO pretes_registrations VALUES(1,10,'Historis RPL','2020-01-02 03:04:05'),(2,20,'Campuran','2020-01-02 03:04:05');
    CREATE TABLE pretes_cbt_sessions(id INT PRIMARY KEY,is_active INT,exam_open INT) ENGINE=InnoDB;INSERT INTO pretes_cbt_sessions VALUES(1,0,0);
    CREATE TABLE pretes_cbt_packages(id INT PRIMARY KEY) ENGINE=InnoDB;INSERT INTO pretes_cbt_packages VALUES(1);
    CREATE TABLE pretes_cbt_attempts(id INT PRIMARY KEY,registration_id INT,user_id INT,score DECIMAL(5,2),submitted_at INT,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB;
    INSERT INTO pretes_cbt_attempts VALUES(1,1,10,85.50,100,'2020-01-02 03:04:05'),(2,-1001,10,NULL,NULL,'2020-01-02 03:04:05');
    CREATE TABLE pretes_cbt_items(attempt_id INT,answer CHAR(1),correct_answer CHAR(1),question_text TEXT) ENGINE=InnoDB;INSERT INTO pretes_cbt_items VALUES(1,'B','B','Immutable question'),(2,'C','A','Unfinished tryout');
    CREATE TABLE users(id INT PRIMARY KEY,jenis_program VARCHAR(16)) ENGINE=InnoDB;INSERT INTO users VALUES(10,'Reguler');");
}
timestampFixture($pdo);
$plan=AdditiveRehearsal::prepare($pdo);
foreach(AdditiveRehearsal::ADDITIONS as $table=>$columns)foreach($columns as $name=>$type)$pdo->exec('ALTER TABLE '.Capture::identifier($table).' ADD COLUMN '.Capture::identifier($name).' '.$type);
// Reproduce the original failure on real ON UPDATE columns, then roll it back.
$pdo->beginTransaction();$pdo->exec("UPDATE master_pretes SET program_scope='rpl' WHERE id=1");
timestampCheck(AdditiveRehearsal::projection($pdo,$plan['original_columns'])!==$plan['original_fingerprint'],'Old UPDATE must reproduce automatic timestamp mutation');
$pdo->rollBack();
timestampCheck(AdditiveRehearsal::inspect($pdo,$plan)['all_original_column_values_unchanged'],'Rollback restores original timestamp values');
$out=AdditiveRehearsal::resume($pdo,$plan);
timestampCheck($out['alter_statements_executed']===0 && $out['all_original_column_values_unchanged'],'Resume uses existing columns without DDL');
timestampCheck(AdditiveRehearsal::projection($pdo,$plan['original_columns'])===$plan['original_fingerprint'],'All old values including TIMESTAMP/DATETIME(6), scores/items/users/flags must match');
timestampCheck($pdo->query('SELECT program_snapshot FROM pretes_cbt_attempts WHERE id=1')->fetchColumn()==='rpl','Verified official snapshot inherited from historical period');
timestampCheck($pdo->query('SELECT program_snapshot FROM pretes_cbt_attempts WHERE id=2')->fetchColumn()===null,'Tryout remains unassigned and unfinished');
$after=Capture::fingerprint($pdo,Capture::tables($pdo));
try{AdditiveRehearsal::resume($pdo,$plan);throw new LogicException('Repeat accepted');}catch(RuntimeException $expected){}
timestampCheck(Capture::fingerprint($pdo,Capture::tables($pdo))===$after,'Repeat refusal leaves all data unchanged');
$freshName=$matches[2].'_fresh';
$pdo->exec('CREATE DATABASE '.Capture::identifier($freshName).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$fresh=new PDO('mysql:host=127.0.0.1;port='.$matches[1].';dbname='.$freshName,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
timestampFixture($fresh);$freshPlan=AdditiveRehearsal::prepare($fresh);$freshResult=AdditiveRehearsal::apply($fresh,$freshPlan);
timestampCheck($freshResult['all_original_column_values_unchanged'] && AdditiveRehearsal::projection($fresh,$freshPlan['original_columns'])===$freshPlan['original_fingerprint'],'Fresh DDL plus backfill also preserves automatic timestamps on MariaDB');
echo "PASS: real MariaDB ON UPDATE failure reproduced and rolled back; fresh/resume preserve TIMESTAMP and DATETIME(6), scores/items/users/flags; resume has zero ALTER and refuses repeats.\n";
