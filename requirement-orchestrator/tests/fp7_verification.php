<?php
/**
 * FP7 Combined Verification Proof
 *
 * Cox  — Extraction Agent: real LLM call through RequirementParser::extract().
 *         Proves the Shopify sentence maps to the correct 3 domains in one pass.
 *
 * Port — Programmatic gate check: RequirementParser::gate() / gateForSession().
 *         Proves the gate fires only when all 8 domains are COVERED.
 *
 * Run:
 *     C:\xampp\php\php.exe tests\fp7_verification.php
 */

require_once __DIR__ . '/../src/InterviewSession.php';
require_once __DIR__ . '/../src/RequirementParser.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $label . "\n";
    $ok ? $pass++ : $fail++;
}

// ══════════════════════════════════════════════════════════════════════════════
//  COX — Extraction Agent: live LLM call (Big Picture Plan §3, Week 3 proof)
// ══════════════════════════════════════════════════════════════════════════════
echo "\n── Cox: Extraction Agent (LLM call) ─────────────────────────────────────\n";

$shopifySentence = 'I want to automatically pull my inventory from a Shopify API every morning';

$sessionId = InterviewSession::createSession('__fp7_extraction_test__');

$parser = new RequirementParser();
$result = $parser->extract($sessionId, $shopifySentence);

check('extract() returns a non-null array',                      is_array($result));

if (is_array($result)) {
    check('data_sources    → COVERED (Shopify API is the source)', $result['data_sources']      === 'COVERED');
    check('data_access     → COVERED (pull from API)',             $result['data_access']       === 'COVERED');
    check('interaction_model → COVERED (every morning = scheduled)', $result['interaction_model'] === 'COVERED');

    $expectedOpen = ['pain_points', 'end_result', 'stakeholders', 'audience_type', 'current_process'];
    $actualOpen   = array_keys(array_filter($result, fn($v) => $v === 'OPEN'));
    sort($expectedOpen);
    sort($actualOpen);
    check('exactly 5 domains remain OPEN',                         count($actualOpen) === 5);
    check('the 5 OPEN domains are the correct ones',               $actualOpen === $expectedOpen);

    check('allCovered() is false — gate must NOT fire',            RequirementParser::allCovered($result) === false);
}

// Clean up extraction test session
@unlink(__DIR__ . '/../sessions/' . $sessionId . '.json');

// ══════════════════════════════════════════════════════════════════════════════
//  PORT — Programmatic Gate Check (gate() + gateForSession())
// ══════════════════════════════════════════════════════════════════════════════
echo "\n── Port: Programmatic Gate Check ────────────────────────────────────────\n";

// Shopify scenario through the session store (simulates what extract() writes)
$gateId   = InterviewSession::createSession('__fp7_gate_test__');
$gateFile = __DIR__ . '/../sessions/' . $gateId . '.json';

InterviewSession::writeDomainState($gateId, [
    'data_sources'      => 'COVERED',
    'data_access'       => 'COVERED',
    'interaction_model' => 'COVERED',
]);

$gate = RequirementParser::gateForSession($gateId);

check('gate reads exactly 3 domains COVERED',                     $gate['covered_count'] === 3);
check('gate reports 5 domains still OPEN',                        count($gate['open_domains']) === 5);

$expectedOpenGate = ['pain_points', 'end_result', 'stakeholders', 'audience_type', 'current_process'];
sort($expectedOpenGate);
$actualOpenGate = $gate['open_domains'];
sort($actualOpenGate);
check('the 5 OPEN domains are the right ones',                    $actualOpenGate === $expectedOpenGate);
check('all_covered is false with domains outstanding',            $gate['all_covered'] === false);
check('gate does NOT trigger Compiler (next_action = INTERVIEW)', $gate['next_action'] === 'INTERVIEW');

// All-8-COVERED case must flip to COMPILE
$all = [];
foreach (InterviewSession::DOMAINS as $d) { $all[$d] = 'COVERED'; }
InterviewSession::writeDomainState($gateId, $all);

$gate = RequirementParser::gateForSession($gateId);
check('gate reads all 8 COVERED',                                 $gate['covered_count'] === 8);
check('all_covered is true when interview is complete',           $gate['all_covered'] === true);
check('gate triggers Compiler (next_action = COMPILE)',           $gate['next_action'] === 'COMPILE');

// Pure-logic edge cases (no session I/O)
$blank = RequirementParser::gate([]);
check('empty state → 0 COVERED, keep interviewing',
      $blank['covered_count'] === 0 && $blank['next_action'] === 'INTERVIEW');

$junk = RequirementParser::gate(['pain_points' => 'MAYBE', 'not_a_domain' => 'COVERED']);
check('non-COVERED values never count toward the gate',           $junk['covered_count'] === 0);
check('unknown keys are ignored (total stays 8)',                  $junk['total'] === 8);

// Unknown session must return null, not a blank gate result
check('gateForSession returns null for an unknown session id',
      RequirementParser::gateForSession('nope-does-not-exist') === null);

// Teardown
@unlink($gateFile);

// ══════════════════════════════════════════════════════════════════════════════
echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
