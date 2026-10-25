<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\ModelCatalog;
use App\Service\Research\Run\Lead\LeadConformance;
use App\Service\Research\Run\Lead\ModelLeadCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Plays the tool-mode scenarios against one lead model. Search, pages and
 * the reader are test doubles, so only that model is called — twenty times,
 * billed to the account passed with --user.
 */
#[AsCommand(
    name: 'app:research:conformance',
    description: 'Check whether a model can lead research with native tool calls.',
)]
final class ResearchLeadConformanceCommand extends Command
{
    public function __construct(
        private readonly LeadConformance $suite,
        private readonly ModelLeadCatalog $catalog,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Catalog key service:providerId:tag of the lead model')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Account the model calls are billed to');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = strtolower(trim((string) $input->getArgument('key')));
        $userId = (int) $input->getOption('user');
        if ($userId < 1) {
            $io->error('Pass --user with the account the model calls are billed to. Nothing was sent.');

            return Command::FAILURE;
        }
        $modelId = ModelCatalog::findBidByKey($key);
        if (null === $modelId || $key !== $this->catalog->key($modelId)) {
            $io->error('No installed model has the catalog key '.$key.'. Use service:providerId:tag. Nothing was sent.');

            return Command::FAILURE;
        }

        $io->writeln(sprintf('Playing %d turns against %s.', LeadConformance::TURNS, $key));
        $results = $this->suite->results($modelId, $userId, static function (string $turn, ?string $failure) use ($io): void {
            $io->writeln((null === $failure ? '  <info>pass</info> ' : '  <error>fail</error> ').$turn.(null === $failure ? '' : ': '.$failure));
        });
        $failed = array_filter($results, static fn (?string $failure): bool => null !== $failure);
        if ([] !== $failed || LeadConformance::TURNS !== count($results)) {
            $io->error(sprintf('%s failed %d of %d turns. Keep it off RESEARCH.TOOL_MODE_MODELS; it stays in round mode.', $key, count($failed), count($results)));

            return Command::FAILURE;
        }
        $io->success(sprintf('%s passed all %d turns. It may be added to RESEARCH.TOOL_MODE_MODELS.', $key, LeadConformance::TURNS));

        return Command::SUCCESS;
    }
}
