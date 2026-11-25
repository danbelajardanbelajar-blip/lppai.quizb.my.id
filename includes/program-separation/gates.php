<?php
declare(strict_types=1);
namespace Lppai\ProgramSeparation;
require_once __DIR__.'/capture.php';

/** Explicit maintenance gate closure. Never invokes exam engine, finalization or schema initializers. */
final class Gates
{
    public static function ids(string $text): array
    {
        if(!preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D',$text))throw new \InvalidArgumentException('Explicit positive session IDs required.');
        $ids=[];foreach(explode(',',$text)as$value){$id=Scope::positiveId($value);if(!$id)throw new \InvalidArgumentException('Session ID invalid.');$ids[]=$id;}
        if(count($ids)>50||count(array_unique($ids))!==count($ids))throw new \InvalidArgumentException('Duplicate or excessive session IDs.');
        sort($ids,SORT_NUMERIC);return $ids;
    }

    private static function sessions(\PDO $pdo,bool $lock=false): array
    {
        $rows=$pdo->query('SELECT * FROM pretes_cbt_sessions ORDER BY id'.($lock&&$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''))->fetchAll(\PDO::FETCH_ASSOC);
        foreach($rows as &$row){foreach($row as &$value)if($value!==null)$value=(string)$value;unset($value);ksort($row);}unset($row);
        return $rows;
    }

    public static function close(\PDO $pdo,array $ids,callable $saveBefore): array
    {
        if($ids!==self::ids(implode(',',$ids)))throw new \InvalidArgumentException('Session IDs must be validated and sorted.');
        if($pdo->inTransaction())throw new \RuntimeException('Gate closure requires its own transaction.');
        $pdo->beginTransaction();
        try{
            $before=self::sessions($pdo,true);$found=[];$flags=[];
            foreach($before as $row)if(in_array((int)$row['id'],$ids,true)){
                if(!array_key_exists('exam_open',$row)||!array_key_exists('is_active',$row))throw new \RuntimeException('Session schema incomplete.');
                $found[]=(int)$row['id'];$flags[]=['id'=>(int)$row['id'],'mode'=>$row['mode']??null,'is_active'=>$row['is_active'],'exam_open'=>$row['exam_open']];
            }
            sort($found,SORT_NUMERIC);if($found!==$ids)throw new \RuntimeException('Selected session no longer exists; no changes applied.');
            $protected=array_values(array_diff(Capture::tables($pdo),['pretes_cbt_sessions']));
            $integrity=Capture::fingerprint($pdo,$protected);
            $saveBefore(['status'=>'prepared_not_committed','session_ids'=>$ids,'flags_before'=>$flags,'protected_integrity_before'=>$integrity]);
            $pdo->prepare('UPDATE pretes_cbt_sessions SET exam_open=0,is_active=0 WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')')->execute($ids);
            $expected=$before;
            foreach($expected as &$row)if(in_array((int)$row['id'],$ids,true)){$row['is_active']='0';$row['exam_open']='0';}unset($row);
            if(self::sessions($pdo)!==$expected)throw new \RuntimeException('Unexpected session fields changed; gate transaction rolled back.');
            $afterIntegrity=Capture::fingerprint($pdo,$protected);
            if($integrity!==$afterIntegrity)throw new \RuntimeException('Protected academic data changed; gate transaction rolled back.');
            $snapshot=Capture::minimumSnapshot($pdo)['snapshot'];$review=Audit::inspect($snapshot);$groups=[];
            foreach($review['issues']as$issue)$groups[$issue['code'].' / '.$issue['table']][]= $issue['id'];
            $findings=[];foreach($groups as $key=>$values)$findings[$key]=['jumlah'=>count($values),'id'=>implode(',',$values)];
            $live=[];foreach(self::sessions($pdo)as$row)$live[]=['id'=>(int)$row['id'],'mode'=>$row['mode']??null,'is_active'=>(int)$row['is_active'],'exam_open'=>(int)$row['exam_open']];
            $programs=[];foreach($snapshot['tables']['users']??[]as$user)$programs[(int)$user['id']]=Scope::program($user['jenis_program']);
            $legacy=[];foreach($snapshot['tables']['tutorial_registrations']??[]as$registration){$program=$programs[(int)$registration['user_id']]??'unknown';$legacy[$program]=($legacy[$program]??0)+1;}
            $result=['status'=>'selected_pretes_gates_closed_no_migration','closed_session_ids'=>$ids,
                'protected_tables'=>count($protected),'protected_data_unchanged_in_transaction'=>true,
                'attempts_submitted_or_deleted_by_tool'=>0,'live_pretes_sessions'=>$live,
                'current_findings'=>$findings,'legacy_tutorial_registration_program_counts'=>$legacy,
                'restore_tested'=>false,'cutover_allowed'=>false];
            $pdo->commit();return $result;
        }catch(\Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }
}
