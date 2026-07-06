<?php
/**
 * RequirementParser — Extraction Agent (FP7, "Extraction Agent & LLM Semantic Evaluation", Step 1 of the chain)
 *
 * Two responsibilities live here, split by owner:
 *
 *   Cox — extract(): the LLM call. Builds the Section 3a extraction prompt,
 *         injects the user's latest message + current domain JSON, calls the
 *         LLM in JSON mode, validates the response against the 8-domain schema,
 *         and persists it via InterviewSession::writeDomainState().
 *         (Stubbed below — see the TODO. The gate does not depend on it being live.)
 *
 *   Port — gate(): the programmatic gate check. Pure PHP logic, no LLM. Given a
 *         domain-state map it decides whether the prompt chain should trigger the
 *         Compiler Agent (all 8 COVERED) or keep interviewing (any domain OPEN).
 *         This is FP7's deliverable; the Shopify verification proof is in
 *         tests/gate_check_test.php.
 *
 * The 8 domains are owned by InterviewSession::DOMAINS — kept as the single
 * source of truth so the gate and the store can never drift out of sync.
 */
class RequirementParser
{
    // ───────────────────────── Cox's seam (Extraction) ─────────────────────────

    /**
     * EXTRACTION AGENT (Cox). Map the user's latest message onto the 8 domains
     * via an LLM call in JSON mode, then persist the updated state.
     *
     * Expected contract once implemented:
     *   - Build the Section 3a system prompt with the current domain JSON + user text.
     *   - Call the LLM with structured-output (JSON mode) enforcement.
     *   - Validate the returned JSON against the 8-domain schema (reject prose/fencing).
     *   - Write it with InterviewSession::writeDomainState($sessionId, $state).
     *   - Return the updated 8-domain state map so the caller can run gate() on it.
     *
     * Until this is built, it throws so nothing silently runs against a fake result.
     *
     * @return array<string,string> updated domain-state map (COVERED|OPEN per domain)
     */
    public static function extract(string $sessionId, string $userMessage): array
    {
        // TODO(Cox, FP7): construct extraction prompt, call LLM in JSON mode,
        //                 validate response, persist, and return the new state.
        throw new RuntimeException(
            'RequirementParser::extract() is not implemented yet (Cox, FP7 Extraction Agent). '
            . 'Port\'s gate() works independently — feed it a domain-state map directly.'
        );
    }

    // ───────────────────────── Port's method (Gate check) ─────────────────────────

    /**
     * PROGRAMMATIC GATE CHECK (Port, FP7). Pure logic, no LLM call.
     *
     * Decides what the prompt chain does next based purely on domain coverage:
     *   - every one of the 8 domains COVERED  → next_action = 'COMPILE'  (trigger the Compiler Agent)
     *   - any domain still OPEN               → next_action = 'INTERVIEW' (keep asking questions)
     *
     * Defensive by design: only the 8 known domain keys are considered, and any
     * value that isn't exactly 'COVERED' counts as not-covered. A missing or junk
     * state can therefore never falsely trip the gate into compiling early.
     *
     * @param  array<string,string> $domainState  domain → 'COVERED'|'OPEN'
     * @return array{
     *     all_covered:bool,
     *     covered_count:int,
     *     total:int,
     *     open_domains:array<int,string>,
     *     next_action:string
     * }
     */
    public static function gate(array $domainState): array
    {
        $open = [];
        $coveredCount = 0;

        foreach (InterviewSession::DOMAINS as $domain) {
            $status = $domainState[$domain] ?? 'OPEN';
            if ($status === 'COVERED') {
                $coveredCount++;
            } else {
                $open[] = $domain;
            }
        }

        $allCovered = ($open === []);

        return [
            'all_covered'   => $allCovered,
            'covered_count' => $coveredCount,
            'total'         => count(InterviewSession::DOMAINS),
            'open_domains'  => $open,
            'next_action'   => $allCovered ? 'COMPILE' : 'INTERVIEW',
        ];
    }

    /**
     * Convenience wrapper: run the gate directly against a saved session's stored
     * domain state (reads through Port's FP6 persistence). Returns null if the
     * session id is unknown. This is how the runtime controller will ask
     * "should we compile yet?" after each extraction pass.
     */
    public static function gateForSession(string $sessionId): ?array
    {
        if (InterviewSession::readSession($sessionId) === null) {
            return null;   // unknown session — caller should handle, not compile
        }
        return self::gate(InterviewSession::readDomainState($sessionId));
    }
}
