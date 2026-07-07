<?php
/**
 * RequirementParser — Extraction Agent (FP7, Cox)
 * Prompt Chain Step 1: maps one user turn to the 8-domain coverage JSON.
 *
 * Install the SDK before use:
 *   composer require anthropic-ai/sdk
 *
 * Set ANTHROPIC_API_KEY in the environment (or config/local.php).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/InterviewSession.php';

use Anthropic\Client;

class RequirementParser
{
    private Client $client;
    private const MODEL = 'claude-opus-4-8';

    public function __construct()
    {
        $key = getenv('ANTHROPIC_API_KEY') ?: $this->keyFromConfig();
        if ($key === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is not set');
        }
        $this->client = new Client(apiKey: $key);
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
- A domain is COVERED only when the user provides concrete, specific detail.
PROMPT;

        $message = $this->client->messages->create(
            model: self::MODEL,
            maxTokens: 512,
            system: $systemPrompt,
            messages: [
                ['role' => 'user', 'content' => $userMessage],
            ],
        );

        $raw = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') { $raw = $block->text; break; }
        }

        return $this->parseAndValidate($raw, $currentState);
    }

    /**
     * Parse the LLM response and validate it against the 8-domain schema.
     * Returns the merged state (COVERED is sticky — never regresses) on
     * success, or null if the JSON is malformed or a domain key is missing.
     */
    private function parseAndValidate(string $raw, array $currentState): ?array
    {
        // Strip any accidental markdown fencing the model may have added
        $raw     = preg_replace('/```json?\s*|\s*```/', '', trim($raw));
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) { return null; }

        $merged = $currentState;
        foreach (InterviewSession::DOMAINS as $domain) {
            if (!array_key_exists($domain, $decoded)) { return null; }
            if ($currentState[$domain] === 'COVERED') { continue; } // sticky
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

    private function keyFromConfig(): string
    {
        $local = __DIR__ . '/../config/local.php';
        if (!file_exists($local)) { return ''; }
        $cfg = include $local;
        return is_array($cfg) ? ($cfg['api_key'] ?? '') : '';
    }
}
