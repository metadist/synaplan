<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

final readonly class PassageMap
{
    /**
     * @param list<Passage> $passages
     */
    public function __construct(public array $passages)
    {
    }

    public function text(string $id): ?string
    {
        foreach ($this->passages as $passage) {
            if ($passage->id === $id) {
                return $passage->text;
            }
        }

        return null;
    }
}
