<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** One catalog key for every model id, for the conformance suite. */
#[Exclude]
final readonly class FixedLeadCatalog implements LeadCatalog
{
    public function __construct(private ?string $key)
    {
    }

    public function key(int $modelId): ?string
    {
        return $this->key;
    }
}
