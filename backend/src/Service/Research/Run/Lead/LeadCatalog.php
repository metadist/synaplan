<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

/** The catalog key of a model id, `service:providerId:tag`. */
interface LeadCatalog
{
    public function key(int $modelId): ?string;
}
