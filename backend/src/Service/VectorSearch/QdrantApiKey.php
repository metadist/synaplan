<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

/**
 * The optional Qdrant API key (`QDRANT_API_KEY`).
 *
 * An empty value sends no credential, which is what an open Qdrant expects.
 * A set value is the `api-key` header Qdrant and Qdrant Cloud require. The
 * raw key is never part of an error message or a cache key.
 */
final readonly class QdrantApiKey
{
    public const HEADER = 'api-key';

    public const PROBE_OK = 'ok';
    public const PROBE_DOWN = 'down';
    public const PROBE_TIMEOUT = 'timeout';
    public const PROBE_UNAUTHORIZED = 'unauthorized';
    public const PROBE_FORBIDDEN = 'forbidden';

    public function __construct(
        private string $value = '',
    ) {
    }

    public function isSet(): bool
    {
        return '' !== $this->normalized();
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public function apply(array $headers): array
    {
        if (!$this->isSet()) {
            return $headers;
        }

        $headers[self::HEADER] = $this->normalized();

        return $headers;
    }

    /**
     * Distinguishes cache entries when the key changes, without storing it.
     */
    public function cacheDiscriminator(): string
    {
        return $this->isSet() ? hash('sha256', $this->normalized()) : '';
    }

    public function failureMessage(int $status, string $body = ''): ?string
    {
        if (401 === $status) {
            return $this->isSet()
                ? 'Qdrant rejected the API key. Check that QDRANT_API_KEY matches the key configured on Qdrant.'
                : 'Qdrant requires an API key. Set QDRANT_API_KEY to the key configured on Qdrant.';
        }

        if (403 !== $status) {
            return null;
        }

        $detail = $this->safeDetail($body);
        $base = $this->isSet()
            ? 'Qdrant refused access (HTTP 403). QDRANT_API_KEY needs permission to list and manage collections.'
            : 'Qdrant refused access (HTTP 403). Set QDRANT_API_KEY if the service requires authentication.';

        return '' === $detail ? $base : $base.' '.$detail;
    }

    public function messageForProbe(string $outcome): ?string
    {
        return match ($outcome) {
            self::PROBE_UNAUTHORIZED => $this->failureMessage(401),
            self::PROBE_FORBIDDEN => $this->failureMessage(403),
            default => null,
        };
    }

    private function normalized(): string
    {
        return trim($this->value);
    }

    private function safeDetail(string $body): string
    {
        $detail = trim($body);
        $key = $this->normalized();
        if ('' !== $key) {
            $detail = str_replace($key, '[redacted]', $detail);
        }
        if (strlen($detail) > 180) {
            $detail = substr($detail, 0, 180);
        }

        return $detail;
    }
}
