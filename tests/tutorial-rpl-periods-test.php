<?php
require_once __DIR__ . '/../includes/tutorial-rpl-periods.php';
function checkPeriod($condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}
function rejectsPeriod(callable $operation, string $message): void {
    try { $operation(); } catch (InvalidArgumentException | PDOException | RuntimeException $e) { return; }
    throw new RuntimeException('FAIL: expected rejection: ' . $message);
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensureTutorialRplPeriodTable($pdo);
ensureTutorialRplPeriodTable($pdo);
$admin = ['id' => 7, 'role' => 'admin'];
$data = ['nama' => 'Tutorial RPL <2026>', 'gelombang' => 'Gelombang 1', 'opens_at' => '2026-11-23T00:00:00',
    'closes_at' => '2026-11-27T23:59:59', 'quota' => '250', 'active_slot' => '1'];
checkPeriod(tutorialRplActivePeriod($pdo) === null, 'fresh database has no implicitly active period');
checkPeriod(tutorialRplRegistrationWindow(null, tutorialRplPeriodPolicy(null)) === 'inactive', 'no fallback opening from old constants');
$id = tutorialRplSavePeriod($pdo, $data, $admin);
$row = tutorialRplActivePeriod($pdo);
checkPeriod((int)$row['id'] === $id && (int)$row['quota'] === 250, 'create and reload quota/active period');
checkPeriod((int)$row['created_by'] === 7 && (int)$row['updated_by'] === 7, 'record actor');
checkPeriod($row['opens_at'] === '2026-11-23 00:00:00', 'store local WIB without UTC shift');
$policy = tutorialRplPeriodPolicy($row);
$at = static fn($v) => (new DateTimeImmutable($v, new DateTimeZone('Asia/Jakarta')))->getTimestamp();
checkPeriod(tutorialRplRegistrationWindow($at('2026-11-22 23:59:59'), $policy) === 'soon', 'before opening');
checkPeriod(tutorialRplRegistrationWindow($at('2026-11-23 00:00:00'), $policy) === 'open', 'inclusive start');
checkPeriod(tutorialRplRegistrationWindow($at('2026-11-27 23:59:59'), $policy) === 'open', 'inclusive final second');
checkPeriod(tutorialRplRegistrationWindow($at('2026-11-28 00:00:00'), $policy) === 'closed', 'after closing');
rejectsPeriod(fn() => tutorialRplSavePeriod($pdo, $data, $admin), 'second active period');
foreach ([['nama'=>''], ['nama'=>str_repeat('a',101)], ['gelombang'=>''], ['opens_at'=>'2026-02-30T00:00:00'],
    ['closes_at'=>'2026-11-22T00:00:00'], ['closes_at'=>$data['opens_at']], ['quota'=>'-1'], ['quota'=>'1.5'], ['quota'=>'1000001'], ['quota'=>[]], ['id'=>'garbage']] as $invalid) {
    rejectsPeriod(fn() => tutorialRplSavePeriod($pdo, array_replace($data, $invalid), $admin), 'invalid input');
}
rejectsPeriod(fn() => tutorialRplSavePeriod($pdo, $data, ['id'=>1,'role'=>'mahasiswa']), 'student cannot create periods');
$update = array_replace($row, ['active_slot'=>null, 'nama'=>'Periode nonaktif']);
tutorialRplSavePeriod($pdo, $update, $admin);
checkPeriod(tutorialRplActivePeriod($pdo) === null, 'deactivation retained in history');
rejectsPeriod(fn() => tutorialRplSavePeriod($pdo, $update, $admin), 'stale edit must not overwrite newer version');
$second = tutorialRplSavePeriod($pdo, array_replace($data, ['nama'=>'Periode berikutnya','quota'=>'0']), $admin);
checkPeriod((int)tutorialRplActivePeriod($pdo)['id'] === $second, 'can activate another after deactivation');
rejectsPeriod(fn() => $pdo->exec('UPDATE tutorial_rpl_registration_periods SET active_slot=1'), 'unique index prevents concurrent dual activation');
checkPeriod((int)$pdo->query('SELECT COUNT(*) FROM tutorial_rpl_registration_periods')->fetchColumn() === 2, 'no rows lost after rejected saves');
$student = ['id'=>2,'role'=>'mahasiswa','jenis_program'=>'RPL'];
foreach (['mandiri'=>$student, 'admin'=>$admin] as $channel=>$actor) {
    checkPeriod(!tutorialRplRegistrationDecision($student,$actor,$channel,null,$at('2026-11-24'),tutorialRplPeriodPolicy(null))['allowed'], 'no active period blocks both channels');
    checkPeriod(tutorialRplRegistrationDecision($student,$actor,$channel,null,$at('2026-11-24'),tutorialRplPeriodPolicy(tutorialRplActivePeriod($pdo)))['allowed'], 'configured period used for both channels');
}
$rplRegistrationPolicy = tutorialRplPeriodPolicy(tutorialRplActivePeriod($pdo));
ob_start(); include __DIR__ . '/../includes/tutorial-rpl-registration-rules-view.php'; $html = ob_get_clean();
checkPeriod(str_contains($html, '23/11/2026 00:00:00') && str_contains($html, '27/11/2026 23:59:59'), 'shared view uses configured dates');

// Execute the actual admin handler in isolated processes with an SQLite database and auth fixtures.
$fixture = sys_get_temp_dir() . '/rpl-period-http-' . bin2hex(random_bytes(6));
mkdir($fixture . '/admin', 0777, true); mkdir($fixture . '/includes', 0777, true); mkdir($fixture . '/pages', 0777, true);
foreach (['admin/tutorial-rpl-periode.php','pages/tutorial-rpl.php','includes/tutorial-rpl-periods.php','includes/tutorial-rpl-registration-policy.php','includes/tutorial-rpl-registration-rules-view.php'] as $file) copy(__DIR__ . '/../' . $file, $fixture . '/' . $file);
file_put_contents($fixture . '/includes/header.php', '<!doctype html><html><body>');
file_put_contents($fixture . '/includes/footer.php', '</body></html>');
file_put_contents($fixture . '/includes/auth.php', <<<'PHP'
<?php
define('BASE_URL', '');
function requireAdmin() { if (($GLOBALS['request']['role'] ?? 'admin') !== 'admin') { http_response_code(403); exit; } }
function requireLogin() {}
function getCurrentUser() { return ['id'=>7,'role'=>'admin']; }
function getDBConnection() { return new PDO('sqlite:' . __DIR__ . '/../test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
function csrfToken() { return 'valid-token'; }
function verifyCsrf($token) { return $token === 'valid-token'; }
PHP
);
file_put_contents($fixture . '/request.php', <<<'PHP'
<?php
$request=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
$_POST=$request['post'] ?? []; $_GET=$request['get'] ?? []; $_SESSION=[];
$_SERVER['REQUEST_METHOD']=$request['method'] ?? 'GET';
http_response_code(200); ob_start();
register_shutdown_function(function(){ $html=ob_get_clean(); echo json_encode(['status'=>http_response_code(),'html'=>$html]); });
include __DIR__ . (($request['page'] ?? '') === 'student' ? '/pages/tutorial-rpl.php' : '/admin/tutorial-rpl-periode.php');
PHP
);
$request = static function(array $payload) use ($fixture): array {
    $process = proc_open([PHP_BINARY, $fixture . '/request.php', json_encode($payload)], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process);
    $expectedLog = !empty($payload['expect_unavailable']) && str_contains($errors, 'RPL registration schedule: SQLSTATE')
        && !preg_match('/Warning|Fatal error|Parse error/', $errors);
    checkPeriod($code===0 && ($errors==='' || $expectedLog), 'handler has no unexpected PHP errors: '.$errors);
    return json_decode($output,true,512,JSON_THROW_ON_ERROR);
};
try {
    $get=$request([]);
    checkPeriod($get['status']===200 && str_contains($get['html'],'csrf_token'), 'admin GET renders form and CSRF token');
    $invalid=$request(['method'=>'POST','post'=>$data]);
    checkPeriod($invalid['status']===403 && str_contains($invalid['html'],'kedaluwarsa'), 'missing CSRF rejected');
    $db=new PDO('sqlite:'.$fixture.'/test.sqlite');
    checkPeriod((int)$db->query('SELECT COUNT(*) FROM tutorial_rpl_registration_periods')->fetchColumn()===0,'CSRF failure writes nothing');
    checkPeriod($request(['method'=>'POST','role'=>'mahasiswa','post'=>$data+['csrf_token'=>'valid-token']])['status']===403,'nonadmin rejected by page guard');
    checkPeriod((int)$db->query('SELECT COUNT(*) FROM tutorial_rpl_registration_periods')->fetchColumn()===0,'nonadmin writes nothing');
    checkPeriod($request(['method'=>'POST','post'=>$data+['csrf_token'=>'valid-token']])['status']===302,'valid POST redirects');
    checkPeriod((int)$db->query('SELECT COUNT(*) FROM tutorial_rpl_registration_periods')->fetchColumn()===1,'valid POST persisted');
    $edit=$request(['get'=>['edit'=>1]]);
    checkPeriod(str_contains($edit['html'],'Tutorial RPL &lt;2026&gt;') && !str_contains($edit['html'],'Tutorial RPL <2026>'),'form and history escape names');
    checkPeriod(str_contains($edit['html'],'name="version" value="1"'),'edit carries concurrency version');
    checkPeriod($request(['get'=>['edit'=>999]])['status']===404,'missing edit returns 404');
    $bad=$request(['method'=>'POST','post'=>array_replace($data,['quota'=>[],'csrf_token'=>'valid-token'])]);
    checkPeriod(str_contains($bad['html'],'Format data periode tidak valid'),'malformed arrays do not crash rendering');
    $db->exec("UPDATE tutorial_rpl_registration_periods SET opens_at='2060-01-01 00:00:00', closes_at='2060-01-10 23:59:59'");
    $studentPage=$request(['page'=>'student']);
    checkPeriod($studentPage['status']===200 && str_contains($studentPage['html'],'01/01/2060 00:00:00') && str_contains($studentPage['html'],'belum dibuka'),'student page uses configured future schedule');
    checkPeriod(!str_contains($studentPage['html'],'23 sampai 27 November 2026') && !str_contains($studentPage['html'],'href="/tutorial-pendaftaran.php"'),'no obsolete hardcoded dates or generic tutorial form link');
    $db->exec('UPDATE tutorial_rpl_registration_periods SET active_slot=NULL');
    checkPeriod(str_contains($request(['page'=>'student'])['html'],'Belum ada periode aktif'),'student page does not fall back when all periods inactive');
    $db->exec('DROP TABLE tutorial_rpl_registration_periods');
    $unavailable=$request(['page'=>'student','expect_unavailable'=>true]);
    checkPeriod(str_contains($unavailable['html'],'Jadwal belum dapat dimuat'),'database failure does not imply registration is open');
    $db=null;
} finally {
    $db=null;
    foreach (glob($fixture.'/admin/*') as $file) unlink($file);
    foreach (glob($fixture.'/includes/*') as $file) unlink($file);
    foreach (glob($fixture.'/pages/*') as $file) unlink($file);
    foreach (glob($fixture.'/*') as $file) if (is_file($file)) unlink($file);
    rmdir($fixture.'/admin'); rmdir($fixture.'/includes'); rmdir($fixture.'/pages'); rmdir($fixture);
}
echo "PASS: RPL period persistence, dates, quota, single active constraint, stale edits, authorization, CSRF and actual admin handler.\n";
