<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * One URL a provider adapter calls.
 *
 * A null URL marks an install-configured host (Ollama's base URL, an
 * OpenAI-compatible endpoint from the registry): the host comes from the
 * environment or the database, so the allowlist cannot be built from it and
 * the Messages gateway needs no provider key for it.
 */
final readonly class ProviderEndpoint
{
    public function __construct(
        public EndpointPurpose $purpose,
        public ?string $url,
    ) {
    }

    public static function chatCompletions(string $url): self
    {
        return new self(EndpointPurpose::ChatCompletions, $url);
    }

    public static function localChatCompletions(): self
    {
        return new self(EndpointPurpose::ChatCompletions, null);
    }

    public static function api(string $url): self
    {
        return new self(EndpointPurpose::Api, $url);
    }

    public function isLocal(): bool
    {
        return null === $this->url;
    }

    /**
     * The host of the URL, lowercased; null for an install-configured host.
     */
    public function host(): ?string
    {
        if (null === $this->url) {
            return null;
        }
        $host = parse_url($this->url, PHP_URL_HOST);

        return is_string($host) && '' !== $host ? strtolower($host) : null;
    }
}
