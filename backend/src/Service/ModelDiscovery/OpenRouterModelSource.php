<?php

declare(strict_types=1);

namespace App\Service\ModelDiscovery;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches OpenRouter's public model list and maps it to {@see UpstreamModel}.
 *
 * OpenRouter is a reseller hint source only — never a price authority. Its ids
 * and own variants can differ from what the first-party provider serves.
 */
final readonly class OpenRouterModelSource
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/models';

    private const TIMEOUT_SECONDS = 20;

    private const TOKENS_PER_MILLION = 1_000_000;

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<UpstreamModel>
     *
     * @throws ModelDiscoveryUnavailableException
     */
    public function fetch(): array
    {
        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new ModelDiscoveryUnavailableException(sprintf('OpenRouter model list request failed: %s', $e->getMessage()), 0, $e);
        }

        if ($status < 200 || $status >= 300) {
            throw new ModelDiscoveryUnavailableException(sprintf('OpenRouter model list returned HTTP %d (expected 2xx). Body starts with: %s', $status, self::preview($body)));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ModelDiscoveryUnavailableException(sprintf('OpenRouter model list is not valid JSON: %s. Body starts with: %s', $e->getMessage(), self::preview($body)), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new ModelDiscoveryUnavailableException('OpenRouter model list decoded to a non-object payload.');
        }

        if (!isset($decoded['data']) || !is_array($decoded['data']) || [] === $decoded['data']) {
            throw new ModelDiscoveryUnavailableException('OpenRouter model list is missing a non-empty "data" array — refusing to treat this as "nothing new".');
        }

        $models = [];
        foreach ($decoded['data'] as $index => $entry) {
            if (!is_array($entry)) {
                throw new ModelDiscoveryUnavailableException(sprintf('OpenRouter model list entry at index %s is not an object.', (string) $index));
            }

            $models[] = $this->parseEntry($entry, (string) $index);
        }

        return $models;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function parseEntry(array $entry, string $index): UpstreamModel
    {
        $id = $entry['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new ModelDiscoveryUnavailableException(sprintf('OpenRouter model list entry at index %s has no string "id".', $index));
        }

        $created = $entry['created'] ?? null;
        if (!is_int($created)) {
            throw new ModelDiscoveryUnavailableException(sprintf('OpenRouter model list entry "%s" has no int "created" (got %s).', $id, get_debug_type($created)));
        }

        $slash = strpos($id, '/');
        $vendor = false === $slash ? '' : substr($id, 0, $slash);

        $pricing = $entry['pricing'] ?? null;
        $priceIn = null;
        $priceOut = null;
        $cacheRead = null;
        if (is_array($pricing)) {
            $priceIn = self::perTokenToPer1M($pricing['prompt'] ?? null);
            $priceOut = self::perTokenToPer1M($pricing['completion'] ?? null);
            $cacheRead = self::perTokenToPer1M($pricing['input_cache_read'] ?? null);
        }

        return new UpstreamModel(
            openRouterId: $id,
            vendor: $vendor,
            created: (new \DateTimeImmutable('@'.$created))->setTimezone(new \DateTimeZone('UTC')),
            priceInPer1M: $priceIn,
            priceOutPer1M: $priceOut,
            cacheReadPer1M: $cacheRead,
        );
    }

    private static function perTokenToPer1M(mixed $value): ?float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        if (is_string($value) && '' === trim($value)) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value * self::TOKENS_PER_MILLION;
    }

    private static function preview(string $body): string
    {
        $trimmed = preg_replace('/\s+/', ' ', $body) ?? $body;

        return strlen($trimmed) > 120 ? substr($trimmed, 0, 117).'...' : $trimmed;
    }
}
