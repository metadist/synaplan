<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

final readonly class Passage
{
    public function __construct(
        public string $id,
        public string $text,
    ) {
    }
}
