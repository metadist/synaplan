<?php

declare(strict_types=1);

namespace App\AI\Provider\Google;

/**
 * Public Gemini 4 ids (`gemini-4-argon`, `gemini-4-carbon`) that share one request shape.
 *
 * They accept `thinkingLevel` (`low`, `medium`, `high`) and reject `thinkingBudget`
 * and `minimal` with HTTP 400. Sampling parameters (`temperature`, `topP`, `topK`)
 * are ignored. Permissive cyber ids are not public API models and must not match,
 * so a request for one never takes this path.
 */
final class Gemini4Models
{
    /** Hard prompts can think for minutes before the first token. */
    public const TIMEOUT_SECONDS = 900;

    /**
     * Default synchronous output cap. A longer decode is a separate, opt-in
     * continuation loop and is not enabled here.
     */
    public const MAX_OUTPUT_TOKENS = 65536;

    private const LOW_BUDGET_UPPER_BOUND = 2048;

    private const MEDIUM_BUDGET_UPPER_BOUND = 8192;

    public static function usesThinkingLevel(string $model): bool
    {
        return 1 === preg_match(
            '/(?:^|[:\/])gemini-4-(?:argon|carbon)(?:$|[:\/])/',
            strtolower(trim($model)),
        );
    }

    /**
     * Map a cross-provider semantic effort onto a valid Gemini 4 `thinkingLevel`.
     *
     * Gemini 4 has no off switch (`minimal` returns HTTP 400), so `off`/`none`
     * map to `low`. Higher tiers (`xhigh`, `max`) clamp to `high`. Omitted or
     * unrecognised values return null so Google's default (`medium`) applies.
     */
    public static function thinkingLevel(?string $effort): ?string
    {
        if (null === $effort) {
            return null;
        }

        return match (strtolower(trim($effort))) {
            'off', 'none', 'disabled', 'low' => 'low',
            'medium', 'dynamic' => 'medium',
            'high', 'xhigh', 'max' => 'high',
            default => null,
        };
    }

    /**
     * Translate Anthropic Messages `thinking` / `output_config.effort` fields
     * into a Gemini 4 `thinkingLevel` before those keys are stripped.
     *
     * @param array<string, mixed> $requestBody
     */
    public static function thinkingLevelFromMessagesRequest(array $requestBody): ?string
    {
        $thinking = \is_array($requestBody['thinking'] ?? null) ? $requestBody['thinking'] : [];
        $type = \is_string($thinking['type'] ?? null) ? strtolower($thinking['type']) : null;
        if ('disabled' === $type) {
            return 'low';
        }

        $outputConfig = \is_array($requestBody['output_config'] ?? null) ? $requestBody['output_config'] : [];
        $effort = $outputConfig['effort'] ?? null;
        if (\is_string($effort) && '' !== trim($effort)) {
            $normalized = strtolower(trim($effort));

            return 'minimal' === $normalized ? 'low' : self::thinkingLevel($normalized);
        }

        if ('enabled' !== $type) {
            return null;
        }

        $budget = $thinking['budget_tokens'] ?? null;
        if (!\is_int($budget) || $budget <= 0) {
            return 'medium';
        }
        if ($budget < self::LOW_BUDGET_UPPER_BOUND) {
            return 'low';
        }
        if ($budget < self::MEDIUM_BUDGET_UPPER_BOUND) {
            return 'medium';
        }

        return 'high';
    }
}
