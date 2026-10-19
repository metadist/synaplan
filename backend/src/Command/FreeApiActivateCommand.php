<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\FreeApi\FreeApiActivator;
use App\Service\FreeApi\FreeApiConfig;
use App\Service\FreeApi\FreeApiException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:freeapi:activate',
    description: 'Start the Synaplan Demo: a free, limited AI key so chat works without an own provider'
)]
final class FreeApiActivateCommand extends Command
{
    public function __construct(
        private readonly FreeApiActivator $activator,
        private readonly FreeApiConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Language of the Demo notices (en, de, es, fr, tr)', 'en')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Optional contact address for notices about the Demo')
            ->addOption('accept-terms', null, InputOption::VALUE_NONE, 'Accept the consent sentence without asking (required with --no-interaction)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Start the Demo even though chat already works with an own AI provider');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->config->isConfigured()) {
            $io->error(FreeApiException::notConfigured()->getMessage());

            return Command::FAILURE;
        }

        $consent = sprintf(
            'Your messages, and the files you ask about, are sent to %s to be answered. No copy is kept there. Usage counts and your install id are stored for 30 days.',
            $this->config->serverHost(),
        );
        $io->text($consent);
        if (!$input->getOption('accept-terms') && !$io->confirm('Start the Synaplan Demo?', false)) {
            $io->note('Nothing was changed.');

            return Command::FAILURE;
        }

        $email = $input->getOption('email');
        try {
            $activation = $this->activator->activate((string) $input->getOption('locale'), \is_string($email) ? $email : null, (bool) $input->getOption('force'));
        } catch (FreeApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'The Synaplan Demo answers chat for %d more days. Add your own AI provider any time; the Demo then turns itself off.',
            $activation->daysLeft,
        ));
        $io->listing([
            'Now answered by the Demo: '.implode(', ', $activation->boundCapabilities),
            $activation->vectorizeKept ? 'Document search keeps its current embedding model, because documents are already indexed.' : 'Document search uses the Demo too.',
            'Turn it off: bin/console app:freeapi:leave',
        ]);

        return Command::SUCCESS;
    }
}
