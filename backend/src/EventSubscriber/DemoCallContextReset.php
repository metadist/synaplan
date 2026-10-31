<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Usage\DemoCallContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drops the Demo install stamp so a reused worker cannot attach it to a
 * later request.
 */
final class DemoCallContextReset implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 100],
            KernelEvents::TERMINATE => ['onTerminate', -100],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            DemoCallContext::clear();
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if ($event->isMainRequest()) {
            DemoCallContext::clear();
        }
    }
}
