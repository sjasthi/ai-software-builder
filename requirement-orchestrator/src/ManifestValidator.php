<?php
/**
 * ManifestValidator (FP9 — Port) — Stage 1 validation of the Compiler Agent output.
 *
 * Pure logic (no LLM, no DB), mirroring Port's programmatic gate() style: given a
 * generated build plan it returns a pass/fail verdict plus a list of violations. It
 * enforces the Big Picture Plan §5 Week-7/8 acceptance criteria:
 *   1. all 5 labelled prompt sections are present and non-empty,
 *   2. no conversational filler openings ("Sure,", "Here is", "Of course", ...),
 *   3. no unfilled template placeholders ([pain_points], [end_result], ...),
 *   4. each prompt carries real, domain-specific content (not just a bare label).
 *
 * Reusable by the verification test today and by the runtime gate / validate_manifest.php later.
 */
class ManifestValidator
{
    /** Filler openings that must never begin a compiled prompt. */
    public const FILLER_PATTERNS = [
        '/^\s*(Sure|Here is|Here\'?s|Of course|Certainly|Great|Absolutely|Got it|No problem)\b/i',
    ];

    /** An unfilled template placeholder, e.g. [pain_points]. */
    private const PLACEHOLDER_PATTERN = '/\[[a-z_]+\]/';

    /** Minimum characters for a prompt to count as substantive, domain-specific content. */
    private const MIN_CONTENT_LENGTH = 40;

    /**
     * Validate a build plan.
     * @param array $plan assoc with keys prompt_1 … prompt_5
     * @return array{valid:bool,violations:array<int,string>}
     */
    public static function validate(array $plan): array
    {
        $violations = [];

        for ($i = 1; $i <= 5; $i++) {
            $key   = 'prompt_' . $i;
            $value = isset($plan[$key]) ? trim((string) $plan[$key]) : '';

            if ($value === '') {
                $violations[] = "{$key}: missing or empty";
                continue;   // nothing more to check on an empty prompt
            }

            if (mb_strlen($value) < self::MIN_CONTENT_LENGTH) {
                $violations[] = "{$key}: too short to be domain-specific content";
            }

            foreach (self::FILLER_PATTERNS as $pattern) {
                if (preg_match($pattern, $value)) {
                    $violations[] = "{$key}: begins with conversational filler";
                    break;
                }
            }

            if (preg_match(self::PLACEHOLDER_PATTERN, $value)) {
                $violations[] = "{$key}: contains an unfilled placeholder";
            }
        }

        return ['valid' => $violations === [], 'violations' => $violations];
    }
}
