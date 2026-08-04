<?php
/**
 * validate_manifest.php — STAGE 1 VALIDATOR (FP-Final, Cox).
 *
 * Big Picture Plan §5 Week 8, Stage 1: "After the Compiler Agent generates the
 * build plan, run validate_manifest.php against the output. This script confirms
 * all 5 prompt sections are present, each is populated with non-empty
 * domain-specific content, and no filler patterns are detected. The system passes
 * Stage 1 when the validator returns ZERO VIOLATIONS."
 *
 * This is the runnable harness, not the rule engine. The rules live in Port's
 * src/ManifestValidator.php (FP9) and are reused verbatim, so the runtime, the
 * FP9 test, and this script can never disagree about what "valid" means. What is
 * added here is everything the pure rule engine cannot see: locating a plan
 * (session store, downloaded export, or stdin), normalising the shape it is
 * stored in, reporting per-section, and returning an exit code a grader or a CI
 * step can act on.
 *
 * Usage:
 *   php validate_manifest.php <session-id>          validate one saved session
 *   php validate_manifest.php --all                 validate every complete session
 *   php validate_manifest.php --file <plan.json>    validate a downloaded export
 *   php validate_manifest.php --file -              read that export from stdin
 *   php validate_manifest.php --help
 *
 * --file accepts all three formats the right panel downloads (.json, .md, .txt),
 * because those are the artifacts a plan actually travels in.
 *
 * Exit codes (Stage 1 has to be machine-checkable, not eyeballed):
 *   0  every plan checked passed with zero violations
 *   1  at least one plan has violations
 *   2  usage error, session not found, or nothing to validate
 */

if (PHP_SAPI !== 'cli') {
    // A validator prints users' requirement content verbatim. It has no business
    // being reachable over HTTP, even if the project is dropped into htdocs.
    http_response_code(403);
    exit("validate_manifest.php is a CLI tool.\n");
}

require_once __DIR__ . '/src/InterviewSession.php';
require_once __DIR__ . '/src/ManifestValidator.php';

/** Exit codes, named so the intent is readable at each call site. */
const EXIT_PASS  = 0;
const EXIT_FAIL  = 1;
const EXIT_USAGE = 2;

/**
 * The 5 sections, in order (Big Picture Plan §3c). Mirrors the labels the right
 * panel renders in public/partials/build_plan.php — kept here as plain data so
 * the CLI never has to load a view partial.
 */
const SECTION_LABELS = [
    1 => 'Project Initialization',
    2 => 'Data Layer',
    3 => 'Core Feature Build',
    4 => 'UI Construction',
    5 => 'Integration & Testing',
];

exit(main(array_slice($argv, 1)));

// ─────────────────────────────── entry point ───────────────────────────────

function main(array $args): int
{
    $mode = $args[0] ?? '';

    if ($mode === '' || $mode === '--help' || $mode === '-h') {
        usage();
        return $mode === '' ? EXIT_USAGE : EXIT_PASS;
    }

    if ($mode === '--all')  { return validateAllSessions(); }
    if ($mode === '--file') { return validateFile($args[1] ?? ''); }

    if (str_starts_with($mode, '-')) {
        fwrite(STDERR, "Unknown option: {$mode}\n\n");
        usage();
        return EXIT_USAGE;
    }

    return validateSession($mode) ? EXIT_PASS : EXIT_FAIL;
}

function usage(): void
{
    echo <<<TXT
    Stage 1 validator — checks a compiled build plan against the FP9 rules:
    5 sections present, non-empty, substantive, no filler openings, no
    unfilled [placeholders].

      php validate_manifest.php <session-id>        validate one saved session
      php validate_manifest.php --all              validate every complete session
      php validate_manifest.php --file <plan.json> validate a downloaded .json export
      php validate_manifest.php --file -           read that JSON from stdin

    Exit codes: 0 = zero violations, 1 = violations found, 2 = usage error.

    TXT;
}

// ───────────────────────────── the three modes ─────────────────────────────

