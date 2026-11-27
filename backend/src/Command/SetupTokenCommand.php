<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Setup\SetupStateService;
use App\Service\Setup\SetupTokenService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a new setup token while this installation still waits for its first
 * administrator. The backend runs it on every start; an operator runs it to
 * get a token without restarting. Each run replaces the earlier token.
 */
#[AsCommand(
    name: 'app:setup:token',
    description: 'Print a new one-time token for the first-run setup wizard',
)]
final class SetupTokenCommand extends Command
{
    public function __construct(
        private readonly SetupStateService $setupState,
        private readonly SetupTokenService $setupToken,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->setupState->isSetupRequired()) {
            $output->writeln('Setup is not open on this installation; no token is needed.', OutputInterface::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }

        $token = $this->setupToken->issue();

        $output->writeln([
            '',
            '================================================================',
            '  Setup token: '.$token,
            '',
            '  This installation has no administrator yet. Open the setup',
            '  wizard in your browser and enter this token there.',
            '  New token: php bin/console app:setup:token',
            '================================================================',
            '',
        ]);

        return Command::SUCCESS;
    }
}
