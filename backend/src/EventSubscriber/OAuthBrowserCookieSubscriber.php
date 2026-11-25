<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\OAuth\BrowserBoundState;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Puts the cookie {@see BrowserBoundState} asked for on the response: set when
 * a consent flow starts, cleared when its callback was accepted.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse')]
final readonly class OAuthBrowserCookieSubscriber
{
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $cookie = $event->getRequest()->attributes->get(BrowserBoundState::COOKIE_ATTRIBUTE);
        if ($cookie instanceof Cookie) {
            $event->getResponse()->headers->setCookie($cookie);
        }
    }
}
