<?php
require_once __DIR__ . '/../includes/tutorial-rpl-registration-policy.php';
$failures = [];
$check = static function ($condition, $message) use (&$failures) { if (!$condition) $failures[] = $message; };
$tz = new DateTimeZone('Asia/Jakarta');
$at = static fn($date) => (new DateTimeImmutable($date, $tz))->getTimestamp();
$student = ['id'=>1,'role'=>'mahasiswa','jenis_program'=>'RPL'];
$admin = ['id'=>9,'role'=>'admin'];
$open = $at('2026-11-23 00:00:00');
$official = static fn($score) => ['official'=>true,'submitted'=>true,'score'=>$score];

$check(tutorialRplRegistrationWindow($at('2026-11-22 23:59:59')) === 'soon', 'Closed before Monday midnight WIB');
$check(tutorialRplRegistrationWindow($open) === 'open', 'Opens exactly Monday Nov 23 midnight WIB');
$check(tutorialRplRegistrationWindow($at('2026-11-27 23:59:59')) === 'open', 'Includes last second Friday Nov 27 WIB');
$check(tutorialRplRegistrationWindow($at('2026-11-28 00:00:00')) === 'closed', 'Closed from Saturday Nov 28 midnight WIB');
$check(tutorialRplRegistrationWindow((new DateTimeImmutable('2026-11-22 17:00:00', new DateTimeZone('UTC')))->getTimestamp()) === 'open', 'UTC conversion still opens at WIB midnight');

foreach (['mandiri'=>$student,'admin'=>$admin] as $channel=>$actor) {
    $decide = static fn($result, $now=null) => tutorialRplRegistrationDecision($student, $actor, $channel, $result, $now ?? $open);
    $check($decide(null)['allowed'], "$channel accepts a student who has never taken pretes");
    foreach ([0, 50, 79.99] as $score) $check($decide($official($score))['allowed'], "$channel accepts official score $score below 80");
    foreach ([80, 80.01, 100] as $score) {
        $result = $decide($official($score));
        $check(!$result['allowed'] && $result['exempt'] && $result['reason'] === 'passed_pretes', "$channel exempts passed official score $score");
    }
    $check($decide(['official'=>true,'submitted'=>false,'score'=>90])['allowed'], "$channel does not block pending pretes");
    $check($decide(['official'=>false,'submitted'=>true,'score'=>100])['allowed'], "$channel does not treat tryout as official passing");
    foreach ([null, 'invalid', -1, 101, INF, NAN] as $score) {
        $result = $decide($official($score));
        $check(!$result['allowed'] && $result['reason'] === 'invalid_pretes_result', "$channel rejects invalid completed result");
    }
    $check(!$decide(null,$at('2026-11-22 23:59:59'))['allowed'], "$channel cannot register before opening");
    $check(!$decide(null,$at('2026-11-28 00:00:00'))['allowed'], "$channel cannot register after closing");
}
$check(tutorialRplRegistrationDecision($student, ['id'=>2,'role'=>'mahasiswa'], 'mandiri', null, $open)['reason'] === 'forbidden', 'Student cannot register another student');
$check(tutorialRplRegistrationDecision($student, $student, 'admin', null, $open)['reason'] === 'forbidden', 'Student cannot use admin channel');
$check(tutorialRplRegistrationDecision($student, ['id'=>4,'role'=>'dosen'], 'admin', null, $open)['reason'] === 'forbidden', 'Dosen cannot use admin registration');
$check(tutorialRplRegistrationDecision(array_replace($student,['jenis_program'=>'Reguler']), $admin, 'admin', null, $open)['reason'] === 'not_rpl_student', 'Reguler is outside the RPL policy');
$check(tutorialRplRegistrationDecision($student, $admin, 'other', null, $open)['reason'] === 'invalid_channel', 'Unknown channel rejected');
ob_start(); include __DIR__ . '/../includes/tutorial-rpl-registration-rules-view.php'; $html = ob_get_clean();
foreach (['belum mengikuti pretes', 'belum lulus pretes', '80', '23 November 2026', '27 November 2026', 'mandiri', 'melalui admin'] as $text) {
    $check(str_contains($html,$text), 'Shared rules include '.$text);
}
if ($failures) { fwrite(STDERR, "FAIL:\n".implode("\n",$failures)."\n"); exit(1); }
echo "PASS: shared RPL policy, self/admin authorization, score 80 boundary, no-pretes/failed/pending/tryout, invalid evidence, WIB registration window and shared rules view.\n";
