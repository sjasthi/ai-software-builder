<?php
/**
 * InterviewSession — Snapshot Agent (FP6, "Snapshot Agent & Session Persistence")
 *
 * Makes the app stateful between turns. Per the professor's direction, sessions
 * are stored as **.json files** (one file per session) so the project is
 * open-source-friendly: anyone who downloads the repo can see previous sessions,
 * and no authentication is needed.
 *
 * Storage: requirement-orchestrator/sessions/<session_id>.json
 *
 * Method ownership (per weekly_deliverable_plan.xlsx, FP6):
 *   Cox  — createSession(), readSession(), writeExchange(), readTranscript()
 *   Port — writeDomainState(), readDomainState(), atomic save + recovery test
 *   listSessions() backs the previous-sessions UI (Landing + hamburger drawer).
 */
class InterviewSession
{
    /** The 8 architectural requirement domains, in interview order. */
    const DOMAINS = [
        'pain_points', 'data_sources', 'data_access', 'end_result',
        'stakeholders', 'audience_type', 'current_process', 'interaction_model',
    ];

    /** Opening question for each domain (non-LLM fallback when no client is configured). */
    const OPENING = [
        'pain_points'       => "What specific problem are you trying to solve?",
        'data_sources'      => "What information or data does this software need to work with?",
        'data_access'       => "How does that data get into the system — typed in, uploaded, or pulled from another service?",
        'end_result'        => "When the software works perfectly, what does it give you?",
        'stakeholders'      => "Who is going to use this day to day, and who owns it?",
        'audience_type'     => "Are the users more business/everyday users, or technical/developer users?",
        'current_process'   => "How do you handle this today, before the software exists?",
        'interaction_model' => "Do you picture talking to it back-and-forth, or setting it up once and getting results automatically?",
    ];

    // ───────────────────────── storage helpers ─────────────────────────

    private static function storeDir(): string
    {
        $dir = __DIR__ . '/../sessions';
        if (!is_dir($dir)) { mkdir($dir, 0777, true); }
        return $dir;
    }

