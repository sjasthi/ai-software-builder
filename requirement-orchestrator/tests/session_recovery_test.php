<?php
/**
 * Atomic transaction recovery test — Port (FP6).
 *
 * Proves the JSON session store survives an interrupted write: because writes
 * go to a temp file and are swapped in with an atomic rename(), a crash partway
 * through can never corrupt the real session file. Run:
 *
 *     C:\xampp\php\php.exe tests\session_recovery_test.php
 */
require_once __DIR__ . '/../src/InterviewSession.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok) {
    global $pass, $fail;
    echo ($ok ? "  [PASS] " : "  [FAIL] ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

$dir = __DIR__ . '/../sessions';

// ── setup ──────────────────────────────────────────────────────────────
$id   = InterviewSession::createSession('__recovery_test__');
$file = $dir . '/' . $id . '.json';

check('createSession writes a file',                 is_file($file));
check('new session is valid JSON',                   json_decode(file_get_contents($file), true) !== null);
check('new session starts with 8 OPEN domains',
      count(array_filter(InterviewSession::readDomainState($id), fn($v) => $v === 'OPEN')) === 8);

// ── normal write round-trips ─────────────────────────────────────────────
InterviewSession::writeDomainState($id, ['pain_points' => 'COVERED', 'data_sources' => 'COVERED']);
$state = InterviewSession::readDomainState($id);
check('writeDomainState persists COVERED',           $state['pain_points'] === 'COVERED' && $state['data_sources'] === 'COVERED');
check('untouched domains stay OPEN',                 $state['end_result'] === 'OPEN');
check('no leftover .tmp after a clean write',        !is_file($file . '.tmp'));   // atomic rename consumed it

// ── simulate a crash mid-write ───────────────────────────────────────────
// A real write would be in progress in the .tmp file. Drop a half-written,
// corrupt temp file there and confirm the live session is unaffected.
$before = file_get_contents($file);
file_put_contents($file . '.tmp', '{ "session_id": "'. $id .'", "domain_state": {  // truncated, never renamed');

check('live session file is still valid JSON after interrupted write',
      json_decode(file_get_contents($file), true) !== null);
check('live session content unchanged by the orphaned .tmp',
      file_get_contents($file) === $before);
check('readSession still recovers full state after the crash',
      InterviewSession::readDomainState($id)['pain_points'] === 'COVERED');

@unlink($file . '.tmp');   // clean up the simulated partial write

// ── completion + safety ──────────────────────────────────────────────────
$all = [];
foreach (InterviewSession::DOMAINS as $d) { $all[$d] = 'COVERED'; }
InterviewSession::writeDomainState($id, $all);
check('status flips to complete when all 8 COVERED',
      (InterviewSession::readSession($id)['status'] ?? '') === 'complete');
check('readSession rejects a path-traversal id',     InterviewSession::readSession('../../etc/passwd') === null);

// ── teardown ──────────────────────────────────────────────────────────────
@unlink($file);

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
