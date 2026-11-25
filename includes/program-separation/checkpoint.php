<?php
declare(strict_types=1);
namespace Lppai\ProgramSeparation;
require_once __DIR__.'/capture.php';

/** Read-only PHP preparation/verification; mysqldump runs in the hosting shell. */
final class Checkpoint
{
    private static function write(string $run,string $name,string $content): void
    {
        if (file_put_contents($run.'/'.$name,$content,LOCK_EX)!==strlen($content) || !chmod($run.'/'.$name,0600)) throw new \RuntimeException('Private capture output is incomplete.');
    }
    private static function json(string $run,string $name,array $value): void
    {
        self::write($run,$name,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    }
    private static function read(string $run,string $name): array
    {
        $path=$run.'/'.$name;
        if (!is_file($path) || is_link($path) || filesize($path)>33554432) throw new \RuntimeException('Prepared capture file missing or invalid.');
        $data=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Prepared capture is invalid.');
        return $data;
    }
    private static function begin(\PDO $pdo): void
    {
        if ($pdo->inTransaction()) throw new \RuntimeException('Capture needs its own read-only transaction.');
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql') {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET TRANSACTION READ ONLY');
        }
        $pdo->beginTransaction();
    }
    public static function quote(string $value): string
    {
        if (str_contains($value,"\0")) throw new \InvalidArgumentException('Invalid shell argument.');
        return "'".str_replace("'","'\\''",$value)."'";
    }
    public static function dumpScript(string $run,string $database,string $charset): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D',$charset)) throw new \InvalidArgumentException('Invalid connection charset.');
        $client=self::quote($run.'/mysql-client.cnf');$part=self::quote($run.'/database.sql.part');
        $sql=self::quote($run.'/database.sql');$marker=self::quote($run.'/dump-complete');$error=self::quote($run.'/dump-error.txt');
        return "#!/bin/sh\nset -eu\numask 077\nclient=$client\n".
            "trap 'rm -f -- \"\$client\"' 0\ntrap 'exit 1' 1 2 15\n".
            "test -f \"\$client\" || { printf '%s\\n' 'Private client file missing; prepare again.' >&2; exit 1; }\n".
            "if [ -e $sql ] || [ -e $part ] || [ -e $marker ]; then printf '%s\\n' 'Dump already attempted; prepare again.' >&2; exit 1; fi\nset -C\n".
            'mysqldump --defaults-extra-file="$client" --single-transaction --skip-lock-tables --quick --hex-blob --routines --events --triggers '.
            self::quote('--default-character-set='.$charset).' -- '.self::quote($database)." > $part 2> $error\n".
            "mv -- $part $sql\nprintf '%s\\n' 'mysqldump-exit-0' > $marker\n";
    }
    public static function prepare(\PDO $pdo,string $parent,string $databaseKey,string $clientOptions,string $database,string $charset): string
    {
        $run=null;
        try {
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql' && $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'")->fetchColumn()!==false) throw new \RuntimeException('Nontransactional tables require a separately verified hosting backup.');
            self::begin($pdo);$academic=Capture::academicTables(Capture::tables($pdo));
            if (!$academic) throw new \RuntimeException('No academic tables found; check deployment database.');
            $before=Capture::fingerprint($pdo,$academic);$capture=Capture::minimumSnapshot($pdo);$pdo->commit();
            $run=$parent.'/lppai-'.(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Jakarta')))->format('Ymd-His').'-'.bin2hex(random_bytes(4));
            if (!mkdir($run,0700)) throw new \RuntimeException('Cannot create private backup directory.');
            self::json($run,'integrity-before.json',$before);self::json($run,'snapshot.json',$capture['snapshot']);self::json($run,'schema-gaps.json',$capture['missing_schema']);
            self::write($run,'dump.sh',self::dumpScript($run,$database,$charset));
            self::json($run,'capture-state.json',['version'=>1,'database_key'=>$databaseKey,
                'snapshot_sha256'=>hash_file('sha256',$run.'/snapshot.json'),'integrity_sha256'=>hash_file('sha256',$run.'/integrity-before.json'),'schema_gaps_sha256'=>hash_file('sha256',$run.'/schema-gaps.json')]);
            self::write($run,'mysql-client.cnf',$clientOptions);return $run;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($run && is_file($run.'/mysql-client.cnf')) unlink($run.'/mysql-client.cnf');
            throw $error;
        }
    }
    public static function finish(\PDO $pdo,string $run,string $databaseKey): array
    {
        $state=self::read($run,'capture-state.json');
        if (($state['version']??null)!==1 || !hash_equals((string)($state['database_key']??''),$databaseKey)) throw new \RuntimeException('Capture belongs to a different database or version.');
        foreach (['snapshot.json'=>'snapshot_sha256','integrity-before.json'=>'integrity_sha256','schema-gaps.json'=>'schema_gaps_sha256'] as $file=>$hash) {
            if (!is_file($run.'/'.$file) || is_link($run.'/'.$file) || !hash_equals((string)($state[$hash]??''),hash_file('sha256',$run.'/'.$file))) throw new \RuntimeException('Prepared capture changed; prepare again.');
        }
        $sql=$run.'/database.sql';$marker=$run.'/dump-complete';
        if (!is_file($marker) || is_link($marker) || trim(file_get_contents($marker))!=='mysqldump-exit-0' || !is_file($sql) || is_link($sql) || filesize($sql)<100 || is_file($run.'/database.sql.part')) throw new \RuntimeException('Shell backup did not complete; no verified capture.');
        if (is_file($run.'/mysql-client.cnf')) throw new \RuntimeException('Shell cleanup incomplete.');
        self::begin($pdo);
        try {$after=Capture::fingerprint($pdo,Capture::academicTables(Capture::tables($pdo)));$pdo->commit();}
        catch (\Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}
        self::json($run,'integrity-after.json',$after);
        if (self::read($run,'integrity-before.json')!==$after) throw new \RuntimeException('Academic data changed during capture; inspect activity and prepare again.');
        $gaps=self::read($run,'schema-gaps.json');$report=Audit::inspect(self::read($run,'snapshot.json'));
        $report['missing_schema']=$gaps;$report['snapshot_sha256']=hash_file('sha256',$run.'/snapshot.json');self::json($run,'audit.json',$report);
        $summary=['status'=>'backup_created_no_migration','academic_data_unchanged_during_capture'=>true,
            'academic_tables'=>count($after),'audit_findings'=>count($report['issues']),'schema_gap_tables'=>count($gaps),
            'backup_sha256'=>hash_file('sha256',$sql),'backup_bytes'=>filesize($sql),'restore_tested'=>false,'cutover_allowed'=>false,'private_directory'=>$run];
        self::json($run,'summary.json',$summary);return $summary;
    }
}
