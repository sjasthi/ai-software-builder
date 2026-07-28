<?php
require_once __DIR__ . '/InterviewSession.php';
require_once __DIR__ . '/LlmClient.php';
require_once __DIR__ . '/MySQLPersister.php';

/**
 * ManifestGenerator — the COMPILER AGENT (FP9).
 *
 * The terminal node of the Orchestrator-Workers pipeline (LLM Call #3). When all
 * 8 domains are COVERED, this agent pulls the finalized, verified answers back out
 * of the database (the same detail the Orchestrator wrote to
 * `domain_state.domain_json`) and composes a sequenced 5-prompt build plan the user
 * pastes, one prompt at a time, into an external coding LLM.
 *
 * It is a SEPARATE agent from the 8 domain/interview agents: those gather
 * requirements; this one compiles them into an executable plan.
 *
 * Robustness (same philosophy as the rest of the app):
 *   - Reads from the DB first; falls back to the JSON session answers so the app
 *     still produces a plan with no MySQL (mock/demo mode).
 *   - LLM-composed via the `plan_generation` task; if there is no key / the call
 *     fails / the JSON is malformed, falls back to deterministic §3c template
 *     substitution. generate() therefore ALWAYS returns a valid, non-empty 5-key plan.
 */
class ManifestGenerator
{
    /** Domain key → human label (also the fact labels injected into the prompt). */
    private const LABELS = [
        'pain_points'       => 'Pain Points',
        'data_sources'      => 'Data Sources',
        'data_access'       => 'Data Access',
        'end_result'        => 'End Result',
        'stakeholders'      => 'Stakeholders & Consumers',
        'audience_type'     => 'Audience Type',
        'current_process'   => 'Current Process',
        'interaction_model' => 'Interaction Model',
    ];

    public function __construct(private ?LlmClient $client = null) {}

    /**
     * Compile the finalized answers for a session into the 5-prompt build plan.
     * @return array{prompt_1:string,prompt_2:string,prompt_3:string,prompt_4:string,prompt_5:string}
     */
    public function generate(string $sessionId): array
    {
        $answers = $this->loadAnswers($sessionId);
        $plan    = $this->composeWithLlm($answers) ?? $this->renderTemplate($answers);
        return $this->fillGaps($plan, $answers);
    }

    /**
     * Pull the finalized answers out of the DB (domain_state.domain_json). If the
     * DB is unavailable/empty, fall back to the JSON session so demos still work.
     * @return array<string,string> domain key => detail (only known, non-empty domains)
     */
    private function loadAnswers(string $sessionId): array
    {
        $answers = $this->filterKnown(MySQLPersister::readDomainAnswers($sessionId));
        if ($answers === []) {
            $answers = $this->filterKnown(InterviewSession::readDomainAnswers($sessionId));
        }
        return $answers;
    }

    /** Keep only the 8 known domains with a non-empty detail string, trimmed. */
    private function filterKnown(array $raw): array
    {
        $out = [];
        foreach (self::LABELS as $key => $_label) {
            if (isset($raw[$key]) && trim((string) $raw[$key]) !== '') {
                $out[$key] = trim((string) $raw[$key]);
            }
        }
        return $out;
    }

    /** LLM path: compose the plan and parse it, or null so the caller falls back. */
    private function composeWithLlm(array $answers): ?array
    {
        if ($answers === []) return null;
        $client = $this->resolveClient('plan_generation');
        if ($client === null) return null;

        $raw = $client->complete(
            $this->systemPrompt($answers),
            [['role' => 'user', 'content' => 'Generate the 5-prompt build plan now. Return JSON only.']],
            ['max_tokens' => 2000]
        );
        return is_string($raw) ? $this->parsePlan($raw) : null;
    }

    private function resolveClient(string $task): ?LlmClient
    {
        if ($this->client !== null) return $this->client;
        try { return LlmClientFactory::forTask($task); } catch (Throwable) { return null; }
    }

