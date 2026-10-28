<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Observability\RequestIdGenerator;
use App\Observability\RequestTimelineContext;
use App\Observability\RequestTimelineStore;
use App\Observability\ServerIdentity;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records start-to-end request timings into {@see RequestTimelineStore}
 * (`04_capacity_and_kubernetes.md` §2–§3, `03_contracts.md` §9, S1 AU13).
 *
 * Writes in `kernel.terminate` (after the response has left PHP) in a single
 * Redis pipeline so measuring costs ≤ 0.3 ms per request.
 */
final readonly class RequestTimelineRecorder implements EventSubscriberInterface
{
    public function __construct(
        private RequestTimelineContext $context,
        private RequestTimelineStore $store,
        private ServerIdentity $identity,
        private RequestIdGenerator $requestIdGenerator = new RequestIdGenerator(),
        private ?Security $security = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 500],
            KernelEvents::CONTROLLER => ['onKernelController', 0],
            KernelEvents::TERMINATE => ['onKernelTerminate', -100],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $attr = $request->attributes->get(RequestIdGenerator::ATTRIBUTE);
        $requestId = \is_string($attr) && '' !== $attr
            ? $attr
            : $this->requestIdGenerator->sanitize($request->headers->get(RequestIdGenerator::HEADER));

        $route = $request->attributes->get('_route');
        $routeClass = RequestTimelineContext::classifyRoute(
            \is_string($route) ? $route : null,
            $request->getPathInfo(),
        );

        $this->context->start($requestId, $routeClass, null, time(), hrtime(true));
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->context->isStarted()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        $this->context->setRouteClass(RequestTimelineContext::classifyRoute(
            \is_string($route) ? $route : null,
            $request->getPathInfo(),
        ));

        $user = $this->security?->getUser();
        if ($user instanceof User && null !== $user->getId()) {
            $this->context->setUserId((int) $user->getId());
        }
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->context->isStarted()) {
            return;
        }

        try {
            $response = $event->getResponse();
            $statusCode = $response->getStatusCode();
            $body = null;
            if (503 === $statusCode && !$response instanceof StreamedResponse) {
                $raw = $response->getContent();
                $body = \is_string($raw) ? $raw : null;
            }
            $statusClass = RequestTimelineContext::classifyStatus($statusCode, $body);
            $record = $this->context->buildRecord($this->identity->id(), $statusClass, hrtime(true));
            $this->store->record($record);
        } catch (\Throwable) {
            // Telemetry never breaks request termination.
        } finally {
            $this->context->reset();
        }
    }
}
