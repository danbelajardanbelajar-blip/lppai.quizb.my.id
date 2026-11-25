<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/program-separation/test-credentials.php';
use Lppai\ProgramSeparation\TestCredentials;
// Synthetic configuration only; no application config or real password loaded.
define('DB_NAME','fixture_production');define('DB_USER','shared_fixture_user');define('DB_PASS','synthetic-fixture-only');
if(TestCredentials::password('quic1934_uji','shared_fixture_user',true)!==DB_PASS)throw new RuntimeException('Approved config credential selection failed.');
foreach([
    ['fixture_production','shared_fixture_user',true],
    ['FIXTURE_PRODUCTION','shared_fixture_user',true],
    ['fixture_production','shared_fixture_user',false],
    ['another_test','shared_fixture_user',true],
    ['quic1934_uji','another_user',true],
] as [$database,$user,$config]){
    try{TestCredentials::password($database,$user,$config);throw new LogicException('Unsafe credential reuse accepted.');}
    catch(RuntimeException $error){if(str_contains($error->getMessage(),DB_PASS))throw new LogicException('Password leaked in diagnostic.');}
}
echo "PASS: approved test DB reuses matching configured account without stdin; production/case variant/other database/other user rejected without password disclosure.\n";