/** Validate one saved session. Returns true when it passed. */
function validateSession(string $id): bool
{
    $session = InterviewSession::readSession($id);
    if ($session === null) {
        fwrite(STDERR, "Session not found: {$id}\n");
        exit(EXIT_USAGE);   // not a validation failure — nothing was validated
    }

    $title  = (string) ($session['title'] ?? 'Untitled session');
    $status = (string) ($session['status'] ?? 'in_progress');
    echo "Session {$id} — \"{$title}\"\n";

    $stored = $session['build_plan'] ?? null;
    $plan   = normalizePlan($stored);
    if ($plan === null) {
        // Failing to produce a plan is a genuine Stage 1 failure — the pipeline
        // was supposed to. Say WHICH failure it is: an interview that has not
        // finished, a finished one the Compiler left empty, and a plan stored in
        // a shape this tool cannot read are three different problems.
        $empty = $stored === null || $stored === '' || $stored === [];
        echo '  [FAIL] ' . ($empty ? 'no build plan on this session' : 'build plan is in an unrecognized format')
            . " (status: {$status})\n";
        if (!$empty) {
            echo "  Stored as " . gettype($stored) . ", with no readable prompt sections.\n";
        } elseif ($status === 'complete') {
            echo "  The session is complete but the Compiler Agent left no plan.\n";
        } else {
            echo "  The interview is still in progress — the Compiler Agent has not run yet.\n";
        }
        echo "\n  1 violation\n";
        return false;
    }

    $passed = report($plan);
    reportCoverage($id);
    return $passed;
}

/** Validate every complete session in the store. Returns the process exit code. */
function validateAllSessions(): int
{
    $complete = array_values(array_filter(
        InterviewSession::listSessions(),
        fn(array $s) => ($s['status'] ?? '') === 'complete'
    ));

    if ($complete === []) {
        // Deliberately NOT a pass. An empty run must never be mistaken for
        // "Stage 1 passed" during a demo — there was nothing to validate.
        fwrite(STDERR, "No complete sessions to validate.\n");
        return EXIT_USAGE;
    }

    $passedCount = 0;
    foreach ($complete as $i => $summary) {
        if ($i > 0) { echo "\n"; }
        if (validateSession($summary['id'])) { $passedCount++; }
    }

    $total = count($complete);
    echo "\n" . str_repeat('─', 60) . "\n";
    echo "{$total} session(s) validated — {$passedCount} passed, " . ($total - $passedCount) . " failed\n";
    return $passedCount === $total ? EXIT_PASS : EXIT_FAIL;
}

/** Validate a plan read from a .json file (or stdin when the path is "-"). */
function validateFile(string $path): int
{
    if ($path === '') {
        fwrite(STDERR, "--file needs a path (or - for stdin).\n");
        return EXIT_USAGE;
    }

    if ($path === '-') {
        $raw    = (string) stream_get_contents(STDIN);
        $label  = 'stdin';
    } else {
        if (!is_file($path)) {
            fwrite(STDERR, "File not found: {$path}\n");
            return EXIT_USAGE;
        }
        $raw   = (string) file_get_contents($path);
        $label = $path;
    }

    // .json first; .md / .txt fall through to the rendered-text parser.
    $decoded = json_decode($raw, true);
    $plan    = is_array($decoded) ? normalizePlan($decoded) : parseRenderedPlan($raw);

    if ($plan === null) {
        fwrite(STDERR, "No build plan found in: {$label}\n");
        fwrite(STDERR, "Expected a .json export, or a .md/.txt plan with PROMPT 1-5 headings.\n");
        return EXIT_USAGE;
    }

    echo "File {$label}\n";
    $passed = report($plan);

    // The .json export carries the source requirements alongside the prompts, so
    // a force-advanced domain is visible without the original session.
    if (is_array($decoded) && isset($decoded['requirements']) && is_array($decoded['requirements'])) {
        warnForced(array_keys(array_filter(
            $decoded['requirements'],
            fn($r) => is_array($r) && array_key_exists('covered', $r) && !$r['covered']
        )));
    }

    return $passed ? EXIT_PASS : EXIT_FAIL;
}

