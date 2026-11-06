<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Http\ClientAddress;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs before other listeners read the client address, so a trusted
 * Cloudflare hop and a later {@see ClientAddress::resolve()} agree.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: 8192)]
final readonly class ClientAddressSubscriber
{
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        ClientAddress::align($event->getRequest());
    }
}
