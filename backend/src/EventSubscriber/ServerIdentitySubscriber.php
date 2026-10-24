<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Observability\ServerIdentity;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Names the server that answered in the `X-Synaplan-Server` response header,
 * next to `X-Request-Id`. The capacity page reads it to mark "You are
 * connected to this server"; a person reporting a problem can quote both.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse', priority: -4096)]
final readonly class ServerIdentitySubscriber
{
    public function __construct(
        private ServerIdentity $identity,
    ) {
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getResponse()->headers->set(ServerIdentity::HEADER, $this->identity->id());
    }
}
