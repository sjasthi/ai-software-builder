<?php
/**
 * LlmClient — provider abstraction (FP8).
 *
 * All LLM traffic in the app goes through this layer instead of hardcoding curl
 * in each agent. That gives us two things the professor asked for:
 *   1. pick which model/provider we talk to, and
 *   2. auto-pick the best-fit model per task (fast+cheap for classification,
 *      stronger for question writing).
 *
 * Adding a new provider later = write one class that implements LlmClient and add
 * a line to config. The agents (RequirementParser, AgentEngine) never change.
 *
 * Uses PHP's built-in curl — no Composer or external SDK required.
 * Configure a key via the ANTHROPIC_API_KEY env var or config/local.php.
 */

/**
 * One completion call. Returns the model's text, or null on any transport/HTTP
 * failure so callers can fall back gracefully.
 *
 * @param string                    $system   system prompt
 * @param array<int,array{role:string,content:string}> $messages conversation turns
 * @param array<string,mixed>       $opts     e.g. ['max_tokens' => 512]
 */
interface LlmClient
{
    public function complete(string $system, array $messages, array $opts = []): ?string;
}

/**
 * Anthropic (Claude) provider. This is the curl block that used to live inline in
 * RequirementParser, lifted out unchanged so every agent shares one transport.
 */
class AnthropicClient implements LlmClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VER = '2023-06-01';

    public function __construct(
        private string $apiKey,
        private string $model,
    ) {}

    public function complete(string $system, array $messages, array $opts = []): ?string
    {
        $body = json_encode([
            'model'      => $this->model,
            'max_tokens' => $opts['max_tokens'] ?? 512,
            'system'     => $system,
            'messages'   => $messages,
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VER,
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) { return null; }

        $data = json_decode($response, true);
        return $data['content'][0]['text'] ?? null;
    }
}

/**
 * OpenAI (ChatGPT) provider. Uses the Chat Completions REST API via curl — no
 * SDK needed, same shape as AnthropicClient. Two wire differences from Anthropic:
 * the system prompt is passed as a leading {role:'system'} message (not a separate
 * field), and auth is a Bearer token. Returns the text, or null on any failure.
 */
class OpenAIClient implements LlmClient
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        private string $apiKey,
        private string $model,
    ) {}

    public function complete(string $system, array $messages, array $opts = []): ?string
    {
        // OpenAI puts the system prompt in the messages array, first.
        $allMessages = array_merge(
            [['role' => 'system', 'content' => $system]],
            $messages,
        );
        $body = json_encode([
            'model'      => $this->model,
            'max_tokens' => $opts['max_tokens'] ?? 512,
            'messages'   => $allMessages,
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) { return null; }

        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? null;
    }
}

/**
 * ScriptedLlm — a FREE, offline stand-in provider for demos and tests.
 *
 * No network, no key, no cost. It produces believable, DYNAMIC-looking output so
 * you can see the FP8 behavior in the browser before buying real API keys:
 *   - questions echo the user's own words (visibly not the static ones),
 *   - off-topic messages are classified OUT_OF_SCOPE (→ redirect, no progress),
 *   - in-scope answers cover domains by keyword (one sentence can cover several).
 *
 * Enable it by setting `'mock' => true` in config/local.php (or env LLM_MOCK=1).
 * Swap in a real key later with zero code change. This is demo/test-only code and
 * only reads the prompt strings the app itself wrote, so it never affects the real
 * provider path.
 */
class ScriptedLlm implements LlmClient
{
    /** The 8 domains in interview order (kept in sync with InterviewSession). */
    private const DOMAINS = [
        'pain_points', 'data_sources', 'data_access', 'end_result',
        'stakeholders', 'audience_type', 'current_process', 'interaction_model',
    ];

    private const DRIFT = ['weather', 'joke', 'how are you', 'who are you', 'what are you', 'lol', 'haha', 'sports', 'movie', 'football', 'basketball', 'hungry', 'tired', 'bored', 'your name', 'favorite', 'good morning'];

    public function __construct(private string $task) {}

