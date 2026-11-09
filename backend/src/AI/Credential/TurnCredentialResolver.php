<?php

declare(strict_types=1);

namespace App\AI\Credential;

/**
 * The gateway and the app chat pick a key here, before a provider is called.
 *
 * The gateway may use a person's own key and only then the operator key,
 * and only when that fallback is allowed. The app chat uses the operator
 * key. A local host (Ollama, an OpenAI-compatible endpoint) needs no store
 * key; the translator attaches the endpoint credential itself.
 */
final readonly class TurnCredentialResolver
{
    public function __construct(
        private UserProviderKeyResolver $users,
        private ?ProviderKeyStore $store = null,
    ) {
    }

    public function forGateway(string $provider, ?int $userId, bool $allowOperator): TurnCredential
    {
        if ($this->users->isLocalHost($provider)) {
            return TurnCredential::local($provider, $userId);
        }
        $resolved = $this->users->resolve($provider, $userId, $allowOperator);
        if (null === $resolved) {
            return TurnCredential::missing($provider, $userId);
        }

        return new TurnCredential(
            provider: strtolower(trim($provider)),
            key: $resolved['key'],
            source: $resolved['source'],
            userId: $userId,
            endpointId: null,
            operatorFallback: 'operator' === $resolved['source'],
            keySource: $resolved['source'],
            present: true,
        );
    }

    public function forAppChat(string $provider): TurnCredential
    {
        if ($this->users->isLocalHost($provider)) {
            return TurnCredential::local($provider, null);
        }
        $key = $this->store?->getKey($provider);
        if (!is_string($key) || '' === $key) {
            return TurnCredential::missing($provider, null);
        }

        return new TurnCredential(
            provider: strtolower(trim($provider)),
            key: $key,
            source: 'operator',
            userId: null,
            endpointId: null,
            operatorFallback: true,
            keySource: 'operator',
            present: true,
        );
    }
}
