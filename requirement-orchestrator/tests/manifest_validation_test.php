<?php
/**
 * Compiler Agent verification proof — Port (FP9).
 *
 * The FP9 acceptance test: run the Compiler Agent (ManifestGenerator) on a fully
 * covered session and prove the output is a valid build plan —
 *   - all 5 labelled prompt sections present and non-empty,
 *   - zero conversational filler openings (regex scan),
 *   - zero unfilled [placeholders],
 *   - real, domain-specific content drawn from the user's actual answers.
 *
 * Runs offline and needs no MySQL: LLM_MOCK exercises the agent path through the
 * ScriptedLlm, and a null-returning stub client exercises the deterministic
 * template fallback. Both outputs must pass validation. Run:
 *
 *     C:\xampp\php\php.exe tests\manifest_validation_test.php
 */
putenv('LLM_MOCK=1');   // free, offline agent path — no key required

require_once __DIR__ . '/../src/InterviewSession.php';
require_once __DIR__ . '/../src/ManifestGenerator.php';
require_once __DIR__ . '/../src/ManifestValidator.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok) {
    global $pass, $fail;
    echo ($ok ? "  [PASS] " : "  [FAIL] ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

// ── fixture: a fully covered session with realistic, domain-specific answers ──
$answers = [
    'pain_points'       => 'Small retail shop loses about $400/week to overselling because stock counts are wrong.',
    'data_sources'      => 'The Shopify product catalog and a supplier price CSV.',
    'data_access'       => 'A nightly pull from the Shopify API plus a manual CSV upload.',
    'end_result'        => 'A daily reorder report listing items below their reorder threshold.',
    'stakeholders'      => 'The shop owner runs it and a part-time clerk reviews the report.',
    'audience_type'     => 'Non-technical everyday business users.',
    'current_process'   => 'Counting stock by hand in a spreadsheet every Sunday.',
    'interaction_model' => 'A scheduled automation that emails the report each morning.',
];

$id = InterviewSession::createSession('__manifest_test__');
foreach ($answers as $domain => $detail) {
    InterviewSession::writeDomainAnswer($id, $domain, $detail);
}
$file = __DIR__ . '/../sessions/' . $id . '.json';

// ── helper assertions shared by both generation paths ──────────────────────
function assertGoodPlan(string $path, array $plan): void
{
    check("$path: returns all 5 prompt sections",
          count(array_filter(['prompt_1','prompt_2','prompt_3','prompt_4','prompt_5'],
                fn($k) => isset($plan[$k]) && trim((string) $plan[$k]) !== '')) === 5);

    $result = ManifestValidator::validate($plan);
    check("$path: ManifestValidator reports the plan valid",
          $result['valid'] === true);
    if (!$result['valid']) {
        echo "         violations: " . implode('; ', $result['violations']) . "\n";
    }

    // Explicit filler scan (the literal FP9 deliverable), independent of the validator.
    $filler = false;
    foreach ($plan as $text) {
        foreach (ManifestValidator::FILLER_PATTERNS as $pat) {
            if (preg_match($pat, (string) $text)) { $filler = true; break 2; }
        }
    }
    check("$path: no conversational filler openings", $filler === false);

    $placeholder = false;
    foreach ($plan as $text) {
        if (preg_match('/\[[a-z_]+\]/', (string) $text)) { $placeholder = true; break; }
    }
    check("$path: no unfilled [placeholders]", $placeholder === false);

    // Domain-specific content: the user's real answers surface in the right prompts.
    check("$path: Prompt 1 reflects the pain point", stripos($plan['prompt_1'], 'overselling') !== false);
    check("$path: Prompt 2 names the data source (Shopify)", stripos($plan['prompt_2'], 'Shopify') !== false);
    check("$path: Prompt 3 reflects the end result (reorder)", stripos($plan['prompt_3'], 'reorder') !== false);
}

// ── path 1: the agent path (ScriptedLlm via LLM_MOCK) ──────────────────────
echo "Agent path (mock LLM):\n";
$agentPlan = (new ManifestGenerator())->generate($id);
assertGoodPlan('agent', $agentPlan);

// ── path 2: the deterministic template fallback (client returns null) ──────
echo "\nTemplate fallback (no LLM):\n";
$nullClient = new class implements LlmClient {
    public function complete(string $system, array $messages, array $opts = []): ?string { return null; }
};
$templatePlan = (new ManifestGenerator($nullClient))->generate($id);
assertGoodPlan('template', $templatePlan);

// ── path 3: the validator must REJECT a bad plan (negative control) ────────
echo "\nNegative control (bad plan must fail validation):\n";
$badPlan = [
    'prompt_1' => 'Sure, here is your build plan for the project you described.',   // filler
    'prompt_2' => 'Generate the database schema for [data_sources] and [data_access].', // placeholders
    'prompt_3' => '',                                                                // empty
    'prompt_4' => 'Build the UI.',                                                   // too short
    'prompt_5' => 'Connect all the layers together and write tests for every critical path.',
];
$bad = ManifestValidator::validate($badPlan);
check('bad plan is reported invalid',                 $bad['valid'] === false);
check('bad plan flags the filler opening',            (bool) count(array_filter($bad['violations'], fn($v) => str_contains($v, 'filler'))));
check('bad plan flags the unfilled placeholder',      (bool) count(array_filter($bad['violations'], fn($v) => str_contains($v, 'placeholder'))));
check('bad plan flags the empty section',             (bool) count(array_filter($bad['violations'], fn($v) => str_contains($v, 'empty'))));

// ── teardown ───────────────────────────────────────────────────────────────
@unlink($file);

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
