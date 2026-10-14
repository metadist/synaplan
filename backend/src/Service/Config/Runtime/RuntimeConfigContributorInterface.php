<?php

declare(strict_types=1);

namespace App\Service\Config\Runtime;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One area of GET /api/v1/config/runtime.
 *
 * Contributors return disjoint top-level keys. The `features` object is the
 * exception: several areas own flags inside it, and the assembler merges that
 * object one level deep. A repeated top-level key, or a repeated feature
 * flag, is a wiring bug.
 */
#[AutoconfigureTag('app.runtime_config.contributor')]
interface RuntimeConfigContributorInterface
{
    /**
     * @return array<string, mixed>
     */
    public function contribute(RuntimeConfigContext $context): array;
}
