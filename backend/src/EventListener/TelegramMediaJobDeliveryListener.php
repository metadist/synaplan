<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Message\DeliverTelegramMediaCommand;
use App\Repository\MessageRepository;
use App\Service\Media\MediaJobTerminalEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A render requested from Telegram is sent there once it is done, so the
 * person does not have to open Synaplan to get the file.
 */
#[AsEventListener]
final readonly class TelegramMediaJobDeliveryListener
{
    public function __construct(
        private MessageRepository $messages,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(MediaJobTerminalEvent $event): void
    {
        $message = $this->messages->find($event->messageId);
        if (null === $message || 'OUT' !== $message->getDirection() || 'telegram' !== $message->getMeta('channel')) {
            return;
        }
        $this->bus->dispatch(new DeliverTelegramMediaCommand($event->job->getJobKey(), $event->messageId));
    }
}
