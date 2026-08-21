<?php

declare(strict_types=1);

namespace App\AI\Service;

use App\Model\ModelCatalog;

/**
 * Turns an internal service key into the provider's branded name.
 *
 * BMODELS.BSERVICE is an identifier, not a label: it is inconsistently cased
 * ("ollama", "xAI", "triton") and cannot be put on screen as-is. CSS cannot
 * repair it either — `text-transform: capitalize` renders xAI as "XAI". The
 * provider registry already carries the correct spelling for every provider,
 * so this reads it rather than introducing a second list to keep in sync.
 *
 * Deliberately built on getUniqueProviders(): it returns the registered
 * instances without asking any of them whether they are reachable, unlike
 * getProvidersMetadata(). Callers here include the status page, which must
 * never trigger a provider call.
 */
final class ProviderDisplayNames
{
    /** @var array<string, string>|null */
    private ?array $names = null;

    public function __construct(private readonly ProviderRegistry $registry)
    {
    }

    /**
     * The branded name for a service key, or the key itself when this build
     * registers no provider for it — a catalog row still has to show something.
     */
    public function forService(string $service): string
    {
        return $this->all()[ModelCatalog::normalizeProvider($service)] ?? $service;
    }

    /**
     * @return array<string, string> branded name, keyed by normalised service
     */
    public function all(): array
    {
        if (null !== $this->names) {
            return $this->names;
        }

        $names = [];
        foreach ($this->registry->getUniqueProviders() as $key => $provider) {
            $names[ModelCatalog::normalizeProvider($key)] = $provider->getDisplayName();
        }

        return $this->names = $names;
    }
}
