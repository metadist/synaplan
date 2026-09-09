<?php

declare(strict_types=1);

namespace App\AI\Import;

/**
 * Lists the models an import source currently offers. Extracted as a port so
 * the scheduled re-check can be unit-tested against a canned listing without a
 * live endpoint.
 */
interface ModelDiscovererInterface
{
    public function discover(string $source): DiscoveryResult;
}
