<?php

namespace App\DTO;

final readonly class CostResult
{
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
