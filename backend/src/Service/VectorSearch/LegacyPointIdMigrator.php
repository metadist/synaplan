<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Rekeys Qdrant points whose primary ID is still in the legacy
 * Rust-microservice integer scheme to the canonical UUIDv5 derived from
 * their `_point_id` payload.
 *
 * Works on any collection that stores points with a `_point_id` payload.
 * Split out of {@see \App\Command\MigrateLegacyPointIdsCommand} so the
 * scroll + rekey loop can be unit-tested with {@see \Symfony\Component\HttpClient\MockHttpClient}.
 */
final class LegacyPointIdMigrator
{
    private const SCROLL_BATCH_LIMIT = 100;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $qdrantUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Scroll `$collection` and rekey every point whose primary ID is not
     * already the canonical UUIDv5 for its `_point_id` payload.
     *
     * @param int                                                                                 $limit   0 = no cap; otherwise migrate at most this many points before returning
     * @param \Closure(string $phase, string $logicalId, int|string $fromId, string $toUuid):void $onPoint Callback for progress reporting; see {@see LegacyPointIdMigrationReport} for phases
     */
    public function migrateCollection(
        string $collection,
        bool $apply,
        int $limit,
        ?\Closure $onPoint = null,
    ): LegacyPointIdMigrationReport {
        $scanned = 0;
        $legacy = 0;
        $migrated = 0;
        $errors = 0;

        $offset = null;

        do {
            $body = [
                'limit' => self::SCROLL_BATCH_LIMIT,
                'with_payload' => true,
                'with_vector' => true,
            ];
            if (null !== $offset) {
                $body['offset'] = $offset;
            }

            try {
                $response = $this->qdrantRequest('POST', "/collections/{$collection}/points/scroll", $body);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to scroll collection during legacy-point migration', [
                    'collection' => $collection,
                    'error' => $e->getMessage(),
                ]);

                return new LegacyPointIdMigrationReport($scanned, $legacy, $migrated, $errors + 1);
            }

            /** @var list<array{id: int|string, payload?: array<string, mixed>, vector?: list<float>}> $points */
            $points = $response['result']['points'] ?? [];

            foreach ($points as $point) {
                ++$scanned;

                $primaryId = $point['id'];
                $logicalId = $point['payload']['_point_id'] ?? null;

                if (!is_string($logicalId) || '' === $logicalId) {
                    if (null !== $onPoint) {
                        $onPoint('skip-missing-payload', '(none)', $primaryId, '(none)');
                    }
                    continue;
                }

                if (QdrantPointId::isCanonicalUuid($primaryId, $logicalId)) {
                    continue;
                }

                ++$legacy;

                $newUuid = QdrantPointId::uuidFor($logicalId);
                $vector = $point['vector'] ?? null;

                if (!is_array($vector) || [] === $vector) {
                    if (null !== $onPoint) {
                        $onPoint('skip-missing-vector', $logicalId, $primaryId, $newUuid);
                    }
                    ++$errors;
                    continue;
                }

                if (null !== $onPoint) {
                    $onPoint($apply ? 'migrate' : 'would-migrate', $logicalId, $primaryId, $newUuid);
                }

                if (!$apply) {
                    ++$migrated;
                    if (0 !== $limit && $migrated >= $limit) {
                        return new LegacyPointIdMigrationReport($scanned, $legacy, $migrated, $errors);
                    }
                    continue;
                }

                try {
                    // Atomic rekey: batch-delete the legacy primary ID and
                    // upsert the canonical UUID-keyed copy in one request.
                    // If the batch fails mid-way, Qdrant returns an error
                    // BEFORE either op is committed (validated upfront).
                    $this->qdrantRequest('POST', "/collections/{$collection}/points/batch?wait=true", [
                        'operations' => [
                            ['upsert' => ['points' => [
                                [
                                    'id' => $newUuid,
                                    'vector' => $vector,
                                    'payload' => $point['payload'] ?? [],
                                ],
                            ]]],
                            ['delete' => ['points' => [$primaryId]]],
                        ],
                    ]);

                    ++$migrated;
                } catch (\Throwable $e) {
                    if (null !== $onPoint) {
                        $onPoint('failed', $logicalId, $primaryId, $newUuid);
                    }
                    $this->logger->error('Failed to rekey legacy point', [
                        'collection' => $collection,
                        'logical_id' => $logicalId,
                        'from_id' => (string) $primaryId,
                        'to_uuid' => $newUuid,
                        'error' => $e->getMessage(),
                    ]);
                    ++$errors;
                }

                if (0 !== $limit && $migrated >= $limit) {
                    return new LegacyPointIdMigrationReport($scanned, $legacy, $migrated, $errors);
                }
            }

            $nextOffset = $response['result']['next_page_offset'] ?? null;

            // Progress guard: abort the loop if Qdrant tells us to scroll
            // back to the very offset we just requested, i.e. the cursor
            // did not advance. This can only happen under a Qdrant bug
            // or a race with a concurrent delete; without the guard the
            // loop would spin forever on the same page. Comparing against
            // the REQUEST offset (not against the previously returned
            // next-offset) catches this on the first stuck iteration.
            if (null !== $nextOffset && $nextOffset === $offset) {
                $this->logger->warning('Scroll offset did not advance during legacy-point migration; aborting loop', [
                    'collection' => $collection,
                    'offset' => is_scalar($nextOffset) ? (string) $nextOffset : '(complex)',
                ]);
                break;
            }

            $offset = $nextOffset;
        } while (null !== $offset && !empty($points));

        return new LegacyPointIdMigrationReport($scanned, $legacy, $migrated, $errors);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function qdrantRequest(string $method, string $path, ?array $body = null): array
    {
        $options = [];
        if (null !== $body) {
            $options['headers'] = ['Content-Type' => 'application/json'];
            $options['json'] = $body;
        }

        $response = $this->httpClient->request($method, "{$this->qdrantUrl}{$path}", $options);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('Qdrant %s %s failed with HTTP %d: %s', $method, $path, $status, $response->getContent(false)));
        }

        $content = $response->getContent();
        if ('' === $content) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
