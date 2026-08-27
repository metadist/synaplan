<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Observability\RequestIdGenerator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Assigns a correlation id to every main request and echoes it back in the
 * `X-Request-Id` response header.
 *
 * The id is stored on the request attributes so {@see \App\Monolog\RequestIdProcessor}
 * can stamp it onto every log record, which is what lets a single incident be
 * reconstructed from otherwise-disconnected log lines. Registered at a high
 * priority so the id exists before any other listener logs.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: 4096)]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse', priority: -4096)]
final readonly class RequestIdSubscriber
{
    public function __construct(
        private RequestIdGenerator $generator,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->attributes->has(RequestIdGenerator::ATTRIBUTE)) {
            return;
        }

        $incoming = $request->headers->get(RequestIdGenerator::HEADER);
        $request->attributes->set(
            RequestIdGenerator::ATTRIBUTE,
            $this->generator->sanitize($incoming),
        );
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $correlationId = $event->getRequest()->attributes->get(RequestIdGenerator::ATTRIBUTE);
        if (\is_string($correlationId) && '' !== $correlationId) {
            $event->getResponse()->headers->set(RequestIdGenerator::HEADER, $correlationId);
        }
    }
}
