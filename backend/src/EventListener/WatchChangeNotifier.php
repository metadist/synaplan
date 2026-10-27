<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Message\NotifyWatchesChanged;
use App\Service\CloudWatch\WatchChangeQueue;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The identity transaction has committed by the time the response is sent.
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final readonly class WatchChangeNotifier
{
    public function __construct(
        private WatchChangeQueue $queue,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(): void
    {
        foreach ($this->queue->pull() as $instanceId) {
            $this->bus->dispatch(new NotifyWatchesChanged($instanceId));
        }
    }
}
