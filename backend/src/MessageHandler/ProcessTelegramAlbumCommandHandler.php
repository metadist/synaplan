<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessTelegramAlbumCommand;
use App\Service\Telegram\TelegramInboundService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessTelegramAlbumCommandHandler
{
    public function __construct(
        private TelegramInboundService $inbound,
    ) {
    }

    public function __invoke(ProcessTelegramAlbumCommand $command): void
    {
        $this->inbound->handleAlbum($command->getBotRowId(), $command->getMediaGroupId());
    }
}
