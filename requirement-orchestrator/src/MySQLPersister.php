<?php
require_once __DIR__ . '/../config/database.php';

class MySQLPersister
{
    /** SHA-256 of the JSON session id → always 64 hex chars, satisfies schema CHECK. */
    private static function token(string $jsonId): string
    {
        return hash('sha256', $jsonId);
    }

    /**
     * Get or create the MySQL session row for a JSON session id.
     * Returns the numeric session_id, or null on failure.
     */
    public static function ensureSession(string $jsonId): ?int
    {
        try {
            $db    = getDB();
            $token = self::token($jsonId);

            $stmt = $db->prepare('SELECT session_id FROM sessions WHERE session_token = ?');
            $stmt->execute([$token]);
            $row = $stmt->fetch();
            if ($row) return (int) $row['session_id'];

            $db->prepare('INSERT INTO sessions (session_token) VALUES (?)')->execute([$token]);
            return (int) $db->lastInsertId();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Write one conversation exchange to conversation_log.
     */
    public static function logExchange(string $jsonId, string $role, string $content): void
    {
        try {
            $mysqlId = self::ensureSession($jsonId);
            if ($mysqlId === null) return;

            $enumRole = match(strtolower($role)) {
                'user'  => 'USER',
                'agent' => 'AGENT',
                default => 'SYSTEM',
            };

            $db = getDB();
            $db->prepare('INSERT INTO conversation_log (session_id, role, message) VALUES (?, ?, ?)')
               ->execute([$mysqlId, $enumRole, $content]);
        } catch (Throwable) {
            // Never surface DB errors to the user
        }
    }

    /**
     * Update the domain_state row after a domain agent marks a domain COVERED.
     * Inserts on first domain, updates on subsequent ones.
     */
    public static function updateDomain(string $jsonId, string $domain, string $detail, array $allDomainAnswers): void
    {
        $allowed = [
            'pain_points', 'data_sources', 'data_access', 'end_result',
            'stakeholders', 'audience_type', 'current_process', 'interaction_model',
        ];
        if (!in_array($domain, $allowed, true)) return;

        try {
            $mysqlId = self::ensureSession($jsonId);
            if ($mysqlId === null) return;

            $db          = getDB();
            $domainJson  = json_encode($allDomainAnswers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Build INSERT ... ON DUPLICATE KEY UPDATE so first and subsequent domains both work.
            $sql = "INSERT INTO domain_state (session_id, `{$domain}`, domain_json)
                    VALUES (?, 'COVERED', ?)
                    ON DUPLICATE KEY UPDATE
                        `{$domain}` = 'COVERED',
                        domain_json  = ?";

            $db->prepare($sql)->execute([$mysqlId, $domainJson, $domainJson]);
        } catch (Throwable) {
            // Never surface DB errors to the user
        }
    }

    /**
     * Write the generated build plan to generated_plans.
     * $prompts must have keys prompt_1 … prompt_5.
     */
    public static function writeBuildPlan(string $jsonId, array $prompts): void
    {
        try {
            $mysqlId = self::ensureSession($jsonId);
            if ($mysqlId === null) return;

            $db = getDB();
            $db->prepare(
                'INSERT INTO generated_plans (session_id, prompt_1, prompt_2, prompt_3, prompt_4, prompt_5)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $mysqlId,
                $prompts['prompt_1'] ?? '',
                $prompts['prompt_2'] ?? '',
                $prompts['prompt_3'] ?? '',
                $prompts['prompt_4'] ?? '',
                $prompts['prompt_5'] ?? '',
            ]);
        } catch (Throwable) {
            // Never surface DB errors to the user
        }
    }
}
