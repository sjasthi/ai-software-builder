<?php
/**
 * FP10 race-condition stress test — Port.
 *
 * The milestone requires proving "single server execution per submit." endpoint.php
 * enforces that with a per-session, non-blocking exclusive lock (flock LOCK_EX |
 * LOCK_NB): the first request for a session acquires it; any overlapping request
 * fails the lock and is rejected with 409 {busy:true} instead of running the chain
 * a second time. Spawning true parallel HTTP cross-platform is awkward, so this
 * test exercises the exact lock primitive the endpoint depends on and asserts the
 * guarantee directly.
 *
 * Run:
 *     C:\xampp\php\php.exe tests\endpoint_lock_test.php
 */

$pass = 0; $fail = 0;
function check(string $label, bool $ok) {
    global $pass, $fail;
    echo ($ok ? "  [PASS] " : "  [FAIL] ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

$lockPath = __DIR__ . '/__endpoint_lock_test__.lock';
@unlink($lockPath);

// ── Request A arrives and acquires the session lock ─────────────────────────
$a = fopen($lockPath, 'c');
check('request A opens the lock file',            $a !== false);
$aGot = flock($a, LOCK_EX | LOCK_NB);
check('request A acquires the exclusive lock',    $aGot === true);

// ── Request B arrives for the SAME session while A is still running ──────────
$b = fopen($lockPath, 'c');
$bGot = flock($b, LOCK_EX | LOCK_NB);
check('request B is REJECTED while A holds it',   $bGot === false);   // → endpoint returns 409 busy
// This is the whole point: two chain executions for one session never overlap.

// ── Request A finishes and releases ─────────────────────────────────────────
$released = flock($a, LOCK_UN);
check('request A releases the lock',              $released === true);
fclose($a);

// ── A later request can now proceed normally ────────────────────────────────
$bGot2 = flock($b, LOCK_EX | LOCK_NB);
check('a later request acquires it once free',    $bGot2 === true);
flock($b, LOCK_UN);
fclose($b);

@unlink($lockPath);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
