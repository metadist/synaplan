<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Infrastructure\DatabaseConnectionRelease;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * After a streamed response (an AI answer, a dictation or TTS stream, an MCP
 * event stream) the worker gives its database connections back, so a worker
 * that is idle between streams holds none.
 *
 * Short requests keep their connection: reconnecting on every request would
 * cost each one a round trip to the database. Runs last on terminate, after
 * every other listener that may still write.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, priority: -2048)]
final readonly class StreamedResponseConnectionRelease
{
    public function __construct(
        private DatabaseConnectionRelease $connections,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getResponse() instanceof StreamedResponse) {
            return;
        }

        $this->connections->release('streamed response ended');
    }
}