    public function complete(string $system, array $messages, array $opts = []): ?string
    {
        $userMsg = '';
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'user') { $userMsg = (string) ($m['content'] ?? ''); }
        }

        return match ($this->task) {
            'extraction'           => $this->mockExtract($system, $userMsg),
            'scope_classification' => $this->mockClassify($userMsg),
            'question_generation'  => $this->mockQuestion($system),
            'redirection'          => $this->mockRedirect($system),
            default                => null,
        };
    }

    /**
     * Return the 8-domain JSON RequirementParser expects. Conservative on purpose:
     * cross off only ONE domain per turn, and only when the answer is substantive.
     * This keeps the mock interview thorough — it needs a real answer for each of
     * the 8 domains instead of crossing several off from one sentence — and a
     * vague answer advances nothing, so the next question re-probes for detail.
     */
    private function mockExtract(string $system, string $userMsg): string
    {
        // Reconstruct the current state the app injected into the prompt. Isolate
        // the "Current domain state:" JSON block first, so we don't accidentally
        // match the schema example ("pain_points": "COVERED" | "OPEN") below it.
        $state = [];
        $block = preg_match('/Current domain state:\s*(\{.*?\})/s', $system, $b) ? $b[1] : '';
        if ($block !== '' && preg_match_all('/"(\w+)":\s*"(COVERED|OPEN)"/', $block, $m, PREG_SET_ORDER)) {
            foreach ($m as $p) { $state[$p[1]] = $p[2]; }
        }

        $result = [];
        foreach (self::DOMAINS as $d) {
            $result[$d] = ($state[$d] ?? 'OPEN') === 'COVERED' ? 'COVERED' : 'OPEN';
        }

        // Only a substantive answer crosses off the current (first OPEN) domain.
        if ($this->isSubstantive($userMsg)) {
            foreach (self::DOMAINS as $d) {
                if ($result[$d] === 'OPEN') { $result[$d] = 'COVERED'; break; }
            }
        }
        return json_encode($result);
    }

    /** A real attempt at an answer — enough to count, but not a one-word brush-off. */
    private function isSubstantive(string $msg): bool
    {
        return str_word_count(trim($msg)) >= 4;
    }

    private function mockClassify(string $userMsg): string
    {
        $lc = mb_strtolower($userMsg);
        foreach (self::DRIFT as $d) {
            if (str_contains($lc, $d)) { return 'OUT_OF_SCOPE'; }
        }
        return 'IN_SCOPE';
    }

    private function mockQuestion(string $system): string
    {
        $topic   = $this->domainDesc($system);
        $snippet = $this->lastUserSnippet($system);
        return $snippet !== ''
            ? "You mentioned \"{$snippet}\" — can you tell me more about {$topic}?"
            : "Can you tell me more about {$topic}?";
    }

    private function mockRedirect(string $system): string
    {
        $topic = $this->domainDesc($system);
        return "Ha — let's steer back to your software project for now. Could you tell me about {$topic}?";
    }

    /** Pull the domain description ("... — <desc>.") out of a generation prompt. */
    private function domainDesc(string $system): string
    {
        if (preg_match('/this domain: \w+ — (.+)/u', $system, $m)) {
            return rtrim(trim($m[1]), '.');
        }
        return 'your project';
    }

    /** Grab the most recent "User: ..." line the app put in the prompt, trimmed. */
    private function lastUserSnippet(string $system): string
    {
        if (!preg_match_all('/^User: (.+)$/mu', $system, $m) || $m[1] === []) { return ''; }
        $s = trim((string) end($m[1]));
        return mb_strlen($s) > 42 ? mb_substr($s, 0, 42) . '…' : $s;
    }
}

/**
 * Picks the right provider + model for a given task. This is the "best-fit LLM
 * per scenario" hook: task name → model. Everything is Anthropic for now, but a
 * second provider slots in here without touching any agent.
 */
class LlmClientFactory
{
    /** Task → model. Defaults to the fast, low-cost Haiku tier for every task
     *  (~5x cheaper than Opus) to keep API usage down. Override any single task
     *  in config `task_models` — e.g. bump question_generation to a stronger
     *  model if question quality ever needs it. */
    private const DEFAULT_TASK_MODELS = [
        'extraction'           => 'claude-haiku-4-5-20251001',
        'scope_classification' => 'claude-haiku-4-5-20251001',
        'question_generation'  => 'claude-haiku-4-5-20251001',
        'redirection'          => 'claude-haiku-4-5-20251001',
    ];

