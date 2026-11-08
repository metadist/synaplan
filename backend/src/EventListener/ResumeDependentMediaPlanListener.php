<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Message\ResumeMediaPlanCommand;
use App\Service\Media\MediaJobTerminalEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A render that a later step needs has reached a terminal state. Continue
 * that plan on a worker, not in the request that started the render.
 */
#[AsEventListener]
final readonly class ResumeDependentMediaPlanListener
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(MediaJobTerminalEvent $event): void
    {
        $nodeId = $event->job->getNodeId();
        if (null === $nodeId || '' === $nodeId) {
            return;
        }
        $this->bus->dispatch(new ResumeMediaPlanCommand($event->messageId, $nodeId, $event->job->getJobKey()));
    }
}
