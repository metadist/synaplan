<?php

declare(strict_types=1);

namespace App\Service\OAuth;

final class OAuthProviderRegistry
{
    /** @var array<string, OAuthProviderSource> */
    private array $byProvider = [];

    /**
     * @param iterable<OAuthProviderSource> $sources
     */
    public function __construct(iterable $sources)
    {
        foreach ($sources as $source) {
            $this->byProvider[$source->provider()] = $source;
        }
    }

    public function has(string $provider): bool
    {
        return isset($this->byProvider[$provider]);
    }

    /**
     * @throws OAuthException when the provider is unknown or not configured —
     *                        the two cases an operator can actually fix, kept distinct in the message
     */
    public function get(string $provider): OAuthProviderSource
    {
        $source = $this->byProvider[$provider] ?? null;
        if (null === $source) {
            throw new OAuthException(sprintf('Unknown OAuth provider "%s"', $provider));
        }

        if (!$source->isConfigured()) {
            throw new OAuthException(sprintf('OAuth provider "%s" is not configured on this installation', $provider));
        }

        return $source;
    }

    /**
     * @return list<string>
     */
    public function configuredProviders(): array
    {
        $ids = [];
        foreach ($this->byProvider as $provider => $source) {
            if ($source->isConfigured()) {
                $ids[] = $provider;
            }
        }

        return $ids;
    }
}
