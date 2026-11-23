<?php
/** Stage 1: shared rules. Evidence must come from the server, never a student's POST. */
function tutorialRplRegistrationPolicy(): array
{
    return [
        'id' => 'rpl-2026-2027-gel1',
        'timezone' => 'Asia/Jakarta',
        'opens_at' => '2026-11-23 00:00:00',
        'closes_at' => '2026-11-27 23:59:59',
        'minimum_pretes_pass_score' => 80.0,
        'channels' => ['mandiri', 'admin'],
        'program' => 'RPL',
    ];
}

function tutorialRplRegistrationWindow(?int $now = null): string
{
    $policy = tutorialRplRegistrationPolicy();
    $tz = new DateTimeZone($policy['timezone']);
    $opens = (new DateTimeImmutable($policy['opens_at'], $tz))->getTimestamp();
    $closes = (new DateTimeImmutable($policy['closes_at'], $tz))->getTimestamp();
    $now ??= time();
    return $now < $opens ? 'soon' : ($now > $closes ? 'closed' : 'open');
}

/**
 * Read-only decision for both future registration handlers.
 * $pretes: null = no official result yet; otherwise server-validated evidence with
 * official, submitted, score. In-progress/tryout results never grant an exemption.
 * Database lookup failures must be handled as unavailable by the caller, not as null.
 */
function tutorialRplRegistrationDecision(array $student, array $actor, string $channel, ?array $pretes = null, ?int $now = null): array
{
    $policy = tutorialRplRegistrationPolicy();
    $window = tutorialRplRegistrationWindow($now);
    $result = ['allowed' => false, 'eligible' => false, 'exempt' => false, 'channel' => $channel, 'window' => $window];
    if (!in_array($channel, $policy['channels'], true)) return $result + ['reason' => 'invalid_channel'];
    if (($student['role'] ?? '') !== 'mahasiswa' || (int)($student['id'] ?? 0) < 1
        || strtoupper(trim((string)($student['jenis_program'] ?? ''))) !== 'RPL') {
        return $result + ['reason' => 'not_rpl_student'];
    }
    $authorized = $channel === 'admin'
        ? (($actor['role'] ?? '') === 'admin' && (int)($actor['id'] ?? 0) > 0)
        : (($actor['role'] ?? '') === 'mahasiswa' && (int)($actor['id'] ?? 0) === (int)$student['id']);
    if (!$authorized) return $result + ['reason' => 'forbidden'];
    if ($pretes && !empty($pretes['official']) && !empty($pretes['submitted'])) {
        $score = $pretes['score'] ?? null;
        if (!is_numeric($score) || !is_finite((float)$score) || (float)$score < 0 || (float)$score > 100) {
            return $result + ['reason' => 'invalid_pretes_result'];
        }
        if ((float)$score >= $policy['minimum_pretes_pass_score']) {
            return array_replace($result, ['exempt' => true, 'reason' => 'passed_pretes']);
        }
    }
    return array_replace($result, ['eligible' => true, 'allowed' => $window === 'open',
        'reason' => $window === 'open' ? 'eligible' : ($window === 'soon' ? 'not_open_yet' : 'registration_closed')]);
}
