<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * The cheap authenticated request that proves a key works. `{key}` and
 * `{secret}` in a header value are substituted at probe time.
 */
final readonly class ProviderKeyValidation
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
    ) {
    }

    /** `Authorization: Bearer {key}` — the OpenAI-compatible shape. */
    public static function bearer(string $url): self
    {
        return new self('GET', $url, ['Authorization' => 'Bearer {key}']);
    }

    /**
     * A single custom header carrying the key.
     *
     * @param array<string, string> $extraHeaders
     */
    public static function header(string $url, string $name, string $value = '{key}', array $extraHeaders = []): self
    {
        return new self('GET', $url, [$name => $value] + $extraHeaders);
    }

    public function host(): ?string
    {
        $host = parse_url($this->url, PHP_URL_HOST);

        return is_string($host) && '' !== $host ? strtolower($host) : null;
    }

    /**
     * The shape today's key catalog stores, for the consumers that still read it.
     *
     * @return array{method: string, url: string, headers: array<string, string>}
     */
    public function toArray(): array
    {
        return ['method' => $this->method, 'url' => $this->url, 'headers' => $this->headers];
    }
}