// ────────────────────────────── shape handling ──────────────────────────────

/**
 * Coerce any of the three shapes a plan travels in into prompt_1 … prompt_5:
 *
 *   1. the .json export     — prompts nested under a "prompts" key, alongside
 *                             all_requirements_covered + requirements
 *                             (public/partials/build_plan.php)
 *   2. the assoc form       — prompt_1 … prompt_5, what ManifestGenerator returns
 *   3. the indexed form     — [p1 … p5], what InterviewSession::writeBuildPlan()
 *                             actually persists on the session
 *   4. one rendered string  — the whole §3c document in a single field. Sessions
 *                             from before FP9 split the plan into 5 fields are
 *                             stored this way; handed to the text parser.
 *
 * Returns null when the input carries no plan at all. Missing or empty
 * individual prompts are preserved as '' so ManifestValidator reports them as
 * violations rather than having them silently disappear here.
 */
function normalizePlan(mixed $raw): ?array
{
    if (is_string($raw))                { return parseRenderedPlan($raw); }
    if (!is_array($raw) || $raw === []) { return null; }

    // Shape 1: unwrap the export envelope, then re-enter with the inner plan.
    if (isset($raw['prompts']) && is_array($raw['prompts'])) {
        return normalizePlan($raw['prompts']);
    }

    // Shape 3: a plain list of prompt strings.
    if (array_is_list($raw)) {
        $plan = [];
        for ($i = 1; $i <= 5; $i++) {
            $plan['prompt_' . $i] = trim((string) ($raw[$i - 1] ?? ''));
        }
        return $plan;
    }

    // Shape 2: keyed prompt_N. Require at least one key to avoid claiming an
    // unrelated JSON object is an (entirely empty) build plan.
    $plan  = [];
    $found = false;
    for ($i = 1; $i <= 5; $i++) {
        $key   = 'prompt_' . $i;
        $value = trim((string) ($raw[$key] ?? ''));
        if ($value !== '') { $found = true; }
        $plan[$key] = $value;
    }
    return $found ? $plan : null;
}

/**
 * Recover the 5 prompts from a rendered plan — the .md and .txt downloads, and
 * the single-string build_plan pre-FP9 sessions carry. All three label their
 * sections the same way, so one split on the PROMPT heading covers everything:
 *
 *   .md      "## Prompt 1 — Project Initialization" then a ``` fenced block
 *   .txt     "PROMPT 1 — PROJECT INITIALIZATION"    then a dashed rule
 *   legacy   "PROMPT 1 — PROJECT INITIALIZATION"    then "Paste this…:" and a
 *            quoted body, closed by a box-drawing separator
 *
 * Only the wrapper is stripped, never anything inside the prompt: over-eager
 * cleanup would change the text being validated and could invent violations.
 */
function parseRenderedPlan(string $text): ?array
{
    // Split on the section headings, keeping the captured prompt number.
    $parts = preg_split(
        '/^[ \t]*(?:#{1,6}[ \t]*)?PROMPT[ \t]+([1-5])[ \t]*(?:[—–-].*)?$/mi',
        $text,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );
    if ($parts === false || count($parts) < 3) { return null; }

    $found = false;
    $plan  = [];
    // $parts[0] is the preamble; pairs of (number, body) follow.
    for ($i = 1; $i < count($parts) - 1; $i += 2) {
        $n = (int) $parts[$i];
        $body = cleanRenderedSection((string) $parts[$i + 1]);
        if ($body !== '') { $found = true; }
        $plan['prompt_' . $n] = $body;
    }
    if (!$found) { return null; }

    // Any section the document omitted stays '' so the validator flags it.
    for ($n = 1; $n <= 5; $n++) { $plan['prompt_' . $n] ??= ''; }
    ksort($plan);
    return $plan;
}

