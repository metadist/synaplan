<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessTelegramUpdateCommand;
use App\Service\Telegram\TelegramInboundService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessTelegramUpdateCommandHandler
{
    public function __construct(
        private TelegramInboundService $inbound,
    ) {
    }

    public function __invoke(ProcessTelegramUpdateCommand $command): void
    {
        $this->inbound->handle($command->getBotRowId(), $command->getUpdateId(), $command->getUpdate());
    }
}
