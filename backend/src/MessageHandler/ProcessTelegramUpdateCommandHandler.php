<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\TelegramBot;
use App\Message\ProcessTelegramUpdateCommand;
use App\Repository\TelegramBotRepository;
use App\Service\Feature\AdminPreview;
use App\Service\Telegram\TelegramInboundService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessTelegramUpdateCommandHandler
{
    public function __construct(
        private TelegramInboundService $inbound,
        private TelegramBotRepository $bots,
        private AdminPreview $preview,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessTelegramUpdateCommand $command): void
    {
        $bot = $this->bots->find($command->getBotRowId());
        if (!$bot instanceof TelegramBot) {
            return;
        }
        if (!$this->preview->allowsUserId(AdminPreview::TELEGRAM, $bot->getOwnerId())) {
            $this->logger->info('Telegram update dropped because the bot owner is not an admin', [
                'bot_id' => $bot->getId(),
            ]);

            return;
        }

        $this->inbound->handle($command->getBotRowId(), $command->getUpdateId(), $command->getUpdate());
    }
}
