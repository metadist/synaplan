<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * Everything the platform knows about one AI provider that is not a model
 * row: its name, its capabilities, the hosts it calls, how it is
 * authenticated, its request dialects, its recommended defaults and how it
 * is drawn. Declared by the provider class itself
 * ({@see DescribesProviderInterface}) and collected by {@see ProviderCatalog},
 * so that adding a provider means adding one class (`03_contracts.md` §2).
 */
final readonly class ProviderDescriptor
{
    /**
     * @param string                   $name              lowercase; equals the registry tag key and BMODELS.BSERVICE
     * @param list<ProviderCapability> $capabilities      one `app.ai.<capability>` tag each
     * @param list<ProviderEndpoint>   $endpoints         every host the adapter calls
     * @param ?ProviderKeySpec         $key               null = no credential at all
     * @param ?ProviderDefaults        $defaults          null = no recommended bindings
     * @param ?int                     $liveVoiceSessions concurrency cap when the provider offers live voice
     */
    public function __construct(
        public string $name,
        public string $displayName,
        public array $capabilities,
        public array $endpoints,
        public ?ProviderKeySpec $key,
        public ProviderDialects $dialects,
        public ?ProviderDefaults $defaults,
        public ProviderPricing $pricing,
        public ?int $liveVoiceSessions,
        public ProviderBranding $branding,
    ) {
        if ($name !== strtolower($name) || '' === $name) {
            throw new \InvalidArgumentException(sprintf('Provider name "%s" must be lowercase and non-empty.', $name));
        }
    }

    public function hasCapability(ProviderCapability $capability): bool
    {
        return \in_array($capability, $this->capabilities, true);
    }

    /** What the key-validation endpoint says about served models; none without a key. */
    public function modelListing(): ModelListing
    {
        return $this->key->modelListing ?? ModelListing::None;
    }

    public function hasPlatformKey(): bool
    {
        return ProviderKeyStoreKind::Platform === $this->key?->store;
    }

    /** A person may replace the platform key with their own on the Messages gateway. */
    public function hasUserKey(): bool
    {
        return UserCredential::Gateway === $this->key?->userCredential;
    }

    /**
     * System-config field keys that show this provider's help hint: the
     * branding's explicit list, else the key's env var when there is a hint.
     *
     * @return list<string>
     */
    public function helpFields(): array
    {
        if ([] !== $this->branding->helpFields) {
            return $this->branding->helpFields;
        }
        if (null === $this->branding->helpKey || null === $this->key?->envVar) {
            return [];
        }

        return [$this->key->envVar];
    }

    public function chatCompletionsEndpoint(): ?ProviderEndpoint
    {
        foreach ($this->endpoints as $endpoint) {
            if (EndpointPurpose::ChatCompletions === $endpoint->purpose) {
                return $endpoint;
            }
        }

        return null;
    }

    /** The fixed `…/chat/completions` URL, null for a local host or a provider without one. */
    public function chatCompletionsUrl(): ?string
    {
        return $this->chatCompletionsEndpoint()?->url;
    }

    /** True for an install-configured Chat Completions host (Ollama, the endpoint registry). */
    public function isLocal(): bool
    {
        return true === $this->chatCompletionsEndpoint()?->isLocal();
    }

    /**
     * Every distinct host of every endpoint and of the key-validation URL.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        $hosts = [];
        foreach ($this->endpoints as $endpoint) {
            $host = $endpoint->host();
            if (null !== $host) {
                $hosts[$host] = true;
            }
        }
        $validationHost = $this->key?->validation?->host();
        if (null !== $validationHost) {
            $hosts[$validationHost] = true;
        }

        return array_keys($hosts);
    }

    /**
     * Sort key for {@see ProviderCatalog::all()}: keyed providers in their
     * Admin → AI keys order, then everything else by name.
     */
    public function rank(): int
    {
        $order = $this->key->presentation->listOrder ?? 0;

        return $order > 0 ? $order : PHP_INT_MAX;
    }
}
