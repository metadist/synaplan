<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A fixed mode and model list, so the suite does not read the database. */
#[Exclude]
final readonly class FixedLeadSettings implements LeadSettings
{
    /**
     * @param list<string> $models
     */
    public function __construct(
        private string $mode,
        private array $models = [],
    ) {
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function models(): array
    {
        return $this->models;
    }
}