    private const FALLBACK_MODEL = 'claude-haiku-4-5-20251001';

    /** OpenAI defaults — used when default_provider is 'openai'. gpt-4o-mini is
     *  the low-cost, capable tier ($0.15/$0.60 per 1M); override in task_models. */
    private const DEFAULT_TASK_MODELS_OPENAI = [
        'extraction'           => 'gpt-4o-mini',
        'scope_classification' => 'gpt-4o-mini',
        'question_generation'  => 'gpt-4o-mini',
        'redirection'          => 'gpt-4o-mini',
    ];

    private const OPENAI_FALLBACK_MODEL = 'gpt-4o-mini';

    /**
     * Build a client for a task. Throws when no key is configured so the caller
     * can catch it and fall back to non-LLM behavior.
     */
    public static function forTask(string $task): LlmClient
    {
        $cfg = self::config();

        // Free offline demo/test mode — no key required.
        if (self::mockEnabled($cfg)) {
            return new ScriptedLlm($task);
        }

        // Pick the provider, then its per-task model (config task_models override
        // the provider's built-in defaults). Switching providers is one config line.
        $provider = $cfg['default_provider'] ?? 'anthropic';
        $defaults = $provider === 'openai' ? self::DEFAULT_TASK_MODELS_OPENAI : self::DEFAULT_TASK_MODELS;
        $models   = ($cfg['task_models'] ?? []) + $defaults;
        $model    = $models[$task] ?? ($provider === 'openai' ? self::OPENAI_FALLBACK_MODEL : self::FALLBACK_MODEL);

        $key = self::providerKey($provider, $cfg);
        if ($key === '') {
            throw new RuntimeException("No API key configured for provider '{$provider}'");
        }

        return match ($provider) {
            'anthropic' => new AnthropicClient($key, $model),
            'openai'    => new OpenAIClient($key, $model),
            default     => throw new RuntimeException("Unsupported LLM provider: {$provider}"),
        };
    }

    /** True when the free offline ScriptedLlm should be used (env LLM_MOCK or config 'mock'). */
    private static function mockEnabled(array $cfg): bool
    {
        $env = getenv('LLM_MOCK');
        if (is_string($env) && $env !== '' && $env !== '0' && strtolower($env) !== 'false') {
            return true;
        }
        return !empty($cfg['mock']);
    }

    /** True when the app has some usable key for the active provider. */
    public static function hasKey(): bool
    {
        $cfg = self::config();
        return self::providerKey($cfg['default_provider'] ?? 'anthropic', $cfg) !== '';
    }

    /** True when free offline demo mode is active. */
    public static function isMock(): bool
    {
        return self::mockEnabled(self::config());
    }

    /**
     * Resolve the API key for a provider, most-trusted source first:
     *   1. the provider's env var (ANTHROPIC_API_KEY / OPENAI_API_KEY),
     *   2. the per-use key a user typed on the launch page (server session only —
     *      never written to disk or the session JSON files); it applies to the
     *      active provider,
     *   3. config/local.php providers.<provider>.api_key (Anthropic also accepts
     *      the legacy FP7 flat 'api_key').
     */
    private static function providerKey(string $provider, array $cfg): string
    {
        $envName = $provider === 'openai' ? 'OPENAI_API_KEY' : 'ANTHROPIC_API_KEY';
        $env = getenv($envName);
        if (is_string($env) && $env !== '') { return $env; }
        // Per-use key from the launch page (only present after session_start()).
        if (!empty($_SESSION['api_key'])) { return (string) $_SESSION['api_key']; }
        // ['providers' => ['<provider>' => ['api_key' => '...']]]
        $new = $cfg['providers'][$provider]['api_key'] ?? '';
        if ($new !== '') { return $new; }
        // Legacy flat shape from FP7 (Anthropic only): ['api_key' => '...']
        return $provider === 'anthropic' ? ($cfg['api_key'] ?? '') : '';
    }

    /** Load config/local.php once (if present). Returns [] when absent. */
    private static function config(): array
    {
        static $cache = null;
        if ($cache !== null) { return $cache; }
        $local = __DIR__ . '/../config/local.php';
        $cache = is_file($local) && is_array($cfg = include $local) ? $cfg : [];
        return $cache;
    }
}
