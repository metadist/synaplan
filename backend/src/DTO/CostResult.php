<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class CostResult
{
    /**
     * @param array<string, mixed> $priceSnapshot
     */
    public function __construct(
        public string $totalCost,
        public string $inputCost,
        public string $outputCost,
        public string $cacheSavings,
        public array $priceSnapshot,
        public int $billedInputTokens,
    ) {
    }
}
