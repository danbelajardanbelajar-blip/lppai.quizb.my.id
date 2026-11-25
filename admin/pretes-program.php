<?php
require_once __DIR__.'/../includes/auth.php';
requireAdmin();
require_once __DIR__.'/../includes/pretes-program-repository.php';
use Lppai\ProgramSeparation\Scope;
$program=defined('PRETES_PORTAL_PROGRAM')?PRETES_PORTAL_PROGRAM:null;
if(!in_array($program,['rpl','reguler'],true)){http_response_code(404);echo 'Halaman tidak ditemukan.';return;}
$programLabel=$program==='rpl'?'RPL':'Reguler';
define('PAGE_TITLE','Pretes '.$programLabel);
$escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$base=BASE_URL.'/admin/pretes-'.$program.'.php';
$url=static fn(array $params)=>$base.($params?'?'.http_build_query($params):'');
try{
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');echo 'Halaman ini hanya untuk membaca data.';return;}
    if(isset($_GET['program'])&&$_GET['program']!==$program){http_response_code(403);echo 'Program permintaan tidak sesuai halaman.';return;}
    $tab=$_GET['tab']??'periods';$kind=$_GET['kind']??'all';
    if(!is_string($tab)||!in_array($tab,['periods','participants','results','tasks'],true)||!is_string($kind)||!in_array($kind,['all','resmi','tryout'],true))throw new InvalidArgumentException('Filter tidak valid.');
    $search=$_GET['q']??'';if(!is_string($search)||strlen($search)>200)throw new InvalidArgumentException('Pencarian maksimal 200 karakter.');$search=trim($search);
    $periodRaw=$_GET['period']??null;if($periodRaw==='')$periodRaw=null;
    $periodId=$periodRaw!==null?Scope::positiveId($periodRaw):null;
    $page=isset($_GET['page'])?Scope::positiveId($_GET['page']):1;
    if(($periodRaw!==null&&!$periodId)||!$page||$page>100000)throw new InvalidArgumentException('Periode atau halaman tidak valid.');
    $repo=new PretesProgramRepository(getDBConnection(),new Scope('pretes-'.$program));
    $repo->periodName($periodId);
    if(isset($_GET['review'])){
        $attemptId=Scope::positiveId($_GET['review']);if(!$attemptId)throw new InvalidArgumentException('ID review tidak valid.');
        $data=$repo->review($attemptId);$attempt=$data['attempt'];$items=$data['items'];$sessionInfo=$data['session'];$packageInfo=null;
        $backUrl=$url(['tab'=>'results','kind'=>$kind]);$reviewJenisKind=(int)$attempt['registration_id']<0?'tryout':'resmi';
        $reviewJenisLabel=$reviewJenisKind==='tryout'?'Tryout':'Resmi';$reviewPassingGrade=80;
        include __DIR__.'/../includes/cbt-attempt-review-view.php';return;
    }
    if(isset($_GET['download'])){
        if($_GET['download']!=='csv'||!in_array($tab,['participants','results'],true))throw new InvalidArgumentException('Ekspor tidak valid.');
        $data=$repo->listing($tab,$search,$periodId,$kind,null);
        header('Content-Type: text/csv; charset=UTF-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="pretes-'.$program.'-'.$tab.'.csv"');
        $output=fopen('php://output','w');fwrite($output,"\xEF\xBB\xBF");
        $columns=$tab==='participants'?['id','nim','nama_lengkap','program_studi','periode','hari_pilihan','jam','dosen_penguji','ruangan','status']:['id','nim','nama_lengkap','program_studi','registration_id','session_title','submitted_at','score'];
        fputcsv($output,$columns,',','"','');
        while($row=$data['cursor']->fetch(PDO::FETCH_ASSOC)){
            $cells=[];foreach($columns as $column){$value=(string)($row[$column]??'');if(preg_match('/^[=+@\-\t\r\n]/',$value))$value="'".$value;$cells[]=$value;}
            fputcsv($output,$cells,',','"','');
        }fclose($output);return;
    }
    $counts=$repo->counts();$periods=$repo->periods();$tasks=$tab==='tasks'?$repo->tasks():[];
    $listing=in_array($tab,['participants','results'],true)?$repo->listing($tab,$search,$periodId,$kind,$page):null;
    $rows=$listing?$listing['cursor']->fetchAll(PDO::FETCH_ASSOC):[];
}catch(InvalidArgumentException $e){http_response_code(400);echo $escape($e->getMessage());return;}
catch(OutOfBoundsException $e){http_response_code(404);echo $escape($e->getMessage());return;}
catch(DomainException $e){http_response_code(409);echo $escape($e->getMessage());return;}
catch(Throwable $e){error_log('Pretes program portal: '.$e->getMessage());http_response_code(503);echo 'Data Pretes belum dapat dimuat. Silakan hubungi pengelola.';return;}
include __DIR__.'/../includes/header.php';
include __DIR__.'/../includes/pretes-program-view.php';
include __DIR__.'/../includes/footer.php';
