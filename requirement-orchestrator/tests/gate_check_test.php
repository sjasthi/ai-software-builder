<?php
/**
 * Programmatic gate-check verification proof — Port (FP7).
 *
 * Proves RequirementParser::gate() correctly decides when the prompt chain may
 * trigger the Compiler Agent. The headline case is the Big Picture Plan's
 * Shopify scenario: the sentence
 *
 *     "I want to automatically pull my inventory from a Shopify API every morning"
 *
 * covers three domains at once (data_sources, data_access, interaction_model).
 * The gate must report that FIVE domains remain OPEN and must NOT trigger the
 * Compiler. Cox's live LLM extraction isn't wired up yet, so this test injects
 * that extraction result straight into Port's FP6 session store — exactly the
 * state RequirementParser::extract() will write once it exists. Run:
 *
 *     C:\xampp\php\php.exe tests\gate_check_test.php
 */
require_once __DIR__ . '/../src/InterviewSession.php';
require_once __DIR__ . '/../src/RequirementParser.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok) {
    global $pass, $fail;
    echo ($ok ? "  [PASS] " : "  [FAIL] ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

$dir = __DIR__ . '/../sessions';

// ── Shopify verification proof (through the real session store) ───────────────
// Simulate what Cox's extract() will do: mark the three domains the Shopify
// sentence covers, then persist via Port's FP6 writeDomainState().
$id   = InterviewSession::createSession('__gate_test__');
$file = $dir . '/' . $id . '.json';

InterviewSession::writeDomainState($id, [
    'data_sources'      => 'COVERED',
    'data_access'       => 'COVERED',
    'interaction_model' => 'COVERED',
]);

$gate = RequirementParser::gateForSession($id);

check('gate reads exactly 3 domains COVERED',        $gate['covered_count'] === 3);
check('gate reports 5 domains still OPEN',            count($gate['open_domains']) === 5);
check('the 5 OPEN domains are the right ones',
      $gate['open_domains'] === ['pain_points', 'end_result', 'stakeholders', 'audience_type', 'current_process']);
check('all_covered is false with domains outstanding', $gate['all_covered'] === false);
check('gate does NOT trigger the Compiler (INTERVIEW)', $gate['next_action'] === 'INTERVIEW');

// ── completion case: all 8 COVERED must trigger the Compiler ──────────────────
$all = [];
foreach (InterviewSession::DOMAINS as $d) { $all[$d] = 'COVERED'; }
InterviewSession::writeDomainState($id, $all);

$gate = RequirementParser::gateForSession($id);
check('gate reads all 8 COVERED',                    $gate['covered_count'] === 8);
check('all_covered is true when the interview is done', $gate['all_covered'] === true);
check('gate triggers the Compiler (COMPILE)',        $gate['next_action'] === 'COMPILE');

// ── pure-logic edge cases (no session needed) ─────────────────────────────────
$blank = RequirementParser::gate([]);
check('empty state → 0 COVERED, keep interviewing',
      $blank['covered_count'] === 0 && $blank['next_action'] === 'INTERVIEW');

$junk = RequirementParser::gate(['pain_points' => 'MAYBE', 'not_a_domain' => 'COVERED']);
check('non-COVERED values never count toward the gate', $junk['covered_count'] === 0);
check('unknown keys are ignored',                    $junk['total'] === 8);

// ── unknown session is not treated as ready-to-compile ────────────────────────
check('gateForSession returns null for an unknown id',
      RequirementParser::gateForSession('nope-does-not-exist') === null);

// ── teardown ──────────────────────────────────────────────────────────────────
@unlink($file);

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
