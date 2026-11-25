<?php
declare(strict_types=1);
require_once __DIR__.'/program-separation/scope.php';
use Lppai\ProgramSeparation\Scope;

/** Scoped SELECT queries only. Never calls CBT state/finalize or schema initializers. */
final class PretesProgramRepository
{
    public function __construct(private PDO $pdo,private Scope $scope)
    {
        if($scope->activity()!=='pretes')throw new InvalidArgumentException('Lingkup Pretes diperlukan.');
    }
    private function query(string $sql,array $params=[]): PDOStatement
    {
        $statement=$this->pdo->prepare($sql);$statement->execute($params);return $statement;
    }
    private function studentScope(): string {return "u.role='mahasiswa' AND LOWER(TRIM(u.jenis_program))=:scope_program";}
    private function params(): array {return [':scope_program'=>$this->scope->programKey()];}
    public function periods(): array
    {
        return $this->query("SELECT id,periode,tahun_ajaran,target_program,status,tanggal_mulai FROM master_pretes
            WHERE LOWER(TRIM(target_program))=:scope_program OR LOWER(TRIM(target_program))='semua' ORDER BY id DESC",$this->params())->fetchAll(PDO::FETCH_ASSOC);
    }
    public function periodName(?int $id): ?string
    {
        if($id===null)return null;
        $params=$this->params();$params[':period_id']=$id;
        $row=$this->query("SELECT periode FROM master_pretes WHERE id=:period_id AND (LOWER(TRIM(target_program))=:scope_program OR LOWER(TRIM(target_program))='semua')",$params)->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new OutOfBoundsException('Periode tidak tersedia untuk program ini.');
        $name=(string)$row['periode'];
        if((int)$this->query('SELECT COUNT(*) FROM master_pretes WHERE periode=?',[$name])->fetchColumn()!==1)throw new DomainException('Nama periode lama tidak unik. Pilih semua periode sampai hubungan arsip ditinjau.');
        return $name;
    }
    private function filters(string $search,?int $periodId,bool $results,string $kind): array
    {
        $where=[$this->studentScope()];$params=$this->params();$period=$this->periodName($periodId);
        if($period!==null){$where[]='pr.periode=:period_name';$params[':period_name']=$period;}
        if($search!==''){
            $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
            $where[]="(u.nama_lengkap LIKE :search_name ESCAPE '!' OR u.nim LIKE :search_nim ESCAPE '!')";
            $params[':search_name']=$term;$params[':search_nim']=$term;
        }
        if($results&&$kind!=='all')$where[]=$kind==='tryout'?'a.registration_id<0':'a.registration_id>0';
        return [implode(' AND ',$where),$params];
    }
    public function counts(): array
    {
        $where=$this->studentScope();$params=$this->params();
        return ['participants'=>(int)$this->query("SELECT COUNT(*) FROM pretes_registrations pr JOIN users u ON u.id=pr.user_id WHERE $where",$params)->fetchColumn(),
            'results'=>(int)$this->query("SELECT COUNT(*) FROM pretes_cbt_attempts a JOIN users u ON u.id=a.user_id WHERE $where",$params)->fetchColumn(),
            'tasks'=>(int)$this->query('SELECT COUNT(*) FROM pretes_components WHERE program=? AND is_active=1',[$this->scope->programKey()])->fetchColumn()];
    }
    /** Returns a cursor for bounded page rendering or streaming CSV; never selects passwords/photos. */
    public function listing(string $tab,string $search='',?int $periodId=null,string $kind='all',?int $page=1): array
    {
        if(!in_array($tab,['participants','results'],true)||!in_array($kind,['all','resmi','tryout'],true))throw new InvalidArgumentException('Filter tidak valid.');
        [$where,$params]=$this->filters($search,$periodId,$tab==='results',$kind);
        if($tab==='participants'){
            $from='FROM pretes_registrations pr JOIN users u ON u.id=pr.user_id';
            $select='pr.id,pr.user_id,pr.periode,pr.hari_pilihan,pr.jam,pr.dosen_penguji,pr.ruangan,pr.status,u.nama_lengkap,u.nim,u.program_studi';$order='pr.id DESC';
        }else{
            // Tryout IDs are synthetic: join by user as well, so a forged/mismatched FK cannot import another roster.
            $from='FROM pretes_cbt_attempts a JOIN users u ON u.id=a.user_id LEFT JOIN pretes_registrations pr ON pr.id=a.registration_id AND pr.user_id=a.user_id LEFT JOIN pretes_cbt_sessions s ON s.id=a.session_id';
            $select='a.id,a.user_id,a.registration_id,a.session_id,a.started_at,a.deadline_at,a.submitted_at,a.score,u.nama_lengkap,u.nim,u.program_studi,pr.periode,s.title AS session_title';$order='a.id DESC';
        }
        $count=(int)$this->query("SELECT COUNT(*) $from WHERE $where",$params)->fetchColumn();
        $limit='';
        if($page!==null){if($page<1||$page>100000)throw new InvalidArgumentException('Halaman tidak valid.');$limit=' LIMIT 25 OFFSET '.(($page-1)*25);}
        return ['count'=>$count,'cursor'=>$this->query("SELECT $select $from WHERE $where ORDER BY $order$limit",$params)];
    }
    public function tasks(): array
    {
        return $this->query('SELECT id,comp_key,label,bentuk,kategori,is_active,sort_order FROM pretes_components WHERE program=? ORDER BY sort_order,id',[$this->scope->programKey()])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function review(int $id): array
    {
        $params=$this->params();$params[':attempt_id']=$id;
        $attempt=$this->query('SELECT a.*,u.nama_lengkap,u.nim,u.program_studi,u.jenis_program FROM pretes_cbt_attempts a JOIN users u ON u.id=a.user_id WHERE a.id=:attempt_id AND '.$this->studentScope(),$params)->fetch(PDO::FETCH_ASSOC);
        if(!$attempt)throw new OutOfBoundsException('Lembar jawaban tidak tersedia untuk program ini.');
        $items=$this->query('SELECT position,question_id,question_text,options_json,correct_answer,answer,flagged FROM pretes_cbt_items WHERE attempt_id=? ORDER BY position',[$id])->fetchAll(PDO::FETCH_ASSOC);
        $session=empty($attempt['session_id'])?null:$this->query('SELECT title FROM pretes_cbt_sessions WHERE id=?',[$attempt['session_id']])->fetch(PDO::FETCH_ASSOC);
        return ['attempt'=>$attempt,'items'=>$items,'session'=>$session?:null];
    }
}
