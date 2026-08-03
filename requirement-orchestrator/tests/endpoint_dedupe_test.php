<?php
/**
 * Duplicate-submission (idempotency) test.
 *
 * The companion endpoint_lock_test.php proves the flock stops requests that
 * OVERLAP — and its final assertion, "a later request acquires it once free," is
 * exactly the hole this covers. Under a single PHP worker a spam-clicked Send
 * arrives serialized, so each duplicate got the lock cleanly and re-ran the whole
 * chain: N copies of one answer in the transcript and N rounds of LLM billing.
 *
 * endpoint.php now records a receipt per submission and replays it instead. This
 * exercises that store directly, plus the per-domain turn counter that replaced
 * the transcript-slice loop breaker.
 *
 * Run:
 *     C:\xampp\php\php.exe tests\endpoint_dedupe_test.php
 */
require_once __DIR__ . '/../src/InterviewSession.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok) {
    global $pass, $fail;
    echo ($ok ? "  [PASS] " : "  [FAIL] ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

$id = InterviewSession::createSession('dedupe test');

// ── A submission with a client id replays exactly once recorded ──────────────
$key     = 'cid:11111111-2222-3333-4444-555555555555';
$payload = ['ok' => true, 'done' => false, 'agent_message' => 'first answer'];

check('an unseen submission is not a replay',
    InterviewSession::findProcessedResult($id, $key) === null);

InterviewSession::recordProcessedResult($id, $key, $payload);
$replay = InterviewSession::findProcessedResult($id, $key);

check('a repeat of the same submission replays',            is_array($replay));
check('the replay is byte-identical to the first response', $replay === $payload);
check('a different submission still runs',
    InterviewSession::findProcessedResult($id, 'cid:other') === null);

// ── Content-hash receipts expire; client-id receipts do not ──────────────────
// A user may legitimately answer "yes" twice, so the no-id fallback is time-bound.
$hashKey = 'msg:' . sha1('yes');
InterviewSession::recordProcessedResult($id, $hashKey, ['ok' => true]);
check('a fresh content hash replays inside the window',
    InterviewSession::findProcessedResult($id, $hashKey, true) !== null);

// Age the receipt past the window by rewriting its timestamp on disk.
$path = __DIR__ . '/../sessions/' . $id . '.json';
$data = json_decode(file_get_contents($path), true);
$data['processed_messages'][$hashKey]['ts'] =
    gmdate('c', time() - (InterviewSession::DEDUPE_WINDOW_SECONDS + 60));
file_put_contents($path, json_encode($data));

check('a stale content hash does NOT replay',
    InterviewSession::findProcessedResult($id, $hashKey, true) === null);
check('a client id is exempt from expiry',
    InterviewSession::findProcessedResult($id, $key) !== null);

// ── Receipts are capped so a long session's file cannot grow without bound ───
for ($i = 0; $i < InterviewSession::DEDUPE_KEEP + 10; $i++) {
    InterviewSession::recordProcessedResult($id, 'cid:bulk-' . $i, ['ok' => true, 'n' => $i]);
}
$stored = json_decode(file_get_contents($path), true)['processed_messages'];
check('receipts are capped at DEDUPE_KEEP',
    count($stored) === InterviewSession::DEDUPE_KEEP);
check('the cap evicts oldest first, keeping the newest receipt',
    isset($stored['cid:bulk-' . (InterviewSession::DEDUPE_KEEP + 9)]));

// ── Per-domain turn counter (the loop breaker the Orchestrator now uses) ─────
// The old guard counted agent turns in the last 12 transcript entries, which read
// 0 whenever several user messages landed in a row — disabling itself during the
// exact pile-up it existed to stop. The counter is immune to transcript shape.
check('a never-active domain starts at zero',
    InterviewSession::readDomainTurns($id, 'pain_points') === 0);

for ($i = 0; $i < 12; $i++) { InterviewSession::writeExchange($id, 'user', 'dupe'); }
check('consecutive user messages do not affect the count',
    InterviewSession::readDomainTurns($id, 'pain_points') === 0);

$n = 0;
for ($i = 0; $i < 5; $i++) { $n = InterviewSession::bumpDomainTurns($id, 'pain_points'); }
check('five turns on a domain reaches the force-advance cap',
    $n === 5 && $n >= Orchestrator_MAX_DOMAIN_TURNS());
check('turns are tracked per domain, not globally',
    InterviewSession::readDomainTurns($id, 'data_sources') === 0);

InterviewSession::resetDomainTurns($id, 'pain_points');
check('closing a domain clears its counter',
    InterviewSession::readDomainTurns($id, 'pain_points') === 0);

InterviewSession::deleteSession($id);

/** Read the cap without pulling in the Orchestrator's agent/LLM dependencies. */
function Orchestrator_MAX_DOMAIN_TURNS(): int
{
    preg_match('/MAX_DOMAIN_TURNS\s*=\s*(\d+)/', file_get_contents(__DIR__ . '/../src/Orchestrator.php'), $m);
    return (int) ($m[1] ?? 0);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