    /** Reject anything that isn't a clean id, so a URL can't escape the folder. */
    private static function safeId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id);
    }

    private static function path(string $id): string
    {
        return self::storeDir() . '/' . $id . '.json';
    }

    /**
     * Atomic save (Port). Write to a temp file, then rename() over the real one.
     * rename() is an atomic replace on both POSIX and Windows (PHP uses
     * MoveFileEx w/ REPLACE_EXISTING), so a crash mid-write can never leave a
     * half-written, corrupt session file — the old file stays valid until the
     * instant the new one fully replaces it. This is what the recovery test proves.
     */
    private static function atomicSave(string $id, array $data): void
    {
        $data['updated_at'] = gmdate('c');
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp  = self::path($id) . '.tmp';
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, self::path($id));
    }

    private static function blankDomainState(): array
    {
        $s = [];
        foreach (self::DOMAINS as $d) { $s[$d] = 'OPEN'; }
        return $s;
    }

    // ───────────────────────── Cox's methods ─────────────────────────

    /** Create a new session file, seeded with the opening agent greeting. */
    public static function createSession(string $title = ''): string
    {
        $id   = gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $now  = gmdate('c');
        $data = [
            'session_id'      => $id,
            'title'           => $title !== '' ? $title : 'Untitled session',
            'created_at'      => $now,
            'updated_at'      => $now,
            'status'          => 'in_progress',
            'technical_level' => null,
            'domain_state'    => self::blankDomainState(),
            'transcript'      => [[
                'role'    => 'agent',
                'content' => "Hello! I'm going to ask you a series of questions to help "
                           . "define your software project. Let's start — "
                           . self::OPENING['pain_points'],
                'ts'      => $now,
            ]],
            'build_plan'      => null,
        ];
        self::atomicSave($id, $data);
        return $id;
    }

    /** Load and decode a session, or null if it doesn't exist. */
    public static function readSession(string $id): ?array
    {
        if (!self::safeId($id) || !is_file(self::path($id))) { return null; }
        $data = json_decode(file_get_contents(self::path($id)), true);
        return is_array($data) ? $data : null;
    }

    /** Append one chat exchange to the transcript. */
    public static function writeExchange(string $id, string $role, string $content): bool
    {
        $data = self::readSession($id);
        if ($data === null) { return false; }
        $data['transcript'][] = [
            'role'    => $role === 'user' ? 'user' : 'agent',
            'content' => $content,
            'ts'      => gmdate('c'),
        ];
        self::atomicSave($id, $data);
        return true;
    }

    /** Return the full conversation transcript. */
    public static function readTranscript(string $id): array
    {
        $data = self::readSession($id);
        return $data['transcript'] ?? [];
    }

    /** Rename a session (e.g. derive a title from the user's first answer). */
    public static function setTitle(string $id, string $title): bool
    {
        $data = self::readSession($id);
        if ($data === null) { return false; }
        $data['title'] = $title;
        self::atomicSave($id, $data);
        return true;
    }

    // ───────────────────────── Port's methods ─────────────────────────

    /**
     * Merge updated domain coverage into the session and re-derive status.
     * Only the 8 known domain keys are accepted; unknown keys are ignored.
     */
    public static function writeDomainState(string $id, array $state): bool
    {
        $data = self::readSession($id);
        if ($data === null) { return false; }
        foreach (self::DOMAINS as $d) {
            if (isset($state[$d])) {
                $data['domain_state'][$d] = $state[$d] === 'COVERED' ? 'COVERED' : 'OPEN';
            }
        }
        $covered = count(array_filter($data['domain_state'], fn($v) => $v === 'COVERED'));
        $data['status'] = $covered === count(self::DOMAINS) ? 'complete' : 'in_progress';
        self::atomicSave($id, $data);
        return true;
    }

    /** Return the 8-domain coverage map for a session. */
    public static function readDomainState(string $id): array
    {
        $data = self::readSession($id);
        return $data['domain_state'] ?? self::blankDomainState();
    }

    /**
     * Persist the extracted answer detail for a single domain, and optionally the
     * GENUINE coverage verdict from the extraction agent (true = the agent judged
     * the domain actually covered; false = it was force-advanced by the turn cap
     * without genuine coverage). Recording the real verdict lets the build-plan
     * JSON view surface any domain that was completed but not truly covered.
     */
    public static function writeDomainAnswer(string $id, string $domain, string $detail, ?bool $covered = null): bool
    {
        $data = self::readSession($id);
        if ($data === null) { return false; }
        if (!isset($data['domain_answers'])) { $data['domain_answers'] = []; }
        $data['domain_answers'][$domain] = $detail;
        if ($covered !== null) {
            if (!isset($data['domain_coverage'])) { $data['domain_coverage'] = []; }
            $data['domain_coverage'][$domain] = $covered;
        }
        self::atomicSave($id, $data);
        return true;
    }

    /** Return all saved domain answer details, keyed by domain. */
    public static function readDomainAnswers(string $id): array
    {
        $data = self::readSession($id);
        return $data['domain_answers'] ?? [];
    }

    /** Return the genuine per-domain coverage verdicts (domain => bool), keyed by domain. */
    public static function readDomainCoverage(string $id): array
    {
        $data = self::readSession($id);
        return $data['domain_coverage'] ?? [];
    }

    // ──────────────── idempotency: replay instead of re-running ────────────────

    /**
     * How many processed-message receipts to keep per session, and how long a
     * content-hash receipt stays replayable.
     *
     * The endpoint's flock only stops requests that OVERLAP; duplicates that
     * arrive back-to-back (a spam-clicked Send, a retry, a stale tab) each get
     * the lock cleanly and used to run the whole chain again — appending a second
     * copy of the answer and billing a second round of LLM calls. These receipts
     * close that hole: the same submission returns the response it produced the
     * first time instead of executing anything.
     *
     * A client-supplied id identifies one submission exactly, so it replays
     * forever. A content hash is only a guess — the user may legitimately send
     * "yes" twice, ten minutes apart — so hash receipts expire.
     */
    const DEDUPE_KEEP           = 25;
    const DEDUPE_WINDOW_SECONDS = 120;

    /**
     * Return the stored result for an already-processed submission, or null if
     * this is genuinely new work.
     *
     * @param bool $timeBound true for content-hash keys (expire after
     *                        DEDUPE_WINDOW_SECONDS), false for client-supplied ids.
     */
    public static function findProcessedResult(string $id, string $key, bool $timeBound = false): ?array
    {
        $data  = self::readSession($id);
        $entry = $data['processed_messages'][$key] ?? null;
        if (!is_array($entry) || !is_array($entry['result'] ?? null)) { return null; }

        if ($timeBound) {
            $ts = strtotime((string) ($entry['ts'] ?? ''));
            if ($ts === false || (time() - $ts) > self::DEDUPE_WINDOW_SECONDS) { return null; }
        }
        return $entry['result'];
    }

    /** Record what a submission produced, so a duplicate can replay it. */
    public static function recordProcessedResult(string $id, string $key, array $result): bool
    {
        $data = self::readSession($id);
        if ($data === null) { return false; }
        if (!isset($data['processed_messages']) || !is_array($data['processed_messages'])) {
            $data['processed_messages'] = [];
        }

        // Delete-then-append keeps insertion order == recency, so the slice below
        // always evicts the oldest receipts.
        unset($data['processed_messages'][$key]);
        $data['processed_messages'][$key] = ['ts' => gmdate('c'), 'result' => $result];
        if (count($data['processed_messages']) > self::DEDUPE_KEEP) {
            $data['processed_messages'] =
                array_slice($data['processed_messages'], -self::DEDUPE_KEEP, null, true);
        }

        self::atomicSave($id, $data);
        return true;
    }

    // ─────────────── per-domain turn counter: the loop breaker ───────────────

    /**
     * Agent turns spent on one domain, counted explicitly rather than inferred
     * from a transcript slice.
     *
     * The Orchestrator force-advances a domain that won't close on its own. That
     * guard used to count agent turns inside the last 12 transcript entries,
     * which silently read 0 whenever several user messages landed in a row — so
     * the exact situation that produced a runaway question loop was also the one
     * that disabled the loop breaker. A counter keyed by domain can't be fooled
     * by transcript shape.
     */
    public static function bumpDomainTurns(string $id, string $domain): int
    {
        $data = self::readSession($id);
        if ($data === null) { return 0; }
        $next = (int) ($data['domain_turns'][$domain] ?? 0) + 1;
        $data['domain_turns'][$domain] = $next;
        self::atomicSave($id, $data);
        return $next;
    }

    /** Clear the counter once a domain closes, so a reopened domain starts fresh. */
    public static function resetDomainTurns(string $id, string $domain): bool
    {
        $data = self::readSession($id);
        if ($data === null) { return false; }
        unset($data['domain_turns'][$domain]);
        self::atomicSave($id, $data);
        return true;
    }

    /** Turns already spent on a domain (0 if it has never been active). */
    public static function readDomainTurns(string $id, string $domain): int
    {
        $data = self::readSession($id);
        return (int) ($data['domain_turns'][$domain] ?? 0);
    }

    /**
     * Persist the Compiler Agent's generated 5-prompt build plan (FP9).
     * Accepts the assoc form (prompt_1 … prompt_5) the ManifestGenerator returns
     * and stores it as an ordered indexed array [p1 … p5] so build_plan.php can
     * render it directly with $builtPlan[$i].
     */
    public static function writeBuildPlan(string $id, array $plan): bool
    {
        $data = self::readSession($id);
        if ($data === null) { return false; }
        $ordered = [];
        for ($i = 1; $i <= 5; $i++) {
            $ordered[] = (string) ($plan['prompt_' . $i] ?? '');
        }
        $data['build_plan'] = $ordered;
        self::atomicSave($id, $data);
        return true;
    }

    /**
     * Permanently delete a session's .json file (and any stray temp write).
     * Path-traversal guarded via safeId(). Returns true if the id was valid and
     * the file is now gone (or was already absent).
     */
    public static function deleteSession(string $id): bool
    {
        if (!self::safeId($id)) { return false; }
        $path = self::path($id);
        if (is_file($path))        { @unlink($path); }
        if (is_file($path . '.tmp')) { @unlink($path . '.tmp'); }
        return !is_file($path);
    }

    // ──────────────────── backs the previous-sessions UI ────────────────────

    /**
     * Summaries of every saved session, newest first. Used by the Landing
     * screen and the hamburger drawer.
     * @return array<int,array{id:string,title:string,updated_at:string,covered:int,total:int,status:string}>
     */
    public static function listSessions(): array
    {
        $out = [];
        foreach (glob(self::storeDir() . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!is_array($data) || !isset($data['session_id'])) { continue; }
            $covered = count(array_filter($data['domain_state'] ?? [], fn($v) => $v === 'COVERED'));
            $out[] = [
                'id'         => $data['session_id'],
                'title'      => $data['title'] ?? 'Untitled session',
                'updated_at' => $data['updated_at'] ?? '',
                'covered'    => $covered,
                'total'      => count(self::DOMAINS),
                'status'     => $data['status'] ?? 'in_progress',
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
        return $out;
    }
}
