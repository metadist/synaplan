<?php

declare(strict_types=1);

namespace App\AI\Provider\Google;

/**
 * Public Gemini 4 ids that share one request shape.
 *
 * They accept thinkingLevel (low, medium, high) and reject thinkingBudget.
 * Sampling parameters are ignored. Permissive cyber ids are not public API
 * models and must not match, so a request for one never takes this path.
 */
final class Gemini4Models
{
    /** Hard prompts can think for minutes before the first token. */
    public const TIMEOUT_SECONDS = 900;

    /**
     * Default synchronous output cap. A longer decode is a separate, opt-in
     * API mode and is not enabled here.
     */
    public const MAX_OUTPUT_TOKENS = 65536;

    public static function usesThinkingLevel(string $model): bool
    {
        return 1 === preg_match(
            '/(?:^|\/)gemini-4-(?:argon|carbon)(?:$|[:\/])/',
            strtolower($model),
        );
    }
}
