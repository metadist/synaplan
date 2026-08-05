<?php

declare(strict_types=1);

namespace App\Service\Update;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches, validates and caches the published release manifest.
 *
 * Mirrors {@see \App\Service\MarketingNews\MarketingNewsFeedService}: a plain GET
 * with a short timeout, graceful degradation on every failure, and a cache in
 * front of it. The request carries no instance identifier, no telemetry and no
 * query parameters — it is a static file download.
 *
 * Every failure mode (transport error, HTTP error status, invalid JSON, unknown
 * schema version, missing or malformed fields) resolves to null. The caller
 * turns that into a recorded error; nothing ever throws out of here.
 */
final readonly class UpdateManifestClient
{
    public const SUPPORTED_SCHEMA = 1;

    private const CACHE_TTL_SECONDS = 21600; // 6 h
    private const FAILURE_CACHE_TTL_SECONDS = 300;
    private const FETCH_TIMEOUT_SECONDS = 5;
    private const MAX_RESPONSE_BYTES = 65536;

    /**
     * A release version as the manifest may spell it: up to four numeric
     * segments plus an optional pre-release/build suffix ('4.1.0-rc.1').
     */
    private const VERSION_PATTERN = '/^\d+(\.\d+){0,3}([-+][0-9A-Za-z.\-]+)?$/';

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The manifest behind $manifestUrl, or null when nothing usable was
     * obtained. Pass $force to bypass the cache (used by `--force`).
     */
    public function fetch(string $manifestUrl, bool $force = false): ?ReleaseManifest
    {
        $cacheKey = 'update_manifest_'.hash('sha256', $manifestUrl);

        try {
            if ($force) {
                $this->cache->delete($cacheKey);
            }

            /** @var array{version: string, notesUrl: string|null, severity: string, releasedAt: string|null, yanked: list<string>}|null $payload */
            $payload = $this->cache->get($cacheKey, function (ItemInterface $item) use ($manifestUrl): ?array {
                $payload = $this->download($manifestUrl);
                // A failed fetch is cached only briefly: a transient outage must
                // not suppress the next daily check for six hours.
                $item->expiresAfter(null === $payload ? self::FAILURE_CACHE_TTL_SECONDS : self::CACHE_TTL_SECONDS);

                return $payload;
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Update manifest cache/fetch failed', [
                'manifestUrl' => $manifestUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (null === $payload) {
            return null;
        }

        return new ReleaseManifest(
            version: $payload['version'],
            notesUrl: $payload['notesUrl'],
            severity: $payload['severity'],
            releasedAt: $payload['releasedAt'],
            yankedVersions: $payload['yanked'],
        );
    }

    /**
     * The validated manifest as a plain array, or null on any failure.
     *
     * Plain arrays (not the value object) are what goes into the cache, so the
     * cached shape stays independent of the class layout.
     *
     * @return array{version: string, notesUrl: string|null, severity: string, releasedAt: string|null, yanked: list<string>}|null
     */
    private function download(string $manifestUrl): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $manifestUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Synaplan/1.0 (+https://www.synaplan.com)',
                ],
                'timeout' => self::FETCH_TIMEOUT_SECONDS,
            ]);

            if ($response->getStatusCode() >= 400) {
                $this->logger->warning('Update manifest returned an error status', [
                    'manifestUrl' => $manifestUrl,
                    'status' => $response->getStatusCode(),
                ]);

                return null;
            }

            $body = $response->getContent();
        } catch (\Throwable $e) {
            $this->logger->warning('Update manifest fetch failed', [
                'manifestUrl' => $manifestUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (\strlen($body) > self::MAX_RESPONSE_BYTES) {
            $this->logger->warning('Update manifest is larger than the accepted maximum', [
                'manifestUrl' => $manifestUrl,
                'bytes' => \strlen($body),
            ]);

            return null;
        }

        return $this->parse($body, $manifestUrl);
    }

    /**
     * @return array{version: string, notesUrl: string|null, severity: string, releasedAt: string|null, yanked: list<string>}|null
     */
    private function parse(string $body, string $manifestUrl): ?array
    {
        try {
            $decoded = json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('Update manifest is not valid JSON', [
                'manifestUrl' => $manifestUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        // An unknown schema version means a newer publisher format we cannot
        // interpret: treat it as "no usable manifest" instead of guessing.
        $schema = $decoded['schema'] ?? null;
        if (!\is_int($schema) || self::SUPPORTED_SCHEMA !== $schema) {
            $this->logger->warning('Update manifest uses an unsupported schema version', [
                'manifestUrl' => $manifestUrl,
                'schema' => \is_scalar($schema) ? $schema : null,
            ]);

            return null;
        }

        $stable = $decoded['stable'] ?? null;
        if (!\is_array($stable)) {
            return null;
        }

        $version = $stable['version'] ?? null;
        if (!\is_string($version) || !$this->isValidVersion(trim($version))) {
            $this->logger->warning('Update manifest has no usable stable version', [
                'manifestUrl' => $manifestUrl,
            ]);

            return null;
        }

        return [
            'version' => trim($version),
            'notesUrl' => $this->readUrl($stable['notesUrl'] ?? null),
            'severity' => ReleaseManifest::SEVERITY_SECURITY === ($stable['severity'] ?? null)
                ? ReleaseManifest::SEVERITY_SECURITY
                : ReleaseManifest::SEVERITY_NORMAL,
            'releasedAt' => $this->readTimestamp($stable['releasedAt'] ?? null),
            'yanked' => $this->readVersionList($decoded['yanked'] ?? null),
        ];
    }

    private function isValidVersion(string $version): bool
    {
        return '' !== $version && 1 === preg_match(self::VERSION_PATTERN, $version);
    }

    private function readUrl(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $url = trim($value);
        if (false === filter_var($url, \FILTER_VALIDATE_URL)) {
            return null;
        }

        return \in_array(parse_url($url, \PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    }

    private function readTimestamp(mixed $value): ?string
    {
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }

        return trim($value);
    }

    /**
     * @return list<string>
     */
    private function readVersionList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $versions = [];
        foreach ($value as $candidate) {
            if (\is_string($candidate) && $this->isValidVersion(trim($candidate))) {
                $versions[] = trim($candidate);
            }
        }

        return array_values(array_unique($versions));
    }
}
