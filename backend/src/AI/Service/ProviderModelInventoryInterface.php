<?php

declare(strict_types=1);

namespace App\AI\Service;

/**
 * Reads which models a provider currently serves.
 *
 * Exists so availability logic can be exercised without provider credentials or
 * network access — see `ModelAvailabilityCheckerTest`.
 */
interface ProviderModelInventoryInterface
{
    /**
     * The provider's full model list, or why it could not be obtained.
     */
    public function fetch(string $provider): ProviderModelListing;

    /**
     * Whether the provider still knows one specific model id.
     */
    public function probe(string $provider, string $providerModelId): ModelProbeResult;
}