/**
 * Strip the decoration around one rendered section, leaving the prompt itself.
 *
 * Handled line by line because a horizontal rule means opposite things in the two
 * formats: .txt puts one directly UNDER the heading as decoration, while the §3c
 * document puts one BETWEEN sections as a terminator. Position disambiguates —
 * before any content it is decoration, after content it ends the section.
 */
function cleanRenderedSection(string $body): string
{
    $lines      = preg_split('/\R/', $body) ?: [];
    $kept       = [];
    $seenContent = false;

    foreach ($lines as $line) {
        if (isRuleLine($line)) {
            if ($seenContent) { break; }    // terminator — the section is over
            continue;                        // heading decoration — skip it
        }
        // "Paste this after Prompt 1 is confirmed:" is instruction, not prompt.
        if (preg_match('/^[ \t]*Paste this\b[^\n]*$/i', $line)) { continue; }
        // Markdown fences wrapping the body in the .md export.
        if (preg_match('/^[ \t]*```[a-z]*[ \t]*$/i', $line))    { continue; }

        if (trim($line) !== '') { $seenContent = true; }
        $kept[] = $line;
    }

    $body = trim(implode("\n", $kept));

    // A fully quote-wrapped body (the legacy format) — only when both ends match.
    if (strlen($body) > 1 && str_starts_with($body, '"') && str_ends_with($body, '"')) {
        $body = trim(substr($body, 1, -1));
    }

    return $body;
}

/**
 * True when a line is pure decoration — a dashed or box-drawing rule.
 * \x{2500} and \x{2550} are the box-drawing characters the §3c document draws with.
 */
function isRuleLine(string $line): bool
{
    $line = trim($line);
    if ($line === '') { return false; }

    $matched = preg_match('/^(?:[-_*=]|\x{2500}|\x{2550}){5,}$/u', $line);
    if ($matched === 1) { return true; }

    // preg returns false on invalid UTF-8 (a .txt saved in another encoding);
    // fall back to the ASCII rules, which are byte-safe.
    return $matched === false && (bool) preg_match('/^[-_*=]{5,}$/', $line);
}

// ───────────────────────────────── reporting ─────────────────────────────────

/**
 * Print one PASS/FAIL line per section plus the violation total.
 * Returns true when the plan has zero violations.
 */
function report(array $plan): bool
{
    $result     = ManifestValidator::validate($plan);
    $violations = $result['violations'];

    // Group violations by the prompt_N prefix ManifestValidator emits.
    $byPrompt = [];
    foreach ($violations as $violation) {
        [$key, $message] = array_pad(explode(':', $violation, 2), 2, '');
        $byPrompt[trim($key)][] = trim($message);
    }

    foreach (SECTION_LABELS as $n => $label) {
        $key    = 'prompt_' . $n;
        $faults = $byPrompt[$key] ?? [];
        $flag   = $faults === [] ? '[PASS]' : '[FAIL]';
        echo "  {$flag} Prompt {$n} — {$label}\n";
        foreach ($faults as $fault) {
            echo "         → {$fault}\n";
        }
    }

    $count = count($violations);
    echo "\n  {$count} violation" . ($count === 1 ? '' : 's') . "\n";
    return $result['valid'];
}

/** Warn about domains the turn cap force-advanced, read from the session store. */
function reportCoverage(string $id): void
{
    warnForced(array_keys(array_filter(
        InterviewSession::readDomainCoverage($id),
        fn($covered) => !$covered
    )));
}

/**
 * A force-advanced domain means the Orchestrator's 5-turn cap closed it rather
 * than the agent judging it genuinely covered. The plan is still structurally
 * valid — this never changes the exit code — but the presentation should be
 * honest about which answers the user actually gave.
 */
function warnForced(array $forced): void
{
    if ($forced === []) { return; }
    echo '  ! warning: ' . count($forced) . ' domain(s) force-advanced by the turn cap ('
        . implode(', ', $forced) . ")\n";
    echo "    Structurally valid, but these answers were not genuinely covered.\n";
}
