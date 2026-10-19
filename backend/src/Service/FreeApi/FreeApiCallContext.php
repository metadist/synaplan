<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Who the current AI call is for and from which surface, captured once per
 * request. A streamed answer runs after the request stack has been emptied,
 * so the Demo headers cannot look the request up at call time.
 *
 * Outside a request (worker, scheduler, console) the surface is `task` and
 * there is no end user.
 */
final class FreeApiCallContext implements EventSubscriberInterface, ResetInterface
{
    public const SURFACE_CHAT = 'chat';
    public const SURFACE_WIDGET = 'widget';
    public const SURFACE_PLATFORM_LINK = 'platform-link';
    public const SURFACE_API = 'api';
    public const SURFACE_TASK = 'task';

    /** @var array<string, string> path pattern => surface, first match wins */
    private const SURFACES = [
        '#^/v1/#' => self::SURFACE_API,
        '#^/(api/v1/(realtime/)?)?widget(/|$)#' => self::SURFACE_WIDGET,
        '#^/api/v1/platform-links(/|$)#' => self::SURFACE_PLATFORM_LINK,
        '#^/api/v1/(webhooks/)?saved-tasks(/|$)#' => self::SURFACE_TASK,
    ];

    private ?int $userId = null;
    private string $surface = self::SURFACE_TASK;

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'capture'];
    }

    public function capture(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->security->getUser();
        $this->userId = $user instanceof User ? $user->getId() : null;
        $this->surface = self::surfaceFor($event->getRequest()->getPathInfo());
    }

    public static function surfaceFor(string $path): string
    {
        foreach (self::SURFACES as $pattern => $surface) {
            if (1 === preg_match($pattern, $path)) {
                return $surface;
            }
        }

        return self::SURFACE_CHAT;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function surface(): string
    {
        return $this->surface;
    }

    public function reset(): void
    {
        $this->userId = null;
        $this->surface = self::SURFACE_TASK;
    }
}
