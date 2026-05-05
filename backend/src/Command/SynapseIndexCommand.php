<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Message\SynapseIndexer;
use App\Service\VectorSearch\QdrantClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'synapse:index',
    description: 'Index topic embeddings into Qdrant for Synapse Routing',
)]
class SynapseIndexCommand extends Command
{
    public function __construct(
        private readonly SynapseIndexer $indexer,
        private readonly QdrantClientInterface $qdrantClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', 'u', InputOption::VALUE_OPTIONAL, 'Index topics for a specific user ID (also re-indexes system topics)')
            ->addOption('status', 's', InputOption::VALUE_NONE, 'Show indexing status without performing any indexing')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Bypass the source-hash skip-when-unchanged optimisation')
            ->addOption('recreate', null, InputOption::VALUE_NONE, 'Drop and recreate the Qdrant collection (use when switching to a model with a different vector dim). Implies --force.')
            ->setHelp(
                "Embeds all topic descriptions and stores them in the Qdrant\n".
                "synapse_topics collection for fast embedding-based routing.\n\n".
                "Run this once after deployment or whenever topics are changed\n".
                "directly in the database (API changes auto-index).\n\n".
                "Examples:\n".
                "  synapse:index               Index all system topics (skip unchanged)\n".
                "  synapse:index --force       Re-embed every topic, even unchanged\n".
                "  synapse:index --recreate    Drop+recreate collection then full re-embed\n".
                "  synapse:index --user=42     Index system + user 42's topics\n".
                "  synapse:index --status      Show collection stats / per-model counts\n"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('status')) {
            return $this->showStatus($io);
        }

        $userId = $input->getOption('user');
        $userId = null !== $userId ? (int) $userId : null;
        $force = (bool) $input->getOption('force');
        $recreate = (bool) $input->getOption('recreate');

        $modelInfo = $this->indexer->getEmbeddingModelInfo();
        $io->section('Synapse Routing — Topic Indexer');
        $io->text(sprintf(
            'Embedding model: %s/%s (id=%s, dim=%d)',
            $modelInfo['provider'] ?? 'auto',
            $modelInfo['model'] ?? 'default',
            $modelInfo['model_id'] ?? 'n/a',
            $modelInfo['vector_dim'],
        ));

        if (null !== $userId) {
            $io->text(sprintf('Scope: system topics + user %d topics', $userId));
        } else {
            $io->text('Scope: system topics only');
        }

        if ($recreate) {
            $io->warning('Recreate flag set — dropping and recreating the collection');
            try {
                $this->qdrantClient->recreateSynapseCollection($modelInfo['vector_dim']);
            } catch (\Throwable $e) {
                $io->error(sprintf('Failed to recreate collection: %s', $e->getMessage()));

                return Command::FAILURE;
            }
            $force = true;
        }

        if ($force) {
            $io->note('Force mode enabled — every topic will be re-embedded');
        }

        $io->newLine();

        try {
            $result = $this->indexer->indexAllTopics($userId, $force);
            $io->success(sprintf(
                'Indexed %d / Skipped %d / Errors %d.',
                $result['indexed'],
                $result['skipped'],
                $result['errors'],
            ));

            return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Indexing failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }

    private function showStatus(SymfonyStyle $io): int
    {
        $io->section('Synapse Routing — Status');

        try {
            $collection = $this->qdrantClient->getSynapseCollection();
            $io->text(sprintf('Collection: %s', $collection));

            if (!$this->qdrantClient->isAvailable()) {
                $io->warning('Qdrant is not available.');

                return Command::FAILURE;
            }

            $modelInfo = $this->indexer->getEmbeddingModelInfo();
            $io->text(sprintf(
                'Active model: %s/%s (id=%s, dim=%d)',
                $modelInfo['provider'] ?? 'not configured',
                $modelInfo['model'] ?? 'not configured',
                $modelInfo['model_id'] ?? 'n/a',
                $modelInfo['vector_dim'],
            ));

            $collectionInfo = $this->qdrantClient->getSynapseCollectionInfo();
            $io->text(sprintf(
                'Collection state: exists=%s, dim=%s, points=%s, distance=%s',
                $collectionInfo['exists'] ? 'yes' : 'no',
                $collectionInfo['vector_dim'] ?? 'n/a',
                $collectionInfo['points_count'] ?? 'n/a',
                $collectionInfo['distance'] ?? 'n/a',
            ));

            if (
                null !== $collectionInfo['vector_dim']
                && (int) $collectionInfo['vector_dim'] !== (int) $modelInfo['vector_dim']
            ) {
                $io->warning(sprintf(
                    'Dimension mismatch: collection=%d, model=%d. Run --recreate to migrate.',
                    $collectionInfo['vector_dim'],
                    $modelInfo['vector_dim'],
                ));
            }

            $points = $this->qdrantClient->scrollSynapseTopics(null, 5000);
            $perModel = [];
            $stale = 0;
            foreach ($points as $point) {
                $payload = $point['payload'];
                $key = sprintf(
                    '%s/%s (id=%s)',
                    (string) ($payload['embedding_provider'] ?? 'n/a'),
                    (string) ($payload['embedding_model'] ?? 'n/a'),
                    (string) ($payload['embedding_model_id'] ?? 'n/a'),
                );
                $perModel[$key] = ($perModel[$key] ?? 0) + 1;
                $indexedModelId = $payload['embedding_model_id'] ?? null;
                if (null !== $indexedModelId && null !== $modelInfo['model_id']
                    && (int) $indexedModelId !== (int) $modelInfo['model_id']) {
                    ++$stale;
                }
            }

            if (!empty($perModel)) {
                $io->section('Indexed per model');
                $rows = [];
                foreach ($perModel as $key => $count) {
                    $rows[] = [$key, $count];
                }
                $io->table(['Model', 'Count'], $rows);
            }

            $io->text(sprintf('Stale entries (different model than active): %d', $stale));

            $io->success('Qdrant is available and Synapse collection is configured.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Status check failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }
}
