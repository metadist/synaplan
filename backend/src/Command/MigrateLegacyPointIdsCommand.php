<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\VectorSearch\LegacyPointIdMigrator;
use App\Service\VectorSearch\QdrantClientDirect;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
 *
 * The actual scrolling, UUID derivation and rekey loop lives in
 * {@see LegacyPointIdMigrator} so it can be unit-tested with MockHttpClient;
 * this command is just CLI wiring.
 */
#[AsCommand(
    name: 'app:qdrant:migrate-legacy-point-ids',
    description: 'Rekey Qdrant points that still use legacy integer primary IDs to UUIDv5 derived from `_point_id`.',
)]
final class MigrateLegacyPointIdsCommand extends Command
{
    public function __construct(
        private readonly QdrantClientDirect $qdrantClient,
        private readonly LegacyPointIdMigrator $migrator,
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
                'Global cap on migrated points across all selected collections. Omit or pass "all" for no cap.',
                'all'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $qdrantUrl = $this->qdrantClient->getQdrantUrl();
        if ('' === $qdrantUrl || 'http://' === $qdrantUrl || 'https://' === $qdrantUrl) {
            $io->error('QDRANT_URL is not configured.');

            return Command::FAILURE;
        }

        $which = strtolower((string) $input->getOption('collection'));
        $apply = (bool) $input->getOption('apply');

        // Strict --limit parsing. A typo like `--limit=al` must NOT silently
        // degrade to 0 (= no cap) and risk migrating every point, especially
        // combined with --apply. Accept only "all" (case-insensitive) or a
        // positive integer; reject everything else with a clear message.
        $limitRaw = trim((string) $input->getOption('limit'));
        if ('' === $limitRaw || 'all' === strtolower($limitRaw)) {
            $limit = 0;
        } elseif (ctype_digit($limitRaw) && (int) $limitRaw > 0) {
            $limit = (int) $limitRaw;
        } else {
            $io->error(sprintf('Invalid --limit value "%s". Use "all" or a positive integer.', $limitRaw));

            return Command::INVALID;
        }

        $collections = match ($which) {
            'memories' => [$this->qdrantClient->getMemoriesCollection()],
            'documents' => [$this->qdrantClient->getDocumentsCollection()],
            'all' => [
                $this->qdrantClient->getMemoriesCollection(),
                $this->qdrantClient->getDocumentsCollection(),
            ],
            default => null,
        };

        if (null === $collections) {
            $io->error(sprintf('Unknown --collection value "%s". Use memories, documents, or all.', $which));

            return Command::INVALID;
        }

        $io->title('Qdrant legacy point-ID migration');
        $io->definitionList(
            ['Qdrant URL' => $qdrantUrl],
            ['Collections' => implode(', ', $collections)],
            ['Mode' => $apply ? 'APPLY (writes)' : 'DRY RUN (read-only)'],
            ['Limit' => 0 === $limit ? 'no cap' : (string) $limit],
        );

        $totalScanned = 0;
        $totalLegacy = 0;
        $totalMigrated = 0;
        $totalErrors = 0;

        foreach ($collections as $collection) {
            if (0 !== $limit && $totalMigrated >= $limit) {
                break;
            }

            $remaining = 0 === $limit ? 0 : max(0, $limit - $totalMigrated);

            $io->section(sprintf('Collection: %s', $collection));

            $report = $this->migrator->migrateCollection(
                collection: $collection,
                apply: $apply,
                limit: $remaining,
                onPoint: function (string $phase, string $logicalId, int|string $fromId, string $toUuid) use ($io): void {
                    $prefix = match ($phase) {
                        'would-migrate' => '<comment>would migrate</comment>',
                        'migrate' => '<info>migrate</info>',
                        'skip-missing-payload' => '<comment>skip</comment>',
                        'skip-missing-vector' => '<error>skip</error>',
                        'failed' => '<error>failed</error>',
                        default => $phase,
                    };
                    $io->writeln(sprintf('  %s %s  %s → %s', $prefix, $logicalId, (string) $fromId, $toUuid));
                },
            );

            $totalScanned += $report->scanned;
            $totalLegacy += $report->legacy;
            $totalMigrated += $report->migrated;
            $totalErrors += $report->errors;
        }

        $io->section('Summary');
        $io->definitionList(
            ['Points scanned' => (string) $totalScanned],
            ['Legacy (non-canonical-UUID) points' => (string) $totalLegacy],
            [$apply ? 'Points migrated' : 'Points that WOULD migrate' => (string) $totalMigrated],
            ['Errors' => (string) $totalErrors],
        );

        if (!$apply && $totalLegacy > 0) {
            $io->note('Re-run with --apply to actually migrate.');
        }

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
