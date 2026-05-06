<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Message\SynapseIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI alias for the admin "Reindex" button on /config/sorting-prompt.
 *
 * Mirrors `POST /api/v1/admin/synapse/reindex` (see AdminSynapseController::reindex)
 * — synchronous, returns the same {indexed, skipped, errors, failures} shape, so
 * operators can verify the SYNAPSE_VECTORIZE provider end-to-end without booting
 * the full SPA / admin auth stack.
 *
 * Examples:
 *   bin/console app:synapse:reindex                    # respect source-hash skip
 *   bin/console app:synapse:reindex --force            # re-embed every topic
 *   bin/console app:synapse:reindex --force --user=1   # only one user's topics
 */
#[AsCommand(
    name: 'app:synapse:reindex',
    description: 'Re-index synapse routing topics through the active SYNAPSE_VECTORIZE model (sync, mirrors the admin Reindex button).'
)]
final class SynapseReindexCommand extends Command
{
    public function __construct(private readonly SynapseIndexer $synapseIndexer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Bypass the source-hash skip-when-unchanged optimisation.')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Restrict re-index to a single user id (default: all users).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // SynapseIndexer::getEmbeddingModelInfo() declares
        //   array{provider: ?string, model: ?string, model_id: ?int, vector_dim: int}
        // so the three identity fields are nullable (when SYNAPSE_VECTORIZE is unbound)
        // but vector_dim always has a sane fallback. Keep the printed lines aligned
        // with that contract — PHPStan rejects a null-coalesce on the non-null fields.
        $info = $this->synapseIndexer->getEmbeddingModelInfo();
        $io->section('SYNAPSE_VECTORIZE active model');
        $io->definitionList(
            ['modelId' => null === $info['model_id'] ? '<unbound>' : (string) $info['model_id']],
            ['provider' => $info['provider'] ?? '<unbound>'],
            ['model' => $info['model'] ?? '<unbound>'],
            ['vectorDim' => (string) $info['vector_dim']],
        );

        $userOpt = $input->getOption('user');
        $userId = (null !== $userOpt && '' !== $userOpt) ? (int) $userOpt : null;
        $force = (bool) $input->getOption('force');

        $start = microtime(true);
        // SynapseIndexer::indexAllTopics() returns
        //   array{indexed: int, skipped: int, errors: int, failures: list<...>}
        // — every scalar is a plain int, no null fallback needed. There is no
        // total_topics key; derive it locally so the operator gets a quick
        // "0 / 9 reindexed" sanity check.
        $result = $this->synapseIndexer->indexAllTopics($userId, $force);
        $ms = (int) ((microtime(true) - $start) * 1000);

        $totalTopics = $result['indexed'] + $result['skipped'] + $result['errors'];

        $io->section('indexAllTopics result');
        $io->definitionList(
            ['total_topics' => (string) $totalTopics],
            ['indexed' => (string) $result['indexed']],
            ['skipped' => (string) $result['skipped']],
            ['errors' => (string) $result['errors']],
            ['total_ms' => (string) $ms],
        );

        if ([] !== $result['failures']) {
            $io->section('failures');
            foreach ($result['failures'] as $failure) {
                $io->writeln('  - '.json_encode($failure, JSON_UNESCAPED_SLASHES));
            }
        }

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
