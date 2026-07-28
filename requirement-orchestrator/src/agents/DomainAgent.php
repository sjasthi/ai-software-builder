<?php
abstract class DomainAgent
{
    public function __construct(protected ?LlmClient $client = null) {}

    abstract public function key(): string;
    abstract public function label(): string;
    abstract public function description(): string;
    abstract public function openingQuestion(): string;
    abstract protected function extractionSystemPrompt(): string;
    abstract protected function questionSystemPrompt(): string;

    /**
     * Evaluate whether the user's message covers this domain.
     * Returns ['covered' => bool, 'detail' => string]
     */
    public function evaluate(string $userMessage, array $transcript): array
    {
        $client = $this->resolveClient('extraction');
        if ($client === null) {
            return ['covered' => false, 'detail' => 'No LLM client configured.'];
        }

        $recent = $this->formatTranscript($transcript, 30);
        $system = $this->extractionSystemPrompt() . "\n\nFull conversation so far:\n" . $recent .
            "\n\nEvaluate the ENTIRE conversation above, not just the latest message. Reply with ONLY a raw JSON object — no markdown, no code fences, no prose before or after: {\"covered\": true|false, \"detail\": \"one-line summary of what was learned\"}";

        $raw = $client->complete($system, [['role' => 'user', 'content' => $userMessage]], ['max_tokens' => 1000]);
        return $this->parseVerdict($raw);
    }

    /**
     * Parse the extraction verdict into ['covered' => bool, 'detail' => string].
     *
     * Real models routinely wrap the JSON in a ```json … ``` code fence (or add a
     * stray sentence) even when told not to. Naively json_decode()-ing that returns
     * null — which previously made every domain read as NOT covered and stored the
     * whole raw blob as the "detail". So: strip the fence, isolate the first {...}
     * object, decode, and coerce the boolean properly. On genuine failure, return
     * not-covered with an EMPTY detail — never persist the raw blob as a summary.
     */
    private function parseVerdict(?string $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return ['covered' => false, 'detail' => ''];
        }

        $t = trim($raw);
        $t = preg_replace('/^```(?:json)?\s*/i', '', $t);
        $t = preg_replace('/\s*```$/', '', (string) $t);
        if (preg_match('/\{.*\}/s', (string) $t, $m)) { $t = $m[0]; }

        $decoded = json_decode((string) $t, true);
        if (!is_array($decoded) || !array_key_exists('covered', $decoded)) {
            return ['covered' => false, 'detail' => ''];
        }

        return [
            'covered' => filter_var($decoded['covered'], FILTER_VALIDATE_BOOLEAN),
            'detail'  => is_string($decoded['detail'] ?? null) ? trim($decoded['detail']) : '',
        ];
    }

    /**
     * Generate the next probing question for this domain.
     * Falls back to openingQuestion() if no LLM.
     */
    public function nextQuestion(array $transcript): string
    {
        $client = $this->resolveClient('question_generation');
        if ($client === null) return $this->openingQuestion();

        $recent = $this->formatTranscript($transcript, 8);
        $system = $this->questionSystemPrompt() . "\n\nConversation so far:\n" . $recent .
            "\n\nAsk exactly one focused question. Output only the question text.";

        $raw = $client->complete($system, [['role' => 'user', 'content' => 'Ask the next question.']], ['max_tokens' => 400]);
        $q = is_string($raw) ? trim($raw) : '';
        return $q !== '' ? $q : $this->openingQuestion();
    }

    protected function resolveClient(string $task): ?LlmClient
    {
        if ($this->client !== null) return $this->client;
        try { return LlmClientFactory::forTask($task); } catch (Throwable) { return null; }
    }

    protected function formatTranscript(array $transcript, int $limit): string
    {
        $turns = array_slice($transcript, -$limit);
        $lines = [];
        foreach ($turns as $t) {
            $role = ($t['role'] ?? 'agent') === 'user' ? 'User' : 'Agent';
            $lines[] = $role . ': ' . trim($t['content'] ?? '');
        }
        return $lines === [] ? '(no messages yet)' : implode("\n", $lines);
    }
}
