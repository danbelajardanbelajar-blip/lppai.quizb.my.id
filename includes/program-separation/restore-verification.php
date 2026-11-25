<?php
declare(strict_types=1);
namespace Lppai\ProgramSeparation;
require_once __DIR__.'/capture.php';
require_once __DIR__.'/test-credentials.php';

/** Verifies a restored historical backup, never the current production contents. */
final class RestoreVerification
{
    public static function baseline(string $run, string $databaseKey): array
    {
        $read = static function (string $name) use ($run): array {
            $path=$run.'/'.$name;
            if (!is_file($path) || is_link($path) || filesize($path)>33554432) throw new \RuntimeException('Invalid capture metadata.');
            $value=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
            if (!is_array($value)) throw new \RuntimeException('Invalid capture metadata.');
            return $value;
        };
        $state=$read('capture-state.json');$summary=$read('summary.json');
        if (($state['version']??null)!==1 || !hash_equals((string)($state['database_key']??''),$databaseKey)) throw new \RuntimeException('Capture belongs to another production database.');
        $sql=$run.'/database.sql';
        if (($summary['status']??null)!=='backup_created_no_migration' || ($summary['academic_data_unchanged_during_capture']??null)!==true
            || !is_file($sql) || is_link($sql) || !is_file($run.'/dump-complete') || is_link($run.'/dump-complete')
            || trim(file_get_contents($run.'/dump-complete'))!=='mysqldump-exit-0' || is_file($run.'/database.sql.part')
            || filesize($sql)!==($summary['backup_bytes']??null)
            || !hash_equals((string)($summary['backup_sha256']??''),hash_file('sha256',$sql))) throw new \RuntimeException('Backup is incomplete or checksum changed.');
        $expected=$read('integrity-before.json');
        if (!hash_equals((string)($state['integrity_sha256']??''),hash_file('sha256',$run.'/integrity-before.json')) || !$expected) throw new \RuntimeException('Baseline checksum changed or baseline is empty.');
        foreach ($expected as $table=>$entry) {
            Capture::identifier((string)$table);
            if (!is_array($entry) || !is_int($entry['rows']??null) || $entry['rows']<0 || !is_string($entry['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D',$entry['sha256'])) throw new \RuntimeException('Malformed baseline.');
        }
        ksort($expected,SORT_STRING);
        return ['fingerprint'=>$expected,'backup_sha256'=>$summary['backup_sha256']];
    }

    public static function compare(\PDO $pdo, array $expected): array
    {
        if (!$expected || $pdo->inTransaction()) throw new \RuntimeException('Nonempty baseline and a separate verification transaction required.');
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql') {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('SET TRANSACTION READ ONLY');
        }
        $pdo->beginTransaction();
        try {
            $all=Capture::tables($pdo);
            $actual=Capture::fingerprint($pdo,Capture::academicTables($all));
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        ksort($expected,SORT_STRING);
        $different=[];
        foreach (array_unique(array_merge(array_keys($expected),array_keys($actual))) as $table) {
            if (($expected[$table]??null)!==($actual[$table]??null)) $different[]=$table;
        }
        sort($different,SORT_STRING);
        return ['academic_data_matches_backup'=>!$different,'academic_tables'=>count($actual),
            'restored_total_tables'=>count($all),'different_tables'=>$different];
    }
}
