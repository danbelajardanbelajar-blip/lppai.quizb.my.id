<?php
declare(strict_types=1);
namespace Lppai\ProgramSeparation;
require_once __DIR__.'/restore-verification.php';

/** Test-copy schema rehearsal only; no auth/CBT initialization or legacy-column writes. */
final class AdditiveRehearsal
{
    public const ADDITIONS=[
        'master_pretes'=>['program_scope'=>'VARCHAR(16) NULL'],
        'pretes_registrations'=>['periode_id'=>'BIGINT NULL','program_snapshot'=>'VARCHAR(16) NULL'],
        'pretes_cbt_sessions'=>['program_scope'=>'VARCHAR(16) NULL'],
        'pretes_cbt_packages'=>['program_scope'=>'VARCHAR(16) NULL'],
        'pretes_cbt_attempts'=>['program_snapshot'=>'VARCHAR(16) NULL'],
    ];

    public static function columns(\PDO $pdo,string $table): array
    {
        return $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'
            ? array_column($pdo->query('PRAGMA table_info('.Capture::identifier($table).')')->fetchAll(\PDO::FETCH_ASSOC),'name')
            : array_column($pdo->query('SHOW COLUMNS FROM '.Capture::identifier($table))->fetchAll(\PDO::FETCH_ASSOC),'Field');
    }

    public static function projection(\PDO $pdo,array $schema): array
    {
        ksort($schema,SORT_STRING);$out=[];
        foreach ($schema as $table=>$columns) {
            $stmt=$pdo->query('SELECT '.implode(',',array_map([Capture::class,'identifier'],$columns)).' FROM '.Capture::identifier($table));$hashes=[];
            while ($row=$stmt->fetch(\PDO::FETCH_ASSOC)) {
                foreach ($row as &$value) if ($value!==null) $value=(string)$value;
                unset($value);ksort($row,SORT_STRING);$hashes[]=hash('sha256',serialize($row));
            }
            sort($hashes,SORT_STRING);$out[$table]=['rows'=>count($hashes),'sha256'=>hash('sha256',implode("\n",$hashes))];
        }
        return $out;
    }

    public static function prepare(\PDO $pdo): array
    {
        if ($pdo->inTransaction()) throw new \RuntimeException('Rehearsal requires its own connection transaction.');
        $schema=[];
        foreach (Capture::tables($pdo) as $table) $schema[$table]=self::columns($pdo,$table);
        foreach (self::ADDITIONS as $table=>$columns) {
            if (!isset($schema[$table]) || array_intersect(array_keys($columns),$schema[$table])) throw new \RuntimeException('Rehearsal requires an untouched restored schema; do not repeat or drop columns automatically.');
        }
        $required=['master_pretes'=>['id','periode','target_program'],'pretes_registrations'=>['id','user_id','periode'],
            'pretes_cbt_attempts'=>['id','user_id','registration_id'],'pretes_cbt_sessions'=>['id'],'pretes_cbt_packages'=>['id']];
        foreach ($required as $table=>$columns) if (array_diff($columns,$schema[$table])) throw new \RuntimeException('Required legacy columns are missing.');
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql' && $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'")->fetchColumn()!==false) throw new \RuntimeException('All rehearsal tables must be InnoDB.');
        $pdo->beginTransaction();
        try {$fingerprint=self::projection($pdo,$schema);$pdo->commit();}
        catch (\Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}
        return ['original_columns'=>$schema,'original_fingerprint'=>$fingerprint,'additions'=>self::ADDITIONS];
    }

    public static function apply(\PDO $pdo,array $plan): array
    {
        // Detect changed input between journal preparation and DDL. No repair or deletion.
        if (($plan['additions']??null)!==self::ADDITIONS || self::prepare($pdo)!==$plan) throw new \RuntimeException('Rehearsal input changed before ALTER; stopped.');
        // MySQL ALTER commits implicitly. On failure added columns may remain; never promise DDL rollback.
        foreach (self::ADDITIONS as $table=>$columns) foreach ($columns as $name=>$type) {
            $pdo->exec('ALTER TABLE '.Capture::identifier($table).' ADD COLUMN '.Capture::identifier($name).' '.$type);
        }
        $pdo->beginTransaction();
        try {
            $programs=[];$periodUpdates=0;$registrationUpdates=0;$attemptUpdates=0;$unresolved=0;$mixed=0;
            $updatePeriod=$pdo->prepare('UPDATE master_pretes SET program_scope=? WHERE id=?');
            foreach ($pdo->query('SELECT id,periode,target_program FROM master_pretes ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $period) {
                $id=(int)$period['id'];$programs[$id]=Scope::program($period['target_program']);
                if($programs[$id]!==null){$updatePeriod->execute([$programs[$id],$id]);$periodUpdates++;}else{$mixed++;}
            }
            $registrationPrograms=[];$registrationUsers=[];
            // Respect the database collation; case/accent variants may be ambiguous legacy labels.
            $findPeriod=$pdo->prepare('SELECT id FROM master_pretes WHERE TRIM(periode)'.($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?' COLLATE NOCASE':'').'=?');
            $updateRegistration=$pdo->prepare('UPDATE pretes_registrations SET periode_id=?,program_snapshot=? WHERE id=?');
            foreach ($pdo->query('SELECT id,user_id,periode FROM pretes_registrations ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $registration) {
                $id=(int)$registration['id'];$label=trim((string)$registration['periode']);
                $findPeriod->execute([$label]);$ids=$label!==''?$findPeriod->fetchAll(\PDO::FETCH_COLUMN):[];$findPeriod->closeCursor();
                $registrationUsers[$id]=(int)$registration['user_id'];$registrationPrograms[$id]=null;
                if(count($ids)!==1){$unresolved++;continue;}
                $periodId=$ids[0];$registrationPrograms[$id]=$programs[$periodId];
                $updateRegistration->execute([$periodId,$registrationPrograms[$id],$id]);$registrationUpdates++;
            }
            $updateAttempt=$pdo->prepare('UPDATE pretes_cbt_attempts SET program_snapshot=? WHERE id=?');
            foreach ($pdo->query('SELECT id,user_id,registration_id FROM pretes_cbt_attempts ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $attempt) {
                $reg=Audit::registrationNumber($attempt['registration_id']);
                // Negative tryout IDs, tester placeholders and mixed/unknown periods stay unassigned.
                if($reg===null || $reg<1 || ($registrationUsers[$reg]??null)!==(int)$attempt['user_id'] || ($registrationPrograms[$reg]??null)===null)continue;
                $updateAttempt->execute([$registrationPrograms[$reg],(int)$attempt['id']]);$attemptUpdates++;
            }
            if(Capture::tables($pdo)!==array_keys($plan['original_columns']) || self::projection($pdo,$plan['original_columns'])!==$plan['original_fingerprint']) throw new \RuntimeException('Original data changed during rehearsal; metadata updates rolled back, added columns may remain.');
            $pdo->commit();
            return ['original_tables_verified'=>count($plan['original_columns']),'all_original_column_values_unchanged'=>true,
                'added_columns'=>array_sum(array_map('count',self::ADDITIONS)),'single_program_periods'=>$periodUpdates,
                'legacy_mixed_or_unknown_periods'=>$mixed,'registrations_linked_to_unique_period'=>$registrationUpdates,
                'registrations_without_unique_period'=>$unresolved,'official_attempt_programs_from_period'=>$attemptUpdates,
                'session_and_package_owners_assigned'=>0,'cutover_allowed'=>false];
        }catch(\Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }
}
