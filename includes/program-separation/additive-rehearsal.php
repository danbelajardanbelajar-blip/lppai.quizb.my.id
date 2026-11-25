<?php
declare(strict_types=1);
namespace Lppai\ProgramSeparation;
require_once __DIR__.'/restore-verification.php';

/** Test-copy schema rehearsal only; no auth/CBT initialization or legacy value changes. */
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

    /** Inspect a failed rehearsal without ALTER/UPDATE, using the saved preimage. */
    public static function inspect(\PDO $pdo,array $plan): array
    {
        if(($plan['additions']??null)!==self::ADDITIONS || !is_array($plan['original_columns']??null) || !$plan['original_columns'] || !is_array($plan['original_fingerprint']??null))throw new \RuntimeException('Invalid rehearsal preimage.');
        if($pdo->inTransaction())throw new \RuntimeException('Inspection requires its own read-only transaction.');
        if($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'){
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET TRANSACTION READ ONLY');
        }
        $pdo->beginTransaction();
        try{
            $tables=Capture::tables($pdo);$schema=array_intersect_key($plan['original_columns'],array_flip($tables));
            $actual=self::projection($pdo,$schema);$different=[];
            foreach(array_unique(array_merge(array_keys($plan['original_fingerprint']),array_keys($actual))) as $table){
                if(($actual[$table]??null)!==($plan['original_fingerprint'][$table]??null))$different[]=$table;
            }
            $missing=array_values(array_diff(array_keys($plan['original_columns']),$tables));
            $extra=array_values(array_diff($tables,array_keys($plan['original_columns'])));
            $added=[];$auto=[];$newFieldsPopulated=[];
            foreach(self::ADDITIONS as $table=>$columns){
                if(!in_array($table,$tables,true))continue;
                $present=self::columns($pdo,$table);
                foreach($columns as $column=>$type){
                    if(!in_array($column,$present,true))continue;
                    $added[]=$table.'.'.$column;
                    $newFieldsPopulated[$table.'.'.$column]=(int)$pdo->query('SELECT COUNT(*) FROM '.Capture::identifier($table).' WHERE '.Capture::identifier($column).' IS NOT NULL')->fetchColumn();
                }
                if($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'){
                    foreach($pdo->query('SHOW COLUMNS FROM '.Capture::identifier($table))->fetchAll(\PDO::FETCH_ASSOC) as $column){
                        if(stripos((string)($column['Extra']??''),'on update')!==false)$auto[]=$table.'.'.$column['Field'];
                    }
                }
            }
            $pdo->commit();sort($different,SORT_STRING);
            return ['all_original_column_values_unchanged'=>!$different&&!$missing&&!$extra,
                'original_tables_verified'=>count($actual),'different_tables'=>$different,'missing_tables'=>$missing,'extra_tables'=>$extra,
                'table_order_matches_preimage'=>$tables===array_keys($plan['original_columns']),
                'added_columns_present'=>$added,'new_fields_nonnull_rows'=>$newFieldsPopulated,'automatic_update_columns'=>$auto,
                'database_modified_by_inspector'=>false,'cutover_allowed'=>false];
        }catch(\Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }

    public static function apply(\PDO $pdo,array $plan): array
    {
        // Detect changed input between journal preparation and DDL. No repair or deletion.
        if (($plan['additions']??null)!==self::ADDITIONS || self::prepare($pdo)!==$plan) throw new \RuntimeException('Rehearsal input changed before ALTER; stopped.');
        // MySQL ALTER commits implicitly. On failure added columns may remain; never promise DDL rollback.
        foreach (self::ADDITIONS as $table=>$columns) foreach ($columns as $name=>$type) {
            $pdo->exec('ALTER TABLE '.Capture::identifier($table).' ADD COLUMN '.Capture::identifier($name).' '.$type);
        }
        return self::backfill($pdo,$plan);
    }

    /** Resume only the empty six-column schema left after a rolled-back rehearsal. No DDL. */
    public static function resume(\PDO $pdo,array $plan): array
    {
        if($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql' && $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'")->fetchColumn()!==false)throw new \RuntimeException('All resume tables must remain InnoDB.');
        $inspection=self::inspect($pdo,$plan);
        if(!$inspection['all_original_column_values_unchanged'] || count($inspection['added_columns_present'])!==6 || array_sum($inspection['new_fields_nonnull_rows'])!==0)throw new \RuntimeException('Resume requires unchanged originals and all six new columns still NULL.');
        foreach($plan['original_columns'] as $table=>$oldColumns){
            $expected=array_merge($oldColumns,array_keys(self::ADDITIONS[$table]??[]));
            if(self::columns($pdo,$table)!==$expected)throw new \RuntimeException('Unexpected schema; resume stopped without ALTER.');
        }
        foreach(self::ADDITIONS as $table=>$additions){
            $sqlite=$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite';
            $metadata=$sqlite?$pdo->query('PRAGMA table_info('.Capture::identifier($table).')')->fetchAll(\PDO::FETCH_ASSOC):$pdo->query('SHOW COLUMNS FROM '.Capture::identifier($table))->fetchAll(\PDO::FETCH_ASSOC);
            foreach($metadata as $column){
                $name=$column[$sqlite?'name':'Field'];if(!isset($additions[$name]))continue;
                $type=strtolower((string)$column[$sqlite?'type':'Type']);
                $validType=$name==='periode_id'?(bool)preg_match('/^bigint(?:\(\d+\))?$/D',$type):$type==='varchar(16)';
                $nullable=$sqlite?(int)$column['notnull']===0:$column['Null']==='YES';
                if(!$validType||!$nullable||($column[$sqlite?'dflt_value':'Default']??null)!==null||(!$sqlite&&($column['Extra']??'')!==''))throw new \RuntimeException('Unexpected added-column definition; resume refused.');
            }
        }
        return self::backfill($pdo,$plan)+['resumed_existing_columns'=>true,'alter_statements_executed'=>0];
    }

    private static function freezeAutomaticTimes(\PDO $pdo,string $table): string
    {
        if($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql')return '';
        $sql='';
        foreach($pdo->query('SHOW COLUMNS FROM '.Capture::identifier($table))->fetchAll(\PDO::FETCH_ASSOC) as $column){
            if(stripos((string)($column['Extra']??''),'on update')!==false){
                $name=Capture::identifier($column['Field']);$sql.=', '.$name.'='.$name;
            }
        }
        return $sql;
    }

    private static function backfill(\PDO $pdo,array $plan): array
    {
        $pdo->beginTransaction();
        try {
            if(self::projection($pdo,$plan['original_columns'])!==$plan['original_fingerprint'])throw new \RuntimeException('Original data changed before metadata UPDATE; stopped.');
            foreach(self::ADDITIONS as $table=>$columns)foreach($columns as $column=>$type){
                if((int)$pdo->query('SELECT COUNT(*) FROM '.Capture::identifier($table).' WHERE '.Capture::identifier($column).' IS NOT NULL')->fetchColumn()!==0)throw new \RuntimeException('New metadata is already populated; do not repeat.');
            }
            $programs=[];$periodUpdates=0;$registrationUpdates=0;$attemptUpdates=0;$unresolved=0;$mixed=0;
            $updatePeriod=$pdo->prepare('UPDATE master_pretes SET program_scope=?'.self::freezeAutomaticTimes($pdo,'master_pretes').' WHERE id=?');
            foreach ($pdo->query('SELECT id,periode,target_program FROM master_pretes ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $period) {
                $id=(int)$period['id'];$programs[$id]=Scope::program($period['target_program']);
                if($programs[$id]!==null){$updatePeriod->execute([$programs[$id],$id]);$periodUpdates++;}else{$mixed++;}
            }
            $registrationPrograms=[];$registrationUsers=[];
            // Respect the database collation; case/accent variants may be ambiguous legacy labels.
            $findPeriod=$pdo->prepare('SELECT id FROM master_pretes WHERE TRIM(periode)'.($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?' COLLATE NOCASE':'').'=?');
            $updateRegistration=$pdo->prepare('UPDATE pretes_registrations SET periode_id=?,program_snapshot=?'.self::freezeAutomaticTimes($pdo,'pretes_registrations').' WHERE id=?');
            foreach ($pdo->query('SELECT id,user_id,periode FROM pretes_registrations ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $registration) {
                $id=(int)$registration['id'];$label=trim((string)$registration['periode']);
                $findPeriod->execute([$label]);$ids=$label!==''?$findPeriod->fetchAll(\PDO::FETCH_COLUMN):[];$findPeriod->closeCursor();
                $registrationUsers[$id]=(int)$registration['user_id'];$registrationPrograms[$id]=null;
                if(count($ids)!==1){$unresolved++;continue;}
                $periodId=$ids[0];$registrationPrograms[$id]=$programs[$periodId];
                $updateRegistration->execute([$periodId,$registrationPrograms[$id],$id]);$registrationUpdates++;
            }
            $updateAttempt=$pdo->prepare('UPDATE pretes_cbt_attempts SET program_snapshot=?'.self::freezeAutomaticTimes($pdo,'pretes_cbt_attempts').' WHERE id=?');
            foreach ($pdo->query('SELECT id,user_id,registration_id FROM pretes_cbt_attempts ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $attempt) {
                $reg=Audit::registrationNumber($attempt['registration_id']);
                // Negative tryout IDs, tester placeholders and mixed/unknown periods stay unassigned.
                if($reg===null || $reg<1 || ($registrationUsers[$reg]??null)!==(int)$attempt['user_id'] || ($registrationPrograms[$reg]??null)===null)continue;
                $updateAttempt->execute([$registrationPrograms[$reg],(int)$attempt['id']]);$attemptUpdates++;
            }
            $actual=self::projection($pdo,$plan['original_columns']);$different=[];
            foreach($plan['original_fingerprint'] as $table=>$fingerprint)if(($actual[$table]??null)!==$fingerprint)$different[]=$table;
            if(Capture::tables($pdo)!==array_keys($plan['original_columns']) || $different) throw new \RuntimeException('Original data changed during rehearsal (tables: '.implode(',',$different).'); metadata updates rolled back, added columns may remain.');
            $pdo->commit();
            return ['original_tables_verified'=>count($plan['original_columns']),'all_original_column_values_unchanged'=>true,
                'added_columns'=>array_sum(array_map('count',self::ADDITIONS)),'single_program_periods'=>$periodUpdates,
                'legacy_mixed_or_unknown_periods'=>$mixed,'registrations_linked_to_unique_period'=>$registrationUpdates,
                'registrations_without_unique_period'=>$unresolved,'official_attempt_programs_from_period'=>$attemptUpdates,
                'session_and_package_owners_assigned'=>0,'cutover_allowed'=>false];
        }catch(\Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }
}
