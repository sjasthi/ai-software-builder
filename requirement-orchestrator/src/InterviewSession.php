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

    /** Opening question for each domain (non-LLM fallback used until the
     *  Routing Agent / RequirementParser take over at FP7–FP8). */
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
