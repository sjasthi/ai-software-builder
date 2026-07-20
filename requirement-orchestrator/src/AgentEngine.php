<?php
/**
 * AgentEngine — Routing Agent (FP8).
 *
 * Sits between the Extraction Agent (RequirementParser, FP7) and the transcript.
 * It has two branches:
 *
 *   IN-SCOPE  (Cox's FP8): the user is genuinely describing their project, so we
 *             let extraction run and then ask a NEXT question that is tailored to
 *             the user's actual project and targets the next OPEN domain — instead
 *             of the static InterviewSession::OPENING[] text used through FP7.
 *
 *   OUT-OF-SCOPE (Port's FP8): the user has drifted (off-topic, meta, unrelated).
 *             We redirect them back to the current domain WITHOUT advancing — no
 *             extraction, no domain flip, the progress bar does not move.
 *
 * Design mirrors FP7: the pure decision logic (route/nextOpenDomain) is separate
 * from the LLM calls and needs no key, so tests inject labels / a fake LlmClient.
 * All model traffic goes through LlmClient so provider/model is a config choice.
 */

require_once __DIR__ . '/InterviewSession.php';
require_once __DIR__ . '/LlmClient.php';

class AgentEngine
{
    public const IN_SCOPE     = 'IN_SCOPE';
    public const OUT_OF_SCOPE = 'OUT_OF_SCOPE';

    /** One-line purpose of each domain, injected into generation prompts so the
     *  LLM asks about the right thing (kept in sync with RequirementParser). */
    private const DOMAIN_GUIDE = [
        'pain_points'       => 'the problem or frustration the user is trying to solve',
        'data_sources'      => 'where the data comes from (APIs, databases, files, services)',
        'data_access'       => 'how data is retrieved (pull, push, sync, read, write)',
        'end_result'        => 'what the finished system should produce or do',
        'stakeholders'      => 'who owns, funds, or is responsible for the system',
        'audience_type'     => 'who will use or consume the system\'s output',
        'current_process'   => 'how the user handles this today (manual steps, existing tools)',
        'interaction_model' => 'how/when the system is triggered (on-demand, scheduled, event-driven, real-time, batch)',
    ];

    /** Optional injected client (tests pass a fake). When null, each call lazily
     *  builds a task-specific client from config and falls back if none exists. */
    public function __construct(private ?LlmClient $client = null) {}

    // ───────────────────────── pure decision logic (no LLM) ─────────────────────────

    /** First still-OPEN domain in interview order, or null when all are covered. */
    public static function nextOpenDomain(array $domainState): ?string
    {
        foreach (InterviewSession::DOMAINS as $d) {
            if (($domainState[$d] ?? 'OPEN') !== 'COVERED') { return $d; }
        }
        return null;
    }

    /**
     * Turn a scope label into a routing decision. Pure — this is what the boundary
     * deviation test drives directly. Anything that isn't an explicit OUT_OF_SCOPE
     * is treated as in-scope, so a mis-classification never traps the user.
     *
     * @return array{advance:bool,action:string}
     */
    public static function route(string $scopeLabel): array
    {
        $outOfScope = strtoupper(trim($scopeLabel)) === self::OUT_OF_SCOPE;
        return [
            'advance' => !$outOfScope,
            'action'  => $outOfScope ? 'REDIRECT' : 'EXTRACT',
        ];
    }

    // ───────────────────────── LLM branches ─────────────────────────

    /**
     * Classify the user's latest message as IN_SCOPE or OUT_OF_SCOPE for the
     * current interview. Fails toward IN_SCOPE (never blocks a real answer).
     */
    public function classifyScope(string $sessionId, string $userMessage): string
    {
        $client = $this->clientFor('scope_classification');
        if ($client === null) { return self::IN_SCOPE; }

        $domain      = self::nextOpenDomain(InterviewSession::readDomainState($sessionId)) ?? 'pain_points';
        $domainDesc  = self::DOMAIN_GUIDE[$domain] ?? '';
        $recent      = $this->recentTranscript($sessionId, 6);

        $system = <<<PROMPT
You are the routing gate for a software-requirements interview. The assistant is
collecting details about the user's software project across 8 domains. Right now it
is trying to learn about: {$domain} — {$domainDesc}.

Recent conversation:
{$recent}

Decide whether the user's latest message is IN_SCOPE or OUT_OF_SCOPE.
- IN_SCOPE: any genuine attempt to describe, answer, clarify, or ask about their
  software project or the current question — even if vague, partial, or unsure.
- OUT_OF_SCOPE: drift — off-topic small talk, unrelated requests, meta questions
  about you/the AI, jokes, or content with nothing to do with building their software.

Reply with ONE word only: IN_SCOPE or OUT_OF_SCOPE.
PROMPT;

        $raw = $client->complete($system, [['role' => 'user', 'content' => $userMessage]], ['max_tokens' => 8]);
        if ($raw === null) { return self::IN_SCOPE; }

        return str_contains(strtoupper($raw), self::OUT_OF_SCOPE) ? self::OUT_OF_SCOPE : self::IN_SCOPE;
    }

