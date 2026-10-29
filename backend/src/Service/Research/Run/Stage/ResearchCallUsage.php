<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

/**
 * What one model call in a research run used: its tokens and the cost the
 * person is charged. The facade meters every call; when it reports the
 * charged cost that figure wins, otherwise the raw catalog price stands in.
 */
final readonly class ResearchCallUsage
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public string $chargedCost,
        public bool $priceKnown = true,
    ) {
    }

    /**
     * @param array<string, mixed> $response the facade's chat response
     */
    public static function fromResponse(array $response, float $rawCost): self
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $prompt = self::tokens($usage, 'prompt_tokens');
        $completion = self::tokens($usage, 'completion_tokens');
        $total = self::tokens($usage, 'total_tokens');
        $charged = $response['facade_charged_cost'] ?? null;

        return new self(
            $prompt,
            $completion,
            $total > 0 ? $total : $prompt + $completion,
            is_string($charged) && '' !== $charged ? $charged : number_format(max(0.0, $rawCost), 6, '.', ''),
            false !== ($response['facade_price_known'] ?? true),
        );
    }

    /**
     * @param array<mixed> $usage
     */
    private static function tokens(array $usage, string $key): int
    {
        return is_numeric($usage[$key] ?? null) ? max(0, (int) $usage[$key]) : 0;
    }
}
