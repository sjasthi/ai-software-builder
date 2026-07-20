<?php
/**
 * RequirementParser — Extraction Agent (FP7, Cox)
 * Prompt Chain Step 1: maps one user turn to the 8-domain coverage JSON.
 *
 * Uses PHP's built-in curl — no Composer or external SDK required.
 * Set ANTHROPIC_API_KEY in the environment (or config/local.php).
 */

require_once __DIR__ . '/InterviewSession.php';
require_once __DIR__ . '/LlmClient.php';

class RequirementParser
{
    private LlmClient $client;

    /**
     * @param LlmClient|null $client injected client (tests); defaults to the
     *        configured provider for the 'extraction' task. Throws when no key
     *        is configured, preserving the FP7 contract.
     */
    public function __construct(?LlmClient $client = null)
    {
        $this->client = $client ?? LlmClientFactory::forTask('extraction');
    }

    /**
     * Prompt Chain Step 1.
     *
     * Sends the user's latest message to Claude with the current domain state
     * injected into the system prompt. Returns the merged, validated 8-domain
     * coverage array on success, or null if the LLM response was not parseable.
     */
    public function extract(string $sessionId, string $userMessage): ?array
    {
        $currentState = InterviewSession::readDomainState($sessionId);
        $stateJson    = json_encode($currentState, JSON_PRETTY_PRINT);

        $systemPrompt = <<<PROMPT
You are a requirement extraction engine. Evaluate the user's latest message
against the 8 architectural domains and determine which have been addressed.

Domain definitions:
- pain_points:       the problem or frustration the user is trying to solve
- data_sources:      where the data comes from (APIs, databases, files, services)
- data_access:       how data is retrieved (pull, push, sync, read, write)
- end_result:        what the finished system should produce or do
- stakeholders:      who owns, funds, or is responsible for the system
- audience_type:     who will use or consume the system's output
- current_process:   how the user handles this today (manual steps, existing tools)
- interaction_model: how/when the system is triggered (on-demand, scheduled automation, event-driven, real-time, batch)

Current domain state:
{$stateJson}

Return ONLY a valid JSON object using this exact schema — no prose, no
explanation, no markdown fencing:
{
  "pain_points":       "COVERED" | "OPEN",
  "data_sources":      "COVERED" | "OPEN",
  "data_access":       "COVERED" | "OPEN",
  "end_result":        "COVERED" | "OPEN",
  "stakeholders":      "COVERED" | "OPEN",
  "audience_type":     "COVERED" | "OPEN",
  "current_process":   "COVERED" | "OPEN",
  "interaction_model": "COVERED" | "OPEN"
}

Rules:
- Only mark COVERED for domains the user explicitly addressed. Do not infer.
- Domains already marked COVERED must remain COVERED.
- Set a HIGH bar for COVERED: the user must give concrete, specific, buildable
  detail for that domain — names, numbers, formats, frequency, or a clear example.
  A vague, generic, or passing mention is NOT enough.
- When an answer is partial or ambiguous, keep the domain OPEN so the interview can
  dig deeper. Prefer OPEN whenever you are not confident the detail is sufficient.
PROMPT;

        $raw = $this->client->complete(
            $systemPrompt,
            [['role' => 'user', 'content' => $userMessage]],
            ['max_tokens' => 512]
        );
        if ($raw === null) { return null; }

        return $this->parseAndValidate($raw, $currentState);
    }

    /**
     * Parse the LLM response and validate it against the 8-domain schema.
     * Returns the merged state (COVERED is sticky — never regresses) on
     * success, or null if the JSON is malformed or a domain key is missing.
     */
    private function parseAndValidate(string $raw, array $currentState): ?array
    {
        $raw     = preg_replace('/```json?\s*|\s*```/', '', trim($raw));
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) { return null; }

        $merged = $currentState;
        foreach (InterviewSession::DOMAINS as $domain) {
            if (!array_key_exists($domain, $decoded)) { return null; }
            if ($currentState[$domain] === 'COVERED') { continue; }
            if ($decoded[$domain] === 'COVERED') { $merged[$domain] = 'COVERED'; }
        }

        return $merged;
    }

    /** Gate check: true when all 8 domains are COVERED. */
    public static function allCovered(array $domainState): bool
    {
        $covered = count(array_filter($domainState, fn($v) => $v === 'COVERED'));
        return $covered === count(InterviewSession::DOMAINS);
    }

    /**
     * Pure-logic gate evaluation — no session I/O.
     * Returns: all_covered, covered_count, open_domains, total, next_action.
     */
    public static function gate(array $domainState): array
    {
        $covered = $open = [];
        foreach (InterviewSession::DOMAINS as $d) {
            if (($domainState[$d] ?? '') === 'COVERED') {
                $covered[] = $d;
            } else {
                $open[] = $d;
            }
        }
        $allCovered = count($open) === 0;
        return [
            'all_covered'   => $allCovered,
            'covered_count' => count($covered),
            'open_domains'  => $open,
            'total'         => count(InterviewSession::DOMAINS),
            'next_action'   => $allCovered ? 'COMPILE' : 'INTERVIEW',
        ];
    }

    /**
     * Gate evaluation read from a persisted session.
     * Returns null if the session does not exist.
     */
    public static function gateForSession(string $sessionId): ?array
    {
        $session = InterviewSession::readSession($sessionId);
        if ($session === null) { return null; }
        return self::gate($session['domain_state'] ?? []);
    }
}
