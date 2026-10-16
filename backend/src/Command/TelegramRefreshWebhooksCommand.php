<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TelegramBotRepository;
use App\Service\Telegram\TelegramConnectionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:telegram:refresh-webhooks',
    description: 'Re-register the webhook and command menu of every connected Telegram bot',
)]
final class TelegramRefreshWebhooksCommand extends Command
{
    public function __construct(
        private readonly TelegramBotRepository $bots,
        private readonly TelegramConnectionService $connections,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            "Bots connected before a release that added update types (edited messages,\n".
            "buttons) or menu commands keep the old registration until they reconnect.\n".
            'This command updates them in place; the owners do not have to do anything.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $refreshed = 0;
        $failed = 0;
        foreach ($this->bots->findWithWebhook() as $bot) {
            if ($this->connections->refresh($bot)) {
                ++$refreshed;
            } else {
                ++$failed;
                $io->warning(sprintf('Bot #%d (@%s) could not be refreshed.', (int) $bot->getId(), $bot->getBotUsername()));
            }
        }
        $io->success(sprintf('%d bot(s) refreshed, %d failed.', $refreshed, $failed));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
