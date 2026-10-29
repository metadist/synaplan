<?php

declare(strict_types=1);

namespace App\AI\Provider\Google;

/**
 * Gemini `usageMetadata`, with thinking tokens billed as output.
 *
 * Google reports `thoughtsTokenCount` beside `candidatesTokenCount` and
 * charges both at the output rate. Cached prompt tokens stay in
 * `cachedContentTokenCount`, so the catalog cache price can discount them.
 */
final class GoogleUsage
{
    /**
     * @param array<string, mixed> $usageMetadata
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int}
     */
    public static function fromMetadata(array $usageMetadata): array
    {
        $promptTokens = (int) ($usageMetadata['promptTokenCount'] ?? 0);
        $completionTokens = self::completionTokens($usageMetadata, 0);
        $cachedTokens = (int) ($usageMetadata['cachedContentTokenCount'] ?? 0);

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'cached_tokens' => $cachedTokens,
            'cache_creation_tokens' => 0,
        ];
    }

    /**
     * Fold one stream chunk into the running totals.
     *
     * A chunk that omits a field keeps the previous value. When the chunk
     * reports candidates, output is candidates plus thoughts (thoughts default
     * to 0). Thoughts without a fresh candidate count are added to the
     * previous output.
     *
     * @return array{0: int, 1: int, 2: int} prompt, completion, cached
     */
    public static function running(mixed $usageMetadata, int $promptTokens, int $completionTokens, int $cachedTokens): array
    {
        if (!\is_array($usageMetadata)) {
            return [$promptTokens, $completionTokens, $cachedTokens];
        }

        $prompt = \array_key_exists('promptTokenCount', $usageMetadata)
            ? (int) $usageMetadata['promptTokenCount']
            : $promptTokens;
        $cached = \array_key_exists('cachedContentTokenCount', $usageMetadata)
            ? (int) $usageMetadata['cachedContentTokenCount']
            : $cachedTokens;

        return [$prompt, self::completionTokens($usageMetadata, $completionTokens), $cached];
    }

    /**
     * @param array<string, mixed> $usageMetadata
     */
    private static function completionTokens(array $usageMetadata, int $previous): int
    {
        $hasCandidates = \array_key_exists('candidatesTokenCount', $usageMetadata);
        $hasThoughts = \array_key_exists('thoughtsTokenCount', $usageMetadata);
        if (!$hasCandidates && !$hasThoughts) {
            return $previous;
        }

        $thoughts = $hasThoughts ? (int) $usageMetadata['thoughtsTokenCount'] : 0;
        if (!$hasCandidates) {
            return $previous + $thoughts;
        }

        return (int) $usageMetadata['candidatesTokenCount'] + $thoughts;
    }
}
