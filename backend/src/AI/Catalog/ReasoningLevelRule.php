<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * One rule of a reasoning ladder: the model ids it matches and the levels
 * they accept, cheapest first.
 */
final readonly class ReasoningLevelRule
{
    /**
     * @param list<string> $patterns lowercased; empty for {@see ReasoningMatch::Any}
     * @param list<string> $levels   cheapest first
     */
    public function __construct(
        public ReasoningMatch $match,
        public array $patterns,
        public array $levels,
    ) {
    }

    public function matches(string $providerModelId): bool
    {
        $id = strtolower($providerModelId);

        return match ($this->match) {
            ReasoningMatch::Any => true,
            ReasoningMatch::Prefix => array_any($this->patterns, static fn (string $prefix): bool => str_starts_with($id, $prefix)),
            ReasoningMatch::Contains => array_any($this->patterns, static fn (string $needle): bool => str_contains($id, $needle)),
        };
    }
}
