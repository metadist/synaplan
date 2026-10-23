<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * The part of a corpus item the scorer reads.
 *
 * @phpstan-type GoldEntry array{id: string, assertion: string, kind: string, match: list<string>}
 */
final readonly class CorpusFacts
{
    /**
     * @param list<GoldEntry> $entries
     * @param list<string>    $preferredSources
     * @param list<string>    $forbidden
     */
    public function __construct(
        public array $entries,
        public array $preferredSources,
        public array $forbidden,
    ) {
    }
}