    /** The Compiler Agent's system prompt: the verified facts + the output contract. */
    private function systemPrompt(array $answers): string
    {
        $lines = [];
        foreach (self::LABELS as $key => $label) {
            $lines[] = '- ' . $label . ': ' . ($answers[$key] ?? '(not specified)');
        }
        $facts = implode("\n", $lines);

        return "You are the Compiler Agent for a requirement-orchestration system. You do NOT "
            . "build software — you produce the sequenced series of prompts that a user pastes, "
            . "one at a time, into an external coding LLM (Claude Code, ChatGPT, Gemini) to build it.\n\n"
            . "The user's verified requirements, gathered across 8 domains:\n{$facts}\n\n"
            . "Produce a build plan of exactly 5 prompts. Return ONLY a JSON object, no prose, with "
            . "exactly these keys: prompt_1, prompt_2, prompt_3, prompt_4, prompt_5.\n"
            . "Sequence:\n"
            . "  prompt_1 = Project Initialization\n"
            . "  prompt_2 = Data Layer\n"
            . "  prompt_3 = Core Feature Build\n"
            . "  prompt_4 = UI Construction\n"
            . "  prompt_5 = Integration & Testing\n"
            . "Rules:\n"
            . "- Each prompt is a complete, self-contained instruction built from the user's ACTUAL "
            . "answers above. Reference their specifics.\n"
            . "- Never emit bracketed placeholders like [pain_points]; substitute the real content.\n"
            . "- No conversational filler. Do not begin any prompt with \"Sure\", \"Here is\", "
            . "\"Of course\", \"Great\", or \"Certainly\".";
    }

    /** Strip markdown fencing, isolate the JSON object, and validate all 5 keys. */
    private function parsePlan(string $raw): ?array
    {
        $t = trim($raw);
        $t = preg_replace('/^```(?:json)?\s*/i', '', $t);
        $t = preg_replace('/\s*```$/', '', (string) $t);
        if (preg_match('/\{.*\}/s', (string) $t, $m)) { $t = $m[0]; }

        $decoded = json_decode((string) $t, true);
        if (!is_array($decoded)) return null;

        $plan = [];
        for ($i = 1; $i <= 5; $i++) {
            $key = 'prompt_' . $i;
            if (!isset($decoded[$key]) || trim((string) $decoded[$key]) === '') return null;
            $plan[$key] = trim((string) $decoded[$key]);
        }
        return $plan;
    }

    /**
     * Deterministic fallback: the §3c template, populated from the user's answers.
     * No LLM, no key, no placeholders left behind.
     */
    private function renderTemplate(array $answers): array
    {
        $g = fn(string $key, string $fallback) => $answers[$key] ?? $fallback;

        return [
            'prompt_1' => "You are a senior software architect. Build an application that solves: "
                . $g('pain_points', 'the stated problem') . ". The primary users are "
                . $g('stakeholders', 'the stated owners and users') . " with a "
                . $g('audience_type', 'general') . " technical background. The system replaces "
                . $g('current_process', 'the current manual process') . ". Set up the project "
                . "structure, stack, and configuration.",
            'prompt_2' => "Generate the complete database schema for this system. Data enters via "
                . $g('data_access', 'the described access method') . " from "
                . $g('data_sources', 'the described data sources') . ". Include all tables, column "
                . "types, foreign keys, and indexes.",
            'prompt_3' => "Build the core application logic. The system must produce "
                . $g('end_result', 'the described output') . " via a "
                . $g('interaction_model', 'the described interaction model') . " interface. Include "
                . "all server-side logic, validation rules, and error handling.",
            'prompt_4' => "Build the front-end interface appropriate for "
                . $g('audience_type', 'general') . " users. The UI must present "
                . $g('end_result', 'the described output') . " clearly and support "
                . $g('interaction_model', 'the described interaction model') . ".",
            'prompt_5' => "Connect all layers. Verify that data flows correctly from "
                . $g('data_access', 'the described access method') . " through the backend to produce "
                . $g('end_result', 'the described output') . ". Write tests for all critical paths.",
        ];
    }

    /** Safety net: any missing/empty prompt is filled from the template; return 5 keys ordered. */
    private function fillGaps(array $plan, array $answers): array
    {
        $tpl = null;
        $out = [];
        for ($i = 1; $i <= 5; $i++) {
            $key = 'prompt_' . $i;
            if (!isset($plan[$key]) || trim((string) $plan[$key]) === '') {
                $tpl ??= $this->renderTemplate($answers);
                $plan[$key] = $tpl[$key];
            }
            $out[$key] = trim((string) $plan[$key]);
        }
        return $out;
    }
}
