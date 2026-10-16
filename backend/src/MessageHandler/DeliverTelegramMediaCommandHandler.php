<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DeliverTelegramMediaCommand;
use App\Service\Telegram\TelegramMediaJobDelivery;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeliverTelegramMediaCommandHandler
{
    public function __construct(
        private TelegramMediaJobDelivery $delivery,
    ) {
    }

    public function __invoke(DeliverTelegramMediaCommand $command): void
    {
        $this->delivery->deliver($command->getJobKey(), $command->getMessageId());
    }
}
