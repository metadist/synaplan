<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ApiKeyPlanRequiredException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns a refused API-key mint into the same 403 the keys page returns.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 20)]
final class ApiKeyPlanExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof ApiKeyPlanRequiredException) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ApiKeyPlanRequiredException::responseBody(),
            Response::HTTP_FORBIDDEN,
        ));
    }
}
