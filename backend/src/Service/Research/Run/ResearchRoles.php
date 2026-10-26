<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

final readonly class ResearchRoles
{
    /**
     * @param list<array{code: string, role: string, tokens: int|null}> $disclosures
     */
    public function __construct(
        public int $sorter,
        public int $reader,
        public int $lead,
        public int $writer,
        public int $checker,
        public bool $checkerSameFamily,
        public array $disclosures,
    ) {
    }
}