    /**
     * IN-SCOPE branch: generate the next question, tailored to the user's project,
     * targeting the next OPEN domain. Falls back to the static opening question when
     * no client is configured or the call fails.
     */
    public function nextQuestion(string $sessionId, array $domainState): string
    {
        $domain = self::nextOpenDomain($domainState);
        if ($domain === null) {
            return 'That covers all 8 areas — your build plan is ready on the right.';
        }
        $fallback = InterviewSession::OPENING[$domain];

        $client = $this->clientFor('question_generation');
        if ($client === null) { return $fallback; }

        $domainDesc = self::DOMAIN_GUIDE[$domain] ?? '';
        $recent     = $this->recentTranscript($sessionId, 8);

        $system = <<<PROMPT
You are interviewing a user to define their software project. Ask the SINGLE next
question. It must draw out this domain: {$domain} — {$domainDesc}.

Conversation so far:
{$recent}

Rules:
- Reference concrete details the user has already shared so the question feels
  specific to THEIR project, not generic.
- Dig for specifics: push for the concrete, buildable detail still missing for this
  domain — names, numbers, formats, frequency, or a concrete example. Do not settle
  for a vague answer; the goal is enough precision to actually build from.
- Ask exactly one clear, focused question. No preamble, no lists, no restating their answer.
- Plain, friendly language. Output only the question text.
PROMPT;

        $raw = $client->complete($system, [['role' => 'user', 'content' => 'Ask the next question.']], ['max_tokens' => 200]);
        $q   = is_string($raw) ? trim($raw) : '';
        return $q !== '' ? $q : $fallback;
    }

    /**
     * OUT-OF-SCOPE branch: a short, friendly steer back to the current domain.
     * Never advances state. Falls back to a template when no client/LLM.
     */
    public function redirect(string $sessionId, string $userMessage, string $domain): string
    {
        $fallbackQ = InterviewSession::OPENING[$domain] ?? 'Let\'s continue with your project.';
        $fallback  = "Let's keep this focused on your software project — {$fallbackQ}";

        $client = $this->clientFor('redirection');
        if ($client === null) { return $fallback; }

        $domainDesc = self::DOMAIN_GUIDE[$domain] ?? '';
        $system = <<<PROMPT
You are guiding a software-requirements interview. The user's last message drifted
off-topic. In ONE or two short sentences, gently acknowledge it and steer them back
to the project — specifically to this domain: {$domain} — {$domainDesc}.
Warm, brief, and end by re-asking that topic. Output only your reply.
PROMPT;

        $raw = $client->complete($system, [['role' => 'user', 'content' => $userMessage]], ['max_tokens' => 120]);
        $r   = is_string($raw) ? trim($raw) : '';
        return $r !== '' ? $r : $fallback;
    }

    // ───────────────────────── helpers ─────────────────────────

    /** Injected client wins (tests); otherwise build one for this task, or null on no key. */
    private function clientFor(string $task): ?LlmClient
    {
        if ($this->client !== null) { return $this->client; }
        try {
            return LlmClientFactory::forTask($task);
        } catch (Throwable) {
            return null;
        }
    }

    /** Last N transcript turns rendered as "Role: text" lines for prompt context. */
    private function recentTranscript(string $sessionId, int $limit): string
    {
        $turns = InterviewSession::readTranscript($sessionId);
        $turns = array_slice($turns, -$limit);
        $lines = [];
        foreach ($turns as $t) {
            $role = ($t['role'] ?? 'agent') === 'user' ? 'User' : 'Agent';
            $lines[] = $role . ': ' . trim($t['content'] ?? '');
        }
        return $lines === [] ? '(no messages yet)' : implode("\n", $lines);
    }
}
