<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One-shot migration for Qdrant collections that still contain points whose
 * primary IDs were assigned by the pre-v2.4.0 Rust microservice (integer
 * hashes) rather than the current scheme (UUIDv5 derived from `_point_id`).
 *
 * Background: in v2.4.0 (commit 713ce1710, Mar 2026) we replaced the Rust
 * microservice with direct PHP-to-Qdrant calls and switched the point-ID
 * scheme to UUIDv5. Existing points were left in place and continued to work
 * for reads via `_point_id` payload filter, but point-by-ID operations
 * (get/update/delete) mis-targeted them. The fix in QdrantClientDirect
 * (filter-based read/delete) makes behaviour correct regardless of primary
 * ID format, so this migration is NOT required for correctness. It remains
 * useful as cleanup — after running, every point is keyed by its canonical
 * UUIDv5 and the primary ID matches `_point_id`.
 *
 * Safe to run repeatedly. Default is dry-run.
 */
#[AsCommand(
    name: 'app:qdrant:migrate-legacy-point-ids',
    description: 'Rekey Qdrant points that still use legacy integer primary IDs to UUIDv5 derived from `_point_id`.',
)]
final class MigrateLegacyPointIdsCommand extends Command
{
    private const BATCH_LIMIT = 100;

    /** UUIDv5 namespace for point-ID derivation — must match QdrantClientDirect::generatePointUuid(). */
    private const POINT_ID_NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(QDRANT_URL)%')]
        private readonly string $qdrantUrl,
        #[Autowire('%env(default::QDRANT_MEMORIES_COLLECTION)%')]
        private readonly ?string $memoriesCollectionOverride = null,
        #[Autowire('%env(default::QDRANT_DOCUMENTS_COLLECTION)%')]
        private readonly ?string $documentsCollectionOverride = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'collection',
                'c',
                InputOption::VALUE_REQUIRED,
                'Which collection to migrate: "memories", "documents", or "all".',
                'all'
            )
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Actually perform the migration. Without this flag the command only reports what would change (dry run).'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum points to migrate in this run (0 = all). Useful for canarying.',
                '0'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('' === $this->qdrantUrl || 'http://' === $this->qdrantUrl || 'https://' === $this->qdrantUrl) {
            $io->error('QDRANT_URL is not configured.');

            return Command::FAILURE;
        }

        $which = strtolower((string) $input->getOption('collection'));
        $apply = (bool) $input->getOption('apply');
        $limit = max(0, (int) $input->getOption('limit'));

        $collections = match ($which) {
            'memories' => [$this->memoriesCollection()],
            'documents' => [$this->documentsCollection()],
            'all' => [$this->memoriesCollection(), $this->documentsCollection()],
            default => null,
        };

        if (null === $collections) {
            $io->error(sprintf('Unknown --collection value "%s". Use memories, documents, or all.', $which));

            return Command::INVALID;
        }

        $io->title('Qdrant legacy point-ID migration');
        $io->definitionList(
            ['Qdrant URL' => $this->qdrantUrl],
            ['Collections' => implode(', ', $collections)],
            ['Mode' => $apply ? 'APPLY (writes)' : 'DRY RUN (read-only)'],
            ['Limit' => 0 === $limit ? 'all points' : (string) $limit],
        );

        $totalScanned = 0;
        $totalLegacy = 0;
        $totalMigrated = 0;
        $totalErrors = 0;

        foreach ($collections as $collection) {
            [$scanned, $legacy, $migrated, $errors] = $this->migrateCollection($collection, $apply, $limit, $io);
            $totalScanned += $scanned;
            $totalLegacy += $legacy;
            $totalMigrated += $migrated;
            $totalErrors += $errors;

            if ($limit > 0 && $totalMigrated >= $limit) {
                break;
            }
        }

        $io->section('Summary');
        $io->definitionList(
            ['Points scanned' => (string) $totalScanned],
            ['Legacy (non-UUID) points' => (string) $totalLegacy],
            [$apply ? 'Points migrated' : 'Points that WOULD migrate' => (string) $totalMigrated],
            ['Errors' => (string) $totalErrors],
        );

        if (!$apply && $totalLegacy > 0) {
            $io->note('Re-run with --apply to actually migrate.');
        }

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array{int, int, int, int} [scanned, legacy, migrated, errors]
     */
    private function migrateCollection(string $collection, bool $apply, int $limit, SymfonyStyle $io): array
    {
        $io->section(sprintf('Collection: %s', $collection));

        $scanned = 0;
        $legacy = 0;
        $migrated = 0;
        $errors = 0;
        $offset = null;

        do {
            $body = [
                'limit' => self::BATCH_LIMIT,
                'with_payload' => true,
                'with_vector' => true,
            ];
            if (null !== $offset) {
                $body['offset'] = $offset;
            }

            try {
                $response = $this->qdrantRequest('POST', "/collections/{$collection}/points/scroll", $body);
            } catch (\Throwable $e) {
                $io->error(sprintf('Failed to scroll %s: %s', $collection, $e->getMessage()));

                return [$scanned, $legacy, $migrated, ++$errors];
            }

            /** @var list<array{id: int|string, payload?: array<string, mixed>, vector?: list<float>}> $points */
            $points = $response['result']['points'] ?? [];

            foreach ($points as $point) {
                ++$scanned;

                $primaryId = $point['id'];
                $logicalId = $point['payload']['_point_id'] ?? null;

                // Skip points that are already correctly keyed, or that lack
                // the `_point_id` payload entirely (we have nothing to derive
                // a new UUID from).
                if (is_string($primaryId) && $this->isUuid($primaryId)) {
                    continue;
                }
                if (!is_string($logicalId) || '' === $logicalId) {
                    $io->writeln(sprintf('  <comment>skip</comment> id=%s — missing `_point_id` payload', var_export($primaryId, true)));
                    continue;
                }

                ++$legacy;

                $newUuid = $this->deriveUuid($logicalId);
                $vector = $point['vector'] ?? null;

                if (!is_array($vector) || [] === $vector) {
                    $io->writeln(sprintf('  <error>skip</error> %s — no vector available to re-upsert', $logicalId));
                    ++$errors;
                    continue;
                }

                $io->writeln(sprintf(
                    '  %s %s  %s → %s',
                    $apply ? '<info>migrate</info>' : '<comment>would migrate</comment>',
                    $logicalId,
                    (string) $primaryId,
                    $newUuid,
                ));

                if (!$apply) {
                    ++$migrated;
                    if ($limit > 0 && $migrated >= $limit) {
                        return [$scanned, $legacy, $migrated, $errors];
                    }
                    continue;
                }

                try {
                    // 1) Upsert under the new UUID primary (carries the full
                    //    payload, including `_point_id`).
                    $this->qdrantRequest('PUT', "/collections/{$collection}/points?wait=true", [
                        'points' => [
                            [
                                'id' => $newUuid,
                                'vector' => $vector,
                                'payload' => $point['payload'] ?? [],
                            ],
                        ],
                    ]);

                    // 2) Delete the old primary-ID point. Using the primary
                    //    ID directly here is correct — we are explicitly
                    //    targeting the legacy row by its raw integer ID.
                    $this->qdrantRequest('POST', "/collections/{$collection}/points/delete?wait=true", [
                        'points' => [$primaryId],
                    ]);

                    ++$migrated;
                } catch (\Throwable $e) {
                    $io->writeln(sprintf('    <error>failed</error>: %s', $e->getMessage()));
                    ++$errors;
                }

                if ($limit > 0 && $migrated >= $limit) {
                    return [$scanned, $legacy, $migrated, $errors];
                }
            }

            $offset = $response['result']['next_page_offset'] ?? null;
        } while (null !== $offset && !empty($points));

        return [$scanned, $legacy, $migrated, $errors];
    }

    private function memoriesCollection(): string
    {
        $override = $this->memoriesCollectionOverride ?? '';

        return '' !== $override ? $override : 'user_memories';
    }

    private function documentsCollection(): string
    {
        $override = $this->documentsCollectionOverride ?? '';

        return '' !== $override ? $override : 'user_documents';
    }

    private function deriveUuid(string $logicalId): string
    {
        return Uuid::v5(Uuid::fromString(self::POINT_ID_NAMESPACE), $logicalId)->toRfc4122();
    }

    private function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function qdrantRequest(string $method, string $path, ?array $body = null): array
    {
        $options = ['headers' => ['Content-Type' => 'application/json']];
        if (null !== $body) {
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
