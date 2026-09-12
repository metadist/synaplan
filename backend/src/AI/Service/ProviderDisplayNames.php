<?php

declare(strict_types=1);

namespace App\AI\Service;

use App\AI\Interface\ProviderMetadataInterface;
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
        $this->names ??= [];
        $normalized = ModelCatalog::normalizeProvider($service);
        if (isset($this->names[$normalized])) {
            return $this->names[$normalized];
        }

        // Resolve one provider — do not enumerate every registered service on
        // each SSE status. The listing path (`all()`) still builds the full map
        // for the status page.
        try {
            $provider = $this->registry->getChatProvider($service);
            if ($provider instanceof ProviderMetadataInterface) {
                return $this->names[$normalized] = $provider->getDisplayName();
            }
        } catch (\Throwable) {
        }

        return $this->all()[$normalized] ?? $service;
    }

    /**
     * Add `provider_label` next to a `provider` service key in a progress
     * event's metadata, so the chat can say "claude-opus-4-8 by Anthropic".
     * Metadata without a provider, or with a label already set, is unchanged.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    public function enrich(array $metadata): array
    {
        if (isset($metadata['provider_label'])) {
            return $metadata;
        }
        $provider = $metadata['provider'] ?? null;
        if (!is_string($provider) || '' === trim($provider) || 'test' === strtolower(trim($provider))) {
            return $metadata;
        }

        $metadata['provider_label'] = $this->forService($provider);

        return $metadata;
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
