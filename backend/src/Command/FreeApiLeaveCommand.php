<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Credential\ChatReadinessService;
use App\Service\FreeApi\FreeApiActivator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:freeapi:leave',
    description: 'Turn the Synaplan Demo off and give every purpose its previous AI model back'
)]
final class FreeApiLeaveCommand extends Command
{
    public function __construct(
        private readonly FreeApiActivator $activator,
        private readonly ChatReadinessService $chatReadiness,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $leave = $this->activator->leave();

        if (!$leave->wasActive) {
            $io->note('The Synaplan Demo was not active. Nothing was changed.');

            return Command::SUCCESS;
        }

        $io->success($this->chatReadiness->isChatReady()
            ? 'Your previous AI settings are back, and chat answers with them again.'
            : 'Your previous AI settings are back. Chat stops until you add your own provider or run Ollama locally.');
        if ([] !== $leave->restoredCapabilities) {
            $io->text('Restored: '.implode(', ', $leave->restoredCapabilities));
        }
        if (!$leave->keyRevoked) {
            $io->note('The Demo server could not be reached to cancel the key. The scheduler retries every hour; the key no longer works on this server either way.');
        }

        return Command::SUCCESS;
    }
}
