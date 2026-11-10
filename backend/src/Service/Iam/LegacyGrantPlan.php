<?php

declare(strict_types=1);

namespace App\Service\Iam;

/**
 * The grants an existing account would get from its current level, billing
 * record and audit trail. `rule1Only` is a Stripe or app-store grant and
 * nothing else: the dry run stays quiet about those.
 */
final readonly class LegacyGrantPlan
{
    /**
     * @param list<array{source: string, level: string, ref: ?string, created: int}> $grants
     */
    public function __construct(
        public array $grants,
        public bool $rule1Only,
    ) {
    }

    public function interesting(): bool
    {
        return [] !== $this->grants && !$this->rule1Only;
    }

    /**
     * @return array<string, array{level: string, ref: ?string, created: int}>
     */
    public function bySource(): array
    {
        $bySource = [];
        foreach ($this->grants as $grant) {
            $bySource[$grant['source']] = [
                'level' => $grant['level'],
                'ref' => $grant['ref'],
                'created' => $grant['created'],
            ];
        }

        return $bySource;
    }
}
