<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\OAuth\BrowserBoundState;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The way back into the native app after a connector consent.
 *
 * The app runs the consent in the system browser, which is not signed in. So
 * when the provider's answer for an app-bound state arrives there, the browser
 * is sent on to the app's deep link with the answer untouched. The app then
 * asks the same callback again with its own token and `Accept:
 * application/json`; {@see BrowserBoundState} redeems the state only for the
 * account that started it, and the redirect the callback answers with is
 * returned as JSON for the app's router.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest')]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse')]
final readonly class NativeConsentReturnSubscriber
{
    /** Callback route => the connector key the app maps back to that callback. */
    private const CALLBACKS = [
        'api_connections_m365_callback' => 'm365',
        'api_connections_dropbox_callback' => 'dropbox',
        'api_mcp_servers_oauth_callback' => 'mcp',
    ];

    public function __construct(
        private BrowserBoundState $state,
        private string $nativeDeepLinkScheme,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $connector = $this->connector($event->isMainRequest(), $request);
        if (null === $connector || $this->fromApp($request)) {
            return;
        }

        $state = $request->query->get('state');
        if (!is_string($state) || '' === $state || !$this->state->isAppBound($state)) {
            return;
        }

        $event->setResponse(new RedirectResponse(sprintf(
            '%s://oauth/connect?%s',
            $this->nativeDeepLinkScheme,
            http_build_query(['connector' => $connector] + $request->query->all()),
        )));
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (null === $this->connector($event->isMainRequest(), $request)
            || !$this->fromApp($request)
            || !$response instanceof RedirectResponse
        ) {
            return;
        }

        $target = parse_url($response->getTargetUrl());
        $path = is_array($target) ? ($target['path'] ?? '/') : '/';
        $query = is_array($target) && isset($target['query']) ? '?'.$target['query'] : '';

        $event->setResponse(new JsonResponse(['redirect' => $path.$query]));
    }

    private function connector(bool $mainRequest, Request $request): ?string
    {
        $route = $request->attributes->get('_route');

        return $mainRequest && is_string($route) ? (self::CALLBACKS[$route] ?? null) : null;
    }

    /** The app fetches the callback; a browser navigates to it. */
    private function fromApp(Request $request): bool
    {
        return 'json' === $request->getPreferredFormat('html');
    }
}
