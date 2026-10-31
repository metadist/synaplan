<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * The discrete reasoning levels a provider's models expose, as ordered
 * rules. The first matching rule wins, so longer prefixes come first. A
 * model no rule matches has no level knob and keeps the on/off toggle.
 */
final readonly class ReasoningLadder
{
    /**
     * @param list<ReasoningLevelRule> $rules
     */
    public function __construct(
        public array $rules,
    ) {
    }

    /**
     * Prefix rules in the given order.
     *
     * @param array<string, list<string>> $byPrefix model id prefix => levels, cheapest first
     */
    public static function prefixes(array $byPrefix): self
    {
        $rules = [];
        foreach ($byPrefix as $prefix => $levels) {
            $rules[] = new ReasoningLevelRule(ReasoningMatch::Prefix, [strtolower((string) $prefix)], $levels);
        }

        return new self($rules);
    }

    /**
     * Every model of the provider accepts the same levels.
     *
     * @param list<string> $levels
     */
    public static function always(array $levels): self
    {
        return new self([new ReasoningLevelRule(ReasoningMatch::Any, [], $levels)]);
    }

    public function with(ReasoningLevelRule $rule): self
    {
        return new self([...$this->rules, $rule]);
    }

    /**
     * @return list<string>|null cheapest first, or null when no rule matches
     */
    public function levelsFor(string $providerModelId): ?array
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($providerModelId)) {
                return $rule->levels;
            }
        }

        return null;
    }
}
