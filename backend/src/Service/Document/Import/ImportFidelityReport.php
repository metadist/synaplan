<?php

declare(strict_types=1);

namespace App\Service\Document\Import;

final readonly class ImportFidelityReport
{
    /**
     * @param list<string> $notes
     */
    public function __construct(
        public array $notes = [],
        public bool $lossy = false,
    ) {
    }

    public static function lossless(): self
    {
        return new self([], false);
    }

    /**
     * @param list<string> $notes
     */
    public static function lossy(array $notes): self
    {
        return new self($notes, true);
    }
}
