<?php

declare(strict_types=1);

namespace App\Bundle;

final readonly class ImportOptions
{
    public const CONFLICT_SKIP = 'skip';
    public const CONFLICT_OVERWRITE = 'overwrite';

    public function __construct(
        public string $conflict = self::CONFLICT_SKIP,
    ) {
        if (!in_array($this->conflict, [self::CONFLICT_SKIP, self::CONFLICT_OVERWRITE], true)) {
            throw new \InvalidArgumentException('conflict must be skip or overwrite');
        }
    }
}
